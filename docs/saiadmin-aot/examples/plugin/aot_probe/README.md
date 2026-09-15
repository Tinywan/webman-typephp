# 中立插件自动发现样例

将 `aot_probe` 目录复制到 SaiAdmin 项目的 `plugin/` 后，再执行：

```bash
php webman typephp:package --profile=saiadmin
```

覆盖清单必须新增 `plugin/aot_probe/app/controller/ProbeController.php`，产物中不得保留该业务 PHP。该样例只用于验证插件自动发现，不需要在 TypePHP 配置中手工登记。
