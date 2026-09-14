# Webman TypePHP AOT 配套

本目录是 `tinywan/webman-typephp` 的使用规范、迁移指南、配置样例和验收脚本，不包含另一套编译器或打包插件。

推荐流程：

1. 安装并锁定 `tinywan/webman-typephp`、builder、PHP、Webman 和 Composer 依赖。
2. 按 [开发规范](docs/development-standard.md) 约束新代码。
3. 对照 [支持矩阵](docs/compatibility-matrix.md)，存量项目按 [迁移指南](docs/migration-guide.md) 只处理真实编译错误。
4. 使用现有插件执行 `php webman typephp:doctor` 和 `php webman typephp:package --profile=saiadmin`。
5. 用 `scripts/verify-package.sh` 检查产物契约。
6. 仅在隔离环境中，用 `scripts/accept-linux.sh` 完成验证码、登录、用户信息和权限拒绝验收。

边界：

- 业务 PHP 必须进入 AOT 编译，不得借 `ignore` 静默回退解释执行。
- 配置、模板、静态数据和明确登记的第三方动态资源可以随包。
- AOT 专用兼容改写只生成到 `.typephp/build/`；普通 PHP源码和执行路径保持不变。
- 不因 AOT 适配修改 Webman；SaiAdmin 只修复已复现、无法由生成副本解决的问题。
- 构建成功不等于运行成功，静态检查不等于业务验收。

本目录不含任何业务仓库源码、数据、凭据、内网地址或构建产物。
