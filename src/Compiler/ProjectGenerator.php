<?php

/**
 * @desc TypePHP AOT 编译配置与项目入口生成器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/05
 */
declare(strict_types=1);

namespace Tinywan\Typephp\Compiler;

// @mago-ignore lint:cyclomatic-complexity -- The explicit compatibility matrix is intentionally kept together for review.
// @mago-ignore lint:kan-defect -- The tokenizer-based helpers flattener is an inherently control-flow dense state machine.
// @mago-ignore lint:too-many-methods -- Generation, flattening, and static-patching transformations belong to one coherent pipeline.
class ProjectGenerator
{
    /**
     * 需要平铺的守卫源文件映射：vendor 原文件 => AOT 专用平铺文件（相对项目根目录）。
     * 新版 webman-framework 的 helpers.php 与 fast-route 的 functions.php 顶层包含
     * `if (!function_exists(...))` / `if (!defined(...))` 守卫，TypePHP 编译器只接受
     * 顶层的 Class/Function/Use 等声明，遇到 Stmt_If 会报 Fatal error 或静默跳过，
     * 导致 base_path()/config()/FastRoute\simpleDispatcher() 等全局函数缺失。
     */
    public const GUARDED_SOURCES = [
        'vendor/workerman/webman-framework/src/support/helpers.php' => '.typephp/build/helpers.php',
        'vendor/nikic/fast-route/src/functions.php' => '.typephp/build/fast-route-functions.php',
    ];

    /**
     * 平铺后的 webman helpers.php 位置（GUARDED_SOURCES 的快捷引用）
     */
    public const HELPERS_TARGET = '.typephp/build/helpers.php';

    /**
     * 需要静态属性初始化补丁的源文件映射：vendor 原文件 => AOT 专用补丁文件。
     * TypePHP 编译产物中，未初始化的标量类型静态属性读取时返回零值（string => ''），
     * 而非 PHP 语义下的“未初始化”，导致 `static::$driver ??= match(...)` 永不赋值，
     * Workerman\Coroutine\Context::destroy() 随即触发
     * `Invalid callback ::destroy` 崩溃循环。补丁把这些属性改为可空并显式默认 null，
     * 使 ??= 守卫在编译产物中恢复语义。
     */
    public const NULLABLE_STATIC_SOURCES = [
        'vendor/workerman/coroutine/src/Context.php' => '.typephp/build/coroutine-context.php',
        'vendor/workerman/coroutine/src/WaitGroup.php' => '.typephp/build/coroutine-wait-group.php',
        'vendor/workerman/coroutine/src/Barrier.php' => '.typephp/build/coroutine-barrier.php',
    ];

    /**
     * 需要可变参数闭包补丁的源文件映射：vendor 原文件 => AOT 专用补丁文件。
     * TypePHP 编译产物对用户态闭包调用强制精确参数个数，而 PHP 语义允许调用时
     * 多传参数（多余参数被忽略）。set_error_handler 固定以 4 个参数调用处理器、
     * pcntl_signal 固定以 1 个参数调用处理器，Workerman 大量使用零参/两参闭包
     * 抑制警告（如 acceptTcpConnection 中的 `static fn (): bool => true`），
     * 编译后每次 accept 都会抛 `expects exactly 0 arguments, 4 given`
     * ArgumentCountError，worker 崩溃循环。同样，优雅停机/重载路径上
     * array_walk 以 2 参(value,key) 调用 1 参闭包、master 进程 pcntl_signal
     * 以 2 参(signo,siginfo) 调用 signalHandler，也会在 Ctrl+C/stop 时崩溃。
     * 补丁把这些闭包改为可变参数形态。
     */
    public const VARIADIC_HANDLER_SOURCES = [
        'vendor/workerman/workerman/src/Worker.php' => '.typephp/build/workerman-worker.php',
        'vendor/workerman/workerman/src/Connection/TcpConnection.php' => '.typephp/build/workerman-tcp-connection.php',
        'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php' => '.typephp/build/workerman-async-tcp-connection.php',
        'vendor/workerman/workerman/src/Events/Select.php' => '.typephp/build/workerman-select.php',
        'vendor/workerman/webman-framework/src/File.php' => '.typephp/build/webman-file.php',
    ];

