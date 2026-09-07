<?php

declare(strict_types=1);

use Tinywan\Typephp\Compiler\ProjectGenerator;

it('creates a portable build configuration from explicit project inputs', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . DIRECTORY_SEPARATOR . 'app', 0777, true);

    try {
        $generator = new ProjectGenerator($directory);
        $path = $generator->generateProjectYml(['include' => ['app'], 'exclude' => ['runtime']]);

        expect($path)
            ->toBe($directory . DIRECTORY_SEPARATOR . 'project.linux.yml')
            ->and(file_get_contents($path))
            ->toContain("sources:\n  - main.php\n  - app\n")
            ->toContain("\n  - runtime\n")
            ->toContain('output: build/webman-server');

        // 测试默认源中包含 fast-route functions.php
        $defaultPath = $generator->generateProjectYml([]);
        expect(file_get_contents($defaultPath))
            ->toContain('vendor/nikic/fast-route/src/functions.php');

        // 测试 generateMain 首次生成
        $mainPath = $generator->generateMain();
        expect(file_exists($mainPath))->toBeTrue();

        // 测试 generateMain 强制刷新生成备份
        file_put_contents($mainPath, '<?php // custom user code');
        $generator->generateMain(null, true);
        expect(file_exists($mainPath . '.bak'))->toBeTrue()
            ->and(file_get_contents($mainPath . '.bak'))->toContain('custom user code');
    } finally {
        $projectFile = $directory . DIRECTORY_SEPARATOR . 'project.linux.yml';
        if (is_file($projectFile)) {
            unlink($projectFile);
        }
        $mainFile = $directory . DIRECTORY_SEPARATOR . 'main.php';
        if (is_file($mainFile)) {
            unlink($mainFile);
        }
        $mainBakFile = $directory . DIRECTORY_SEPARATOR . 'main.php.bak';
        if (is_file($mainBakFile)) {
            unlink($mainBakFile);
        }
        $appDirectory = $directory . DIRECTORY_SEPARATOR . 'app';
        if (is_dir($appDirectory)) {
            rmdir($appDirectory);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
