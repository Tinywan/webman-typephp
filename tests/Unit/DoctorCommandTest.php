<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;
use Tinywan\Typephp\Commands\DoctorCommand;

it('runs doctor command and checks local system environment', function (): void {
    $command = new DoctorCommand();
    $tester = new CommandTester($command);

    $statusCode = $tester->execute([]);

    $output = $tester->getDisplay();
    expect($output)->toContain('TypePHP Environment Diagnostic Tool')->toContain('PHP Version:')->toContain('Docker:');

    // 状态码应为 0 (成功) 或 1 (当缺少前置依赖时)
    expect($statusCode)->toBeIn([0, 1]);
});
