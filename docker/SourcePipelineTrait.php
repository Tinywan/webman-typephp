<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use TypePhp\Backend\CompilerFactory;
use TypePhp\Exception\SyntaxError;
use TypePhp\Exception\Unsupported;
use TypePhp\Installer\LibPhpInstaller;
use TypePhp\Installer\LibPhpxInstaller;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Wasi;
use TypePhp\Platform\Windows;

trait SourcePipelineTrait
{
    public function addFiles(array $files): void
    {
        $this->sourceDirs = array_merge($this->sourceDirs, $files);
    }

    public function getFiles(string $path): array
    {
        $this->applyPhpVersionCommandLineArgument();
        $realpath = realpath($path);
        if ($realpath === false) {
            $this->error("path not exists: {$path}");
        }
        $path = $realpath;

        if (is_dir($path)) {
            // Directory mode: no YAML parsing
            $list = $this->getFilesFromDir($path);
            $targetName = basename($path);
            $this->setTargetName($targetName);
            $this->sourceDirs[] = $path;
        } else {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'yml' || $ext === 'yaml') {
                // YAML config mode: parse the YAML first
                $list = $this->parseProjectYaml($path);
            } elseif ($ext === 'php') {
                // Single-file mode: no YAML parsing
                $list = [$path];
                $targetName = FileScanner::getFileName($path);
                $this->setTargetName($targetName);
                $this->sourceDirs[] = dirname($path);
            } else {
                $this->error('Unsupported file type: ' . $path);
            }
        }

        // Apply command-line arguments after all configuration is loaded (so they
        // take the highest precedence)
        $this->applyCommandLineArguments();

        // The generated public import stub is an output artifact, not an input
        // of the library that produced it. Exclude a previous build's copy when
        // a project scans its output directory recursively.
        if ($this->isBuildModeLib()) {
            $generatedStub = realpath($this->getLibraryImportStubFile());
            if ($generatedStub !== false) {
                $list = array_values(array_filter(
                    $list,
                    static fn(string $file): bool => realpath($file) !== $generatedStub,
                ));
            }
        }

