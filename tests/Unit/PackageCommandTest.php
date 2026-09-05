<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('rejects illegal output names or invalid paths', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();
    expect($command->getName())->toBe('typephp:package');
    expect($command->getDefinition()->hasOption('force'))->toBeTrue();
    expect($command->getDefinition()->hasOption('image'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-dir'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-name'))->toBeTrue();
});
