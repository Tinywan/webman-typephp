<?php

declare(strict_types=1);

namespace Tinywan\Typephp\Compiler\Profile;

final class SaiAdminProfile
{
    public const NAME = 'saiadmin';

    private const SUPPORTED_EXACT_VERSIONS = [
        'topthink/think-orm' => ['v3.0.34'],
        'nesbot/carbon' => ['3.13.2'],
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return array<string, string>
     */
    public function assertSupported(): array
    {
        if (!is_dir($this->absolute('plugin/saiadmin'))) {
            throw new \RuntimeException('The saiadmin profile requires plugin/saiadmin.');
        }

        $lockFile = $this->absolute('composer.lock');
        $contents = is_file($lockFile) ? file_get_contents($lockFile) : false;
        $lock = $contents === false ? null : json_decode($contents, true);
        if (!is_array($lock)) {
            throw new \RuntimeException('The saiadmin profile requires a valid composer.lock.');
        }

        $installed = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $installed[$package['name']] = $package['version'];
            }
        }

        $saiAdminVersion = $installed['saithink/saiadmin'] ?? null;
        if ($saiAdminVersion === null) {
            throw new \RuntimeException('The saiadmin profile requires saithink/saiadmin.');
        }
        $this->assertInstalledSaiAdminMatchesPackage();

