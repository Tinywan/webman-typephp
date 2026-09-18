# SaiAdmin Linux amd64 AOT 验证证据

验收编号：`AOT-SAIADMIN-LINUX-01`

本记录对应 `feat/saiadmin-aot-profile` 的首版候选。SaiAdmin 6.1.1 的完整业务
验收于 2026-09-14 在 Mac 上的隔离 Linux amd64 容器环境完成；SaiAdmin 6.1.5
的构建验收于 2026-09-18 完成。原始日志包含本地路径和一次性测试账号，因此不提交
原始文件；下文保留版本、数量、哈希、返回码和可复现命令。

## SaiAdmin 6.1.1 完整验收

## 验证结论

- Linux amd64 AOT 完整编译、链接和 portable-dir 打包成功。
- 所有已发现的自有业务 PHP 和已安装插件业务 PHP 均进入 AOT 覆盖清单。
- AOT 产物成功启动，并通过验证码、登录、登录后用户信息、权限拒绝和中立插件路由。
- 相同业务路径在普通 PHP 下通过，AOT 适配没有改变普通运行方式。
- 一次性测试账号、数据库卷、运行容器和隔离网络已在验收后销毁。

## 固定组合

| 项目 | 验证值 |
| --- | --- |
| 目标 | Linux amd64 / glibc portable-dir |
| builder PHP | 8.4.25 |
| TypePHP | `swoole/typephp v0.8.0` |
| builder 基线 | `tinywan/typephp-linux-x64:v0.8.0@sha256:f18cac640edf52126acc1ad781f220f9fe547f7c8db925dcc38d4854f9436f90` |
| 候选镜像 ID | `sha256:bf2dc10f8470bac08b958bf2f457969d43eb6b6a77d123c66dc7b9864f363922` |
| SaiAdmin | 6.1.1 |
| ThinkORM | v3.0.34 |
| Carbon | 3.13.2 |
| Webman framework | `fa352016aac4c9e21c8781cc127afd25ee144795` |
| Workerman | `69bfc7765fff55bc3792560c715ae3ca8eadccb5` |

## 环境与自动化测试

`php webman typephp:doctor` 的关键结果：

```text
PHP 8.4.25: OK
Docker CLI 29.1.3 and daemon: OK
Host Clang: optional
```

插件测试和静态检查：

```text
Pest: 128 passed, 1,369 assertions
Existing fixture warning: 1
PHP 8.4 syntax: passed
Shell syntax: passed
git diff --check: passed
```

## 完整编译

执行命令：

```bash
php webman typephp:doctor
php webman typephp:package --profile=saiadmin
```

最终构建记录：

```text
Source roots scanned: 200
PHP inputs generated and prechecked: 2,279
Native objects compiled and linked: 2,283 / 2,283
Build exit code: 0
portable-dir size: 230 MB
Runtime resources recorded: 74
```

中立插件 `plugin/aot_probe/app/controller/ProbeController.php` 由 profile 自动发现并
直接编译，没有在 profile 中手工登记。

## 产物完整性

保留产物于 2026-09-15 再次运行 `scripts/verify-package.sh`，结果：

```text
ELF 64-bit LSB pie executable, x86-64
Portable-dir contract OK
profile=saiadmin
compiled=131
generated=11
covered files=142
runtime resources=74
business PHP leaked into portable-dir=0
unresolved ldd dependency=0
```

产物身份：

| 文件 | SHA-256 |
| --- | --- |
| `webman-server.bin` | `a248c3fdd7fe7e95dfacf6d0557e320bfb19f7a5d9053ac5605329b696aee2ff` |
| `build-manifest.json` | `379a8d19fcd1d76af463efe567cd83d51ee9414a4b0f173096a5c2ac9315e862` |
| `source-coverage.json` | `5fd8bd86bed3987cc43fd97dd0c7f8047664b235a0b2b3bb571ea9ed785d2d77` |

## 启动与业务路径

portable-dir 在隔离 Linux amd64 容器中启动，Workerman 记录：

```text
Workerman[main.php] start in DEBUG mode
40 workers [OK]
```

业务验收结果：

