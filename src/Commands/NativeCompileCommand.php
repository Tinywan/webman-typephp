<?php

declare(strict_types=1);

namespace Tinywan\Typephp\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Tinywan\Typephp\Compiler\NativeCompilerOverlay;
use Tinywan\Typephp\Compiler\NativeToolchain;
use Tinywan\Typephp\Compiler\ProjectGenerator;

// @mago-ignore lint:cyclomatic-complexity -- The command coordinates validation, AOT preparation, and native compilation.
// @mago-ignore lint:kan-defect -- Each failure branch reports the exact native build gate that failed.
final class NativeCompileCommand extends Command
{
    protected static $defaultName = 'typephp:compile';
    protected static $defaultDescription = 'Compile Webman for the current host using a local TypePHP toolchain.';

    protected function configure(): void
    {
        $this
            ->setName('typephp:compile')
            ->setDescription('Compile Webman for the current host without Docker')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Compatibility profile (supported: saiadmin)')
            ->addOption('output-name', null, InputOption::VALUE_REQUIRED, 'Native executable name', 'webman-server')
            ->addOption('tpc', null, InputOption::VALUE_REQUIRED, 'Path to tpc or tpc.php')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP executable matching the embed SDK')
            ->addOption('php-home', null, InputOption::VALUE_REQUIRED, 'PHP embed SDK prefix')
            ->addOption('phpx-home', null, InputOption::VALUE_REQUIRED, 'Built PHPX prefix or checkout')
            ->addOption('cxx', null, InputOption::VALUE_REQUIRED, 'C++ compiler executable')
            ->addOption(
                'build-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Project-relative compiler cache',
                '.typephp/native-cache',
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force a clean TypePHP rebuild')
            ->addOption('refresh-main', null, InputOption::VALUE_NONE, 'Refresh main.php from the packaged stub');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputName = (string) $input->getOption('output-name');
        $buildDir = trim((string) $input->getOption('build-dir'), '/\\');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $outputName)) {
            $output->writeln("<error>[ERROR] Invalid output-name: '{$outputName}'.</error>");
            return Command::FAILURE;
        }
        if (!$this->isSafeRelativePath($buildDir)) {
            $output->writeln("<error>[ERROR] Invalid build-dir: '{$buildDir}'.</error>");
            return Command::FAILURE;
        }

        $pluginConfig = $this->pluginConfig();
        $jobs = $pluginConfig['build']['jobs'] ?? 4;
        if (!is_int($jobs) || $jobs < 1 || $jobs > 4) {
            $output->writeln('<error>[ERROR] build.jobs must be an integer between 1 and 4.</error>');
            return Command::FAILURE;
        }
        $nativeConfig = is_array($pluginConfig['native'] ?? null) ? $pluginConfig['native'] : [];
        foreach (['tpc', 'php', 'php_home', 'phpx_home', 'cxx'] as $key) {
            $option = str_replace('_', '-', $key);
            $value = $input->getOption($option);
            if (is_string($value) && $value !== '') {
                $nativeConfig[$key] = $value;
            }
        }

        try {
            $toolchain = new NativeToolchain()->resolve($nativeConfig);
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>[ERROR] Native toolchain check failed: ' . $exception->getMessage() . '</error>');
            $output->writeln('<comment>Run: php webman typephp:doctor --target=native</comment>');
            return Command::FAILURE;
        }

        $basePath = (string) (function_exists('base_path') ? base_path() : getcwd());
        $profile = $input->getOption('profile');
        $profileName = is_string($profile) && $profile !== '' ? $profile : null;
        $generator = new ProjectGenerator($basePath);
        $skipGuardedSources = [];
        $nativeIgnores = [];
        if (version_compare($toolchain['php_version'], '8.5', '>=')) {
            foreach (array_keys(ProjectGenerator::GUARDED_SOURCES) as $source) {
                if (str_starts_with($source, 'vendor/symfony/polyfill-php85/')) {
                    $skipGuardedSources[] = $source;
                }
            }
            $nativeIgnores[] = 'vendor/symfony/polyfill-php85';
        }
        if ($toolchain['intl_loaded'] === '1') {
            $skipGuardedSources = [
                ...$skipGuardedSources,
                'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php',
                'vendor/symfony/polyfill-intl-idn/bootstrap80.php',
                'vendor/symfony/polyfill-intl-normalizer/bootstrap80.php',
            ];
            $nativeIgnores = [
                ...$nativeIgnores,
                'vendor/symfony/polyfill-intl-grapheme',
                'vendor/symfony/polyfill-intl-idn',
                'vendor/symfony/polyfill-intl-normalizer',
            ];
        }

