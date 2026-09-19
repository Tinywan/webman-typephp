# SaiAdmin AOT 支持矩阵

发布型 portable-dir 首版只验收 Linux amd64；宿主机原生模式另已验证 macOS arm64。
profile 不对 Webman、Workerman 或 SaiAdmin 增加版本白名单；依赖是否可安装由 Composer
约束决定。表中的版本是已验证记录，不代表未列出版本自动兼容；源码结构、安装副本或
锁定依赖漂移仍会失败关闭。

| 组件 | 支持/锁定版本 |
| --- | --- |
| PHP | 8.4.25 |
| TypePHP Linux builder | `tinywan/typephp-linux-x64:v0.8.0@sha256:f18cac640edf52126acc1ad781f220f9fe547f7c8db925dcc38d4854f9436f90` |
| SaiAdmin | profile 无额外版本门禁；已验证 `6.1.1`、`6.1.5` |
| ThinkORM | v3.0.34 |
| Carbon | 3.13.2 |
| Webman framework | 沿用插件 Composer 约束 `^1.5.4 \|\| ^2.0 \|\| dev-master`；已验证 `v2.2.4` 和记录中的 `dev-master` |
| Workerman | profile 无额外版本门禁；已验证 `v5.2.2` 和记录中的 `dev-master` |

## 非 Docker 原生模式

| 宿主平台 | PHP embed / TypePHP | SaiAdmin | 编译 | 启动与 HTTP | 结论 |
| --- | --- | --- | --- | --- | --- |
| macOS arm64 | PHP ZTS 8.5.10 / TypePHP 0.9.0 | 6.1.5 | 2,056 / 2,056，通过 | 验证码和未登录权限拒绝通过 | 当前宿主原生验证通过 |

原生模式要求 PHP 可执行文件、embed 头文件与 `libphp`、PHPX 来自同一 ABI。它生成
当前宿主平台程序，不是跨平台编译：macOS 产物是 Mach-O，不能作为 Linux 发布物。

## 已验证版本

| SaiAdmin | Linux amd64 编译/打包 | 隔离数据库业务验收 | 结论 |
| --- | --- | --- | --- |
| 6.1.1 | 通过 | 验证码、登录、用户信息、权限拒绝、普通 PHP 对照均通过 | 完整验证 |
| 6.1.5 | 通过 | MySQL 下验证码、登录、用户信息、权限拒绝通过 | AOT 业务验证完成；普通 PHP 8.4 权限异常路径仍受上游隐式 nullable deprecation 影响 |

SaiAdmin 6.1.5 的最小变更依赖组合新增 CakePHP Chronos `3.5.1`，
CakePHP Core/Database/Datasource/Event/Utility `5.4.2`、League Container
`5.2.0`、Phinx `0.16.12`、Symfony Config `8.1.5` 和 Symfony Filesystem
`8.1.6`，并将 PHPMailer 升至 `7.1.1`、IP2Region 升至 `3.0.15`。升级时应使用 Composer
`--minimal-changes`，避免无关依赖整体漂移。

## 已验证的兼容规则

| 归属 | 问题类别 | AOT 处理 |
| --- | --- | --- |
| Webman / Workerman | 顶层守卫与初始化、固定参数回调、引用捕获、switch 落空、运行期清理 | 生成锁定结构的副本，并在入口补回等价初始化 |
| Workerman Coroutine | Fiber 上下文与连接池对空 `ArrayObject`/`WeakMap` 的维度写入被 TypePHP 错译为读取 | 在 AOT 副本中使用等价的 `offsetSet()`，并保留缺失、重复及结构漂移失败 |
| ThinkORM | 参数与局部变量类型漂移、动态解析调用参数数目、结果集跨类型、运行期连接状态清理、Collection 回调少声明键参数 | 生成类型稳定且调用参数显式的副本，并声明集合回调的值与键参数 |
| Carbon | 变量变量、魔术单位调用、继承常量、DatePeriod 声明顺序和类型漂移 | 生成显式方法、常量和稳定局部变量副本 |
| SaiAdmin | 验证码颜色/字体、编译类 protected 默认属性反射缺失、零参控制器无法接收 Webman 请求参数、缓存标签变量跨类型、异常原因隐式可空 | 使用随包字体；从当前源码静态提取登录与安装匿名动作；生成显式 `Request` 参数、类型稳定缓存分支和显式 nullable 异常副本 |
| SaiAdmin 6.1.5 第三方依赖 | CakePHP/Phinx/League Container 的安装与迁移工具依赖 TypePHP v0.8 无法有界表示的动态写法 | 作为明确登记的第三方动态运行资源随包；SaiAdmin 核心、登录、权限、模型及自有业务不得进入该清单 |
| IP2Region / Nelexa | v3 整数槽跨类型、PSR Stream 签名不一致 | 生成返回分支和接口签名明确的 AOT 副本 |

规则匹配已记录的源码结构。`plugin/saiadmin` 的安装副本还必须与
`vendor/saithink/saiadmin/src/plugin/saiadmin` 内容一致。未知源码结构、零命中、重复命中、
安装副本漂移或无法证明等价的写法必须终止构建；不得改成运行时解释业务代码。

## 未承诺范围

- Windows 可执行文件和 DLL。
- 将 macOS 原生产物当作 Linux portable-dir 发布。
- 未来 SaiAdmin 或依赖版本的自动兼容。
- 未经完整编译和业务验收的第三方插件。
- PostgreSQL 业务运行尚未验收；当前完整数据库业务证据使用 MySQL 8.4。
- 依赖动态 `include`、`eval`、闭包 rebinding 或不稳定魔术调用且无法静态等价转换的业务功能。
