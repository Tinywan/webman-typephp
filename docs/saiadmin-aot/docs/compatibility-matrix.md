# SaiAdmin AOT 支持矩阵

首版只验收 Linux amd64。下列组合按一个不可拆分的构建基线处理；任一依赖漂移都必须先更新 fixture、规则命中数和完整运行证据。

| 组件 | 锁定版本 |
| --- | --- |
| PHP | 8.4.25 |
| TypePHP Linux builder | `tinywan/typephp-linux-x64:v0.8.0@sha256:f18cac640edf52126acc1ad781f220f9fe547f7c8db925dcc38d4854f9436f90` |
| SaiAdmin | 6.1.1 |
| ThinkORM | v3.0.34 |
| Carbon | 3.13.2 |
| Webman framework | `fa352016aac4c9e21c8781cc127afd25ee144795` |
| Workerman | `69bfc7765fff55bc3792560c715ae3ca8eadccb5` |

## 已验证的兼容规则

| 归属 | 问题类别 | AOT 处理 |
| --- | --- | --- |
| Webman / Workerman | 顶层守卫与初始化、固定参数回调、引用捕获、switch 落空、运行期清理 | 生成锁定结构的副本，并在入口补回等价初始化 |
| ThinkORM | 参数与局部变量类型漂移、动态解析调用参数数目、结果集跨类型、运行期连接状态清理、Collection 回调少声明键参数 | 生成类型稳定且调用参数显式的副本，并声明集合回调的值与键参数 |
| Carbon | 变量变量、魔术单位调用、继承常量、DatePeriod 声明顺序和类型漂移 | 生成显式方法、常量和稳定局部变量副本 |
| SaiAdmin | 验证码颜色/字体、编译类 protected 默认属性反射缺失、零参控制器无法接收 Webman 请求参数 | 使用随包字体；对 6.1.1 的登录与安装匿名动作生成显式等价副本；为全部已登记零参路由动作生成显式 `Request` 参数 |

规则只匹配已记录的依赖版本和源码结构。未知版本、零命中、重复命中或无法证明等价的写法必须终止构建；不得改成运行时解释业务代码。

## 未承诺范围

- Windows 可执行文件和 DLL。
- 未来 SaiAdmin 或依赖版本的自动兼容。
- 未经完整编译和业务验收的第三方插件。
- 依赖动态 `include`、`eval`、闭包 rebinding 或不稳定魔术调用且无法静态等价转换的业务功能。
