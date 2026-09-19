<div align="center">

# Webman TypePHP AOT Build Plugin

**Compile Webman applications into portable, production-ready Linux native artifacts**

<p align="center">
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.4%20%3C8.6-8892BF.svg?style=flat-square&logo=php" alt="PHP Version">
  <img src="https://img.shields.io/badge/Webman-Plugin-2F80ED.svg?style=flat-square" alt="Webman Plugin">
  <img src="https://img.shields.io/badge/TypePHP-AOT-2E9E5B.svg?style=flat-square" alt="TypePHP AOT">
  <img src="https://img.shields.io/badge/Docker-Builder-2496ED.svg?style=flat-square&logo=docker" alt="Docker Builder">
  <img src="https://img.shields.io/badge/License-MIT-2E9E5B.svg?style=flat-square" alt="MIT License">
</p>

[English](README.md) | [中文](README.zh-CN.md)

</div>

## 📖 Introduction

`tinywan/webman-typephp` is a [TypePHP AOT](https://swoole.com/aot/zh) build plugin designed for Webman 2.x. It automatically inspects existing Webman projects, generates AOT compilation entrypoints and configurations, builds the application using pinned Docker builder images, and packages a deployment-ready `dist/` directory for target Linux servers.

The host system only requires PHP, Composer, and Docker. No C++, Clang, or TypePHP compilation toolchains need to be installed locally.

## 🚀 Quick Start

### 1. Install Plugin

Run the following command in your Webman project root:

```bash
composer require tinywan/webman-typephp --dev
```

### 2. Environment Check

Check and verify PHP version, Docker CLI, and Docker daemon availability:

```bash
php webman typephp:doctor
```

### 3. Build & Package

```bash
# Default build output to dist/
php webman typephp:package

# Force overwrite if dist/ already exists
php webman typephp:package --force

# Force refresh main.php entrypoint with the latest official TypePHP stub (existing main.php is backed up to main.php.bak)
php webman typephp:package --refresh-main

# Build a fully-static single binary executable (zero dynamic library dependencies)
php webman typephp:package --static
```

The default builder image is `tinywan/typephp-webman-builder:v0.2.1` (portable dynamic directory); static mode uses `tinywan/typephp-webman-builder-static:v0.2.1`. Compilation runs entirely within Docker containers.

### 4. Run Artifacts

Copy the `dist/` directory to a compatible Linux x86_64 server and run:

```bash
cd dist
./start.sh start
```

`start.sh` automatically configures `PHPRC` to load bundled `php.ini` and sets dynamic library search paths to the packaged `lib/`. Standard Workerman management commands are fully supported:

```bash
./start.sh start -d
./start.sh status
./start.sh stop
./start.sh restart
```

You can also launch via the wrapper script directly:
```bash
./webman-server start
```

## 📦 Artifact Contract

Depending on the packaging command, the build artifacts are produced in two forms:

### Mode 1: Fully-Static Single Binary (`php webman typephp:package --static`)

Outputs a statically linked standalone executable with **zero external dynamic library dependencies**. It does not require any pre-installed PHP environment or glibc on the target system (runs directly on Alpine, BusyBox, or minimal Linux containers):

```text
dist/
├── webman-server           # Statically linked standalone binary (ELF 64-bit statically linked, stripped, ~17MB)
├── start.sh                # Standard Workerman start script (exec ./webman-server "$@")
├── build-manifest.json     # Build metadata including inputs, builder image, and timestamps
├── config/                 # Application runtime configurations (supports hot edits and reload)
├── public/                 # Static web assets (HTML, CSS, JS, images, etc.)
├── app/
│   ├── view/               # View templates (if present)
│   └── functions.php       # Custom global functions (if present)
└── runtime/                # Runtime cache and log directory (logs, views)
```

#### Key Highlights
* **Zero External Dependencies**: Fully self-contained single binary; no `libphp.so`, `ext/*.so`, `lib/` directory, or external `php.ini` needed.
* **Universal Distro Compatibility**: Compatible with any Linux x86_64 environment (Alpine, Ubuntu, Debian, CentOS, BusyBox, etc.).
* **Ready Out of the Box**: Run the executable directly without system dependency installations.

#### Minimal Containerization Example (Optional)
Thanks to static linking, you can build an ultra-lightweight production container (~20MB) using the official 5MB Alpine image:

```dockerfile
FROM alpine:latest

WORKDIR /app
COPY dist /app

EXPOSE 8787
CMD ["./webman-server", "start"]
```

---

### Mode 2: Portable Dynamic Directory (`php webman typephp:package`)

Outputs a self-contained portable directory with a precompiled `libphp.so` core runtime and bundled dynamic extensions. Best suited for workloads requiring native Linux `.so` extensions:

```text
dist/
├── webman-server.bin       # TypePHP generated native ELF executable (dynamically linked)
├── webman-server           # Launcher wrapper (configures LD_LIBRARY_PATH and PHPRC)
├── start.sh                # Workerman process management script
├── libphp.so               # PHP core shared library
├── libphpx.so              # PHPX runtime shared library
├── php.ini                 # Isolated clean PHP runtime configuration
├── ext/                    # Bundled PHP core & network extensions (.so)
├── lib/                    # Underlying system & extension dynamic libraries (collected via ldd)
├── build-manifest.json     # Build metadata
├── config/                 # Application runtime configuration
├── public/                 # Static web assets
├── app/
│   ├── view/               # View templates (if present)
│   └── functions.php       # Custom global functions (if present)
└── runtime/                # Runtime logs and cache (logs, views)
```

#### Key Highlights
* **Rich Extension Ecosystem**: Supports precompiled `.so` extensions installed from Linux package managers.
* **Flexible Maintenance**: The executable and underlying runtime libraries (`libphp.so` / `ext/*.so`) are decoupled for granular updates.
* **Standard glibc Compatibility**: Compatible with Linux distributions using glibc 2.31+ (Ubuntu 20.04+, Debian 11+, RHEL 9+, etc.).

---

## 🛠️ Commands

| Command | Description |
| --- | --- |
| `php webman typephp:package` | Build Linux portable directory using the default builder |
| `php webman typephp:package --static` | Build fully-static single binary executable (zero external dynamic library dependencies) |
| `php webman typephp:package --force` | Overwrite existing output and preserve backup of old dist directory |
| `php webman typephp:package --refresh-main` | Force refresh `main.php` from official TypePHP stub (backs up existing file) |
| `php webman typephp:package --image=...` | Use a custom verified Docker image |
| `php webman typephp:doctor` | Check PHP, Docker, and build prerequisites |
| `php webman typephp:init-ci` | Generate Linux amd64 GitHub Actions workflow |

## ⚙️ Configuration

After installing the plugin, the configuration file is located at `config/plugin/tinywan/typephp/app.php`:

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

The `--image` CLI option takes highest precedence; if omitted, `docker.image` from config is used, falling back to `tinywan/typephp-webman-builder:v0.2.1`.

## 🎯 MVP Scope & Boundaries

The current phase supports the verified combinations:

- Target platform: `linux/amd64`.
- Runtime: glibc dynamic portable-dir and musl fully-static single binary.
- Delivery: Standalone binary or `webman-server.bin` + `start.sh` + `lib/` + Webman runtime resources.
- Build mechanism: Pinned Docker builder images aligned with repository release tags.

Future roadmaps include Composer dependency audits, expanded Webman extension fixtures, ARM64 builds/tests, and incremental caching.

## 🐳 Maintainers: Publishing Docker Builders

Regular users do not need this section. Pushing a Git tag matching `vMAJOR.MINOR.PATCH` (e.g. `v0.2.1`) triggers GitHub Actions to build and push Linux amd64 images automatically:

```text
Git tag v0.2.1  →  GitHub Actions  →  tinywan/typephp-webman-builder:v0.2.1
```

The repository requires `DOCKER_USERNAME` and `DOCKER_PASSWORD` (Docker Hub Access Token) secrets. Workflows only publish exact version tags and do not push `latest` or floating tags.

See [RELEASING.md](RELEASING.md) for details.

## 🧪 Quality & Testing

Tests are written with [Pest](https://pestphp.com), formatted and linted with [Mago](https://github.com/carthage-software/mago):

```bash
composer test
composer format:check
composer lint
composer analyze
composer check
```

Tests must not connect to production, shared, or external databases. Tests requiring databases must run in isolated temporary environments (e.g., SQLite `:memory:`).

## 🗺️ Documentation & References

- [TypePHP AOT Documentation](https://swoole.com/aot/zh)
- [TypePHP Plugin Proposal](TYPEPHP_PLUGIN_PROPOSAL.md)
- [Release Guide](RELEASING.md)
- [Issue Tracker](https://github.com/Tinywan/webman-typephp/issues)
- [Tinywan](https://github.com/Tinywan)

## License

MIT © [Tinywan](https://github.com/Tinywan)
