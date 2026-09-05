# Webman TypePHP 构建插件（技术预览）

`tinywan/webman-typephp` 为 Webman 提供受约束、可测试的 TypePHP AOT 构建集成。
第一阶段仅支持 **Linux x86_64**，输出 **portable-dir（可移植目录）**，不承诺
通用的全静态单文件二进制。

## MVP 范围

技术预览仅面向 TypePHP 兼容子集，以及项目显式配置的源文件。动态类名、反射、
运行时 `require`、任意 Composer 包和未列出的 PHP 扩展不在保证范围内。

Docker 构建器内置固定版本的 TypePHP 工具链；安装插件不会把 TypePHP 安装到宿主
项目的 `vendor/`。产物包含 `webman-server`，以及存在时的运行时配置、公共资源
和视图。第一阶段不宣传静态链接、零依赖、6 MB、单文件或跨平台能力，部署前应以
发布镜像的实际链接和运行要求为准。

## 快速开始

```bash
composer require tinywan/webman-typephp --dev
php webman typephp:doctor
php webman typephp:package
```

默认产物目录为 `dist/`。已有输出受到保护，只有确认要替换时才使用 `--force`。

```bash
cd dist
./webman-server start
```

请在兼容的 Linux x86_64 主机上，先对产物执行应用 HTTP 冒烟测试，再部署到生产环境。

## 命令

| 命令 | 说明 |
| --- | --- |
| `php webman typephp:doctor` | 检查所需的 PHP 和 Docker 前置条件。 |
| `php webman typephp:package [--image=REFERENCE] [--force]` | 调用 Linux x86_64 构建器生成 portable-dir。 |
| `php webman typephp:init-ci` | 生成 Linux x86_64 portable-dir 的 CI 工作流。 |

## 配置与可追溯性

插件配置位于 `config/plugin/tinywan/typephp/app.php`。`docker.image` 选择构建
镜像，`sources` 和 `ignore` 定义编译输入，`build.output_name` 和 `build.dist_dir`
定义输出位置。第一阶段不会声称已经完整发现 Composer 或运行时依赖。

每个产物包含 `build-manifest.json`，记录构建器引用、生成器输入、输出设置和可用的
工具链信息。镜像覆盖参数会经过校验，并作为单独的 Docker 参数传递，不拼接为 shell
命令。CI 优先使用不可变镜像摘要；构建在 `.typephp/` 下暂存，只有 `--force` 才替换
已有输出。

## 开发检查约定

测试使用 Pest，PHP 代码格式化和静态检查使用 Mago。Composer 脚本约定为：

```json
{
  "format:check": "mago format --check",
  "format": "mago format",
  "lint": "mago lint",
  "analyze": "mago analyze",
  "test": "pest",
  "check": ["@format:check", "@lint", "@analyze", "@test"]
}
```

依赖未安装的环境不能据此推断这些检查已经实际执行并通过。

## 许可证

TypePHP 使用 GPL-3.0 许可证。分发相关工具链、镜像或二进制时，请单独核查许可证
义务；本插件的 MIT 许可证不会消除这些义务。

## 路线图

第二阶段将补充 Composer `installed.php`/classmap 依赖分析和可审查报告、TypePHP
兼容性预检与项目覆盖配置、扩展支持矩阵及 fixtures、ARM64 构建器和测试，以及按
编译器版本、源码输入 hash、配置 hash 生成的增量构建缓存。

全静态链接、资源嵌入和单文件交付、更多 Linux 兼容性、Windows/macOS 等平台，属于
第二阶段之后的更后续阶段；在可复现构建和实际部署验证完成前，不扩大兼容性承诺。

完整方案见 [TYPEPHP_PLUGIN_PROPOSAL.md](TYPEPHP_PLUGIN_PROPOSAL.md)。
