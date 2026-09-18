<?php

declare(strict_types=1);

namespace Tinywan\Typephp\Compiler;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// @mago-ignore lint:cyclomatic-complexity -- Toolchain discovery validates several independent host fallbacks.
// @mago-ignore lint:kan-defect -- Fail-closed ABI and path checks intentionally keep each fallback explicit.
// @mago-ignore lint:too-many-methods -- Each small resolver owns one native toolchain prerequisite.
final class NativeToolchain
{
    /**
     * @param array<array-key, mixed> $config
     * @return array{php:string,php_home:string,phpx_home:string,tpc:string,typephp_home:string,cxx:string,php_version:string,tpc_version:string,intl_loaded:string}
     */
    public function resolve(array $config = []): array
    {
        $phpHome = $this->resolvePhpHome($config);
        $php = $this->resolveExecutable(
            $config['php'] ?? null,
            [
                $phpHome . '/bin/php',
                PHP_BINARY,
            ],
            'PHP executable',
        );
        $phpVersion = $this->capture([$php, '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;']);
        $intlLoaded = $this->capture([$php, '-r', 'echo extension_loaded("intl") ? "1" : "0";']);
        if (!in_array($phpVersion, ['8.4', '8.5'], true)) {
            throw new \RuntimeException("Native TypePHP requires PHP 8.4 or 8.5; {$php} reports {$phpVersion}.");
        }

        $phpConfig = $phpHome . '/bin/php-config';
        if (!is_executable($phpConfig)) {
            throw new \RuntimeException("PHP_HOME does not provide bin/php-config: {$phpHome}");
        }
        $configuredVersion = $this->capture([$phpConfig, '--version']);
        if (!str_starts_with($configuredVersion, $phpVersion . '.')) {
            throw new \RuntimeException(
                "PHP ABI mismatch: {$php} reports {$phpVersion}, but {$phpConfig} reports {$configuredVersion}.",
            );
        }
        $this->assertPhpEmbed($phpHome);

        $tpc = $this->resolveTpc($config);
        $typephpHome = $this->resolveTypephpHome($tpc);
        $phpxHome = $this->resolvePhpxHome($config, $tpc);
        $this->assertPhpxLibrary($phpxHome);
        $cxx = $this->resolveCxx($config);

        $versionCommand = str_ends_with($tpc, '.php') ? [$php, $tpc, '--version'] : [$tpc, '--version'];
        $tpcVersion = $this->capture($versionCommand, [
            'PHP_HOME' => $phpHome,
            'PHPX_HOME' => $phpxHome,
        ]);
        $match = [];
        if (!preg_match('/TypePHP Compiler \(AOT\) v(\d+\.\d+\.\d+)/', $tpcVersion, $match)) {
            throw new \RuntimeException("Unable to determine TypePHP version from: {$tpc}");
        }

        return [
            'php' => $php,
            'php_home' => $phpHome,
            'phpx_home' => $phpxHome,
            'tpc' => $tpc,
            'typephp_home' => $typephpHome,
            'cxx' => $cxx,
            'php_version' => $phpVersion,
            'tpc_version' => $match[1],
            'intl_loaded' => $intlLoaded,
        ];
    }

