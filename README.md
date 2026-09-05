# Webman TypePHP AOT 编译打包插件

<div align="center">

# ⚡ webman-typephp

**为 Webman 注入 TypePHP AOT 静态编译能力，生成 6MB 零依赖原生单二进制文件**

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-8892BF.svg?style=flat-square&logo=php" alt="PHP Version">
  <img src="https://img.shields.io/badge/Webman-Plugin-blue.svg?style=flat-square" alt="Webman Plugin">
  <img src="https://img.shields.io/badge/Docker-Builder-2496ED.svg?style=flat-square&logo=docker" alt="Docker">
  <img src="https://img.shields.io/badge/License-MIT-green.svg?style=flat-square" alt="License">
</p>

</div>

---

## 🌟 核心特性

- ⚡ **零环境负担**：无需在开发机上安装复杂 C++17、Clang、Musl-libc 或 GMP/MPFR 工具链，内置 Docker 容器一键编译。
- 📦 **全静态单文件**：输出仅 **~6MB** 的 Musl 静态可执行文件（ELF），不依赖宿主机 glibc，兼容任意 Linux 发行版与 Docker Scratch 镜像。
- 🤖 **自动化依赖生成**：自动分析当前 Webman 项目结构与已安装的 Composer 依赖，动态生成 AOT 引导入口 `main.php` 与 `project.linux.yml`。
- ☁️ **CI/CD 自动化**：提供 `php webman typephp:init-ci` 一键生成 GitHub Actions 自动化发布流水线。

---

## 🚀 快速使用

### 1. 在 Webman 项目中安装插件

```bash
composer require tinywan/webman-typephp --dev
```

### 2. 一键编译打包

只要本地安装了 Docker（Docker Desktop 或 Docker CE），运行：

```bash
php webman typephp:package
```

插件会自动：
1. 分析当前 Webman 项目并生成 AOT 引导入口 `main.php`；
2. 动态扫描已安装依赖生成 `project.linux.yml`；
3. 唤起 `tinywan/typephp-builder:alpine` 容器完成转译与 Musl 全静态链接；
4. 在项目根目录的 `dist/` 输出可执行文件 `dist/webman-server`。

### 3. 运行服务

将 `dist/` 拷贝到任何 Linux 服务器（甚至不需要安装 PHP）：

```bash
cd dist
./webman-server start        # 前台调试启动
./webman-server start -d     # 守护进程（后台）启动
./webman-server status       # 查看运行状态
./webman-server stop         # 优雅停止
```

---

## 🛠️ 命令列表

| 命令 | 说明 |
| :--- | :--- |
| `php webman typephp:package` | 默认启动 Docker 容器全自动编译打包为纯静态单二进制 |
| `php webman typephp:doctor` | 本地环境自检（PHP 版本、Docker 状态、Clang 编译器等） |
| `php webman typephp:init-ci` | 一键生成 `.github/workflows/typephp-build.yml`，打 Tag 即可自动云端构建 |

---

## 🐳 Docker 构建镜像说明

本插件自带配套的轻量 Alpine 编译环境定义（位于 `docker/` 目录）：
- 镜像仓库：`tinywan/typephp-builder:alpine`
- 支持当 `docker/` 发生改动时，通过 GitHub Actions 自动构建并推送到 Docker Hub。

---

## 📄 开源许可

本项目遵循 [MIT](LICENSE) 开源许可协议。