        try {
            $configuredIgnores = is_array($pluginConfig['ignore'] ?? null) ? $pluginConfig['ignore'] : [];
            $generator->generateMain(null, (bool) $input->getOption('refresh-main'));
            $projectFile = $generator->generateProjectYml(array_replace_recursive($pluginConfig, [
                'profile' => $profileName,
                'ignore' => array_values(array_unique([...$configuredIgnores, ...$nativeIgnores])),
                'build' => [
                    'output_name' => $outputName,
                    'jobs' => $jobs,
                    'skip_guarded_sources' => array_values(array_unique($skipGuardedSources)),
                ],
            ]), 'project.native.yml');
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>[ERROR] ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $absoluteBuildDir = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $buildDir);
        $outputDirectory = $basePath . DIRECTORY_SEPARATOR . 'build';
        if (
            !is_dir($absoluteBuildDir) && !mkdir($absoluteBuildDir, 0777, true) && !is_dir($absoluteBuildDir)
            || !is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)
        ) {
            $output->writeln('<error>[ERROR] Unable to create native build directories.</error>');
            return Command::FAILURE;
        }

        try {
            $compilerEntry = $profileName === 'saiadmin'
                ? new NativeCompilerOverlay()->prepare($toolchain, $basePath . '/.typephp/native-compiler')
                : $toolchain['tpc'];
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>[ERROR] Native compiler overlay failed: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $compilerToolchain = $toolchain;
        $compilerToolchain['tpc'] = $compilerEntry;
        $command = new NativeToolchain()->command($compilerToolchain);
        $command = [
            ...$command,
            $projectFile,
            '--php-version',
            $toolchain['php_version'],
            '--compiler',
            $toolchain['cxx'],
            '--build-dir',
            $absoluteBuildDir,
            '--no-progress',
        ];
        if ((bool) $input->getOption('force')) {
            $command[] = '--force';
        }

        $output->writeln('<info>[TypePHP] Native toolchain ready (Docker will not be used).</info>');
        $output->writeln(
            "<comment>PHP {$toolchain['php_version']} | TypePHP {$toolchain['tpc_version']} | "
            . basename($toolchain['cxx'])
            . '</comment>',
        );
        $process = new Process(
            $command,
            $basePath,
            [
                'PHP_HOME' => $toolchain['php_home'],
                'PHPX_HOME' => $toolchain['phpx_home'],
            ],
            null,
            1800,
        );
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });
        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $output->writeln(
                '<error>[ERROR] Native TypePHP build failed with exit code '
                . ($exitCode === null ? 'unknown' : (string) $exitCode)
                . '</error>',
            );
            return Command::FAILURE;
        }

        $compiled = $this->findCompiledExecutable($outputDirectory, $outputName);
        if ($compiled === null) {
            $output->writeln("<error>[ERROR] Compiled executable build/{$outputName} was not found.</error>");
            return Command::FAILURE;
        }
        $this->writeManifest($basePath, $compiled, $profileName, $toolchain);
        $relative = ltrim(str_replace($basePath, '', $compiled), DIRECTORY_SEPARATOR);
        $output->writeln("<info>Successfully built native executable: {$relative}</info>");
        $output->writeln("<info>Run command: ./{$relative} start</info>");
        return Command::SUCCESS;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function pluginConfig(): array
    {
        $defaults = require dirname(__DIR__) . '/config/plugin/tinywan/typephp/app.php';
        if (!is_array($defaults) || !function_exists('config')) {
            return is_array($defaults) ? $defaults : [];
        }
        $configured = config('plugin.tinywan.typephp.app', []);
        if (!is_array($configured)) {
            return $defaults;
        }
        $merged = array_replace_recursive($defaults, $configured);
        foreach (['ignore', 'runtime_resources'] as $key) {
            if (is_array($defaults[$key] ?? null) && is_array($configured[$key] ?? null)) {
                $merged[$key] = array_values(array_unique(array_merge($defaults[$key], $configured[$key])));
            }
        }
        return $merged;
    }

    private function isSafeRelativePath(string $path): bool
    {
        return (
            $path !== ''
            && !str_starts_with($path, '/')
            && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)
            && !in_array('..', preg_split('#[\\\\/]#', $path) ?: [], true)
        );
    }

    private function findCompiledExecutable(string $outputDirectory, string $outputName): ?string
    {
        foreach ([$outputName, str_replace('-', '_', $outputName)] as $name) {
            $candidate = $outputDirectory . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $toolchain
     */
    private function writeManifest(string $basePath, string $compiled, ?string $profile, array $toolchain): void
    {
        $manifest = [
            'target' => strtolower(PHP_OS_FAMILY) . '-' . php_uname('m') . '-native',
            'mode' => 'native',
            'profile' => $profile,
            'php_version' => $toolchain['php_version'],
            'typephp_version' => $toolchain['tpc_version'],
            'cxx' => basename($toolchain['cxx']),
            'output' => ltrim(str_replace($basePath, '', $compiled), DIRECTORY_SEPARATOR),
            'output_sha256' => (string) hash_file('sha256', $compiled),
            'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        file_put_contents(
            $basePath . '/.typephp/build/native-build-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
