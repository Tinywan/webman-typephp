<?php

declare(strict_types=1);

use Tinywan\Typephp\Install;

it('runs install and uninstall safely without webman environment', function (): void {
    // 在纯组件隔离环境下不应抛出致命异常
    Install::install();
    Install::uninstall();

    expect(Install::WEBMAN_PLUGIN)->toBeTrue();
});
