<?php

declare(strict_types=1);

use Tinywan\Typephp\Compiler\ProjectGenerator;

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
        expect(file_exists($mainPath))->toBeTrue();

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

        // worker_start 中的 support/bootstrap.php require 改为 is_file 守卫
        // （便携产物不携带 support/ 目录，缺失时不能致命）
        expect($flattened)
            ->toContain('$bootstrap = base_path(')
            ->toContain('if (is_file($bootstrap)) {')
            ->toContain('require_once $bootstrap;');
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
            ->toContain("\n  - vendor/workerman/webman-framework/src/support/helpers.php\n");
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
            ->toContain("'FastRoute\\\\RouteParser\\\\Std'");
        expect(str_contains($flattened, 'function_exists'))->toBeFalse();

        // yml 中注入平铺 fast-route 源并忽略 vendor 原版 functions.php
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain(
                "sources:\n  - main.php\n  - .typephp/build/helpers.php\n  - .typephp/build/fast-route-functions.php\n",
            )
            ->toContain("\n  - vendor/nikic/fast-route/src/functions.php\n");
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

    try {
        $generator = new ProjectGenerator($directory);
        $generated = $generator->generateNullableStaticSources();

        expect($generated)->toBe(['.typephp/build/coroutine-context.php', '.typephp/build/coroutine-wait-group.php']);

        // 未初始化标量静态属性补成可空 + 默认 null，??= 守卫主体保持原样
        $context = (string) file_get_contents($directory . '/.typephp/build/coroutine-context.php');
        expect($context)
            ->toContain('protected static ?string $driver = null;')
            ->toContain('static::$driver ??= match (Worker::$eventLoopClass)');
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
        . "        set_error_handler(static function (int \$code, string \$msg): bool {\n"
        . "            return true;\n        });\n        restore_error_handler();\n    }\n}\n",
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
        expect(str_contains($workerPatched, 'array_walk($workers, static fn (Worker $worker) => $worker->stop(false));'))->toBeFalse();
        expect(str_contains($workerPatched, 'pcntl_signal($signal, static::signalHandler(...), false);'))->toBeFalse();

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
        expect($tcpPatched)->toContain('set_error_handler(static function (int $code, string $msg, ...$__err): bool {');

        $asyncPatched = (string) file_get_contents($directory . '/.typephp/build/workerman-async-tcp-connection.php');
        expect($asyncPatched)->toContain('set_error_handler(fn(...$__err) => false);');

        $filePatched = (string) file_get_contents($directory . '/.typephp/build/webman-file.php');
        expect($filePatched)->toContain('set_error_handler(function ($type, $msg, ...$__err) use (&$error) {');

        // yml 注入全部补丁源，并忽略 vendor 原版文件
        $yml = (string) file_get_contents($generator->generateProjectYml([]));
        expect($yml)
            ->toContain('  - .typephp/build/workerman-worker.php' . "\n")
            ->toContain('  - .typephp/build/workerman-select.php' . "\n")
            ->toContain("\n  - vendor/workerman/workerman/src/Worker.php\n")
            ->toContain("\n  - vendor/workerman/workerman/src/Events/Select.php\n")
            ->toContain("\n  - vendor/workerman/webman-framework/src/File.php\n");
    } finally {
        removeTypephpTestDirectory($directory);
    }
});
