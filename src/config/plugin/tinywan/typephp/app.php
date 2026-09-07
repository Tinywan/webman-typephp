<?php

/**
 * @desc TypePHP 基础插件配置文件
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/05
 */
declare(strict_types=1);

return [
    'enable' => true,
    // Docker 编译环境配置
    'docker' => [
        'enabled' => true,
        'image' => 'tinywan/typephp-webman-builder:v0.0.19',
    ],
    // 默认输出配置
    'build' => [
        'output_name' => 'webman-server',
        'dist_dir' => 'dist',
        'clean_build' => true,
    ],
    // 编译排除项（动态文件与运行时资源；带 if 守卫的 helpers.php/functions.php 由
    // 打包命令自动平铺、coroutine 的 Context/WaitGroup/Barrier 由打包命令自动做
    // 静态属性可空化补丁、Worker/TcpConnection/AsyncTcpConnection/Select/File 由
    // 打包命令自动做可变参数闭包补丁，均在 .typephp/build/ 下生成 AOT 专用版本后参与编译）
    'ignore' => [
        'config',
        'public',
        'runtime',
        'app/view',
        'app/model',
        'app/process/Monitor.php',
        'support',
        'vendor/workerman/webman-framework/src/support/helpers.php',
        'vendor/nikic/fast-route/src/functions.php',
        'vendor/workerman/webman-framework/src/support/bootstrap.php',
        'vendor/workerman/webman-framework/src/start.php',
        'vendor/workerman/webman-framework/src/windows.php',
        'vendor/workerman/webman-framework/src/Install.php',
        'vendor/nikic/fast-route/src/bootstrap.php',
        'vendor/nikic/fast-route/src/DataGenerator/CharCountBased.php',
        'vendor/nikic/fast-route/src/DataGenerator/GroupPosBased.php',
        'vendor/nikic/fast-route/src/DataGenerator/MarkBased.php',
        'vendor/nikic/fast-route/src/Dispatcher/CharCountBased.php',
        'vendor/nikic/fast-route/src/Dispatcher/GroupPosBased.php',
        'vendor/nikic/fast-route/src/Dispatcher/MarkBased.php',
        'vendor/workerman/coroutine/src/Context.php',
        'vendor/workerman/coroutine/src/WaitGroup.php',
        'vendor/workerman/coroutine/src/Barrier.php',
        'vendor/workerman/workerman/src/Worker.php',
        'vendor/workerman/workerman/src/Connection/TcpConnection.php',
        'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php',
        'vendor/workerman/workerman/src/Events/Select.php',
        'vendor/workerman/webman-framework/src/File.php',
        'vendor/workerman/coroutine/tests',
        'vendor/workerman/coroutine/stubs',
        'vendor/workerman/coroutine/src/Barrier/Swow.php',
        'vendor/workerman/coroutine/src/Channel/Swow.php',
        'vendor/workerman/coroutine/src/Context/Swow.php',
        'vendor/workerman/coroutine/src/Coroutine/Swow.php',
        'vendor/workerman/coroutine/src/WaitGroup/Swow.php',
        'vendor/workerman/workerman/src/Events/Swow.php',
        'vendor/workerman/coroutine/src/Pool.php',
        'vendor/workerman/coroutine/src/Utils/DestructionWatcher.php',
    ],
];
