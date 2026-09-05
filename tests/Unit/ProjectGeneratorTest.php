<?php

declare(strict_types=1);

use Tinywan\Typephp\Compiler\ProjectGenerator;

it('creates a portable build configuration from explicit project inputs', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-test-' . bin2hex(random_bytes(4));
    mkdir($directory . DIRECTORY_SEPARATOR . 'app', 0777, true);

    try {
        $generator = new ProjectGenerator($directory);
        $path = $generator->generateProjectYml(['include' => ['app'], 'exclude' => ['runtime']]);

        expect($path)->toBe($directory . DIRECTORY_SEPARATOR . 'project.linux.yml')
            ->and(file_get_contents($path))->toContain("sources:\n  - main.php\n  - app\n")
            ->toContain("ignore:\n  - runtime\n")
            ->toContain('output: build/webman-server');
    } finally {
        @unlink($directory . DIRECTORY_SEPARATOR . 'project.linux.yml');
        @rmdir($directory . DIRECTORY_SEPARATOR . 'app');
        @rmdir($directory);
    }
});
