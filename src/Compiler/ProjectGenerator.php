<?php

/**
 * @desc TypePHP AOT 编译配置与项目入口生成器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/05
 */
declare(strict_types=1);

namespace Tinywan\Typephp\Compiler;

use Tinywan\Typephp\Compiler\Profile\SaiAdminProfile;

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
        'vendor/illuminate/collections/helpers.php' => '.typephp/build/illuminate-collections-helpers.php',
        'vendor/illuminate/collections/functions.php' => '.typephp/build/illuminate-collections-functions.php',
        'vendor/illuminate/events/functions.php' => '.typephp/build/illuminate-events-functions.php',
        'vendor/illuminate/filesystem/functions.php' => '.typephp/build/illuminate-filesystem-functions.php',
        'vendor/illuminate/reflection/helpers.php' => '.typephp/build/illuminate-reflection-helpers.php',
        'vendor/illuminate/support/helpers.php' => '.typephp/build/illuminate-support-helpers.php',
        'vendor/illuminate/support/functions.php' => '.typephp/build/illuminate-support-functions.php',
        'vendor/cakephp/core/functions.php' => '.typephp/build/cakephp-core-functions.php',
        'vendor/ralouphie/getallheaders/src/getallheaders.php' => '.typephp/build/getallheaders.php',
        'vendor/symfony/deprecation-contracts/function.php' => '.typephp/build/symfony-trigger-deprecation.php',
        'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php' => '.typephp/build/symfony-grapheme-functions.php',
        'vendor/symfony/polyfill-intl-idn/bootstrap80.php' => '.typephp/build/symfony-idn-functions.php',
        'vendor/symfony/polyfill-intl-normalizer/bootstrap80.php' => '.typephp/build/symfony-normalizer-functions.php',
        'vendor/symfony/var-dumper/Resources/functions/dump.php' => '.typephp/build/symfony-var-dumper-functions.php',
        'vendor/topthink/think-validate/src/helper.php' => '.typephp/build/think-validate-functions.php',
        'vendor/zoujingli/ip2region/Ip2Region.php' => '.typephp/build/ip2region-class.php',
        'vendor/zoujingli/ip2region/function.php' => '.typephp/build/ip2region-functions.php',
        'vendor/zoujingli/ip2region/src/common.php' => '.typephp/build/ip2region-v3-functions.php',
        'vendor/symfony/polyfill-php85/bootstrap.php' => '.typephp/build/symfony-php85-functions.php',
        'vendor/symfony/polyfill-php85/Resources/stubs/DelayedTargetValidation.php' => '.typephp/build/php85-delayed-target-validation.php',
        'vendor/symfony/polyfill-php85/Resources/stubs/NoDiscard.php' => '.typephp/build/php85-no-discard.php',
        'vendor/symfony/polyfill-php85/Resources/stubs/Filter/FilterException.php' => '.typephp/build/php85-filter-exception.php',
        'vendor/symfony/polyfill-php85/Resources/stubs/Filter/FilterFailedException.php' => '.typephp/build/php85-filter-failed-exception.php',
        'vendor/symfony/cache/Traits/Redis61ProxyTrait.php' => '.typephp/build/symfony-cache-redis61-proxy-trait.php',
        'vendor/symfony/cache/Traits/Redis62ProxyTrait.php' => '.typephp/build/symfony-cache-redis62-proxy-trait.php',
        'vendor/symfony/cache/Traits/Redis63ProxyTrait.php' => '.typephp/build/symfony-cache-redis63-proxy-trait.php',
        'vendor/symfony/cache/Traits/RedisCluster61ProxyTrait.php' => '.typephp/build/symfony-cache-redis-cluster61-proxy-trait.php',
        'vendor/symfony/cache/Traits/RedisCluster62ProxyTrait.php' => '.typephp/build/symfony-cache-redis-cluster62-proxy-trait.php',
        'vendor/symfony/cache/Traits/RedisCluster63ProxyTrait.php' => '.typephp/build/symfony-cache-redis-cluster63-proxy-trait.php',
    ];

    /**
     * 仅负责动态加载、而真实声明位于静态目标文件的已知 Composer files wrapper。
     *
     * wrapper 不可直接平铺：guard 主体是 require，不是声明。生成配置时严格校验
     * wrapper 形态、显式加入声明源并忽略 wrapper，未知或变形内容立即失败。
     */
    public const STATIC_LOADER_SOURCES = [
        'vendor/guzzlehttp/guzzle/src/functions_include.php' => 'vendor/guzzlehttp/guzzle/src/functions.php',
    ];

    /**
     * 明确登记为运行时解释执行的第三方动态源码。
     *
     * laravel/serializable-closure 依赖 extract、动态 stream include 与 Closure::bindTo；
     * symfony/cache 的适配器、标签与 Redis trait 广泛依赖 Closure::bind() 访问 CacheItem
     * 内部槽。二者均为第三方动态实现，不能静态转换，随包保留 Composer 相对路径。
     */
    public const REGISTERED_DYNAMIC_SOURCES = [
        // These third-party units rely on runtime-only PHP behavior:
        // FnStream dispatches dynamically stored callables, while PHPMailer
        // passes stream arguments by reference through internal functions.
        'vendor/guzzlehttp/psr7/src/FnStream.php',
        'vendor/laravel/serializable-closure',
        'vendor/phpmailer/phpmailer/get_oauth_token.php',
        'vendor/phpmailer/phpmailer/src/POP3.php',
        'vendor/phpmailer/phpmailer/src/SMTP.php',
        // SaiAdmin 6.1.5 uses Phinx for install-time migrations. Its CakePHP
        // and League Container stack relies on dynamic PHP patterns outside
        // TypePHP v0.8's bounded type system, so keep this third-party tooling
        // explicit and inspectable instead of silently dropping its features.
        'vendor/cakephp',
        'vendor/league/container',
        'vendor/robmorgan/phinx',
        'vendor/symfony/cache',
        'vendor/symfony/clock/Resources',
        'vendor/symfony/config',
        'vendor/symfony/console/Attribute',
        'vendor/symfony/console/Command/InvokableCommand.php',
        'vendor/symfony/console/Command/TraceableCommand.php',
        'vendor/symfony/console/Completion',
        'vendor/symfony/console/DataCollector',
        'vendor/symfony/error-handler',
        'vendor/symfony/finder',
        'vendor/symfony/http-foundation/Session/Storage/Handler/IdentityMarshaller.php',
        'vendor/symfony/http-foundation/Session/Storage/Handler/MigratingSessionHandler.php',
        'vendor/symfony/http-kernel/DataCollector',
        'vendor/symfony/http-kernel/Fragment/InlineFragmentRenderer.php',
        'vendor/symfony/http-kernel/Kernel.php',
        'vendor/symfony/mime/Crypto',
        'vendor/symfony/mime/Part',
        'vendor/symfony/polyfill-intl-normalizer',
        'vendor/symfony/process',
        'vendor/symfony/string',
        'vendor/symfony/translation/Util/ArrayConverter.php',
        'vendor/symfony/var-dumper',
        'vendor/symfony/var-exporter',
        'vendor/symfony/translation/DataCollector',
        'vendor/openspout/openspout',
        'vendor/twig/twig',
        'vendor/tinywan/storage',
        // Composer loads this guarded wrapper before ThinkORM classes. Keep
        // the wrapper path in the package, while compiling Exception.php.
        // Facade.php is only a fallback and duplicates think-container here.
        'vendor/topthink/think-orm/stubs/load_stubs.php',
        // ThinkORM ships MongoDB as an optional ext-mongodb driver. Keep the
        // coherent driver unit available at runtime without forcing its
        // reference-heavy query builder through the static compiler.
        'vendor/topthink/think-orm/src/db/Mongo.php',
        'vendor/topthink/think-orm/src/db/builder/Mongo.php',
        'vendor/topthink/think-orm/src/db/connector/Mongo.php',
        'vendor/topthink/think-orm/src/db/builder/Oracle.php',
        'vendor/topthink/think-orm/src/db/builder/Pgsql.php',
        'vendor/topthink/think-orm/src/db/builder/Sqlite.php',
        'vendor/topthink/think-orm/src/db/builder/Sqlsrv.php',
        'vendor/voku/portable-ascii',
        'vendor/webman/channel',
        'vendor/webman/console/src/Commands',
        'vendor/webman/console/src/Messages.php',
        'vendor/webman/console/src/Util.php',
        'vendor/webman/database/src/support/MongoModel.php',
        'vendor/workerman/channel',
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
        'vendor/workerman/coroutine/src/Barrier/BarrierInterface.php' => '.typephp/build/coroutine-barrier-interface.php',
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
        'vendor/workerman/workerman/src/Timer.php' => '.typephp/build/workerman-timer.php',
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
        'app/process/Monitor.php' => '.typephp/build/app-process-monitor.php',
        'vendor/godruoyi/php-snowflake/src/Snowflake.php' => '.typephp/build/godruoyi-snowflake.php',
        'vendor/godruoyi/php-snowflake/src/Sonyflake.php' => '.typephp/build/godruoyi-sonyflake.php',
        'vendor/guzzlehttp/guzzle/src/Handler/CurlMultiHandler.php' => '.typephp/build/guzzle-curl-multi-handler.php',
        'vendor/workerman/workerman/src/Protocols/Websocket.php' => '.typephp/build/workerman-websocket.php',
        'vendor/zoujingli/ip2region/XdbSearcher.php' => '.typephp/build/ip2region-xdb-searcher.php',
        'vendor/zoujingli/ip2region/src/ip2region/xdb/Util.php' => '.typephp/build/ip2region-v3-util.php',
        'vendor/workerman/webman-framework/src/App.php' => '.typephp/build/webman-app.php',
        'vendor/workerman/webman-framework/src/Config.php' => '.typephp/build/webman-config.php',
        'vendor/webman/captcha/src/CaptchaBuilder.php' => '.typephp/build/webman-captcha-builder.php',
        'vendor/monolog/monolog/src/Monolog/Utils.php' => '.typephp/build/monolog-utils.php',
        'vendor/monolog/monolog/src/Monolog/Handler/BrowserConsoleHandler.php' => '.typephp/build/monolog-browser-console-handler.php',
        'vendor/nelexa/zip/src/IO/Filter/Cipher/Pkware/PKCryptContext.php' => '.typephp/build/nelexa-pkcrypt-context.php',
        'vendor/nelexa/zip/src/Model/ZipEntry.php' => '.typephp/build/nelexa-zip-entry.php',
        'vendor/nelexa/zip/src/ZipFile.php' => '.typephp/build/nelexa-zip-file.php',
        'vendor/nelexa/zip/src/Util/FilesUtil.php' => '.typephp/build/nelexa-files-util.php',
        'vendor/nelexa/zip/src/IO/Stream/ResponseStream.php' => '.typephp/build/nelexa-response-stream.php',
        'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php' => '.typephp/build/carbon-interval.php',
        'vendor/nesbot/carbon/src/Carbon/CarbonTimeZone.php' => '.typephp/build/carbon-time-zone.php',
        'vendor/nesbot/carbon/lazy/Carbon/MessageFormatter/MessageFormatterMapperStrongType.php' => '.typephp/build/carbon-message-formatter-strong.php',
        'vendor/nesbot/carbon/lazy/Carbon/TranslatorStrongType.php' => '.typephp/build/carbon-translator-strong.php',
        'vendor/nesbot/carbon/src/Carbon/MessageFormatter/MessageFormatterMapper.php' => '.typephp/build/carbon-message-formatter.php',
        'vendor/nesbot/carbon/src/Carbon/Translator.php' => '.typephp/build/carbon-translator.php',
        'vendor/phpmailer/phpmailer/src/PHPMailer.php' => '.typephp/build/phpmailer.php',
        'plugin/saiadmin/utils/Captcha.php' => '.typephp/build/saiadmin-captcha.php',
        'plugin/saiadmin/utils/code/CodeEngine.php' => '.typephp/build/saiadmin-code-engine.php',
        'plugin/saiadmin/app/controller/LoginController.php' => '.typephp/build/saiadmin-login-controller.php',
        'plugin/saiadmin/app/controller/InstallController.php' => '.typephp/build/saiadmin-install-controller.php',
        'plugin/saiadmin/app/controller/SystemController.php' => '.typephp/build/saiadmin-system-controller.php',
        'plugin/saiadmin/app/controller/system/SystemPostController.php' => '.typephp/build/saiadmin-system-post-controller.php',
        'plugin/saiadmin/app/controller/system/DataBaseController.php' => '.typephp/build/saiadmin-database-controller.php',
        'plugin/saiadmin/app/cache/ReflectionCache.php' => '.typephp/build/saiadmin-reflection-cache.php',
        'plugin/saiadmin/app/cache/UserAuthCache.php' => '.typephp/build/saiadmin-user-auth-cache.php',
        'plugin/saiadmin/app/cache/UserInfoCache.php' => '.typephp/build/saiadmin-user-info-cache.php',
        'plugin/saiadmin/exception/SystemException.php' => '.typephp/build/saiadmin-system-exception.php',
        'vendor/ramsey/collection/src/DoubleEndedQueue.php' => '.typephp/build/ramsey-double-ended-queue.php',
        'vendor/ramsey/uuid/src/Converter/Time/PhpTimeConverter.php' => '.typephp/build/ramsey-php-time-converter.php',
        'vendor/topthink/think-orm/src/model/Collection.php' => '.typephp/build/think-orm-model-collection.php',
        'vendor/saithink/saipackage/src/service/Filesystem.php' => '.typephp/build/saipackage-filesystem.php',
        'vendor/saithink/saipackage/src/service/Terminal.php' => '.typephp/build/saipackage-terminal.php',
        'vendor/saithink/saipackage/src/service/Version.php' => '.typephp/build/saipackage-version.php',
        'vendor/symfony/console/Attribute/AsCommand.php' => '.typephp/build/symfony-console-as-command.php',
        'vendor/symfony/console/Formatter/OutputFormatter.php' => '.typephp/build/symfony-console-output-formatter.php',
        'vendor/symfony/console/Helper/ProgressIndicator.php' => '.typephp/build/symfony-console-progress-indicator.php',
        'vendor/symfony/console/Helper/QuestionHelper.php' => '.typephp/build/symfony-console-question-helper.php',
        'vendor/symfony/console/Output/AnsiColorMode.php' => '.typephp/build/symfony-console-ansi-color-mode.php',
        'vendor/symfony/console/Output/ConsoleSectionOutput.php' => '.typephp/build/symfony-console-section-output.php',
        'vendor/symfony/console/Style/SymfonyStyle.php' => '.typephp/build/symfony-console-symfony-style.php',
        'vendor/symfony/event-dispatcher/EventDispatcherInterface.php' => '.typephp/build/symfony-event-dispatcher-interface.php',
        'vendor/symfony/event-dispatcher/EventDispatcher.php' => '.typephp/build/symfony-event-dispatcher.php',
        'vendor/symfony/http-foundation/AcceptHeaderItem.php' => '.typephp/build/symfony-http-foundation-accept-header-item.php',
        'vendor/symfony/http-foundation/BinaryFileResponse.php' => '.typephp/build/symfony-http-foundation-binary-file-response.php',
        'vendor/symfony/http-foundation/HeaderUtils.php' => '.typephp/build/symfony-http-foundation-header-utils.php',
        'vendor/symfony/http-foundation/IpUtils.php' => '.typephp/build/symfony-http-foundation-ip-utils.php',
        'vendor/symfony/http-foundation/RequestStack.php' => '.typephp/build/symfony-http-foundation-request-stack.php',
        'vendor/symfony/http-foundation/ResponseHeaderBag.php' => '.typephp/build/symfony-http-foundation-response-header-bag.php',
        'vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php' => '.typephp/build/symfony-http-foundation-pdo-session-handler.php',
        'vendor/symfony/http-foundation/UriSigner.php' => '.typephp/build/symfony-http-foundation-uri-signer.php',
        'vendor/symfony/http-kernel/Controller/ControllerResolver.php' => '.typephp/build/symfony-http-kernel-controller-resolver.php',
        'vendor/symfony/http-kernel/EventListener/ErrorListener.php' => '.typephp/build/symfony-http-kernel-error-listener.php',
        'vendor/symfony/http-kernel/EventListener/SessionListener.php' => '.typephp/build/symfony-http-kernel-session-listener.php',
        'vendor/symfony/http-kernel/HttpKernelInterface.php' => '.typephp/build/symfony-http-kernel-interface.php',
        'vendor/symfony/mime/Header/ParameterizedHeader.php' => '.typephp/build/symfony-mime-parameterized-header.php',
        'vendor/symfony/polyfill-php80/Php80.php' => '.typephp/build/symfony-php80.php',
        'vendor/symfony/translation/PseudoLocalizationTranslator.php' => '.typephp/build/symfony-pseudo-localization-translator.php',
        'vendor/illuminate/bus/Batch.php' => '.typephp/build/illuminate-bus-batch.php',
        'vendor/illuminate/collections/Enumerable.php' => '.typephp/build/illuminate-collections-enumerable.php',
        'vendor/illuminate/collections/Traits/EnumeratesValues.php' => '.typephp/build/illuminate-collections-enumerates-values.php',
        'vendor/illuminate/contracts/Support/Arrayable.php' => '.typephp/build/illuminate-contracts-arrayable.php',
        'vendor/illuminate/database/Connectors/PostgresConnector.php' => '.typephp/build/illuminate-database-postgres-connector.php',
        'vendor/illuminate/database/Query/Grammars/PostgresGrammar.php' => '.typephp/build/illuminate-database-postgres-grammar.php',
        'vendor/illuminate/database/Schema/Blueprint.php' => '.typephp/build/illuminate-database-schema-blueprint.php',
        'vendor/illuminate/database/Eloquent/Casts/ArrayObject.php' => '.typephp/build/illuminate-eloquent-array-object.php',
        'vendor/illuminate/filesystem/Filesystem.php' => '.typephp/build/illuminate-filesystem.php',
        'vendor/illuminate/http/Client/Response.php' => '.typephp/build/illuminate-http-client-response.php',
        'vendor/symfony/http-foundation/Request.php' => '.typephp/build/symfony-http-foundation-request.php',
        'vendor/symfony/http-foundation/File/UploadedFile.php' => '.typephp/build/symfony-http-foundation-uploaded-file.php',
        'vendor/illuminate/redis/RedisManager.php' => '.typephp/build/illuminate-redis-manager.php',
        'vendor/illuminate/support/Facades/Password.php' => '.typephp/build/illuminate-support-password-facade.php',
        'vendor/illuminate/support/MultipleInstanceManager.php' => '.typephp/build/illuminate-support-multiple-instance-manager.php',
        'vendor/illuminate/support/Sleep.php' => '.typephp/build/illuminate-support-sleep.php',
        'vendor/illuminate/support/Str.php' => '.typephp/build/illuminate-support-str.php',
        'vendor/nesbot/carbon/src/Carbon/CarbonInterface.php' => '.typephp/build/carbon-interface.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Creator.php' => '.typephp/build/carbon-traits-creator.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Date.php' => '.typephp/build/carbon-traits-date.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Boundaries.php' => '.typephp/build/carbon-traits-boundaries.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Comparison.php' => '.typephp/build/carbon-traits-comparison.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Converter.php' => '.typephp/build/carbon-traits-converter.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Difference.php' => '.typephp/build/carbon-traits-difference.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Mixin.php' => '.typephp/build/carbon-traits-mixin.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Options.php' => '.typephp/build/carbon-traits-options.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Localization.php' => '.typephp/build/carbon-traits-localization.php',
        'vendor/nesbot/carbon/src/Carbon/Traits/Rounding.php' => '.typephp/build/carbon-traits-rounding.php',
        'vendor/nesbot/carbon/lazy/Carbon/UnprotectedDatePeriod.php' => '.typephp/build/carbon-date-period-base.php',
        'vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php' => '.typephp/build/carbon-period.php',
        'vendor/illuminate/database/Eloquent/Concerns/HasAttributes.php' => '.typephp/build/illuminate-eloquent-has-attributes.php',
        'vendor/illuminate/database/Eloquent/Concerns/QueriesRelationships.php' => '.typephp/build/illuminate-eloquent-queries-relationships.php',
        'vendor/illuminate/database/Eloquent/Relations/HasOne.php' => '.typephp/build/illuminate-eloquent-has-one.php',
        'vendor/illuminate/database/Eloquent/Relations/MorphOne.php' => '.typephp/build/illuminate-eloquent-morph-one.php',
        'vendor/illuminate/database/Eloquent/Model.php' => '.typephp/build/illuminate-eloquent-model.php',
        'vendor/illuminate/database/Connection.php' => '.typephp/build/illuminate-database-connection.php',
        'vendor/illuminate/pagination/Cursor.php' => '.typephp/build/illuminate-pagination-cursor.php',
        'vendor/illuminate/pagination/CursorPaginator.php' => '.typephp/build/illuminate-pagination-cursor-paginator.php',
        'vendor/illuminate/pagination/LengthAwarePaginator.php' => '.typephp/build/illuminate-pagination-length-aware-paginator.php',
        'vendor/illuminate/pagination/Paginator.php' => '.typephp/build/illuminate-pagination-paginator.php',
        'vendor/illuminate/support/DefaultProviders.php' => '.typephp/build/illuminate-support-default-providers.php',
        'vendor/illuminate/support/Fluent.php' => '.typephp/build/illuminate-support-fluent.php',
        'vendor/illuminate/support/InteractsWithTime.php' => '.typephp/build/illuminate-support-interacts-with-time.php',
        'vendor/illuminate/support/MessageBag.php' => '.typephp/build/illuminate-support-message-bag.php',
        'vendor/illuminate/support/UriQueryString.php' => '.typephp/build/illuminate-support-uri-query-string.php',
        'vendor/illuminate/support/ValidatedInput.php' => '.typephp/build/illuminate-support-validated-input.php',
        'vendor/symfony/cache/Traits/AbstractAdapterTrait.php' => '.typephp/build/symfony-cache-abstract-adapter-trait.php',
        'vendor/symfony/cache/Traits/ValueWrapper.php' => '.typephp/build/symfony-cache-value-wrapper.php',
        'vendor/symfony/cache/CacheItem.php' => '.typephp/build/symfony-cache-item.php',
        'vendor/webman/database/src/support/Model.php' => '.typephp/build/webman-database-model.php',
        'vendor/webman/database/src/support/Db.php' => '.typephp/build/webman-database-db.php',
        'vendor/webman/database/src/Initializer.php' => '.typephp/build/webman-database-initializer.php',
        'vendor/webman/database/src/DatabaseManager.php' => '.typephp/build/webman-database-manager.php',
        'vendor/webman/think-orm/src/Initializer.php' => '.typephp/build/webman-think-orm-initializer.php',
        'vendor/webman/think-orm/src/DbManager.php' => '.typephp/build/webman-think-orm-db-manager.php',
        'vendor/topthink/think-orm/src/Model.php' => '.typephp/build/think-orm-model.php',
        'vendor/topthink/think-orm/src/model/concern/Attribute.php' => '.typephp/build/think-orm-attribute.php',
        'vendor/topthink/think-orm/src/model/concern/Conversion.php' => '.typephp/build/think-orm-conversion.php',
        'vendor/topthink/think-orm/src/model/concern/RelationShip.php' => '.typephp/build/think-orm-relation-ship.php',
        'vendor/topthink/think-orm/src/model/concern/TimeStamp.php' => '.typephp/build/think-orm-time-stamp.php',
        'vendor/topthink/think-validate/src/Validate.php' => '.typephp/build/think-validate.php',
        'vendor/topthink/think-orm/src/db/BaseQuery.php' => '.typephp/build/think-orm-base-query.php',
        'vendor/topthink/think-orm/src/db/Builder.php' => '.typephp/build/think-orm-builder.php',
        'vendor/topthink/think-orm/src/db/concern/WhereQuery.php' => '.typephp/build/think-orm-where-query.php',
        'vendor/topthink/think-orm/src/db/Connection.php' => '.typephp/build/think-orm-connection.php',
        'vendor/topthink/think-orm/src/db/concern/ModelRelationQuery.php' => '.typephp/build/think-orm-model-relation-query.php',
        'vendor/topthink/think-orm/src/db/concern/ResultOperation.php' => '.typephp/build/think-orm-result-operation.php',
        'vendor/topthink/think-orm/src/db/Fetch.php' => '.typephp/build/think-orm-fetch.php',
        'vendor/topthink/think-orm/src/db/PDOConnection.php' => '.typephp/build/think-orm-pdo-connection.php',
        'vendor/brick/math/src/BigInteger.php' => '.typephp/build/brick-math-big-integer.php',
        'vendor/brick/math/src/Internal/Calculator.php' => '.typephp/build/brick-math-calculator.php',
        'vendor/brick/math/src/Internal/Calculator/NativeCalculator.php' => '.typephp/build/brick-math-native-calculator.php',
        'vendor/guzzlehttp/psr7/src/Query.php' => '.typephp/build/guzzle-psr7-query.php',
        'vendor/guzzlehttp/psr7/src/StreamWrapper.php' => '.typephp/build/guzzle-psr7-stream-wrapper.php',
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
        'vendor/workerman/coroutine/src/Parallel.php' => '.typephp/build/coroutine-parallel.php',
        'vendor/workerman/coroutine/src/Channel/Fiber.php' => '.typephp/build/coroutine-channel-fiber.php',
        'vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php' => '.typephp/build/guzzle-curl-factory.php',
        'vendor/guzzlehttp/guzzle/src/Handler/StreamHandler.php' => '.typephp/build/guzzle-stream-handler.php',
        'vendor/guzzlehttp/guzzle/src/MessageFormatter.php' => '.typephp/build/guzzle-message-formatter.php',
        'vendor/guzzlehttp/guzzle/src/Pool.php' => '.typephp/build/guzzle-pool.php',
        'vendor/guzzlehttp/promises/src/Utils.php' => '.typephp/build/guzzle-promises-utils.php',
        'vendor/illuminate/collections/Arr.php' => '.typephp/build/illuminate-collections-arr.php',
        'vendor/illuminate/collections/Collection.php' => '.typephp/build/illuminate-collections-collection.php',
        'vendor/illuminate/collections/LazyCollection.php' => '.typephp/build/illuminate-collections-lazy-collection.php',
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
            'public static function wait(object &$barrier, int $timeout = -1): void'
            => 'public static function wait(mixed &$barrier, int $timeout = -1): void',
            '        $resumed = false;' . "\r\n"
            . '        $timerId = null;' . "\r\n"
            => '        $timerId = null;' . "\r\n",
            'function() use ($coroutine, &$resumed, &$timerId) {'
            => 'function() use ($coroutine, &$resumed, $timerId) {',
        ],
        'vendor/workerman/coroutine/src/Parallel.php' => [
            '        $barrier = Barrier::create();'
            => '        $barrierState = new \stdClass();' . "\n"
                . '        $barrierState->value = Barrier::create();',
            '            $barrierRef->value = $barrier;'
            => '            $barrierRef->value = $barrierState->value;',
            '        Barrier::wait($barrier);'
            => '        Barrier::wait($barrierState->value);',
        ],
        // Channel/Fiber 的 push()/pop()：&$timedOut 无外层赋值，删除初始化即为
        // REF 槽；$timerId 无引用捕获、且需保证条件赋值路径上已定义，保留 null 初始化。
        'vendor/workerman/coroutine/src/Channel/Fiber.php' => [
            '            $timedOut = false;' . "\r\n"
            . '            $timerId = null;' . "\r\n"
            => '            $timerId = null;' . "\r\n",
        ],
        'vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php' => [
            "        \$startingResponse = false;\n        \$collectingTrailers = false;\n"
            => '',
            "        static \$options = null;\n\n        if (\$options !== null) {\n            return \$options;\n        }\n\n"
            => '',
        ],
        'vendor/guzzlehttp/guzzle/src/Handler/StreamHandler.php' => [
            "        \$errors = [];\n        \\set_error_handler"
            => '        \set_error_handler',
            "            \\restore_error_handler();\n        }\n\n        if (!\$resource) {"
            => "            \\restore_error_handler();\n        }\n\n"
                . "        if (!isset(\$errors)) {\n            \$errors = [];\n        }\n\n"
                . "        if (!\$resource) {",
        ],
        'vendor/guzzlehttp/guzzle/src/MessageFormatter.php' => [
            "        \$cache = [];\n\n        \$result = \\preg_replace_callback("
            => '        $result = \preg_replace_callback(',
        ],
        'vendor/guzzlehttp/guzzle/src/Pool.php' => [
            'private static function cmpCallback(array &$options, string $name, array &$results): void'
            => 'private static function cmpCallback(array &$options, string $name, mixed &$results): void',
        ],
        'vendor/guzzlehttp/promises/src/Utils.php' => [
            "        \$results = [];\n        \$promise = Each::of("
            => '        $promise = Each::of(',
            "        \$results = [];\n        \$rejections = [];\n\n        return Each::of("
            => '        return Each::of(',
            "        \$results = [];\n\n        return Each::of("
            => '        return Each::of(',
            "        )->then(function () use (&\$results) {\n            ksort(\$results);"
            => "        )->then(function () use (&\$results) {\n"
                . "            if (!isset(\$results)) {\n                \$results = [];\n            }\n"
                . '            ksort($results);',
            "            function () use (&\$results, &\$rejections, \$count) {\n"
            . "                if (count(\$results) !== \$count) {"
            => "            function () use (&\$results, &\$rejections, \$count) {\n"
                . "                if (!isset(\$results)) {\n                    \$results = [];\n                }\n"
                . "                if (!isset(\$rejections)) {\n                    \$rejections = [];\n                }\n"
                . "                if (count(\$results) !== \$count) {",
            'use ($recursive, &$promises)' => 'use ($recursive, $promises)',
        ],
        'vendor/illuminate/collections/Arr.php' => [
            "        \$results = [];\n\n        \$flatten = function"
            => '        $flatten = function',
            '        $flatten = function ($data, $prefix, $currentDepth) use (&$results, &$flatten, $depth): void {'
            => '        $flatten = function ($data, $prefix, $currentDepth, $recurse) use (&$results, $depth): void {',
            "                    \$flatten(\$value, \$newKey.'.', \$currentDepth + 1);"
            => "                    \$recurse(\$value, \$newKey.'.', \$currentDepth + 1, \$recurse);",
            '        $flatten($array, $prepend, 0);'
            => '        $flatten($array, $prepend, 0, $flatten);',
            "        \$flatten = null;\n\n        return \$results;"
            => "        unset(\$flatten);\n\n"
                . "        if (!isset(\$results)) {\n            \$results = [];\n        }\n\n"
                . '        return $results;',
        ],
        'vendor/illuminate/collections/Collection.php' => [
            "        \$exists = [];\n\n        return \$this->reject("
            => '        return $this->reject(',
            'fn () => new static(func_get_args())'
            => 'fn (...$values) => new static($values)',
        ],
        // sliding() 的生成器内部再创建闭包并按引用捕获 $chunk。tap() 会同步
        // 执行回调并返回原集合，故可等价展开为保存窗口、切片、再 yield。
        'vendor/illuminate/collections/LazyCollection.php' => [
            '                    yield (new static($chunk))->tap(function () use (&$chunk, $step) {' . "\n"
            . '                        $chunk = array_slice($chunk, $step, null, true);' . "\n"
            . '                    });'
            => '                    $window = new static($chunk);' . "\n"
                . '                    $chunk = array_slice($chunk, $step, null, true);' . "\n"
                . '                    yield $window;',
        ],
    ];

    /**
     * switch 终结补丁的字面替换规则：源文件 => [搜索串 => 替换串]。
     * 搜索串为 vendor 源码中的精确字面量；未匹配时静默跳过（版本差异容忍）。
     */
    protected const SWITCH_TERMINAL_REPLACEMENTS = [
        'app/process/Monitor.php' => [
            '$iterator = [new SplFileInfo($monitorDir)];'
            => '$iterator = new \ArrayIterator([new SplFileInfo($monitorDir)]);',
            '$this->ppid = function_exists(\'posix_getppid\') ? posix_getppid() : 0;'
            => '$this->ppid = function_exists(\'posix_getppid\') ? (int) posix_getppid() : 0;',
        ],
        'vendor/godruoyi/php-snowflake/src/Snowflake.php' => [
            'return floor(microtime(true) * 1000) | 0;'
            => 'return (int) floor(microtime(true) * 1000);',
        ],
        'vendor/godruoyi/php-snowflake/src/Sonyflake.php' => [
            '$elapsedTime = floor(($this->getCurrentMillisecond() - $millisecond) / 10) | 0;'
            => '$elapsedTime = (int) floor(($this->getCurrentMillisecond() - $millisecond) / 10);',
            'return floor(($this->getCurrentMillisecond() - $this->getStartTimeStamp()) / 10) | 0;'
            => 'return (int) floor(($this->getCurrentMillisecond() - $this->getStartTimeStamp()) / 10);',
        ],
        'vendor/guzzlehttp/guzzle/src/Handler/CurlMultiHandler.php' => [
            '        static $options = null;'
            . "\n\n"
            . '        if ($options !== null) {'
            . "\n"
            . '            return $options;'
            . "\n"
            . '        }'
            . "\n\n"
            . '        $options = [];'
            => '        $options = [];',
        ],
        'vendor/workerman/workerman/src/Protocols/Websocket.php' => [
            'foreach ($connection->headers as $header) {'
            => 'foreach ($connection->headers as $responseHeader) {',
            'strpbrk($header, "\r\n")'
            => 'strpbrk($responseHeader, "\r\n")',
            "stripos(\$header, 'Sec-WebSocket-Extensions:')"
            => "stripos(\$responseHeader, 'Sec-WebSocket-Extensions:')",
            "stripos(\$header, 'permessage-deflate')"
            => "stripos(\$responseHeader, 'permessage-deflate')",
            '$handshakeMessage .= "$header\r\n";'
            => '$handshakeMessage .= "$responseHeader\r\n";',
        ],
        'vendor/zoujingli/ip2region/XdbSearcher.php' => [
            '            $val = sprintf("%u", $val);'
            => '            return sprintf("%u", $val);',
        ],
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
        'vendor/workerman/webman-framework/src/Config.php' => [
            'if (is_dir($file) ||'
            => 'if ($file->isDir() ||',
            'substr($file, 0, -4)'
            => 'substr((string) $file, 0, -4)',
            '$config = include $file;'
            => '$config = include (string) $file;',
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
        'vendor/monolog/monolog/src/Monolog/Handler/BrowserConsoleHandler.php' => [
            '    protected static $records = [];'
            => '    protected static $records = [];'
                . "\n"
                . '    private static array $aotColors = [\'blue\', \'green\', \'red\', \'magenta\', \'orange\', \'black\', \'grey\'];'
                . "\n"
                . '    private static array $aotLabels = [];',
            '        static $colors = [\'blue\', \'green\', \'red\', \'magenta\', \'orange\', \'black\', \'grey\'];'
            . "\n"
            . '        static $labels = [];'
            => '',
            'function (array $m) use ($string, &$colors, &$labels)'
            => 'function (array $m) use ($string)',
            '$labels[$string] = $colors[count($labels) % count($colors)];'
            => 'self::$aotLabels[$string] = self::$aotColors[count(self::$aotLabels) % count(self::$aotColors)];',
            '$color = $labels[$string];'
            => '$color = self::$aotLabels[$string];',
            '!isset($labels[$string])'
            => '!isset(self::$aotLabels[$string])',
        ],
        'vendor/nelexa/zip/src/IO/Filter/Cipher/Pkware/PKCryptContext.php' => [
            '        $byte = 0;'
            . "\n\n"
            . '        foreach (unpack(\'C*\', $header) as $byte) {'
            . "\n"
            . '            $byte = ($byte ^ $this->decryptByte()) & 0xFF;'
            . "\n"
            . '            $this->updateKeys($byte);'
            . "\n"
            . '        }'
            . "\n\n"
            . '        if ($byte !== $checkByte) {'
            => '        $lastByte = 0;'
                . "\n\n"
                . '        foreach (unpack(\'C*\', $header) as $encryptedByte) {'
                . "\n"
                . '            $lastByte = ($encryptedByte ^ $this->decryptByte()) & 0xFF;'
                . "\n"
                . '            $this->updateKeys($lastByte);'
                . "\n"
                . '        }'
                . "\n\n"
                . '        if ($lastByte !== $checkByte) {',
        ],
        'vendor/nelexa/zip/src/Model/ZipEntry.php' => [
            'ZipCompressionLevel::SUPER_FAST' => '1',
            'ZipCompressionLevel::FAST' => '2',
            'ZipCompressionLevel::NORMAL' => '5',
            'ZipCompressionLevel::MAXIMUM' => '9',
            'ZipCompressionLevel::LEVEL_MIN' => '1',
            'ZipCompressionLevel::LEVEL_MAX' => '9',
        ],
        'vendor/nelexa/zip/src/ZipFile.php' => [
            'ZipCompressionLevel::NORMAL' => '5',
            'foreach ($lastModDirs as $dir => $lastMod) {'
            . "\n"
            . '            touch($dir, $lastMod);'
            => 'foreach ($lastModDirs as $lastModDir => $lastMod) {'
                . "\n"
                . '            touch($lastModDir, $lastMod);',
        ],
        'vendor/nelexa/zip/src/Util/FilesUtil.php' => [
            '        if ($recursive) {'
            . "\n"
            . '            $directoryIterator = new \RecursiveDirectoryIterator($folder);'
            . "\n"
            . '            $iterator = new \RecursiveIteratorIterator($directoryIterator);'
            . "\n"
            . '        } else {'
            . "\n"
            . '            $directoryIterator = new \DirectoryIterator($folder);'
            . "\n"
            . '            $iterator = new \IteratorIterator($directoryIterator);'
            . "\n"
            . '        }'
            . "\n\n"
            . '        $regexIterator = new \RegexIterator($iterator, $pattern, \RegexIterator::MATCH);'
            => '        if ($recursive) {'
                . "\n"
                . '            $recursiveDirectory = new \RecursiveDirectoryIterator($folder);'
                . "\n"
                . '            $recursiveIterator = new \RecursiveIteratorIterator($recursiveDirectory);'
                . "\n"
                . '            $regexIterator = new \RegexIterator($recursiveIterator, $pattern, \RegexIterator::MATCH);'
                . "\n"
                . '        } else {'
                . "\n"
                . '            $flatDirectory = new \DirectoryIterator($folder);'
                . "\n"
                . '            $flatIterator = new \IteratorIterator($flatDirectory);'
                . "\n"
                . '            $regexIterator = new \RegexIterator($flatIterator, $pattern, \RegexIterator::MATCH);'
                . "\n"
                . '        }',
        ],
        'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php' => [
            'CarbonInterface::ONE_DAY_WORDS' => '04',
            'CarbonInterface::TWO_DAY_WORDS' => '010',
            'foreach ($interval as $index => &$item) {'
            . "\n"
            . '            $item = $transChoice($item[0], $item[1], $index, $actualParts);'
            => 'foreach ($interval as $partIndex => &$item) {'
                . "\n"
                . '            $item = $transChoice($item[0], $item[1], $partIndex, $actualParts);',
            'foreach ($diffIntervalArray as $index => &$unitData) {'
            => 'foreach ($diffIntervalArray as $skipIndex => &$unitData) {',
            '$nextIndex = $index + 1;'
            => '$nextIndex = $skipIndex + 1;',
            '        $optionalSpace = \' \';'
            . "\n"
            . '        $default = $this->getTranslationMessage(\'list.0\') ?? $this->getTranslationMessage(\'list\') ?? \' \';'
            . "\n"
            . '        /** @var bool|string $join */'
            . "\n"
            . '        $join = $default === \'\' ? \'\' : \' \';'
            . "\n"
            . '        /** @var bool|array|string $altNumbers */'
            . "\n"
            . '        $altNumbers = false;'
            . "\n"
            . '        $aUnit = false;'
            . "\n"
            . '        $minimumUnit = \'s\';'
            . "\n"
            . '        $skip = [];'
            . "\n"
            . '        extract($this->getForHumansInitialVariables($syntax, $short));'
            => '        $humanOptions = $this->getForHumansInitialVariables($syntax, $short);'
                . "\n"
                . '        $optionalSpace = \' \';'
                . "\n"
                . '        $default = $this->getTranslationMessage(\'list.0\') ?? $this->getTranslationMessage(\'list\') ?? \' \';'
                . "\n"
                . '        $join = $humanOptions[\'join\'] ?? ($default === \'\' ? \'\' : \' \');'
                . "\n"
                . '        $altNumbers = $humanOptions[\'altNumbers\'] ?? false;'
                . "\n"
                . '        $aUnit = $humanOptions[\'aUnit\'] ?? false;'
                . "\n"
                . '        $minimumUnit = $humanOptions[\'minimumUnit\'] ?? \'s\';'
                . "\n"
                . '        $skip = $humanOptions[\'skip\'] ?? [];'
                . "\n"
                . '        $syntax = $humanOptions[\'syntax\'] ?? $syntax;'
                . "\n"
                . '        $short = $humanOptions[\'short\'] ?? $short;'
                . "\n"
                . '        $parts = $humanOptions[\'parts\'] ?? $parts;'
                . "\n"
                . '        $options = $humanOptions[\'options\'] ?? $options;'
                . "\n"
                . '        $locale = $humanOptions[\'locale\'] ?? null;'
                . "\n"
                . '        $translator = $humanOptions[\'translator\'] ?? null;',
            '        while ([$part, $value, $unit] = array_shift($parts)) {'
            => '        while ($partValues = array_shift($parts)) {'
                . "\n"
                . '            $part = $partValues[0];'
                . "\n"
                . '            $value = $partValues[1];'
                . "\n"
                . '            $unit = $partValues[2];',
            'foreach ([\'years\', \'months\', \'weeks\', \'days\', \'hours\', \'minutes\', \'seconds\'] as $unit) {'
            . "\n"
            . '            $value = $$unit;'
            => 'foreach ([\'years\' => $years, \'months\' => $months, \'weeks\' => $weeks, \'days\' => $days,'
                . ' \'hours\' => $hours, \'minutes\' => $minutes, \'seconds\' => $seconds] as $unit => $value) {',
            '            foreach ([\'source\', \'target\'] as $key) {'
            . "\n"
            . '                if ($$key === \'dayz\') {'
            . "\n"
            . '                    $$key = \'daysExcludeWeeks\';'
            . "\n"
            . '                }'
            . "\n"
            . '            }'
            => '            if ($source === \'dayz\') {'
                . "\n"
                . '                $source = \'daysExcludeWeeks\';'
                . "\n"
                . '            }'
                . "\n"
                . '            if ($target === \'dayz\') {'
                . "\n"
                . '                $target = \'daysExcludeWeeks\';'
                . "\n"
                . '            }',
        ],
        'vendor/nesbot/carbon/src/Carbon/CarbonTimeZone.php' => [
            '        return Carbon::now($this);'
            => '        return Carbon::now($this->getName());',
        ],
        'vendor/nesbot/carbon/lazy/Carbon/MessageFormatter/MessageFormatterMapperStrongType.php' => [
            'if (!class_exists(LazyMessageFormatter::class, false)) {'
            . "\n"
            . '    abstract class LazyMessageFormatter implements MessageFormatterInterface'
            => 'abstract class LazyMessageFormatter implements MessageFormatterInterface',
            "        }\n    }\n}" => "        }\n}",
        ],
        'vendor/nesbot/carbon/lazy/Carbon/TranslatorStrongType.php' => [
            'if (!class_exists(LazyTranslator::class, false)) {'
            . "\n"
            . '    class LazyTranslator extends AbstractTranslator implements TranslatorStrongTypeInterface'
            => 'class LazyTranslator extends AbstractTranslator implements TranslatorStrongTypeInterface',
            "        }\n    }\n}" => "        }\n}",
        ],
        'vendor/nesbot/carbon/src/Carbon/MessageFormatter/MessageFormatterMapper.php' => [
            '// @codeCoverageIgnoreStart'
            . "\n"
            . '$transMethod = new ReflectionMethod(MessageFormatterInterface::class, \'format\');'
            . "\n\n"
            . 'require $transMethod->getParameters()[0]->hasType()'
            . "\n"
            . '    ? __DIR__.\'/../../../lazy/Carbon/MessageFormatter/MessageFormatterMapperStrongType.php\''
            . "\n"
            . '    : __DIR__.\'/../../../lazy/Carbon/MessageFormatter/MessageFormatterMapperWeakType.php\';'
            . "\n"
            . '// @codeCoverageIgnoreEnd'
            => '',
        ],
        'vendor/nesbot/carbon/src/Carbon/Translator.php' => [
            '$transMethod = new ReflectionMethod('
            . "\n"
            . '    class_exists(TranslatorInterface::class)'
            . "\n"
            . '        ? TranslatorInterface::class'
            . "\n"
            . '        : Translation\Translator::class,'
            . "\n"
            . '    \'trans\','
            . "\n"
            . ');'
            . "\n\n"
            . 'require $transMethod->hasReturnType()'
            . "\n"
            . '    ? __DIR__.\'/../../lazy/Carbon/TranslatorStrongType.php\''
            . "\n"
            . '    : __DIR__.\'/../../lazy/Carbon/TranslatorWeakType.php\';'
            => '',
        ],
        'vendor/phpmailer/phpmailer/src/PHPMailer.php' => [
            '            $encoding = false;'
            => '            $encoding = \'\';',
            '        if ($this->has8bitChars(substr($address, ++$pos))) {'
            => '        if ($this->has8bitChars(substr($address, $pos + 1))) {',
        ],
        'vendor/phpmailer/phpmailer/src/SMTP.php' => [
            '                if (!$n) {'
            . "\n"
            . '                    $name = $type;'
            . "\n"
            . '                    $fields = $fields[0];'
            . "\n"
            . '                } else {'
            . "\n"
            . '                    $name = array_shift($fields);'
            . "\n"
            . '                    switch ($name) {'
            . "\n"
            . '                        case \'SIZE\':'
            . "\n"
            . '                            $fields = ($fields ? $fields[0] : 0);'
            . "\n"
            . '                            break;'
            . "\n"
            . '                        case \'AUTH\':'
            . "\n"
            . '                            if (!is_array($fields)) {'
            . "\n"
            . '                                $fields = [];'
            . "\n"
            . '                            }'
            . "\n"
            . '                            break;'
            . "\n"
            . '                        default:'
            . "\n"
            . '                            $fields = true;'
            . "\n"
            . '                    }'
            . "\n"
            . '                }'
            . "\n"
            . '                $this->server_caps[$name] = $fields;'
            => '                if (!$n) {'
                . "\n"
                . '                    $this->server_caps[$type] = $fields[0];'
                . "\n"
                . '                    continue;'
                . "\n"
                . '                }'
                . "\n"
                . '                $name = array_shift($fields);'
                . "\n"
                . '                switch ($name) {'
                . "\n"
                . '                    case \'SIZE\':'
                . "\n"
                . '                        $this->server_caps[$name] = ($fields ? $fields[0] : 0);'
                . "\n"
                . '                        break;'
                . "\n"
                . '                    case \'AUTH\':'
                . "\n"
                . '                        $this->server_caps[$name] = $fields;'
                . "\n"
                . '                        break;'
                . "\n"
                . '                    default:'
                . "\n"
                . '                        $this->server_caps[$name] = true;'
                . "\n"
                . '                }',
        ],
        'vendor/ramsey/collection/src/DoubleEndedQueue.php' => [
            '    public function __construct(private readonly string $queueType, array $data = [])'
            => '    public function __construct(string $queueType, array $data = [])',
            '        parent::__construct($this->queueType, $data);'
            => '        parent::__construct($queueType, $data);',
        ],
        'vendor/ramsey/uuid/src/Converter/Time/PhpTimeConverter.php' => [
            '        $seconds = new IntegerObject($seconds);'
            => '        $secondsValue = new IntegerObject($seconds);',
            '        $microseconds = new IntegerObject($microseconds);'
            => '        $microsecondsValue = new IntegerObject($microseconds);',
            '$seconds->toString()' => '$secondsValue->toString()',
            '$microseconds->toString()' => '$microsecondsValue->toString()',
        ],
        'vendor/topthink/think-orm/src/model/Collection.php' => [
            'function (Model $model)' => 'function (Model $model, int|string $key)',
        ],
        'plugin/saiadmin/utils/Captcha.php' => [
            '        $captcha->setBackgroundColor(242, 243, 245);'
            => '        $captcha->setBackgroundColor(242, 243, 245);' . "\n"
                . '        $captcha->setLineColor(180, 180, 180);',
            '        $captcha->build(120, 36);'
            => '        $captcha->build(120, 36, base_path(\'vendor/webman/captcha/src/Font/captcha0.ttf\'));',
        ],
        'plugin/saiadmin/app/controller/LoginController.php' => [
            '    public function captcha() : Response'
            => '    public function captcha(Request $request) : Response',
        ],
        'plugin/saiadmin/app/controller/InstallController.php' => [
            '    public function index()'
            => '    public function index(Request $request)',
        ],
        'plugin/saiadmin/app/controller/SystemController.php' => [
            '    public function userInfo(): Response' => '    public function userInfo(Request $request): Response',
            '    public function dictAll(): Response' => '    public function dictAll(Request $request): Response',
            '    public function menu(): Response' => '    public function menu(Request $request): Response',
            '    public function getLoginLogList(): Response' => '    public function getLoginLogList(Request $request): Response',
            '    public function getOperationLogList(): Response' => '    public function getOperationLogList(Request $request): Response',
            '    public function clearAllCache(): Response' => '    public function clearAllCache(Request $request): Response',
            '    public function statistics(): Response' => '    public function statistics(Request $request): Response',
            '    public function loginChart(): Response' => '    public function loginChart(Request $request): Response',
            '    public function loginBarChart(): Response' => '    public function loginBarChart(Request $request): Response',
        ],
        'plugin/saiadmin/app/controller/system/SystemPostController.php' => [
            '    public function downloadTemplate(): Response'
            => '    public function downloadTemplate(Request $request): Response',
        ],
        'plugin/saiadmin/app/controller/system/DataBaseController.php' => [
            '    public function source(): Response'
            => '    public function source(Request $request): Response',
        ],
        'plugin/saiadmin/app/cache/ReflectionCache.php' => [
            '        // 反射逻辑' . "\n"
            . '        if (class_exists($controller)) {' . "\n"
            . '            $ref = new ReflectionClass($controller);' . "\n"
            . '            $data = $ref->getDefaultProperties()[\'noNeedLogin\'] ?? [];' . "\n"
            . '        } else {' . "\n"
            . '            $data = [];' . "\n"
            . '        }'
            => '        // TypePHP v0.8 cannot expose compiled protected defaults through ReflectionClass.' . "\n"
                . '        if ($controller === \plugin\saiadmin\app\controller\LoginController::class) {' . "\n"
                . '            $data = __SAIADMIN_LOGIN_NO_NEED_LOGIN__;' . "\n"
                . '        } elseif ($controller === \plugin\saiadmin\app\controller\InstallController::class) {' . "\n"
                . '            $data = __SAIADMIN_INSTALL_NO_NEED_LOGIN__;' . "\n"
                . '        } elseif (class_exists($controller)) {' . "\n"
                . '            $ref = new ReflectionClass($controller);' . "\n"
                . '            $data = $ref->getDefaultProperties()[\'noNeedLogin\'] ?? [];' . "\n"
                . '        } else {' . "\n"
                . '            $data = [];' . "\n"
                . '        }',
        ],
        'plugin/saiadmin/app/cache/UserAuthCache.php' => [
            '        if (is_array($role_id)) {' . "\n"
            . '            $tags = [];' . "\n"
            . '            foreach ($role_id as $id) {' . "\n"
            . '                $tags[] = $cache[\'role\'] . $id;' . "\n"
            . '            }' . "\n"
            . '        } else {' . "\n"
            . '            $tags = $cache[\'role\'] . $role_id;' . "\n"
            . '        }' . "\n"
            . '        return Cache::tag($tags)->clear();'
            => '        if (is_array($role_id)) {' . "\n"
                . '            $tags = [];' . "\n"
                . '            foreach ($role_id as $id) {' . "\n"
                . '                $tags[] = $cache[\'role\'] . $id;' . "\n"
                . '            }' . "\n"
                . '            return Cache::tag($tags)->clear();' . "\n"
                . '        }' . "\n"
                . '        $tag = $cache[\'role\'] . $role_id;' . "\n"
                . '        return Cache::tag($tag)->clear();',
        ],
        'plugin/saiadmin/exception/SystemException.php' => [
            ', Throwable $previous = null)' => ', ?Throwable $previous = null)',
        ],
        'vendor/nelexa/zip/src/IO/Stream/ResponseStream.php' => [
            '    public function getMetadata($key = null)' . "\n"
            . '    {' => '    public function getMetadata(?string $key = null)' . "\n"
                . '    {',
            '    public function tell()' . "\n"
            . '    {' => '    public function tell(): int' . "\n"
                . '    {',
            '        return $this->stream ? ftell($this->stream) : false;'
            => '        return (int) ($this->stream ? ftell($this->stream) : false);',
            '    public function seek($offset, $whence = \SEEK_SET): void'
            => '    public function seek(int $offset, int $whence = \SEEK_SET): void',
            '    public function write($string)' . "\n"
            . '    {' => '    public function write(string $string): int' . "\n"
                . '    {',
            '        return $this->stream !== null && $this->writable ? fwrite($this->stream, $string) : false;'
            => '        return (int) ($this->stream !== null && $this->writable ? fwrite($this->stream, $string) : false);',
            '    public function read($length): string'
            => '    public function read(int $length): string',
        ],
        'vendor/zoujingli/ip2region/src/ip2region/xdb/Util.php' => [
            '        if ($val < 0 && PHP_INT_SIZE == 4) {' . "\n"
            . '            $val = sprintf("%u", $val);' . "\n"
            . '        }'
            => '        if ($val < 0 && PHP_INT_SIZE == 4) {' . "\n"
                . '            return sprintf("%u", $val);' . "\n"
                . '        }',
        ],
        'vendor/webman/captcha/src/CaptchaBuilder.php' => [
            'return imagecolorat($image, $x, $y);'
            => 'return imagecolorat($image, (int) $x, (int) $y);',
        ],
        'vendor/saithink/saipackage/src/service/Filesystem.php' => [
            '        $path = array_filter(explode(DIRECTORY_SEPARATOR, $path));'
            => '        $pathParts = array_filter(explode(DIRECTORY_SEPARATOR, $path));',
            'for ($i = count($path) - 1;' => 'for ($i = count($pathParts) - 1;',
            'implode(DIRECTORY_SEPARATOR, $path)' => 'implode(DIRECTORY_SEPARATOR, $pathParts)',
            'unset($path[$i]);' => 'unset($pathParts[$i]);',
        ],
        'vendor/saithink/saipackage/src/service/Terminal.php' => [
            '        $data = ['
            . "\n"
            . '            \'data\'   => $data,'
            . "\n"
            . '            \'uuid\'   => $this->uuid,'
            . "\n"
            . '            \'extend\' => $this->extend,'
            . "\n"
            . '            \'key\'    => $this->commandKey,'
            . "\n"
            . '        ];'
            . "\n"
            . '        $data = json_encode($data, JSON_UNESCAPED_UNICODE);'
            . "\n"
            . '        if ($data === false) {'
            . "\n"
            . '            $data = json_encode([\'error\' => \'JSON encode error\'], JSON_UNESCAPED_UNICODE);'
            . "\n"
            . '        }'
            . "\n"
            . '        return $data;'
            => '        $payload = ['
                . "\n"
                . '            \'data\'   => $data,'
                . "\n"
                . '            \'uuid\'   => $this->uuid,'
                . "\n"
                . '            \'extend\' => $this->extend,'
                . "\n"
                . '            \'key\'    => $this->commandKey,'
                . "\n"
                . '        ];'
                . "\n"
                . '        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);'
                . "\n"
                . '        if ($encoded === false) {'
                . "\n"
                . '            return \'{"error":"JSON encode error"}\';'
                . "\n"
                . '        }'
                . "\n"
                . '        return $encoded;',
        ],
        'vendor/saithink/saipackage/src/service/Version.php' => [
            '        $v1 = explode(\'.\', $v1);'
            . "\n"
            . '        $v2 = explode(\'.\', $v2);'
            => '        $v1Parts = explode(\'.\', $v1);'
                . "\n"
                . '        $v2Parts = explode(\'.\', $v2);',
            'count($v1)' => 'count($v1Parts)',
            'count($v2)' => 'count($v2Parts)',
            '$v1[$i]' => '$v1Parts[$i]',
            '$v2[$i]' => '$v2Parts[$i]',
        ],
        'vendor/symfony/console/Attribute/AsCommand.php' => [
            '        $name = explode(\'|\', $name);'
            . "\n"
            . '        $name = array_merge($name, $aliases);'
            => '        $commandNames = explode(\'|\', $name);'
                . "\n"
                . '        $commandNames = array_merge($commandNames, $aliases);',
            '$name[0]' => '$commandNames[0]',
            'array_unshift($name,' => 'array_unshift($commandNames,',
            'implode(\'|\', $name)' => 'implode(\'|\', $commandNames)',
        ],
        'vendor/symfony/console/Formatter/OutputFormatter.php' => [
            '$i = $width - $currentLineLength' => '$chunkWidth = $width - $currentLineLength',
            'Helper::substr($lines[0], $i);' => 'Helper::substr($lines[0], $chunkWidth);',
        ],
        'vendor/symfony/console/Helper/ProgressIndicator.php' => [
            '    public function finish(string $message/* , ?string $finishedIndicator = null */): void'
            => '    public function finish(string $message, ?string $finishedIndicator = null): void',
            '        $finishedIndicator = 1 < \func_num_args() ? func_get_arg(1) : null;'
            . "\n"
            . '        if (null !== $finishedIndicator && !\is_string($finishedIndicator)) {'
            . "\n"
            . '            throw new \TypeError(\sprintf(\'Argument 2 passed to "%s()" must be of the type string or null, "%s" given.\', __METHOD__, get_debug_type($finishedIndicator)));'
            . "\n"
            . '        }'
            . "\n"
            => '',
        ],
        'vendor/symfony/console/Helper/QuestionHelper.php' => [
            '            $ret = false;' => "            \$ret = '';\n            \$hasAnswer = false;",
            '                    $ret = $question->isTrimmable() ? trim($hiddenResponse) : $hiddenResponse;'
            => '                    $ret = $question->isTrimmable() ? trim($hiddenResponse) : $hiddenResponse;'
                . "\n"
                . '                    $hasAnswer = true;',
            '            if (false === $ret) {' => '            if (!$hasAnswer) {',
            '                $ret = $this->readInput($inputStream, $question);'
            => '                $inputAnswer = $this->readInput($inputStream, $question);',
            '                if (false === $ret) {' => '                if (false === $inputAnswer) {',
            '                if ($question->isTrimmable()) {'
            => "                \$ret = \$inputAnswer;\n                if (\$question->isTrimmable()) {",
            '        $ret = \strlen($ret) > 0 ? $ret : $question->getDefault();'
            . "\n"
            . "\n"
            . '        if ($normalizer = $question->getNormalizer()) {'
            . "\n"
            . '            return $normalizer($ret);'
            . "\n"
            . '        }'
            . "\n"
            . "\n"
            . '        return $ret;'
            => '        $answer = \strlen($ret) > 0 ? $ret : $question->getDefault();'
                . "\n"
                . "\n"
                . '        if ($normalizer = $question->getNormalizer()) {'
                . "\n"
                . '            return $normalizer($answer);'
                . "\n"
                . '        }'
                . "\n"
                . "\n"
                . '        return $answer;',
            'mb_strlen($fullChoice, $encoding)'
            => '(int) mb_strlen($fullChoice, $encoding)',
        ],
        'vendor/symfony/console/Output/AnsiColorMode.php' => [
            'return round($b / 255) << 2 | (round($g / 255) << 1) | round($r / 255);'
            => 'return (int) round($b / 255) << 2 | ((int) round($g / 255) << 1) | (int) round($r / 255);',
        ],
        'vendor/symfony/console/Output/ConsoleSectionOutput.php' => [
            '$this->lines -= (int) ceil($this->getDisplayLength($lastLine) / $width) ?: 1;'
            => '$previousLineCount = max(1, (int) ceil($this->getDisplayLength($lastLine) / $width));'
                . "\n"
                . '                $this->lines -= $previousLineCount;',
        ],
        'vendor/symfony/console/Style/SymfonyStyle.php' => [
            '    private TrimmedBufferOutput $bufferedOutput;'
            => "    private TrimmedBufferOutput \$bufferedOutput;\n    private OutputInterface \$styleOutput;",
            '        private OutputInterface $output,' => '        OutputInterface $output,',
            '    ) {'
            . "\n"
            . '        $this->bufferedOutput = new TrimmedBufferOutput'
            => '    ) {'
                . "\n"
                . '        $this->styleOutput = $output;'
                . "\n"
                . '        $this->bufferedOutput = new TrimmedBufferOutput',
            '$this->output' => '$this->styleOutput',
            '        $question = new Question($question, $default);'
            . "\n"
            . '        $question->setValidator($validator);'
            . "\n"
            . "\n"
            . '        return $this->askQuestion($question);'
            => '        $questionObject = new Question($question, $default);'
                . "\n"
                . '        $questionObject->setValidator($validator);'
                . "\n"
                . "\n"
                . '        return $this->askQuestion($questionObject);',
            '        $question = new Question($question);'
            . "\n"
            . "\n"
            . '        $question->setHidden(true);'
            . "\n"
            . '        $question->setValidator($validator);'
            . "\n"
            . "\n"
            . '        return $this->askQuestion($question);'
            => '        $questionObject = new Question($question);'
                . "\n"
                . "\n"
                . '        $questionObject->setHidden(true);'
                . "\n"
                . '        $questionObject->setValidator($validator);'
                . "\n"
                . "\n"
                . '        return $this->askQuestion($questionObject);',
        ],
        'vendor/symfony/event-dispatcher/EventDispatcher.php' => [
            '                $closure = &$this->optimized[$eventName][];'
            . "\n"
            . '                if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {'
            . "\n"
            . '                    $closure = static function (...$args) use (&$listener, &$closure) {'
            . "\n"
            . '                        if ($listener[0] instanceof \Closure) {'
            . "\n"
            . '                            $listener[0] = $listener[0]();'
            . "\n"
            . '                            $listener[1] ??= \'__invoke\';'
            . "\n"
            . '                        }'
            . "\n"
            . '                        ($closure = $listener(...))(...$args);'
            . "\n"
            . '                    };'
            . "\n"
            . '                } else {'
            . "\n"
            . '                    $closure = $listener instanceof WrappedListener ? $listener : $listener(...);'
            . "\n"
            . '                }'
            => '                if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {'
                . "\n"
                . '                    $closure = static function (...$args) use (&$listener) {'
                . "\n"
                . '                        if ($listener[0] instanceof \Closure) {'
                . "\n"
                . '                            $listener[0] = $listener[0]();'
                . "\n"
                . '                            $listener[1] ??= \'__invoke\';'
                . "\n"
                . '                        }'
                . "\n"
                . '                        $listener(...$args);'
                . "\n"
                . '                    };'
                . "\n"
                . '                } else {'
                . "\n"
                . '                    $closure = $listener instanceof WrappedListener ? $listener : $listener(...);'
                . "\n"
                . '                }'
                . "\n"
                . '                $this->optimized[$eventName][] = $closure;',
        ],
        'vendor/symfony/event-dispatcher/EventDispatcherInterface.php' => [
            'public function addListener(string $eventName, callable $listener,'
            => 'public function addListener(string $eventName, callable|array $listener,',
            'public function removeListener(string $eventName, callable $listener)'
            => 'public function removeListener(string $eventName, callable|array $listener)',
            'public function getListenerPriority(string $eventName, callable $listener)'
            => 'public function getListenerPriority(string $eventName, callable|array $listener)',
        ],
        'vendor/symfony/http-foundation/AcceptHeaderItem.php' => [
            '        foreach ($attributes as $name => $value) {'
            . "\n"
            . '            $this->setAttribute($name, $value);'
            => '        foreach ($attributes as $name => $attributeValue) {'
                . "\n"
                . '            $this->setAttribute($name, $attributeValue);',
        ],
        'vendor/symfony/http-foundation/BinaryFileResponse.php' => [
            '$this->maxlen = $end < $fileSize ? $end - $start + 1 : -1;'
            => '$this->maxlen = $end < $fileSize ? (int) ($end - $start + 1) : -1;',
        ],
        'vendor/symfony/http-foundation/HeaderUtils.php' => [
            '        $query = [];'
            . "\n"
            . "\n"
            . '        foreach ($q as $k => $v) {'
            => '        $parsedQuery = [];'
                . "\n"
                . "\n"
                . '        foreach ($q as $encodedKey => $v) {',
            '            if (false !== $i = strpos($k, \'_\')) {'
            => '            if (false !== $i = strpos($encodedKey, \'_\')) {',
            '                $query[substr_replace($k, hex2bin(substr($k, 0, $i)).\'[\', 0, 1 + $i)] = $v;'
            => '                $parsedQuery[substr_replace($encodedKey, hex2bin(substr($encodedKey, 0, $i)).\'[\', 0, 1 + $i)] = $v;',
            '                $query[hex2bin($k)] = $v;'
            => '                $parsedQuery[hex2bin($encodedKey)] = $v;',
            '        return $query;'
            . "\n"
            . '    }'
            . "\n"
            . "\n"
            . '    private static function groupParts'
            => '        return $parsedQuery;'
                . "\n"
                . '    }'
                . "\n"
                . "\n"
                . '    private static function groupParts',
            '        foreach ($partMatches as $matches) {'
            . "\n"
            . '            if (\'\' === $separators && \'\' !== $unquoted = self::unquote($matches[0][0])) {'
            . "\n"
            . '                $parts[] = $unquoted;'
            . "\n"
            . '            } elseif ($groupedParts = self::groupParts($matches, $separators, false)) {'
            => '        foreach ($partMatches as $groupMatches) {'
                . "\n"
                . '            if (\'\' === $separators && \'\' !== $unquoted = self::unquote($groupMatches[0][0])) {'
                . "\n"
                . '                $parts[] = $unquoted;'
                . "\n"
                . '            } elseif ($groupedParts = self::groupParts($groupMatches, $separators, false)) {',
        ],
        'vendor/symfony/http-foundation/IpUtils.php' => [
            '    public static function anonymize(string $ip/* , int $v4Bytes = 1, int $v6Bytes = 8 */): string'
            => '    public static function anonymize(string $ip, int $v4Bytes = 1, int $v6Bytes = 8): string',
            '        $v4Bytes = 1 < \func_num_args() ? func_get_arg(1) : 1;'
            . "\n"
            . '        $v6Bytes = 2 < \func_num_args() ? func_get_arg(2) : 8;'
            . "\n"
            . "\n"
            => '',
        ],
        'vendor/symfony/http-foundation/RequestStack.php' => [
            '        static $resetRequestFormats;'
            . "\n"
            . '        $resetRequestFormats ??= \Closure::bind(static fn () => self::$formats = null, null, Request::class);'
            . "\n"
            . '        $resetRequestFormats();'
            => '        Request::resetFormatsForAot();',
        ],
        'vendor/symfony/http-foundation/ResponseHeaderBag.php' => [
            '    public function clearCookie(string $name, ?string $path = \'/\', ?string $domain = null, bool $secure = false, bool $httpOnly = true, ?string $sameSite = null /* , bool $partitioned = false */): void'
            => '    public function clearCookie(string $name, ?string $path = \'/\', ?string $domain = null, bool $secure = false, bool $httpOnly = true, ?string $sameSite = null, bool $partitioned = false): void',
            '        $partitioned = 6 < \func_num_args() ? func_get_arg(6) : false;'
            => '',
        ],
        'vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php' => [
            '    private function getInsertStatement(#[\SensitiveParameter] string $sessionId, string $sessionData, int $maxlifetime): \PDOStatement'
            . "\n"
            . '    {'
            => '    private function getInsertStatement(#[\SensitiveParameter] string $sessionId, string $sessionData, int $maxlifetime): \PDOStatement'
                . "\n"
                . '    {'
                . "\n"
                . '        $dataHolder = new \stdClass();',
            '    private function getUpdateStatement(#[\SensitiveParameter] string $sessionId, string $sessionData, int $maxlifetime): \PDOStatement'
            . "\n"
            . '    {'
            => '    private function getUpdateStatement(#[\SensitiveParameter] string $sessionId, string $sessionData, int $maxlifetime): \PDOStatement'
                . "\n"
                . '    {'
                . "\n"
                . '        $dataHolder = new \stdClass();',
            '                $data = fopen(\'php://memory\', \'r+\');'
            => '                $dataHolder->value = fopen(\'php://memory\', \'r+\');',
            '                fwrite($data, $sessionData);'
            => '                fwrite($dataHolder->value, $sessionData);',
            '                rewind($data);'
            => '                rewind($dataHolder->value);',
            '                $data = $sessionData;'
            => '                $dataHolder->value = $sessionData;',
            '        $stmt->bindParam(\':data\', $data, \PDO::PARAM_LOB);'
            => '        $stmt->bindParam(\':data\', $dataHolder->value, \PDO::PARAM_LOB);',
        ],
        'vendor/symfony/http-foundation/UriSigner.php' => [
            '    public function sign(string $uri/* , \DateTimeInterface|\DateInterval|int|null $expiration = null */): string'
            => '    public function sign(string $uri, \DateTimeInterface|\DateInterval|int|null $expiration = null): string',
            '        $expiration = null;'
            . "\n"
            . "\n"
            . '        if (1 < \func_num_args()) {'
            . "\n"
            . '            $expiration = func_get_arg(1);'
            . "\n"
            . '        }'
            . "\n"
            . "\n"
            => '',
        ],
        'vendor/symfony/http-kernel/Controller/ControllerResolver.php' => [
            '            $controller = $this->instantiateController($controller);'
            => '            $controllerInstance = $this->instantiateController($controller);',
            '            if (!\is_callable($controller)) {'
            . "\n"
            . '                throw new \InvalidArgumentException($this->getControllerError($controller));'
            . "\n"
            . '            }'
            . "\n"
            . "\n"
            . '            return $controller;'
            => '            if (!\is_callable($controllerInstance)) {'
                . "\n"
                . '                throw new \InvalidArgumentException($this->getControllerError($controllerInstance));'
                . "\n"
                . '            }'
                . "\n"
                . "\n"
                . '            return $controllerInstance;',
            '            $controller = [$this->instantiateController($class), $method];'
            => '            $controllerCallable = [$this->instantiateController($class), $method];',
            '        if (!\is_callable($controller)) {'
            . "\n"
            . '            throw new \InvalidArgumentException($this->getControllerError($controller));'
            . "\n"
            . '        }'
            . "\n"
            . "\n"
            . '        return $controller;'
            => '        if (!\is_callable($controllerCallable)) {'
                . "\n"
                . '            throw new \InvalidArgumentException($this->getControllerError($controllerCallable));'
                . "\n"
                . '        }'
                . "\n"
                . "\n"
                . '        return $controllerCallable;',
        ],
        'vendor/symfony/http-kernel/EventListener/ErrorListener.php' => [
            '    protected function logException(\Throwable $exception, string $message, ?string $logLevel = null/* , ?string $logChannel = null */): void'
            => '    protected function logException(\Throwable $exception, string $message, ?string $logLevel = null, ?string $logChannel = null): void',
            '        $logChannel = (3 < \func_num_args() ? func_get_arg(3) : null) ?? $this->resolveLogChannel($exception);'
            => '        $logChannel ??= $this->resolveLogChannel($exception);',
            '        $class = new \ReflectionClass($class);'
            => '        $reflectionClass = new \ReflectionClass($class);',
            '$class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)'
            => '$reflectionClass->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)',
            'class_implements($class->name)'
            => 'class_implements($reflectionClass->name)',
            '        } while ($class = $class->getParentClass());'
            => '        } while ($reflectionClass = $reflectionClass->getParentClass());',
            '                $class = new \ReflectionClass($interface);'
            => '                $interfaceReflection = new \ReflectionClass($interface);',
            '                if ($attributes = $reflectionClass->getAttributes'
            => '                if ($attributes = $interfaceReflection->getAttributes',
        ],
        'vendor/symfony/http-kernel/EventListener/SessionListener.php' => [
            '        private ?ContainerInterface $container = null,'
            => '        private ?ContainerInterface $sessionContainer = null,',
            '        parent::__construct($container, $debug, $sessionOptions);'
            => '        parent::__construct($sessionContainer, $debug, $sessionOptions);',
            '$this->container->'
            => '$this->sessionContainer->',
        ],
        'vendor/symfony/http-kernel/HttpKernelInterface.php' => [
            'int $type = self::MAIN_REQUEST'
            => 'int $type = 1',
        ],
        'vendor/symfony/mime/Header/ParameterizedHeader.php' => [
            'private ?Rfc2231Encoder $encoder'
            => 'private ?Rfc2231Encoder $parameterEncoder',
            '$this->encoder'
            => '$this->parameterEncoder',
        ],
        'vendor/symfony/polyfill-php80/Php80.php' => [
            'switch (preg_last_error()) {'
            => 'switch ((int) preg_last_error()) {',
        ],
        'vendor/symfony/translation/PseudoLocalizationTranslator.php' => [
            'mb_strlen($s, $encoding)'
            => '(int) mb_strlen($s, $encoding)',
        ],
        'vendor/illuminate/bus/Batch.php' => [
            '    public function toArray()' => '    public function toArray(): array',
            '        $count = 0;' => "        \$countState = new \\stdClass();\n        \$countState->value = 0;",
            'map(function ($job) use (&$count) {' => 'map(function ($job) use ($countState) {',
            '$count += count($job);' => '$countState->value = $countState->value + count($job);',
            '$count++;' => '$countState->value = $countState->value + 1;',
            "        });\n\n        \$this->repository->transaction(function () use (\$jobs, \$count) {"
            => "        });\n\n        \$count = \$countState->value;\n\n"
                . '        $this->repository->transaction(function () use ($jobs, $count) {',
        ],
        'vendor/illuminate/collections/Enumerable.php' => [
            '    public function toArray()' => '    public function toArray(): array',
            '    public function unless($value, callable $callback, ?callable $default = null);'
            => '    public function unless($value = null, ?callable $callback = null, ?callable $default = null);',
        ],
        'vendor/illuminate/collections/Traits/EnumeratesValues.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/contracts/Support/Arrayable.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/database/Connectors/PostgresConnector.php' => [
            "        extract(\$config, EXTR_SKIP);\n\n" => '',
            '        $host = isset($host) ? "host={$host};" : \'\';'
            => '        $host = isset($config[\'host\']) ? "host={$config[\'host\']};" : \'\';',
            '        $database = $connect_via_database ?? $database ?? null;'
            => '        $database = $config[\'connect_via_database\'] ?? $config[\'database\'] ?? null;',
            '        $port = $connect_via_port ?? $port ?? null;'
            => '        $port = $config[\'connect_via_port\'] ?? $config[\'port\'] ?? null;',
            '        if (isset($charset)) {' => '        if (isset($config[\'charset\'])) {',
            '            $dsn .= ";client_encoding=\'{$charset}\'";'
            => '            $dsn .= ";client_encoding=\'{$config[\'charset\']}\'";',
            '        if (isset($application_name)) {' => '        if (isset($config[\'application_name\'])) {',
            '            $dsn .= ";application_name=\'".str_replace("\'", "\\\'", $application_name)."\'";'
            => '            $dsn .= ";application_name=\'".str_replace("\'", "\\\'", $config[\'application_name\'])."\'";',
        ],
        'vendor/illuminate/database/Query/Grammars/PostgresGrammar.php' => [
            '    protected function wrapJsonPathAttributes($path)' . "\n"
            . '    {' . "\n"
            . '        $quote = func_num_args() === 2 ? func_get_arg(1) : "\'";'
            => '    protected function wrapJsonPathAttributes($path, $quote = "\'")' . "\n"
                . '    {',
        ],
        'vendor/illuminate/database/Schema/Blueprint.php' => [
            '    public function dropColumn($columns)' => '    public function dropColumn($columns, ...$additionalColumns)',
            '        $columns = is_array($columns) ? $columns : func_get_args();'
            => '        $columns = is_array($columns) ? $columns : array_merge([$columns], $additionalColumns);',
        ],
        'vendor/illuminate/filesystem/Filesystem.php' => [
            '                extract($__data, EXTR_SKIP);'
            => '                if ($__data !== []) {' . "\n"
                . '                    throw new RuntimeException(\'AOT dynamic require does not support injected variables.\');' . "\n"
                . '                }',
        ],
        'vendor/illuminate/http/Client/Response.php' => [
            '    public function throw()' => '    public function throw($callback = null)',
            '        $callback = func_get_args()[0] ?? null;' . "\n\n" => '',
            '    public function throwIf($condition)' => '    public function throwIf($condition, $callback = null)',
            'return value($condition, $this) ? $this->throw(func_get_args()[1] ?? null) : $this;'
            => 'return value($condition, $this) ? $this->throw($callback) : $this;',
        ],
        'vendor/symfony/http-foundation/Request.php' => [
            '        if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST)) {' . "\n"
            . '            $host = $host[0];'
            => '        if ($this->isFromTrustedProxy() && $forwardedHosts = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST)) {' . "\n"
                . '            $host = $forwardedHosts[0];',
            '    public function getFormat(?string $mimeType/* , bool $subtypeFallback = false */): ?string' . "\n"
            . '    {' . "\n"
            . '        $subtypeFallback = 2 <= \func_num_args() ? func_get_arg(1) : false;'
            => '    public function getFormat(?string $mimeType, bool $subtypeFallback = false): ?string' . "\n"
                . '    {',
            '    /**' . "\n"
            . '     * Associates a format with mime types.'
            => '    public static function resetFormatsForAot(): void' . "\n"
                . '    {' . "\n"
                . '        self::$formats = null;' . "\n"
                . '    }' . "\n"
                . "\n"
                . '    /**' . "\n"
                . '     * Associates a format with mime types.',
            '        $_REQUEST = [[]];' . "\n\n"
            . '        foreach (str_split($requestOrder) as $order) {' . "\n"
            . '            $_REQUEST[] = $request[$order];' . "\n"
            . '        }' . "\n\n"
            . '        $_REQUEST = array_merge(...$_REQUEST);'
            => '        $requestParts = [[]];' . "\n\n"
                . '        foreach (str_split($requestOrder) as $order) {' . "\n"
                . '            $requestParts[] = $request[$order];' . "\n"
                . '        }' . "\n\n"
                . '        $_REQUEST = array_merge(...$requestParts);',
        ],
        'vendor/symfony/http-foundation/File/UploadedFile.php' => [
            '        $max = ltrim($size, \'+\');' . "\n"
            . '        if (str_starts_with($max, \'0x\')) {' . "\n"
            . '            $max = \intval($max, 16);' . "\n"
            . '        } elseif (str_starts_with($max, \'0\')) {' . "\n"
            . '            $max = \intval($max, 8);' . "\n"
            . '        } else {' . "\n"
            . '            $max = (int) $max;'
            => '        $maxString = ltrim($size, \'+\');' . "\n"
                . '        if (str_starts_with($maxString, \'0x\')) {' . "\n"
                . '            $max = \intval($maxString, 16);' . "\n"
                . '        } elseif (str_starts_with($maxString, \'0\')) {' . "\n"
                . '            $max = \intval($maxString, 8);' . "\n"
                . '        } else {' . "\n"
                . '            $max = (int) $maxString;',
            '            case \'t\': $max *= 1024;' . "\n"
            . '                // no break' . "\n"
            . '            case \'g\': $max *= 1024;' . "\n"
            . '                // no break' . "\n"
            . '            case \'m\': $max *= 1024;' . "\n"
            . '                // no break' . "\n"
            . '            case \'k\': $max *= 1024;'
            => '            case \'t\': $max *= 1024 * 1024 * 1024 * 1024; break;' . "\n"
                . '            case \'g\': $max *= 1024 * 1024 * 1024; break;' . "\n"
                . '            case \'m\': $max *= 1024 * 1024; break;' . "\n"
                . '            case \'k\': $max *= 1024; break;',
        ],
        'vendor/illuminate/redis/RedisManager.php' => [
            '        $this->customCreators[$driver] = $callback->bindTo($this, $this);'
            => '        throw new \RuntimeException(\'AOT Redis custom driver closure binding is not supported.\');',
        ],
        'vendor/illuminate/support/Facades/Password.php' => [
            'PasswordBroker::RESET_LINK_SENT' => '\'passwords.sent\'',
            'PasswordBroker::PASSWORD_RESET' => '\'passwords.reset\'',
            'PasswordBroker::INVALID_USER' => '\'passwords.user\'',
            'PasswordBroker::INVALID_TOKEN' => '\'passwords.token\'',
            'PasswordBroker::RESET_THROTTLED' => '\'passwords.throttled\'',
        ],
        'vendor/illuminate/support/MultipleInstanceManager.php' => [
            '        $this->customCreators[$name] = $callback->bindTo($this, $this);'
            => '        throw new \RuntimeException(\'AOT custom instance creator closure binding is not supported.\');',
        ],
        'vendor/illuminate/support/Sleep.php' => [
            '            static $return = [true, false];'
            => '            static $return;' . "\n"
                . '            if ($return === null) {' . "\n"
                . '                $return = [true, false];' . "\n"
                . '            }',
        ],
        'vendor/illuminate/support/Str.php' => [
            'return implode(array_reverse(mb_str_split($value)));'
            => 'return implode(\'\', array_reverse(mb_str_split($value)));',
            'return static::$studlyCache[$key] = implode($studlyWords);'
            => 'return static::$studlyCache[$key] = implode(\'\', $studlyWords);',
            '        $start = ltrim($matches[1]);' => '        $startText = ltrim($matches[1]);',
            '        $start = Str::of(mb_substr($start, max(mb_strlen($start, \'UTF-8\') - $radius, 0), $radius, \'UTF-8\'))->ltrim()->unless('
            => '        $start = Str::of(mb_substr($startText, max(mb_strlen($startText, \'UTF-8\') - $radius, 0), $radius, \'UTF-8\'))->ltrim()->unless(',
            '            fn ($startWithRadius) => $startWithRadius->exactly($start),'
            => '            fn ($startWithRadius) => $startWithRadius->exactly($startText),',
            '        $end = rtrim($matches[3]);' => '        $endText = rtrim($matches[3]);',
            '        $end = Str::of(mb_substr($end, 0, $radius, \'UTF-8\'))->rtrim()->unless('
            => '        $end = Str::of(mb_substr($endText, 0, $radius, \'UTF-8\'))->rtrim()->unless(',
            '            fn ($endWithRadius) => $endWithRadius->exactly($end),'
            => '            fn ($endWithRadius) => $endWithRadius->exactly($endText),',
        ],
        'vendor/nesbot/carbon/src/Carbon/CarbonInterface.php' => [
            'int $mode = self::TRANSLATE_ALL' => 'int $mode = TranslationOptions::TRANSLATE_ALL',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Creator.php' => [
            '        $fields = static::getRangesByUnit();'
            => '        $fields = static::getRangesByUnit();' . "\n"
                . '        $fieldValues = [' . "\n"
                . '            \'year\' => $year, \'month\' => $month, \'day\' => $day,' . "\n"
                . '            \'hour\' => $hour, \'minute\' => $minute, \'second\' => $second,' . "\n"
                . '        ];',
            '$$field' => '$fieldValues[$field]',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Date.php' => [
            '                    $value = $operator === \'Of\''
            => '                    $unitValue = $operator === \'Of\'',
            '                    return (int) $value;' => '                    return (int) $unitValue;',
            '$result = $result->subSecond();' => '$result = $result->addUnit(\'second\', -1);',
            '$result = $result->addSecond();' => '$result = $result->addUnit(\'second\', 1);',
            '$result = $result->addDays($value - $this->dayOfYear);'
            => '$result = $result->addUnit(\'day\', $value - $this->dayOfYear);',
            '$result = $result->addDays($value - $this->dayOfWeek);'
            => '$result = $result->addUnit(\'day\', $value - $this->dayOfWeek);',
            '$result = $result->addDays($value - $this->dayOfWeekIso);'
            => '$result = $result->addUnit(\'day\', $value - $this->dayOfWeekIso);',
            '$this->addDays(' => '$this->addUnit(\'day\', ',
            '            $boundMacro = @$macro->bindTo($this, static::class) ?: @$macro->bindTo(null, static::class);'
            => '            throw new \RuntimeException(\'AOT Carbon closure macro binding is not supported.\');',
            '                $boundMacro = @Closure::bind($macro, null, static::class);'
            => '                throw new \RuntimeException(\'AOT Carbon static closure macro binding is not supported.\');',
            'return \call_user_func_array($boundMacro ?: $macro, $parameters);'
            => 'return \call_user_func_array($macro, $parameters);',
            '                $$name = self::monthToInt($value, $name);'
            => '                $normalizedValue = self::monthToInt($value, $name);' . "\n"
                . '                switch ($name) {' . "\n"
                . '                    case \'year\': $year = $normalizedValue; break;' . "\n"
                . '                    case \'month\': $month = $normalizedValue; break;' . "\n"
                . '                    case \'day\': $day = $normalizedValue; break;' . "\n"
                . '                    case \'hour\': $hour = $normalizedValue; break;' . "\n"
                . '                    case \'minute\': $minute = $normalizedValue; break;' . "\n"
                . '                    case \'second\': $second = $normalizedValue; break;' . "\n"
                . '                }',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Boundaries.php' => [
            '->addMonths(' => '->addUnit(\'month\', ',
            '->addDays(' => '->addUnit(\'day\', ',
            '            ->subDays(' . "\n"
            . '                (static::DAYS_PER_WEEK + $this->dayOfWeek - (WeekDay::int($weekStartsAt) ?? $this->firstWeekDay)) %' . "\n"
            . '                static::DAYS_PER_WEEK,' . "\n"
            . '            )'
            => '            ->addUnit(\'day\', -(' . "\n"
                . '                (static::DAYS_PER_WEEK + $this->dayOfWeek - (WeekDay::int($weekStartsAt) ?? $this->firstWeekDay)) %' . "\n"
                . '                static::DAYS_PER_WEEK' . "\n"
                . '            ))',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Comparison.php' => [
            'return $this->{\'isSame\'.ucfirst($unit)}(\'now\');'
            => 'return $this->isSameUnit($unit, \'now\');',
            '$this->isSameYear($date)' => '$this->isSameUnit(\'year\', $date)',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Converter.php' => [
            '!$this->isValid()' => '$this->year === 0',
            'CarbonInterval::day()' => 'new CarbonInterval(0, 0, 0, 1)',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Difference.php' => [
            'CarbonInterval::day()' => 'new CarbonInterval(0, 0, 0, 1)',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Mixin.php' => [
            '                    $downContext->copyProperties($result);' . "\n"
            . '                    self::copyStep($downContext, $result);' . "\n"
            . '                    self::copyNegativeUnits($downContext, $result);'
            => '                    throw new \RuntimeException(' . "\n"
                . '                        \'AOT Carbon interval trait mixin result copying is not supported.\',' . "\n"
                . '                    );',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Options.php' => [
            '$infos[\'date\'] ??= $this->format(CarbonInterface::MOCK_DATETIME_FORMAT);'
            => '$infos[\'date\'] ??= \call_user_func([$this, \'format\'], CarbonInterface::MOCK_DATETIME_FORMAT);',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Localization.php' => [
            '            $language = $$key;'
            => '            $language = $key === \'from\' ? $from : $to;',
            '                        foreach ($$variable as $index => &$name) {' . "\n"
            . '                            $name .= \'|\'.$list[$index];' . "\n"
            . '                        }'
            => '                        if ($variable === \'months\') {' . "\n"
                . '                            foreach ($months as $index => $name) {' . "\n"
                . '                                $months[$index] = $name.\'|\'.$list[$index];' . "\n"
                . '                            }' . "\n"
                . '                        } else {' . "\n"
                . '                            foreach ($weekdays as $index => $name) {' . "\n"
                . '                                $weekdays[$index] = $name.\'|\'.$list[$index];' . "\n"
                . '                            }' . "\n"
                . '                        }',
            '            $$translationKey = array_merge('
            => '            $translatedWords = array_merge(',
            '            );' . "\n"
            . '        }' . "\n"
            . "\n"
            . '        // Make all dots optional'
            => '            );' . "\n"
                . '            if ($key === \'from\') {' . "\n"
                . '                $fromTranslations = $translatedWords;' . "\n"
                . '            } else {' . "\n"
                . '                $toTranslations = $translatedWords;' . "\n"
                . '            }' . "\n"
                . '        }' . "\n"
                . "\n"
                . '        // Make all dots optional',
        ],
        'vendor/nesbot/carbon/src/Carbon/Traits/Rounding.php' => [
            '        foreach ($ranges as $unit => [$minimum, $maximum]) {'
            => '        foreach ($ranges as $rangeUnit => [$minimum, $maximum]) {',
            '            if ($normalizedUnit === $unit) {' => '            if ($normalizedUnit === $rangeUnit) {',
            '                $arguments = [$this->$unit, $minimum];'
            => '                $arguments = [$this->$rangeUnit, $minimum];',
            '                $initialValue = $this->$unit;' => '                $initialValue = $this->$rangeUnit;',
            '                $inc = ($this->$unit - $minimum) * $factor;'
            => '                $inc = ($this->$rangeUnit - $minimum) * $factor;',
            '                $changes[$unit] = round(' => '                $changes[$rangeUnit] = round(',
            '                    $minimum + ($fraction ? $fraction * $function(($this->$unit - $minimum) / $fraction) : 0),'
            => '                    $minimum + ($fraction ? $fraction * $function(($this->$rangeUnit - $minimum) / $fraction) : 0),',
            '                while ($changes[$unit] >= $delta) {' => '                while ($changes[$rangeUnit] >= $delta) {',
            '                    $changes[$unit] -= $delta;' => '                    $changes[$rangeUnit] -= $delta;',
            '        foreach ($changes as $unit => $value) {' => '        foreach ($changes as $changeUnit => $value) {',
            '            $result = $result->$unit($value);' => '            $result = $result->$changeUnit($value);',
        ],
        'vendor/nesbot/carbon/lazy/Carbon/UnprotectedDatePeriod.php' => [
            'if (!class_exists(DatePeriodBase::class, false)) {' . "\n"
            . '    class DatePeriodBase extends DatePeriod' . "\n"
            . '    {' . "\n"
            . '    }' . "\n"
            . '}'
            => 'class DatePeriodBase extends DatePeriod' . "\n"
                . '{' . "\n"
                . '}',
        ],
        'vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php' => [
            'require PHP_VERSION < 8.2' . "\n"
            . '    ? __DIR__.\'/../../lazy/Carbon/ProtectedDatePeriod.php\'' . "\n"
            . '    : __DIR__.\'/../../lazy/Carbon/UnprotectedDatePeriod.php\';'
            => '',
            '\Carbon\CarbonInterval::day()' => 'new \Carbon\CarbonInterval(0, 0, 0, 1)',
            'CarbonInterval::day()' => 'new CarbonInterval(0, 0, 0, 1)',
            'CarbonInterval::month()' => 'new CarbonInterval(0, 1)',
        ],
        'vendor/illuminate/database/Eloquent/Casts/ArrayObject.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/database/Eloquent/Concerns/HasAttributes.php' => [
            '        foreach ($this->getArrayableRelations() as $key => $value) {'
            => "        foreach (\$this->getArrayableRelations() as \$key => \$value) {\n"
                . '            $relationSet = false;',
            '                $relation = $value->toArray();'
            => "                \$relation = \$value->toArray();\n                \$relationSet = true;",
            '                $relation = $value;'
            => "                \$relation = \$value;\n                \$relationSet = true;",
            "            if (array_key_exists('relation', get_defined_vars())) { // check if \$relation is in scope (could be null)"
            => '            if ($relationSet) {',
        ],
        'vendor/illuminate/database/Eloquent/Concerns/QueriesRelationships.php' => [
            '            unset($alias);' => '            $alias = null;',
        ],
        'vendor/illuminate/database/Eloquent/Relations/HasOne.php' => [
            "class HasOne extends HasOneOrMany implements SupportsPartialRelations\n{"
            => "class HasOne extends HasOneOrMany implements SupportsPartialRelations\n{\n"
                . "    public function getParentKey()\n"
                . "    {\n"
                . "        return parent::getParentKey();\n"
                . "    }\n",
        ],
        'vendor/illuminate/database/Eloquent/Relations/MorphOne.php' => [
            "class MorphOne extends MorphOneOrMany implements SupportsPartialRelations\n{"
            => "class MorphOne extends MorphOneOrMany implements SupportsPartialRelations\n{\n"
                . "    public function getParentKey()\n"
                . "    {\n"
                . "        return parent::getParentKey();\n"
                . "    }\n",
        ],
        'vendor/illuminate/database/Eloquent/Model.php' => [
            '    public function toArray()' => '    public function toArray(): array',
            '        $class = $class ?: static::class;'
            => '        if (!$class) {' . "\n"
                . '            $class = static::class;' . "\n"
                . '        }',
        ],
        'vendor/illuminate/pagination/Cursor.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/pagination/CursorPaginator.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/pagination/LengthAwarePaginator.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/pagination/Paginator.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/support/DefaultProviders.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/support/Fluent.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/support/InteractsWithTime.php' => [
            'CarbonInterval::milliseconds($runTime)'
            => 'new CarbonInterval(0, 0, 0, 0, 0, 0, 0, $runTime * 1000)',
        ],
        'vendor/illuminate/support/MessageBag.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/support/UriQueryString.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/support/ValidatedInput.php' => [
            '    public function toArray()' => '    public function toArray(): array',
        ],
        'vendor/illuminate/database/Connection.php' => [
            '    public function setQueryGrammar(Query\Grammars\Grammar $grammar)'
            => '    public function setQueryGrammar(?Query\Grammars\Grammar $grammar)',
        ],
        'vendor/symfony/cache/Traits/AbstractAdapterTrait.php' => [
            '    private function generateItems(iterable $items, array &$keys): \Generator'
            => '    private function generateItems(iterable $items, array $keys): \Generator',
        ],
        'vendor/symfony/cache/Traits/ValueWrapper.php' => [
            "class \xA9" => 'class TypephpSymfonyCacheValueWrapper',
        ],
        'vendor/symfony/cache/CacheItem.php' => [
            '    private const VALUE_WRAPPER = "\xA9";'
            => "    private const VALUE_WRAPPER = 'TypephpSymfonyCacheValueWrapper';",
        ],
        'vendor/webman/database/src/support/Model.php' => [
            "require_once __DIR__ . '/../Initializer.php';\n" => '',
        ],
        'vendor/webman/database/src/support/Db.php' => [
            "require_once __DIR__ . '/../Initializer.php';\n" => '',
        ],
        'vendor/webman/database/src/Initializer.php' => [
            "\nInitializer::init(config('database', []));\n" => "\n",
        ],
        'vendor/webman/database/src/DatabaseManager.php' => [
            '        $clearProperties = function () {' . "\n"
            . '            $this->queryGrammar = null;' . "\n"
            . '        };' . "\n"
            . '        $clearProperties->call($connection);'
            => '        $connection->setQueryGrammar(null);',
        ],
        'vendor/webman/think-orm/src/Initializer.php' => [
            "\nInitializer::init();\n" => "\n",
        ],
        'vendor/webman/think-orm/src/DbManager.php' => [
            '        $clearProperties = function () {' . "\n"
            . '            $this->db = null;' . "\n"
            . '            $this->cache = null;' . "\n"
            . '            $this->builder = null;' . "\n"
            . '        };' . "\n"
            . '        $clearProperties->call($connection);'
            => '        $connection->clearRuntimeStateForAot();',
        ],
        'vendor/topthink/think-orm/src/model/concern/Conversion.php' => [
            '            foreach ($append as $key => $attr) {' . "\n"
            . '                $key = is_numeric($key) ? $attr : $key;'
            => '            foreach ($append as $key => $appendAttr) {' . "\n"
            . '                $key = is_numeric($key) ? $appendAttr : $key;',
            '$this->data[$key] = $model->getAttr($attr);'
            => '$this->data[$key] = $model->getAttr($appendAttr);',
        ],
        'vendor/topthink/think-orm/src/model/concern/RelationShip.php' => [
            '    private $parent;' => '    protected $parent;',
        ],
        'vendor/topthink/think-orm/src/model/concern/Attribute.php' => [
            '            $relation = false;' . "\n"
            => '',
            '            $relation = $this->isRelationAttr($name);'
            . "\n"
            . '            $value    = null;'
            => '            return $this->getValue($name, null, $this->isRelationAttr($name));',
            '        return $this->getValue($name, $value, $relation);'
            => '        return $this->getValue($name, $value, false);',
        ],
        'vendor/topthink/think-orm/src/model/concern/TimeStamp.php' => [
            '$value = $this->formatDateTime(\'Y-m-d H:i:s.u\');'
            => 'return $this->formatDateTime(\'Y-m-d H:i:s.u\');',
            '$value = $obj->__toString();'
            => 'return $obj->__toString();',
        ],
        'vendor/topthink/think-orm/src/Model.php' => [
            '    public static function query(): Query'
            => '    public static function where(...$args)'
                . "\n"
                . '    {'
                . "\n"
                . '        return static::__callStatic(\'where\', $args);'
                . "\n"
                . '    }'
                . "\n"
                . "\n"
                . '    public static function query(): Query',
            '$db->transaction(function () use ($data, $allowFields, $db) {'
            => '$db->transaction(function ($connection) use ($data, $allowFields, $db) {',
            '$db->transaction(function () use ($data, $sequence, $allowFields, $db) {'
            => '$db->transaction(function ($connection) use ($data, $sequence, $allowFields, $db) {',
            '$result = $db->transaction(function () use ($replace, $dataSet) {'
            => '$result = $db->transaction(function ($connection) use ($replace, $dataSet) {',
            '$db->transaction(function () use ($where, $db) {'
            => '$db->transaction(function ($connection) use ($where, $db) {',
        ],
        'vendor/topthink/think-validate/src/Validate.php' => [
            '        foreach ($keys as $key) {' . "\n"
            . '            if (!isset($data[$key])) {'
            => '        foreach ($keys as $segment) {' . "\n"
            . '            if (!isset($data[$segment])) {',
            '$value = $data = $data[$key];' => '$value = $data = $data[$segment];',
            'protected function parseErrorMsg(string $msg, $rule, string $title)'
            => 'protected function parseErrorMsg(mixed $msg, $rule, string $title)',
        ],
        'vendor/topthink/think-orm/src/db/BaseQuery.php' => [
            '        if (empty($this->options[\'where\']) && empty($this->options[\'scope\']) && empty($this->options[\'order\']) && empty($this->options[\'sort\'])) {'
            => '        $resultState = new \stdClass();'
                . "\n"
                . '        if (empty($this->options[\'where\']) && empty($this->options[\'scope\']) && empty($this->options[\'order\']) && empty($this->options[\'sort\'])) {',
            '            $result = [];'
            => '            $resultState->value = [];',
            '            $result = $this->connection->find($this);'
            => '            $resultState->value = $this->connection->find($this);',
            '        if (empty($result)) {'
            => '        if (empty($resultState->value)) {',
            '            $this->resultToModel($result);'
            => '            $this->resultToModel($resultState->value);',
            '            $this->result($result);'
            => '            $this->result($resultState->value);',
            '        return $result;'
            . "\n"
            . '    }'
            . "\n"
            . "\n"
            . '    /**'
            . "\n"
            . '     * 分析表达式（可用于查询或者写入操作）.'
            => '        return $resultState->value;'
                . "\n"
                . '    }'
                . "\n"
                . "\n"
                . '    /**'
                . "\n"
                . '     * 分析表达式（可用于查询或者写入操作）.',
            '    protected function tableStr(string $table): array | string'
            . "\n"
            . '    {'
            => '    protected function tableStr(string $table): array | string'
                . "\n"
                . '    {'
                . "\n"
                . '        $tableState = new \stdClass();'
                . "\n"
                . '        $tableState->value = $table;',
            '$table          = [];'
            => '$tableState->value = [];',
            '$table  = [];'
            => '$tableState->value = [];',
            '$table[$item]'
            => '$tableState->value[$item]',
            '$table[]'
            => '$tableState->value[]',
            '                $tableState->value[] = $val;'
            => '                $table[] = $val;',
            '        return $table;'
            . "\n"
            . '    }'
            . "\n"
            . "\n"
            . '    /**'
            . "\n"
            . '     * 指定多个数据表（数组格式）.'
            => '        return $tableState->value;'
                . "\n"
                . '    }'
                . "\n"
                . "\n"
                . '    /**'
                . "\n"
                . '     * 指定多个数据表（数组格式）.',
        ],
        'vendor/topthink/think-orm/src/db/Connection.php' => [
            '    /**' . "\n"
            . '     * 析构方法.' . "\n"
            . '     */' . "\n"
            . '    public function __destruct()'
            => '    public function clearRuntimeStateForAot(): void' . "\n"
                . '    {' . "\n"
                . '        $this->db = null;' . "\n"
                . '        $this->cache = null;' . "\n"
                . '        $this->builder = null;' . "\n"
                . '    }' . "\n"
                . "\n"
                . '    /**' . "\n"
                . '     * 析构方法.' . "\n"
                . '     */' . "\n"
                . '    public function __destruct()',
        ],
        'vendor/topthink/think-orm/src/db/concern/ModelRelationQuery.php' => [
            '    protected function resultToModel(array &$result): void'
            => '    protected function resultToModel(mixed &$result): void',
        ],
        'vendor/topthink/think-orm/src/db/concern/ResultOperation.php' => [
            '    protected function resultSet(array &$resultSet, bool $toCollection = true): void'
            => '    protected function resultSet(mixed &$resultSet, bool $toCollection = true): void',
        ],
        'vendor/topthink/think-orm/src/db/Fetch.php' => [
            '        $field = array_map(\'trim\', explode(\',\', $field));'
            . "\n"
            . "\n"
            . '        $this->query->setOption(\'field\', $field);'
            => '        $columnFields = array_map(\'trim\', explode(\',\', $field));'
                . "\n"
                . "\n"
                . '        $this->query->setOption(\'field\', $columnFields);',
        ],
        'vendor/topthink/think-orm/src/db/concern/WhereQuery.php' => [
            '        if ($field instanceof $this) {'
            => '        $queryClass = get_class($this);'
                . "\n"
                . '        if ($field instanceof $queryClass) {',
        ],
        'vendor/topthink/think-orm/src/db/Builder.php' => [
            '                return $this->$fun($query, $key, $exp, $value, $field, $bindType, $val[2] ?? \'AND\');'
            => '                if ($fun === \'parseLike\') {'
                . "\n"
                . '                    return $this->$fun($query, $key, $exp, $value, $field, $bindType, $val[2] ?? \'AND\');'
                . "\n"
                . '                }'
                . "\n"
                . '                if (in_array($fun, [\'parseRegexp\', \'parseFindInSet\'], true)) {'
                . "\n"
                . '                    return $this->$fun($query, $key, $exp, $value, $field);'
                . "\n"
                . '                }'
                . "\n"
                . '                if (in_array($fun, [\'parseCompare\', \'parseBetween\', \'parseIn\', \'parseExp\', \'parseNull\', \'parseBetweenTime\', \'parseTime\', \'parseExists\', \'parseColumn\'], true)) {'
                . "\n"
                . '                    return $this->$fun($query, $key, $exp, $value, $field, $bindType);'
                . "\n"
                . '                }'
                . "\n"
                . '                return $this->$fun($query, $key, $exp, $value, $field, $bindType, $val[2] ?? \'AND\');',
        ],
        'vendor/topthink/think-orm/src/db/PDOConnection.php' => [
            '    public function getTableInfo(array | string $tableName, string $fetch = \'\')'
            => '    public function getTableInfo(mixed $tableName, string $fetch = \'\')',
            '    public function getFieldsType($tableName, ?string $field = null)'
            => '    public function getFieldsType(mixed $tableName, ?string $field = null)',
            '            $pk          = count($pk) > 1 ? $pk : $pk[0];'
            . "\n"
            . '            $info[\'_pk\'] = $pk;'
            => '            $info[\'_pk\'] = count($pk) > 1 ? $pk : $pk[0];',
            '    protected function autoInsIDType(BaseQuery $query, string $insertId)'
            . "\n"
            . '    {'
            => '    protected function autoInsIDType(BaseQuery $query, string $insertId)'
                . "\n"
                . '    {'
                . "\n"
                . '        $insertIdState = new \stdClass();'
                . "\n"
                . '        $insertIdState->value = $insertId;',
            '$insertId = (int) $insertId;'
            => '$insertIdState->value = (int) $insertId;',
            '$insertId = (float) $insertId;'
            => '$insertIdState->value = (float) $insertId;',
            '        return $insertId;'
            => '        return $insertIdState->value;',
            '        $dbMaster = false;'
            => '        $dbMasterState = new \stdClass();'
                . "\n"
                . '        $dbMasterState->value = false;',
            '            $dbMaster = [];'
            => '            $dbMasterState->value = [];',
            '                $dbMaster[$name] = $config[$name][$m] ?? $config[$name][0];'
            => '                $dbMasterState->value[$name] = $config[$name][$m] ?? $config[$name][0];',
            '        return $this->connect($dbConfig, $r, $r == $m ? false : $dbMaster);'
            => '        return $this->connect($dbConfig, $r, $r == $m ? false : $dbMasterState->value);',
        ],
        'vendor/brick/math/src/BigInteger.php' => [
            '                $value = ~$value;'
            => '                $complement = \'\';' . "\n"
            . '                for ($i = 0, $length = strlen($value); $i < $length; $i++) {' . "\n"
            . '                    $complement .= chr(255 - ord($value[$i]));' . "\n"
            . '                }' . "\n"
            . '                $value = $complement;',
        ],
        'vendor/brick/math/src/Internal/Calculator.php' => [
            'toDecimal(' => 'typephpToDecimal(',
        ],
        'vendor/brick/math/src/Internal/Calculator/NativeCalculator.php' => [
            '                $q = intdiv($na, $nb);' => '                $intQ = intdiv($na, $nb);',
            '                $r = $na % $nb;' => '                $intR = $na % $nb;',
            '                    (string) $q,' => '                    (string) $intQ,',
            '                    (string) $r,' => '                    (string) $intR,',
            '            $sum = $blockA - $blockB - $carry;' => '            $sumValue = $blockA - $blockB - $carry;',
            '            if ($sum < 0) {' => '            if ($sumValue < 0) {',
            '                $sum += $complement;' => '                $sumValue += $complement;',
            '            $sum = (string) $sum;' => '            $sum = (string) $sumValue;',
            '                $value = $mul % $complement;' => '                $remainderValue = $mul % $complement;',
            '                $carry = ($mul - $value) / $complement;' => '                $carry = ($mul - $remainderValue) / $complement;',
            '                $value = (string) $value;' => '                $value = (string) $remainderValue;',
            '            $r = (int) substr($a, 0, $z - 1);' => '            $intRemainder = (int) substr($a, 0, $z - 1);',
            '                $n = $r * 10 + (int) $a[$i];' => '                $n = $intRemainder * 10 + (int) $a[$i];',
            '                $r = $n % $nb;' => '                $intRemainder = $n % $nb;',
            "            return [ltrim(\$q, '0') ?: '0', (string) \$r];" => "            return [ltrim(\$q, '0') ?: '0', (string) \$intRemainder];",
        ],
        'vendor/guzzlehttp/psr7/src/Query.php' => [
            "            \$decoder = 'rawurldecode';"
            => '            $decoder = static fn($value) => rawurldecode((string) $value);',
            "            \$decoder = 'urldecode';"
            => '            $decoder = static fn($value) => urldecode((string) $value);',
            "            \$encoder = 'rawurlencode';"
            => '            $encoder = static fn(string $value): string => rawurlencode($value);',
            "            \$encoder = 'urlencode';"
            => '            $encoder = static fn(string $value): string => urlencode($value);',
        ],
        'vendor/guzzlehttp/psr7/src/StreamWrapper.php' => [
            '        $options = stream_context_get_options($this->context);'
            => '        $contextOptions = stream_context_get_options($this->context);',
            "        if (!isset(\$options['guzzle']['stream'])) {"
            => "        if (!isset(\$contextOptions['guzzle']['stream'])) {",
            "        \$this->stream = \$options['guzzle']['stream'];"
            => "        \$this->stream = \$contextOptions['guzzle']['stream'];",
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
        'vendor/workerman/workerman/src/Timer.php' => [
            'pcntl_signal(SIGALRM, self::signalHandle(...), false);'
            => 'pcntl_signal(SIGALRM, static fn (...$__sig) => self::signalHandle(), false);',
        ],
        'vendor/workerman/workerman/src/Connection/TcpConnection.php' => [
            'set_error_handler(static function (int $code, string $msg): bool {' => 'set_error_handler(static function (int $code, string $msg, ...$__err): bool {',
            "        \$reason = '';\n"
            . '        set_error_handler(static function (int $code, string $msg) use (&$reason): bool {'
            => '        set_error_handler(static function (int $code, string $msg, ...$__err) use (&$reason): bool {',
            "        } finally {\n"
            . "            restore_error_handler();\n"
            . "        }\n"
            . '        // Negotiation has failed.'
            => "        } finally {\n"
                . "            restore_error_handler();\n"
                . "        }\n"
                . "        if (\$reason === null) {\n"
                . "            \$reason = '';\n"
                . "        }\n"
                . '        // Negotiation has failed.',
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
        foreach (array_merge(self::GUARDED_SOURCES, $this->discoverProjectGuardedSources()) as $sourceRel => $targetRel) {
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
            $content = $this->prepareGuardedSource($sourceRel, $content);
            if (file_put_contents($targetFile, $this->flattenGuardedSource($content)) === false) {
                throw new \RuntimeException('Unable to write the AOT flattened source: ' . $targetFile);
            }
            $generated[] = $targetRel;
        }
        return $generated;
    }

    /**
     * @return array<string, string>
     */
    public function discoverProjectGuardedSources(): array
    {
        $sources = [];
        if (is_file($this->basePath . '/app/functions.php')) {
            $sources['app/functions.php'] = '.typephp/build/app-functions.php';
        }
        foreach (glob($this->basePath . '/plugin/*/app/functions.php') ?: [] as $sourceFile) {
            $plugin = basename(dirname(dirname($sourceFile)));
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $plugin)) {
                continue;
            }
            $sources["plugin/{$plugin}/app/functions.php"] = ".typephp/build/{$plugin}-functions.php";
        }
        ksort($sources);
        return $sources;
    }

    /**
     * Webman discovers plugin routes and bootstrap configuration from files in
     * plugin/<name>/config at runtime. Keep those configuration directories in
     * the portable package even when the application code itself is compiled.
     *
     * @return list<string>
     */
    public function discoverPluginConfigResources(): array
    {
        $resources = [];
        foreach (glob($this->basePath . '/plugin/*/config', GLOB_ONLYDIR) ?: [] as $configDirectory) {
            $plugin = basename(dirname($configDirectory));
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $plugin)) {
                continue;
            }
            $resources[] = "plugin/{$plugin}/config";
        }
        sort($resources);
        return $resources;
    }

    /**
     * @return list<string>
     */
    public function discoverWebmanVendorPackagingSources(): array
    {
        $sources = [];
        foreach (glob($this->basePath . '/vendor/webman/*/src') ?: [] as $sourceDirectory) {
            $package = basename(dirname($sourceDirectory));
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $package)) {
                continue;
            }
            if (is_file($sourceDirectory . '/Install.php')) {
                $sources[] = "vendor/webman/{$package}/src/Install.php";
            }
            if (is_dir($sourceDirectory . '/config')) {
                $sources[] = "vendor/webman/{$package}/src/config";
            }
            foreach (['demo', 'tests'] as $developmentDirectory) {
                if (is_dir(dirname($sourceDirectory) . '/' . $developmentDirectory)) {
                    $sources[] = "vendor/webman/{$package}/{$developmentDirectory}";
                }
            }
        }
        sort($sources);
        return $sources;
    }

    /**
     * @return list<string>
     */
    public function discoverFrameworkSupportOverrides(): array
    {
        $overrides = [];
        foreach (['Request.php', 'Response.php'] as $file) {
            if (
                is_file($this->basePath . '/support/' . $file)
                && is_file($this->basePath . '/vendor/workerman/webman-framework/src/support/' . $file)
            ) {
                $overrides[] = 'vendor/workerman/webman-framework/src/support/' . $file;
            }
        }
        return $overrides;
    }

    /**
     * 固定 PHP 8.4、无 intl 的构建组合下，静态选择 grapheme polyfill 的 8.0+
     * 声明分支，并把运行时 define 转为 TypePHP 可接受的顶层常量。
     */
    protected function prepareGuardedSource(string $sourceRel, string $content): string
    {
        if ($sourceRel === 'vendor/cakephp/core/functions.php') {
            $constantBlocks = <<<'PHP'
                if (!defined('DS')) {
                    /**
                     * Defines DS as short form of DIRECTORY_SEPARATOR.
                     */
                    define('DS', DIRECTORY_SEPARATOR);
                }

                if (!defined('CAKE_DATE_RFC7231')) {
                    define('CAKE_DATE_RFC7231', 'D, d M Y H:i:s \G\M\T');
                }
                PHP;
            $content = str_replace($constantBlocks, '', $content, $constantCount);
            $content = $this->flattenGuardedSource($content);
            $content = str_replace(
                'namespace Cake\Core;',
                "namespace {\nconst DS = DIRECTORY_SEPARATOR;\n"
                . "const CAKE_DATE_RFC7231 = 'D, d M Y H:i:s \\\\G\\\\M\\\\T';\n}\n\nnamespace Cake\\Core {",
                $content,
                $namespaceCount,
            );
            if ($constantCount !== 1 || $namespaceCount !== 1) {
                throw new \RuntimeException(
                    'The CakePHP core constants no longer match the pinned AOT transform.',
                );
            }
            return $content . "\n}\n";
        }
        if ($sourceRel === 'vendor/nikic/fast-route/src/functions.php') {
            $content = str_replace('$options += [', '$options = $options + [', $content, $count);
            if ($count < 1 || str_contains($content, '$options += [')) {
                throw new \RuntimeException(
                    'The FastRoute options union no longer matches the pinned AOT transform.',
                );
            }
            return $content;
        }
        if ($sourceRel === 'vendor/illuminate/reflection/helpers.php') {
            $search = '$proxy = $reflectionClass->newLazyProxy(function () use ($callback, $eager, &$proxy) {'
                . "\n"
                . '            $instance = $callback($proxy, $eager);'
                . "\n\n"
                . '            return $instance;'
                . "\n"
                . '        }, $options);';
            $replace = '$proxyInitializer = function () use ($callback, $eager, &$proxy) {'
                . "\n"
                . '            $instance = $callback($proxy, $eager);'
                . "\n\n"
                . '            return $instance;'
                . "\n"
                . '        };'
                . "\n\n"
                . '        $proxy = $reflectionClass->newLazyProxy($proxyInitializer, $options);';
            $content = str_replace($search, $replace, $content, $count);
            if ($count !== 1) {
                throw new \RuntimeException(
                    'The Illuminate reflection proxy helper no longer matches the pinned AOT transform.',
                );
            }
            return $content;
        }
        if ($sourceRel === 'vendor/illuminate/support/helpers.php') {
            $search = 'return preg_replace_callback($pattern, function () use (&$replacements) {'
                . "\n"
                . '            return array_shift($replacements);'
                . "\n"
                . '        }, $subject);';
            $replace = 'return preg_replace_callback($pattern, function () use ($replacements, &$remaining) {'
                . "\n"
                . '            if ($remaining === null) {'
                . "\n"
                . '                $remaining = $replacements;'
                . "\n"
                . '            }'
                . "\n\n"
                . '            return array_shift($remaining);'
                . "\n"
                . '        }, $subject);';
            $content = str_replace($search, $replace, $content, $count);
            if ($count !== 1) {
                throw new \RuntimeException(
                    'The Illuminate preg_replace_array helper no longer matches the pinned AOT transform.',
                );
            }
            return $content;
        }
        if ($sourceRel === 'vendor/symfony/var-dumper/Resources/functions/dump.php') {
            $content = str_replace(
                "            VarDumper::dump(\$vars[0]);\n            \$k = 0;",
                "            VarDumper::dump(\$vars[0]);",
                $content,
                $assignmentCount,
            );
            $content = str_replace('return $vars[$k];', 'return $vars[0];', $content, $returnCount);
            if ($assignmentCount !== 1 || $returnCount !== 1) {
                throw new \RuntimeException(
                    'The Symfony VarDumper helper no longer matches the pinned AOT transform.',
                );
            }
            return $content;
        }
        if (preg_match('#^vendor/symfony/cache/Traits/Redis(?:Cluster)?6[123]ProxyTrait\.php$#', $sourceRel)) {
            return $this->selectSymfonyRedis6ProxyTrait($content);
        }
        if ($sourceRel === 'vendor/symfony/polyfill-php85/bootstrap.php') {
            return $this->selectPhp85BootstrapForPhp84($content);
        }
        if ($sourceRel === 'vendor/zoujingli/ip2region/function.php') {
            $content = (string) preg_replace(
                "/if \\(!class_exists\\('Ip2Region'\\)\\) \\{\\s*require_once __DIR__ \\. '\\/Ip2Region\\.php';\\s*\\}\\s*/",
                '',
                $content,
                1,
                $loaderCount,
            );
            if ($loaderCount !== 1) {
                throw new \RuntimeException('The IP2Region function loader no longer matches the pinned AOT transform.');
            }
            return $content;
        }
        if ($sourceRel === 'vendor/zoujingli/ip2region/src/common.php') {
            $content = (string) preg_replace(
                "/\\s*if \\(!class_exists\\('Ip2Region'\\)\\) \\{\\s*"
                . "require_once __DIR__ \\. '\\/src\\/Ip2Region\\.php';\\s*\\}/",
                '',
                $content,
                1,
                $loaderCount,
            );
            if ($loaderCount !== 1) {
                throw new \RuntimeException(
                    'The IP2Region v3 class loader no longer matches the pinned AOT transform.',
                );
            }
            return $content;
        }
        if (str_starts_with($sourceRel, 'vendor/symfony/polyfill-php85/Resources/stubs/')) {
            $count = 0;
            $content = (string) preg_replace(
                '/if \(\\\\PHP_VERSION_ID < 80500\) \{\n([\s\S]*)\n\}\s*$/',
                '$1',
                $content,
                1,
                $count,
            );
            if ($count !== 1 || str_contains($content, 'PHP_VERSION_ID')) {
                throw new \RuntimeException(
                    'The Symfony PHP 8.5 stub structure no longer matches the pinned PHP 8.4 AOT transform.',
                );
            }
            return $content;
        }

        $intlBootstrapSources = [
            'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php',
            'vendor/symfony/polyfill-intl-idn/bootstrap80.php',
        ];
        if (!in_array($sourceRel, $intlBootstrapSources, true)) {
            return $content;
        }

        $constantGuard = "/if \\(!defined\\('([A-Z][A-Z0-9_]*)'\\)\\) \\{\\n    define\\('\\1', ([0-9]+)\\);\\n\\}\\n/";
        $content = (string) preg_replace($constantGuard, "const $1 = $2;\n", $content);
        if ($sourceRel === 'vendor/symfony/polyfill-intl-idn/bootstrap80.php') {
            if (str_contains($content, '!defined(')) {
                throw new \RuntimeException(
                    'The Symfony IDN bootstrap structure no longer matches the pinned PHP 8.4 AOT transform.',
                );
            }
            return $content;
        }

        $content = (string) preg_replace(
            [
                "/if \\(extension_loaded\\('intl'\\)\\) \\{\\n    return;\\n\\}\\n/",
                "/if \\(\\\\PHP_VERSION_ID >= 80500\\) \\{\\n    return require __DIR__\\.'\\/bootstrap85\\.php';\\n\\}\\n/",
            ],
            ['', ''],
            $content,
        );
        if (
            str_contains($content, "extension_loaded('intl')")
            || str_contains($content, 'PHP_VERSION_ID >= 80500')
            || str_contains($content, "defined('GRAPHEME_EXTR_")
        ) {
            throw new \RuntimeException(
                'The Symfony grapheme bootstrap structure no longer matches the pinned PHP 8.4 AOT transform.',
            );
        }
        return $content;
    }

    /**
     * 构建镜像固定 phpredis 6.3，选择 Symfony Redis 6.1/6.2/6.3 trait 的新签名分支。
     */
    protected function selectSymfonyRedis6ProxyTrait(string $content): string
    {
        $content = (string) preg_replace(
            '/if \(version_compare\(phpversion\(\'redis\'\), \'6\.[123]\.[^\\\']*\', \'>=?\'\)\) \{\n'
            . '([\s\S]*?)\n\} else \{\n[\s\S]*\n\}\s*$/',
            '$1',
            $content,
            1,
            $count,
        );
        if ($count !== 1 || str_contains($content, "phpversion('redis')")) {
            throw new \RuntimeException(
                'The Symfony Redis 6 proxy trait structure no longer matches the pinned phpredis 6.3 AOT transform.',
            );
        }
        return $content;
    }

    /**
     * PHP 8.4 需要 php85 的四个通用函数，但无 intl 时不应暴露 intl 分支。
     */
    protected function selectPhp85BootstrapForPhp84(string $content): string
    {
        $content = (string) preg_replace(
            '/if \(\\\\PHP_VERSION_ID >= 80500\) \{\s*return;\s*\}\s*/',
            '',
            $content,
            1,
            $versionCount,
        );
        if ($versionCount !== 1) {
            throw new \RuntimeException(
                'The Symfony PHP 8.5 bootstrap structure no longer matches the pinned PHP 8.4 AOT transform.',
            );
        }
        $content = (string) preg_replace(
            "/if \\(extension_loaded\\('intl'\\) && !function_exists\\('locale_is_right_to_left'\\)\\) \\{\\n"
            . "    function locale_is_right_to_left\\([^\\n]+\\n"
            . "\\}\\s*/",
            '',
            $content,
            1,
            $intlCount,
        );
        $legacyStart = strpos($content, "if (\\PHP_VERSION_ID >= 80000) {\n");
        if ($intlCount !== 1 || $legacyStart === false) {
            throw new \RuntimeException(
                'The Symfony PHP 8.5 bootstrap branches no longer match the pinned PHP 8.4 AOT transform.',
            );
        }
        $content = substr($content, 0, $legacyStart);
        foreach (['get_error_handler', 'get_exception_handler', 'array_first', 'array_last'] as $function) {
            if (!str_contains($content, "function {$function}(")) {
                throw new \RuntimeException("The Symfony PHP 8.5 bootstrap is missing {$function}().");
            }
        }
        return $content;
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
        $content = (string) preg_replace(
            '/(^|\n)([ \t]*(?:public|protected|private)[ \t]+static[ \t]+)(bool|int|float|string)([ \t]+\$[A-Za-z_][A-Za-z0-9_]*)[ \t]*;/',
            '$1$2?$3$4 = null;',
            $content,
        );
        return str_replace(
            'public static function wait(object &$barrier, int $timeout = -1): void',
            'public static function wait(mixed &$barrier, int $timeout = -1): void',
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
            $content = str_replace((string) $search, $replacement, $content);
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
        if ($sourceRel === 'plugin/saiadmin/app/cache/UserInfoCache.php') {
            return $this->patchSaiAdminUserInfoCache($content);
        }
        if ($sourceRel === 'vendor/symfony/http-foundation/Request.php') {
            $content = $this->stripSymfonyRequestPreloadHints($content);
        }
        if ($sourceRel === 'plugin/saiadmin/utils/code/CodeEngine.php') {
            $content = str_replace(
                "defined('DS') or define('DS', DIRECTORY_SEPARATOR);",
                'const DS = DIRECTORY_SEPARATOR;',
                $content,
                $count,
            );
            if ($count !== 1) {
                throw new \RuntimeException(
                    "SaiAdmin CodeEngine directory separator compatibility rule expected 1 match, found {$count}: "
                    . 'plugin/saiadmin/utils/code/CodeEngine.php.',
                );
            }
        }
        foreach (self::SWITCH_TERMINAL_REPLACEMENTS[$sourceRel] ?? [] as $search => $replacement) {
            if ($sourceRel === 'plugin/saiadmin/app/cache/ReflectionCache.php') {
                $replacement = str_replace(
                    ['__SAIADMIN_LOGIN_NO_NEED_LOGIN__', '__SAIADMIN_INSTALL_NO_NEED_LOGIN__'],
                    [
                        $this->readSaiAdminNoNeedLogin('LoginController.php'),
                        $this->readSaiAdminNoNeedLogin('InstallController.php'),
                    ],
                    (string) $replacement,
                );
            }
            $matches = substr_count($content, $search);
            $expectedMatches = match (true) {
                $sourceRel === 'vendor/topthink/think-orm/src/model/Collection.php' => 10,
                $sourceRel === 'plugin/saiadmin/utils/Captcha.php',
                str_starts_with($sourceRel, 'plugin/saiadmin/app/controller/'),
                $sourceRel === 'plugin/saiadmin/app/cache/ReflectionCache.php',
                $sourceRel === 'plugin/saiadmin/app/cache/UserAuthCache.php',
                $sourceRel === 'plugin/saiadmin/exception/SystemException.php',
                $sourceRel === 'vendor/nelexa/zip/src/IO/Stream/ResponseStream.php',
                $sourceRel === 'vendor/zoujingli/ip2region/src/ip2region/xdb/Util.php' => 1,
                default => null,
            };
            if ($expectedMatches !== null && $matches !== $expectedMatches) {
                $rule = match (true) {
                    $sourceRel === 'vendor/topthink/think-orm/src/model/Collection.php'
                        => 'ThinkORM collection callback arity',
                    str_starts_with($sourceRel, 'plugin/saiadmin/app/controller/')
                        => 'controller request arity',
                    $sourceRel === 'plugin/saiadmin/exception/SystemException.php'
                        => 'system exception nullable cause',
                    $sourceRel === 'vendor/nelexa/zip/src/IO/Stream/ResponseStream.php'
                        => 'Nelexa PSR stream signature',
                    $sourceRel === 'vendor/zoujingli/ip2region/src/ip2region/xdb/Util.php'
                        => 'IP2Region v3 integer slot',
                    default => 'reflection',
                };
                throw new \RuntimeException(
                    "SaiAdmin {$rule} compatibility rule expected {$expectedMatches} match(es), "
                    . "found {$matches}: {$sourceRel}.",
                );
            }
            $content = str_replace($search, $replacement, $content);
        }
        return $content;
    }

    private function stripSymfonyRequestPreloadHints(string $content): string
    {
        $preloadHints = <<<'PHP'
            // Help opcache.preload discover always-needed symbols
            class_exists(AcceptHeader::class);
            class_exists(FileBag::class);
            class_exists(HeaderBag::class);
            class_exists(HeaderUtils::class);
            class_exists(InputBag::class);
            class_exists(ParameterBag::class);
            class_exists(ServerBag::class);

            PHP;
        $content = str_replace($preloadHints, '', $content, $count);
        if ($count !== 1) {
            throw new \RuntimeException(
                "Symfony Request preload hints compatibility rule expected 1 match, found {$count}: "
                . 'vendor/symfony/http-foundation/Request.php.',
            );
        }
        return $content;
    }

    private function patchSaiAdminUserInfoCache(string $content): string
    {
        $pattern = '/        if \(is_array\(\$([a-z_]+)\)\) \{\n'
            . '            \$tags = \[\];\n'
            . '            foreach \(\$\1 as \$id\) \{\n'
            . '                \$tags\[\] = \$cache\[\'([a-z]+)\'\] \. \$id;\n'
            . '            \}\n'
            . '        \} else \{\n'
            . '            \$tags = \$cache\[\'\2\'\] \. \$\1;\n'
            . '        \}\n'
            . '        return Cache::tag\(\$tags\)->clear\(\);/';
        $content = (string) preg_replace_callback(
            $pattern,
            static fn(array $matches): string =>
                "        if (is_array(\${$matches[1]})) {\n"
                . "            \$tags = [];\n"
                . "            foreach (\${$matches[1]} as \$id) {\n"
                . "                \$tags[] = \$cache['{$matches[2]}'] . \$id;\n"
                . "            }\n"
                . "            return Cache::tag(\$tags)->clear();\n"
                . "        }\n"
                . "        \$tag = \$cache['{$matches[2]}'] . \${$matches[1]};\n"
                . '        return Cache::tag($tag)->clear();',
            $content,
            -1,
            $count,
        );
        if ($count !== 3) {
            throw new \RuntimeException(
                "SaiAdmin cache tag compatibility rule expected 3 matches, found {$count}: "
                . 'plugin/saiadmin/app/cache/UserInfoCache.php.',
            );
        }
        return $content;
    }

    private function readSaiAdminNoNeedLogin(string $controller): string
    {
        $relative = 'plugin/saiadmin/app/controller/' . $controller;
        $content = file_get_contents($this->basePath . '/' . $relative);
        if ($content === false || preg_match(
            '/protected\s+array\s+\$noNeedLogin\s*=\s*'
            . '(\[\s*(?:\'[A-Za-z_][A-Za-z0-9_]*\'\s*(?:,\s*\'[A-Za-z_][A-Za-z0-9_]*\'\s*)*)?\]);/',
            $content,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                "SaiAdmin reflection compatibility rule requires a static noNeedLogin list: {$relative}.",
            );
        }

        return $matches[1];
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
                } elseif (preg_match("/^!\s*(?:function|class)_exists\s*\(\s*['\"][^'\"]+['\"]\s*\)$/", $condition) === 1) {
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
     * 校验并返回已知 Composer files wrapper 的静态声明源。
     *
     * @return list<string>
     */
    protected function resolveStaticLoaderSources(): array
    {
        $sources = [];
        foreach (self::STATIC_LOADER_SOURCES as $loaderRel => $sourceRel) {
            $loaderFile = $this->basePath . '/' . $loaderRel;
            if (!is_file($loaderFile)) {
                continue;
            }
            $sourceFile = $this->basePath . '/' . $sourceRel;
            if (!is_file($sourceFile)) {
                throw new \RuntimeException("Static loader target was not found: {$sourceRel}");
            }

            $content = file_get_contents($loaderFile);
            if ($content === false) {
                throw new \RuntimeException("Unable to read the static loader source: {$loaderRel}");
            }
            $compact = '';
            foreach (token_get_all($content) as $token) {
                if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $compact .= is_array($token) ? $token[1] : $token;
            }
            if (!in_array($compact, [
                "if(!\\function_exists('GuzzleHttp\\describe_type')){require__DIR__.'/functions.php';}",
                'if(!\\function_exists("GuzzleHttp\\describe_type")){require__DIR__."/functions.php";}',
            ], true)) {
                throw new \RuntimeException(
                    "Unsupported static loader wrapper: {$loaderRel}. Refusing to compile unknown top-level execution.",
                );
            }
            $sources[] = $sourceRel;
        }
        return $sources;
    }

    /**
     * @param list<mixed> $resources
     */
    public function generateRuntimeResourceList(array $resources): ?string
    {
        $targetRel = '.typephp/build/runtime-resources.list';
        $targetFile = $this->basePath . '/' . $targetRel;
        $resolved = [];
        foreach ($resources as $resource) {
            if (!is_string($resource)) {
                throw new \RuntimeException('Runtime resource paths must be strings.');
            }
            $resource = str_replace('\\', '/', $resource);
            $segments = explode('/', $resource);
            if (
                $resource === ''
                || !preg_match('/^[A-Za-z0-9._\/-]+$/', $resource)
                || str_starts_with($resource, '/')
                || preg_match('/^[A-Za-z]:/', $resource)
                || in_array('', $segments, true)
                || in_array('.', $segments, true)
                || in_array('..', $segments, true)
                || $segments[0] === '.typephp'
            ) {
                throw new \RuntimeException('Unsafe runtime resource path: ' . $resource);
            }
            if (file_exists($this->basePath . '/' . $resource)) {
                $resolved[] = $resource;
            }
        }
        $resolved = array_values(array_unique($resolved));
        if ($resolved === []) {
            if (is_file($targetFile)) {
                unlink($targetFile);
            }
            return null;
        }
        $directory = dirname($targetFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the TypePHP build directory: ' . $directory);
        }
        if (file_put_contents($targetFile, implode("\n", $resolved) . "\n") === false) {
            throw new \RuntimeException('Unable to write the runtime resource list: ' . $targetFile);
        }
        return $targetRel;
    }

    /**
     * 分析项目并生成 project.linux.yml
     */
    public function generateProjectYml(array $extraConfig = []): string
    {
        $profile = null;
        $profileName = $extraConfig['profile'] ?? null;
        if ($profileName !== null) {
            if ($profileName !== SaiAdminProfile::NAME) {
                throw new \RuntimeException("Unknown TypePHP build profile: {$profileName}.");
            }
            $profile = new SaiAdminProfile($this->basePath);
            $profile->assertSupported();
        }

        $runtimeResources = array_merge(
            self::REGISTERED_DYNAMIC_SOURCES,
            $this->discoverPluginConfigResources(),
            $profile?->runtimeResources() ?? [],
            $extraConfig['runtime_resources'] ?? [],
        );
        if (!is_array($runtimeResources)) {
            throw new \RuntimeException('runtime_resources must be a list of project-relative paths.');
        }
        $this->generateRuntimeResourceList(array_values($runtimeResources));

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
        if ($profile !== null) {
            $sources = $profile->mergeCompilerSources($sources);
        }
        foreach ($this->resolveStaticLoaderSources() as $loaderTarget) {
            $coveredByDirectory = false;
            foreach ($sources as $source) {
                if (
                    is_dir($this->basePath . '/' . $source)
                    && str_starts_with($loaderTarget, rtrim($source, '/') . '/')
                ) {
                    $coveredByDirectory = true;
                    break;
                }
            }
            if (!$coveredByDirectory) {
                $sources[] = $loaderTarget;
            }
        }

        // 自动探测 monolog 与 webman-mcp 等常用库
        if (!isset($extraConfig['include']) && !isset($extraConfig['sources'])) {
            if ($profile === null && is_dir($this->basePath . '/vendor/monolog/monolog/src')) {
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
            'support/bootstrap.php',
            'vendor/workerman/webman-framework/src/support/view',
            'vendor/autoload.php',
            'vendor/bin',
            'vendor/composer',
            ...array_keys(self::STATIC_LOADER_SOURCES),
            ...array_keys(self::GUARDED_SOURCES),
            ...self::REGISTERED_DYNAMIC_SOURCES,
            ...array_keys($this->discoverProjectGuardedSources()),
            ...$this->discoverWebmanVendorPackagingSources(),
            ...$this->discoverFrameworkSupportOverrides(),
            'vendor/workerman/webman-framework/src/support/bootstrap.php',
            'vendor/workerman/webman-framework/src/start.php',
            'vendor/workerman/webman-framework/src/windows.php',
            'vendor/workerman/webman-framework/src/Install.php',
            'vendor/nikic/fast-route/src/bootstrap.php',
            'vendor/nikic/fast-route/src/DataGenerator/CharCountBased.php',
            'vendor/nikic/fast-route/src/DataGenerator/GroupPosBased.php',
            'vendor/nikic/fast-route/src/DataGenerator/MarkBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/CharCountBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/GroupPosBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/MarkBased.php',
            'vendor/illuminate/pagination/resources',
            'vendor/illuminate/bus/ChainedBatch.php',
            'vendor/illuminate/bus/DynamoBatchRepository.php',
            'vendor/illuminate/database/Console',
            'vendor/illuminate/database/Eloquent/BroadcastableModelEventOccurred.php',
            'vendor/illuminate/database/Eloquent/BroadcastsEvents.php',
            'vendor/illuminate/database/Eloquent/BroadcastsEventsAfterCommit.php',
            'vendor/illuminate/events/CallQueuedListener.php',
            'vendor/illuminate/session/Console',
            'vendor/illuminate/support/Testing',
            'vendor/nelexa/zip/.php-cs-fixer.php',
            'vendor/nesbot/carbon/src/Carbon/Lang',
            'vendor/nesbot/carbon/src/Carbon/List',
            'vendor/nesbot/carbon/src/Carbon/PHPStan',
            'vendor/nesbot/carbon/lazy/Carbon/MessageFormatter/MessageFormatterMapperWeakType.php',
            'vendor/nesbot/carbon/lazy/Carbon/ProtectedDatePeriod.php',
            'vendor/nesbot/carbon/lazy/Carbon/TranslatorWeakType.php',
            'vendor/symfony/clock/Test',
            'vendor/saithink/saiadmin/src/orm',
            'vendor/saithink/saiadmin/src/plugin',
            'vendor/saithink/saipackage/src/plugin',
            'vendor/tinywan/jwt/src/config',
            'vendor/tinywan/jwt/tests',
            'vendor/tinywan/storage/.php-cs-fixer.php',
            'vendor/tinywan/storage/src/config',
            'vendor/tinywan/storage/tests',
            'vendor/tinywan/webman-typephp',
            'vendor/topthink/think-container/tests',
            // These guarded helpers duplicate Illuminate's compiled helpers;
            // the two remaining names are PHP 8.1/8.3 built-ins.
            'vendor/topthink/think-helper/src/helper.php',
            'vendor/topthink/think-helper/tests',
            'vendor/topthink/think-orm/stubs/load_stubs.php',
            'vendor/topthink/think-orm/stubs/Facade.php',
            'vendor/symfony/error-handler/Resources',
            'vendor/symfony/console/DependencyInjection',
            'vendor/symfony/console/Tester',
            'vendor/symfony/event-dispatcher/Debug',
            'vendor/symfony/event-dispatcher/DependencyInjection',
            'vendor/symfony/http-foundation/Test',
            'vendor/symfony/http-kernel/Resources',
            'vendor/symfony/http-kernel/Bundle',
            'vendor/symfony/http-kernel/Config',
            'vendor/symfony/http-kernel/Debug',
            'vendor/symfony/http-kernel/DependencyInjection',
            'vendor/symfony/http-kernel/EventListener/DumpListener.php',
            'vendor/symfony/http-kernel/EventListener/ProfilerListener.php',
            'vendor/symfony/http-kernel/HttpKernelBrowser.php',
            'vendor/symfony/http-kernel/Profiler',
            'vendor/symfony/mime/DependencyInjection',
            'vendor/symfony/mime/Test',
            'vendor/symfony/service-contracts/Test',
            'vendor/symfony/translation-contracts/Test',
            'vendor/symfony/translation/DependencyInjection',
            'vendor/symfony/translation/Extractor',
            'vendor/symfony/translation/Test',
            'vendor/symfony/cache/Traits/Relay',
            'vendor/symfony/cache/Traits/RelayProxy.php',
            'vendor/symfony/cache/Traits/RelayClusterProxy.php',
            'vendor/symfony/string/Resources',
            'vendor/symfony/translation/Resources',
            'vendor/symfony/var-dumper/Resources',
            'vendor/symfony/polyfill-intl-idn/Resources',
            'vendor/symfony/polyfill-intl-normalizer/Resources',
            'vendor/symfony/polyfill-mbstring/Resources',
            'vendor/symfony/polyfill-php80/Resources',
            'vendor/symfony/polyfill-php83/Resources',
            'vendor/symfony/polyfill-php84/Resources',
            'vendor/voku/portable-ascii/src/voku/helper/data',
            'vendor/symfony/polyfill-ctype/bootstrap.php',
            'vendor/symfony/polyfill-ctype/bootstrap80.php',
            'vendor/symfony/polyfill-intl-grapheme/bootstrap.php',
            'vendor/symfony/polyfill-intl-grapheme/bootstrap85.php',
            'vendor/symfony/polyfill-intl-idn/bootstrap.php',
            'vendor/symfony/polyfill-intl-normalizer/bootstrap.php',
            'vendor/symfony/polyfill-mbstring/bootstrap.php',
            'vendor/symfony/polyfill-mbstring/bootstrap72.php',
            'vendor/symfony/polyfill-mbstring/bootstrap80.php',
            'vendor/symfony/polyfill-php80/bootstrap.php',
            'vendor/symfony/polyfill-php83/bootstrap.php',
            'vendor/symfony/polyfill-php83/bootstrap72.php',
            'vendor/symfony/polyfill-php83/bootstrap81.php',
            'vendor/symfony/polyfill-php84/bootstrap.php',
            'vendor/symfony/polyfill-php84/bootstrap72.php',
            'vendor/symfony/polyfill-php84/bootstrap82.php',
            'vendor/symfony/polyfill-php85/bootstrap80.php',
            'vendor/workerman/coroutine/src/Context.php',
            'vendor/workerman/coroutine/src/WaitGroup.php',
            'vendor/workerman/coroutine/src/Barrier.php',
            'vendor/workerman/coroutine/src/Barrier/BarrierInterface.php',
            'vendor/workerman/workerman/src/Protocols/Http/Session.php',
            'vendor/workerman/workerman/src/Protocols/Http/Session/FileSessionHandler.php',
            'vendor/workerman/coroutine/src/Context/Fiber.php',
            'vendor/workerman/coroutine/src/Coroutine/Fiber.php',
            'vendor/workerman/coroutine/src/Coroutine.php',
            ...array_keys(self::SWITCH_TERMINAL_SOURCES),
            ...array_keys(self::REF_CAPTURE_SOURCES),
            'vendor/workerman/workerman/src/Worker.php',
            'vendor/workerman/workerman/src/Timer.php',
            'vendor/workerman/workerman/src/Connection/TcpConnection.php',
            'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php',
            'vendor/workerman/workerman/src/Events/Select.php',
            'vendor/workerman/webman-framework/src/File.php',
            'vendor/workerman/coroutine/tests',
            'vendor/workerman/channel/test',
            'vendor/workerman/coroutine/stubs',
            'vendor/workerman/coroutine/src/Barrier/Swow.php',
            'vendor/workerman/coroutine/src/Channel/Swow.php',
            'vendor/workerman/coroutine/src/Context/Swow.php',
            'vendor/workerman/coroutine/src/Coroutine/Swow.php',
            'vendor/workerman/coroutine/src/WaitGroup/Swow.php',
            'vendor/workerman/workerman/src/Events/Swow.php',
            'vendor/workerman/coroutine/src/Pool.php',
            'vendor/workerman/coroutine/src/Utils/DestructionWatcher.php',
            'vendor/cakephp/chronos/rector.php',
            'vendor/cakephp/core/functions_global.php',
            'vendor/league/container/examples',
            'vendor/robmorgan/phinx/app',
            'vendor/robmorgan/phinx/src/composer_autoloader.php',
            'vendor/zoujingli/ip2region/_test.php',
            'vendor/zoujingli/ip2region/tests',
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
        if ($profile !== null) {
            $userIgnores = $profile->filterUserIgnores($userIgnores);
        }
        $ignores = array_values(array_unique(array_merge(
            $mandatoryIgnores,
            $profile?->resourceIgnores() ?? [],
            $userIgnores,
        )));

        if ($profile !== null) {
            $generatedSources = array_merge(
                self::GUARDED_SOURCES,
                $this->discoverProjectGuardedSources(),
                self::NULLABLE_STATIC_SOURCES,
                self::STRAY_BOOTSTRAP_SOURCES,
                self::VARIADIC_HANDLER_SOURCES,
                self::SWITCH_TERMINAL_SOURCES,
                self::REF_CAPTURE_SOURCES,
            );
            $profile->writeCoverageManifest(array_values(array_unique($sources)), $ignores, $generatedSources);
        }

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
        $jobs = (int) ($extraConfig['build']['jobs'] ?? 4);
        $yaml .= "job: {$jobs}\n";
        $yaml .= "debug: false\n";

        $ymlPath = $this->basePath . DIRECTORY_SEPARATOR . 'project.linux.yml';
        file_put_contents($ymlPath, $yaml);
        return $ymlPath;
    }
}
