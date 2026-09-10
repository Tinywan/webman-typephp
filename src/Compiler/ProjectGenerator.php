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
     * 需要剥离顶层引导调用的源文件映射：vendor 原文件 => AOT 专用补丁文件。
     * workerman 系列源文件在类声明之后以顶层 `Session::init();` 等调用触发模块
     * 初始化，而 TypePHP 编译器只接受顶层的 Class/Function/Use/Const/Namespace
     * 声明，扫描期即报 `found stray code` Fatal。main.php 桩已带 class_exists
     * 守卫在启动时调用全部 init 方法，这些顶层调用在 AOT 源里属于冗余，可安全剥离。
     */
    public const STRAY_BOOTSTRAP_SOURCES = [
        'vendor/workerman/workerman/src/Protocols/Http/Session.php' => '.typephp/build/http-session.php',
        'vendor/workerman/workerman/src/Protocols/Http/Session/FileSessionHandler.php' => '.typephp/build/http-session-file-handler.php',
        'vendor/workerman/coroutine/src/Context/Fiber.php' => '.typephp/build/coroutine-context-fiber.php',
        'vendor/workerman/coroutine/src/Coroutine/Fiber.php' => '.typephp/build/coroutine-fiber.php',
        'vendor/workerman/coroutine/src/Coroutine.php' => '.typephp/build/coroutine-coroutine.php',
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
     * 补丁把这些闭包改为可变参数形态。另对 resetStd() 的标准流关闭段做整段删除
     * （见 RESET_STD_STREAM_CLOSE_BLOCK）：TypePHP 标准流为 NO_CLOSE、禁止手动关闭，
     * start -d 守护进程化必经的 fclose(STDOUT/STDERR/outputStream) 会抛 TypeError
     * 令 master/worker 在 "Start success" 后即刻崩溃。
     */
    public const VARIADIC_HANDLER_SOURCES = [
        'vendor/workerman/workerman/src/Worker.php' => '.typephp/build/workerman-worker.php',
        'vendor/workerman/workerman/src/Connection/TcpConnection.php' => '.typephp/build/workerman-tcp-connection.php',
        'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php' => '.typephp/build/workerman-async-tcp-connection.php',
        'vendor/workerman/workerman/src/Events/Select.php' => '.typephp/build/workerman-select.php',
        'vendor/workerman/webman-framework/src/File.php' => '.typephp/build/webman-file.php',
    ];

    /**
     * resetStd() 的标准流关闭段（STDOUT / STDERR / outputStream），按真实 vendor 逐字节匹配后整段删除。
     *
     * TypePHP 嵌入式运行时（libphp 链入原生二进制）把标准流标为 NO_CLOSE、禁止手动关闭：
     * is_resource(STDOUT) 为真但 fclose(STDOUT) 抛 "supplied resource is not a valid
     * stream resource" TypeError。start -d 守护进程化必经 resetStd()（master 与每个 fork 出的
     * worker 各执行一次），任一处 fclose 都会让进程在 "Start success" 后即刻崩溃。
     * 三处关闭并非日志重定向所需——紧随其后的 fopen(static::$stdoutFile, 'a') 已把
     * static::$outputStream 重指向目标文件（默认 /dev/null），safeEcho()/log() 均经
     * outputStream 输出，删除后日志去向与 stock 完全一致。该补丁源仅参与 AOT 编译
     * （vendor 原件已在 ignore 中排除），无需兼容普通 PHP 中"关闭 fd1/fd2 使 fopen 复用"的语义。
     */
    protected const RESET_STD_STREAM_CLOSE_BLOCK =
        "\n"
        . '        if (is_resource(STDOUT)) {' . "\n"
        . '            fclose(STDOUT);' . "\n"
        . '        }' . "\n"
        . "\n"
        . '        if (is_resource(STDERR)) {' . "\n"
        . '            fclose(STDERR);' . "\n"
        . '        }' . "\n"
        . "\n"
        . '        if (is_resource(static::$outputStream)) {' . "\n"
        . '            fclose(static::$outputStream);' . "\n"
        . '        }' . "\n"
        . "\n";

    /**
     * 需要 switch 终结语句补丁的源文件映射：vendor 原文件 => AOT 专用补丁文件。
     * TypePHP 编译器要求每个非空 case 体以 return/break/continue/exit/throw 结束
     * （不支持落空 fall-through）。App::stringify() 的 `case 'object'` 依赖落空到
     * default 返回 `(string)$data`，Monolog Utils 的 JSON 错误 default 缺 break、
     * 内存单位 g/m/k 依赖级联落空连乘 1024。补丁把这些 case 改写为语义等价的
     * 终结形态（object 直接 return、default 补 break、级联展开为各 case 独立连乘）。
     */
    public const SWITCH_TERMINAL_SOURCES = [
        'vendor/workerman/webman-framework/src/App.php' => '.typephp/build/webman-app.php',
        'vendor/monolog/monolog/src/Monolog/Utils.php' => '.typephp/build/monolog-utils.php',
    ];

    /**
     * 需要类型化引用捕获补丁的源文件映射：vendor 原文件 => AOT 专用补丁文件。
     * TypePHP 的 v0.8 类型化引用体系要求：被 `use (&$var)` 按引用捕获的变量在捕获点
     * 不能已持有固定类型（数组参数、bool/int/string 局部变量均属固定类型），否则报
     * `Cannot create a reference to variable $x of fixed type` Fatal；捕获未初始化
     * 变量则编译为普通 Zend 引用槽，与 stock PHP 语义一致。Route::url()、协程
     * Barrier/Fiber 与 Channel/Fiber 都先 `$timedOut = false;` 再按引用捕获，补丁
     * 删除这类初始化（或改为捕获未初始化变量），使引用语义经 Zend 引用 ABI 恢复。
     */
    public const REF_CAPTURE_SOURCES = [
        'vendor/workerman/webman-framework/src/Route/Route.php' => '.typephp/build/webman-route.php',
        'vendor/workerman/coroutine/src/Barrier/Fiber.php' => '.typephp/build/coroutine-barrier-fiber.php',
        'vendor/workerman/coroutine/src/Channel/Fiber.php' => '.typephp/build/coroutine-channel-fiber.php',
    ];

    /**
     * 类型化引用捕获补丁的字面替换规则：源文件 => [搜索串 => 替换串]。
     * 搜索串为 vendor 源码中的精确字面量（注意各文件 CRLF/LF 差异）；
     * 未匹配时静默跳过（版本差异容忍）。
     */
    protected const REF_CAPTURE_REPLACEMENTS = [
        // Route::url() 把 array 参数 $parameters 按引用捕获进 preg_replace_callback
        // 回调消耗已用参数。改为按值捕获 $parameters + 按引用捕获未初始化的
        // $remaining（首次回调内拷贝，回调未执行时回退为完整参数表拼查询串）。
        'vendor/workerman/webman-framework/src/Route/Route.php' => [
            '        $path = preg_replace_callback(\'/\\{(.*?)(?:\\:[^\\}]*?)*?\\}/\', function ($matches) use (&$parameters) {' . "\r\n"
            . '            if (!$parameters) {' . "\r\n"
            . '                return $matches[0];' . "\r\n"
            . '            }' . "\r\n"
            . '            if (isset($parameters[$matches[1]])) {' . "\r\n"
            . '                $value = $parameters[$matches[1]];' . "\r\n"
            . '                unset($parameters[$matches[1]]);' . "\r\n"
            . '                return $value;' . "\r\n"
            . '            }' . "\r\n"
            . '            $key = key($parameters);' . "\r\n"
            . '            if (is_int($key)) {' . "\r\n"
            . '                $value = $parameters[$key];' . "\r\n"
            . '                unset($parameters[$key]);' . "\r\n"
            . '                return $value;' . "\r\n"
            . '            }' . "\r\n"
            . '            return $matches[0];' . "\r\n"
            . '        }, $path);' . "\r\n"
            . '        return count($parameters) > 0 ? $path . \'?\' . http_build_query($parameters) : $path;'
            => '        $path = preg_replace_callback(\'/\\{(.*?)(?:\\:[^\\}]*?)*?\\}/\', function ($matches) use ($parameters, &$remaining) {' . "\r\n"
                . '            if ($remaining === null) {' . "\r\n"
                . '                $remaining = $parameters;' . "\r\n"
                . '            }' . "\r\n"
                . '            if (!$remaining) {' . "\r\n"
                . '                return $matches[0];' . "\r\n"
                . '            }' . "\r\n"
                . '            if (isset($remaining[$matches[1]])) {' . "\r\n"
                . '                $value = $remaining[$matches[1]];' . "\r\n"
                . '                unset($remaining[$matches[1]]);' . "\r\n"
                . '                return $value;' . "\r\n"
                . '            }' . "\r\n"
                . '            $key = key($remaining);' . "\r\n"
                . '            if (is_int($key)) {' . "\r\n"
                . '                $value = $remaining[$key];' . "\r\n"
                . '                unset($remaining[$key]);' . "\r\n"
                . '                return $value;' . "\r\n"
                . '            }' . "\r\n"
                . '            return $matches[0];' . "\r\n"
                . '        }, $path);' . "\r\n"
                . '        if ($remaining === null) {' . "\r\n"
                . '            $remaining = $parameters;' . "\r\n"
                . '        }' . "\r\n"
                . '        return count($remaining) > 0 ? $path . \'?\' . http_build_query($remaining) : $path;',
        ],
        // Barrier/Fiber::wait() 的 &$resumed 无外层赋值 → 捕获为 REF 槽，删除
        // `$resumed = false;` 即可。$timerId 则先被 `Timer::delay()` 返回值定型为
        // Int 再被引用捕获（v0.8 禁止），而闭包内只读不写——改为按值捕获（捕获点
        // 即赋值后，值恒等），并保留 `$timerId = null;` 保证未启动计时器时变量已定义。
        'vendor/workerman/coroutine/src/Barrier/Fiber.php' => [
            '        $resumed = false;' . "\r\n"
            . '        $timerId = null;' . "\r\n"
            => '        $timerId = null;' . "\r\n",
            'function() use ($coroutine, &$resumed, &$timerId) {'
            => 'function() use ($coroutine, &$resumed, $timerId) {',
        ],
        // Channel/Fiber 的 push()/pop()：&$timedOut 无外层赋值，删除初始化即为
        // REF 槽；$timerId 无引用捕获、且需保证条件赋值路径上已定义，保留 null 初始化。
        'vendor/workerman/coroutine/src/Channel/Fiber.php' => [
            '            $timedOut = false;' . "\r\n"
            . '            $timerId = null;' . "\r\n"
            => '            $timerId = null;' . "\r\n",
        ],
    ];

    /**
     * switch 终结补丁的字面替换规则：源文件 => [搜索串 => 替换串]。
     * 搜索串为 vendor 源码中的精确字面量；未匹配时静默跳过（版本差异容忍）。
     */
    protected const SWITCH_TERMINAL_REPLACEMENTS = [
        'vendor/workerman/webman-framework/src/App.php' => [
            // getReflector() 同一变量 `$reflector` 在两个分支分别 new ReflectionFunction /
            // ReflectionMethod，TypePHP 的类型化对象局部变量禁止跨类重赋值。改为分支内
            // 独立变量 + 提前 return，缓存写入逻辑随分支各带一份，语义不变。
            '        if ($call instanceof Closure || is_string($call)) {' . "\n"
            . '            $reflector = new ReflectionFunction($call);' . "\n"
            . '        } else {' . "\n"
            . '            $reflector = new ReflectionMethod($call[0], $call[1]);' . "\n"
            . '        }' . "\n"
            . "\n"
            . '        if ($cacheKey !== null) {' . "\n"
            . '            static::$reflectorCache[$cacheKey] = $reflector;' . "\n"
            . '            if (count(static::$reflectorCache) > 1024) {' . "\n"
            . '                unset(static::$reflectorCache[key(static::$reflectorCache)]);' . "\n"
            . '            }' . "\n"
            . '        }' . "\n"
            . "\n"
            . '        return $reflector;'
            => '        if ($call instanceof Closure || is_string($call)) {' . "\n"
                . '            $reflectorFunction = new ReflectionFunction($call);' . "\n"
                . '            if ($cacheKey !== null) {' . "\n"
                . '                static::$reflectorCache[$cacheKey] = $reflectorFunction;' . "\n"
                . '                if (count(static::$reflectorCache) > 1024) {' . "\n"
                . '                    unset(static::$reflectorCache[key(static::$reflectorCache)]);' . "\n"
                . '                }' . "\n"
                . '            }' . "\n"
                . '            return $reflectorFunction;' . "\n"
                . '        }' . "\n"
                . "\n"
                . '        $reflectorMethod = new ReflectionMethod($call[0], $call[1]);' . "\n"
                . '        if ($cacheKey !== null) {' . "\n"
                . '            static::$reflectorCache[$cacheKey] = $reflectorMethod;' . "\n"
                . '            if (count(static::$reflectorCache) > 1024) {' . "\n"
                . '                unset(static::$reflectorCache[key(static::$reflectorCache)]);' . "\n"
                . '            }' . "\n"
                . '        }' . "\n"
                . '        return $reflectorMethod;',
            '                if (!method_exists($data, \'__toString\')) {'
            . "\n"
            . '                    return \'Object\';'
            . "\n"
            . '                }'
            . "\n"
            . '            default:'
            => '                if (!method_exists($data, \'__toString\')) {'
                . "\n"
                . '                    return \'Object\';'
                . "\n"
                . '                }'
                . "\n"
                . '                return (string)$data;'
                . "\n"
                . '            default:',
        ],
        'vendor/monolog/monolog/src/Monolog/Utils.php' => [
            '                $msg = \'Unknown error\';'
            . "\n"
            . '        }'
            => '                $msg = \'Unknown error\';'
                . "\n"
                . '                break;'
                . "\n"
                . '        }',
            '            case \'g\':'
            . "\n"
            . '                $val *= 1024;'
            . "\n"
            . '            case \'m\':'
            . "\n"
            . '                $val *= 1024;'
            . "\n"
            . '            case \'k\':'
            . "\n"
            . '                $val *= 1024;'
            . "\n"
            . '        }'
            => '            case \'g\':'
                . "\n"
                . '                $val *= 1024;'
                . "\n"
                . '                $val *= 1024;'
                . "\n"
                . '                $val *= 1024;'
                . "\n"
                . '                break;'
                . "\n"
                . '            case \'m\':'
                . "\n"
                . '                $val *= 1024;'
                . "\n"
                . '                $val *= 1024;'
                . "\n"
                . '                break;'
                . "\n"
                . '            case \'k\':'
                . "\n"
                . '                $val *= 1024;'
                . "\n"
                . '                break;'
                . "\n"
                . '        }',
        ],
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
            // daemon 模式 resetStd()：TypePHP 嵌入式运行时把标准流标记 NO_CLOSE、禁止手动关闭，三处
            // fclose 必抛 TypeError 令守护进程崩溃。日志重定向不依赖关闭它们——其下 fopen(stdoutFile)
            // 已把 outputStream 重指向目标文件，故整段删除关闭段（保留一个空行）。
            self::RESET_STD_STREAM_CLOSE_BLOCK => "\n",
            // parseCommand 的 `case 'status'` 以 while(1) 死循环结尾后落空到 `case 'connections'`，
            // 编译器要求 case 以终结语句收尾。循环仅经内部 exit(0) 退出，落空本为不可达死代码，
            // 补一个不可达的 exit(0) 即满足约束且不改变行为。
            '                    static::safeEcho("\nPress Ctrl+C to quit.\n\n");' . "\n"
            . '                }' . "\n"
            . '            case \'connections\':'
            => '                    static::safeEcho("\nPress Ctrl+C to quit.\n\n");' . "\n"
                . '                }' . "\n"
                . '                exit(0);' . "\n"
                . '            case \'connections\':',
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
            if (file_put_contents($targetFile, $this->stripStrayBootstrapCalls($this->patchUninitializedScalarStatics($content))) === false) {
                throw new \RuntimeException('Unable to write the AOT static-patch source: ' . $targetFile);
            }
            $generated[] = $targetRel;
        }
        return $generated;
    }

    /**
     * 删除文件末尾顶层的 `Class::init*();` 引导调用
     *
     * 仅匹配类结束大括号之后、文件末尾的调用语句（含紧邻的 `// Init ...`
     * 注释行），方法体内部的 init 调用不受影响。main.php 桩会在启动时按
     * 相同语义调用这些方法，剥离后运行时行为不变。
     */
    protected function stripStrayBootstrapCalls(string $content): string
    {
        return (string) preg_replace(
            '/\n(?:[ \t]*\/\/[^\n]*\n[ \t]*)?[ \t]*(?:Session|FileSessionHandler|Fiber|Coroutine|Context)::(?:initContext|initDriver|init)\(\);\s*$/D',
            "\n",
            $content,
        );
    }

    /**
     * 为含顶层引导调用的源文件生成剥离后的 AOT 专用版本
     *
     * 读取项目实际安装的 workerman 源文件（Http/Session、FileSessionHandler、
     * coroutine 的 Fiber/Context/Coroutine 等），删除类声明之后顶层的
     * `Class::init*();` 引导调用后写入打包工作区。返回生成的补丁文件相对
     * 路径列表（不含未安装的源）。
     *
     * @return list<string>
     */
    public function generateStrayBootstrapSources(): array
    {
        $generated = [];
        foreach (self::STRAY_BOOTSTRAP_SOURCES as $sourceRel => $targetRel) {
            $sourceFile = $this->basePath . '/' . $sourceRel;
            if (!is_file($sourceFile)) {
                continue;
            }
            $content = file_get_contents($sourceFile);
            if ($content === false) {
                throw new \RuntimeException('Unable to read the stray-bootstrap source file: ' . $sourceFile);
            }

            $targetFile = $this->basePath . '/' . $targetRel;
            $directory = dirname($targetFile);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the TypePHP build directory: ' . $directory);
            }
            if (file_put_contents($targetFile, $this->stripStrayBootstrapCalls($content)) === false) {
                throw new \RuntimeException('Unable to write the AOT stray-bootstrap source: ' . $targetFile);
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
     * 为含类型化引用捕获的源文件生成补丁后的 AOT 专用版本
     *
     * 读取项目实际安装的源文件（webman Route.php、协程 Barrier/Channel Fiber），
     * 按 REF_CAPTURE_REPLACEMENTS 把“先初始化再按引用捕获”改写为捕获未初始化
     * 变量后写入打包工作区。返回生成的补丁文件相对路径列表（不含未安装的源）。
     *
     * @return list<string>
     */
    public function generateRefCaptureSources(): array
    {
        $generated = [];
        foreach (self::REF_CAPTURE_SOURCES as $sourceRel => $targetRel) {
            $sourceFile = $this->basePath . '/' . $sourceRel;
            if (!is_file($sourceFile)) {
                continue;
            }
            $content = file_get_contents($sourceFile);
            if ($content === false) {
                throw new \RuntimeException('Unable to read the ref-capture source file: ' . $sourceFile);
            }

            $targetFile = $this->basePath . '/' . $targetRel;
            $directory = dirname($targetFile);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the TypePHP build directory: ' . $directory);
            }
            if (file_put_contents($targetFile, $this->patchRefCaptures($sourceRel, $content)) === false) {
                throw new \RuntimeException('Unable to write the AOT ref-capture source: ' . $targetFile);
            }
            $generated[] = $targetRel;
        }
        return $generated;
    }

    /**
     * 按字面规则把“先初始化再按引用捕获”改写为捕获未初始化变量
     */
    protected function patchRefCaptures(string $sourceRel, string $content): string
    {
        foreach (self::REF_CAPTURE_REPLACEMENTS[$sourceRel] ?? [] as $search => $replacement) {
            $content = str_replace($search, $replacement, $content);
        }
        return $content;
    }

    /**
     * 为含非终结 switch case 的源文件生成补丁后的 AOT 专用版本
     *
     * 读取项目实际安装的源文件（webman-framework App.php、monolog Utils.php），
     * 按 SWITCH_TERMINAL_REPLACEMENTS 把落空 case 改写为终结形态后写入打包
     * 工作区。返回生成的补丁文件相对路径列表（不含未安装的源）。
     *
     * @return list<string>
     */
    public function generateSwitchTerminalSources(): array
    {
        $generated = [];
        foreach (self::SWITCH_TERMINAL_SOURCES as $sourceRel => $targetRel) {
            $sourceFile = $this->basePath . '/' . $sourceRel;
            if (!is_file($sourceFile)) {
                continue;
            }
            $content = file_get_contents($sourceFile);
            if ($content === false) {
                throw new \RuntimeException('Unable to read the switch-terminal source file: ' . $sourceFile);
            }

            $targetFile = $this->basePath . '/' . $targetRel;
            $directory = dirname($targetFile);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the TypePHP build directory: ' . $directory);
            }
            if (file_put_contents($targetFile, $this->patchSwitchTerminals($sourceRel, $content)) === false) {
                throw new \RuntimeException('Unable to write the AOT switch-terminal source: ' . $targetFile);
            }
            $generated[] = $targetRel;
        }
        return $generated;
    }

    /**
     * 按字面规则把落空 switch case 改写为终结形态
     */
    protected function patchSwitchTerminals(string $sourceRel, string $content): string
    {
        foreach (self::SWITCH_TERMINAL_REPLACEMENTS[$sourceRel] ?? [] as $search => $replacement) {
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
        // 生成 AOT 专用顶层引导调用剥离源（Http/Session、FileSessionHandler、
        // coroutine Fiber/Coroutine），保证扫描期不再出现 stray code Fatal
        $strayStrippedTargets = array_values(array_filter(
            $this->generateStrayBootstrapSources(),
            static fn(string $target): bool => !in_array($target, $sources, true),
        ));
        // 生成 AOT 专用 switch 终结补丁源（webman App、monolog Utils），保证
        // 落空 case 不再触发 switch case must end with Fatal
        $switchTerminalTargets = array_values(array_filter(
            $this->generateSwitchTerminalSources(),
            static fn(string $target): bool => !in_array($target, $sources, true),
        ));
        // 生成 AOT 专用类型化引用捕获补丁源（webman Route、协程 Barrier/Channel
        // Fiber），保证按引用捕获不再触发 fixed type Fatal
        $refCaptureTargets = array_values(array_filter(
            $this->generateRefCaptureSources(),
            static fn(string $target): bool => !in_array($target, $sources, true),
        ));
        $aotGeneratedTargets = [...$flattenedTargets, ...$patchedTargets, ...$variadicTargets, ...$strayStrippedTargets, ...$switchTerminalTargets, ...$refCaptureTargets];
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
            'vendor/workerman/workerman/src/Protocols/Http/Session.php',
            'vendor/workerman/workerman/src/Protocols/Http/Session/FileSessionHandler.php',
            'vendor/workerman/coroutine/src/Context/Fiber.php',
            'vendor/workerman/coroutine/src/Coroutine/Fiber.php',
            'vendor/workerman/coroutine/src/Coroutine.php',
            'vendor/workerman/webman-framework/src/App.php',
            'vendor/monolog/monolog/src/Monolog/Utils.php',
            'vendor/workerman/webman-framework/src/Route/Route.php',
            'vendor/workerman/coroutine/src/Barrier/Fiber.php',
            'vendor/workerman/coroutine/src/Channel/Fiber.php',
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