        foreach (self::SUPPORTED_EXACT_VERSIONS as $package => $versions) {
            $version = $installed[$package] ?? null;
            if ($version === null) {
                throw new \RuntimeException("The saiadmin profile requires {$package}.");
            }
            if (!in_array($version, $versions, true)) {
                throw new \RuntimeException(
                    "Unsupported {$package} version {$version}; supported: " . implode(', ', $versions) . '.',
                );
            }
        }
        return array_intersect_key(
            $installed,
            ['saithink/saiadmin' => true] + array_fill_keys(array_keys(self::SUPPORTED_EXACT_VERSIONS), true),
        );
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        $sources = [];
        foreach (['app', 'support', 'plugin/saiadmin'] as $source) {
            if (is_dir($this->absolute($source))) {
                $sources[] = $source;
            }
        }
        foreach (glob($this->absolute('plugin/*/app'), GLOB_ONLYDIR) ?: [] as $directory) {
            $plugin = basename(dirname($directory));
            if ($plugin !== 'saiadmin' && preg_match('/^[A-Za-z0-9_-]+$/', $plugin)) {
                $sources[] = "plugin/{$plugin}/app";
            }
        }
        $sources = array_values(array_unique($sources));
        sort($sources);
        return $sources;
    }

    /**
     * Dependency declarations required by generated compatibility copies.
     *
     * @return list<string>
     */
    public function dependencySources(): array
    {
        if (!is_dir($this->absolute('vendor'))) {
            throw new \RuntimeException('SaiAdmin profile dependency source was not found: vendor.');
        }

        return ['vendor'];
    }

    /**
     * @param list<string> $sources
     * @return list<string>
     */
    public function mergeCompilerSources(array $sources): array
    {
        $merged = array_merge($sources, $this->sources(), $this->dependencySources());
        if (in_array('vendor', $merged, true)) {
            $merged = array_values(array_filter(
                $merged,
                static fn(string $source): bool => $source === 'vendor' || !str_starts_with($source, 'vendor/'),
            ));
        }

        return array_values(array_unique($merged));
    }

    /**
     * @return list<string>
     */
    public function runtimeResources(): array
    {
        $resources = [];
        foreach (glob($this->absolute('plugin/*'), GLOB_ONLYDIR) ?: [] as $pluginDirectory) {
            $plugin = basename($pluginDirectory);
            foreach (['config', 'public', 'app/view'] as $suffix) {
                $resource = "plugin/{$plugin}/{$suffix}";
                if (is_dir($this->absolute($resource))) {
                    $resources[] = $resource;
                }
            }
        }
        foreach (['plugin/saiadmin/db', 'plugin/saiadmin/utils/code/stub'] as $resource) {
            if (is_dir($this->absolute($resource))) {
                $resources[] = $resource;
            }
        }
        $resources = array_values(array_unique($resources));
        sort($resources);
        return $resources;
    }

    /**
     * @return list<string>
     */
    public function resourceIgnores(): array
    {
        return $this->runtimeResources();
    }

    /**
     * @param list<string> $sources
     * @param list<string> $ignores
     * @param array<string, string> $generatedSources
     * @return array{profile: string, packages: array<string, string>, counts: array<string, int>, files: list<array<string, string>>}
     */
    public function writeCoverageManifest(array $sources, array $ignores, array $generatedSources): array
    {
        $packages = $this->assertSupported();
        $files = [];
        $counts = ['compiled' => 0, 'generated' => 0];

        foreach ($this->businessFiles() as $file) {
            $ignored = $this->matchesAny($file, $ignores);
            $generated = $generatedSources[$file] ?? null;
            if ($ignored && ($generated === null || !$this->matchesAny($generated, $sources))) {
                throw new \RuntimeException(
                    "SaiAdmin business PHP is excluded without a compiled AOT replacement: {$file}.",
                );
            }
            if (!$ignored && !$this->matchesAny($file, $sources)) {
                throw new \RuntimeException("SaiAdmin business PHP is missing from compiler sources: {$file}.");
            }

            if ($ignored) {
                $files[] = ['path' => $file, 'mode' => 'generated', 'compiled_path' => (string) $generated];
                ++$counts['generated'];
            } else {
                $files[] = ['path' => $file, 'mode' => 'compiled', 'compiled_path' => $file];
                ++$counts['compiled'];
            }
        }

        $manifest = [
            'profile' => self::NAME,
            'packages' => $packages,
            'counts' => $counts,
            'files' => $files,
        ];
        $target = $this->absolute('.typephp/build/source-coverage.json');
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Unable to create the TypePHP build directory.');
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($target, $json . "\n") === false) {
            throw new \RuntimeException('Unable to write the SaiAdmin source coverage manifest.');
        }
        return $manifest;
    }

    /**
     * @return list<string>
     */
    private function businessFiles(): array
    {
        $files = [];
        foreach ($this->sources() as $source) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->absolute($source),
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
                ),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $relative = $this->relative($file->getPathname());
                if ($this->isNonBusinessPhp($relative)) {
                    continue;
                }
                $files[] = $relative;
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    private function isNonBusinessPhp(string $path): bool
    {
        return $path === 'support/bootstrap.php'
            || str_contains($path, '/config/')
            || str_contains($path, '/app/view/')
            || str_starts_with($path, 'plugin/saiadmin/db/')
            || str_starts_with($path, 'plugin/saiadmin/utils/code/stub/');
    }

    private function assertInstalledSaiAdminMatchesPackage(): void
    {
        $installed = $this->saiAdminBusinessSourceMap($this->absolute('plugin/saiadmin'));
        $package = $this->saiAdminBusinessSourceMap(
            $this->absolute('vendor/saithink/saiadmin/src/plugin/saiadmin'),
        );
        if ($installed === $package) {
            return;
        }

        $paths = array_values(array_unique(array_merge(array_keys($installed), array_keys($package))));
        sort($paths);
        foreach ($paths as $path) {
            if (($installed[$path] ?? null) !== ($package[$path] ?? null)) {
                throw new \RuntimeException(
                    "Installed SaiAdmin source does not match composer.lock package: plugin/saiadmin/{$path}.",
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function saiAdminBusinessSourceMap(string $root): array
    {
        if (!is_dir($root)) {
            throw new \RuntimeException('SaiAdmin package source was not found for installed-source verification.');
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($this->isNonBusinessPhp('plugin/saiadmin/' . $relative)) {
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            if (!is_string($hash)) {
                throw new \RuntimeException("Unable to hash SaiAdmin package source: {$relative}.");
            }
            $files[$relative] = $hash;
        }
        ksort($files);
        return $files;
    }

    /**
     * @param list<string> $paths
     */
    private function matchesAny(string $file, array $paths): bool
    {
        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', $path), '/');
            if ($path !== '' && ($file === $path || str_starts_with($file, $path . '/'))) {
                return true;
            }
        }
        return false;
    }

    private function absolute(string $path): string
    {
        return rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(rtrim($this->basePath, '/\\')) + 1));
    }
}
