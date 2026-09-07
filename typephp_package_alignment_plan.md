# 对齐 tinywan-typephp-webman 构建产物改造方案

## Goal Description
解决通过 `php webman typephp:package` 打包出的产物在执行 `./start.sh start` 时出现的 `Fatal error: Undefined constant "Monolog\Handler\Curl\CURLE_COULDNT_RESOLVE_HOST"` 错误，并对标参考项目 `tinywan-typephp-webman`（GitHub Actions 成功构建的 Linux portable-dir），达到 **100% 架构对齐与产物自包含运行**。

---

## 现状与根因分析

通过深入对比 `tinywan-typephp-webman-php8.5-linux-x64` 与 `webman-docker-build-demo/dist`：

| 模块 | 参考标准产物 (`tinywan-typephp-webman`) | 当前插件打包产物 (`webman-typephp`) | 影响与故障现象 |
| :--- | :--- | :--- | :--- |
| **Monolog 编译源** | 精准白名单 (`Logger`, `LogRecord`, `StreamHandler` 等核心类) | 粗暴全量 `vendor/monolog/monolog/src` | 包含了未提供对应扩展的 Handler（如 `Curl/Util.php`），启动即崩溃 |
| **PHP 扩展库 (`ext/`)** | 包含 `curl.so`, `pdo_mysql.so`, `posix.so` 等完整扩展 | **完全缺失 `ext/` 目录** | 运行时没有任何扩展可用 |
| **运行时配置 (`php.ini`)** | 存在独立 `php.ini`，指定 `extension_dir="./ext"` 并按顺序加载各扩展 | **完全缺失 `php.ini`** | 无法加载任何底层扩展 |
| **启动环境 (`start.sh`)** | 包含 `export PHPRC="$SCRIPT_DIR"` | 缺少 `export PHPRC` | 就算放了 `php.ini`，`libphp.so` 也不会读取 |
| **底包动态库 (`lib/`)** | 246 个 `.so` 文件（含 `libcurl.so.4`, `libssl`, `libevent` 等） | 仅 14 个基础系统库 | 扩展即使放入也会因缺失底层 `.so` 报错 |
| **源码隔离** | `dist` 内无任何 `vendor/` 源码 | 把整个 `vendor/` 错误拷入 `dist/` | 冗余且容易触发 AOT 编译符号与动态源码冲突 |
| **fast-route 处理** | `functions.php` 编入二进制内部 | 未编译，却在 `main.stub` 动态 `require` | 启动流程冗余脆弱 |

---

## User Review Required

> [!IMPORTANT]
> 1. **Builder 镜像重新打包与升级版本**：
>    当前的打包机制是由 Docker 镜像中的 `/entrypoint.sh` 负责收集动态库和组装 `dist`。要使构建产物生成 `ext/`、`php.ini` 并补全 `lib/` 依赖库，必须更新 `webman-typephp/docker/entrypoint.sh` 并重新构建发布 builder 镜像（例如 `tinywan/typephp-webman-builder:v0.0.14`）。
> 2. **Monolog 策略选择**：
>    采用参考项目成熟的白名单机制，仅编译 Webman 必需的核心 Logger 与 Handler，确保不引入冷门扩展的 Handler 导致崩溃。

---

## Proposed Changes

### Component 1: `webman-typephp` 编译器配置与启动入口优化

#### [MODIFY] `src/Compiler/ProjectGenerator.php`
- 将 `monolog` 的探测由目录探测调整为核心文件白名单（对齐参考项目 `project.linux.yml`）。
- 将 `vendor/nikic/fast-route/src/functions.php` 编入 `sources`。
- 将默认并发编译 `job` 参数从 `4` 调整为安全默认值或根据可用内存设置。

