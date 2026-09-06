<div align="center">

# Webman TypePHP AOT 构建插件

**为 Webman 注入 TypePHP 原生 AOT 编译能力，将 Webman 应用编译打包为高性能原生可移植目录 (portable-dir)**

<p align="center">
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.4%20%3C8.6-8892BF.svg?style=flat-square&logo=php" alt="PHP Version">
  <img src="https://img.shields.io/badge/Webman-Plugin-blue.svg?style=flat-square" alt="Webman Plugin">
  <img src="https://img.shields.io/badge/TypePHP-AOT-success.svg?style=flat-square" alt="TypePHP">
  <img src="https://img.shields.io/badge/Docker-Builder-2496ED.svg?style=flat-square&logo=docker" alt="Docker">
  <img src="https://img.shields.io/badge/License-MIT-green.svg?style=flat-square" alt="License">
</p>

</div>

## 📖 简介

`tinywan/webman-typephp` 是专为 **Webman** 高性能框架打造的官方标准基础插件。基于 [TypePHP](https://www.swoole.com/)（Swoole 研发的 PHP AOT 编译器），将 Webman 业务源码直接转译为原生机器码 ELF 可执行程序，并自动化装配包含运行时配置、静态资源及视图模板的独立发布目录。

内置配套的预编译 Docker 构建镜像，**开发者宿主机无需安装任何 C++、Clang 或底层编译工具链**，即可在本地一键完成生产发布包的编译组装。

## 🌟 核心特性

- ⚡ **开箱即用**：零环境心智负担，所有 C++ 编译器和底层工具链全部由 Docker 镜像内聚提供。
- 📦 **可移植目录构建**：自动化输出包含 `webman-server` 二进制、`build-manifest.json` 元数据、配置与视图的 `dist/` 独立运行目录。
- 🤖 **自动化依赖生成**：自动分析当前 Webman 项目结构与依赖，动态生成 AOT 专属入口 `main.php` 与 `project.linux.yml`。
- 🛡️ **生产级安全防护**：输出目录防覆盖保护机制（需显式 `--force`），Docker 调度执行严格采用安全参数数组，坚决杜绝命令注入。
- ☁️ **一键 CI/CD 接入**：内置 `php webman typephp:init-ci`，秒级生成 GitHub Actions 自动化构建工作流。

## 🚀 快速开始

### 1. 安装插件

在现有的 Webman 项目中执行 Composer 安装：

```bash
composer require tinywan/webman-typephp --dev
```

### 2. 环境自检

检查当前宿主机环境是否满足基本要求（仅需 PHP 与 Docker）：

```bash
php webman typephp:doctor
```

### 3. 一键打包编译

执行打包构建命令：

```bash
# 默认编译构建
php webman typephp:package

# 若输出目录已存在，使用 --force 覆盖
php webman typephp:package --force
```

### 4. 产物目录结构

编译完成后，项目根目录将生成标准的 `dist/` 目录：

```text
dist/
├── webman-server.bin        # AOT 编译生成的原生机器码二进制程序
├── start.sh                 # 设置运行库路径并启动二进制程序
├── lib/                     # 随包发布的非平台动态依赖
├── build-manifest.json      # 构建元数据清单（记录输入摘要、镜像版本与编译时间）
├── config/                  # 运行时业务配置
├── public/                  # 静态静态资源托管
└── app/view/                # 视图模板文件
```

### 5. 启动运行

将 `dist/` 目录拷贝至任意兼容的 Linux x86_64 服务器（无需在服务器安装 PHP）：

```bash
cd dist

# 前台调试启动
./start.sh start

# 守护进程（后台）启动
./start.sh start -d

# 查看运行状态与停止
./start.sh status
./start.sh stop
./start.sh restart
```

## 🛠️ 命令列表

| 命令 | 选项 | 说明 |
| :--- | :--- | :--- |
| `php webman typephp:package` | `--force`, `-f`<br/>`--image=...`<br/>`--output-dir=...`<br/>`--output-name=...` | 调用 Docker 构建镜像将当前 Webman 项目编译打包为可移植目录 |
| `php webman typephp:doctor` | 无 | 自检本地环境依赖（PHP 8.4~8.5 约束、Docker 运行状态等） |
| `php webman typephp:init-ci` | `--force`, `-f`<br/>`--path=...` | 一键在 `.github/workflows/` 生成自动化构建与发布工作流 |

## ⚙️ 配置文件

安装插件后，配置文件自动发布于 `config/plugin/tinywan/typephp/app.php`：

```php
return [
    'enable' => true,
    // Docker 编译环境配置
    'docker' => [
        'enabled' => true,
        'image' => 'tinywan/typephp-webman-builder:v0.0.10',
    ],
    // 默认输出配置
    'build' => [
        'output_name' => 'webman-server',
        'dist_dir' => 'dist',
        'clean_build' => true,
    ],
    // 编译忽略项
    'ignore' => [
        'config',
        'public',
        'runtime',
        'app/view',
        // ...
    ]
];
```

## 🐳 Docker 构建镜像发布

默认构建镜像为 `tinywan/typephp-webman-builder:v0.0.10`。镜像仅发布与 Git tag 完全一致的版本标签，不发布 `latest`、`alpine` 等浮动标签。

仓库维护者需要在 GitHub 中配置 `DOCKER_USERNAME` 和 `DOCKER_PASSWORD`。其中 `DOCKER_PASSWORD` 必须使用 Docker Hub Access Token，不能使用账户登录密码。推送符合 `vMAJOR.MINOR.PATCH` 格式的 Git tag（例如 `v0.0.10`）后，GitHub Actions 会自动构建并仅推送同名的 Linux amd64 镜像。也可以手动运行工作流，并明确指定版本以及是否推送。

完整发布步骤参见 [RELEASING.md](RELEASING.md)。

## 🧪 代码质量与测试

本插件遵循现代 PHP 规范，采用 [Mago](https://github.com/carthage-software/mago) 与 [Pest](https://pestphp.com) 保障工程质量：

```bash
composer test         # 运行 Pest 单元测试
composer format       # 代码格式化 (Mago)
composer lint         # 代码规范检查 (Mago)
composer analyze      # 静态代码分析 (Mago)
composer check        # 全量质量检测
```

<div align="center">

**如果这个项目对你有帮助，欢迎 ⭐️ Star 支持！**

Made with ❤️ by [Tinywan](https://github.com/Tinywan) · [MIT License](LICENSE)

</div>
