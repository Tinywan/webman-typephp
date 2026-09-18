# SaiAdmin AOT 支持矩阵

首版只验收 Linux amd64。profile 允许稳定版 SaiAdmin `>=6.1.1 <6.2.0`
进入候选构建，预发布版不在范围内。这个范围只是准入规则，不是对每个 `6.1.x`
版本的无条件兼容承诺；源码结构、安装副本或锁定依赖漂移仍会失败关闭。

| 组件 | 支持/锁定版本 |
| --- | --- |
| PHP | 8.4.25 |
| TypePHP Linux builder | `tinywan/typephp-linux-x64:v0.8.0@sha256:f18cac640edf52126acc1ad781f220f9fe547f7c8db925dcc38d4854f9436f90` |
| SaiAdmin | 稳定版 `>=6.1.1 <6.2.0` |
| ThinkORM | v3.0.34 |
| Carbon | 3.13.2 |
| Webman framework | `fa352016aac4c9e21c8781cc127afd25ee144795` |
| Workerman | `69bfc7765fff55bc3792560c715ae3ca8eadccb5` |

## 已验证版本

| SaiAdmin | Linux amd64 编译/打包 | 隔离数据库业务验收 | 结论 |
| --- | --- | --- | --- |
| 6.1.1 | 通过 | 验证码、登录、用户信息、权限拒绝、普通 PHP 对照均通过 | 完整验证 |
| 6.1.5 | 通过 | 尚未执行 | 构建支持；不能据此宣称业务运行已验收 |

SaiAdmin 6.1.5 的最小变更依赖组合新增 CakePHP Chronos `3.5.1`，
CakePHP Core/Database/Datasource/Event/Utility `5.4.2`、League Container
`5.2.0`、Phinx `0.16.12`、Symfony Config `8.1.5` 和 Symfony Filesystem
`8.1.6`，并将 PHPMailer 升至 `7.1.1`、IP2Region 升至 `3.0.15`。升级时应使用 Composer
`--minimal-changes`，避免无关依赖整体漂移。

## 已验证的兼容规则

| 归属 | 问题类别 | AOT 处理 |
| --- | --- | --- |
| Webman / Workerman | 顶层守卫与初始化、固定参数回调、引用捕获、switch 落空、运行期清理 | 生成锁定结构的副本，并在入口补回等价初始化 |
| ThinkORM | 参数与局部变量类型漂移、动态解析调用参数数目、结果集跨类型、运行期连接状态清理、Collection 回调少声明键参数 | 生成类型稳定且调用参数显式的副本，并声明集合回调的值与键参数 |
| Carbon | 变量变量、魔术单位调用、继承常量、DatePeriod 声明顺序和类型漂移 | 生成显式方法、常量和稳定局部变量副本 |
| SaiAdmin | 验证码颜色/字体、编译类 protected 默认属性反射缺失、零参控制器无法接收 Webman 请求参数、缓存标签变量跨类型、异常原因隐式可空 | 使用随包字体；从当前源码静态提取登录与安装匿名动作；生成显式 `Request` 参数、类型稳定缓存分支和显式 nullable 异常副本 |
| SaiAdmin 6.1.5 第三方依赖 | CakePHP/Phinx/League Container 的安装与迁移工具依赖 TypePHP v0.8 无法有界表示的动态写法 | 作为明确登记的第三方动态运行资源随包；SaiAdmin 核心、登录、权限、模型及自有业务不得进入该清单 |
| IP2Region / Nelexa | v3 整数槽跨类型、PSR Stream 签名不一致 | 生成返回分支和接口签名明确的 AOT 副本 |

规则只匹配已记录的依赖版本和源码结构。`plugin/saiadmin` 的安装副本还必须与
`vendor/saithink/saiadmin/src/plugin/saiadmin` 内容一致。未知版本、零命中、重复命中、
安装副本漂移或无法证明等价的写法必须终止构建；不得改成运行时解释业务代码。

## 未承诺范围

- Windows 可执行文件和 DLL。
- 未来 SaiAdmin 或依赖版本的自动兼容。
- 未经完整编译和业务验收的第三方插件。
- 依赖动态 `include`、`eval`、闭包 rebinding 或不稳定魔术调用且无法静态等价转换的业务功能。