    /**
     * 可变参数闭包补丁的字面替换规则：源文件 => [搜索串 => 替换串]。
     * 搜索串为 vendor 源码中的精确字面量；未匹配时静默跳过（版本差异容忍）。
     */
    protected const VARIADIC_HANDLER_REPLACEMENTS = [
        'vendor/workerman/workerman/src/Worker.php' => [
            'set_error_handler(static fn (): bool => true);' => 'set_error_handler(static fn (...$__err): bool => true);',
            'set_error_handler(function ($code, $msg) {' => 'set_error_handler(function ($code, $msg, ...$__err) {',
            // 优雅停机/信号路径：array_walk 实传 2 参(value,key)、pcntl_signal 实传 2 参(signo,siginfo)，
            // 补丁成可变参数避免停机/重载时抛 ArgumentCountError。
            'array_walk($workers, static fn (Worker $worker) => $worker->stop(false));' => 'array_walk($workers, static fn (Worker $worker, ...$__walk) => $worker->stop(false));',
            'array_walk($workerPidArray, static fn ($pid) => posix_kill($pid, $sig));' => 'array_walk($workerPidArray, static fn ($pid, ...$__walk) => posix_kill($pid, $sig));',
            'pcntl_signal($signal, static::signalHandler(...), false);' => 'pcntl_signal($signal, static fn (...$__sig) => static::signalHandler($__sig[0]), false);',
        ],
        'vendor/workerman/workerman/src/Connection/TcpConnection.php' => [
            'set_error_handler(static function (int $code, string $msg): bool {' => 'set_error_handler(static function (int $code, string $msg, ...$__err): bool {',
        ],
        'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php' => [
            'set_error_handler(fn() => false);' => 'set_error_handler(fn(...$__err) => false);',
        ],
        'vendor/workerman/workerman/src/Events/Select.php' => [
            'pcntl_signal($signal, fn () => $this->safeCall($this->signalEvents[$signal], [$signal]));' => 'pcntl_signal($signal, fn (...$__sig) => $this->safeCall($this->signalEvents[$signal], [$signal]));',
        ],
        'vendor/workerman/webman-framework/src/File.php' => [
            'set_error_handler(function ($type, $msg) use (&$error) {' => 'set_error_handler(function ($type, $msg, ...$__err) use (&$error) {',
        ],
    ];

    protected string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    /**
     * 生成或刷新 main.php AOT 入口
     */
    public function generateMain(?string $targetFile = null, bool $force = false): string
    {
        $targetFile = $targetFile ?: $this->basePath . DIRECTORY_SEPARATOR . 'main.php';
        $stubPath = dirname(__DIR__) . '/Stubs/main.php.stub';
        $content = file_get_contents($stubPath);
        if ($content === false) {
            throw new \RuntimeException('Unable to read the TypePHP main.php stub.');
        }
        // 彻底去除任何可能的 UTF-8 BOM 头 (EF BB BF)
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (!file_exists($targetFile)) {
            file_put_contents($targetFile, $content);
        } elseif ($force || str_contains((string) file_get_contents($targetFile), '$helpersFile = BASE_PATH')) {
            // 当明确指定 force 或检测到旧版废弃动态加载逻辑时，先备份再更新
            $backupFile = $targetFile . '.bak';
            copy($targetFile, $backupFile);
            file_put_contents($targetFile, $content);
        }
        return $targetFile;
    }

