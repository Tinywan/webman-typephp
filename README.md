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

`tinywan/webman-typephp` 是面向 Webman 2.x 的 [TypePHP AOT](https://swoole.com/aot/zh) 构建插件。它会从现有 Webman 项目生成 AOT 入口和 Linux 编译配置，再交给固定版本的 Docker builder 完成编译，最后整理出可以复制到目标服务器的 `dist/` 目录。

宿主机只需要 PHP、Composer 和 Docker，不需要安装 C++、Clang 或 TypePHP 编译工具链。

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

### 3. 编译打包

```bash
# 默认输出到 dist/
php webman typephp:package

# dist/ 已存在时，显式确认覆盖
php webman typephp:package --force

# 强制使用最新 TypePHP stub 重置 main.php 入口（原有 main.php 会自动备份为 main.php.bak）
php webman typephp:package --refresh-main

# 构建全静态单文件可执行产物 (零动态库依赖，单二进制)
php webman typephp:package --static
```

默认 builder 为 `tinywan/typephp-webman-builder:v0.2.1`（动态便携包）；全静态模式使用 `tinywan/typephp-webman-builder-static:v0.2.1`。编译在 Docker 中完成，宿主机不需要 C++、Clang 或 TypePHP 编译器。

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

根据打包命令的不同，构建产物呈现两种专业形态：

### 形态 1：全静态单二进制产物 (`php webman typephp:package --static`)

输出纯静态链接（Statically Linked）单一可执行程序，**零外部动态库依赖**，甚至不需要目标系统存在任何 PHP 环境或 glibc（支持在 Alpine、BusyBox 或任何最小 Linux 容器上直接运行）：

```text
dist/
├── webman-server           # 纯静态链接单二进制 (ELF 64-bit statically linked, stripped，约 17MB)
├── start.sh                # 标准 Workerman 启动脚本（直接 exec ./webman-server "$@"）
├── build-manifest.json     # 输入、镜像与时间等构建元数据
├── config/                 # 业务运行时配置（支持修改配置并动态热生效）
├── public/                 # 静态 Web 资源（HTML、CSS、JS、静态图片等）
├── app/
│   ├── view/               # 视图模板文件（若存在）
│   └── functions.php       # 用户自定义全局函数（若存在）
└── runtime/                # 运行时缓存与日志目录 (logs, views)
```

> **特点**：
> - **无动态依赖**：不需要 `libphp.so`，不需要 `ext/*.so`，不需要 `lib/` 动态库目录，也不需要外部 `php.ini`。
> - **超轻量容器**：可直接使用 5MB 的 `alpine:latest` 或 `scratch` 基础镜像制作 20MB 左右的生产级超轻镜像：
>   ```dockerfile
>   FROM alpine:latest
>   COPY dist /app
>   WORKDIR /app
>   EXPOSE 8787
>   CMD ["./webman-server", "start"]
>   ```

---

### 形态 2：动态便携目录产物 (`php webman typephp:package`)

输出自包含的绿色便携目录，自带预编译 `libphp.so` 核心运行时与动态扩展，适合需要灵活挂载或使用 Linux 原生 `.so` 扩展的场景：

```text
dist/
├── webman-server.bin       # TypePHP 生成的 ELF 原生可执行文件（动态链接）
├── webman-server           # 启动包装脚本（自动配置 LD_LIBRARY_PATH 与 PHPRC）
├── start.sh                # 标准 Workerman 启动管理脚本
├── libphp.so               # PHP 核心运行时共享库
├── libphpx.so              # PHPX 运行时共享库
├── php.ini                 # 自包含纯净 PHP 运行时配置
├── ext/                    # 随包分发的 PHP 核心与网络扩展模块 (.so)
├── lib/                    # 随包发布的底层系统与扩展动态依赖库 (通过 ldd 完整收集)
├── build-manifest.json     # 输入、镜像与时间等构建元数据
├── config/                 # 业务运行时配置
├── public/                 # 静态 Web 资源
├── app/
│   ├── view/               # 视图模板（若存在）
│   └── functions.php       # 自定义全局函数（若存在）
└── runtime/                # 运行时缓存与日志目录 (logs, views)
```

`lib/` 携带构建及扩展所需的所有动态依赖库；glibc、动态加载器由目标系统提供。`build-manifest.json` 用于追踪构建，不应写入密钥、令牌或其他敏感信息。

## 🛠️ 命令

| 命令 | 说明 |
| --- | --- |
| `php webman typephp:package` | 使用默认 builder 构建 Linux portable-dir |
| `php webman typephp:package --static` | 构建全静态单文件可执行二进制（零外部动态库依赖） |
| `php webman typephp:package --force` | 覆盖已有输出，并保留旧目录备份 |
| `php webman typephp:package --refresh-main` | 强制从最新官方 stub 刷新 `main.php`（旧文件自动备份） |
| `php webman typephp:package --image=...` | 使用指定且经过验证的 Docker 镜像 |
| `php webman typephp:doctor` | 检查 PHP、Docker 和构建前置条件 |
| `php webman typephp:init-ci` | 生成 Linux amd64 GitHub Actions 工作流 |

## ⚙️ 配置

安装插件后，配置文件位于 `config/plugin/tinywan/typephp/app.php`：

```php
return [
    'enable' => true,
    'docker' => [
        'enabled' => true,
        'image' => 'tinywan/typephp-webman-builder:v0.2.1',
        'static_image' => 'tinywan/typephp-webman-builder-static:v0.2.1',
    ],
    'build' => [
        'output_name' => 'webman-server',
        'dist_dir' => 'dist',
        'clean_build' => true,
    ],
];
```

`--image` 的优先级最高；未指定时使用上述 `docker.image`，配置缺失时才回退至 `tinywan/typephp-webman-builder:v0.2.1`。

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