        return $this->filterIgnoredFiles($list);
    }

    public function prepare(string $path): array
    {
        $files = $this->getFiles($path);

        if ($this->isBuildModeEmbed() && $this->getPlatform() instanceof Linux) {
            try {
                $phpDir = (new LibPhpInstaller())->ensure($this->getPhpDir()) ?? $this->getPhpDir();
            } catch (\Throwable $e) {
                $this->error('Unable to install libphp.so: ' . $e->getMessage());
            }
        } else {
            $phpDir = $this->getPhpDir();
        }

        if (!($this->getPlatform() instanceof Wasi)) {
            $this->validatePhpRuntimeMinimum($phpDir);
        }

        if ($this->getPlatform() instanceof Linux) {
            try {
                (new LibPhpxInstaller())->ensure($this->getPhpxDir(), $phpDir);
            } catch (\Throwable $e) {
                $this->error('Unable to build libphpx.so: ' . $e->getMessage());
            }
        }

        // Pre-check the phpx library only at the PHP script entry (bin/tpc.php):
        // a missing library fails immediately rather than surfacing later during
        // file processing/compilation. The compiled tpc executable has libphpx
        // loaded by the dynamic linker before entering main(), so checking here
        // is neither needed nor possible.
        if (defined('TYPEPHP_PHP_SCRIPT_ENTRY') && !($this->getPlatform() instanceof Wasi)) {
            $this->validatePhpxLibrary();
        }

        $this->validateCompilerToolchain();

        // shell_exec and define are already called directly via php::fn::, so no
        // dynamic symbol table is needed

        // All Windows build modes depend on the PHPX import library and runtime.
        // Other platforms only run the existing checks in embedded build mode.
        if ($this->isBuildModeEmbed() || $this->getPlatform() instanceof Windows) {
            foreach ($this->getPlatform()->getBuildLibraryWarnings(
                $this->getPhpDir(),
                $this->getPhpxDir(),
                $this->buildMode,
                defined('TYPEPHP_PHP_SCRIPT_ENTRY'),
            ) as $message) {
                if (!empty($message['error'])) {
                    $detail = $message['error'];
                    if (!empty($message['info'])) {
                        $detail .= "\n" . $message['info'];
                    }
                    $this->error($detail);
                }
                $this->climate->warning($message['warning']);
                if (!empty($message['info'])) {
                    $this->climate->info($message['info']);
                }
            }
        }

        $files = $this->filterIgnoredFiles($files);
        $totalFiles = count($files);
        $this->climate->out("[Analyzing] Pre-checking native class declarations across {$totalFiles} files...");
        $this->discoverNativeClassDeclarations($files);
        // Analyze and preprocess the PHP files
        $totalPrepareFiles = count($files);
        $prepareIndex = 0;
        foreach ($files as $k => $file) {
            $prepareIndex++;
            if (FileScanner::isPhpFile($file)) {
                $shortPath = $this->removeCommonPrefix($this->sourceDirs[0] ?? getcwd(), $file);
                $percent = (int) ceil(($prepareIndex / max(1, $totalPrepareFiles)) * 100);
                $this->climate->out("[Analyzing {$prepareIndex}/{$totalPrepareFiles}] {$percent}% {$shortPath}");
                try {
                    $this->prepareFile($file);
                } catch (Unsupported $e) {
                    $this->output(' unsupported syntax: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                } catch (SyntaxError $e) {
                    $this->output(' syntax error: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                }
            }
        }
        // Trait declarations can only be flattened after the complete source
        // set has been prepared: a consuming class may precede its Trait file.
        // Complete the declaration graph before any body is converted.
        $this->composeTraitDeclarations(array_values($files));
        // Global slots are shared by every translation unit. Fix any Native
        // pointer ABI now, after declarations are known and before the first
        // per-file C++ body is generated.
        $this->discoverNativeGlobalObjects(array_values($files));
        $files = $this->getSortedFiles($files);
        return $files;
    }

    protected function validateCompilerToolchain(): void
    {
        $backend = $this->getCompilerBackend();
        $compilerCommand = $backend->getCompilerCommand();
        if (!CompilerFactory::isCommandExecutable($compilerCommand)) {
            $program = CompilerFactory::getCommandProgram($compilerCommand);
            $this->error(
                "C/C++ compiler executable not found: {$program}\n" .
                "Configured compiler command: {$compilerCommand}\n" .
                "Install a supported compiler, or select one with --compiler, or set `cpp-compiler` in project.yml."
            );
        }

        $linkerCommand = $backend->getLinkerCommand();
        if ($linkerCommand !== $compilerCommand && !CompilerFactory::isCommandExecutable($linkerCommand)) {
            $program = CompilerFactory::getCommandProgram($linkerCommand);
            $this->error(
                "Linker executable not found: {$program}\n" .
                "Configured linker command: {$linkerCommand}\n" .
                "Install the required linker or update compiler configuration."
            );
        }
    }

    /** Validate the selected headers/libphp independently of --php-version. */
    protected function validatePhpRuntimeMinimum(string $phpDir): void
    {
        $versionId = null;
        $headers = [
            $phpDir . '/include/php/main/php_version.h',
            $phpDir . '/include/main/php_version.h',
        ];
        foreach ($headers as $header) {
            if (!is_file($header)) {
                continue;
            }
            $contents = file_get_contents($header);
            if (is_string($contents) && preg_match('/^#define\s+PHP_VERSION_ID\s+(\d+)/m', $contents, $matches)) {
                $versionId = (int) $matches[1];
                break;
            }
        }

        if ($versionId === null) {
            $phpConfig = $phpDir . '/bin/php-config';
            if (is_executable($phpConfig)) {
                $value = shell_exec(escapeshellarg($phpConfig) . ' --vernum 2>/dev/null');
                if (is_string($value) && ctype_digit(trim($value))) {
                    $versionId = (int) trim($value);
                }
            }
        }

        if ($versionId !== null && $versionId < 80400) {
            $version = intdiv($versionId, 10000) . '.' . intdiv($versionId % 10000, 100);
            $this->error("TypePHP requires libphp 8.4 or later; selected PHP installation is {$version}: {$phpDir}");
        }
    }

    protected function shouldIgnoreFile(string $file): bool
    {
        foreach ($this->ignorePaths as $ignorePath) {
            if ($file === $ignorePath) {
                return true;
            }
            if (is_dir($ignorePath) && str_starts_with($file, rtrim($ignorePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    protected function filterIgnoredFiles(array $files): array
    {
        if (empty($this->ignorePaths)) {
            return $files;
        }

        $filteredFiles = [];
        foreach ($files as $file) {
            if (!$this->shouldIgnoreFile($file)) {
                $filteredFiles[] = $file;
            }
        }

        return $filteredFiles;
    }

    public function convert(array $files): array
    {
        $this->composeTraitDeclarations($files);
        $previousPhase = $this->enterCompilerPhase(self::PHASE_CONVERT);
        try {
            // All declarations are now known. Lower declaration constant
            // expressions before translating any function body so cache IDs
            // are assigned exclusively in the convert phase.
            $this->finalizeDeclarationExpressions($files);

            $sourceFiles = [];
            $validSourceCount = 0;
            // Generate the C++ files
            $totalConvertFiles = count($files);
            $convertIndex = 0;
            foreach ($files as $k => $file) {
                $convertIndex++;
                try {
                    if (FileScanner::isPhpFile($file)) {
                        $shortPath = $this->removeCommonPrefix($this->sourceDirs[0] ?? getcwd(), $file);
                        $percent = (int) ceil(($convertIndex / max(1, $totalConvertFiles)) * 100);
                        $this->climate->out("[Generating C++ {$convertIndex}/{$totalConvertFiles}] {$percent}% {$shortPath}");
                        $cppFile = $this->convertFile($file);
                    } elseif (FileScanner::isNativeSourceFile($file)) {
                        $cppFile = $file;
                    } else {
                        continue;
                    }
                    $validSourceCount++;
                    if ($cppFile !== null) {
                        $sourceFiles[] = $cppFile;
                    }
                } catch (Unsupported $e) {
                    echo ' unsupported syntax: ' . $e->getMessage() . "\n";
                    echo ' skip: ' . $file . "\n";
                    unset($files[$k]);
                }
            }

            // A valid PHP input may intentionally emit no standalone translation
            // unit (for example a compile-time trait or an interface). The shared
            // extension source still carries its runtime metadata, so only reject
            // an input set in which no supported source was converted at all.
            if ($validSourceCount === 0) {
                $this->stop('No valid source file found');
            }

            // A WASI library publishes WIT/Component exports rather than a native
            // TypePHP shared-library ABI, so a PHP import stub would be misleading.
            if ($this->isBuildModeLib() && !$this->isWasiTarget()) {
                $this->genLibraryImportStub($files);
            }

            // Generate the build-time internal headers: function declarations and
            // runtime data declarations
            $this->genFunctionDeclarations($this->getIncludeDir() . "/php_{$this->targetName}_func_decl.h");
            $this->genDataDeclarations($this->getIncludeDir() . "/php_{$this->targetName}_data_decl.h");
            // Generate the extension module source file
            $sourceFiles[] = $this->genExtension();

            return $sourceFiles;
        } finally {
            $this->restoreCompilerPhase($previousPhase);
        }
    }
}
