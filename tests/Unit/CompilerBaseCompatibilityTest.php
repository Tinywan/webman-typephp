<?php

declare(strict_types=1);

it('keeps the CompilerBase override compatible with the v0.8 native build platform contract', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $dockerfile = file_get_contents($repositoryRoot . '/docker/Dockerfile');
    $compilerBase = file_get_contents($repositoryRoot . '/docker/CompilerBase.php');
    if ($dockerfile === false || $compilerBase === false) {
        throw new RuntimeException('Unable to load CompilerBase Docker override files.');
    }

    expect($dockerfile)->toContain('COPY CompilerBase.php /opt/typephp/vendor/swoole/typephp/src/CompilerBase.php');

    foreach ([
        'use TypePhp\\Platform\\Ios;',
        'use TypePhp\\Platform\\Android;',
        'public function isIosTarget(): bool',
        'return $this->getPlatform() instanceof Ios;',
        'public function isAndroidTarget(): bool',
        'return $this->getPlatform() instanceof Android;',
        'if ($this->isIosTarget()) {',
        'return $this->getIosSdkDir();',
        'if ($this->isAndroidTarget()) {',
        'return $this->getAndroidSdkDir();',
    ] as $required) {
        expect($compilerBase)->toContain($required);
    }

    expect($compilerBase)->toMatch(
        '/public function getPhpDir\(\): string\s*\{\s*if \(\$this->isIosTarget\(\)\) \{.*?'
        . 'return \$this->getIosSdkDir\(\);\s*}\s*if \(\$this->isAndroidTarget\(\)\) \{.*?'
        . 'return \$this->getAndroidSdkDir\(\);\s*}\s*try/s',
    );
});
