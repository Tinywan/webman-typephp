# 存量项目迁移指南

## 1. 建立基线

记录当前提交和工作树状态，PHP、Composer、Docker、Webman、Workerman、TypePHP、builder 与扩展版本，以及普通 PHP 回归和原始编译 Fatal。不要运行安装器或数据库初始化。

profile 不额外限制 Webman、Workerman 或 SaiAdmin 版本，不要为了通过 profile 检查
切换项目依赖。先保留项目现有 Composer 锁定组合；若兼容规则因源码结构变化失败，
再以实际编译错误扩展规则并补充验证记录。

## 2. 划分输入

- 必须编译：所有自有业务 PHP。
- 随包资源：配置、模板、静态文件和语言/数据文件。
- 明确登记的第三方动态代码：仅在编译器当前不能表示、且运行时加载方案已经验收时使用。

逐项检查生成的 `project.linux.yml`。业务文件被 `ignore` 时，必须能对应到参与编译的 `.typephp/build` 等价副本；否则迁移失败。

## 3. 逐个处理真实错误

每轮只处理日志中的首个阻碍：确认失败文件和锁定版本，判断责任层，在现有插件增加最小 AOT 生成改写及 fixture，然后重新全量编译。只有出现新失败位置才算取得新证据；禁止排除整块业务目录来“先出包”。

升级了插件的 `main.php.stub` 后，存量项目必须显式使用 `--refresh-main` 重建入口并审查备份，不能只更新依赖后沿用旧入口。

升级 SaiAdmin 时使用 Composer `--minimal-changes`，并确认
`plugin/saiadmin` 已与 `vendor/saithink/saiadmin/src/plugin/saiadmin` 同步。
profile 会拒绝版本号已升级但安装目录仍保留旧源码的项目。

TypePHP 会拒绝同一局部变量跨不兼容类型赋值，也可能拒绝业务代码直接读取模型的
protected 属性。前者应拆成不同变量或分支内直接返回；后者应改用公开 accessor、
`getAttr()` 或显式 DTO。未知自有业务写法必须修复后重新编译，不能登记为动态资源。

若第三方代码依赖 TypePHP 当前不能表示的运行时反射或动态行为，必须同时：

1. 在 `runtime_resources` 中以最小文件或包目录登记；
2. 在 `ignore` 中使用相同路径；
3. 通过 Webman `autoload.files` 在使用前加载；
4. 证明自有业务 PHP 没有进入该清单。

## 4. 产物和运行验收

编译完成后：

```bash
AOT_DIST=/absolute/path/to/dist ./scripts/verify-package.sh
```

准备隔离数据库、测试账号和未授权接口后：

```bash
AOT_ACCEPT_ISOLATED=YES \
AOT_DIST=/absolute/path/to/dist \
AOT_BASE_URL=http://127.0.0.1:8787 \
AOT_CAPTCHA_PATH=/core/captcha \
AOT_LOGIN_PATH=/core/login \
AOT_LOGIN_BODY_FILE=/absolute/path/to/login.json \
AOT_TOKEN_PATH=data.access_token \
AOT_USER_INFO_PATH=/core/system/user \
AOT_DENIED_PATH=/core/post/index \
AOT_DENIED_EXPECT_STATUS=200 \
AOT_DENIED_EXPECT_CODE=400 \
./scripts/accept-linux.sh
```

`AOT_TOKEN_PATH` 是登录 JSON 中 Bearer token 的点分路径。脚本会验证验证码响应含 UUID 和图片、登录 token、登录后用户信息，以及权限拒绝的 HTTP 状态和业务码。登录请求文件应由隔离环境夹具写入有效且尚未消费的验证码；脚本不会创建或迁移数据库，凭据只从本地文件读取且不会输出。宿主特有路径和响应断言不得进入公开仓库。

## 5. 回归与发布

重新运行普通 PHP 基线。提交、推送、镜像发布和部署均是独立授权动作；发布必须重建完整 portable-dir 后重新验收。
