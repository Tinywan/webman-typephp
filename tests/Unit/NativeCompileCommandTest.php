<?php

declare(strict_types=1);

use Tinywan\Typephp\Commands\NativeCompileCommand;

it('exposes a Docker-free native compile command', function (): void {
    $command = new NativeCompileCommand();

    expect($command->getName())->toBe('typephp:compile');
    expect($command->getDescription())->toContain('without Docker');
    expect($command->getDefinition()->hasOption('profile'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-name'))->toBeTrue();
    expect($command->getDefinition()->hasOption('tpc'))->toBeTrue();
    expect($command->getDefinition()->hasOption('php'))->toBeTrue();
    expect($command->getDefinition()->hasOption('php-home'))->toBeTrue();
    expect($command->getDefinition()->hasOption('phpx-home'))->toBeTrue();
    expect($command->getDefinition()->hasOption('cxx'))->toBeTrue();
    expect($command->getDefinition()->hasOption('build-dir'))->toBeTrue();
    expect($command->getDefinition()->hasOption('force'))->toBeTrue();
    expect($command->getDefinition()->hasOption('refresh-main'))->toBeTrue();
});

it('registers native compilation alongside the existing Docker package command', function (): void {
    $commands = require dirname(__DIR__, 2) . '/src/config/plugin/tinywan/typephp/command.php';

    expect($commands)
        ->toContain(NativeCompileCommand::class)
        ->toContain(\Tinywan\Typephp\Commands\PackageCommand::class);
});

it('ships host-neutral native toolchain configuration', function (): void {
    $config = require dirname(__DIR__, 2) . '/src/config/plugin/tinywan/typephp/app.php';

    expect($config['native'] ?? null)->toBe([
        'tpc' => null,
        'php' => null,
        'php_home' => null,
        'phpx_home' => null,
        'cxx' => null,
    ]);
});
