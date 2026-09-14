<?php

declare(strict_types=1);

return [
    'enable' => true,
    'docker' => [
        // 发布使用时可进一步固定为 registry tag@sha256:digest。
        'image' => 'tinywan/typephp-webman-builder:v0.1.3',
    ],
    'build' => [
        'output_name' => 'webman-server',
        'dist_dir' => 'dist',
        'clean_build' => true,
    ],
    'runtime_resources' => [
        'config',
        'public',
        'app/view',
        // 只增加模板、静态数据或经登记的第三方动态资源。
    ],
    'ignore' => [
        'config',
        'public',
        'runtime',
        'app/view',
        // 不得增加自有业务 PHP 目录。
    ],
];