    /**
     * @param array<string, string> $toolchain
     * @return list<string>
     */
    public function command(array $toolchain): array
    {
        return str_ends_with($toolchain['tpc'], '.php') ? [$toolchain['php'], $toolchain['tpc']] : [$toolchain['tpc']];
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function resolvePhpHome(array $config): string
    {
        $configured = $this->stringValue($config['php_home'] ?? null) ?? $this->stringValue(getenv('PHP_HOME'));
        $candidates = array_filter([
            $configured,
            PHP_OS_FAMILY === 'Darwin' ? '/opt/homebrew/opt/php-zts' : null,
            PHP_OS_FAMILY === 'Darwin' ? '/usr/local/opt/php-zts' : null,
            dirname(dirname((string) (realpath(PHP_BINARY) ?: PHP_BINARY))),
        ]);
        foreach ($candidates as $candidate) {
            $path = (string) (realpath($candidate) ?: $candidate);
            if (is_dir($path) && is_executable($path . '/bin/php-config') && $this->hasPhpEmbed($path)) {
                return rtrim($path, '/\\');
            }
        }
        throw new \RuntimeException(
            'Native PHP embed SDK was not found. Set native.php_home or PHP_HOME to a PHP 8.4/8.5 '
            . 'installation containing bin/php-config, sapi/embed/php_embed.h, and libphp.',
        );
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function resolveTpc(array $config): string
    {
        $configured = $this->stringValue($config['tpc'] ?? null) ?? $this->stringValue(getenv('TYPEPHP_TPC'));
        $home = $this->stringValue(getenv('HOME'));
        $cwd = getcwd();
        $cwd = $cwd === false ? '.' : $cwd;
        $candidates = array_filter([
            $configured,
            $cwd . '/vendor/bin/tpc.php',
            $home === null ? null : $home . '/.composer/vendor/bin/tpc.php',
            $home === null ? null : $home . '/.config/composer/vendor/bin/tpc.php',
            new ExecutableFinder()->find('tpc'),
            new ExecutableFinder()->find('tpc.php'),
        ]);
        foreach ($candidates as $candidate) {
            $path = (string) (realpath($candidate) ?: $candidate);
            if (is_file($path) && (str_ends_with($path, '.php') || is_executable($path))) {
                return $path;
            }
        }
        throw new \RuntimeException(
            'TypePHP compiler was not found. Install swoole/typephp globally or set native.tpc/TYPEPHP_TPC.',
        );
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function resolvePhpxHome(array $config, string $tpc): string
    {
        $configured = $this->stringValue($config['phpx_home'] ?? null) ?? $this->stringValue(getenv('PHPX_HOME'));
        $home = $this->stringValue(getenv('HOME'));
        $vendorDir = dirname(dirname($tpc));
        $cwd = getcwd();
        $cwd = $cwd === false ? '.' : $cwd;
        $candidates = array_filter([
            $configured,
            $cwd . '/vendor/swoole/phpx',
            $vendorDir . '/swoole/phpx',
            $home === null ? null : $home . '/.composer/vendor/swoole/phpx',
            $home === null ? null : $home . '/.config/composer/vendor/swoole/phpx',
        ]);
        foreach ($candidates as $candidate) {
            $path = (string) (realpath($candidate) ?: $candidate);
            if (is_dir($path) && $this->hasPhpxLibrary($path)) {
                return rtrim($path, '/\\');
            }
        }
        throw new \RuntimeException(
            'A built PHPX runtime was not found. Set native.phpx_home or PHPX_HOME to a matching PHPX '
            . 'installation containing libphpx.',
        );
    }

    private function resolveTypephpHome(string $tpc): string
    {
        foreach ([dirname(dirname($tpc)), dirname(dirname($tpc)) . '/swoole/typephp'] as $candidate) {
            $path = (string) (realpath($candidate) ?: $candidate);
            if (is_file($path . '/bin/bootstrap.php') && is_file($path . '/src/compiler.php')) {
                return $path;
            }
        }
        return '';
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function resolveCxx(array $config): string
    {
        $configured = $this->stringValue($config['cxx'] ?? null) ?? $this->stringValue(getenv('CXX'));
        $finder = new ExecutableFinder();
        $candidates = array_filter([
            $configured,
            $finder->find('clang++'),
            $finder->find('g++'),
        ]);
        foreach ($candidates as $candidate) {
            $path = (string) (realpath($candidate) ?: $candidate);
            if (is_executable($path)) {
                return $path;
            }
        }
        throw new \RuntimeException('A C++17 compiler was not found. Set native.cxx or CXX.');
    }

    /**
     * @param list<string> $fallbacks
     */
    private function resolveExecutable(mixed $configured, array $fallbacks, string $label): string
    {
        $candidates = array_filter([$this->stringValue($configured), ...$fallbacks]);
        foreach ($candidates as $candidate) {
            $path = (string) (realpath($candidate) ?: $candidate);
            if (is_executable($path)) {
                return $path;
            }
        }
        throw new \RuntimeException("{$label} was not found.");
    }

    private function assertPhpEmbed(string $phpHome): void
    {
        if (!$this->hasPhpEmbed($phpHome)) {
            throw new \RuntimeException(
                "PHP embed SDK is incomplete: {$phpHome}. Expected sapi/embed/php_embed.h and libphp.",
            );
        }
    }

    private function hasPhpEmbed(string $phpHome): bool
    {
        $header = $phpHome . '/include/php/sapi/embed/php_embed.h';
        if (!is_file($header)) {
            return false;
        }
        foreach (['libphp.dylib', 'libphp.so', 'libphp.a'] as $library) {
            if (is_file($phpHome . '/lib/' . $library)) {
                return true;
            }
        }
        return false;
    }

    private function assertPhpxLibrary(string $phpxHome): void
    {
        if (!$this->hasPhpxLibrary($phpxHome)) {
            throw new \RuntimeException("PHPX runtime library is missing from: {$phpxHome}");
        }
    }

    private function hasPhpxLibrary(string $phpxHome): bool
    {
        foreach (['libphpx.dylib', 'libphpx.so', 'libphpx.a'] as $library) {
            if (is_file($phpxHome . '/lib/' . $library)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function capture(array $command, array $environment = []): string
    {
        $process = new Process($command, null, $environment, null, 15);
        $process->run();
        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            throw new \RuntimeException($message === '' ? 'Native toolchain command failed.' : $message);
        }
        return trim($process->getOutput());
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
