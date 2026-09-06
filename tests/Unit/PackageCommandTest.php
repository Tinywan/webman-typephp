<?php

declare(strict_types=1);

it('rejects illegal output names or invalid paths', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();
    expect($command->getName())->toBe('typephp:package');
    expect($command->getDefinition()->hasOption('force'))->toBeTrue();
    expect($command->getDefinition()->hasOption('image'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-dir'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-name'))->toBeTrue();
});

it('defaults to the versioned portable-dir builder image', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();

    expect($command->getDefinition()->getOption('image')->getDefault())->toBe('tinywan/typephp-webman-builder:v0.0.10');
});