    /**
     * 为所有守卫源文件生成 AOT 专用平铺版本
     *
     * 读取项目实际安装的守卫文件（webman helpers.php、fast-route functions.php 等），
     * 剥离顶层 if 守卫后写入打包工作区。旧版（纯函数声明）文件会被原样保留；
     * 新版（function_exists / BASE_PATH 守卫）文件会被平铺成顶层函数声明，保证任何
     * Webman 版本均可编译。返回生成的平铺文件相对路径列表（不含未安装的源）。
     *
     * @return list<string>
     */
    public function generateFlattenedSources(): array
    {
        $generated = [];
        foreach (self::GUARDED_SOURCES as $sourceRel => $targetRel) {
            $sourceFile = $this->basePath . '/' . $sourceRel;
            if (!is_file($sourceFile)) {
                continue;
            }
            $content = file_get_contents($sourceFile);
            if ($content === false) {
                throw new \RuntimeException('Unable to read the guarded source file: ' . $sourceFile);
            }

            $targetFile = $this->basePath . '/' . $targetRel;
            $directory = dirname($targetFile);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the TypePHP build directory: ' . $directory);
            }
            if (file_put_contents($targetFile, $this->flattenGuardedSource($content)) === false) {
                throw new \RuntimeException('Unable to write the AOT flattened source: ' . $targetFile);
            }
            $generated[] = $targetRel;
        }
        return $generated;
    }

    /**
     * 为静态属性初始化补丁源文件生成 AOT 专用版本
     *
     * 读取项目实际安装的 workerman/coroutine 源文件（Context.php、WaitGroup.php、
     * Barrier.php），把未初始化的标量静态属性补成可空 + 默认 null 后写入打包工作区。
     * 旧版本文件若无此类声明则原样复制，保证任何 workerman 版本均可编译。
     * 返回生成的补丁文件相对路径列表（不含未安装的源）。
     *
     * @return list<string>
     */
    public function generateNullableStaticSources(): array
    {
        $generated = [];
        foreach (self::NULLABLE_STATIC_SOURCES as $sourceRel => $targetRel) {
            $sourceFile = $this->basePath . '/' . $sourceRel;
            if (!is_file($sourceFile)) {
                continue;
            }
            $content = file_get_contents($sourceFile);
            if ($content === false) {
                throw new \RuntimeException('Unable to read the static-patch source file: ' . $sourceFile);
            }

            $targetFile = $this->basePath . '/' . $targetRel;
            $directory = dirname($targetFile);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the TypePHP build directory: ' . $directory);
            }
            if (file_put_contents($targetFile, $this->patchUninitializedScalarStatics($content)) === false) {
                throw new \RuntimeException('Unable to write the AOT static-patch source: ' . $targetFile);
            }
            $generated[] = $targetRel;
        }
        return $generated;
    }

    /**
     * 把未初始化的标量类型静态属性补成可空并显式默认 null
     *
     * 仅匹配带可见性修饰符、标量类型、无默认值的静态属性声明，例如：
     * `protected static string $driver;` => `protected static ?string $driver = null;`
     * 函数内局部 `static $id = 0;`、已带默认值的属性、对象类型属性均不受影响。
     */
    protected function patchUninitializedScalarStatics(string $content): string
    {
        return (string) preg_replace(
            '/(^|\n)([ \t]*(?:public|protected|private)[ \t]+static[ \t]+)(bool|int|float|string)([ \t]+\$[A-Za-z_][A-Za-z0-9_]*)[ \t]*;/',
            '$1$2?$3$4 = null;',
            $content,
        );
    }

    /**
     * 为签名不足的错误处理/信号闭包生成可变参数 AOT 专用源文件
     *
     * 按 VARIADIC_HANDLER_REPLACEMENTS 的字面规则把 Worker.php、TcpConnection.php、
     * AsyncTcpConnection.php、Select.php、webman File.php 中会被 PHP 以固定参数个数
     * 调用的零参/两参闭包补成可变参数形态，写入打包工作区。返回生成的补丁文件
     * 相对路径列表（不含未安装的源）。
     *
     * @return list<string>
     */
    public function generateVariadicHandlerSources(): array
    {
        $generated = [];
        foreach (self::VARIADIC_HANDLER_SOURCES as $sourceRel => $targetRel) {
            $sourceFile = $this->basePath . '/' . $sourceRel;
            if (!is_file($sourceFile)) {
                continue;
            }
            $content = file_get_contents($sourceFile);
            if ($content === false) {
                throw new \RuntimeException('Unable to read the variadic-handler source file: ' . $sourceFile);
            }

            $targetFile = $this->basePath . '/' . $targetRel;
            $directory = dirname($targetFile);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the TypePHP build directory: ' . $directory);
            }
            if (file_put_contents($targetFile, $this->patchVariadicHandlers($sourceRel, $content)) === false) {
                throw new \RuntimeException('Unable to write the AOT variadic-handler source: ' . $targetFile);
            }
            $generated[] = $targetRel;
        }
        return $generated;
    }

    /**
     * 按字面规则把签名不足的处理器闭包改为可变参数形态
     */
    protected function patchVariadicHandlers(string $sourceRel, string $content): string
    {
        foreach (self::VARIADIC_HANDLER_REPLACEMENTS[$sourceRel] ?? [] as $search => $replacement) {
            $content = str_replace($search, $replacement, $content);
        }
        return $content;
    }

    /**
     * 生成 AOT 专用 helpers.php（GUARDED_SOURCES 中 webman helpers 的快捷入口）
     */
    public function generateHelpers(?string $targetFile = null): ?string
    {
        $sourceFile = $this->basePath . '/vendor/workerman/webman-framework/src/support/helpers.php';
        if (!is_file($sourceFile)) {
            return null;
        }
        $targetFile ??= $this->basePath . '/' . self::HELPERS_TARGET;
        $directory = dirname($targetFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the TypePHP helpers directory: ' . $directory);
        }
        $content = file_get_contents($sourceFile);
        if ($content === false) {
            throw new \RuntimeException('Unable to read the Webman helpers.php source file: ' . $sourceFile);
        }
        if (file_put_contents($targetFile, $this->flattenGuardedSource($content)) === false) {
            throw new \RuntimeException('Unable to write the AOT helpers file: ' . $targetFile);
        }
        return $targetFile;
    }

    /**
     * 把守卫源文件平铺为仅含顶层声明的 AOT 兼容版本
     *
     * - `if (!defined('BASE_PATH')) {...}`：整块丢弃（main.php 入口会在运行时最先定义 BASE_PATH）
     * - `if (!function_exists('fn')) {...}`：解包为顶层 function 声明（含命名空间限定的函数名）
     * - 其余顶层语句：原样保留；出现未知的顶层 if 时直接抛错，避免把问题推迟到编译期
     */
    protected function flattenGuardedSource(string $content): string
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $tokens = token_get_all($content);
        $total = count($tokens);
        $output = '';
        $trivia = '';
        $depth = 0;
        $interpolations = 0;
        $index = 0;

        while ($index < $total) {
            $token = $tokens[$index];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT)) {
                $trivia .= $text;
                $index++;
                continue;
            }

            if ($depth === 0 && $id === T_IF) {
                $statement = $this->extractTopLevelIf($tokens, $index);
                $condition = trim($statement['condition']);
                if (preg_match("/^!\s*defined\s*\(\s*(['\"])BASE_PATH\\1\s*\)$/", $condition) === 1) {
                    // BASE_PATH 由 main.php 在运行时定义，守卫连同前置注释一起丢弃
                } elseif (preg_match("/^!\s*function_exists\s*\(\s*['\"][^'\"]+['\"]\s*\)$/", $condition) === 1) {
                    $output .= $trivia . $statement['body'];
                } else {
                    throw new \RuntimeException(sprintf(
                        'Unsupported top-level guard in a guarded source file (line %d): `%s`. The TypePHP AOT compiler only accepts function declarations.',
                        $token[2],
                        $condition,
                    ));
                }
                $trivia = '';
                $index = $statement['next'];
                continue;
            }

            $output .= $trivia . $text;
            $trivia = '';
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
            $index++;
        }

        $output .= $trivia;

        // 新版 webman 的 worker_start 闭包会无条件 require support/bootstrap.php，而
        // 便携产物不携带 support/ 目录（bootstrap 属于运行时动态加载内容）；参考旧版
        // 行为改为 is_file 守卫，保证缺失时不致命
        $output = (string) preg_replace(
            "/require_once\\s+base_path\(\s*(['\"])\/support\/bootstrap\.php\\1\s*\);/",
            '$bootstrap = base_path($1/support/bootstrap.php$1);'
            . "\n"
            . '            if (is_file($bootstrap)) {'
            . "\n"
            . '                require_once $bootstrap;'
            . "\n"
            . '            }',
            $output,
        );

        $this->assertAotCompatibleTopLevel($output);
        return $output;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{condition: string, body: string, next: int}
     */
    protected function extractTopLevelIf(array $tokens, int $start): array
    {
        $total = count($tokens);
        $index = $start + 1;
        $condition = '';
        $body = '';

        $index = self::skipTrivia($tokens, $index);
        if ($index >= $total || (is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index]) !== '(') {
            throw new \RuntimeException(
                'Malformed top-level if statement in a guarded source file: missing condition.',
            );
        }
        $index++;

        $parenDepth = 1;
        while ($index < $total && $parenDepth > 0) {
            $token = $tokens[$index];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            if ($id === null && $text === '(') {
                $parenDepth++;
            } elseif ($id === null && $text === ')') {
                $parenDepth--;
                if ($parenDepth === 0) {
                    $index++;
                    break;
                }
            }
            $condition .= $text;
            $index++;
        }
        if ($parenDepth !== 0) {
            throw new \RuntimeException(
                'Malformed top-level if statement in a guarded source file: unbalanced condition.',
            );
        }

        $index = self::skipTrivia($tokens, $index);
        if ($index >= $total || (is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index]) !== '{') {
            throw new \RuntimeException(
                'Unsupported top-level if statement in a guarded source file: only braced bodies can be flattened.',
            );
        }
        $index++;

        $braceDepth = 1;
        $interpolations = 0;
        while ($index < $total && $braceDepth > 0) {
            $token = $tokens[$index];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interpolations++;
            } elseif ($id === null && $text === '{') {
                $braceDepth++;
            } elseif ($id === null && $text === '}') {
                if ($interpolations > 0) {
                    $interpolations--;
                } else {
                    $braceDepth--;
                    if ($braceDepth === 0) {
                        $index++;
                        break;
                    }
                }
            }
            $body .= $text;
            $index++;
        }
        if ($braceDepth !== 0) {
            throw new \RuntimeException('Malformed top-level if statement in a guarded source file: unbalanced body.');
        }

        $after = self::skipTrivia($tokens, $index);
        if ($after < $total && is_array($tokens[$after]) && in_array($tokens[$after][0], [T_ELSE, T_ELSEIF], true)) {
            throw new \RuntimeException(
                'Unsupported top-level if statement in a guarded source file: else branches cannot be flattened.',
            );
        }

        return ['condition' => $condition, 'body' => $body, 'next' => $index];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    protected static function skipTrivia(array $tokens, int $index): int
    {
        $total = count($tokens);
        while ($index < $total) {
            $id = is_array($tokens[$index]) ? $tokens[$index][0] : null;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                break;
            }
            $index++;
        }
        return $index;
    }

    /**
     * 校验平铺结果不会触发 TypePHP 的 Stmt_If 限制，且大括号配平。
     * 顶层函数声明的签名位于大括号之外，因此不能按 token 白名单校验；
     * 而 if 不可能出现在常量表达式签名中，顶层 T_IF 必然是残留的守卫语句。
     */
    protected function assertAotCompatibleTopLevel(string $content): void
    {
        $depth = 0;
        $interpolations = 0;
        foreach (token_get_all($content) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            if ($depth === 0 && $id === T_IF) {
                throw new \RuntimeException(sprintf(
                    'Flattened source still contains a top-level if statement near line %d.',
                    $token[2],
                ));
            }
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
        if ($depth !== 0 || $interpolations !== 0) {
            throw new \RuntimeException('Flattened source has unbalanced braces.');
        }
    }

    /**
     * 分析项目并生成 project.linux.yml
     */
    public function generateProjectYml(array $extraConfig = []): string
    {
        $sources = [
            'main.php',
            'app',
            'vendor/workerman/workerman/src',
            'vendor/workerman/webman-framework/src',
            'vendor/workerman/coroutine/src',
            'vendor/psr',
            'vendor/nikic/fast-route/src/BadRouteException.php',
            'vendor/nikic/fast-route/src/DataGenerator.php',
            'vendor/nikic/fast-route/src/Dispatcher.php',
            'vendor/nikic/fast-route/src/Route.php',
            'vendor/nikic/fast-route/src/RouteCollector.php',
            'vendor/nikic/fast-route/src/RouteParser.php',
            'vendor/nikic/fast-route/src/RouteParser/Std.php',
            'vendor/nikic/fast-route/src/DataGenerator/RegexBasedAbstract.php',
            'vendor/nikic/fast-route/src/DataGenerator/GroupCountBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/RegexBasedAbstract.php',
            'vendor/nikic/fast-route/src/Dispatcher/GroupCountBased.php',
        ];

        if (isset($extraConfig['include']) && is_array($extraConfig['include'])) {
            $sources = array_merge(['main.php'], $extraConfig['include']);
        } elseif (isset($extraConfig['sources']) && is_array($extraConfig['sources'])) {
            $sources = $extraConfig['sources'];
        }

        // 自动探测 monolog 与 webman-mcp 等常用库
        if (!isset($extraConfig['include']) && !isset($extraConfig['sources'])) {
            if (is_dir($this->basePath . '/vendor/monolog/monolog/src')) {
                $monologSources = [
                    'vendor/monolog/monolog/src/Monolog/Logger.php',
                    'vendor/monolog/monolog/src/Monolog/LogRecord.php',
                    'vendor/monolog/monolog/src/Monolog/ResettableInterface.php',
                    'vendor/monolog/monolog/src/Monolog/DateTimeImmutable.php',
                    'vendor/monolog/monolog/src/Monolog/ErrorHandler.php',
                    'vendor/monolog/monolog/src/Monolog/Registry.php',
                    'vendor/monolog/monolog/src/Monolog/Utils.php',
                    'vendor/monolog/monolog/src/Monolog/Formatter/FormatterInterface.php',
                    'vendor/monolog/monolog/src/Monolog/Formatter/NormalizerFormatter.php',
                    'vendor/monolog/monolog/src/Monolog/Formatter/LineFormatter.php',
                    'vendor/monolog/monolog/src/Monolog/Formatter/JsonFormatter.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/HandlerInterface.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/Handler.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/AbstractHandler.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/AbstractProcessingHandler.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/StreamHandler.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/RotatingFileHandler.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/ErrorLogHandler.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/NullHandler.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/FormattableHandlerInterface.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/FormattableHandlerTrait.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/ProcessableHandlerInterface.php',
                    'vendor/monolog/monolog/src/Monolog/Handler/ProcessableHandlerTrait.php',
                    'vendor/monolog/monolog/src/Monolog/Processor',
                ];
                foreach ($monologSources as $source) {
                    if (file_exists($this->basePath . '/' . $source) || is_dir($this->basePath . '/' . $source)) {
                        $sources[] = $source;
                    }
                }
            }
            if (is_dir($this->basePath . '/vendor/tinywan/webman-mcp/src')) {
                $sources[] = 'vendor/tinywan/webman-mcp/src';
            }
            if (is_dir($this->basePath . '/vendor/neuron-core/neuron-ai/src')) {
                $sources[] = 'vendor/neuron-core/neuron-ai/src';
            }
        }

        // 生成 AOT 专用平铺守卫源（helpers.php、fast-route functions.php 等），并注入
        // sources（任何配置形态下都生效，保证 base_path()/config()/simpleDispatcher()
        // 等全局函数被编译进二进制且不触发 Stmt_If 错误或被静默跳过）
        $flattenedTargets = array_values(array_filter(
            $this->generateFlattenedSources(),
            static fn(string $target): bool => !in_array($target, $sources, true),
        ));
        // 生成 AOT 专用静态属性补丁源（coroutine Context/WaitGroup/Barrier），同样
        // 注入 sources，保证 ??= 驱动初始化在编译产物中恢复语义
        $patchedTargets = array_values(array_filter(
            $this->generateNullableStaticSources(),
            static fn(string $target): bool => !in_array($target, $sources, true),
        ));
        // 生成 AOT 专用可变参数闭包补丁源（Worker/TcpConnection/AsyncTcpConnection/
        // Select/webman File），保证错误处理与信号闭包不再触发精确参数个数错误
        $variadicTargets = array_values(array_filter(
            $this->generateVariadicHandlerSources(),
            static fn(string $target): bool => !in_array($target, $sources, true),
        ));
        $aotGeneratedTargets = [...$flattenedTargets, ...$patchedTargets, ...$variadicTargets];
        if ($aotGeneratedTargets !== []) {
            $position = array_search('main.php', $sources, true);
            if ($position === false) {
                $sources = array_merge($aotGeneratedTargets, $sources);
            } else {
                array_splice($sources, (int) $position + 1, 0, $aotGeneratedTargets);
            }
        }

        // 默认忽略列表（无论用户配置如何，框架与动态必须忽略项始终自动补充）
        $mandatoryIgnores = [
            'config',
            'public',
            'runtime',
            'app/view',
            'app/model',
            'app/command',
            'app/functions.php',
            'app/process/Monitor.php',
            'support',
            'vendor/workerman/webman-framework/src/support/view',
            'vendor/workerman/webman-framework/src/support/helpers.php',
            'vendor/workerman/webman-framework/src/support/bootstrap.php',
            'vendor/workerman/webman-framework/src/start.php',
            'vendor/workerman/webman-framework/src/windows.php',
            'vendor/workerman/webman-framework/src/Install.php',
            'vendor/nikic/fast-route/src/functions.php',
            'vendor/nikic/fast-route/src/bootstrap.php',
            'vendor/nikic/fast-route/src/DataGenerator/CharCountBased.php',
            'vendor/nikic/fast-route/src/DataGenerator/GroupPosBased.php',
            'vendor/nikic/fast-route/src/DataGenerator/MarkBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/CharCountBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/GroupPosBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/MarkBased.php',
            'vendor/workerman/coroutine/src/Context.php',
            'vendor/workerman/coroutine/src/WaitGroup.php',
            'vendor/workerman/coroutine/src/Barrier.php',
            'vendor/workerman/workerman/src/Worker.php',
            'vendor/workerman/workerman/src/Connection/TcpConnection.php',
            'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php',
            'vendor/workerman/workerman/src/Events/Select.php',
            'vendor/workerman/webman-framework/src/File.php',
            'vendor/workerman/coroutine/tests',
            'vendor/workerman/coroutine/stubs',
            'vendor/workerman/coroutine/src/Barrier/Swow.php',
            'vendor/workerman/coroutine/src/Channel/Swow.php',
            'vendor/workerman/coroutine/src/Context/Swow.php',
            'vendor/workerman/coroutine/src/Coroutine/Swow.php',
            'vendor/workerman/coroutine/src/WaitGroup/Swow.php',
            'vendor/workerman/workerman/src/Events/Swow.php',
            'vendor/workerman/coroutine/src/Pool.php',
            'vendor/workerman/coroutine/src/Utils/DestructionWatcher.php',
            'vendor/monolog/monolog/src/Monolog/Test',
            'vendor/tinywan/webman-mcp/src/config',
            'vendor/tinywan/webman-mcp/src/Install.php',
            'vendor/tinywan/webman-mcp/src/Command',
            'vendor/neuron-core/neuron-ai/src/Console',
            'vendor/neuron-core/neuron-ai/src/Providers',
            'vendor/neuron-core/neuron-ai/src/Workflow',
            'vendor/neuron-core/neuron-ai/src/Evaluation',
            'vendor/neuron-core/neuron-ai/src/Testing',
            'vendor/neuron-core/neuron-ai/src/Agent',
            'vendor/neuron-core/neuron-ai/src/Chat',
            'vendor/neuron-core/neuron-ai/src/Observability',
            'vendor/neuron-core/neuron-ai/src/RAG',
        ];

        $userIgnores = [];
        if (isset($extraConfig['exclude']) && is_array($extraConfig['exclude'])) {
            $userIgnores = $extraConfig['exclude'];
        } elseif (isset($extraConfig['ignore']) && is_array($extraConfig['ignore'])) {
            $userIgnores = $extraConfig['ignore'];
        }
        $ignores = array_unique(array_merge($mandatoryIgnores, $userIgnores));

        $outputName = $extraConfig['build']['output_name'] ?? 'webman-server';

        $yaml = "name: {$outputName}\n\n";
        $yaml .= "sources:\n";
        foreach (array_unique($sources) as $source) {
            $yaml .= "  - {$source}\n";
        }

        $yaml .= "\nignore:\n";
        foreach (array_unique($ignores) as $ignore) {
            $yaml .= "  - {$ignore}\n";
        }

        $yaml .= "\noutput: build/{$outputName}\n";
        $yaml .= "mode: bin\n";
        $yaml .= "optimize: 2\n";
        // The builder entrypoint raises this to the container's core count (nproc,
        // capped) at compile time; 4 is a safe parallel default for direct tpc runs.
        $yaml .= "job: 4\n";
        $yaml .= "debug: false\n";

        $ymlPath = $this->basePath . DIRECTORY_SEPARATOR . 'project.linux.yml';
        file_put_contents($ymlPath, $yaml);
        return $ymlPath;
    }
}
