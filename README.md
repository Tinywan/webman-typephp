<div align="center">

# Webman TypePHP AOT 构建插件

**把 Webman 应用编译成可复制、可启动的 Linux 原生发布目录**

<p align="center">
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.4%20%3C8.6-8892BF.svg?style=flat-square&logo=php" alt="PHP Version">
  <img src="https://img.shields.io/badge/Webman-Plugin-2F80ED.svg?style=flat-square" alt="Webman Plugin">
  <img src="https://img.shields.io/badge/TypePHP-AOT-2E9E5B.svg?style=flat-square" alt="TypePHP AOT">
  <img src="https://img.shields.io/badge/Docker-Builder-2496ED.svg?style=flat-square&logo=docker" alt="Docker Builder">
  <img src="https://img.shields.io/badge/License-MIT-2E9E5B.svg?style=flat-square" alt="MIT License">
</p>

</div>

## 📖 简介

`tinywan/webman-typephp` 是面向 Webman 2.x 的 [TypePHP AOT](https://swoole.com/aot/zh) 构建插件。它会从现有 Webman 项目生成 AOT 入口和编译配置，可交给固定版本的 Docker builder 生成 Linux portable-dir，也可直接调用宿主机 TypePHP 工具链生成当前平台原生程序。

Linux portable-dir 模式下，宿主机只需要 PHP、Composer 和 Docker。原生模式不使用 Docker，但需要匹配 ABI 的 PHP embed SDK、PHPX、TypePHP 和 C++17 编译器。

## 🌟 核心特性

- ⚡ **两种构建路径**：`typephp:package` 使用 Docker 生成 Linux portable-dir；`typephp:compile` 直接调用宿主机 TypePHP、PHPX 与 C++ 编译器生成当前平台程序。
- 🧩 **全版本 Webman 兼容**：自动把 `webman-framework` 的 `helpers.php` 与 `fast-route` 的 `functions.php` 平铺为 AOT 专用版本（`.typephp/build/`），规避新版框架顶层 `if` 守卫触发的 `Unsupported statement: Stmt_If` 编译错误或静默跳过，同时保证 `base_path()`、`config()`、`FastRoute\simpleDispatcher()` 等全局函数完整编译进二进制。
- 🩹 **协程静态属性补丁**：自动把 `workerman/coroutine` 的 `Context`/`WaitGroup`/`Barrier` 中未初始化的标量静态属性补成可空并默认 `null`（`.typephp/build/`），规避 TypePHP 编译产物把未初始化标量静态读作零值导致 `??=` 守卫失效、进而触发 `Invalid callback ::destroy` 崩溃循环的问题。
- 🔧 **可变参数闭包补丁**：自动把 `Worker`/`TcpConnection`/`AsyncTcpConnection`/`Select`/webman `File` 中签名不足的错误处理与信号闭包补成可变参数形态（`.typephp/build/`），规避 TypePHP 编译产物对闭包调用强制精确参数个数（PHP 语义允许多传忽略）导致每次 accept 抛 `ArgumentCountError`、worker 崩溃循环的问题。
- 🧹 **顶层引导调用剥离**：自动删除 `workerman` 的 `Http/Session`、`FileSessionHandler` 与 `workerman/coroutine` 的 `Context`/`Coroutine` 系列文件尾部 `Session::init();` 之类顶层调用（`.typephp/build/`），规避 AOT 编译器只接受顶层声明而在扫描期抛 `All execution code must be within a function, found stray code` Fatal；这些初始化由 `main.php` 入口带 `class_exists` 守卫在启动时完成，行为不变。
- 🔀 **switch 终结补丁**：自动把 webman `App::stringify()` 落空到 `default`、`Monolog\Utils` 缺 `break` 的 JSON 错误分支与 `g/m/k` 级联连乘改写为语义等价的终结形态（`.typephp/build/`），规避 TypePHP 要求每个非空 case 以 `return`/`break`/`throw` 结尾的 `switch case must end with` Fatal。
- 🪝 **类型化引用捕获补丁**：自动改写 webman `Route::url()` 与协程 `Barrier`/`Channel` Fiber 驱动中「先赋值再 `use (&$var)`」的捕获形态（`.typephp/build/`），规避 TypePHP v0.8 类型化引用体系对固定类型变量抛 `Cannot create a reference to variable of fixed type` Fatal。
- 📦 **目录化交付**：输出原生二进制、启动脚本、运行库和 Webman 资源，结构清晰、便于发布。
- 🛡️ **安全默认值**：已有输出不会被静默覆盖；必须显式使用 `--force`，旧目录会先备份。
- 🧾 **可追溯构建**：`build-manifest.json` 记录入口、配置与每组 AOT 生成源的输入摘要、镜像和构建时间，不写入密钥或令牌。
- ☁️ **CI/CD 就绪**：可生成 Linux amd64 构建工作流，并由 Git tag 触发 Docker Hub 镜像发布。

## 🚀 快速开始

### 1. 安装插件

在 Webman 项目根目录执行：

```bash
composer require tinywan/webman-typephp --dev
```

### 2. 检查环境

确认 PHP 版本、Docker CLI 和 Docker daemon 可用：

```bash
php webman typephp:doctor
```

检查不使用 Docker 的宿主机原生工具链：

```bash
php webman typephp:doctor --target=native
```

### 3. 编译打包

```bash
# 默认输出到 dist/
php webman typephp:package

# SaiAdmin：自动发现核心、app、support 与已安装插件服务端业务代码
php webman typephp:package --profile=saiadmin

# dist/ 已存在时，显式确认覆盖
php webman typephp:package --force

# 强制使用最新 TypePHP stub 重置 main.php 入口（原有 main.php 会自动备份为 main.php.bak）
php webman typephp:package --refresh-main
```

默认 builder 为 `tinywan/typephp-webman-builder:v0.1.3`。编译在 Docker 中完成，宿主机不需要 C++、Clang 或 TypePHP 编译器。

直接调用宿主机 TypePHP 和 clang 编译当前平台程序：

```bash
php webman typephp:doctor --target=native
php webman typephp:compile --profile=saiadmin
```

原生模式输出 `build/webman-server` 及
`.typephp/build/native-build-manifest.json`。它不会启动 Docker，也不会把当前平台程序包装成 Linux
portable-dir；macOS 构建结果是 Mach-O，只能在 ABI 兼容的 macOS 环境运行。
如果项目中已经存在旧版本插件生成的 `main.php`，升级后首次重建应增加
`--refresh-main`；命令会先保留 `main.php.bak`。

SaiAdmin 的支持矩阵、开发规范、存量迁移、配置样例、验收脚本和实跑证据见
[`docs/saiadmin-aot/`](docs/saiadmin-aot/README.md)。

### 4. 启动产物

将 `dist/` 复制到兼容的 Linux x86_64/glibc 服务器，在目录内启动：

```bash
cd dist
./start.sh start
```

`start.sh` 会自动指定 `PHPRC` 加载随包的 `php.ini`，并将随包发布的 `lib/` 加入动态库搜索路径。也支持 Webman 常用命令：

```bash
./start.sh start -d
./start.sh status
./start.sh stop
./start.sh restart
```

也可以直接通过包装脚本启动：
```bash
./webman-server start
```

## 📦 产物契约

成功构建后的完整目录结构如下（与全静态便携环境 100% 对齐）：

```text
dist/
├── webman-server.bin       # TypePHP 生成的 ELF 原生二进制
├── webman-server           # 启动包装脚本（自动载入 php.ini 与底层动态库）
├── start.sh                # 标准 Workerman 启动脚本
├── libphp.so               # PHP 核心运行时共享库
├── libphpx.so              # PHPX 运行时共享库
├── php.ini                 # 自包含纯净 PHP 运行时配置
├── ext/                    # 随包分发的 PHP 核心与网络扩展模块 (.so)
├── lib/                    # 随包发布的底层系统与扩展动态依赖库 (ldd 完整收集)
├── runtime/                # 运行时缓存与日志目录 (logs, views)
├── build-manifest.json     # 输入、镜像与时间等构建元数据
├── source-coverage.json    # SaiAdmin profile 的逐业务文件 AOT 覆盖清单
├── config/                 # 项目运行时配置（若存在）
├── public/                 # 静态资源（若存在）
└── app/view/               # 视图模板（若存在）
```

`lib/` 携带构建及扩展所需的所有动态依赖库；glibc、动态加载器由目标系统提供。`build-manifest.json` 用于追踪构建，不应写入密钥、令牌或其他敏感信息。

## 🛠️ 命令

| 命令 | 说明 |
| --- | --- |
| `php webman typephp:package` | 使用默认 builder 构建 Linux portable-dir |
| `php webman typephp:package --profile=saiadmin` | 使用锁版本、失败关闭的 SaiAdmin 自动发现与兼容规则构建 |
| `php webman typephp:compile --profile=saiadmin` | 不使用 Docker，调用本机 TypePHP 工具链编译当前平台原生程序 |
| `php webman typephp:package --force` | 覆盖已有输出，并保留旧目录备份 |
| `php webman typephp:package --refresh-main` | 强制从最新官方 stub 刷新 `main.php`（旧文件自动备份） |
| `php webman typephp:package --image=...` | 使用指定且经过验证的 Docker 镜像 |
| `php webman typephp:doctor` | 检查 PHP、Docker 和构建前置条件 |
| `php webman typephp:doctor --target=native` | 检查 PHP embed、PHPX、TypePHP 和本机 C++ 编译器 |
| `php webman typephp:init-ci` | 生成 Linux amd64 GitHub Actions 工作流 |

## ⚙️ 配置

安装插件后，配置文件位于 `config/plugin/tinywan/typephp/app.php`：

```php
return [
    'enable' => true,
    'docker' => [
        'enabled' => true,
        'image' => 'tinywan/typephp-webman-builder:v0.1.3',
    ],
    'native' => [
        'tpc' => null,
        'php' => null,
        'php_home' => null,
        'phpx_home' => null,
        'cxx' => null,
    ],
    'build' => [
        'output_name' => 'webman-server',
        'dist_dir' => 'dist',
        'clean_build' => true,
    ],
];
```

`--image` 的优先级最高；未指定时使用上述 `docker.image`，配置缺失时才回退至 `tinywan/typephp-webman-builder:v0.1.3`。

原生工具链默认按环境变量和常见 Composer/Homebrew 路径发现。自动发现不适用时，可通过
`native.*`、`PHP_HOME`、`PHPX_HOME`、`TYPEPHP_TPC`、`CXX`，或
`typephp:compile` 的同名命令选项显式指定。PHP 可执行文件、`php-config`、头文件、
`libphp` 和 `libphpx` 必须来自同一 PHP 8.4/8.5 ABI；发现版本混用时命令会在编译前失败。

### SaiAdmin profile

`--profile=saiadmin` 要求存在 `plugin/saiadmin` 和有效的 `composer.lock`。profile
沿用本插件 Composer 对 Webman 的版本约束，不再额外设置 Webman、Workerman 或
SaiAdmin 版本白名单。安全边界由 Composer 可安装约束、SaiAdmin 安装源码一致性、
兼容规则预期命中数和完整编译共同保证；源码结构漂移会失败关闭。当前仍会精确校验
ThinkORM `v3.0.34` 和 Carbon `3.13.2`，因为相关兼容规则尚未完成跨版本验证。

已验证版本分层如下：SaiAdmin `6.1.1` 与 `6.1.5` 均已完成 Linux amd64
编译、打包和隔离 MySQL 业务验收。`6.1.5` 的普通 PHP 8.4 对照中，验证码、
登录和用户信息通过；权限异常路径仍受上游隐式 nullable deprecation 影响，具体
证据边界见 [支持矩阵](docs/saiadmin-aot/docs/compatibility-matrix.md) 与
[验证证据](docs/saiadmin-aot/docs/verification-evidence.md)。

profile 会自动发现根 `app/`、`support/`、SaiAdmin 核心和每个 `plugin/*/app/`，并编译完整 Composer 依赖树。兼容副本只写入 `.typephp/build/`，普通 PHP 源码不变。构建输出中的 `source-coverage.json` 逐项记录业务 PHP 是直接编译还是由哪个 AOT 副本替代；业务文件未分类、被排除却没有等价副本，或漂移到未知依赖版本时都会终止构建。

## 🎯 可信 MVP 边界

当前第一阶段只承诺已经验证的组合：

- 目标平台：`linux/amd64`。
- 运行时：glibc 动态 portable-dir。
- 交付方式：`webman-server.bin`、`start.sh`、`lib/` 与 Webman 运行资源组合分发。
- 编译方式：固定版本 Docker builder，镜像版本与发布 tag 对齐。

当前不宣称单文件、完全静态链接或所有 Linux 发行版通用。目标服务器需要兼容的 x86_64/glibc 运行环境。

第二阶段计划包括 Composer 依赖审计、更多 Webman/扩展 fixtures、ARM64 构建与测试、增量缓存，以及在独立验证后再评估静态链接、资源嵌入和单文件交付。

## 🐳 维护者：发布 Docker builder

普通使用者无需执行本节。推送符合 `vMAJOR.MINOR.PATCH` 格式的 Git tag（例如 `v0.0.10`）后，GitHub Actions 会自动构建并推送同名的 Linux amd64 镜像：

```text
Git tag v0.0.10  →  GitHub Actions  →  tinywan/typephp-webman-builder:v0.0.10
```

仓库需要配置 `DOCKER_USERNAME` 和 `DOCKER_PASSWORD`，其中密码必须是 Docker Hub Access Token。工作流只发布与 Git tag 完全一致的版本标签，不发布 `latest`、`alpine` 或其他浮动标签。

完整流程参见 [RELEASING.md](RELEASING.md)。

## 🧪 质量与测试

项目使用 [Pest](https://pestphp.com) 编写测试，使用 [Mago](https://github.com/carthage-software/mago) 进行格式化、lint 和静态分析：

```bash
composer test
composer format:check
composer lint
composer analyze
composer check
```

测试不得连接生产、真实业务或共享数据库；涉及数据库时必须使用一次性隔离环境，优先使用 SQLite `:memory:`。

## 🗺️ 相关文档

- [TypePHP AOT 官方文档](https://swoole.com/aot/zh)
- [TypePHP 插件方案](TYPEPHP_PLUGIN_PROPOSAL.md)
- [发布指南](RELEASING.md)
- [问题反馈](https://github.com/Tinywan/webman-typephp/issues)
- [Tinywan](https://github.com/Tinywan)

## License

MIT © [Tinywan](https://github.com/Tinywan)