| 路径 | HTTP | JSON 业务码 | 验证内容 |
| --- | ---: | ---: | --- |
| 验证码 | 200 | 200 | 返回非空 UUID 和 `data:image/` 图片 |
| 登录 | 200 | 200 | 返回非空 Bearer access token |
| 登录后用户信息 | 200 | 200 | 返回当前隔离用户数据 |
| 权限拒绝 | 200 | 400 | 无权限用户被拒绝访问受保护资源 |
| 中立插件 `/aot-probe` | 200 | 200 | 返回 `data.aot=true` |

`scripts/accept-linux.sh` 的完成结果：

```text
Portable-dir contract OK
Isolated captcha, login, user-info, and permission-denial acceptance passed.
```

普通 PHP 使用同一依赖组合、隔离数据库和业务请求执行对照：

```text
captcha: HTTP 200 / JSON 200
login: HTTP 200 / JSON 200
user-info: HTTP 200 / JSON 200
permission denial: HTTP 200 / JSON 400
```

## 复现

维护者可以在一次性、处于支持范围的 SaiAdmin 测试项目中执行：

```bash
php webman typephp:doctor
php webman typephp:package --profile=saiadmin

AOT_DIST=/absolute/path/to/dist \
  ./vendor/tinywan/webman-typephp/docs/saiadmin-aot/scripts/verify-package.sh
```

业务验收需要由调用方提供隔离环境的 URL、接口路径和临时登录请求文件。必填变量及
安全开关记录在 `scripts/accept-linux.sh` 中。脚本拒绝在未显式设置
`AOT_ACCEPT_ISOLATED=YES` 时启动。

## SaiAdmin 6.1.5 构建验收

2026-09-18 使用官方 SaiAdmin `6.1.5` 源码和 Composer `--minimal-changes`
依赖组合执行完整 Linux amd64 构建。构建前验证安装目录与 Composer 包源码一致；
自有项目代码和非 SaiAdmin 插件不属于本证据输入。

```text
TypePHP precheck and C++ generation: 2,275 / 2,275
Build exit code: 0
Portable-dir contract: OK
ELF: 64-bit LSB pie executable, x86-64
portable-dir size: 224 MB
runtime resources: 74
Pest: 133 passed, 1,410 assertions
Existing fixture warning: 1
SaiAdmin business PHP: 120
directly compiled: 109
generated AOT copies: 11
unclassified SaiAdmin business PHP: 0
business PHP leaked into portable-dir: 0
```

第三方安装与迁移工具 `vendor/cakephp`、`vendor/league/container` 和
`vendor/robmorgan/phinx` 明确登记为动态运行资源；SaiAdmin 核心、登录、权限、
模型与缓存业务代码均未借此绕过 AOT。

本次产物身份：

| 文件 | SHA-256 |
| --- | --- |
| `webman-server.bin` | `6f6fe803153c9bf84b8be3a4a1f9c4fe1493703df77a401ba5cdcf774baf5c7e` |
| `build-manifest.json` | `6aa936c82bdb5549ef2302bf5feb24d34d7ece0191a6e1da143251ce12785836` |
| `source-coverage.json` | `d7cf4d22f4388d830496fbcdb5bb6114ee7d7de3a998ca601e75dd8d3afeb5b5` |

本次构建镜像身份为
`webman-typephp-saiadmin@sha256:5f4065b17fafc6eb86f6478f10a060211b5e9c396e8f64fa93f164b727195c5b`。
它是本地验证候选，不替代发布时应固定的公开 builder digest。

6.1.5 本轮没有创建或连接数据库，也没有创建测试账号，因此只证明编译、链接、
覆盖和 portable-dir 完整性，不证明验证码、登录、用户信息或权限拒绝路径已经运行。
在新的隔离数据库验收完成前，6.1.5 的状态是“构建支持”，不是“完整业务验收”。

## 证据边界

- 本文件是脱敏后的本地隔离验收记录，不是公开托管的二进制或完整原始日志。
- 哈希只能标识本次保留产物，不能替代维护者在自己的锁定环境中重建。
- GitHub 当前没有为该分支返回 CI checks；合并前建议维护者重跑插件测试和完整构建。
- Windows exe/DLL 未验证，不属于本次支持范围。
