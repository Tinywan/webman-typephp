<?php

declare(strict_types=1);

it('rejects illegal output names or invalid paths', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();
    expect($command->getName())->toBe('typephp:package');
    expect($command->getDefinition()->hasOption('force'))->toBeTrue();
    expect($command->getDefinition()->hasOption('image'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-dir'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-name'))->toBeTrue();
    expect($command->getDefinition()->hasOption('refresh-main'))->toBeTrue();
});

it('leaves the image option unset so configuration can supply it', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();

    expect($command->getDefinition()->getOption('image')->getDefault())->toBeNull();
});

it('ships the v0.1.2 builder image in the plugin configuration', function (): void {
    $pluginConfig = require dirname(__DIR__, 2) . '/src/config/plugin/tinywan/typephp/app.php';
    if (!is_array($pluginConfig)) {
        throw new RuntimeException('Plugin configuration must be an array.');
    }

    $dockerConfig = $pluginConfig['docker'] ?? null;
    if (!is_array($dockerConfig)) {
        throw new RuntimeException('Plugin Docker configuration must be an array.');
    }

    expect($dockerConfig['image'] ?? null)->toBe('tinywan/typephp-webman-builder:v0.1.2');
});

it('resolves the builder image from the option, configuration, then fallback', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();
    $method = new ReflectionMethod($command, 'resolveBuilderImage');

    expect($method->invoke($command, 'registry.example/explicit:v1', [
        'docker' => ['image' => 'registry.example/configured:v1'],
    ]))->toBe('registry.example/explicit:v1');

    expect($method->invoke($command, null, [
        'docker' => ['image' => 'registry.example/configured:v1'],
    ]))->toBe('registry.example/configured:v1');

    expect($method->invoke($command, null, []))->toBe('tinywan/typephp-webman-builder:v0.1.2');
});
