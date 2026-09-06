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

`tinywan/webman-typephp` 是面向 Webman 1.5+/2.x 的 TypePHP AOT 构建插件。它会从现有 Webman 项目生成 AOT 入口和 Linux 编译配置，再交给固定版本的 Docker builder 完成编译，最后整理出可以复制到目标服务器的 `dist/` 目录。

宿主机只需要 PHP、Composer 和 Docker，不需要安装 C++、Clang 或 TypePHP 编译工具链。

## 🌟 核心特性

- ⚡ **一键构建**：自动生成 `main.php` 与 `project.linux.yml`，统一调度 Docker 编译环境。
- 📦 **目录化交付**：输出原生二进制、启动脚本、运行库和 Webman 资源，结构清晰、便于发布。
- 🛡️ **安全默认值**：已有输出不会被静默覆盖；必须显式使用 `--force`，旧目录会先备份。
- 🧾 **可追溯构建**：`build-manifest.json` 记录输入摘要、镜像和构建时间，不写入密钥或令牌。
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

### 3. 编译打包

```bash
# 默认输出到 dist/
php webman typephp:package

# dist/ 已存在时，显式确认覆盖
php webman typephp:package --force
```

默认 builder 为 `tinywan/typephp-webman-builder:v0.0.11`。编译在 Docker 中完成，宿主机不需要 C++、Clang 或 TypePHP 编译器。

### 4. 启动产物

将 `dist/` 复制到兼容的 Linux x86_64/glibc 服务器，在目录内启动：

```bash
cd dist
./start.sh start
```

`start.sh` 会自动把随包发布的 `lib/` 加入动态库搜索路径，并将参数传给 `webman-server.bin`。也支持 Webman 常用命令：

```bash
./start.sh start -d
./start.sh status
./start.sh stop
./start.sh restart
```

## 📦 产物契约

成功构建后的核心目录如下：

```text
dist/
├── webman-server.bin       # TypePHP 生成的 ELF 原生二进制
├── start.sh                # 设置 lib/ 路径并启动二进制
├── lib/                    # 随包发布的非平台动态依赖
├── build-manifest.json     # 输入、镜像与时间等构建元数据
├── config/                 # 项目运行时配置（若存在）
├── public/                 # 静态资源（若存在）
└── app/view/               # 视图模板（若存在）
```

`lib/` 只携带构建所需的非平台动态库；glibc、动态加载器以及标准 C/C++ 运行库由目标系统提供。`build-manifest.json` 用于追踪构建，不应写入密钥、令牌或其他敏感信息。

## 🛠️ 命令

| 命令 | 说明 |
| --- | --- |
| `php webman typephp:package` | 使用默认 builder 构建 Linux portable-dir |
| `php webman typephp:package --force` | 覆盖已有输出，并保留旧目录备份 |
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
        'image' => 'tinywan/typephp-webman-builder:v0.0.10',
    ],
    'build' => [
        'output_name' => 'webman-server',
        'dist_dir' => 'dist',
        'clean_build' => true,
    ],
];
```

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

- [TypePHP 插件方案](TYPEPHP_PLUGIN_PROPOSAL.md)
- [发布指南](RELEASING.md)
- [问题反馈](https://github.com/Tinywan/webman-typephp/issues)
- [Tinywan](https://github.com/Tinywan)

## License

MIT © [Tinywan](https://github.com/Tinywan)
