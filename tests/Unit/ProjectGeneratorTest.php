<?php

declare(strict_types=1);

use Tinywan\Typephp\Compiler\Profile\SaiAdminProfile;
use Tinywan\Typephp\Compiler\ProjectGenerator;

it('excludes optional non-Fiber coroutine backends from AOT', function (): void {
    $config = require dirname(__DIR__, 2) . '/src/config/plugin/tinywan/typephp/app.php';
    expect($config['ignore'])
        ->not->toContain('vendor/workerman/coroutine/src/Pool.php')
        ->not->toContain('vendor/workerman/coroutine/src/Utils/DestructionWatcher.php');
    expect($config['runtime_resources'])
        ->not->toContain('vendor/workerman/coroutine/src/Pool.php')
        ->not->toContain('vendor/workerman/coroutine/src/Utils/DestructionWatcher.php');
    foreach (['Swow', 'Swoole'] as $backend) {
        foreach (['Barrier', 'Channel', 'Context', 'Coroutine', 'WaitGroup'] as $component) {
            expect($config['ignore'])->toContain("vendor/workerman/coroutine/src/{$component}/{$backend}.php");
        }
        expect($config['ignore'])->toContain("vendor/workerman/workerman/src/Events/{$backend}.php");
    }
});

it('does not let stale project config exclude required coroutine dependencies', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/plugin/saiadmin', 0777, true);

    try {
        $profile = new SaiAdminProfile($directory);
        $stalePaths = [
            'vendor/workerman/coroutine/src/Pool.php',
            'vendor/workerman/coroutine/src/Utils/DestructionWatcher.php',
        ];
        expect($profile->filterUserIgnores($stalePaths))
            ->toBe([])
            ->and($profile->filterRuntimeResources($stalePaths))
            ->toBe([]);
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('bypasses the Workerman pool WeakMap placeholder outside coroutines in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/workerman/coroutine/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/workerman/coroutine/src/Pool.php',
        "<?php\nnamespace Workerman\\Coroutine;\nclass Pool {\n"
        . "    public function createConnection(): object\n    {\n"
        . "        \$placeholder = new stdClass;\n"
        . "        \$connection = new stdClass;\n"
        . "        \$this->connections[\$connection] = \$this->lastUsedTimes[\$connection] = \$this->lastHeartbeatTimes[\$connection] = time();\n"
        . "        return \$placeholder;\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toContain('.typephp/build/workerman-coroutine-pool.php');
        expect(file_get_contents($directory . '/.typephp/build/workerman-coroutine-pool.php'))
            ->toContain('        if (!Coroutine::isCoroutine()) {')
            ->toContain('            $this->connections->offsetSet($connection, $timestamp);')
            ->toContain('        $this->connections->offsetSet($connection, $timestamp);')
            ->not
            ->toContain('$this->connections[$connection] = $this->lastUsedTimes[$connection]')
            ->toContain('        $placeholder = new stdClass;');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

function removeTypephpTestDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            removeTypephpTestDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

function createWebmanHelpersFixture(string $directory, string $content): string
{
    $helpersDirectory = $directory . DIRECTORY_SEPARATOR . 'vendor/workerman/webman-framework/src/support';
    mkdir($helpersDirectory, 0777, true);
    $helpersFile = $helpersDirectory . DIRECTORY_SEPARATOR . 'helpers.php';
    file_put_contents($helpersFile, $content);
    return $helpersFile;
}

it('creates a portable build configuration from explicit project inputs', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . DIRECTORY_SEPARATOR . 'app', 0777, true);

    try {
        $generator = new ProjectGenerator($directory);
        $path = $generator->generateProjectYml(['include' => ['app'], 'exclude' => ['runtime']]);

        expect($path)
            ->toBe($directory . DIRECTORY_SEPARATOR . 'project.linux.yml')
            ->and(file_get_contents($path))
            ->toContain("sources:\n  - main.php\n  - app\n")
            ->toContain("\n  - runtime\n")
            ->toContain('output: build/webman-server');

        // 默认源中 fast-route functions.php 已被平铺版取代；无 vendor 时注入为空
        $defaultPath = $generator->generateProjectYml([]);
        $defaultYml = (string) file_get_contents($defaultPath);
        expect($defaultYml)->toContain('vendor/nikic/fast-route/src/BadRouteException.php');
        expect(str_contains($defaultYml, "sources:\n  - main.php\n  - .typephp/build/"))->toBeFalse();

        // 测试 generateMain 首次生成
        $mainPath = $generator->generateMain();
        expect(file_exists($mainPath))
            ->toBeTrue()
            ->and(file_get_contents($mainPath))
            ->toContain('\Webman\Database\Initializer::init(config(\'database\', []));')
            ->toContain('\Webman\ThinkOrm\Initializer::init();')
            ->toContain('function typephp_is_compiled_autoload_file(string $file): bool')
            ->toContain('function typephp_worker_bootstrap($worker): void')
            ->toContain('E_DEPRECATED | E_USER_DEPRECATED')
            ->toContain("'support/Request.php', 'support/Response.php'")
            ->toContain("preg_match('#^plugin/[^/]+/app/functions")
            ->toContain("foreach (config('autoload.files', []) as \$file)")
            ->toContain("foreach (\$projects['autoload']['files'] ?? [] as \$file)")
            ->toContain('if (is_file($file) && !typephp_is_compiled_autoload_file($file))')
            ->toContain('\Webman\Middleware::load($pluginConfig[\'middleware\'] ?? [], (string) $pluginName);')
            ->toContain("glob(base_path() . '/plugin/*/config', GLOB_ONLYDIR)")
            ->toContain('\Webman\Route::load($routePaths);');
        expect(substr_count((string) file_get_contents($mainPath), '\Webman\Route::load($routePaths);'))->toBe(1);

        // 测试 generateMain 强制刷新生成备份
        file_put_contents($mainPath, '<?php // custom user code');
        $generator->generateMain(null, true);
        expect(file_exists($mainPath . '.bak'))
            ->toBeTrue()
            ->and(file_get_contents($mainPath . '.bak'))
            ->toContain('custom user code');
    } finally {
        $projectFile = $directory . DIRECTORY_SEPARATOR . 'project.linux.yml';
        if (is_file($projectFile)) {
            unlink($projectFile);
        }
        $mainFile = $directory . DIRECTORY_SEPARATOR . 'main.php';
        if (is_file($mainFile)) {
            unlink($mainFile);
        }
        $mainBakFile = $directory . DIRECTORY_SEPARATOR . 'main.php.bak';
        if (is_file($mainBakFile)) {
            unlink($mainBakFile);
        }
        $appDirectory = $directory . DIRECTORY_SEPARATOR . 'app';
        if (is_dir($appDirectory)) {
            rmdir($appDirectory);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('writes only existing safe runtime resources into the packaging manifest', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/example/data', 0777, true);
    mkdir($directory . '/vendor/laravel/serializable-closure', 0777, true);
    mkdir($directory . '/vendor/symfony/cache', 0777, true);
    file_put_contents($directory . '/vendor/example/data/map.php', "<?php\nreturn [];\n");

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateRuntimeResourceList([
            'vendor/example/data',
            'vendor/example/missing',
            'vendor/example/data',
        ]))
            ->toBe('.typephp/build/runtime-resources.list');
        expect(file_get_contents($directory . '/.typephp/build/runtime-resources.list'))->toBe("vendor/example/data\n");
        $generator->generateProjectYml(['include' => ['app']]);
        expect(file_get_contents($directory . '/.typephp/build/runtime-resources.list'))
            ->toBe("vendor/laravel/serializable-closure\nvendor/symfony/cache\n");
        expect(file_get_contents($directory . '/project.linux.yml'))
            ->toContain("\n  - vendor/laravel/serializable-closure\n")
            ->toContain("\n  - vendor/symfony/cache\n");
        expect(fn(): ?string => $generator->generateRuntimeResourceList(['../secret']))
            ->toThrow(RuntimeException::class);
        expect(fn(): ?string => $generator->generateRuntimeResourceList(['/absolute/path']))
            ->toThrow(RuntimeException::class);
        expect(fn(): ?string => $generator->generateRuntimeResourceList(['.typephp/build']))
            ->toThrow(RuntimeException::class);
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Webman plugin configuration available for runtime discovery', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-plugin-config-' . bin2hex(random_bytes(4));
    mkdir($directory . '/plugin/saiadmin/config', 0777, true);
    mkdir($directory . '/plugin/example/config', 0777, true);
    file_put_contents($directory . '/plugin/saiadmin/config/route.php', "<?php\nreturn [];\n");
    file_put_contents($directory . '/plugin/example/config/app.php', "<?php\nreturn ['enable' => true];\n");

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->discoverPluginConfigResources())->toBe([
            'plugin/example/config',
            'plugin/saiadmin/config',
        ]);

        $generator->generateProjectYml(['include' => ['app']]);
        expect(file_get_contents($directory . '/.typephp/build/runtime-resources.list'))
            ->toBe("plugin/example/config\nplugin/saiadmin/config\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('flattens guarded webman helpers into an AOT-compatible source file', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    createWebmanHelpersFixture($directory, <<<'PHP'
        <?php

        /**
         * This file is part of webman.
         */

        use support\Request;
        use Webman\Config;

        /**
         * Get the base path of the application
         */
        if (!defined('BASE_PATH')) {
            if (!$basePath = Phar::running()) {
                $basePath = getcwd();
                while ($basePath !== dirname($basePath)) {
                    if (is_dir("$basePath/vendor") && is_file("$basePath/start.php")) {
                        break;
                    }
                    $basePath = dirname($basePath);
                }
            }
            define('BASE_PATH', realpath($basePath) ?: $basePath);
        }

        if (!function_exists('run_path')) {
            /**
             * return the program execute directory
             */
            function run_path(string $path = ''): string
            {
                static $runPath = '';
                if (!$runPath) {
                    $runPath = is_phar() ? dirname(Phar::running(false)) : BASE_PATH;
                }
                return path_combine($runPath, $path);
            }
        }

        if (!function_exists('base_path')) {
            /**
             * Base path
             */
            function base_path($path = ''): string
            {
                if (false === $path) {
                    return run_path();
                }
                return path_combine(BASE_PATH, $path);
            }
        }

        if (!function_exists('config')) {
            /**
             * Get config
             */
            function config(?string $key = null, mixed $default = null)
            {
                $braces = ['{', '}'];
                return Config::get("{$key}", $default) . implode('', $braces);
            }
        }

        if (!function_exists('worker_start')) {
            /**
             * Start worker
             */
            function worker_start($processName, $config)
            {
                $worker = new \Workerman\Worker($config['listen'] ?? null);
                $worker->onWorkerStart = function ($worker) use ($config) {
                    require_once base_path('/support/bootstrap.php');
                };
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $helpersFile = $generator->generateHelpers();

        $expectedPath = str_replace('\\', '/', $directory) . '/.typephp/build/helpers.php';
        expect(str_replace('\\', '/', (string) $helpersFile))
            ->toBe($expectedPath)
            ->and(file_exists((string) $helpersFile))
            ->toBeTrue();

        $flattened = (string) file_get_contents((string) $helpersFile);
        expect($flattened)
            ->toContain('function run_path(string $path = \'\'): string')
            ->toContain('function base_path($path = \'\'): string')
            ->toContain('function config(?string $key = null, mixed $default = null)')
            ->toContain('{$key}');
        expect(str_contains($flattened, 'function_exists'))
            ->toBeFalse()
            ->and(str_contains($flattened, "defined('BASE_PATH')"))
            ->toBeFalse()
            ->and(str_contains($flattened, 'Get the base path of the application'))
            ->toBeFalse();

        // worker_start 使用 AOT bootstrap，避免 support/bootstrap.php 再次 include
        // 已编译的 Request、Response 与业务 functions。
        expect($flattened)->toContain('typephp_worker_bootstrap($worker);');
        expect(str_contains($flattened, "require_once base_path('/support/bootstrap.php')"))->toBeFalse();

        // 顶层不允许出现任何 T_IF（TypePHP 会报 Unsupported statement: Stmt_If）
        $depth = 0;
        $interpolations = 0;
        foreach (token_get_all($flattened) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            expect($depth === 0 && $id === T_IF)->toBeFalse();
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interpolations++;
            } elseif ($id === null) {
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}') {
                    if ($interpolations > 0) {
                        $interpolations--;
                    } else {
                        $depth--;
                    }
                }
            }
        }
        expect($depth)->toBe(0)->and($interpolations)->toBe(0);

        // yml 中注入平铺 helpers 源并忽略 vendor 原版 helpers.php
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain("sources:\n  - main.php\n  - .typephp/build/helpers.php\n")
            ->toContain("\n  - vendor/workerman/webman-framework/src/support/helpers.php\n")
            ->toContain("\n  - vendor/illuminate/pagination/resources\n")
            ->toContain("\n  - vendor/illuminate/bus/ChainedBatch.php\n")
            ->toContain("\n  - vendor/illuminate/database/Console\n")
            ->toContain("\n  - vendor/illuminate/database/Eloquent/BroadcastableModelEventOccurred.php\n")
            ->toContain("\n  - vendor/illuminate/database/Eloquent/BroadcastsEvents.php\n")
            ->toContain("\n  - vendor/illuminate/database/Eloquent/BroadcastsEventsAfterCommit.php\n")
            ->toContain("\n  - vendor/illuminate/events/CallQueuedListener.php\n")
            ->toContain("\n  - vendor/symfony/cache/Traits/Relay\n")
            ->toContain("\n  - vendor/symfony/cache/Traits/RelayProxy.php\n")
            ->toContain("\n  - vendor/symfony/cache/Traits/RelayClusterProxy.php\n")
            ->toContain("\n  - vendor/symfony/http-kernel/Bundle\n")
            ->toContain("\n  - vendor/symfony/http-kernel/Config\n")
            ->toContain("\n  - vendor/symfony/http-kernel/Debug\n")
            ->toContain("\n  - vendor/symfony/http-kernel/EventListener/DumpListener.php\n")
            ->toContain("\n  - vendor/symfony/http-kernel/EventListener/ProfilerListener.php\n")
            ->toContain("\n  - vendor/symfony/http-kernel/HttpKernelBrowser.php\n")
            ->toContain("\n  - vendor/symfony/http-kernel/Profiler\n")
            ->toContain("\n  - vendor/symfony/mime/DependencyInjection\n")
            ->toContain("\n  - vendor/symfony/mime/Test\n")
            ->toContain("\n  - vendor/symfony/service-contracts/Test\n")
            ->toContain("\n  - vendor/symfony/translation-contracts/Test\n")
            ->toContain("\n  - vendor/symfony/translation/DependencyInjection\n")
            ->toContain("\n  - vendor/symfony/translation/Extractor\n")
            ->toContain("\n  - vendor/symfony/translation/Test\n")
            ->toContain("\n  - vendor/symfony/console/DependencyInjection\n")
            ->toContain("\n  - vendor/symfony/console/Tester\n")
            ->toContain("\n  - vendor/symfony/event-dispatcher/Debug\n")
            ->toContain("\n  - vendor/symfony/event-dispatcher/DependencyInjection\n")
            ->toContain("\n  - vendor/symfony/http-foundation/Test\n")
            ->toContain("\n  - vendor/symfony/http-kernel/DependencyInjection\n")
            ->toContain("\n  - vendor/illuminate/session/Console\n")
            ->toContain("\n  - vendor/illuminate/support/Testing\n")
            ->toContain("\n  - vendor/nelexa/zip/.php-cs-fixer.php\n")
            ->toContain("\n  - vendor/nesbot/carbon/src/Carbon/Lang\n")
            ->toContain("\n  - vendor/nesbot/carbon/src/Carbon/List\n")
            ->toContain("\n  - vendor/nesbot/carbon/src/Carbon/PHPStan\n")
            ->toContain("\n  - vendor/saithink/saiadmin/src/orm\n")
            ->toContain("\n  - vendor/saithink/saiadmin/src/plugin\n")
            ->toContain("\n  - vendor/saithink/saipackage/src/plugin\n")
            ->toContain("\n  - vendor/tinywan/jwt/src/config\n")
            ->toContain("\n  - vendor/tinywan/jwt/tests\n")
            ->toContain("\n  - vendor/tinywan/storage/.php-cs-fixer.php\n")
            ->toContain("\n  - vendor/tinywan/storage/src/config\n")
            ->toContain("\n  - vendor/tinywan/storage/tests\n")
            ->toContain("\n  - vendor/tinywan/webman-typephp\n")
            ->toContain("\n  - vendor/topthink/think-container/tests\n")
            ->toContain("\n  - vendor/topthink/think-helper/src/helper.php\n")
            ->toContain("\n  - vendor/topthink/think-helper/tests\n")
            ->toContain("\n  - vendor/topthink/think-orm/stubs/load_stubs.php\n")
            ->toContain("\n  - vendor/topthink/think-orm/stubs/Facade.php\n")
            ->not
            ->toContain("\n  - vendor/topthink/think-orm/stubs\n")
            ->toContain("\n  - vendor/workerman/channel/test\n")
            ->toContain("\n  - vendor/symfony/error-handler/Resources\n")
            ->toContain("\n  - vendor/symfony/http-kernel/Resources\n")
            ->toContain("\n  - vendor/symfony/string/Resources\n")
            ->toContain("\n  - vendor/symfony/translation/Resources\n")
            ->toContain("\n  - vendor/symfony/var-dumper/Resources\n")
            ->toContain("\n  - vendor/symfony/polyfill-intl-idn/Resources\n")
            ->toContain("\n  - vendor/symfony/polyfill-intl-normalizer/Resources\n")
            ->toContain("\n  - vendor/symfony/polyfill-mbstring/Resources\n")
            ->toContain("\n  - vendor/symfony/polyfill-php80/Resources\n")
            ->toContain("\n  - vendor/symfony/polyfill-php83/Resources\n")
            ->toContain("\n  - vendor/symfony/polyfill-php84/Resources\n")
            ->toContain("\n  - vendor/symfony/polyfill-ctype/bootstrap.php\n")
            ->toContain("\n  - vendor/symfony/polyfill-ctype/bootstrap80.php\n");
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/illuminate/collections/helpers.php'])
            ->toBe('.typephp/build/illuminate-collections-helpers.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/illuminate/collections/functions.php'])
            ->toBe('.typephp/build/illuminate-collections-functions.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/illuminate/events/functions.php'])
            ->toBe('.typephp/build/illuminate-events-functions.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/illuminate/filesystem/functions.php'])
            ->toBe('.typephp/build/illuminate-filesystem-functions.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/illuminate/reflection/helpers.php'])
            ->toBe('.typephp/build/illuminate-reflection-helpers.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/illuminate/support/helpers.php'])
            ->toBe('.typephp/build/illuminate-support-helpers.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/illuminate/support/functions.php'])
            ->toBe('.typephp/build/illuminate-support-functions.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/ralouphie/getallheaders/src/getallheaders.php'])
            ->toBe('.typephp/build/getallheaders.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/symfony/deprecation-contracts/function.php'])
            ->toBe('.typephp/build/symfony-trigger-deprecation.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/symfony/var-dumper/Resources/functions/dump.php'])
            ->toBe('.typephp/build/symfony-var-dumper-functions.php');
        expect(ProjectGenerator::GUARDED_SOURCES['vendor/topthink/think-validate/src/helper.php'])
            ->toBe('.typephp/build/think-validate-functions.php');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands Illuminate support CarbonInterval factories in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/illuminate/support';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/functions.php', <<<'PHP'
        <?php
        if (! function_exists('microseconds')) {
            function microseconds(int|float $microseconds): CarbonInterval
            {
                return CarbonInterval::microseconds($microseconds);
            }
        }
        if (! function_exists('milliseconds')) {
            function milliseconds(int|float $milliseconds): CarbonInterval
            {
                return CarbonInterval::milliseconds($milliseconds);
            }
        }
        if (! function_exists('seconds')) {
            function seconds(int|float $seconds): CarbonInterval
            {
                return CarbonInterval::seconds($seconds);
            }
        }
        if (! function_exists('minutes')) {
            function minutes(int|float $minutes): CarbonInterval
            {
                return CarbonInterval::minutes($minutes);
            }
        }
        if (! function_exists('hours')) {
            function hours(int|float $hours): CarbonInterval
            {
                return CarbonInterval::hours($hours);
            }
        }
        if (! function_exists('days')) {
            function days(int|float $days): CarbonInterval
            {
                return CarbonInterval::days($days);
            }
        }
        if (! function_exists('weeks')) {
            function weeks(int $weeks): CarbonInterval
            {
                return CarbonInterval::weeks($weeks);
            }
        }
        if (! function_exists('months')) {
            function months(int $months): CarbonInterval
            {
                return CarbonInterval::months($months);
            }
        }
        if (! function_exists('years')) {
            function years(int $years): CarbonInterval
            {
                return CarbonInterval::years($years);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateFlattenedSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/illuminate-support-functions.php');
        expect($generated)
            ->toContain('new CarbonInterval(0, 0, 0, 0, 0, 0, 0, $microseconds)')
            ->toContain('new CarbonInterval(0, 0, 0, 0, 0, 0, 0, $milliseconds * 1000)')
            ->toContain('new CarbonInterval(0, 0, 0, 0, 0, 0, $seconds)')
            ->toContain('new CarbonInterval(0, 0, 0, 0, 0, $minutes)')
            ->toContain('new CarbonInterval(0, 0, 0, 0, $hours)')
            ->toContain('new CarbonInterval(0, 0, 0, $days)')
            ->toContain('new CarbonInterval(0, 0, $weeks)')
            ->toContain('new CarbonInterval(0, $months)')
            ->toContain('new CarbonInterval($years)')
            ->not->toContain('CarbonInterval::microseconds')
            ->not->toContain('CarbonInterval::milliseconds')
            ->not->toContain('CarbonInterval::seconds')
            ->not->toContain('CarbonInterval::minutes')
            ->not->toContain('CarbonInterval::hours')
            ->not->toContain('CarbonInterval::days')
            ->not->toContain('CarbonInterval::weeks')
            ->not->toContain('CarbonInterval::months')
            ->not->toContain('CarbonInterval::years');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps legacy flat webman helpers unchanged', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    $legacy = <<<'PHP'
        <?php

        use Webman\Config;

            /**
             * Get config
             */
            function config(?string $key = null, mixed $default = null)
            {
                return Config::get($key, $default);
            }

            /**
             * Base path
             */
            function base_path($path = ''): string
            {
                return path_combine(BASE_PATH, $path);
            }
        PHP;
    createWebmanHelpersFixture($directory, $legacy);

    try {
        $generator = new ProjectGenerator($directory);
        $helpersFile = $generator->generateHelpers();

        expect(file_get_contents((string) $helpersFile))->toBe($legacy);

        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain("\n  - .typephp/build/helpers.php\n")
            ->toContain("\n  - vendor/workerman/webman-framework/src/support/helpers.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rejects helpers containing unknown top-level guards', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    createWebmanHelpersFixture($directory, <<<'PHP'
        <?php

        if (!defined('WEBMAN_START_TIME')) {
            define('WEBMAN_START_TIME', microtime(true));
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect(fn(): ?string => $generator->generateHelpers())->toThrow(RuntimeException::class);
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('flattens namespaced fast-route functions guards into AOT sources', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    createWebmanHelpersFixture(
        $directory,
        "<?php\nfunction base_path(\$path = ''): string\n{\n    return \$path;\n}\n",
    );
    $fastRouteDirectory = $directory . DIRECTORY_SEPARATOR . 'vendor/nikic/fast-route/src';
    mkdir($fastRouteDirectory, 0777, true);
    file_put_contents($fastRouteDirectory . DIRECTORY_SEPARATOR . 'functions.php', <<<'PHP'
        <?php

        namespace FastRoute;

        if (!function_exists('FastRoute\simpleDispatcher')) {
            /**
             * @param callable $routeDefinitionCallback
             * @param array $options
             *
             * @return Dispatcher
             */
            function simpleDispatcher(callable $routeDefinitionCallback, array $options = [])
            {
                $options += [
                    'routeParser' => 'FastRoute\\RouteParser\\Std',
                ];
                return $options;
            }
        }

        if (!function_exists('FastRoute\cachedDispatcher')) {
            /**
             * @param callable $routeDefinitionCallback
             * @param array $options
             */
            function cachedDispatcher(callable $routeDefinitionCallback, array $options = [])
            {
                return simpleDispatcher($routeDefinitionCallback, $options);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generated = $generator->generateFlattenedSources();

        expect($generated)->toBe(['.typephp/build/helpers.php', '.typephp/build/fast-route-functions.php']);

        $flattened = (string) file_get_contents(
            $directory . DIRECTORY_SEPARATOR . '.typephp/build/fast-route-functions.php',
        );
        expect($flattened)
            ->toContain('namespace FastRoute;')
            ->toContain('function simpleDispatcher(callable $routeDefinitionCallback, array $options = [])')
            ->toContain('function cachedDispatcher(callable $routeDefinitionCallback, array $options = [])')
            ->toContain('$options = $options + [')
            ->not
            ->toContain('$options += [')
            ->toContain("'FastRoute\\\\RouteParser\\\\Std'");
        expect(str_contains($flattened, 'function_exists'))->toBeFalse();

        // yml 中注入平铺 fast-route 源并忽略 vendor 原版 functions.php
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain(
                "sources:\n  - main.php\n  - .typephp/build/helpers.php\n  - .typephp/build/fast-route-functions.php\n",
            )
            ->toContain("\n  - vendor/nikic/fast-route/src/functions.php\n")
            ->toContain("\n  - vendor/cakephp/chronos/rector.php\n")
            ->toContain("\n  - vendor/cakephp\n")
            ->toContain("\n  - vendor/robmorgan/phinx\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('flattens CakePHP function guards before converting its namespace', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/cakephp/core', 0777, true);
    file_put_contents($directory . '/vendor/cakephp/core/functions.php', <<<'PHP'
        <?php

        if (!defined('DS')) {
            /**
             * Defines DS as short form of DIRECTORY_SEPARATOR.
             */
            define('DS', DIRECTORY_SEPARATOR);
        }

        if (!defined('CAKE_DATE_RFC7231')) {
            define('CAKE_DATE_RFC7231', 'D, d M Y H:i:s \G\M\T');
        }

        namespace Cake\Core;

        if (!function_exists('Cake\Core\pathCombine')) {
            function pathCombine(array $parts, ?bool $trailing = null): string
            {
                return implode(DS, $parts);
            }
        }

        if (!function_exists('Cake\Core\h')) {
            function h(mixed $text): mixed
            {
                return $text;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generated = $generator->generateFlattenedSources();

        expect($generated)->toContain('.typephp/build/cakephp-core-functions.php');

        $flattened = (string) file_get_contents($directory . '/.typephp/build/cakephp-core-functions.php');
        expect($flattened)
            ->toContain("namespace {\nconst DS = DIRECTORY_SEPARATOR;")
            ->toContain('namespace Cake\Core {')
            ->toContain('function pathCombine(array $parts, ?bool $trailing = null): string')
            ->toContain('function h(mixed $text): mixed')
            ->not->toContain('function_exists');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('discovers and flattens first-party plugin function guards', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/app', 0777, true);
    mkdir($directory . '/plugin/example/app', 0777, true);
    file_put_contents($directory . '/app/functions.php', <<<'PHP'
        <?php
        if (!function_exists('application_id')) {
            function application_id(): string
            {
                return 'application';
            }
        }
        PHP);
    file_put_contents($directory . '/plugin/example/app/functions.php', <<<'PHP'
        <?php
        if (!function_exists('example_id')) {
            function example_id(): string
            {
                return 'example';
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->discoverProjectGuardedSources())->toBe([
            'app/functions.php' => '.typephp/build/app-functions.php',
            'plugin/example/app/functions.php' => '.typephp/build/example-functions.php',
        ]);
        expect($generator->generateFlattenedSources())->toBe([
            '.typephp/build/app-functions.php',
            '.typephp/build/example-functions.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/app-functions.php'))
            ->toContain('function application_id(): string')
            ->not->toContain('function_exists');
        expect(file_get_contents($directory . '/.typephp/build/example-functions.php'))
            ->toContain('function example_id(): string')
            ->not->toContain('function_exists');
        expect(file_get_contents($generator->generateProjectYml(['include' => ['plugin/example/app']])))
            ->toContain("  - .typephp/build/app-functions.php\n")
            ->toContain("  - .typephp/build/example-functions.php\n")
            ->toContain("  - app/functions.php\n")
            ->toContain("  - plugin/example/app/functions.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('flattens IP2Region guards while keeping its database as a runtime resource', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/zoujingli/ip2region', 0777, true);
    file_put_contents(
        $directory . '/vendor/zoujingli/ip2region/Ip2Region.php',
        "<?php\nif (!class_exists('Ip2Region')) {\nclass Ip2Region {}\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/zoujingli/ip2region/function.php',
        "<?php\nif (!class_exists('Ip2Region')) {\n"
        . "    require_once __DIR__ . '/Ip2Region.php';\n}\n"
        . "if (!function_exists('ip2region')) {\nfunction ip2region() { return new Ip2Region(); }\n}\n",
    );
    file_put_contents($directory . '/vendor/zoujingli/ip2region/ip2region.xdb', 'xdb');

    try {
        $generator = new ProjectGenerator($directory);
        $generated = $generator->generateFlattenedSources();
        expect($generated)
            ->toContain('.typephp/build/ip2region-class.php')
            ->toContain('.typephp/build/ip2region-functions.php');
        expect(file_get_contents($directory . '/.typephp/build/ip2region-class.php'))
            ->toContain('class Ip2Region')
            ->not->toContain('class_exists');
        expect(file_get_contents($directory . '/.typephp/build/ip2region-functions.php'))
            ->toContain('function ip2region()')
            ->not->toContain('require_once')
            ->not->toContain('function_exists');
        expect($generator->generateRuntimeResourceList([
            'vendor/zoujingli/ip2region/ip2region.xdb',
        ]))
            ->toBe('.typephp/build/runtime-resources.list');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('excludes installed Webman package installers and config templates', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/webman/cache/src/config', 0777, true);
    mkdir($directory . '/vendor/webman/cache/demo', 0777, true);
    mkdir($directory . '/vendor/webman/cache/tests', 0777, true);
    file_put_contents($directory . '/vendor/webman/cache/src/Install.php', "<?php\nclass Install {}\n");
    file_put_contents($directory . '/vendor/webman/cache/src/config/cache.php', "<?php\nreturn [];\n");

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->discoverWebmanVendorPackagingSources())->toBe([
            'vendor/webman/cache/demo',
            'vendor/webman/cache/src/Install.php',
            'vendor/webman/cache/src/config',
            'vendor/webman/cache/tests',
        ]);
        expect(file_get_contents($generator->generateProjectYml(['include' => ['vendor']])))
            ->toContain("  - vendor/webman/cache/src/Install.php\n")
            ->toContain("  - vendor/webman/cache/src/config\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('excludes vendor IDE metadata from compilation', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/example/package/src', 0777, true);
    file_put_contents($directory . '/vendor/example/package/.phpstorm.meta.php', "<?php\nnamespace PHPSTORM_META {}\n");
    file_put_contents($directory . '/vendor/example/package/src/Runtime.php', "<?php\nclass Runtime {}\n");

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->discoverVendorIdeMetadata())->toBe([
            'vendor/example/package/.phpstorm.meta.php',
        ]);
        expect(file_get_contents($generator->generateProjectYml(['include' => ['vendor']])))
            ->toContain("  - vendor/example/package/.phpstorm.meta.php\n")
            ->not->toContain("  - vendor/example/package/src/Runtime.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('replaces opcache preload hints with compiled AOT copies and rejects drift', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $source = $directory . '/vendor/symfony/cache-contracts/CacheTrait.php';
    mkdir(dirname($source), 0777, true);
    file_put_contents($source, "<?php\nclass_exists(InvalidArgumentException::class);\ntrait CacheTrait {}\n");

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generatePreloadHintSources())->toBe([
            '.typephp/build/symfony-cache-contracts-cache-trait.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/symfony-cache-contracts-cache-trait.php'))
            ->not
            ->toContain('class_exists(')
            ->toContain('trait CacheTrait');

        file_put_contents($source, "<?php\ntrait CacheTrait {}\n");
        expect(fn() => $generator->generatePreloadHintSources())
            ->toThrow(RuntimeException::class, 'expected 1 match(es), found 0');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses stable Symfony HttpKernel event variable types for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $source = $directory . '/vendor/symfony/http-kernel/HttpKernel.php';
    mkdir(dirname($source), 0777, true);
    $preloads = implode("\n", array_fill(0, 9, 'class_exists(PreloadedClass::class);'));
    file_put_contents($source, "<?php\n{$preloads}\n" . <<<'PHP'
        class HttpKernel
        {
            private function handleRaw($request, $type)
            {
                $event = new RequestEvent($this, $request, $type);
                $this->dispatcher->dispatch($event, KernelEvents::REQUEST);

                if ($event->hasResponse()) {
                    return $this->filterResponse($event->getResponse(), $request, $type);
                }

                $event = new ControllerEvent($this, $controller, $request, $type);
                $this->dispatcher->dispatch($event, KernelEvents::CONTROLLER);
                $controller = $event->getController();

                // controller arguments
                $arguments = $this->argumentResolver->getArguments($request, $controller, $event->getControllerReflector());

                $event = new ControllerArgumentsEvent($this, $event, $arguments, $request, $type);
                $this->dispatcher->dispatch($event, KernelEvents::CONTROLLER_ARGUMENTS);
                $controller = $event->getController();
                $arguments = $event->getArguments();

                if (!$response instanceof Response) {
                    $event = new ViewEvent($this, $request, $type, $response, $event);
                    $this->dispatcher->dispatch($event, KernelEvents::VIEW);

                    if ($event->hasResponse()) {
                        $response = $event->getResponse();
                    }
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generatePreloadHintSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/symfony-http-kernel.php');
        expect($generated)
            ->toContain('$requestEvent = new RequestEvent')
            ->toContain('$controllerEvent = new ControllerEvent')
            ->toContain('$controllerArgumentsEvent = new ControllerArgumentsEvent')
            ->toContain('$viewEvent = new ViewEvent')
            ->not->toContain('$event =');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('bridges Symfony native session bags without referencing the session superglobal for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $source = $directory . '/vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php';
    mkdir(dirname($source), 0777, true);
    file_put_contents($source, <<<'PHP'
        <?php
        class_exists(SessionBagInterface::class);
        class_exists(MetadataBag::class);
        class_exists(AbstractProxy::class);
        class NativeSessionStorage
        {
            protected function loadSession(?array &$session = null): void
            {
                if (null === $session) {
                    $session = &$_SESSION;
                }

                foreach ($this->bags as $bag) {
                    $key = $bag->getStorageKey();
                    $session[$key] = isset($session[$key]) && \is_array($session[$key]) ? $session[$key] : [];
                    $bag->initialize($session[$key]);
                }

                $this->started = true;
                $this->closed = false;
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generatePreloadHintSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/symfony-native-session-storage.php');
        expect($generated)
            ->toContain('protected function loadSession(mixed $session = null): void')
            ->toContain('$session = $_SESSION;')
            ->toContain('$_SESSION = $session;')
            ->not->toContain('$session = &$_SESSION;');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses dynamic reference storage for Symfony session bag initialization in AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $fixtures = [
        'Session/Attribute/AttributeBag.php' => ['attributes', 'symfony-session-attribute-bag.php'],
        'Session/Flash/FlashBag.php' => ['flashes', 'symfony-session-flash-bag.php'],
        'Session/Flash/AutoExpireFlashBag.php' => ['flashes', 'symfony-session-auto-expire-flash-bag.php'],
        'Session/SessionBagProxy.php' => ['array', 'symfony-session-bag-proxy.php'],
    ];
    foreach ($fixtures as $relative => [$parameter, $target]) {
        $source = $directory . '/vendor/symfony/http-foundation/' . $relative;
        if (!is_dir(dirname($source))) {
            mkdir(dirname($source), 0777, true);
        }
        file_put_contents(
            $source,
            "<?php\nclass SessionBag\n{\n"
            . (
                $relative === 'Session/SessionBagProxy.php'
                    ? "    public function __construct(\n"
                    . "        array &\$data,\n"
                    . "        ?int &\$usageIndex,\n"
                    . "    ) {}\n"
                    : ''
            )
            . "    public function initialize(array &\${$parameter}): void\n"
            . "    {\n        \$this->data = &\${$parameter};\n    }\n}\n",
        );
    }

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        foreach ($fixtures as [$parameter, $target]) {
            $generated = (string) file_get_contents($directory . '/.typephp/build/' . $target);
            expect($generated)->toContain("function initialize(mixed &\${$parameter}): void");
            if ($target === 'symfony-session-bag-proxy.php') {
                expect($generated)->toContain('mixed &$data,')->toContain('mixed &$usageIndex,');
            }
        }
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('prefers project support Request and Response overrides over framework defaults', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/support', 0777, true);
    mkdir($directory . '/vendor/workerman/webman-framework/src/support', 0777, true);
    file_put_contents($directory . '/support/Request.php', "<?php\nnamespace support;\nclass Request {}\n");
    file_put_contents(
        $directory . '/vendor/workerman/webman-framework/src/support/Request.php',
        "<?php\nnamespace support;\nclass Request {}\n",
    );
    file_put_contents(
        $directory . '/vendor/workerman/webman-framework/src/support/Response.php',
        "<?php\nnamespace support;\nclass Response {}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->discoverFrameworkSupportOverrides())->toBe([
            'vendor/workerman/webman-framework/src/support/Request.php',
        ]);
        expect(file_get_contents($generator->generateProjectYml(['include' => ['support', 'vendor']])))
            ->toContain("  - vendor/workerman/webman-framework/src/support/Request.php\n")
            ->not->toContain("  - vendor/workerman/webman-framework/src/support/Response.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('selects the PHP 8.4 intl polyfill declarations for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/polyfill-intl-grapheme';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/bootstrap80.php', <<<'PHP'
        <?php
        use Symfony\Polyfill\Intl\Grapheme as p;
        if (extension_loaded('intl')) {
            return;
        }
        if (!defined('GRAPHEME_EXTR_COUNT')) {
            define('GRAPHEME_EXTR_COUNT', 0);
        }
        if (!defined('GRAPHEME_EXTR_MAXBYTES')) {
            define('GRAPHEME_EXTR_MAXBYTES', 1);
        }
        if (!defined('GRAPHEME_EXTR_MAXCHARS')) {
            define('GRAPHEME_EXTR_MAXCHARS', 2);
        }
        if (\PHP_VERSION_ID >= 80500) {
            return require __DIR__.'/bootstrap85.php';
        }
        if (!function_exists('grapheme_strlen')) {
            function grapheme_strlen(?string $string): int|false|null { return p\Grapheme::grapheme_strlen((string) $string); }
        }
        PHP);
    $idnDirectory = $directory . '/vendor/symfony/polyfill-intl-idn';
    mkdir($idnDirectory, 0777, true);
    file_put_contents($idnDirectory . '/bootstrap80.php', <<<'PHP'
        <?php
        use Symfony\Polyfill\Intl\Idn as p;
        if (!defined('IDNA_DEFAULT')) {
            define('IDNA_DEFAULT', 0);
        }
        if (!defined('INTL_IDNA_VARIANT_UTS46')) {
            define('INTL_IDNA_VARIANT_UTS46', 1);
        }
        if (!function_exists('idn_to_ascii')) {
            function idn_to_ascii(?string $domain, ?int $flags = IDNA_DEFAULT): string|false { return p\Idn::idn_to_ascii((string) $domain, (int) $flags); }
        }
        PHP);
    $normalizerDirectory = $directory . '/vendor/symfony/polyfill-intl-normalizer';
    mkdir($normalizerDirectory, 0777, true);
    file_put_contents($normalizerDirectory . '/bootstrap80.php', <<<'PHP'
        <?php
        use Symfony\Polyfill\Intl\Normalizer as p;
        if (!function_exists('normalizer_is_normalized')) {
            function normalizer_is_normalized(?string $string, ?int $form = p\Normalizer::FORM_C): bool { return p\Normalizer::isNormalized((string) $string, (int) $form); }
        }
        if (!function_exists('normalizer_normalize')) {
            function normalizer_normalize(?string $string, ?int $form = p\Normalizer::FORM_C): string|false { return p\Normalizer::normalize((string) $string, (int) $form); }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateFlattenedSources())->toBe([
            '.typephp/build/symfony-grapheme-functions.php',
            '.typephp/build/symfony-idn-functions.php',
            '.typephp/build/symfony-normalizer-functions.php',
        ]);
        $flattened = (string) file_get_contents($directory . '/.typephp/build/symfony-grapheme-functions.php');
        expect($flattened)
            ->toContain('const GRAPHEME_EXTR_COUNT = 0;')
            ->toContain('function grapheme_strlen(?string $string): int|false|null');
        expect(str_contains($flattened, 'extension_loaded'))
            ->toBeFalse()
            ->and(str_contains($flattened, 'PHP_VERSION_ID'))
            ->toBeFalse()
            ->and(str_contains($flattened, 'function_exists'))
            ->toBeFalse();
        $idn = (string) file_get_contents($directory . '/.typephp/build/symfony-idn-functions.php');
        expect($idn)->toContain('const IDNA_DEFAULT = 0;')->toContain('function idn_to_ascii(?string $domain');
        expect(str_contains($idn, 'defined('))->toBeFalse()->and(str_contains($idn, 'function_exists'))->toBeFalse();
        $normalizer = (string) file_get_contents($directory . '/.typephp/build/symfony-normalizer-functions.php');
        expect($normalizer)
            ->toContain('function normalizer_is_normalized(?string $string')
            ->toContain('function normalizer_normalize(?string $string');
        expect(str_contains($normalizer, 'function_exists'))->toBeFalse();
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('declares the Symfony Grapheme cluster expression without top-level execution', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/polyfill-intl-grapheme';
    mkdir($sourceDirectory, 0777, true);
    $definition =
        "\\define('SYMFONY_GRAPHEME_CLUSTER_RX', ((float) \\PCRE_VERSION >= 10.44) "
        . "? '\\X' : Grapheme::GRAPHEME_CLUSTER_RX);";
    file_put_contents(
        $sourceDirectory . '/Grapheme.php',
        "<?php\nnamespace Symfony\\Polyfill\\Intl\\Grapheme;\n{$definition}\n"
        . "final class Grapheme { public const GRAPHEME_CLUSTER_RX = 'fallback'; }\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/symfony-grapheme.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/symfony-grapheme.php');
        expect($source)
            ->toContain('const SYMFONY_GRAPHEME_CLUSTER_RX = Grapheme::GRAPHEME_CLUSTER_RX;')
            ->not->toContain('\\define(');

        file_put_contents(
            $sourceDirectory . '/Grapheme.php',
            "<?php\nnamespace Symfony\\Polyfill\\Intl\\Grapheme;\nfinal class Grapheme {}\n",
        );
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'compatibility rule expected 1 match(es), found 0: '
                . 'vendor/symfony/polyfill-intl-grapheme/Grapheme.php',
            );

        file_put_contents(
            $sourceDirectory . '/Grapheme.php',
            "<?php\nnamespace Symfony\\Polyfill\\Intl\\Grapheme;\n{$definition}\n{$definition}\n"
            . "final class Grapheme { public const GRAPHEME_CLUSTER_RX = 'fallback'; }\n",
        );
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'compatibility rule expected 1 match(es), found 2: '
                . 'vendor/symfony/polyfill-intl-grapheme/Grapheme.php',
            );
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('skips host-provided guarded polyfills for native compilation', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/polyfill-intl-grapheme';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($directory . '/main.php', "<?php\nfunction main(): void {}\n");
    file_put_contents($sourceDirectory . '/bootstrap80.php', "<?php\nfunction grapheme_str_split() {}\n");

    try {
        $generator = new ProjectGenerator($directory);
        $yml = (string) file_get_contents($generator->generateProjectYml([
            'include' => ['main.php'],
            'build' => [
                'skip_guarded_sources' => [
                    'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php',
                ],
            ],
        ], 'project.native.yml'));

        expect($yml)
            ->not
            ->toContain('.typephp/build/symfony-grapheme-functions.php')
            ->toContain('vendor/symfony/polyfill-intl-grapheme/bootstrap80.php');
        expect(is_file($directory . '/.typephp/build/symfony-grapheme-functions.php'))->toBeFalse();
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rejects unknown guarded source skips', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);

    try {
        expect(fn() => new ProjectGenerator($directory)->generateProjectYml([
            'build' => ['skip_guarded_sources' => ['vendor/unknown/bootstrap.php']],
        ], 'project.native.yml'))
            ->toThrow(RuntimeException::class, 'Unknown guarded source skip');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('selects the PHP 8.4 branch of the PHP 8.5 polyfill for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $php85Directory = $directory . '/vendor/symfony/polyfill-php85';
    mkdir($php85Directory . '/Resources/stubs/Filter', 0777, true);
    file_put_contents($php85Directory . '/bootstrap.php', <<<'PHP'
        <?php
        use Symfony\Polyfill\Php85 as p;
        if (\PHP_VERSION_ID >= 80500) {
            return;
        }
        if (!function_exists('get_error_handler')) {
            function get_error_handler(): ?callable { return p\Php85::get_error_handler(); }
        }
        if (!function_exists('get_exception_handler')) {
            function get_exception_handler(): ?callable { return p\Php85::get_exception_handler(); }
        }
        if (!function_exists('array_first')) {
            function array_first(array $array) { return p\Php85::array_first($array); }
        }
        if (!function_exists('array_last')) {
            function array_last(array $array) { return p\Php85::array_last($array); }
        }
        if (extension_loaded('intl') && !function_exists('locale_is_right_to_left')) {
            function locale_is_right_to_left(string $locale): bool { return p\Php85::locale_is_right_to_left($locale); }
        }
        if (\PHP_VERSION_ID >= 80000) {
            require __DIR__.'/bootstrap80.php';

            return;
        }
        if (!class_exists('ValueError', false)) {
            class ValueError extends Error {}
        }
        if (extension_loaded('intl') && !function_exists('grapheme_levenshtein')) {
            function grapheme_levenshtein(string $first, string $second) { return false; }
        }
        PHP);
    $stubs = [
        'DelayedTargetValidation.php' => <<<'PHP'
            <?php
            if (\PHP_VERSION_ID < 80500) {
                #[Attribute(Attribute::TARGET_ALL)]
                final class DelayedTargetValidation {}
            }
            PHP,
        'NoDiscard.php' => <<<'PHP'
            <?php
            if (\PHP_VERSION_ID < 80500) {
                #[Attribute(Attribute::TARGET_METHOD)]
                final class NoDiscard {}
            }
            PHP,
        'Filter/FilterException.php' => <<<'PHP'
            <?php
            namespace Filter;
            if (\PHP_VERSION_ID < 80500) {
                class FilterException extends \Exception {}
            }
            PHP,
        'Filter/FilterFailedException.php' => <<<'PHP'
            <?php
            namespace Filter;
            if (\PHP_VERSION_ID < 80500) {
                class FilterFailedException extends FilterException {}
            }
            PHP,
    ];
    foreach ($stubs as $path => $content) {
        file_put_contents($php85Directory . '/Resources/stubs/' . $path, $content);
    }

    try {
        $generated = new ProjectGenerator($directory)->generateFlattenedSources();
        expect($generated)->toBe([
            '.typephp/build/symfony-php85-functions.php',
            '.typephp/build/php85-delayed-target-validation.php',
            '.typephp/build/php85-no-discard.php',
            '.typephp/build/php85-filter-exception.php',
            '.typephp/build/php85-filter-failed-exception.php',
        ]);
        $functions = (string) file_get_contents($directory . '/.typephp/build/symfony-php85-functions.php');
        foreach (['get_error_handler', 'get_exception_handler', 'array_first', 'array_last'] as $function) {
            expect($functions)->toContain("function {$function}(");
        }
        foreach ([
            'function_exists',
            'PHP_VERSION_ID',
            'extension_loaded',
            'locale_is_right_to_left',
            'ValueError',
        ] as $absent) {
            expect(str_contains($functions, $absent))->toBeFalse();
        }
        foreach (array_values(array_slice(ProjectGenerator::GUARDED_SOURCES, -4)) as $target) {
            $stub = (string) file_get_contents($directory . '/' . $target);
            expect(str_contains($stub, 'PHP_VERSION_ID'))->toBeFalse();
        }
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('replaces the known Guzzle files wrapper with its declaration source', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $guzzleDirectory = $directory . '/vendor/guzzlehttp/guzzle/src';
    mkdir($guzzleDirectory, 0777, true);
    file_put_contents(
        $guzzleDirectory . '/functions_include.php',
        "<?php\n// loader\nif (!\\function_exists('GuzzleHttp\\describe_type')) {\n"
        . "    require __DIR__.'/functions.php';\n}\n",
    );
    file_put_contents(
        $guzzleDirectory . '/functions.php',
        "<?php\nnamespace GuzzleHttp;\nfunction describe_type(\$value): string { return get_debug_type(\$value); }\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        $yml = (string) file_get_contents($generator->generateProjectYml(['include' => ['vendor']]));
        expect(substr_count($yml, "  - vendor/guzzlehttp/guzzle/src/functions.php\n"))->toBe(0);
        expect($yml)->toContain("\n  - vendor/guzzlehttp/guzzle/src/functions_include.php\n");
        $defaultYml = (string) file_get_contents($generator->generateProjectYml([]));
        expect(substr_count($defaultYml, "  - vendor/guzzlehttp/guzzle/src/functions.php\n"))->toBe(1);
        expect($yml)->toContain("\n  - support/bootstrap.php\n");
        foreach (['app/model', 'app/command', 'app/functions.php', 'support'] as $path) {
            expect(str_contains($yml, "\n  - {$path}\n"))->toBeFalse();
        }
        expect($yml)->toContain("\n  - app/process/Monitor.php\n");

        file_put_contents(
            $guzzleDirectory . '/functions_include.php',
            "<?php\nif (!\\function_exists('GuzzleHttp\\describe_type')) { require \$dynamic; }\n",
        );
        expect(fn() => $generator->generateProjectYml(['include' => ['vendor']]))
            ->toThrow(RuntimeException::class, 'Unsupported static loader wrapper');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('patches uninitialized scalar statics in coroutine sources into AOT sources', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    createWebmanHelpersFixture(
        $directory,
        "<?php\nfunction base_path(\$path = ''): string\n{\n    return \$path;\n}\n",
    );
    $coroutineDirectory = $directory . DIRECTORY_SEPARATOR . 'vendor/workerman/coroutine/src';
    mkdir($coroutineDirectory, 0777, true);
    file_put_contents($coroutineDirectory . DIRECTORY_SEPARATOR . 'Context.php', <<<'PHP'
        <?php

        namespace Workerman\Coroutine;

        use Workerman\Worker;

        class Context implements ContextInterface
        {
            protected static string $driver;

            public static function initDriver(): void
            {
                static::$driver ??= match (Worker::$eventLoopClass) {
                    default => Context\Fiber::class,
                };
            }
        }
        PHP);
    file_put_contents($coroutineDirectory . DIRECTORY_SEPARATOR . 'WaitGroup.php', <<<'PHP'
        <?php

        namespace Workerman\Coroutine;

        class WaitGroup
        {
            protected static string $driverClass;

            public static function create(): void
            {
                static $id = 0;
                static::$driverClass ??= 'Fiber';
            }
        }
        PHP);
    mkdir($coroutineDirectory . DIRECTORY_SEPARATOR . 'Barrier', 0777, true);
    file_put_contents($coroutineDirectory . DIRECTORY_SEPARATOR . 'Barrier.php', <<<'PHP'
        <?php
        class Barrier
        {
            protected static string $driver;
            public static function wait(object &$barrier, int $timeout = -1): void {}
        }
        PHP);
    file_put_contents($coroutineDirectory . DIRECTORY_SEPARATOR . 'Barrier/BarrierInterface.php', <<<'PHP'
        <?php
        interface BarrierInterface
        {
            public static function wait(object &$barrier, int $timeout = -1): void;
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generated = $generator->generateNullableStaticSources();

        expect($generated)->toBe([
            '.typephp/build/coroutine-context.php',
            '.typephp/build/coroutine-wait-group.php',
            '.typephp/build/coroutine-barrier.php',
            '.typephp/build/coroutine-barrier-interface.php',
        ]);

        // 未初始化标量静态属性补成可空 + 默认 null，??= 守卫主体保持原样
        $context = (string) file_get_contents($directory . '/.typephp/build/coroutine-context.php');
        expect($context)
            ->toContain('protected static ?string $driver = null;')
            ->toContain('static::$driver ??= match (Worker::$eventLoopClass)');
        expect((string) file_get_contents($directory . '/.typephp/build/coroutine-barrier.php'))
            ->toContain('public static function wait(mixed &$barrier, int $timeout = -1): void');
        expect((string) file_get_contents($directory . '/.typephp/build/coroutine-barrier-interface.php'))
            ->toContain('public static function wait(mixed &$barrier, int $timeout = -1): void;');
        expect(str_contains($context, 'protected static string $driver;'))->toBeFalse();

        // 函数内局部 static 与已带默认值的属性不受补丁影响
        $waitGroup = (string) file_get_contents($directory . '/.typephp/build/coroutine-wait-group.php');
        expect($waitGroup)->toContain('protected static ?string $driverClass = null;')->toContain('static $id = 0;');

        // yml 在平铺源之后注入补丁源，并忽略 vendor 原版三个文件
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain(
                "sources:\n  - main.php\n  - .typephp/build/helpers.php\n"
                . "  - .typephp/build/coroutine-context.php\n  - .typephp/build/coroutine-wait-group.php\n",
            )
            ->toContain("\n  - vendor/workerman/coroutine/src/Context.php\n")
            ->toContain("\n  - vendor/workerman/coroutine/src/WaitGroup.php\n")
            ->toContain("\n  - vendor/workerman/coroutine/src/Barrier.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('patches under-declared handler closures into variadic AOT sources', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    createWebmanHelpersFixture(
        $directory,
        "<?php\nfunction base_path(\$path = ''): string\n{\n    return \$path;\n}\n",
    );
    $workermanSrc = $directory . '/vendor/workerman/workerman/src';
    mkdir($workermanSrc . '/Connection', 0777, true);
    mkdir($workermanSrc . '/Events', 0777, true);

    $worker = <<<'PHP'
        <?php
        namespace Workerman;
        class Worker
        {
            protected function acceptTcpConnection($socket): void
            {
                set_error_handler(static fn (): bool => true);
                $newSocket = stream_socket_accept($socket, 0, $remoteAddress);
                restore_error_handler();
            }
            protected function listen(): void
            {
                set_error_handler(function ($code, $msg) {
                    throw new RuntimeException($msg);
                });
                restore_error_handler();
            }
            public static function stopAll(): void
            {
                $workers = [];
                array_walk($workers, static fn (Worker $worker) => $worker->stop(false));
                $workerPidArray = [];
                array_walk($workerPidArray, static fn ($pid) => posix_kill($pid, $sig));
            }
            protected static function installSignal(): void
            {
                $signal = 2;
                pcntl_signal($signal, static::signalHandler(...), false);
            }
            public static function resetStd(): void
            {
                // 整块删除规则按字节匹配；下面关闭段的缩进与空行必须与真实 vendor Worker.php 一致，勿改
                if (is_resource(STDOUT)) {
                    fclose(STDOUT);
                }

                if (is_resource(STDERR)) {
                    fclose(STDERR);
                }

                if (is_resource(static::$outputStream)) {
                    fclose(static::$outputStream);
                }

                $stdOutStream = fopen(static::$stdoutFile, 'a');
            }
        }
        PHP;
    file_put_contents($workermanSrc . '/Worker.php', $worker);
    file_put_contents(
        $workermanSrc . '/Timer.php',
        "<?php\nnamespace Workerman;\nclass Timer\n{\n"
        . "    public static function init(): void\n    {\n"
        . "        pcntl_signal(SIGALRM, self::signalHandle(...), false);\n"
        . "    }\n"
        . "    public static function signalHandle(): void {}\n}\n",
    );

    $select = <<<'PHP'
        <?php
        namespace Workerman\Events;
        class Select
        {
            public function onSignal(int $signal, callable $func): void
            {
                pcntl_signal($signal, fn () => $this->safeCall($this->signalEvents[$signal], [$signal]));
            }
        }
        PHP;
    file_put_contents($workermanSrc . '/Events/Select.php', $select);

    file_put_contents(
        $workermanSrc . '/Connection/TcpConnection.php',
        "<?php\nnamespace Workerman\\Connection;\nclass TcpConnection\n{\n"
        . "    public function enableSsl(): void\n    {\n"
        . "        \$reason = '';\n"
        . "        set_error_handler(static function (int \$code, string \$msg) use (&\$reason): bool {\n"
        . "            \$reason = \$msg;\n            return true;\n        });\n"
        . "        try {\n            \$ret = true;\n        } finally {\n            restore_error_handler();\n        }\n"
        . "        // Negotiation has failed.\n    }\n}\n",
    );
    file_put_contents(
        $workermanSrc . '/Connection/AsyncTcpConnection.php',
        "<?php\nnamespace Workerman\\Connection;\nclass AsyncTcpConnection\n{\n"
        . "    public function connect(): void\n    {\n"
        . "        set_error_handler(fn() => false);\n"
        . "        restore_error_handler();\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/workerman/webman-framework/src/File.php',
        "<?php\nnamespace Webman;\nclass File\n{\n"
        . "    public function move(string \$destination): File\n    {\n"
        . "        set_error_handler(function (\$type, \$msg) use (&\$error) {\n"
        . "            \$error = \$msg;\n        });\n        restore_error_handler();\n        return \$this;\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        $generated = $generator->generateVariadicHandlerSources();

        expect($generated)->toBe([
            '.typephp/build/workerman-worker.php',
            '.typephp/build/workerman-timer.php',
            '.typephp/build/workerman-tcp-connection.php',
            '.typephp/build/workerman-async-tcp-connection.php',
            '.typephp/build/workerman-select.php',
            '.typephp/build/webman-file.php',
        ]);

        // 零参/两参错误处理闭包与零参信号闭包均补成可变参数形态
        $workerPatched = (string) file_get_contents($directory . '/.typephp/build/workerman-worker.php');
        expect($workerPatched)
            ->toContain('set_error_handler(static fn (...$__err): bool => true);')
            ->toContain('set_error_handler(function ($code, $msg, ...$__err) {');
        expect(str_contains($workerPatched, 'static fn (): bool => true'))->toBeFalse();

        // 停机/重载路径：array_walk 2 参(value,key)、master pcntl_signal 2 参(signo,siginfo) 均补成可变参数形态
        expect($workerPatched)
            ->toContain('array_walk($workers, static fn (Worker $worker, ...$__walk) => $worker->stop(false));')
            ->toContain('array_walk($workerPidArray, static fn ($pid, ...$__walk) => posix_kill($pid, $sig));')
            ->toContain('pcntl_signal($signal, static fn (...$__sig) => static::signalHandler($__sig[0]), false);');
        expect(str_contains(
            $workerPatched,
            'array_walk($workers, static fn (Worker $worker) => $worker->stop(false));',
        ))->toBeFalse();
        expect(str_contains($workerPatched, 'pcntl_signal($signal, static::signalHandler(...), false);'))->toBeFalse();

        $timerPatched = (string) file_get_contents($directory . '/.typephp/build/workerman-timer.php');
        expect($timerPatched)
            ->toContain('pcntl_signal(SIGALRM, static fn (...$__sig) => self::signalHandle(), false);')
            ->not->toContain('pcntl_signal(SIGALRM, self::signalHandle(...), false);');

        // daemon 模式 resetStd()：TypePHP 标准流 NO_CLOSE 禁止关闭，三处 fclose 段被整块删除；
        // 日志重定向（fopen stdoutFile → outputStream）保留，与 stock 行为一致
        expect(str_contains($workerPatched, 'is_resource(STDOUT)'))->toBeFalse();
        expect(str_contains($workerPatched, 'fclose(STDOUT);'))->toBeFalse();
        expect(str_contains($workerPatched, 'is_resource(STDERR)'))->toBeFalse();
        expect(str_contains($workerPatched, 'fclose(STDERR);'))->toBeFalse();
        expect(str_contains($workerPatched, 'is_resource(static::$outputStream)'))->toBeFalse();
        expect(str_contains($workerPatched, 'fclose(static::$outputStream);'))->toBeFalse();
        expect($workerPatched)->toContain('$stdOutStream = fopen(static::$stdoutFile, \'a\');');

        $selectPatched = (string) file_get_contents($directory . '/.typephp/build/workerman-select.php');
        expect($selectPatched)->toContain('pcntl_signal($signal, fn (...$__sig) => $this->safeCall(');

        $tcpPatched = (string) file_get_contents($directory . '/.typephp/build/workerman-tcp-connection.php');
        expect($tcpPatched)
            ->toContain('set_error_handler(static function (int $code, string $msg, ...$__err) use (&$reason): bool {')
            ->toContain("if (\$reason === null) {\n            \$reason = '';")
            ->not->toContain("\$reason = '';\n        set_error_handler");

        $asyncPatched = (string) file_get_contents($directory . '/.typephp/build/workerman-async-tcp-connection.php');
        expect($asyncPatched)->toContain('set_error_handler(fn(...$__err) => false);');

        $filePatched = (string) file_get_contents($directory . '/.typephp/build/webman-file.php');
        expect($filePatched)->toContain('set_error_handler(function ($type, $msg, ...$__err) use (&$error) {');

        // yml 注入全部补丁源，并忽略 vendor 原版文件
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain('  - .typephp/build/workerman-worker.php' . "\n")
            ->toContain('  - .typephp/build/workerman-timer.php' . "\n")
            ->toContain('  - .typephp/build/workerman-select.php' . "\n")
            ->toContain("\n  - vendor/workerman/workerman/src/Worker.php\n")
            ->toContain("\n  - vendor/workerman/workerman/src/Timer.php\n")
            ->toContain("\n  - vendor/workerman/workerman/src/Events/Select.php\n")
            ->toContain("\n  - vendor/workerman/webman-framework/src/File.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('strips stray top-level bootstrap calls into AOT sources', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);

    $httpDirectory = $directory . '/vendor/workerman/workerman/src/Protocols/Http';
    mkdir($httpDirectory . '/Session', 0777, true);
    $coroutineDirectory = $directory . '/vendor/workerman/coroutine/src';
    mkdir($coroutineDirectory . '/Context', 0777, true);
    mkdir($coroutineDirectory . '/Coroutine', 0777, true);

    // 顶层引导调用（workerman/coroutine 与 workerman 的模块初始化写法）
    file_put_contents(
        $httpDirectory . '/Session.php',
        "<?php\nnamespace Workerman\\Protocols\\Http;\nclass Session\n{\n"
        . "    public static function init(): void\n    {\n    }\n}\n\n// Init session handler\nSession::init();\n",
    );
    file_put_contents(
        $httpDirectory . '/Session/FileSessionHandler.php',
        "<?php\nnamespace Workerman\\Protocols\\Http\\Session;\nclass FileSessionHandler\n{\n"
        . "    public static function init(): void\n    {\n    }\n}\n\nFileSessionHandler::init();\n",
    );
    file_put_contents(
        $coroutineDirectory . '/Context/Fiber.php',
        "<?php\nnamespace Workerman\\Coroutine\\Context;\nclass Fiber\n{\n"
        . "    private static \\ArrayObject \$nonFiberContext;\n"
        . "    private static \\WeakMap \$contexts;\n\n"
        . "    public static function set(string \$name, mixed \$value): void\n"
        . "    {\n"
        . "        \$fiber = \\Fiber::getCurrent();\n"
        . "        if (\$fiber === null) {\n"
        . "            static::\$nonFiberContext[\$name] = \$value;\n"
        . "            return;\n"
        . "        }\n"
        . "        static::\$contexts[\$fiber][\$name] = \$value;\n"
        . "    }\n\n"
        . "    public static function initContext(): void\n    {\n    }\n}\n\nFiber::initContext();\n",
    );
    file_put_contents(
        $coroutineDirectory . '/Coroutine/Fiber.php',
        "<?php\nnamespace Workerman\\Coroutine\\Coroutine;\nclass Fiber\n{\n"
        . "    public static function init(): void\n    {\n    }\n}\n\nFiber::init();\n",
    );
    file_put_contents(
        $coroutineDirectory . '/Coroutine.php',
        "<?php\nnamespace Workerman\\Coroutine;\nclass Coroutine\n{\n"
        . "    public static function init(): void\n    {\n    }\n}\n\nCoroutine::init();\n",
    );
    // 回归用例：静态属性补丁源同样必须剥掉尾部顶层引导调用，否则 AOT 扫描期报 stray code Fatal
    file_put_contents(
        $coroutineDirectory . '/Context.php',
        "<?php\nnamespace Workerman\\Coroutine;\nclass Context\n{\n"
        . "    protected static string \$driver;\n\n"
        . "    public static function initDriver(): void\n    {\n        static::\$driver ??= 'Fiber';\n    }\n}\n\nContext::initDriver();\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateStrayBootstrapSources())->toBe([
            '.typephp/build/http-session.php',
            '.typephp/build/http-session-file-handler.php',
            '.typephp/build/coroutine-context-fiber.php',
            '.typephp/build/coroutine-fiber.php',
            '.typephp/build/coroutine-coroutine.php',
        ]);

        // 每个剥离后的 AOT 源以类结束大括号收尾，紧邻引导调用的注释行一并移除；init 方法体保持原样
        foreach (ProjectGenerator::STRAY_BOOTSTRAP_SOURCES as $target) {
            $content = rtrim((string) file_get_contents($directory . '/' . $target));
            expect(str_ends_with($content, '}'))->toBeTrue();
        }
        $session = (string) file_get_contents($directory . '/.typephp/build/http-session.php');
        expect(str_contains($session, '// Init session handler'))->toBeFalse();
        expect($session)->toContain("class Session\n{\n    public static function init(): void");
        $fiberContext = (string) file_get_contents($directory . '/.typephp/build/coroutine-context-fiber.php');
        expect($fiberContext)
            ->toContain('static::$nonFiberContext->offsetSet($name, $value);')
            ->toContain('static::$contexts[$fiber]->offsetSet($name, $value);')
            ->not->toContain('static::$nonFiberContext[$name] = $value;')
            ->not->toContain('static::$contexts[$fiber][$name] = $value;');

        expect($generator->generateNullableStaticSources())->toBe(['.typephp/build/coroutine-context.php']);

        // 回归：静态属性补丁与顶层引导剥离在同一产物上串联生效
        $context = (string) file_get_contents($directory . '/.typephp/build/coroutine-context.php');
        expect($context)
            ->toContain('protected static ?string $driver = null;')
            ->toContain("static::\$driver ??= 'Fiber';");
        expect(str_contains($context, 'Context::initDriver();'))->toBeFalse();

        // yml 注入剥离源并忽略 vendor 原版
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain('  - .typephp/build/http-session.php' . "\n")
            ->toContain('  - .typephp/build/coroutine-coroutine.php' . "\n")
            ->toContain("\n  - vendor/workerman/workerman/src/Protocols/Http/Session.php\n")
            ->toContain("\n  - vendor/workerman/coroutine/src/Context/Fiber.php\n")
            ->toContain("\n  - vendor/workerman/coroutine/src/Coroutine.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rewrites fall-through switch cases into terminal AOT sources', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    mkdir($directory . '/vendor/workerman/webman-framework/src', 0777, true);
    mkdir($directory . '/vendor/monolog/monolog/src/Monolog', 0777, true);
    mkdir($directory . '/vendor/illuminate/bus', 0777, true);
    mkdir($directory . '/vendor/illuminate/collections', 0777, true);
    mkdir($directory . '/vendor/illuminate/database/Eloquent', 0777, true);
    mkdir($directory . '/vendor/illuminate/database/Eloquent/Concerns', 0777, true);
    mkdir($directory . '/vendor/symfony/cache/Traits', 0777, true);
    mkdir($directory . '/vendor/twig/twig/src/Extension', 0777, true);
    mkdir($directory . '/vendor/twig/twig/src/Node', 0777, true);

    file_put_contents($directory . '/vendor/workerman/webman-framework/src/App.php', implode("\n", [
        '<?php',
        'namespace Webman;',
        'class App',
        '{',
        '    public static function methodNotAllowed(string $allowHeader): mixed',
        '    {',
        '        return static function () use ($allowHeader) {',
        '            return $allowHeader;',
        '        };',
        '    }',
        '    public static function fallback(string $plugin, int $status): mixed',
        '    {',
        '        return Route::getFallback($plugin, $status) ?: function () {',
        '            throw new \RuntimeException();',
        '        };',
        '    }',
        '    public static function callbacks(string $key, string $file): void',
        '    {',
        '            static::collectCallbacks($key, [function () use ($file) {',
        '                return static::execPhpFile($file);',
        '            }]);',
        '    }',
        '    public static function getReflector(callable|string $call, ?string $cacheKey): mixed',
        '    {',
        "        if (\$call instanceof Closure || is_string(\$call)) {",
        '            $reflector = new ReflectionFunction($call);',
        '        } else {',
        '            $reflector = new ReflectionMethod($call[0], $call[1]);',
        '        }',
        '',
        '        if ($cacheKey !== null) {',
        '            static::$reflectorCache[$cacheKey] = $reflector;',
        '            if (count(static::$reflectorCache) > 1024) {',
        '                unset(static::$reflectorCache[key(static::$reflectorCache)]);',
        '            }',
        '        }',
        '',
        '        return $reflector;',
        '    }',
        "    public static function stringify(mixed \$data): string",
        '    {',
        "        switch (gettype(\$data)) {",
        "            case 'object':",
        "                if (!method_exists(\$data, '__toString')) {",
        "                    return 'Object';",
        '                }',
        '            default:',
        '                return (string)$data;',
        '        }',
        '    }',
        '}',
        '',
    ]));
    file_put_contents($directory . '/vendor/monolog/monolog/src/Monolog/Utils.php', implode("\n", [
        '<?php',
        'namespace Monolog;',
        'class Utils',
        '{',
        "    public static function throwJsonError(int \$code): void",
        '    {',
        '        switch ($code) {',
        '            case 8:',
        "                \$msg = 'Syntax error, malformed JSON';",
        '                break;',
        '            default:',
        "                \$msg = 'Unknown error';",
        '        }',
        '    }',
        '    public static function parseBytes(string $value): ?int',
        '    {',
        "        \$val = (int) \$value;",
        '        switch (strtolower(substr($value, -1))) {',
        "            case 'g':",
        '                $val *= 1024;',
        "            case 'm':",
        '                $val *= 1024;',
        "            case 'k':",
        '                $val *= 1024;',
        '        }',
        '        return $val;',
        '    }',
        '}',
        '',
    ]));
    file_put_contents(
        $directory . '/vendor/illuminate/bus/Batch.php',
        "<?php\nclass Batch\n{\n    public function add(\$jobs)\n    {\n"
        . "        \$count = 0;\n        \$jobs = Collection::wrap(\$jobs)->map(function (\$job) use (&\$count) {\n"
        . "            if (is_array(\$job)) {\n                \$count += count(\$job);\n"
        . "            } else {\n                \$count++;\n            }\n            return \$job;\n        });\n\n"
        . "        \$this->repository->transaction(function () use (\$jobs, \$count) {\n"
        . "            return \$count;\n        });\n    }\n"
        . "    public function toArray()\n    {\n        return [];\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/illuminate/collections/Enumerable.php',
        "<?php\ninterface Enumerable\n{\n    public function toArray();\n"
        . "    public function unless(\$value, callable \$callback, ?callable \$default = null);\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/illuminate/database/Eloquent/Concerns/HasAttributes.php',
        "<?php\ntrait HasAttributes\n{\n    public function relationsToArray()\n    {\n"
        . "        \$attributes = [];\n        foreach (\$this->getArrayableRelations() as \$key => \$value) {\n"
        . "            if (\$value instanceof Arrayable) {\n                \$relation = \$value->toArray();\n"
        . "            } elseif (is_null(\$value)) {\n                \$relation = \$value;\n            }\n"
        . "            if (array_key_exists('relation', get_defined_vars())) { // check if \$relation is in scope (could be null)\n"
        . "                \$attributes[\$key] = \$relation ?? null;\n            }\n            unset(\$relation);\n"
        . "        }\n        return \$attributes;\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/illuminate/database/Eloquent/Model.php',
        "<?php\nclass Model\n{\n    public function toArray()\n    {\n        return [];\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/symfony/cache/Traits/AbstractAdapterTrait.php',
        "<?php\ntrait AbstractAdapterTrait\n{\n    private function generateItems(iterable \$items, array &\$keys): \\Generator\n    {\n        yield from \$items;\n    }\n}\n",
    );
    file_put_contents($directory . '/vendor/symfony/cache/Traits/ValueWrapper.php', "<?php\nclass \xA9\n{\n}\n");
    file_put_contents(
        $directory . '/vendor/symfony/cache/CacheItem.php',
        "<?php\nclass CacheItem\n{\n    private const VALUE_WRAPPER = \"\\xA9\";\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/twig/twig/src/Node/Node.php',
        "<?php\nclass Node\n{\n    public function __construct(array \$nodes = [], array \$attributes = [], int \$lineno = 0)\n    {\n"
        . "        \$this->nodes = \$nodes;\n        \$this->attributes = \$attributes;\n        \$this->lineno = \$lineno;\n\n"
        . "        if (\\func_num_args() > 3) {\n"
        . "            trigger_deprecation('twig/twig', '3.12', \\sprintf('The \"tag\" constructor argument of the \"%s\" class is deprecated and ignored (check which TokenParser class set it to \"%s\"), the tag is now automatically set by the Parser when needed.', static::class, func_get_arg(3) ?: 'null'));\n"
        . "        }\n    }\n"
        . "    public function getAttribute(string \$name)\n    {\n"
        . "        \$triggerDeprecation = \\func_num_args() > 1 ? func_get_arg(1) : true;\n"
        . "        return \$name;\n    }\n"
        . "    public function setAttribute(string \$name, \$value): void\n    {\n"
        . "        \$triggerDeprecation = \\func_num_args() > 2 ? func_get_arg(2) : true;\n    }\n"
        . "    public function getNode(string \$name): self\n    {\n"
        . "        \$triggerDeprecation = \\func_num_args() > 1 ? func_get_arg(1) : true;\n"
        . "        return \$this;\n    }\n"
        . "    public function setNode(string \$name, self \$node): void\n    {\n"
        . "        \$triggerDeprecation = \\func_num_args() > 2 ? func_get_arg(2) : true;\n    }\n"
        . "}\n",
    );
    file_put_contents(
        $directory . '/vendor/twig/twig/src/Node/EmptyNode.php',
        "<?php\nclass EmptyNode extends Node\n{\n    public function setNode(string \$name, Node \$node): void {}\n}\n",
    );
    mkdir($directory . '/vendor/twig/twig/src/Node/Expression', 0777, true);
    file_put_contents(
        $directory . '/vendor/twig/twig/src/Node/Expression/SupportDefinedTestDeprecationTrait.php',
        "<?php\ntrait SupportDefinedTestDeprecationTrait\n{\n"
        . "    public function setAttribute(string \$name, \$value): void\n"
        . "    {\n        parent::setAttribute(\$name, \$value);\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/twig/twig/src/Extension/CoreExtension.php',
        "<?php\nclass CoreExtension\n{\n    public static function toArray(\$seq, \$preserveKeys = true)\n"
        . "    {\n        return self::toArray(\$seq, \$preserveKeys);\n    }\n"
        . "    private static function getPropertyChecker(string \$class, string \$property): \\Closure\n    {\n"
        . "        static \$classReflectors = [];\n        \$class = \$classReflectors[\$class] ??= new \\ReflectionClass(\$class);\n"
        . "        if (!\$class->hasProperty(\$property)) {\n            return static fn () => false;\n        }\n"
        . "        \$property = \$class->getProperty(\$property);\n"
        . "        if (!\$property->isPublic() || \$property->isStatic()) {\n            return static fn () => false;\n        }\n"
        . "        return static fn (\$object) => \$property->isInitialized(\$object);\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/twig/twig/src/Node/IncludeNode.php',
        "<?php\nclass IncludeNode\n{\n"
        . "    protected function addGetTemplate(Compiler \$compiler/* , string \$template = '' */) {}\n"
        . "    public function compile(): void\n    {\n"
        . "        CoreExtension::toArray([]);\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/twig/twig/src/Node/WithNode.php',
        "<?php\n\$compiler->write(\"CoreExtension::toArray(\\\$vars);\");\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/webman-app.php',
            '.typephp/build/monolog-utils.php',
            '.typephp/build/illuminate-bus-batch.php',
            '.typephp/build/illuminate-collections-enumerable.php',
            '.typephp/build/illuminate-eloquent-has-attributes.php',
            '.typephp/build/illuminate-eloquent-model.php',
            '.typephp/build/symfony-cache-abstract-adapter-trait.php',
            '.typephp/build/symfony-cache-value-wrapper.php',
            '.typephp/build/symfony-cache-item.php',
        ]);

        // 同一变量跨 ReflectionFunction/ReflectionMethod 重赋值改为分支内独立变量 + 提前 return
        $app = (string) file_get_contents($directory . '/.typephp/build/webman-app.php');
        expect($app)
            ->toContain('$reflectorFunction = new ReflectionFunction($call);')
            ->toContain('$reflectorMethod = new ReflectionMethod($call[0], $call[1]);')
            ->toContain('return $reflectorMethod;')
            ->toContain('static function (...$arguments) use ($allowHeader)')
            ->toContain('function (...$arguments) {')
            ->toContain('[function (...$arguments) use ($file)');
        expect(str_contains($app, '$reflector = new Reflection'))->toBeFalse();

        // case 'object' 不再落空到 default，自带终结 return
        expect($app)->toContain("}\n                return (string)\$data;\n            default:");

        $utils = (string) file_get_contents($directory . '/.typephp/build/monolog-utils.php');
        expect($utils)->toContain("\$msg = 'Unknown error';\n                break;\n        }");

        // g/m/k 级联落空展开为各 case 独立连乘，总量不变
        expect($utils)->toContain(
            "case 'g':\n                \$val *= 1024;\n                \$val *= 1024;\n                \$val *= 1024;\n                break;",
        );
        expect($utils)->toContain(
            "case 'm':\n                \$val *= 1024;\n                \$val *= 1024;\n                break;",
        );
        expect($utils)->toContain("case 'k':\n                \$val *= 1024;\n                break;");
        expect((string) file_get_contents($directory . '/.typephp/build/illuminate-bus-batch.php'))
            ->toContain('public function toArray(): array')
            ->toContain('$countState = new \stdClass();')
            ->toContain('map(function ($job) use ($countState) {')
            ->toContain('$countState->value = $countState->value + count($job);')
            ->toContain('$count = $countState->value;')
            ->not->toContain('use (&$count)');
        expect((string) file_get_contents($directory . '/.typephp/build/illuminate-collections-enumerable.php'))
            ->toContain('public function toArray(): array')
            ->toContain('unless($value = null, ?callable $callback = null, ?callable $default = null)');
        expect((string) file_get_contents($directory . '/.typephp/build/illuminate-eloquent-model.php'))
            ->toContain('public function toArray(): array');
        expect((string) file_get_contents($directory . '/.typephp/build/illuminate-eloquent-has-attributes.php'))
            ->toContain('$relationSet = false;')
            ->toContain('$relationSet = true;')
            ->toContain('if ($relationSet) {')
            ->not->toContain('get_defined_vars()');
        expect((string) file_get_contents($directory . '/.typephp/build/symfony-cache-abstract-adapter-trait.php'))
            ->toContain('generateItems(iterable $items, array $keys): \Generator');
        expect((string) file_get_contents($directory . '/.typephp/build/symfony-cache-value-wrapper.php'))
            ->toContain('class TypephpSymfonyCacheValueWrapper');
        expect((string) file_get_contents($directory . '/.typephp/build/symfony-cache-item.php'))
            ->toContain("private const VALUE_WRAPPER = 'TypephpSymfonyCacheValueWrapper';");
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain('  - .typephp/build/webman-app.php' . "\n")
            ->toContain('  - .typephp/build/monolog-utils.php' . "\n")
            ->toContain("\n  - vendor/workerman/webman-framework/src/App.php\n")
            ->toContain("\n  - vendor/monolog/monolog/src/Monolog/Utils.php\n")
            ->toContain("\n  - vendor/illuminate/collections/Enumerable.php\n")
            ->toContain("\n  - vendor/illuminate/database/Eloquent/Model.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('moves Monolog browser style caches out of captured static locals for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/monolog/monolog/src/Monolog/Handler';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/BrowserConsoleHandler.php', <<<'PHP'
        <?php
        class BrowserConsoleHandler {
            protected static $records = [];
            private static function handleCustomStyles(string $style, string $string): string {
                static $colors = ['blue', 'green', 'red', 'magenta', 'orange', 'black', 'grey'];
                static $labels = [];
                return preg_replace_callback('/x/', function (array $m) use ($string, &$colors, &$labels) {
                    if (!isset($labels[$string])) {
                        $labels[$string] = $colors[count($labels) % count($colors)];
                    }
                    $color = $labels[$string];
                    return $color;
                }, $style);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/monolog-browser-console-handler.php');
        expect($generated)
            ->toContain('private static array $aotColors')
            ->toContain('self::$aotLabels[$string] = self::$aotColors[')
            ->not->toContain('static $colors =')
            ->not->toContain('use ($string, &$colors, &$labels)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps PhpZip encrypted input separate from its integer check byte in AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/nelexa/zip/src/IO/Filter/Cipher/Pkware';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/PKCryptContext.php', <<<'PHP'
        <?php
        class PKCryptContext {
            public function checkHeader(string $header, int $checkByte): void {
                $byte = 0;

                foreach (unpack('C*', $header) as $byte) {
                    $byte = ($byte ^ $this->decryptByte()) & 0xFF;
                    $this->updateKeys($byte);
                }

                if ($byte !== $checkByte) {
                    throw new RuntimeException();
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/nelexa-pkcrypt-context.php');
        expect($generated)
            ->toContain('foreach (unpack(\'C*\', $header) as $encryptedByte)')
            ->toContain('if ($lastByte !== $checkByte)')
            ->not->toContain('foreach (unpack(\'C*\', $header) as $byte)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('folds PhpZip interface constants used by AOT defaults', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nelexa/zip/src/Model', 0777, true);
    file_put_contents(
        $directory . '/vendor/nelexa/zip/src/Model/ZipEntry.php',
        "<?php class ZipEntry { private int \$level = ZipCompressionLevel::NORMAL; }\n",
    );
    file_put_contents($directory . '/vendor/nelexa/zip/src/ZipFile.php', <<<'PHP'
        <?php
        class ZipFile {
            public function level(int $level = ZipCompressionLevel::NORMAL): void {
                $dir = 'x';
                foreach ($lastModDirs as $dir => $lastMod) {
                    touch($dir, $lastMod);
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        expect(file_get_contents($directory . '/.typephp/build/nelexa-zip-entry.php'))
            ->toContain('private int $level = 5');
        expect(file_get_contents($directory . '/.typephp/build/nelexa-zip-file.php'))
            ->toContain('int $level = 5')
            ->toContain('as $lastModDir => $lastMod')
            ->toContain('touch($lastModDir, $lastMod)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps PhpZip directory iterator branch types separate in AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/nelexa/zip/src/Util';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/FilesUtil.php', <<<'PHP'
        <?php
        class FilesUtil {
            public static function search(string $folder, string $pattern, bool $recursive): void {
                if ($recursive) {
                    $directoryIterator = new \RecursiveDirectoryIterator($folder);
                    $iterator = new \RecursiveIteratorIterator($directoryIterator);
                } else {
                    $directoryIterator = new \DirectoryIterator($folder);
                    $iterator = new \IteratorIterator($directoryIterator);
                }

                $regexIterator = new \RegexIterator($iterator, $pattern, \RegexIterator::MATCH);
            }

            public static function convertGlobToRegEx(array $chars): string {
                $regexPattern = '';
                $escaping = false;
                foreach ($chars as $currentChar) {
                    switch ($currentChar) {
                        default:
                            $escaping = false;
                            $regexPattern .= $currentChar;
                    }
                }
                return $regexPattern;
            }

            public static function fileSearchWithIgnore(string $inputDir, bool $recursive, array $ignoreFiles): array {
                if ($recursive) {
                    $directoryIterator = new \RecursiveDirectoryIterator($inputDir);

                    if (!empty($ignoreFiles)) {
                        $directoryIterator = new IgnoreFilesRecursiveFilterIterator($directoryIterator, $ignoreFiles);
                    }
                    $iterator = new \RecursiveIteratorIterator($directoryIterator);
                } else {
                    $directoryIterator = new \DirectoryIterator($inputDir);

                    if (!empty($ignoreFiles)) {
                        $directoryIterator = new IgnoreFilesFilterIterator($directoryIterator, $ignoreFiles);
                    }
                    $iterator = new \IteratorIterator($directoryIterator);
                }
                return iterator_to_array($iterator);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/nelexa-files-util.php');
        expect($generated)
            ->toContain('$recursiveDirectory = new \RecursiveDirectoryIterator')
            ->toContain('$flatDirectory = new \DirectoryIterator')
            ->toContain("\$regexPattern .= \$currentChar;\n                    break;")
            ->toContain('new IgnoreFilesRecursiveFilterIterator($recursiveDirectory, $ignoreFiles)')
            ->toContain('new IgnoreFilesFilterIterator($flatDirectory, $ignoreFiles)')
            ->not->toContain('$directoryIterator =');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands CarbonInterval variable variables in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/nesbot/carbon/src/Carbon';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/CarbonInterval.php', <<<'PHP'
        <?php
        class CarbonInterval {
            public function normalize($years, $months, $weeks, $days, $hours, $minutes, $seconds): void {
                $translations = [
                    ['option' => CarbonInterface::ONE_DAY_WORDS],
                    ['option' => CarbonInterface::TWO_DAY_WORDS],
                ];
                $optionalSpace = ' ';
                $default = $this->getTranslationMessage('list.0') ?? $this->getTranslationMessage('list') ?? ' ';
                /** @var bool|string $join */
                $join = $default === '' ? '' : ' ';
                /** @var bool|array|string $altNumbers */
                $altNumbers = false;
                $aUnit = false;
                $minimumUnit = 's';
                $skip = [];
                extract($this->getForHumansInitialVariables($syntax, $short));
                foreach ($diffIntervalArray as $index => &$unitData) {
                    $nextIndex = $index + 1;
                }
                foreach ($interval as $index => &$item) {
                    $item = $transChoice($item[0], $item[1], $index, $actualParts);
                }
                while ([$part, $value, $unit] = array_shift($parts)) {
                }
                foreach (['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'] as $unit) {
                    $value = $$unit;
                }
                foreach ($factors as $source => [$target, $factor]) {
                    foreach (['source', 'target'] as $key) {
                        if ($$key === 'dayz') {
                            $$key = 'daysExcludeWeeks';
                        }
                    }
                }
                switch ($key) {
                    default:
                        $this->$key = $value;
                }
                switch ($unit) {
                    default:
                        $instance->$unit = $value;
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/carbon-interval.php');
        expect($generated)
            ->toContain("['option' => 04]")
            ->toContain("['option' => 010]")
            ->not->toContain('CarbonInterface::ONE_DAY_WORDS')->toContain(
                'foreach ($interval as $partIndex => &$item)',
            )->toContain('$partIndex, $actualParts')->toContain(
                'foreach ($diffIntervalArray as $skipIndex => &$unitData)',
            )->toContain('$nextIndex = $skipIndex + 1;')->toContain(
                '$humanOptions = $this->getForHumansInitialVariables',
            )->toContain('$translator = $humanOptions[\'translator\'] ?? null;')
            ->not->toContain('extract(')->toContain('while ($partValues = array_shift($parts))')->toContain(
                '$unit = $partValues[2];',
            )
            ->not->toContain('while ([$part, $value, $unit]')->toContain("'years' => \$years")->toContain(
                "if (\$target === 'dayz')",
            )->toMatch('/\$this->\$key = \$value;\s+break;/')->toMatch('/\$instance->\$unit = \$value;\s+break;/')
            ->not->toContain('$$');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('passes a CarbonTimeZone name across the AOT Carbon now boundary', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/nesbot/carbon/src/Carbon';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/CarbonTimeZone.php', <<<'PHP'
        <?php
        class CarbonTimeZone {
            private function resolveCarbon(): object {
                return Carbon::now($this);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/carbon-time-zone.php');
        expect($generated)
            ->toContain('return Carbon::now($this->getName());')
            ->not->toContain('return Carbon::now($this);');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('moves Webman database loading side effects into the AOT entrypoint', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/webman/database/src/support', 0777, true);
    mkdir($directory . '/vendor/webman/think-orm/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/webman/database/src/support/Model.php',
        "<?php\nrequire_once __DIR__ . '/../Initializer.php';\nclass Model {}\n",
    );
    file_put_contents(
        $directory . '/vendor/webman/database/src/support/Db.php',
        "<?php\nrequire_once __DIR__ . '/../Initializer.php';\nclass Db {}\n",
    );
    file_put_contents(
        $directory . '/vendor/webman/database/src/Initializer.php',
        "<?php\nclass Initializer {}\n\nInitializer::init(config('database', []));\n",
    );
    file_put_contents(
        $directory . '/vendor/webman/think-orm/src/Initializer.php',
        "<?php\nclass Initializer {}\n\nInitializer::init();\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/webman-database-model.php',
            '.typephp/build/webman-database-db.php',
            '.typephp/build/webman-database-initializer.php',
            '.typephp/build/webman-think-orm-initializer.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/webman-database-model.php'))
            ->not
            ->toContain('require_once __DIR__');
        expect(file_get_contents($directory . '/.typephp/build/webman-database-db.php'))
            ->not
            ->toContain('require_once __DIR__');
        expect(file_get_contents($directory . '/.typephp/build/webman-database-initializer.php'))
            ->not
            ->toContain('Initializer::init(');
        expect(file_get_contents($directory . '/.typephp/build/webman-think-orm-initializer.php'))
            ->not
            ->toContain('Initializer::init();');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('clears Webman database grammar without Closure call in AOT copies', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database', 0777, true);
    mkdir($directory . '/vendor/webman/database/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/database/Connection.php',
        "<?php\nclass Connection {\n    public function setQueryGrammar(Query\\Grammars\\Grammar \$grammar) {}\n}\n",
    );
    file_put_contents($directory . '/vendor/webman/database/src/DatabaseManager.php', <<<'PHP'
        <?php
        class DatabaseManager {
            protected function closeAndFreeConnection($connection): void {
                $clearProperties = function () {
                    $this->queryGrammar = null;
                };
                $clearProperties->call($connection);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        expect(file_get_contents($directory . '/.typephp/build/illuminate-database-connection.php'))
            ->toContain('setQueryGrammar(?Query\Grammars\Grammar $grammar)');
        expect(file_get_contents($directory . '/.typephp/build/webman-database-manager.php'))
            ->toContain('$connection->setQueryGrammar(null);')
            ->not->toContain('Closure')
            ->not->toContain('->call(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('clears Webman ThinkORM state without Closure call in AOT copies', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/topthink/think-orm/src/db', 0777, true);
    mkdir($directory . '/vendor/webman/think-orm/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/topthink/think-orm/src/db/Connection.php',
        "<?php\nabstract class Connection {\n    protected \$db;\n    protected \$cache;\n    protected \$builder;\n    /**\n     * 析构方法.\n     */\n    public function __destruct() {}\n}\n",
    );
    file_put_contents($directory . '/vendor/webman/think-orm/src/DbManager.php', <<<'PHP'
        <?php
        class DbManager {
            protected function closeConnection($connection): void {
                $clearProperties = function () {
                    $this->db = null;
                    $this->cache = null;
                    $this->builder = null;
                };
                $clearProperties->call($connection);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        expect(file_get_contents($directory . '/.typephp/build/think-orm-connection.php'))
            ->toContain('function clearRuntimeStateForAot(): void')
            ->toContain('$this->builder = null;');
        expect(file_get_contents($directory . '/.typephp/build/webman-think-orm-db-manager.php'))
            ->toContain('$connection->clearRuntimeStateForAot();')
            ->not->toContain('Closure')
            ->not->toContain('->call(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the Workerman parallel barrier in a reference-capable AOT slot', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/workerman/coroutine/src', 0777, true);
    file_put_contents($directory . '/vendor/workerman/coroutine/src/Parallel.php', <<<'PHP'
        <?php
        class Parallel {
            public function wait(): array {
                $barrier = Barrier::create();
                $barrierRef = new \stdClass();
                    $barrierRef->value = $barrier;
                Barrier::wait($barrier);
                return [];
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateRefCaptureSources();
        $content = (string) file_get_contents($directory . '/.typephp/build/coroutine-parallel.php');
        expect($content)
            ->toContain('$barrierState = new \stdClass()')
            ->toContain('$barrierRef->value = $barrierState->value')
            ->toContain('Barrier::wait($barrierState->value)')
            ->not->toContain('$barrier = Barrier::create()');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the Workerman websocket request header separate from response headers', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/workerman/workerman/src/Protocols', 0777, true);
    file_put_contents($directory . '/vendor/workerman/workerman/src/Protocols/Websocket.php', <<<'PHP'
        <?php
        class Websocket {
            public static function handshake(string $header, object $connection): string {
                $handshakeMessage = $header;
                foreach ($connection->headers as $header) {
                    if (strpbrk($header, "\r\n") !== false) {
                        continue;
                    }
                    if (stripos($header, 'Sec-WebSocket-Extensions:') === 0 && stripos($header, 'permessage-deflate') !== false) {
                        $connection->compressed = true;
                    }
                    $handshakeMessage .= "$header\r\n";
                }
                return $handshakeMessage;
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $content = (string) file_get_contents($directory . '/.typephp/build/workerman-websocket.php');
        expect($content)
            ->toContain('foreach ($connection->headers as $responseHeader)')
            ->toContain('strpbrk($responseHeader, "\r\n")')
            ->toContain("stripos(\$responseHeader, 'Sec-WebSocket-Extensions:')")
            ->toContain("stripos(\$responseHeader, 'permessage-deflate')")
            ->toContain('$handshakeMessage .= "$responseHeader\r\n";')
            ->not->toContain('foreach ($connection->headers as $header)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the ip2region integer slot stable on 32-bit fallback', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/zoujingli/ip2region', 0777, true);
    file_put_contents($directory . '/vendor/zoujingli/ip2region/XdbSearcher.php', <<<'PHP'
        <?php
        class XdbSearcher {
            public static function getLong(string $b, int $idx) {
                $val = ord($b[$idx]);
                if ($val < 0 && PHP_INT_SIZE == 4) {
                    $val = sprintf("%u", $val);
                }
                return $val;
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $content = (string) file_get_contents($directory . '/.typephp/build/ip2region-xdb-searcher.php');
        expect($content)->toContain('return sprintf("%u", $val);')->not->toContain('$val = sprintf("%u", $val);');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the ip2region v3 integer slot stable on 32-bit fallback', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/zoujingli/ip2region/src/ip2region/xdb';
    mkdir($sourceDirectory, 0777, true);
    $source = <<<'PHP'
        <?php
        class Util {
            public static function le_getUint32($b, $idx) {
                $val = ord($b[$idx]);
                if ($val < 0 && PHP_INT_SIZE == 4) {
                    $val = sprintf("%u", $val);
                }
                return $val;
            }
        }
        PHP;
    file_put_contents($sourceDirectory . '/Util.php', $source);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/ip2region-v3-util.php');
        expect($content)->toContain('return sprintf("%u", $val);')->not->toContain('$val = sprintf("%u", $val);');

        file_put_contents($sourceDirectory . '/Util.php', str_replace(
            '$val = sprintf("%u", $val);',
            'return sprintf("%u", $val);',
            $source,
        ));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin IP2Region v3 integer slot compatibility rule expected 1 match(es), found 0',
            );

        file_put_contents($sourceDirectory . '/Util.php', $source . "\n" . $source);
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin IP2Region v3 integer slot compatibility rule expected 1 match(es), found 2',
            );
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the Webman monitor iterator object-typed in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/app/process', 0777, true);
    file_put_contents(
        $directory . '/app/process/Monitor.php',
        "<?php\nclass Monitor\n{\n    public function check(\$monitorDir): void\n    {\n"
        . "        if (is_file(\$monitorDir)) {\n            \$iterator = [new SplFileInfo(\$monitorDir)];\n"
        . "        } else {\n            \$dirIterator = \$monitorDir;\n"
        . "            \$iterator = new RecursiveIteratorIterator(\$dirIterator);\n        }\n"
        . "        foreach (\$iterator as \$file) {}\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/app-process-monitor.php']);
        expect(file_get_contents($directory . '/.typephp/build/app-process-monitor.php'))
            ->toContain('$iterator = new \IteratorIterator(new \ArrayIterator([new SplFileInfo($monitorDir)]));')
            ->toContain('$iterator = new \IteratorIterator(new RecursiveIteratorIterator($dirIterator));')
            ->not->toContain('$iterator = [new SplFileInfo($monitorDir)];');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkORM method parameters type-stable in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/topthink/think-orm/src/model/concern', 0777, true);
    file_put_contents(
        $directory . '/vendor/topthink/think-orm/src/model/concern/Conversion.php',
        "<?php\ntrait Conversion\n{\n    public function appendRelationAttr(string \$attr, array \$append)\n    {\n"
        . "            foreach (\$append as \$key => \$attr) {\n                \$key = is_numeric(\$key) ? \$attr : \$key;\n"
        . "                \$this->data[\$key] = \$model->getAttr(\$attr);\n            }\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/topthink/think-orm/src/model/concern/RelationShip.php',
        "<?php\ntrait RelationShip\n{\n    private \$parent;\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/think-orm-conversion.php',
            '.typephp/build/think-orm-relation-ship.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/think-orm-conversion.php'))
            ->toContain('foreach ($append as $key => $appendAttr)')
            ->toContain('$model->getAttr($appendAttr)')
            ->not->toContain('foreach ($append as $key => $attr)');
        expect(file_get_contents($directory . '/.typephp/build/think-orm-relation-ship.php'))
            ->toContain('protected $parent;')
            ->not->toContain('private $parent;');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkValidate recursive path parameters type-stable in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/topthink/think-validate/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/topthink/think-validate/src/Validate.php',
        "<?php\nclass Validate\n{\n    protected function getRecursiveData(array \$data, string \$key)\n    {\n"
        . "        \$keys = explode('.', \$key);\n        foreach (\$keys as \$key) {\n"
        . "            if (!isset(\$data[\$key])) {\n                break;\n            }\n"
        . "            \$value = \$data = \$data[\$key];\n"
        . "        }\n        return \$value;\n    }\n"
        . "    protected function parseErrorMsg(string \$msg, \$rule, string \$title) {}\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/think-validate.php']);
        expect(file_get_contents($directory . '/.typephp/build/think-validate.php'))
            ->toContain('foreach ($keys as $segment)')
            ->toContain('$data[$segment]')
            ->toContain('parseErrorMsg(mixed $msg, $rule, string $title)')
            ->not->toContain('foreach ($keys as $key)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rewrites Brick Math byte-string complement without integer coercion', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/brick/math/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/brick/math/src/BigInteger.php',
        "<?php\nclass BigInteger\n{\n    public static function fromBytes(string \$value): void\n    {\n"
        . "        if (\$value !== '') {\n                \$value = ~\$value;\n        }\n    }\n}\n",
    );
    mkdir($directory . '/vendor/brick/math/src/Internal', 0777, true);
    file_put_contents(
        $directory . '/vendor/brick/math/src/Internal/Calculator.php',
        "<?php\nclass Calculator\n{\n"
        . "    public function run(string \$value): string { return \$this->toDecimal(\$value); }\n"
        . "    private function toDecimal(string \$bytes): string { return \$bytes; }\n}\n",
    );
    mkdir($directory . '/vendor/brick/math/src/Internal/Calculator', 0777, true);
    file_put_contents(
        $directory . '/vendor/brick/math/src/Internal/Calculator/NativeCalculator.php',
        "<?php\nclass NativeCalculator\n{\n    public function div(): array\n    {\n"
        . "        \$na = \$a * 1; // cast to number\n        \$nb = \$b * 1;\n        \$nb = \$b * 1;\n"
        . "                \$q = intdiv(\$na, \$nb);\n                \$r = \$na % \$nb;\n"
        . "                return [\n                    (string) \$q,\n                    (string) \$r,\n                ];\n"
        . "            \$sum = \$blockA - \$blockB - \$carry;\n            if (\$sum < 0) {\n"
        . "                \$sum += \$complement;\n            }\n            \$sum = (string) \$sum;\n"
        . "                \$value = \$mul % \$complement;\n                \$carry = (\$mul - \$value) / \$complement;\n"
        . "                \$value = (string) \$value;\n            \$r = (int) substr(\$a, 0, \$z - 1);\n"
        . "                \$n = \$r * 10 + (int) \$a[\$i];\n                \$r = \$n % \$nb;\n"
        . "            return [ltrim(\$q, '0') ?: '0', (string) \$r];\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/brick-math-big-integer.php',
            '.typephp/build/brick-math-calculator.php',
            '.typephp/build/brick-math-native-calculator.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/brick-math-big-integer.php'))
            ->toContain("\$complement .= chr(255 - ord(\$value[\$i]));")
            ->toContain('$value = $complement;')
            ->not->toContain('$value = ~$value;');
        expect(file_get_contents($directory . '/.typephp/build/brick-math-calculator.php'))
            ->toContain('typephpToDecimal($value)')
            ->toContain('function typephpToDecimal(string $bytes)');
        expect(file_get_contents($directory . '/.typephp/build/brick-math-native-calculator.php'))
            ->toContain('$na = (float) $a; // AOT: force the overflow-safe string algorithm')
            ->toContain('$nb = (float) $b;')
            ->not
            ->toContain('$b * 1')
            ->toContain('$intQ = intdiv($na, $nb);')
            ->toContain('(string) $intR,')
            ->toContain('$sumValue = $blockA - $blockB - $carry;')
            ->toContain('if ($sumValue < 0)')
            ->toContain('$sumValue += $complement;')
            ->toContain('$sum = (string) $sumValue;')
            ->toContain('$remainderValue = $mul % $complement;')
            ->toContain('$carry = ($mul - $remainderValue) / $complement;')
            ->toContain('$value = (string) $remainderValue;')
            ->toContain('$intRemainder = (int) substr($a, 0, $z - 1);')
            ->toContain('$n = $intRemainder * 10 + (int) $a[$i];')
            ->toContain('$intRemainder = $n % $nb;')
            ->toContain("(string) \$intRemainder];");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rewrites typed-reference captures into AOT sources', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    mkdir($directory . '/vendor/workerman/webman-framework/src/Route', 0777, true);
    $coroutineDirectory = $directory . '/vendor/workerman/coroutine/src';
    mkdir($coroutineDirectory . '/Barrier', 0777, true);
    mkdir($coroutineDirectory . '/Channel', 0777, true);
    mkdir($directory . '/vendor/guzzlehttp/guzzle/src/Handler', 0777, true);

    // 真实 vendor 的 Route.php 与协程驱动文件为 CRLF，补丁字面量按 CRLF 匹配
    file_put_contents($directory . '/vendor/workerman/webman-framework/src/Route/Route.php', implode("\r\n", [
        '<?php',
        'namespace Webman\Route;',
        'class Route',
        '{',
        '    public function url(array $parameters = []): string',
        '    {',
        '        $path = $this->path;',
        "        \$path = preg_replace_callback('/\\{(.*?)(?:\\:[^\\}]*?)*?\\}/', function (\$matches) use (&\$parameters) {",
        '            if (!$parameters) {',
        '                return $matches[0];',
        '            }',
        '            if (isset($parameters[$matches[1]])) {',
        '                $value = $parameters[$matches[1]];',
        '                unset($parameters[$matches[1]]);',
        '                return $value;',
        '            }',
        '            $key = key($parameters);',
        '            if (is_int($key)) {',
        '                $value = $parameters[$key];',
        '                unset($parameters[$key]);',
        '                return $value;',
        '            }',
        '            return $matches[0];',
        '        }, $path);',
        "        return count(\$parameters) > 0 ? \$path . '?' . http_build_query(\$parameters) : \$path;",
        '    }',
        '}',
        '',
    ]));
    file_put_contents($coroutineDirectory . '/Barrier/Fiber.php', implode("\r\n", [
        '<?php',
        'namespace Workerman\Coroutine\Barrier;',
        'class Fiber',
        '{',
        '    public static function wait(array $dependencies, float $timeout): bool',
        '    {',
        '        $resumed = false;',
        '        $timerId = null;',
        '        $coroutine = 1;',
        '        if ($timeout > 0) {',
        '            function() use ($coroutine, &$resumed, &$timerId) {',
        '                $timerId = 2;',
        '            };',
        '        }',
        '        return $resumed;',
        '    }',
        '}',
        '',
    ]));
    file_put_contents($coroutineDirectory . '/Channel/Fiber.php', implode("\r\n", [
        '<?php',
        'namespace Workerman\Coroutine\Channel;',
        'class Fiber',
        '{',
        '    public function push($value, float $timeout = -1): bool',
        '    {',
        "        if (\$timeout > 0) {",
        '            $timedOut = false;',
        '            $timerId = null;',
        '            function() use (&$timedOut) {',
        '                $timedOut = true;',
        '            };',
        '        }',
        '        return true;',
        '    }',
        '}',
        '',
    ]));
    file_put_contents(
        $directory . '/vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php',
        "<?php\nclass CurlFactory\n{\n    public function headers(): callable\n    {\n"
        . "        \$startingResponse = false;\n        \$collectingTrailers = false;\n"
        . "        return static function () use (&\$startingResponse, &\$collectingTrailers) {\n"
        . "            \$startingResponse = true;\n            \$collectingTrailers = true;\n        };\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/guzzlehttp/guzzle/src/Handler/StreamHandler.php',
        "<?php\nclass StreamHandler\n{\n    public function create()\n    {\n"
        . "        \$errors = [];\n        \\set_error_handler(static function () use (&\$errors): bool {\n"
        . "            \$errors[] = 'error';\n            return true;\n        });\n"
        . "        try {\n            \$resource = true;\n        } finally {\n            \\restore_error_handler();\n        }\n\n"
        . "        if (!\$resource) {\n            foreach (\$errors as \$error) {}\n        }\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/guzzlehttp/guzzle/src/MessageFormatter.php',
        "<?php\nclass MessageFormatter\n{\n    public function format(): string\n    {\n"
        . "        \$cache = [];\n\n        \$result = \\preg_replace_callback('/x/', function () use (&\$cache) {\n"
        . "            switch (\$matches[1]) {\n                default:\n"
        . "                    if (strpos(\$matches[1], 'req_header_') === 0) {\n"
        . "                        \$result = 'request';\n"
        . "                    } elseif (strpos(\$matches[1], 'res_header_') === 0) {\n"
        . "                        \$result = \$response\n"
        . "                            ? \$response->getHeaderLine(substr(\$matches[1], 11))\n"
        . "                                : 'NULL';\n"
        . "                        }\n"
        . "                }\n"
        . "            return \$cache['x'] = 'x';\n        }, 'x');\n        return \$result;\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/guzzlehttp/guzzle/src/Pool.php',
        "<?php\nclass Pool\n{\n"
        . "    private static function cmpCallback(array &\$options, string \$name, array &\$results): void\n"
        . "    {\n        \$options[\$name] = static function (\$value) use (&\$results) {\n"
        . "            \$results[] = \$value;\n        };\n    }\n}\n",
    );
    mkdir($directory . '/vendor/guzzlehttp/promises/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/guzzlehttp/promises/src/Utils.php',
        "<?php\n"
        . "        \$results = [];\n        \$promise = Each::of(\$promises);\n"
        . "        \$results = [];\n        \$rejections = [];\n\n        return Each::of(\$promises)->then(\n"
        . "            function () use (&\$results, &\$rejections, \$count) {\n"
        . "                if (count(\$results) !== \$count) {}\n            }\n        );\n"
        . "        \$results = [];\n\n        return Each::of(\$promises)"
        . "        )->then(function () use (&\$results) {\n            ksort(\$results);\n        });\n"
        . "        function () use (\$recursive, &\$promises) {};\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateRefCaptureSources())->toBe([
            '.typephp/build/webman-route.php',
            '.typephp/build/coroutine-barrier-fiber.php',
            '.typephp/build/coroutine-channel-fiber.php',
            '.typephp/build/guzzle-curl-factory.php',
            '.typephp/build/guzzle-stream-handler.php',
            '.typephp/build/guzzle-message-formatter.php',
            '.typephp/build/guzzle-pool.php',
            '.typephp/build/guzzle-promises-utils.php',
        ]);

        // array 参数改为按值捕获 + 捕获未初始化的引用槽
        $route = (string) file_get_contents($directory . '/.typephp/build/webman-route.php');
        expect($route)->toContain('function ($matches) use ($parameters, &$remaining) {');
        expect(str_contains($route, 'use (&$parameters)'))->toBeFalse();

        // 被引用捕获的 $resumed 去掉初始化即为 REF 槽；只读的 $timerId 改为按值捕获
        $barrier = (string) file_get_contents($directory . '/.typephp/build/coroutine-barrier-fiber.php');
        expect($barrier)->toContain('function() use ($coroutine, &$resumed, $timerId) {');
        expect(str_contains($barrier, '$resumed = false;'))->toBeFalse();
        expect($barrier)->toContain('        $timerId = null;');

        $channel = (string) file_get_contents($directory . '/.typephp/build/coroutine-channel-fiber.php');
        expect(str_contains($channel, '$timedOut = false;'))->toBeFalse();
        expect($channel)->toContain('            $timerId = null;');

        $curlFactory = (string) file_get_contents($directory . '/.typephp/build/guzzle-curl-factory.php');
        expect($curlFactory)
            ->not->toContain('$startingResponse = false;')
            ->not->toContain('$collectingTrailers = false;')->toContain(
                'use (&$startingResponse, &$collectingTrailers)',
            );

        $streamHandler = (string) file_get_contents($directory . '/.typephp/build/guzzle-stream-handler.php');
        expect($streamHandler)
            ->not
            ->toContain('$errors = [];' . "\n" . '        \set_error_handler')
            ->toContain('if (!isset($errors)) {')
            ->toContain('use (&$errors)');

        $messageFormatter = (string) file_get_contents($directory . '/.typephp/build/guzzle-message-formatter.php');
        expect($messageFormatter)
            ->not
            ->toContain('$cache = [];')
            ->toContain("                        break;\n                }")
            ->toContain('use (&$cache)');

        $pool = (string) file_get_contents($directory . '/.typephp/build/guzzle-pool.php');
        expect($pool)->toContain('string $name, mixed &$results): void');

        $promisesUtils = (string) file_get_contents($directory . '/.typephp/build/guzzle-promises-utils.php');
        expect($promisesUtils)
            ->not->toContain('$results = [];' . "\n" . '        $promise = Each::of(')
            ->not->toContain('$rejections = [];' . "\n\n" . '        return Each::of(')->toContain(
                'if (!isset($results)) {',
            )->toContain('if (!isset($rejections)) {')->toContain('use ($recursive, $promises)');

        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain('  - .typephp/build/webman-route.php' . "\n")
            ->toContain('  - .typephp/build/coroutine-barrier-fiber.php' . "\n")
            ->toContain('  - .typephp/build/coroutine-channel-fiber.php' . "\n")
            ->toContain('  - .typephp/build/guzzle-curl-factory.php' . "\n")
            ->toContain('  - .typephp/build/guzzle-stream-handler.php' . "\n")
            ->toContain('  - .typephp/build/guzzle-message-formatter.php' . "\n")
            ->toContain('  - .typephp/build/guzzle-pool.php' . "\n")
            ->toContain('  - .typephp/build/guzzle-promises-utils.php' . "\n")
            ->toContain("\n  - vendor/workerman/webman-framework/src/Route/Route.php\n")
            ->toContain("\n  - vendor/workerman/coroutine/src/Barrier/Fiber.php\n")
            ->toContain("\n  - vendor/workerman/coroutine/src/Channel/Fiber.php\n")
            ->toContain("\n  - vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php\n")
            ->toContain("\n  - vendor/guzzlehttp/guzzle/src/Handler/StreamHandler.php\n")
            ->toContain("\n  - vendor/guzzlehttp/guzzle/src/MessageFormatter.php\n")
            ->toContain("\n  - vendor/guzzlehttp/guzzle/src/Pool.php\n")
            ->toContain("\n  - vendor/guzzlehttp/promises/src/Utils.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('normalizes PSR-7 query decoders to one callable type', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/guzzlehttp/psr7/src', 0777, true);
    file_put_contents(
        $directory . '/vendor/guzzlehttp/psr7/src/Query.php',
        "<?php\nclass Query\n{\n    public static function parse(\$mode): void\n    {\n"
        . "        if (\$mode === 1) {\n            \$decoder = 'rawurldecode';\n"
        . "        } else {\n            \$decoder = 'urldecode';\n        }\n"
        . "        if (\$mode === 2) {\n            \$encoder = 'rawurlencode';\n"
        . "        } else {\n            \$encoder = 'urlencode';\n        }\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/guzzlehttp/psr7/src/StreamWrapper.php',
        "<?php\nclass StreamWrapper\n{\n    public function stream_open(int \$options): bool\n    {\n"
        . "        \$options = stream_context_get_options(\$this->context);\n"
        . "        if (!isset(\$options['guzzle']['stream'])) {\n            return false;\n        }\n"
        . "        \$this->stream = \$options['guzzle']['stream'];\n        return true;\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/guzzle-psr7-query.php',
            '.typephp/build/guzzle-psr7-stream-wrapper.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/guzzle-psr7-query.php'))
            ->toContain('$decoder = static fn($value) => rawurldecode((string) $value);')
            ->toContain('$decoder = static fn($value) => urldecode((string) $value);')
            ->toContain('$encoder = static fn(string $value): string => rawurlencode($value);')
            ->toContain('$encoder = static fn(string $value): string => urlencode($value);');
        expect(file_get_contents($directory . '/.typephp/build/guzzle-psr7-stream-wrapper.php'))
            ->toContain('$contextOptions = stream_context_get_options($this->context);')
            ->toContain("\$contextOptions['guzzle']['stream']");
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/guzzlehttp/psr7/src/Query.php\n")
            ->toContain("\n  - vendor/guzzlehttp/psr7/src/StreamWrapper.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony session metadata initialization in a reference-capable slot', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation/Session/Storage';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/MetadataBag.php', <<<'PHP'
        <?php
        class MetadataBag {
            public function initialize(array &$array): void
            {
                $this->meta = &$array;
            }
        }
        PHP);

    try {
        expect(new ProjectGenerator($directory)->generateRefCaptureSources())->toBe([
            '.typephp/build/symfony-session-metadata-bag.php',
        ]);
        $generated = (string) file_get_contents($directory . '/.typephp/build/symfony-session-metadata-bag.php');
        expect($generated)
            ->toContain('initialize(mixed &$array): void')
            ->not->toContain('initialize(array &$array): void');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rewrites recursive Illuminate Arr dot storage for AOT references', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/collections', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/collections/Arr.php',
        "<?php\nclass Arr\n{\n    public static function dot(\$array): array\n    {\n"
        . "        \$results = [];\n\n        \$flatten = function (\$data, \$prefix, \$currentDepth) use (&\$results, &\$flatten, \$depth): void {\n"
        . "            foreach (\$data as \$key => \$value) {\n                \$newKey = \$prefix.\$key;\n"
        . "                if (is_array(\$value)) {\n                    \$flatten(\$value, \$newKey.'.', \$currentDepth + 1);\n"
        . "                } else {\n                    \$results[\$newKey] = \$value;\n                }\n            }\n"
        . "        };\n        \$flatten(\$array, \$prepend, 0);\n        \$flatten = null;\n\n        return \$results;\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateRefCaptureSources())->toBe(['.typephp/build/illuminate-collections-arr.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-collections-arr.php');
        expect($source)
            ->not
            ->toContain('$results = [];' . "\n\n" . '        $flatten = function')
            ->toContain('unset($flatten);')
            ->toContain('if (!isset($results)) {')
            ->toContain('$currentDepth, $recurse) use (&$results, $depth)')
            ->toContain('$recurse($value, $newKey.\'.\', $currentDepth + 1, $recurse);')
            ->toContain('$flatten($array, $prepend, 0, $flatten);');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/collections/Arr.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands Postgres DSN extract into explicit configuration reads', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Connectors', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/database/Connectors/PostgresConnector.php', <<<'PHP'
        <?php
        class PostgresConnector
        {
            protected function getDsn(array $config)
            {
                extract($config, EXTR_SKIP);

                $host = isset($host) ? "host={$host};" : '';
                $database = $connect_via_database ?? $database ?? null;
                $port = $connect_via_port ?? $port ?? null;
                if (isset($charset)) {
                    $dsn .= ";client_encoding='{$charset}'";
                }
                if (isset($application_name)) {
                    $dsn .= ";application_name='".str_replace("'", "\'", $application_name)."'";
                }
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-database-postgres-connector.php',
        ]);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-database-postgres-connector.php');
        expect($source)
            ->not
            ->toContain('extract(')
            ->toContain('$config[\'host\']')
            ->toContain('$config[\'connect_via_database\']')
            ->toContain('$config[\'connect_via_port\']')
            ->toContain('$config[\'charset\']')
            ->toContain('$config[\'application_name\']');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('declares the optional Postgres JSON path quote argument for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Query/Grammars', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/database/Query/Grammars/PostgresGrammar.php', <<<'PHP'
        <?php
        class PostgresGrammar
        {
            protected function wrapJsonPathAttributes($path)
            {
                $quote = func_num_args() === 2 ? func_get_arg(1) : "'";

                return [$path, $quote];
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-database-postgres-grammar.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/illuminate-database-postgres-grammar.php'))
            ->toContain('protected function wrapJsonPathAttributes($path, $quote = "\'")')
            ->not->toContain('func_num_args()')
            ->not->toContain('func_get_arg(');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/database/Query/Grammars/PostgresGrammar.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('declares the Blueprint dropColumn variadic arguments for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Schema', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/database/Schema/Blueprint.php', <<<'PHP'
        <?php
        class Blueprint
        {
            public function dropColumn($columns)
            {
                $columns = is_array($columns) ? $columns : func_get_args();

                return $columns;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-database-schema-blueprint.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/illuminate-database-schema-blueprint.php'))
            ->toContain('public function dropColumn($columns, ...$additionalColumns)')
            ->toContain('array_merge([$columns], $additionalColumns)')
            ->not->toContain('func_get_args()');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/database/Schema/Blueprint.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rejects nonempty Illuminate dynamic require data explicitly for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/filesystem', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/filesystem/Filesystem.php', <<<'PHP'
        <?php
        use RuntimeException;
        class Filesystem
        {
            public function getRequire($__path, array $__data = [])
            {
                return (static function () use ($__path, $__data) {
                        extract($__data, EXTR_SKIP);
                    return require $__path;
                })();
            }

            public function requireOnce($__path, array $__data = [])
            {
                return (static function () use ($__path, $__data) {
                        extract($__data, EXTR_SKIP);
                    return require_once $__path;
                })();
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/illuminate-filesystem.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-filesystem.php');
        expect(substr_count($source, 'AOT dynamic require does not support injected variables.'))
            ->toBe(2)
            ->and($source)
            ->not
            ->toContain('extract(')
            ->toContain('return require $__path;')
            ->toContain('return require_once $__path;');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/filesystem/Filesystem.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('declares Illuminate HTTP Response exception callbacks explicitly for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/http/Client', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/http/Client/Response.php', <<<'PHP'
        <?php
        class Response
        {
            public function throw()
            {
                $callback = func_get_args()[0] ?? null;

                return $callback;
            }

            public function throwIf($condition)
            {
                return value($condition, $this) ? $this->throw(func_get_args()[1] ?? null) : $this;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-http-client-response.php',
        ]);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-http-client-response.php');
        expect($source)
            ->toContain('public function throw($callback = null)')
            ->toContain('public function throwIf($condition, $callback = null)')
            ->toContain('$this->throw($callback)')
            ->not->toContain('func_get_args()');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/http/Client/Response.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('moves Illuminate JSON resource request transforms away from the TypePHP conversion keyword', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/http/Resources/Json', 0777, true);
    mkdir($directory . '/vendor/illuminate/http/Resources/JsonApi', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/http/Resources/Json/JsonResource.php', <<<'PHP'
        <?php
        class JsonResource
        {
            public function toAttributes(Request $request)
            {
                return $this->toArray($request);
            }

            public function toArray(Request $request)
            {
                return [];
            }
        }
        PHP);
    file_put_contents($directory . '/vendor/illuminate/http/Resources/Json/ResourceCollection.php', <<<'PHP'
        <?php
        class ResourceCollection extends JsonResource
        {
            public function toArray(Request $request)
            {
                return [];
            }
        }
        PHP);
    file_put_contents($directory . '/vendor/illuminate/http/Resources/JsonApi/JsonApiResource.php', <<<'PHP'
        <?php
        class JsonApiResource extends JsonResource
        {
            public function toAttributes(Request $request)
            {
                return $this->toArray($request);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-json-resource.php',
            '.typephp/build/illuminate-resource-collection.php',
            '.typephp/build/illuminate-json-api-resource.php',
        ]);
        $resource = (string) file_get_contents($directory . '/.typephp/build/illuminate-json-resource.php');
        $collection = (string) file_get_contents($directory . '/.typephp/build/illuminate-resource-collection.php');
        $jsonApi = (string) file_get_contents($directory . '/.typephp/build/illuminate-json-api-resource.php');
        expect($resource)
            ->toContain('public function toArray(): array')
            ->toContain('return $this->resolve($this->resolveRequestFromContainer());')
            ->toContain('public function toResourceArray(Request $request)')
            ->toContain('return $this->toResourceArray($request);')
            ->not->toContain('public function toArray(Request $request)');
        expect($collection)
            ->toContain('public function toResourceArray(Request $request)')
            ->not->toContain('public function toArray(Request $request)');
        expect($jsonApi)
            ->toContain('return $this->toResourceArray($request);')
            ->not->toContain('return $this->toArray($request);');

        file_put_contents($directory . '/vendor/illuminate/http/Resources/Json/JsonResource.php', str_replace(
            'return $this->toArray($request);',
            'return [];',
            (string) file_get_contents($directory . '/vendor/illuminate/http/Resources/Json/JsonResource.php'),
        ));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'compatibility rule expected 1 match(es), found 0: '
                . 'vendor/illuminate/http/Resources/Json/JsonResource.php',
            );
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands Blueprint integer compact options for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Schema', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/database/Schema/Blueprint.php',
        "<?php\nclass Blueprint\n{\n"
        . "    public function integer(\$column, \$autoIncrement = false, \$unsigned = false) {\n"
        . "        return compact('autoIncrement', 'unsigned');\n    }\n"
        . "    public function tinyInteger(\$column, \$autoIncrement = false, \$unsigned = false) {\n"
        . "        return compact('autoIncrement', 'unsigned');\n    }\n"
        . "    public function smallInteger(\$column, \$autoIncrement = false, \$unsigned = false) {\n"
        . "        return compact('autoIncrement', 'unsigned');\n    }\n"
        . "    public function mediumInteger(\$column, \$autoIncrement = false, \$unsigned = false) {\n"
        . "        return compact('autoIncrement', 'unsigned');\n    }\n"
        . "    public function bigInteger(\$column, \$autoIncrement = false, \$unsigned = false) {\n"
        . "        return compact('autoIncrement', 'unsigned');\n    }\n}\n",
    );

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory
        . '/.typephp/build/illuminate-database-schema-blueprint.php');
        expect(substr_count($generated, "['autoIncrement' => \$autoIncrement, 'unsigned' => \$unsigned]"))->toBe(5);
        expect($generated)->not->toContain("compact('autoIncrement', 'unsigned')");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands the Query Builder basic where compact expression for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Query', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/database/Query/Builder.php', <<<'PHP'
        <?php
        class Builder
        {
            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                $type = 'Basic';

                $this->wheres[] = compact(
                    'type', 'column', 'operator', 'value', 'boolean'
                );
            }

            public function whereColumn($first, $operator = null, $second = null, $boolean = 'and')
            {
                $type = 'Column';

                $this->wheres[] = compact(
                    'type', 'first', 'operator', 'second', 'boolean'
                );
            }

            public function whereJsonLength($column, $operator, $value = null, $boolean = 'and')
            {
                $type = 'JsonLength';
                $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');
            }

            public function having($column, $operator = null, $value = null, $boolean = 'and')
            {
                $type = 'Basic';
                $this->havings[] = compact('type', 'column', 'operator', 'value', 'boolean');
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/illuminate-database-query-builder.php');
        expect($generated)
            ->toContain("'operator' => \$operator")
            ->toContain("'second' => \$second")
            ->toContain("'value' => \$value")
            ->not->toContain("compact(\n            'type', 'column', 'operator', 'value', 'boolean'")
            ->not->toContain("compact(\n            'type', 'first', 'operator', 'second', 'boolean'")
            ->not->toContain("compact('type', 'column', 'operator', 'value', 'boolean')");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands date clause trait compact expressions for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Concerns', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/database/Concerns/BuildsWhereDateClauses.php', <<<'PHP'
        <?php
        trait BuildsWhereDateClauses
        {
            protected function wherePastOrFuture($columns, $operator, $boolean)
            {
                $type = 'Basic';
                $value = Carbon::now();
                foreach (Arr::wrap($columns) as $column) {
                    $this->wheres[] = compact('type', 'column', 'boolean', 'operator', 'value');
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory
        . '/.typephp/build/illuminate-database-builds-where-date-clauses.php');
        expect($generated)
            ->toContain("'operator' => \$operator")
            ->not->toContain("compact('type', 'column', 'boolean', 'operator', 'value')");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('initializes Eloquent one-of-many loop state for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Eloquent/Relations/Concerns', 0777, true);
    file_put_contents($directory
    . '/vendor/illuminate/database/Eloquent/Relations/Concerns/CanBeOneOfMany.php', <<<'PHP'
        <?php
        trait CanBeOneOfMany
        {
            public function ofMany($columns, $aggregate)
            {
                if ($aggregate instanceof Closure) {
                    $closure = $aggregate;
                }

                foreach ($columns as $column => $aggregate) {
                    $values = $previous['columns'] ?? [];
                    if (isset($previous)) {
                        consume($previous);
                    }
                    if (isset($closure)) {
                        $closure($subQuery);
                    }
                    if (! isset($previous)) {
                        consume($subQuery);
                    }
                    $previous = ['columns' => $values];
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory
        . '/.typephp/build/illuminate-eloquent-can-be-one-of-many.php');
        expect($generated)
            ->toContain('$previous = [];')
            ->toContain('if ($previous !== [])')
            ->toContain('if ($aggregate instanceof Closure)')
            ->toContain('if ($previous === [])')
            ->not->toContain('$closure');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps dotenv writer objects in stable local variable types for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/vlucas/phpdotenv/src/Repository', 0777, true);
    file_put_contents($directory . '/vendor/vlucas/phpdotenv/src/Repository/RepositoryBuilder.php', <<<'PHP'
        <?php
        class RepositoryBuilder
        {
            public function make()
            {
                $reader = new MultiReader($this->readers);
                $writer = new MultiWriter($this->writers);

                if ($this->immutable) {
                    $writer = new ImmutableWriter($writer, $reader);
                }

                if ($this->allowList !== null) {
                    $writer = new GuardedWriter($writer, $this->allowList);
                }

                return new AdapterRepository($reader, $writer);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/phpdotenv-repository-builder.php');
        expect($generated)
            ->toContain('if ($this->immutable && $this->allowList !== null)')
            ->toContain('new GuardedWriter(new ImmutableWriter($writer, $reader), $this->allowList)')
            ->not->toContain('$writer = new ImmutableWriter')
            ->not->toContain('$writer = new GuardedWriter');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('terminates dotenv parser switch cases for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/vlucas/phpdotenv/src/Parser', 0777, true);
    file_put_contents($directory . '/vendor/vlucas/phpdotenv/src/Parser/EntryParser.php', <<<'PHP'
        <?php
        class EntryParser
        {
            private static function processToken(int $state, string $token)
            {
                switch ($state) {
                    case self::INITIAL_STATE:
                        if ($token) {
                            return 1;
                        } else {
                            return 2;
                        }
                    case self::UNQUOTED_STATE:
                        if ($token) {
                            return 1;
                        } else {
                            return 2;
                        }
                    case self::SINGLE_QUOTED_STATE:
                        if ($token) {
                            return 1;
                        } else {
                            return 2;
                        }
                    case self::DOUBLE_QUOTED_STATE:
                        if ($token) {
                            return 1;
                        } else {
                            return 2;
                        }
                    case self::ESCAPE_SEQUENCE_STATE:
                        if ($token) {
                            return 1;
                        } else {
                            return 2;
                        }
                    case self::WHITESPACE_STATE:
                        if ($token) {
                            return 1;
                        } else {
                            return 2;
                        }
                    case self::COMMENT_STATE:
                        return 3;
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/phpdotenv-entry-parser.php');
        expect(substr_count($generated, "throw new \\Error('Unreachable parser state.');"))->toBe(6);
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses stable JWT exception variable types for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/firebase/php-jwt/src', 0777, true);
    file_put_contents($directory . '/vendor/firebase/php-jwt/src/JWT.php', <<<'PHP'
        <?php
        class JWT
        {
            public static function decode($payload, $timestamp)
            {
                if ($payload->nbf) {
                    $ex = new BeforeValidException(
                        'Cannot handle token with nbf prior to ' . \date(DateTime::ATOM, (int) floor($payload->nbf))
                    );
                    $ex->setPayload($payload);
                    throw $ex;
                }
                if ($payload->iat) {
                    $ex = new BeforeValidException(
                        'Cannot handle token with iat prior to ' . \date(DateTime::ATOM, (int) floor($payload->iat))
                    );
                    $ex->setPayload($payload);
                    throw $ex;
                }
                if ($payload->exp) {
                    $ex = new ExpiredException('Expired token');
                    $ex->setPayload($payload);
                    $ex->setTimestamp($timestamp);
                    throw $ex;
                }
            }

            public static function sodium($msg, $key)
            {
                switch ($msg) {
                    case 'sign':
                        try {
                            return sign($msg, $key);
                        } catch (Exception $e) {
                            throw new DomainException($e->getMessage(), 0, $e);
                        }
                    case 'verify':
                        try {
                            return verify($msg, $key);
                        } catch (Exception $e) {
                            throw new DomainException($e->getMessage(), 0, $e);
                        }
                    default:
                        return false;
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/firebase-jwt.php');
        expect($generated)
            ->toContain('$beforeValidNbf = new BeforeValidException')
            ->toContain('$beforeValidIat = new BeforeValidException')
            ->toContain('$expired = new ExpiredException')
            ->not->toContain('$ex =');
        expect(substr_count($generated, "throw new DomainException('Unreachable sodium operation.');"))->toBe(2);
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses stable Symfony controller reflection variable types for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/symfony/http-kernel/Exception', 0777, true);
    file_put_contents($directory
    . '/vendor/symfony/http-kernel/Exception/ControllerDoesNotReturnResponseException.php', <<<'PHP'
        <?php
        class ControllerDoesNotReturnResponseException
        {
            private function parseControllerDefinition(callable $controller): ?array
            {
                if (\is_array($controller)) {
                    try {
                        $r = new \ReflectionMethod($controller[0], $controller[1]);

                        return [
                            'file' => $r->getFileName(),
                            'line' => $r->getEndLine(),
                        ];
                    } catch (\ReflectionException) {
                        return null;
                    }
                }
                if ($controller instanceof \Closure) {
                    $r = new \ReflectionFunction($controller);

                    return [
                        'file' => $r->getFileName(),
                        'line' => $r->getEndLine(),
                    ];
                }
                if (\is_object($controller)) {
                    $r = new \ReflectionClass($controller);

                    try {
                        $line = $r->getMethod('__invoke')->getEndLine();
                    } catch (\ReflectionException) {
                        $line = $r->getEndLine();
                    }

                    return [
                        'file' => $r->getFileName(),
                        'line' => $line,
                    ];
                }
                return null;
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory
        . '/.typephp/build/symfony-http-kernel-controller-no-response.php');
        expect($generated)
            ->toContain('$methodReflection = new \ReflectionMethod')
            ->toContain('$functionReflection = new \ReflectionFunction')
            ->toContain('$classReflection = new \ReflectionClass')
            ->not->toContain('$r =');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the Symfony forwarded host array separate from the scalar host for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/symfony/http-foundation', 0777, true);
    file_put_contents($directory . '/vendor/symfony/http-foundation/Request.php', <<<'PHP'
        <?php

        // Help opcache.preload discover always-needed symbols
        class_exists(AcceptHeader::class);
        class_exists(FileBag::class);
        class_exists(HeaderBag::class);
        class_exists(HeaderUtils::class);
        class_exists(InputBag::class);
        class_exists(ParameterBag::class);
        class_exists(ServerBag::class);

        class Request
        {
            protected static ?array $formats = null;

            public function getHost(): string
            {
                if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST)) {
                    $host = $host[0];
                } else {
                    $host = '';
                }

                return strtolower($host);
            }

            public function getFormat(?string $mimeType/* , bool $subtypeFallback = false */): ?string
            {
                $subtypeFallback = 2 <= \func_num_args() ? func_get_arg(1) : false;

                return $subtypeFallback ? $mimeType : null;
            }

            public static function create(string $method, array $parameters): array
            {
                switch (strtoupper($method)) {
                    case 'POST':
                    case 'PUT':
                    case 'DELETE':
                    case 'QUERY':
                        if (true) {
                            $contentType = 'application/x-www-form-urlencoded';
                        }
                        // no break
                    case 'PATCH':
                        $request = $parameters;
                        $query = [];
                        break;
                    default:
                        $request = [];
                        $query = $parameters;
                        break;
                }
                return [$request, $query];
            }

            /**
             * Associates a format with mime types.
             */
            public function setFormat(?string $format, string|array $mimeTypes): void
            {
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/symfony-http-foundation-request.php',
        ]);
        $source = (string) file_get_contents($directory . '/.typephp/build/symfony-http-foundation-request.php');
        expect($source)
            ->toContain('$forwardedHosts = $this->getTrustedValues')
            ->toContain('$host = $forwardedHosts[0];')
            ->not->toContain('$host = $this->getTrustedValues')->toContain(
                'getFormat(?string $mimeType, bool $subtypeFallback = false)',
            )
            ->not->toContain('func_get_arg(1)')->toContain(
                'public static function resetFormatsForAot(): void',
            )->toContain('self::$formats = null;')->toContain(
                "\$query = [];\n                break;\n            case 'PATCH':",
            )
            ->not->toContain('class_exists(');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/symfony/http-foundation/Request.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('replaces Symfony RequestStack closure binding with the AOT reset bridge', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-request-stack-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/RequestStack.php', <<<'PHP'
        <?php
        class RequestStack
        {
            public function resetRequestFormats(): void
            {
                static $resetRequestFormats;
                $resetRequestFormats ??= \Closure::bind(static fn () => self::$formats = null, null, Request::class);
                $resetRequestFormats();
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-foundation-request-stack.php');

        expect($content)
            ->toContain('Request::resetFormatsForAot();')
            ->not->toContain('Closure::bind')
            ->not->toContain('$resetRequestFormats');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('makes Symfony ResponseHeaderBag partitioned cookies explicit in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-response-headers-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/ResponseHeaderBag.php', <<<'PHP'
        <?php
        class ResponseHeaderBag
        {
            public function clearCookie(string $name, ?string $path = '/', ?string $domain = null, bool $secure = false, bool $httpOnly = true, ?string $sameSite = null /* , bool $partitioned = false */): void
            {
                $partitioned = 6 < \func_num_args() ? func_get_arg(6) : false;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-foundation-response-header-bag.php');

        expect($content)
            ->toContain('?string $sameSite = null, bool $partitioned = false')
            ->not->toContain('func_get_arg')
            ->not->toContain('func_num_args');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony PDO session payloads type-stable through an AOT holder', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-pdo-session-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation/Session/Storage/Handler';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/PdoSessionHandler.php', <<<'PHP'
        <?php
        class PdoSessionHandler
        {
            private function buildDsn(array $params, string $driver): string
            {
                $dsn = null;
                switch ($driver) {
                    case 'mysql':
                        $dsn = 'mysql:';
                        // If "unix_socket" is not in the query, we continue with the same process as pgsql
                        // no break
                    case 'pgsql':
                        $dsn ??= 'pgsql:';
                        if (isset($params['host']) && '' !== $params['host']) {
                            $dsn .= 'host='.$params['host'].';';
                        }
                        if (isset($params['port']) && '' !== $params['port']) {
                            $dsn .= 'port='.$params['port'].';';
                        }
                        if (isset($params['path'])) {
                            $dbName = substr($params['path'], 1); // Remove the leading slash
                            $dsn .= 'dbname='.$dbName.';';
                        }
                        return $dsn;
                }
            }

            private function getInsertStatement(#[\SensitiveParameter] string $sessionId, string $sessionData, int $maxlifetime): \PDOStatement
            {
                        $data = fopen('php://memory', 'r+');
                        fwrite($data, $sessionData);
                        rewind($data);
                        $data = $sessionData;
                $stmt->bindParam(':data', $data, \PDO::PARAM_LOB);
            }

            private function getUpdateStatement(#[\SensitiveParameter] string $sessionId, string $sessionData, int $maxlifetime): \PDOStatement
            {
                        $data = fopen('php://memory', 'r+');
                        fwrite($data, $sessionData);
                        rewind($data);
                        $data = $sessionData;
                $stmt->bindParam(':data', $data, \PDO::PARAM_LOB);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-foundation-pdo-session-handler.php');

        expect(substr_count($content, '$dataHolder = new \stdClass();'))
            ->toBe(2)
            ->and($content)
            ->toContain('fwrite($dataHolder->value, $sessionData)')
            ->toContain('rewind($dataHolder->value)')
            ->toContain('$dataHolder->value = $sessionData')
            ->toContain("bindParam(':data', \$dataHolder->value, \\PDO::PARAM_LOB)")
            ->not->toContain('// no break')->toContain("case 'mysql':\n                \$dsn = 'mysql:';")->toContain(
                "return \$dsn;\n\n            case 'pgsql':",
            )
            ->not->toContain('$data = fopen')
            ->not->toContain('$data = $sessionData');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('makes Symfony URI expiration explicit in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-uri-signer-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/UriSigner.php', <<<'PHP'
        <?php
        class UriSigner
        {
            public function sign(string $uri/* , \DateTimeInterface|\DateInterval|int|null $expiration = null */): string
            {
                $expiration = null;

                if (1 < \func_num_args()) {
                    $expiration = func_get_arg(1);
                }

                return $uri;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-foundation-uri-signer.php');

        expect($content)
            ->toContain('sign(string $uri, \DateTimeInterface|\DateInterval|int|null $expiration = null)')
            ->not->toContain('func_get_arg')
            ->not->toContain('func_num_args')
            ->not->toContain('$expiration = null;');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony controller strings separate from resolved callables for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-controller-resolver-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-kernel/Controller';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/ControllerResolver.php', <<<'PHP'
        <?php
        class ControllerResolver
        {
            protected function createController(string $controller): callable
            {
                if (!str_contains($controller, '::')) {
                    $controller = $this->instantiateController($controller);

                    if (!\is_callable($controller)) {
                        throw new \InvalidArgumentException($this->getControllerError($controller));
                    }

                    return $controller;
                }

                [$class, $method] = explode('::', $controller, 2);

                try {
                    $controller = [$this->instantiateController($class), $method];
                } catch (\Error|\LogicException $e) {
                    throw $e;
                }

                if (!\is_callable($controller)) {
                    throw new \InvalidArgumentException($this->getControllerError($controller));
                }

                return $controller;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-kernel-controller-resolver.php');

        expect($content)
            ->toContain('$controllerInstance = $this->instantiateController($controller)')
            ->toContain('return $controllerInstance;')
            ->toContain('$controllerCallable = [$this->instantiateController($class), $method]')
            ->toContain('return $controllerCallable;')
            ->not->toContain('$controller = $this->instantiateController')
            ->not->toContain('$controller = [$this->instantiateController');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('makes Symfony exception log channels explicit in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-error-listener-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-kernel/EventListener';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/ErrorListener.php', <<<'PHP'
        <?php
        class ErrorListener
        {
            protected function logException(\Throwable $exception, string $message, ?string $logLevel = null/* , ?string $logChannel = null */): void
            {
                $logChannel = (3 < \func_num_args() ? func_get_arg(3) : null) ?? $this->resolveLogChannel($exception);
            }

            private function getInheritedAttribute(string $class, string $attribute): ?object
            {
                $class = new \ReflectionClass($class);
                $interfaces = [];
                $parentInterfaces = [];
                do {
                    if ($attributes = $class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)) {
                        $parentInterfaces = class_implements($class->name);
                    }
                    $interfaces[] = class_implements($class->name);
                } while ($class = $class->getParentClass());

                while ($interfaces) {
                    foreach ($interfaces as $interface) {
                        $class = new \ReflectionClass($interface);

                        if ($attributes = $class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)) {
                        }
                    }
                }
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-kernel-error-listener.php');

        expect($content)
            ->toContain('?string $logLevel = null, ?string $logChannel = null')
            ->toContain('$logChannel ??= $this->resolveLogChannel($exception);')
            ->not->toContain('func_get_arg')
            ->not->toContain('func_num_args')->toContain('$reflectionClass = new \ReflectionClass($class);')->toContain(
                '$interfaceReflection = new \ReflectionClass($interface);',
            )
            ->not->toContain('$class = new \ReflectionClass');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('avoids Symfony private session container shadowing in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-session-listener-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-kernel/EventListener';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/SessionListener.php', <<<'PHP'
        <?php
        class SessionListener
        {
            public function __construct(
                private ?ContainerInterface $container = null,
                bool $debug = false,
                array $sessionOptions = [],
            ) {
                parent::__construct($container, $debug, $sessionOptions);
            }

            protected function getSession(): mixed
            {
                return $this->container->has('session_factory')
                    ? $this->container->get('session_factory')
                    : null;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-kernel-session-listener.php');

        expect($content)
            ->toContain('private ?ContainerInterface $sessionContainer = null')
            ->toContain('parent::__construct($sessionContainer, $debug, $sessionOptions)')
            ->toContain('$this->sessionContainer->has')
            ->toContain('$this->sessionContainer->get')
            ->not->toContain('$this->container->');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses the stable Symfony main request value in the AOT interface default', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-http-kernel-interface-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-kernel';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/HttpKernelInterface.php', <<<'PHP'
        <?php
        interface HttpKernelInterface
        {
            public const MAIN_REQUEST = 1;
            public const SUB_REQUEST = 2;

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response;
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-kernel-interface.php');

        expect($content)
            ->toContain('public const MAIN_REQUEST = 1;')
            ->toContain('public const SUB_REQUEST = 2;')
            ->toContain('int $type = 1')
            ->not->toContain('int $type = self::MAIN_REQUEST');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('avoids Symfony private MIME encoder shadowing in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-parameterized-header-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/mime/Header';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/ParameterizedHeader.php', <<<'PHP'
        <?php
        class ParameterizedHeader
        {
            private ?Rfc2231Encoder $encoder = null;

            public function encode(): string
            {
                $this->encoder = new Rfc2231Encoder();

                return $this->encoder->encodeString('value');
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-mime-parameterized-header.php');

        expect($content)
            ->toContain('private ?Rfc2231Encoder $parameterEncoder = null')
            ->toContain('$this->parameterEncoder = new Rfc2231Encoder()')
            ->toContain('$this->parameterEncoder->encodeString')
            ->not->toContain('$this->encoder');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkORM table input separate from its normalized array result for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-base-query-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/BaseQuery.php', <<<'PHP'
        <?php
        class BaseQuery
        {
            protected function tableStr(string $table): array | string
            {
                if (!str_contains($table, ',')) {
                    if (str_contains($table, ' ')) {
                        [$item, $alias] = explode(' ', $table);
                        $table          = [];
                        $table[$item] = $alias;
                    }
                } else {
                    $tables = explode(',', $table);
                    $table  = [];
                    foreach ($tables as $item) {
                        $table[] = $item;
                    }
                }
                return $table;
            }

            /**
             * 指定多个数据表（数组格式）.
             */
            protected function tableArr(array $tables): array
            {
                return $tables;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-base-query.php');

        expect($content)
            ->toContain('$tableState = new \stdClass()')
            ->toContain('$tableState->value = $table')
            ->toContain('$tableState->value[$item]')
            ->toContain('$tableState->value[] = $item')
            ->toContain('return $tableState->value')
            ->not->toContain('$table  = []')
            ->not->toContain('$table          = []');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkORM relation result mutable across array and model states for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-relation-query-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db/concern';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/ModelRelationQuery.php', <<<'PHP'
        <?php
        trait ModelRelationQuery
        {
            protected function resultToModel(array &$result): void
            {
                $result = $this->model->newInstance($result);
                $result->setSuffix($this->suffix);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-model-relation-query.php');

        expect($content)->toContain('resultToModel(mixed &$result)')->not->toContain('resultToModel(array &$result)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('stores the ThinkORM find result in a dynamic property before model conversion for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-find-result-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/BaseQuery.php', <<<'PHP'
        <?php
        class BaseQuery
        {
            public function find($data = null, ?Closure $closure = null)
            {
                if (empty($this->options['where']) && empty($this->options['scope']) && empty($this->options['order']) && empty($this->options['sort'])) {
                    $result = [];
                } else {
                    $result = $this->connection->find($this);
                }

                // 数据处理
                if (empty($result)) {
                    return $this->resultToEmpty($closure);
                }

                if (!empty($this->model)) {
                    // 返回模型对象
                    $this->resultToModel($result);
                } else {
                    $this->result($result);
                }

                return $result;
            }

            /**
             * 分析表达式（可用于查询或者写入操作）.
             */
            public function parseExpression(): void
            {
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-base-query.php');

        expect($content)
            ->toContain('$resultState = new \stdClass()')
            ->toContain('$resultState->value = $this->connection->find($this)')
            ->toContain('$this->resultToModel($resultState->value)')
            ->toContain('return $resultState->value')
            ->not->toContain('$this->resultToModel($result)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('accepts the connection argument supplied to ThinkORM model transaction closures for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-model-transactions-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Model.php', <<<'PHP'
        <?php
        class Model
        {
            public function write(): void
            {
                $db->transaction(function () use ($data, $allowFields, $db) {});
                $db->transaction(function () use ($data, $sequence, $allowFields, $db) {});
                $result = $db->transaction(function () use ($replace, $dataSet) {});
                $db->transaction(function () use ($where, $db) {});
                $untouched = function () {};
            }

            public static function query(): Query
            {
                return (new static())->db();
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-model.php');

        expect(substr_count($content, 'transaction(function ($connection) use'))
            ->toBe(4)
            ->and($content)
            ->toContain('$untouched = function () {}')
            ->toContain('public static function where(...$args)')
            ->toContain("return static::__callStatic('where', \$args)")
            ->not->toContain('transaction(function () use');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkORM relation names distinct from false in attribute lookup for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-attribute-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/model/concern';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Attribute.php', <<<'PHP'
        <?php
        trait Attribute
        {
            public function getAttr(string $name)
            {
                try {
                    $relation = false;
                    if ($this->mapping) {
                        $name = array_search($name, $this->mapping) ?: $name;
                    }
                    $value    = $this->getData($name);
                } catch (InvalidArgumentException $e) {
                    $relation = $this->isRelationAttr($name);
                    $value    = null;
                }

                return $this->getValue($name, $value, $relation);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-attribute.php');

        expect($content)
            ->toContain('return $this->getValue($name, null, $this->isRelationAttr($name))')
            ->toContain('return $this->getValue($name, $value, false)')
            ->not->toContain('$relation = false')
            ->not->toContain('$relation = $this->isRelationAttr($name)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('returns formatted ThinkORM timestamps before the integer fallback fixes their type for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-time-stamp-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/model/concern';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/TimeStamp.php', <<<'PHP'
        <?php
        trait TimeStamp
        {
            protected function getTimeTypeValue(string $type)
            {
                $value = time();
                switch ($type) {
                    case 'datetime':
                    case 'date':
                    case 'timestamp':
                        $value = $this->formatDateTime('Y-m-d H:i:s.u');
                        break;
                    default:
                        if (str_contains($type, '\\')) {
                            $obj = new $type();
                            if ($obj instanceof Stringable) {
                                $value = $obj->__toString();
                            }
                        }
                }
                return $value;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-time-stamp.php');

        expect($content)
            ->toContain("return \$this->formatDateTime('Y-m-d H:i:s.u')")
            ->toContain('return $obj->__toString()')
            ->toContain("                break;\n        }")
            ->toContain('return $value')
            ->not->toContain("\$value = \$this->formatDateTime('Y-m-d H:i:s.u')");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkORM result set mutable across array and collection states for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-result-operation-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db/concern';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/ResultOperation.php', <<<'PHP'
        <?php
        trait ResultOperation
        {
            protected function resultSet(array &$resultSet, bool $toCollection = true): void
            {
                if ($toCollection) {
                    $resultSet = new Collection($resultSet);
                }
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-result-operation.php');

        expect($content)->toContain('resultSet(mixed &$resultSet')->not->toContain('resultSet(array &$resultSet');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkORM column input separate from its parsed field array for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-fetch-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Fetch.php', <<<'PHP'
        <?php
        class Fetch
        {
            public function column(string $field): void
            {
                $field = array_map('trim', explode(',', $field));

                $this->query->setOption('field', $field);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-fetch.php');

        expect($content)
            ->toContain('$columnFields = array_map')
            ->toContain("setOption('field', \$columnFields)")
            ->not->toContain('$field = array_map');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps ThinkORM insert IDs type-flexible through an AOT state holder', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-pdo-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/PDOConnection.php', <<<'PHP'
        <?php
        class PDOConnection
        {
            public function getTableInfo(array | string $tableName, string $fetch = '')
            {
                return [];
            }

            public function getFieldsType($tableName, ?string $field = null)
            {
                return [];
            }

            public function getTableFieldsInfo(string $tableName): array
            {
                $info = [];
                foreach ($this->getFields($tableName) as $key => $val) {
                    if (!empty($val['primary'])) {
                        $pk[] = $key;
                    }
                }

                if (isset($pk)) {
                    $pk          = count($pk) > 1 ? $pk : $pk[0];
                    $info['_pk'] = $pk;
                }

                return $info;
            }

            protected function autoInsIDType(BaseQuery $query, string $insertId)
            {
                if ($query->asInt()) {
                    $insertId = (int) $insertId;
                } elseif ($query->asFloat()) {
                    $insertId = (float) $insertId;
                }

                return $insertId;
            }

            protected function multiConnect(bool $master = false): PDO
            {
                $config = [];
                $m = 0;
                $r = 1;
                $dbMaster = false;

                if ($m != $r) {
                    $dbMaster = [];
                    foreach (['hostname'] as $name) {
                        $dbMaster[$name] = $config[$name][$m] ?? $config[$name][0];
                    }
                }

                $dbConfig = [];

                return $this->connect($dbConfig, $r, $r == $m ? false : $dbMaster);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-pdo-connection.php');

        expect($content)
            ->toContain('getTableInfo(mixed $tableName, string $fetch = \'\')')
            ->toContain('getFieldsType(mixed $tableName, ?string $field = null)')
            ->toContain('$info[\'_pk\'] = count($pk) > 1 ? $pk : $pk[0]')
            ->not->toContain('$pk          = count($pk) > 1')->toContain('$insertIdState = new \stdClass()')->toContain(
                '$insertIdState->value = (int) $insertId',
            )->toContain('$insertIdState->value = (float) $insertId')->toContain('return $insertIdState->value')
            ->not->toContain('$insertId = (int)')->toContain('$dbMasterState = new \stdClass()')->toContain(
                '$dbMasterState->value = []',
            )->toContain('$dbMasterState->value[$name] =')->toContain('$dbMasterState->value)')
            ->not->toContain('$dbMaster = false');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses a static ThinkORM query class for the AOT instanceof check', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-where-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db/concern';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/WhereQuery.php', "<?php\n        if (\$field instanceof \$this) {}\n");

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-where-query.php');

        expect($content)
            ->toContain('$queryClass = get_class($this)')
            ->toContain('$field instanceof $queryClass')
            ->not->toContain('$field instanceof BaseQuery')
            ->not->toContain('$field instanceof $this');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('initializes ThinkORM insert-all field state explicitly for AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-base-builder-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/BaseBuilder.php', <<<'PHP'
        <?php
        class BaseBuilder
        {
            public function insertAll(array $dataSet): array
            {
                $fields = [];
                $values = [];

                foreach ($dataSet as $data) {
                    if (!isset($insertFields)) {
                        $insertFields = array_keys($data);
                    }
                }
                return $insertFields;
            }
        }
        PHP);
    mkdir($sourceDirectory . '/builder', 0777, true);
    file_put_contents($sourceDirectory . '/builder/Mysql.php', str_replace(
        'class BaseBuilder',
        'class Mysql',
        (string) file_get_contents($sourceDirectory . '/BaseBuilder.php'),
    ));

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        foreach (['think-orm-base-builder.php', 'think-orm-mysql-builder.php'] as $target) {
            $content = file_get_contents($directory . '/.typephp/build/' . $target);

            expect($content)
                ->toContain('$insertFields = [];')
                ->toContain('$hasInsertFields = false;')
                ->toContain('if (!$hasInsertFields)')
                ->toContain('$hasInsertFields = true;')
                ->not->toContain('isset($insertFields)');
        }
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('makes SaiAdmin crontab switch cases terminal in the AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-crontab-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/plugin/saiadmin/app/logic/tool';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/CrontabLogic.php', <<<'PHP'
        <?php
        class CrontabLogic
        {
            public function run(int $type): bool
            {
                switch ($type) {
                    case 1:
                        try {
                            return true;
                        } catch (\Exception $e) {
                            return false;
                        }
                    case 2:
                        try {
                            return true;
                        } catch (\Exception $e) {
                            return false;
                        }
                    case 3:
                        if ($type) {
                            return true;
                        } else {
                            return false;
                        }
                    default:
                        return false;
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/saiadmin-crontab-logic.php');

        expect(substr_count($content, '                break;'))->toBe(3);
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony console event objects in distinct AOT variables', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-console-application-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/console';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Application.php', <<<'PHP'
        <?php
        class Application
        {
            protected function doRunCommand($command, $input, $output): int
            {
                $event = new ConsoleCommandEvent($command, $input, $output);
                $e = null;

                try {
                    $this->dispatcher->dispatch($event, ConsoleEvents::COMMAND);

                    if ($event->commandShouldRun()) {
                        $exitCode = $command->run($input, $output);
                    }
                } catch (\Throwable $e) {
                    $event = new ConsoleErrorEvent($input, $output, $e, $command);
                    $this->dispatcher->dispatch($event, ConsoleEvents::ERROR);
                    $e = $event->getError();

                    if (0 === $exitCode = $event->getExitCode()) {
                        $e = null;
                    }
                }

                $event = new ConsoleTerminateEvent($command, $input, $output, $exitCode);
                $this->dispatcher->dispatch($event, ConsoleEvents::TERMINATE);

                if (null !== $e) {
                    throw $e;
                }

                return $event->getExitCode();
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-console-application.php');

        expect($content)
            ->toContain('$commandEvent = new ConsoleCommandEvent')
            ->toContain('$errorEvent = new ConsoleErrorEvent')
            ->toContain('$terminateEvent = new ConsoleTerminateEvent')
            ->toContain('return $terminateEvent->getExitCode();');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('matches ThinkORM dynamic parser calls to their AOT arity', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-builder-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/db';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents(
        $sourceDirectory . '/Builder.php',
        "<?php\n                return \$this->\$fun(\$query, \$key, \$exp, \$value, \$field, \$bindType, \$val[2] ?? 'AND');\n",
    );

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-builder.php');

        expect($content)
            ->toContain('$fun === \'parseLike\'')
            ->toContain('$this->$fun($query, $key, $exp, $value, $field, $bindType, $val[2] ?? \'AND\')')
            ->toContain('[\'parseRegexp\', \'parseFindInSet\']')
            ->toContain('$this->$fun($query, $key, $exp, $value, $field);')
            ->toContain(
                '[\'parseCompare\', \'parseBetween\', \'parseIn\', \'parseExp\', \'parseNull\', \'parseBetweenTime\', \'parseTime\', \'parseExists\', \'parseColumn\']',
            )
            ->toContain('$this->$fun($query, $key, $exp, $value, $field, $bindType);');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('replaces the SaiAdmin CodeEngine runtime constant guard with a declaration', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-code-engine-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/plugin/saiadmin/utils/code';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/CodeEngine.php', <<<'PHP'
        <?php
        namespace plugin\saiadmin\utils\code;

        // 定义目录分隔符常量
        defined('DS') or define('DS', DIRECTORY_SEPARATOR);

        class CodeEngine
        {
            public function templatePath(): string
            {
                return base_path() . DS . 'plugin';
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/saiadmin-code-engine.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/saiadmin-code-engine.php');
        expect($source)
            ->toContain('const DS = DIRECTORY_SEPARATOR;')
            ->not->toContain("defined('DS')")
            ->not->toContain("define('DS'");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('stabilizes SaiAdmin captcha colors and uses a packaged runtime font in AOT', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-captcha-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/plugin/saiadmin/utils';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Captcha.php', <<<'PHP'
        <?php
        class Captcha
        {
            public static function imageCaptcha(): array
            {
                $captcha = new CaptchaBuilder();
                $captcha->setBackgroundColor(242, 243, 245);
                $captcha->build(120, 36);
                $uuid = Uuid::uuid4();
                $key = $uuid->toString();
                return ['uuid' => $key];
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/saiadmin-captcha.php');

        expect($content)
            ->toContain('$captcha->setLineColor(180, 180, 180);')
            ->toContain('$captcha->build(120, 36, base_path(\'vendor/webman/captcha/src/Font/captcha0.ttf\'));')
            ->toContain('Uuid::uuid4()')
            ->toContain('$uuid->toString()');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses explicit SaiAdmin public routes when TypePHP reflection omits protected defaults', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-reflection-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/plugin/saiadmin/app/cache';
    $controllerDirectory = $directory . '/plugin/saiadmin/app/controller';
    mkdir($sourceDirectory, 0777, true);
    mkdir($controllerDirectory, 0777, true);
    file_put_contents(
        $controllerDirectory . '/LoginController.php',
        "<?php\nclass LoginController\n{\n"
        . "    protected array \$noNeedLogin = ['captcha', 'login', 'refresh'];\n"
        . "    public function captcha() : Response {}\n}\n",
    );
    file_put_contents(
        $controllerDirectory . '/InstallController.php',
        "<?php\nclass InstallController\n{\n"
        . "    protected array \$noNeedLogin = ['index', 'install'];\n"
        . "    public function index() {}\n}\n",
    );
    $source = <<<'PHP'
        <?php
        class ReflectionCache
        {
            public static function getNoNeedLogin(string $controller): array
            {
                // 反射逻辑
                if (class_exists($controller)) {
                    $ref = new ReflectionClass($controller);
                    $data = $ref->getDefaultProperties()['noNeedLogin'] ?? [];
                } else {
                    $data = [];
                }
                return $data;
            }
        }
        PHP;
    file_put_contents($sourceDirectory . '/ReflectionCache.php', $source);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/saiadmin-reflection-cache.php');

        expect($content)
            ->toContain('LoginController::class')
            ->toContain('$data = [\'captcha\', \'login\', \'refresh\'];')
            ->toContain('InstallController::class')
            ->toContain('$data = [\'index\', \'install\'];')
            ->toContain('elseif (class_exists($controller))');

        file_put_contents($sourceDirectory . '/ReflectionCache.php', str_replace(
            '// 反射逻辑',
            '// upstream changed',
            $source,
        ));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(RuntimeException::class, 'SaiAdmin reflection compatibility rule expected 1 match(es), found 0');

        file_put_contents($sourceDirectory . '/ReflectionCache.php', $source);
        file_put_contents(
            $controllerDirectory . '/LoginController.php',
            "<?php\nclass LoginController\n{\n"
            . "    protected array \$noNeedLogin = self::PUBLIC_ACTIONS;\n"
            . "    public function captcha() : Response {}\n}\n",
        );
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(RuntimeException::class, 'requires a static noNeedLogin list');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('declares the SaiAdmin captcha request argument in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-login-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/plugin/saiadmin/app/controller';
    mkdir($sourceDirectory, 0777, true);
    $source = <<<'PHP'
        <?php
        use support\Request;
        use support\Response;
        class LoginController
        {
            public function captcha() : Response
            {
                return new Response();
            }
        }
        PHP;
    file_put_contents($sourceDirectory . '/LoginController.php', $source);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/saiadmin-login-controller.php');

        expect($content)
            ->toContain('public function captcha(Request $request) : Response')
            ->not->toContain('public function captcha() : Response');

        file_put_contents($sourceDirectory . '/LoginController.php', str_replace(
            'captcha() : Response',
            'captcha(Request $request) : Response',
            $source,
        ));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin controller request arity compatibility rule expected 1 match(es), found 0',
            );

        file_put_contents($sourceDirectory . '/LoginController.php', $source . "\n" . $source);
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin controller request arity compatibility rule expected 1 match(es), found 2',
            );
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('makes the SaiAdmin system exception previous cause explicitly nullable', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-system-exception-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/plugin/saiadmin/exception';
    mkdir($sourceDirectory, 0777, true);
    $source = <<<'PHP'
        <?php
        use Throwable;
        class SystemException extends RuntimeException
        {
            public function __construct($message, $code = 400, Throwable $previous = null)
            {
                parent::__construct($message, $code, $previous);
            }
        }
        PHP;
    file_put_contents($sourceDirectory . '/SystemException.php', $source);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/saiadmin-system-exception.php');

        expect($content)->toContain('?Throwable $previous = null')->not->toContain(', Throwable $previous = null');

        file_put_contents($sourceDirectory . '/SystemException.php', str_replace(
            ', Throwable $previous = null)',
            ', ?Throwable $previous = null)',
            $source,
        ));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin system exception nullable cause compatibility rule expected 1 match(es), found 0',
            );

        file_put_contents($sourceDirectory . '/SystemException.php', $source . "\n" . $source);
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin system exception nullable cause compatibility rule expected 1 match(es), found 2',
            );
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('aligns the Nelexa response stream with PSR signatures in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-nelexa-response-stream-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/nelexa/zip/src/IO/Stream';
    mkdir($sourceDirectory, 0777, true);
    $source = <<<'PHP'
        <?php
        class ResponseStream
        {
            public function getMetadata($key = null)
            {
            }
            public function tell()
            {
                return $this->stream ? ftell($this->stream) : false;
            }
            public function seek($offset, $whence = \SEEK_SET): void {}
            public function write($string)
            {
                return $this->stream !== null && $this->writable ? fwrite($this->stream, $string) : false;
            }
            public function read($length): string {}
        }
        PHP;
    file_put_contents($sourceDirectory . '/ResponseStream.php', $source);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/nelexa-response-stream.php');

        expect($content)
            ->toContain('tell(): int')
            ->toContain('seek(int $offset, int $whence')
            ->toContain('write(string $string): int')
            ->toContain('read(int $length): string');

        file_put_contents($sourceDirectory . '/ResponseStream.php', str_replace(
            'public function tell()',
            'public function tell(): int',
            $source,
        ));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin Nelexa PSR stream signature compatibility rule expected 1 match(es), found 0',
            );

        file_put_contents($sourceDirectory . '/ResponseStream.php', $source . "\n" . $source);
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin Nelexa PSR stream signature compatibility rule expected 1 match(es), found 2',
            );
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('matches ThinkORM collection callbacks to each value and key arguments', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-think-orm-collection-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/topthink/think-orm/src/model';
    mkdir($sourceDirectory, 0777, true);
    $closure = '$this->each(function (Model $model) { return $model; });';
    file_put_contents($sourceDirectory . '/Collection.php', "<?php\n" . str_repeat($closure . "\n", 10));

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/think-orm-model-collection.php');
        expect(substr_count($content, 'function (Model $model, int|string $key)'))->toBe(10);

        file_put_contents($sourceDirectory . '/Collection.php', "<?php\n" . str_repeat($closure . "\n", 9));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin ThinkORM collection callback arity compatibility rule expected 10 match(es), found 9',
            );

        file_put_contents($sourceDirectory . '/Collection.php', "<?php\n" . str_repeat($closure . "\n", 11));
        expect(fn(): array => $generator->generateSwitchTerminalSources())
            ->toThrow(
                RuntimeException::class,
                'SaiAdmin ThinkORM collection callback arity compatibility rule expected 10 match(es), found 11',
            );
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('passes integral pixel coordinates to GD in the Webman captcha AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-webman-captcha-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/webman/captcha/src';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/CaptchaBuilder.php', '<?php return imagecolorat($image, $x, $y);');

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/webman-captcha-builder.php');

        expect($content)
            ->toContain('imagecolorat($image, (int) $x, (int) $y)')
            ->not->toContain('imagecolorat($image, $x, $y)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony uploaded filesize parsing type-stable for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/symfony/http-foundation/File', 0777, true);
    file_put_contents($directory . '/vendor/symfony/http-foundation/File/UploadedFile.php', <<<'PHP'
        <?php
        class UploadedFile
        {
            private int $error = 0;

            public function move(): void
            {
                switch ($this->error) {
                }
            }

            private static function parseFilesize(string $size): int|float
            {
                $size = strtolower($size);
                $max = ltrim($size, '+');
                if (str_starts_with($max, '0x')) {
                    $max = \intval($max, 16);
                } elseif (str_starts_with($max, '0')) {
                    $max = \intval($max, 8);
                } else {
                    $max = (int) $max;
                }
                switch (substr($size, -1)) {
                    case 't': $max *= 1024;
                        // no break
                    case 'g': $max *= 1024;
                        // no break
                    case 'm': $max *= 1024;
                        // no break
                    case 'k': $max *= 1024;
                }
                return $max;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/symfony-http-foundation-uploaded-file.php',
        ]);
        $source = (string) file_get_contents($directory . '/.typephp/build/symfony-http-foundation-uploaded-file.php');
        expect($source)
            ->toContain('$maxString = ltrim')
            ->toContain('switch ((int) $this->error)')
            ->toContain('case \'t\': $max *= 1024 * 1024 * 1024 * 1024; break;')
            ->not->toContain('// no break');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/symfony/http-foundation/File/UploadedFile.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rejects Illuminate Redis custom closure rebinding explicitly for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/redis', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/redis/RedisManager.php', <<<'PHP'
        <?php
        use Closure;
        class RedisManager
        {
            public function extend($driver, Closure $callback)
            {
                $this->customCreators[$driver] = $callback->bindTo($this, $this);

                return $this;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/illuminate-redis-manager.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-redis-manager.php');
        expect($source)
            ->toContain('AOT Redis custom driver closure binding is not supported.')
            ->not->toContain('->bindTo(');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/redis/RedisManager.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses the explicit owner for Carbon inherited default constants in AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/CarbonInterface.php',
        "<?php\nuse Carbon\\Constants\\TranslationOptions;\ninterface CarbonInterface extends TranslationOptions\n{\n"
        . "    public static function translateTimeString(string \$timeString, ?string \$from = null, "
        . "?string \$to = null, int \$mode = self::TRANSLATE_ALL): string;\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-interface.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-interface.php');
        expect($source)
            ->toContain('int $mode = TranslationOptions::TRANSLATE_ALL')
            ->not->toContain('int $mode = self::TRANSLATE_ALL');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/nesbot/carbon/src/Carbon/CarbonInterface.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('folds Illuminate password facade contract constants in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/support/Facades', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/support/Facades/Password.php',
        "<?php\nclass Password\n{\n"
        . "    const ResetLinkSent = PasswordBroker::RESET_LINK_SENT;\n"
        . "    const PasswordReset = PasswordBroker::PASSWORD_RESET;\n"
        . "    const InvalidUser = PasswordBroker::INVALID_USER;\n"
        . "    const InvalidToken = PasswordBroker::INVALID_TOKEN;\n"
        . "    const ResetThrottled = PasswordBroker::RESET_THROTTLED;\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-support-password-facade.php',
        ]);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-support-password-facade.php');
        expect($source)
            ->toContain("const ResetLinkSent = 'passwords.sent';")
            ->toContain("const PasswordReset = 'passwords.reset';")
            ->toContain("const InvalidUser = 'passwords.user';")
            ->toContain("const InvalidToken = 'passwords.token';")
            ->toContain("const ResetThrottled = 'passwords.throttled';")
            ->not->toContain('PasswordBroker::');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('rejects Illuminate custom instance closure rebinding explicitly for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/support', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/support/MultipleInstanceManager.php',
        "<?php\nclass MultipleInstanceManager\n{\n    public function extend(\$name, Closure \$callback)\n    {\n"
        . "        \$this->customCreators[\$name] = \$callback->bindTo(\$this, \$this);\n"
        . "        return \$this;\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-support-multiple-instance-manager.php',
        ]);
        $source = (string) file_get_contents($directory
        . '/.typephp/build/illuminate-support-multiple-instance-manager.php');
        expect($source)
            ->toContain('AOT custom instance creator closure binding is not supported.')
            ->not->toContain('->bindTo(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('defers Illuminate Sleep static reference storage in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/support', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/support/Sleep.php',
        "<?php\nclass Sleep\n{\n    public function run()\n    {\n        \$while = function () {\n"
        . "            static \$return = [true, false];\n            return array_shift(\$return);\n        };\n"
        . "        \$duration = CarbonInterval::seconds(0);\n"
        . "        \$micro = CarbonInterval::microsecond(0);\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/illuminate-support-sleep.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-support-sleep.php');
        expect($source)
            ->toContain("static \$return;\n            if (\$return === null)")
            ->toContain('$return = [true, false];')
            ->toContain('array_shift($return)')
            ->toContain('new CarbonInterval(0, 0, 0, 0, 0, 0, 0)')
            ->not->toContain('CarbonInterval::seconds(0)')->toContain('new CarbonInterval(0, 0, 0, 0, 0, 0, 0, 0)')
            ->not->toContain('CarbonInterval::microsecond(0)')
            ->not->toContain('static $return = [true, false];');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Illuminate excerpt strings separate from fluent objects in AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/support', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/support/Str.php',
        "<?php\nclass Str\n{\n    public static function excerpt(\$matches, \$radius)\n    {\n"
        . "        \$start = ltrim(\$matches[1]);\n"
        . "        \$start = Str::of(mb_substr(\$start, max(mb_strlen(\$start, 'UTF-8') - \$radius, 0), \$radius, 'UTF-8'))->ltrim()->unless(\n"
        . "            fn (\$startWithRadius) => \$startWithRadius->exactly(\$start),\n        );\n"
        . "        \$end = rtrim(\$matches[3]);\n"
        . "        \$end = Str::of(mb_substr(\$end, 0, \$radius, 'UTF-8'))->rtrim()->unless(\n"
        . "            fn (\$endWithRadius) => \$endWithRadius->exactly(\$end),\n        );\n"
        . "        return \$start->append(\$matches[2], \$end)->toString();\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/illuminate-support-str.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-support-str.php');
        expect($source)
            ->toContain('$startText = ltrim(')
            ->toContain('mb_strlen($startText')
            ->toContain('exactly($startText)')
            ->toContain('$endText = rtrim(')
            ->toContain('mb_substr($endText')
            ->toContain('exactly($endText)')
            ->toContain('return $start->append(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Carbon dynamic unit property values type-stable for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Date.php',
        "<?php\ntrait Date\n{\n    public function get(\$operator)\n    {\n"
        . "                    \$value = \$operator === 'Of'\n"
        . "                        ? floor(1.5)\n                        : round(1.5);\n\n"
        . "                    \$result = \$result->subSecond();\n"
        . "                    \$result = \$result->addSecond();\n"
        . "                \$result = \$result->addDays(\$value - \$this->dayOfYear);\n"
        . "                \$result = \$result->addDays(\$value - \$this->dayOfWeek);\n"
        . "                \$result = \$result->addDays(\$value - \$this->dayOfWeekIso);\n"
        . "                return \$this->addDays(\$value - \$dayOfYear);\n"
        . "            \$boundMacro = @\$macro->bindTo(\$this, static::class) ?: @\$macro->bindTo(null, static::class);\n"
        . "            return \\call_user_func_array(\$boundMacro ?: \$macro, \$parameters);\n"
        . "                \$boundMacro = @Closure::bind(\$macro, null, static::class);\n"
        . "                \$\$name = self::monthToInt(\$value, \$name);\n"
        . "                \$result->\$name = \$value;\n"
        . "                } catch (UnknownUnitException) {\n"
        . "                    // default to macro\n"
        . "                }\n\n"
        . "            default:\n"
        . "                \$macro = \$this->getLocalMacro('get'.ucfirst(\$name));\n"
        . "                    return (int) \$value;\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-traits-date.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-date.php');
        expect($source)
            ->toContain('$unitValue = $operator')
            ->toContain('return (int) $unitValue;')
            ->toContain('$result->addUnit(\'second\', -1)')
            ->toContain('$result->addUnit(\'second\', 1)')
            ->toContain('$result->addUnit(\'day\', $value - $this->dayOfYear)')
            ->toContain('$result->addUnit(\'day\', $value - $this->dayOfWeek)')
            ->toContain('$result->addUnit(\'day\', $value - $this->dayOfWeekIso)')
            ->toContain('$this->addUnit(\'day\', $value - $dayOfYear)')
            ->toContain('AOT Carbon closure macro binding is not supported.')
            ->toContain('AOT Carbon static closure macro binding is not supported.')
            ->not->toContain('->bindTo(')
            ->not->toContain('Closure::bind(')
            ->not->toContain('$boundMacro')->toContain(
                '$normalizedValue = self::monthToInt($value, $name);',
            )->toContain('case \'year\': $year = $normalizedValue; break;')->toContain(
                'case \'second\': $second = $normalizedValue; break;',
            )
            ->not->toContain('$$name')
            ->not->toContain('return (int) $value;');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/nesbot/carbon/src/Carbon/Traits/Date.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands Carbon boundary magic units into declared AOT calls', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Boundaries.php',
        "<?php\ntrait Boundaries\n{\n    public function boundaries(\$weekStartsAt)\n    {\n"
        . "        \$quarter = \$this->startOfQuarter()->addMonths(2)->endOfMonth();\n"
        . "        \$start = \$this\n"
        . "            ->subDays(\n"
        . "                (static::DAYS_PER_WEEK + \$this->dayOfWeek - (WeekDay::int(\$weekStartsAt) ?? \$this->firstWeekDay)) %\n"
        . "                static::DAYS_PER_WEEK,\n"
        . "            )\n"
        . "            ->startOfDay();\n"
        . "        return \$this->addDays(1);\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-traits-boundaries.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-boundaries.php');
        expect($source)
            ->toContain('->addUnit(\'month\', 2)')
            ->toContain('->addUnit(\'day\', -(')
            ->toContain('->addUnit(\'day\', 1)')
            ->not->toContain('->addMonths(')
            ->not->toContain('->addDays(')
            ->not->toContain('->subDays(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the Carbon rounding parameter separate from range keys in AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Rounding.php',
        "<?php\ntrait Rounding\n{\n    public function roundUnit(string \$unit, array \$ranges)\n    {\n"
        . "        foreach (\$ranges as \$unit => [\$minimum, \$maximum]) {\n"
        . "            if (\$normalizedUnit === \$unit) {\n"
        . "                \$arguments = [\$this->\$unit, \$minimum];\n"
        . "                \$initialValue = \$this->\$unit;\n            }\n"
        . "                \$inc = (\$this->\$unit - \$minimum) * \$factor;\n"
        . "                \$changes[\$unit] = round(\n"
        . "                    \$minimum + (\$fraction ? \$fraction * \$function((\$this->\$unit - \$minimum) / \$fraction) : 0),\n"
        . "            );\n"
        . "                while (\$changes[\$unit] >= \$delta) {\n"
        . "                    \$changes[\$unit] -= \$delta;\n            }\n        }\n"
        . "        foreach (\$changes as \$unit => \$value) {\n"
        . "            \$result = \$result->\$unit(\$value);\n        }\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-traits-rounding.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-rounding.php');
        expect($source)
            ->toContain('foreach ($ranges as $rangeUnit =>')
            ->toContain('$this->$rangeUnit')
            ->toContain('$changes[$rangeUnit]')
            ->toContain('foreach ($changes as $changeUnit => $value)')
            ->toContain('$result->$changeUnit($value)')
            ->not->toContain('foreach ($ranges as $unit =>');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('uses declared Carbon comparison methods in the AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Comparison.php',
        "<?php\ntrait Comparison\n{\n"
        . "    public function current(\$unit) { return \$this->{'isSame'.ucfirst(\$unit)}('now'); }\n"
        . "    public function quarter(\$date) { return \$this->isSameYear(\$date); }\n"
        . "}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-traits-comparison.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-comparison.php');
        expect($source)
            ->toContain('$this->isSameUnit($unit, \'now\')')
            ->toContain('$this->isSameUnit(\'year\', $date)')
            ->not->toContain('$this->{\'isSame\'')
            ->not->toContain('$this->isSameYear(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('inlines Carbon validity semantics in the converter AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Converter.php',
        "<?php\ntrait Converter\n{\n"
        . "    public function iso() { if (!\$this->isValid()) { return null; } return CarbonInterval::day(); }\n"
        . "}\n",
    );
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Difference.php',
        "<?php\ntrait Difference { public function day() { return CarbonInterval::day(); } "
        . "public function hour() { return CarbonInterval::hour(); } }\n",
    );
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Creator.php',
        "<?php\ntrait Creator\n{\n    public static function createSafe(\$year, \$month, \$day, \$hour, \$minute, \$second)\n"
        . "    {\n        \$fields = static::getRangesByUnit();\n"
        . "        foreach (\$fields as \$field => \$range) {\n"
        . "            if (\$\$field !== null) { throw new InvalidDateException(\$field, \$\$field); }\n"
        . "        }\n    }\n}\n",
    );
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php',
        "<?php\nrequire PHP_VERSION < 8.2\n"
        . "    ? __DIR__.'/../../lazy/Carbon/ProtectedDatePeriod.php'\n"
        . "    : __DIR__.'/../../lazy/Carbon/UnprotectedDatePeriod.php';\n"
        . "class CarbonPeriod { public function day() { return \\Carbon\\CarbonInterval::day(); } "
        . 'public function month() { return CarbonInterval::month(); } '
        . "public function dynamic(\$dateClass, \$part, \$start, \$value) { "
        . "\$dateClass::make(\$part); "
        . "\$dateClass::make(static::addMissingParts(\$start ?? '', \$part)); "
        . "\$dateClass::now(); "
        . "\$dateClass::isStrictModeEnabled(); "
        . "\$dateClass::parse(\$value, \$this->timezoneSetting); "
        . "} }\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/carbon-traits-creator.php',
            '.typephp/build/carbon-traits-converter.php',
            '.typephp/build/carbon-traits-difference.php',
            '.typephp/build/carbon-period.php',
        ]);
        $creatorSource = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-creator.php');
        expect($creatorSource)
            ->toContain('\'year\' => $year')
            ->toContain('$fieldValues[$field]')
            ->not->toContain('$$field');
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-converter.php');
        expect($source)
            ->toContain('if ($this->year === 0)')
            ->toContain('new CarbonInterval(0, 0, 0, 1)')
            ->not->toContain('$this->isValid()');
        expect(file_get_contents($directory . '/.typephp/build/carbon-traits-difference.php'))
            ->toContain('new CarbonInterval(0, 0, 0, 1)');
        expect(file_get_contents($directory . '/.typephp/build/carbon-period.php'))
            ->toContain('new \Carbon\CarbonInterval(0, 0, 0, 1)')
            ->toContain('new CarbonInterval(0, 1)')
            ->not->toContain('ProtectedDatePeriod.php')
            ->not->toContain('UnprotectedDatePeriod.php')
            ->not->toContain('\Carbon\new CarbonInterval');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('fails explicitly for Carbon interval trait mixin result copying in AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Mixin.php',
        "<?php\ntrait Mixin\n{\n    public function copy(\$downContext, \$result)\n    {\n"
        . "                    \$downContext->copyProperties(\$result);\n"
        . "                    self::copyStep(\$downContext, \$result);\n"
        . "                    self::copyNegativeUnits(\$downContext, \$result);\n"
        . "    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-traits-mixin.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-mixin.php');
        expect($source)
            ->toContain('AOT Carbon interval trait mixin result copying is not supported.')
            ->not->toContain('self::copyStep(')
            ->not->toContain('self::copyNegativeUnits(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Carbon debug formatting dynamic in the Options AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Options.php',
        "<?php\ntrait Options\n{\n    protected function addExtraDebugInfos(array &\$infos): void\n    {\n"
        . "        if (\$this instanceof DateTimeInterface) {\n"
        . "            \$infos['date'] ??= \$this->format(CarbonInterface::MOCK_DATETIME_FORMAT);\n"
        . "        }\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-traits-options.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-options.php');
        expect($source)
            ->toContain('\call_user_func([$this, \'format\'], CarbonInterface::MOCK_DATETIME_FORMAT)')
            ->not->toContain('$this->format(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands Carbon localization variable variables in the AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/src/Carbon/Traits', 0777, true);
    $fixture = <<<'PHP'
        <?php
        trait Localization
        {
            public static function translate($from, $to, $mode)
            {
                $fromTranslations = [];
                $toTranslations = [];
                foreach (['from', 'to'] as $key) {
                    $language = $$key;
                    $translationKey = $key.'Translations';
                    $months = [];
                    $weekdays = [];
                        foreach (['months', 'weekdays'] as $variable) {
                            $list = [];
                            if ($list) {
                                foreach ($$variable as $index => &$name) {
                                    $name .= '|'.$list[$index];
                                }
                            }
                        }
                    }
                    $$translationKey = array_merge(
                        $months,
                        $weekdays,
                    );
                }

                // Make all dots optional
                return [$fromTranslations, $toTranslations];
            }
        }
        PHP;
    file_put_contents($directory . '/vendor/nesbot/carbon/src/Carbon/Traits/Localization.php', $fixture);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-traits-localization.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-traits-localization.php');
        expect($source)
            ->toContain('$language = $key === \'from\' ? $from : $to;')
            ->toContain('$months[$index] = $name.\'|\'.$list[$index];')
            ->toContain('$fromTranslations = $translatedWords;')
            ->toContain('$toTranslations = $translatedWords;')
            ->not->toContain('$$');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('flattens the pinned Carbon DatePeriod base class guard for AOT ordering', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/nesbot/carbon/lazy/Carbon', 0777, true);
    file_put_contents(
        $directory . '/vendor/nesbot/carbon/lazy/Carbon/UnprotectedDatePeriod.php',
        "<?php\nnamespace Carbon;\nuse DatePeriod;\n"
        . "if (!class_exists(DatePeriodBase::class, false)) {\n"
        . "    class DatePeriodBase extends DatePeriod\n"
        . "    {\n"
        . "    }\n"
        . "}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe(['.typephp/build/carbon-date-period-base.php']);
        $source = (string) file_get_contents($directory . '/.typephp/build/carbon-date-period-base.php');
        expect($source)->toContain('class DatePeriodBase extends DatePeriod')->not->toContain('if (!class_exists(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('initializes the Eloquent aggregate alias slot explicitly for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/database/Eloquent/Concerns', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/database/Eloquent/Concerns/QueriesRelationships.php',
        "<?php\ntrait QueriesRelationships\n{\n    public function withAggregate(\$relations)\n    {\n"
        . "        foreach (\$relations as \$name) {\n            unset(\$alias);\n"
        . "            \$alias ??= \$name;\n        }\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-eloquent-queries-relationships.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/illuminate-eloquent-queries-relationships.php'))
            ->toContain('$alias = null;')
            ->not->toContain('unset($alias)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands the CarbonInterval milliseconds magic factory for AOT', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/support', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/support/InteractsWithTime.php',
        "<?php\ntrait InteractsWithTime\n{\n    protected function runTimeForHumans(\$runTime)\n    {\n"
        . "        return CarbonInterval::milliseconds(\$runTime)->cascade()->forHumans(short: true);\n"
        . "    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateSwitchTerminalSources())->toBe([
            '.typephp/build/illuminate-support-interacts-with-time.php',
        ]);
        expect(file_get_contents($directory . '/.typephp/build/illuminate-support-interacts-with-time.php'))
            ->toContain('new CarbonInterval(0, 0, 0, 0, 0, 0, 0, $runTime * 1000)')
            ->not->toContain('CarbonInterval::milliseconds($runTime)');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/support/InteractsWithTime.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('defers the Illuminate Collection unique reference storage', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/collections', 0777, true);
    file_put_contents(
        $directory . '/vendor/illuminate/collections/Collection.php',
        "<?php\nclass Collection\n{\n    public function unique()\n    {\n"
        . "        \$exists = [];\n\n        return \$this->reject(function (\$item) use (&\$exists) {\n"
        . "            \$exists[] = \$item;\n        });\n    }\n}\n",
    );

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateRefCaptureSources())->toBe(['.typephp/build/illuminate-collections-collection.php']);
        expect(file_get_contents($directory . '/.typephp/build/illuminate-collections-collection.php'))
            ->not
            ->toContain('$exists = [];')
            ->toContain('use (&$exists)');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/collections/Collection.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('expands the LazyCollection sliding tap before yielding its window', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . '/vendor/illuminate/collections', 0777, true);
    file_put_contents($directory . '/vendor/illuminate/collections/LazyCollection.php', <<<'PHP'
        <?php
        class LazyCollection
        {
            public function sliding($step)
            {
                return new static(function () use ($step) {
                    $chunk = [];
                    while (true) {
                        if ($chunk === []) {
                            yield (new static($chunk))->tap(function () use (&$chunk, $step) {
                                $chunk = array_slice($chunk, $step, null, true);
                            });
                        }
                    }
                });
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateRefCaptureSources())->toBe([
            '.typephp/build/illuminate-collections-lazy-collection.php',
        ]);
        $source = (string) file_get_contents($directory . '/.typephp/build/illuminate-collections-lazy-collection.php');
        expect($source)
            ->toContain('$window = new static($chunk);')
            ->toContain('$chunk = array_slice($chunk, $step, null, true);')
            ->toContain('yield $window;')
            ->not->toContain('use (&$chunk, $step)');
        expect(file_get_contents($generator->generateProjectYml([])))
            ->toContain("\n  - vendor/illuminate/collections/LazyCollection.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('selects Symfony Redis 6 proxy trait declarations for the pinned extension', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $traits = $directory . '/vendor/symfony/cache/Traits';
    mkdir($traits, 0777, true);
    $source = <<<'PHP'
        <?php
        namespace Symfony\Component\Cache\Traits;

        if (version_compare(phpversion('redis'), '6.1.0-dev', '>=')) {
            trait Redis61ProxyTrait
            {
                public function modern(): \Redis|string|false {}
            }
        } else {
            trait Redis61ProxyTrait
            {
                public function legacy(): \Redis|string {}
            }
        }
        PHP;
    file_put_contents($traits . '/Redis61ProxyTrait.php', $source);
    file_put_contents($traits . '/RedisCluster61ProxyTrait.php', str_replace(
        ['Redis61ProxyTrait', "'>='"],
        ['RedisCluster61ProxyTrait', "'>'"],
        $source,
    ));

    try {
        $generator = new ProjectGenerator($directory);
        expect($generator->generateFlattenedSources())->toBe([
            '.typephp/build/symfony-cache-redis61-proxy-trait.php',
            '.typephp/build/symfony-cache-redis-cluster61-proxy-trait.php',
        ]);

        $aot = (string) file_get_contents($directory . '/.typephp/build/symfony-cache-redis61-proxy-trait.php');
        expect($aot)
            ->toContain('trait Redis61ProxyTrait')
            ->toContain('function modern()')
            ->not->toContain('function legacy()')
            ->not->toContain("phpversion('redis')");
        expect((string) file_get_contents($directory . '/.typephp/build/symfony-cache-redis-cluster61-proxy-trait.php'))
            ->toContain('trait RedisCluster61ProxyTrait')
            ->toContain('function modern()')
            ->not->toContain('function legacy()');

        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain('  - .typephp/build/symfony-cache-redis61-proxy-trait.php' . "\n")
            ->toContain("\n  - vendor/symfony/cache/Traits/Redis61ProxyTrait.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('splits the Illuminate lazy proxy initializer before assigning its self reference', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $reflection = $directory . '/vendor/illuminate/reflection';
    mkdir($reflection, 0777, true);
    file_put_contents($reflection . '/helpers.php', <<<'PHP'
        <?php
        if (! function_exists('proxy')) {
            function proxy($callback, $eager, $options)
            {
                $reflectionClass = new ReflectionClass('Example');
                $proxy = $reflectionClass->newLazyProxy(function () use ($callback, $eager, &$proxy) {
                    $instance = $callback($proxy, $eager);

                    return $instance;
                }, $options);
                return $proxy;
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateFlattenedSources();
        $aot = (string) file_get_contents($directory . '/.typephp/build/illuminate-reflection-helpers.php');
        expect($aot)
            ->toContain('$proxyInitializer = function () use ($callback, $eager, &$proxy) {')
            ->toContain('$proxy = $reflectionClass->newLazyProxy($proxyInitializer, $options);')
            ->not->toContain('$proxy = $reflectionClass->newLazyProxy(function ()');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Illuminate replacement arrays mutable through an AOT reference slot', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $support = $directory . '/vendor/illuminate/support';
    mkdir($support, 0777, true);
    file_put_contents($support . '/helpers.php', <<<'PHP'
        <?php
        if (! function_exists('preg_replace_array')) {
            function preg_replace_array($pattern, array $replacements, $subject): string
            {
                return preg_replace_callback($pattern, function () use (&$replacements) {
                    return array_shift($replacements);
                }, $subject);
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateFlattenedSources();
        $aot = (string) file_get_contents($directory . '/.typephp/build/illuminate-support-helpers.php');
        expect($aot)
            ->toContain('function () use ($replacements, &$remaining) {')
            ->toContain('$remaining = $replacements;')
            ->toContain('return array_shift($remaining);')
            ->not->toContain('use (&$replacements)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony dump branch keys type-stable in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $functions = $directory . '/vendor/symfony/var-dumper/Resources/functions';
    mkdir($functions, 0777, true);
    file_put_contents($functions . '/dump.php', <<<'PHP'
        <?php
        if (!function_exists('dump')) {
            function dump(mixed ...$vars): mixed
            {
                if (array_key_exists(0, $vars) && 1 === count($vars)) {
                    VarDumper::dump($vars[0]);
                    $k = 0;
                } else {
                    foreach ($vars as $k => $v) {
                        VarDumper::dump($v);
                    }
                }
                return $vars[$k];
            }
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateFlattenedSources();
        $aot = (string) file_get_contents($directory . '/.typephp/build/symfony-var-dumper-functions.php');
        expect($aot)->toContain('return $vars[0];')->not->toContain('$k = 0;')->not->toContain('return $vars[$k];');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps the PHPMailer header encoding selector string-typed in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/phpmailer/phpmailer/src';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/PHPMailer.php', <<<'PHP'
        <?php
        class PHPMailer {
            public $Debugoutput = 'echo';

            public function encodeHeader(string $str, string $position): string {
                $matchcount = 0;
                switch (strtolower($position)) {
                    /* @noinspection PhpMissingBreakStatementInspection */
                    case 'comment':
                        $matchcount = preg_match_all('/[()"]/', $str, $matches);
                    //fallthrough
                    case 'text':
                    default:
                        $matchcount += preg_match_all('/[\000-\010\013\014\016-\037\177-\377]/', $str, $matches);
                        break;
                }
                if ($matchcount > 0) {
                    $encoding = 'Q';
                } else {
                    $encoding = false;
                }
                return $encoding ?: 'plain';
            }

            public function edebug(string $str): void {
                switch ($this->Debugoutput) {
                    case 'error_log':
                        error_log($str);
                        break;
                    case 'echo':
                    default:
                        echo gmdate('Y-m-d H:i:s'),
                        "\n";
                }
            }

            public function encodeQ(string $position): string {
                $pattern = '';
                switch (strtolower($position)) {
                    /* @noinspection PhpMissingBreakStatementInspection */
                    case 'comment':
                        $pattern = '\(\)"';
                    /* Intentional fall through */
                    case 'text':
                    default:
                        //RFC 2047 section 5.1
                        //Replace every high ascii, control, =, ? and _ characters
                        $pattern = '\000-\011\013\014\016-\037\075\077\137\177-\377' . $pattern;
                        break;
                }
                return $pattern;
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/phpmailer.php');
        expect($generated)
            ->toContain('$encoding = \'\';')
            ->not->toContain('$encoding = false;')
            ->not->toContain('//fallthrough')->toContain(
                "\$matchcount = preg_match_all('/[()\"]/', \$str, \$matches);\n"
                . "                \$matchcount += preg_match_all('/[\\000-\\010\\013\\014\\016-\\037\\177-\\377]/', \$str, \$matches);\n"
                . '                break;',
            )->toContain(
                "case 'comment':\n"
                . "                \$pattern = '\\000-\\011\\013\\014\\016-\\037\\075\\077\\137\\177-\\377\\(\\)\"';\n"
                . '                break;',
            )->toContain("                \"\\n\";\n                break;\n        }");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('avoids Ramsey private property shadowing in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/ramsey/collection/src';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/DoubleEndedQueue.php', <<<'PHP'
        <?php
        class DoubleEndedQueue extends Queue {
            public function __construct(private readonly string $queueType, array $data = [])
            {
                parent::__construct($this->queueType, $data);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/ramsey-double-ended-queue.php');
        expect($generated)
            ->toContain('public function __construct(string $queueType')
            ->toContain('parent::__construct($queueType, $data);')
            ->not->toContain('private readonly string $queueType');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Ramsey PHP time string inputs separate from integer objects in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/ramsey/uuid/src/Converter/Time';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/PhpTimeConverter.php', <<<'PHP'
        <?php
        class PhpTimeConverter {
            public function calculateTime(string $seconds, string $microseconds) {
                $seconds = new IntegerObject($seconds);
                $microseconds = new IntegerObject($microseconds);
                return [$seconds->toString(), $microseconds->toString()];
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/ramsey-php-time-converter.php');
        expect($generated)
            ->toContain('$secondsValue = new IntegerObject($seconds);')
            ->toContain('$microsecondsValue->toString()')
            ->not->toContain('$seconds = new IntegerObject($seconds);');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps SaiPackage empty-directory path segments separate in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/saithink/saipackage/src/service';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Filesystem.php', <<<'PHP'
        <?php
        class Filesystem {
            public static function delEmptyDir(string $path): void {
                $path = str_replace(base_path() . DIRECTORY_SEPARATOR, '', rtrim($path, DIRECTORY_SEPARATOR));
                $path = array_filter(explode(DIRECTORY_SEPARATOR, $path));
                for ($i = count($path) - 1; $i >= 0; $i--) {
                    $dirPath = base_path() . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $path);
                    unset($path[$i]);
                }
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/saipackage-filesystem.php');
        expect($generated)
            ->toContain('$pathParts = array_filter(')
            ->toContain('unset($pathParts[$i]);')
            ->not->toContain('$path = array_filter(');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps SaiPackage terminal output values type-stable in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/saithink/saipackage/src/service';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Terminal.php', <<<'PHP'
        <?php
        class Terminal {
            public function output(string $data): string {
                $data = [
                    'data'   => $data,
                    'uuid'   => $this->uuid,
                    'extend' => $this->extend,
                    'key'    => $this->commandKey,
                ];
                $data = json_encode($data, JSON_UNESCAPED_UNICODE);
                if ($data === false) {
                    $data = json_encode(['error' => 'JSON encode error'], JSON_UNESCAPED_UNICODE);
                }
                return $data;
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/saipackage-terminal.php');
        expect($generated)
            ->toContain('$payload = [')
            ->toContain('$encoded = json_encode($payload')
            ->toContain('return \'{"error":"JSON encode error"}\';')
            ->not->toContain('$data = [');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps SaiPackage version strings separate from their parts in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/saithink/saipackage/src/service';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Version.php', <<<'PHP'
        <?php
        class Version {
            public static function compare(string $v1, bool|string $v2): bool {
                $v1 = explode('.', $v1);
                $v2 = explode('.', $v2);
                for ($i = 0; $i < count($v1); $i++) {
                    if (!isset($v2[$i])) break;
                    if ($v1[$i] < $v2[$i]) return true;
                }
                return count($v1) !== count($v2);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/saipackage-version.php');
        expect($generated)->toContain('$v1Parts = explode')->toContain('$v2Parts[$i]')->not->toContain('$v1 = explode');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony command names separate from aliases in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/console/Attribute';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/AsCommand.php', <<<'PHP'
        <?php
        class AsCommand {
            public function __construct(public string $name, array $aliases = [], bool $hidden = false) {
                $name = explode('|', $name);
                $name = array_merge($name, $aliases);
                if ($hidden && '' !== $name[0]) array_unshift($name, '');
                $this->name = implode('|', $name);
            }
        }
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = (string) file_get_contents($directory . '/.typephp/build/symfony-console-as-command.php');
        expect($generated)
            ->toContain('$commandNames = explode')
            ->toContain('array_unshift($commandNames,')
            ->not->toContain('$name = explode');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony output chunk widths separate from line indexes in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-output-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/console/Formatter';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/OutputFormatter.php', <<<'PHP'
        <?php
                    $prefix = Helper::substr($lines[0], 0, $i = $width - $currentLineLength)."\n";
                    $text = Helper::substr($lines[0], $i);
        foreach ($lines as $i => $line) {
        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generated = $generator->generateSwitchTerminalSources();
        $target = $directory . '/.typephp/build/symfony-console-output-formatter.php';

        expect($generated)
            ->toContain('.typephp/build/symfony-console-output-formatter.php')
            ->and(file_get_contents($target))
            ->toContain('$chunkWidth = $width - $currentLineLength')
            ->toContain('Helper::substr($lines[0], $chunkWidth)')
            ->not->toContain('$i = $width - $currentLineLength');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('makes the Symfony progress finished indicator explicit in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-progress-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/console/Helper';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/ProgressIndicator.php', <<<'PHP'
        <?php
            public function finish(string $message/* , ?string $finishedIndicator = null */): void
            {
                $finishedIndicator = 1 < \func_num_args() ? func_get_arg(1) : null;
                if (null !== $finishedIndicator && !\is_string($finishedIndicator)) {
                    throw new \TypeError(\sprintf('Argument 2 passed to "%s()" must be of the type string or null, "%s" given.', __METHOD__, get_debug_type($finishedIndicator)));
                }
            }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-console-progress-indicator.php');

        expect($content)
            ->toContain('finish(string $message, ?string $finishedIndicator = null)')
            ->not->toContain('func_get_arg')
            ->not->toContain('func_num_args');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps Symfony question input sentinels separate from string answers in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-question-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/console/Helper';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/QuestionHelper.php', implode("\n", [
        '<?php',
        '            $ret = false;',
        '                    $ret = $question->isTrimmable() ? trim($hiddenResponse) : $hiddenResponse;',
        '            if (false === $ret) {',
        '                $ret = $this->readInput($inputStream, $question);',
        '                if (false === $ret) {',
        '                if ($question->isTrimmable()) {',
        '        $ret = \strlen($ret) > 0 ? $ret : $question->getDefault();',
        '',
        '        if ($normalizer = $question->getNormalizer()) {',
        '            return $normalizer($ret);',
        '        }',
        '',
        '        return $ret;',
    ]));

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-console-question-helper.php');

        expect($content)
            ->toContain('$hasAnswer = false;')
            ->toContain('$inputAnswer = $this->readInput')
            ->toContain('$answer = \strlen($ret) > 0')
            ->not->toContain('$ret = false;')
            ->not->toContain('if (false === $ret)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('avoids Symfony style private output property shadowing in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-style-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/console/Style';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/SymfonyStyle.php', implode("\n", [
        '<?php',
        '    private TrimmedBufferOutput $bufferedOutput;',
        '    public function __construct(',
        '        private InputInterface $input,',
        '        private OutputInterface $output,',
        '    ) {',
        '        $this->bufferedOutput = new TrimmedBufferOutput($output);',
        '        parent::__construct($output);',
        '        $this->output->write("ready");',
        '    }',
        '        $question = new Question($question, $default);',
        '        $question->setValidator($validator);',
        '',
        '        return $this->askQuestion($question);',
        '        $question = new Question($question);',
        '',
        '        $question->setHidden(true);',
        '        $question->setValidator($validator);',
        '',
        '        return $this->askQuestion($question);',
    ]));

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-console-symfony-style.php');

        expect($content)
            ->toContain('private OutputInterface $styleOutput;')
            ->toContain('OutputInterface $output,')
            ->toContain('$this->styleOutput = $output;')
            ->toContain('$this->styleOutput->write')
            ->toContain('$questionObject = new Question($question')
            ->toContain('$this->askQuestion($questionObject)')
            ->not->toContain('private OutputInterface $output,');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('avoids a reference to an unkeyed Symfony listener append slot in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-dispatcher-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/event-dispatcher';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/EventDispatcher.php', <<<'PHP'
        <?php
                        $closure = &$this->optimized[$eventName][];
                        if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {
                            $closure = static function (...$args) use (&$listener, &$closure) {
                                if ($listener[0] instanceof \Closure) {
                                    $listener[0] = $listener[0]();
                                    $listener[1] ??= '__invoke';
                                }
                                ($closure = $listener(...))(...$args);
                            };
                        } else {
                            $closure = $listener instanceof WrappedListener ? $listener : $listener(...);
                        }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-event-dispatcher.php');

        expect($content)
            ->toContain('$this->optimized[$eventName][] = $closure;')
            ->toContain('$listener(...$args);')
            ->not->toContain('$closure = &$this->optimized')
            ->not->toContain('use (&$listener, &$closure)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('preserves Symfony lazy listener arrays in its AOT interface copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-dispatcher-interface-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/event-dispatcher';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/EventDispatcherInterface.php', implode("\n", [
        '<?php',
        'public function addListener(string $eventName, callable $listener, int $priority = 0): void;',
        'public function removeListener(string $eventName, callable $listener): void;',
        'public function getListenerPriority(string $eventName, callable $listener): ?int;',
    ]));

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-event-dispatcher-interface.php');

        expect(substr_count((string) $content, 'callable|array $listener'))->toBe(3);
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('separates Symfony accept header attributes from its promoted string value in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-accept-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/AcceptHeaderItem.php', <<<'PHP'
        <?php
                foreach ($attributes as $name => $value) {
                    $this->setAttribute($name, $value);
                }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-foundation-accept-header-item.php');

        expect($content)->toContain('as $name => $attributeValue')->toContain('setAttribute($name, $attributeValue)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('separates Symfony query input from its parsed array result in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-query-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/HeaderUtils.php', <<<'PHP'
        <?php
                $query = [];

                foreach ($q as $k => $v) {
                    if (false !== $i = strpos($k, '_')) {
                        $query[substr_replace($k, hex2bin(substr($k, 0, $i)).'[', 0, 1 + $i)] = $v;
                    } else {
                        $query[hex2bin($k)] = $v;
                    }
                }

                return $query;
            }

            private static function groupParts(array $matches, string $separators): array
            {
                foreach ($partMatches as $matches) {
                    if ('' === $separators && '' !== $unquoted = self::unquote($matches[0][0])) {
                        $parts[] = $unquoted;
                    } elseif ($groupedParts = self::groupParts($matches, $separators, false)) {
                    }
                }
            }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-foundation-header-utils.php');

        expect($content)
            ->toContain('$parsedQuery = [];')
            ->toContain('as $encodedKey => $v')
            ->toContain('$parsedQuery[substr_replace($encodedKey')
            ->toContain('$parsedQuery[hex2bin($encodedKey)]')
            ->toContain('return $parsedQuery;')
            ->toContain('as $groupMatches')
            ->toContain('self::groupParts($groupMatches');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('makes Symfony IP anonymization byte counts explicit in its AOT copy', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-symfony-ip-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/symfony/http-foundation';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/IpUtils.php', <<<'PHP'
        <?php
            public static function anonymize(string $ip/* , int $v4Bytes = 1, int $v6Bytes = 8 */): string
            {
                $v4Bytes = 1 < \func_num_args() ? func_get_arg(1) : 1;
                $v6Bytes = 2 < \func_num_args() ? func_get_arg(2) : 8;

            }
        PHP);

    try {
        $generator = new ProjectGenerator($directory);
        $generator->generateSwitchTerminalSources();
        $content = file_get_contents($directory . '/.typephp/build/symfony-http-foundation-ip-utils.php');

        expect($content)
            ->toContain('anonymize(string $ip, int $v4Bytes = 1, int $v6Bytes = 8)')
            ->not->toContain('func_get_arg')
            ->not->toContain('func_num_args');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('keeps runtime-only mail and callable streams dynamic while stabilizing native scalar expressions', function (): void {
    expect(ProjectGenerator::REGISTERED_DYNAMIC_SOURCES)
        ->toContain('vendor/guzzlehttp/psr7/src/FnStream.php')
        ->toContain('vendor/monolog/monolog/src/Monolog/Handler/Slack/SlackRecord.php')
        ->toContain('vendor/phpmailer/phpmailer/src/POP3.php')
        ->toContain('vendor/phpmailer/phpmailer/src/SMTP.php')
        ->toContain('vendor/symfony/http-kernel/EventListener/DebugHandlersListener.php')
        ->toContain('vendor/symfony/polyfill-mbstring')
        ->toContain('vendor/symfony/polyfill-php83')
        ->toContain('vendor/symfony/translation/Command')
        ->toContain('vendor/cakephp')
        ->toContain('vendor/league/container')
        ->toContain('vendor/robmorgan/phinx');

    $directory = sys_get_temp_dir() . '/typephp-native-scalars-' . bin2hex(random_bytes(4));
    $fixtures = [
        'app/process/Monitor.php' => '<?php $this->ppid = function_exists(\'posix_getppid\') ? posix_getppid() : 0;',
        'vendor/godruoyi/php-snowflake/src/Snowflake.php' => '<?php return floor(microtime(true) * 1000) | 0;',
        'vendor/godruoyi/php-snowflake/src/Sonyflake.php' => <<<'PHP'
            <?php
            $elapsedTime = floor(($this->getCurrentMillisecond() - $millisecond) / 10) | 0;
            return floor(($this->getCurrentMillisecond() - $this->getStartTimeStamp()) / 10) | 0;
            PHP,
        'vendor/guzzlehttp/guzzle/src/Handler/CurlMultiHandler.php' => <<<'PHP'
            <?php
                    static $options = null;

                    if ($options !== null) {
                        return $options;
                    }

                    $options = [];
            PHP,
        'vendor/symfony/console/Helper/QuestionHelper.php' => '<?php $i = mb_strlen($fullChoice, $encoding);',
        'vendor/symfony/console/Helper/SymfonyQuestionHelper.php' => <<<'PHP'
            <?php
            class SymfonyQuestionHelper extends QuestionHelper
            {
                public function writePrompt(mixed $default): void
                {
                    switch (true) {
                        default:
                            $text = \sprintf(' <info>%s</info> [<comment>%s</comment>]:', $text, OutputFormatter::escape($default));
                    }
                }
            }
            PHP,
        'vendor/symfony/console/Output/AnsiColorMode.php' => '<?php return round($b / 255) << 2 | (round($g / 255) << 1) | round($r / 255);',
        'vendor/symfony/console/Output/ConsoleSectionOutput.php' => <<<'PHP'
            <?php
            class ConsoleSectionOutput extends StreamOutput
            {
                public function __construct(
                    $stream,
                    array &$sections,
                    int $verbosity,
                ) {
                    $this->sections = &$sections;
                }

                public function clear(): void
                {
                    $this->lines -= (int) ceil($this->getDisplayLength($lastLine) / $width) ?: 1;
                }
            }
            PHP,
        'vendor/symfony/http-foundation/BinaryFileResponse.php' => <<<'PHP'
            <?php
            $this->maxlen = $end < $fileSize ? $end - $start + 1 : -1;
            $read = $length > $this->chunkSize || 0 > $length ? $this->chunkSize : $length;
            PHP,
        'vendor/symfony/http-foundation/Session/Storage/Handler/SessionHandlerFactory.php' => <<<'PHP'
            <?php
            class SessionHandlerFactory
            {
                public function create(array $params, object $config, array $options): object
                {
                    switch (true) {
                        case true:
                            $connection = DriverManager::getConnection($params, $config)->getNativeConnection();
                            // no break;
                        default:
                            return new PdoSessionHandler($connection, $options);
                    }
                }
            }
            PHP,
        'vendor/symfony/mime/Header/AbstractHeader.php' => <<<'PHP'
            <?php
            class AbstractHeader
            {
                public function encode(string $token): string
                {
                            $firstChar = substr($token, 0, 1);
                            switch ($firstChar) {
                                case ' ':
                                case "\t":
                                    $value .= $firstChar;
                                    $token = substr($token, 1);
                            }
                    return $token;
                }
            }
            PHP,
        'vendor/symfony/http-kernel/Log/Logger.php' => <<<'PHP'
            <?php
            class Logger
            {
                public function record(string $level): void
                {
                    switch ($level) {
                        case 'error':
                            ++$this->errorCount[$key];
                    }
                }
            }
            PHP,
        'vendor/symfony/polyfill-php80/Php80.php' => '<?php switch (preg_last_error()) {',
        'vendor/symfony/translation/PseudoLocalizationTranslator.php' => '<?php return mb_strlen($s, $encoding);',
    ];

    foreach ($fixtures as $path => $content) {
        $fixtureDirectory = dirname($directory . '/' . $path);
        if (!is_dir($fixtureDirectory)) {
            mkdir($fixtureDirectory, 0777, true);
        }
        file_put_contents($directory . '/' . $path, $content);
    }

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();

        expect(file_get_contents($directory . '/.typephp/build/app-process-monitor.php'))
            ->toContain('? (int) posix_getppid() : 0;');
        expect(file_get_contents($directory . '/.typephp/build/godruoyi-snowflake.php'))
            ->toContain('return (int) floor(microtime(true) * 1000);');
        expect(file_get_contents($directory . '/.typephp/build/godruoyi-sonyflake.php'))
            ->toContain('$elapsedTime = (int) floor(')
            ->toContain('return (int) floor(')
            ->not->toContain('| 0');
        expect(file_get_contents($directory . '/.typephp/build/guzzle-curl-multi-handler.php'))
            ->not
            ->toContain('static $options')
            ->toContain('$options = [];');
        expect(file_get_contents($directory . '/.typephp/build/symfony-console-question-helper.php'))
            ->toContain('(int) mb_strlen($fullChoice, $encoding)');
        expect(file_get_contents($directory . '/.typephp/build/symfony-console-symfony-question-helper.php'))
            ->toContain("OutputFormatter::escape(\$default));\n                break;");
        expect(file_get_contents($directory . '/.typephp/build/symfony-console-ansi-color-mode.php'))
            ->toContain('(int) round($b / 255)');
        expect(file_get_contents($directory . '/.typephp/build/symfony-console-section-output.php'))
            ->toContain('$previousLineCount = max(1, (int) ceil(')
            ->toContain('mixed &$sections');
        expect(file_get_contents($directory . '/.typephp/build/symfony-http-foundation-binary-file-response.php'))
            ->toContain('? (int) ($end - $start + 1) : -1;')
            ->toContain('$length > (int) $this->chunkSize');
        expect(file_get_contents($directory . '/.typephp/build/symfony-session-handler-factory.php'))
            ->toContain("getNativeConnection();\n\n                return new PdoSessionHandler");
        expect(file_get_contents($directory . '/.typephp/build/symfony-mime-abstract-header.php'))
            ->toContain("\$token = substr(\$token, 1);\n                        break;");
        expect(file_get_contents($directory . '/.typephp/build/symfony-http-kernel-logger.php'))
            ->toContain("++\$this->errorCount[\$key];\n                break;");
        expect(file_get_contents($directory . '/.typephp/build/symfony-php80.php'))
            ->toContain('switch ((int) preg_last_error())');
        expect(file_get_contents($directory . '/.typephp/build/symfony-pseudo-localization-translator.php'))
            ->toContain('(int) mb_strlen($s, $encoding)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('materializes inherited Eloquent relation methods required by AOT trait registration', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-eloquent-relation-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/illuminate/database/Eloquent/Relations';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents(
        $sourceDirectory . '/HasOne.php',
        "<?php\nclass HasOne extends HasOneOrMany implements SupportsPartialRelations\n{\n}\n",
    );
    file_put_contents(
        $sourceDirectory . '/MorphOne.php',
        "<?php\nclass MorphOne extends MorphOneOrMany implements SupportsPartialRelations\n{\n}\n",
    );

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();

        foreach (['illuminate-eloquent-has-one.php', 'illuminate-eloquent-morph-one.php'] as $file) {
            expect(file_get_contents($directory . '/.typephp/build/' . $file))
                ->toContain('public function getParentKey()')
                ->toContain('return parent::getParentKey();');
        }
    } finally {
        removeTypephpTestDirectory($directory);
    }
});

it('passes explicit path strings across Webman config internal function boundaries', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-webman-config-' . bin2hex(random_bytes(4));
    $sourceDirectory = $directory . '/vendor/workerman/webman-framework/src';
    mkdir($sourceDirectory, 0777, true);
    file_put_contents($sourceDirectory . '/Config.php', <<<'PHP'
        <?php
        if (is_dir($file) || $file->getExtension() != 'php') {
            continue;
        }
        $relativePath = substr($file, 0, -4);
        $config = include $file;
        PHP);

    try {
        new ProjectGenerator($directory)->generateSwitchTerminalSources();
        $generated = file_get_contents($directory . '/.typephp/build/webman-config.php');

        expect($generated)
            ->toContain('if ($file->isDir() ||')
            ->toContain('substr((string) $file, 0, -4)')
            ->toContain('$config = include (string) $file;')
            ->not->toContain('is_dir($file)');
    } finally {
        removeTypephpTestDirectory($directory);
    }
});
