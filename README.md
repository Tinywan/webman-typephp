<div align="center">

# Webman TypePHP

**把 Webman 项目编译为 Linux amd64 的 TypePHP AOT portable-dir**

面向 Webman 1.5+/2.x 的构建插件。编译器由固定版本的 Docker builder 提供，开发机只需要 PHP 与 Docker。

<p>
  <a href="https://packagist.org/packages/tinywan/webman-typephp"><img src="https://img.shields.io/packagist/v/tinywan/webman-typephp?style=flat-square&color=777BB4" alt="Packagist version"></a>
  <a href="https://github.com/Tinywan/webman-typephp/actions"><img src="https://img.shields.io/github/actions/workflow/status/Tinywan/webman-typephp/docker-publish.yml?style=flat-square&label=build" alt="Build status"></a>
</p>

</div>

## 编译链

这是本项目最重要的边界：输入是现有 Webman 源码，输出不是单文件，而是带运行时动态库的 Linux portable-dir。

```text
PHP / Webman 项目
        │  生成 main.php + project.linux.yml
        ▼
固定版本 Docker builder
        │  TypePHP AOT 编译
        ▼
ELF 原生二进制 + lib/ 动态库 + Webman 运行资源
        │
        ▼
dist/portable-dir  ──►  Linux amd64 / glibc
```

当前第一阶段是技术预览：产物面向 `linux/amd64` 与 glibc 环境，依赖随目录分发。不承诺单文件、完全静态链接或所有 Linux 发行版通用。

## 快速开始

### 安装

在 Webman 项目根目录执行：

```bash
composer require tinywan/webman-typephp --dev
```

### 检查环境并构建

```bash
php webman typephp:doctor
php webman typephp:package
```

输出目录已有内容时，必须明确确认覆盖：

```bash
php webman typephp:package --force
```

默认 builder 为 `tinywan/typephp-webman-builder:v0.0.10`。构建过程在 Docker 中运行，宿主机不需要安装 C++、Clang 或 TypePHP 编译器。

### 运行产物

将 `dist/` 复制到兼容的 Linux amd64/glibc 服务器，在目录内启动：

```bash
cd dist && ./start.sh start
```

`start.sh` 会设置随包的 `lib/` 搜索路径，并将参数传给 `webman-server.bin`。

## 产物契约

构建成功后，`dist/` 至少包含以下核心文件；项目中存在的运行资源会按规则复制：

```text
dist/
├── webman-server.bin       # TypePHP 生成的 ELF 原生二进制
├── start.sh                # 设置动态库路径并启动二进制
├── lib/                    # 随包分发的非平台动态库
├── build-manifest.json     # 构建输入、镜像与时间等元数据
├── config/                 # 存在时复制
├── public/                 # 存在时复制
└── app/view/               # 存在时复制
```

`lib/` 用于携带 PHP/PHPX 等构建所需的非平台库；glibc、动态加载器和标准 C/C++ 运行库由目标系统提供。`build-manifest.json` 用于追溯构建，不应写入密钥或令牌。

## 命令与配置

| 命令 | 作用 |
| --- | --- |
| `php webman typephp:package` | 使用默认 builder 构建 portable-dir |
| `php webman typephp:package --force` | 明确覆盖已有输出目录，并保留旧目录备份 |
| `php webman typephp:package --image=...` | 使用经过验证的 Docker 镜像引用 |
| `php webman typephp:doctor` | 检查 PHP 与 Docker 前置条件 |
| `php webman typephp:init-ci` | 生成 Linux amd64 构建工作流 |

安装插件后，可在 `config/plugin/tinywan/typephp/app.php` 调整 Docker 镜像、输出目录、输出名称和源码忽略项。默认配置示例：

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

## 质量检查

项目使用 Pest 编写测试，使用 Mago 进行格式化、lint 与静态分析：

```bash
composer test
composer format:check
composer lint
composer analyze
composer check
```

测试不得连接生产、真实业务或共享数据库；涉及数据库的测试必须使用一次性隔离环境，优先使用 SQLite `:memory:`。

## 维护者：发布 builder 镜像

普通使用者不需要执行本节。builder 镜像由 GitHub Actions 在发布版本 tag 时构建并推送到 Docker Hub：

```text
Git tag v0.0.10
        │
        ▼
GitHub Actions ──► tinywan/typephp-webman-builder:v0.0.10
                   linux/amd64
```

仓库需要配置 `DOCKER_USERNAME` 与 `DOCKER_PASSWORD`，其中密码必须是 Docker Hub Access Token。工作流只发布与 Git tag 完全一致的版本标签，不发布 `latest`、`alpine` 或其他浮动标签。

完整流程、手动构建和凭据说明见 [RELEASING.md](RELEASING.md)。

## 方案边界与后续阶段

第一阶段只承诺已验证的 Webman 入口、显式源码配置、Linux amd64/glibc portable-dir、构建追溯信息和覆盖保护。

第二阶段将补充 Composer 依赖审计与兼容性预检、更多 Webman/扩展 fixtures、ARM64 构建与测试覆盖，以及基于版本、源码和配置摘要的增量缓存。完全静态链接、资源嵌入、单文件二进制和更广泛的 Linux 兼容性，须在独立验证后再形成对外承诺。

## 相关链接

- [TypePHP 项目方案](TYPEPHP_PLUGIN_PROPOSAL.md)
- [发布指南](RELEASING.md)
- [问题反馈](https://github.com/Tinywan/webman-typephp/issues)
- [Tinywan](https://github.com/Tinywan)

## License

MIT © [Tinywan](https://github.com/Tinywan)
