<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;
use Tinywan\Typephp\Commands\InitCiCommand;

it('generates github actions workflow file', function (): void {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typephp-ci-' . bin2hex(random_bytes(4));
    mkdir($tempDir, 0777, true);

    $command = new InitCiCommand();
    $tester = new CommandTester($command);

    $oldCwd = getcwd();
    chdir($tempDir);

    try {
        $tester->execute(['--path' => $tempDir]);
        $output = $tester->getDisplay();
        expect($output)
            ->toContain('Created GitHub Actions workflow')
            ->and(file_exists($tempDir . '/.github/workflows/typephp-build.yml'))
            ->toBeTrue();

        $content = file_get_contents($tempDir . '/.github/workflows/typephp-build.yml');
        expect($content)
            ->toContain('TypePHP Automated Build & Release')
            ->toContain('php webman typephp:package --force');
    } finally {
        if ($oldCwd !== false) {
            chdir($oldCwd);
        }
        $workflow = $tempDir . '/.github/workflows/typephp-build.yml';
        if (is_file($workflow)) {
            unlink($workflow);
        }
        foreach ([$tempDir . '/.github/workflows', $tempDir . '/.github', $tempDir] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
});