```diff
--- a/src/Compiler/ProjectGenerator.php
+++ b/src/Compiler/ProjectGenerator.php
@@ -52,6 +52,7 @@ class ProjectGenerator
             'vendor/workerman/coroutine/src',
             'vendor/psr',
+            'vendor/nikic/fast-route/src/functions.php',
             'vendor/nikic/fast-route/src/BadRouteException.php',
             'vendor/nikic/fast-route/src/DataGenerator.php',
@@ -74,3 +75,28 @@
             if (is_dir($this->basePath . '/vendor/monolog/monolog/src')) {
-                $sources[] = 'vendor/monolog/monolog/src';
+                $monologSources = [
+                    'vendor/monolog/monolog/src/Monolog/Logger.php',
+                    'vendor/monolog/monolog/src/Monolog/LogRecord.php',
+                    'vendor/monolog/monolog/src/Monolog/ResettableInterface.php',
+                    'vendor/monolog/monolog/src/Monolog/DateTimeImmutable.php',
+                    'vendor/monolog/monolog/src/Monolog/ErrorHandler.php',
+                    'vendor/monolog/monolog/src/Monolog/Registry.php',
+                    'vendor/monolog/monolog/src/Monolog/Utils.php',
+                    'vendor/monolog/monolog/src/Monolog/Formatter/FormatterInterface.php',
+                    'vendor/monolog/monolog/src/Monolog/Formatter/NormalizerFormatter.php',
+                    'vendor/monolog/monolog/src/Monolog/Formatter/LineFormatter.php',
+                    'vendor/monolog/monolog/src/Monolog/Formatter/JsonFormatter.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/HandlerInterface.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/Handler.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/AbstractHandler.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/AbstractProcessingHandler.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/StreamHandler.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/RotatingFileHandler.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/ErrorLogHandler.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/NullHandler.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/FormattableHandlerInterface.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/FormattableHandlerTrait.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/ProcessableHandlerInterface.php',
+                    'vendor/monolog/monolog/src/Monolog/Handler/ProcessableHandlerTrait.php',
+                    'vendor/monolog/monolog/src/Monolog/Processor',
+                ];
+                foreach ($monologSources as $source) {
+                    if (file_exists($this->basePath . '/' . $source) || is_dir($this->basePath . '/' . $source)) {
+                        $sources[] = $source;
+                    }
+                }
             }
```

#### [MODIFY] `src/Stubs/main.php.stub`
- 对齐 `tinywan-typephp-webman/main.php`，精简启动入口，去除已 AOT 编译文件的重复 `require_once`。

---

### Component 2: Docker 构建器逻辑对标参考项目的 `package.sh`

#### [MODIFY] `docker/entrypoint.sh`
1. **停止错误复制 `vendor` 目录**：
   删除将 `vendor/workerman`、`vendor/nikic` 等源代码复制到 `stage_dir/vendor` 的逻辑。
2. **复制扩展模块 (`dist/ext`)**：
   从系统 PHP 扩展目录（`php-config --extension-dir`）提取 `.so` 扩展至 `$stage_dir/ext/`。
3. **生成自包含 `php.ini`**：
   写入配置并按顺序激活 `posix`, `pcntl`, `curl`, `pdo_mysql`, `mbstring`, `openssl`, `redis` 等扩展。
4. **扩展依赖库扫描与 `ldd` 补全 (`dist/lib`)**：
   像参考项目的 `package.sh` 一样，不仅扫描 `webman-server.bin`，还要扫描 `$stage_dir/ext/*.so`，将扩展所依赖的 `libcurl.so.4` 等系统共享库全部追踪并复制到 `$stage_dir/lib/`。
5. **升级 `start.sh`**：
   加入 `export PHPRC="$app_dir"`。

#### [MODIFY] `src/Commands/PackageCommand.php`
- 将默认镜像版本标签递增（如构建发布后升级为 `v0.0.14`）。

---

## Verification Plan

### Automated / Command Verification
1. **执行代码格式与规范分析**：
   ```powershell
   composer check
   ```
2. **在 Webman 测试项目中生成配置并检查**：
   执行 `php webman typephp:package` 前，检查生成的 `.typephp/build/project.linux.yml` 中的 `sources` 是否包含完整的白名单且无粗暴的全量 `vendor/monolog/monolog/src`。
3. **构建测试与产物结构比对**：
   运行编译后，检查 `dist/` 目录结构：
   - 必须存在 `dist/ext/` 且包含 `curl.so`
   - 必须存在 `dist/php.ini` 且声明 `extension_dir="./ext"` 与 `extension=curl.so`
   - 必须存在 `dist/lib/libcurl.so.4`
   - `dist/` 根目录下不得有 `vendor/` 源码目录
4. **运行测试**：
   在 Linux 环境或容器内运行：
   ```bash
   cd dist && ./start.sh start
   ```
   检查终端输出，确保无 `Undefined constant` 报错，正常输出 Workerman 监听信息。
