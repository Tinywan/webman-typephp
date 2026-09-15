<?php

declare(strict_types=1);

use Tinywan\Typephp\Compiler\Profile\SaiAdminProfile;

function removeSaiAdminProfileFixture(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            removeSaiAdminProfileFixture($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

function createSaiAdminProfileFixture(string $directory, string $ormVersion = 'v3.0.34'): void
{
    foreach ([
        'app/controller',
        'support',
        'plugin/saiadmin/app/controller',
        'plugin/saiadmin/config',
        'plugin/saiadmin/public',
        'plugin/example/app/controller',
        'plugin/example/app/view',
        'vendor/nesbot/carbon/src/Carbon/Traits',
    ] as $path) {
        mkdir($directory . '/' . $path, 0777, true);
    }
    foreach ([
        'app/controller/HomeController.php',
        'support/Request.php',
        'plugin/saiadmin/app/controller/LoginController.php',
        'plugin/example/app/controller/ExampleController.php',
    ] as $path) {
        file_put_contents($directory . '/' . $path, "<?php\nclass Fixture {}\n");
    }
    file_put_contents($directory . '/support/bootstrap.php', "<?php\nreturn true;\n");
    file_put_contents($directory . '/plugin/saiadmin/config/app.php', "<?php\nreturn [];\n");
    file_put_contents($directory . '/plugin/example/app/view/page.php', "<?php\n");
    foreach ([
        'Cast',
        'DeprecatedPeriodProperties',
        'IntervalRounding',
        'IntervalStep',
        'LocalFactory',
        'Macro',
        'MagicParameter',
        'Modifiers',
        'Mutability',
        'ObjectInitialisation',
        'Serialization',
        'StaticLocalization',
        'StaticOptions',
        'Test',
        'Timestamp',
        'ToStringFormat',
        'Units',
        'Week',
    ] as $trait) {
        file_put_contents(
            $directory . "/vendor/nesbot/carbon/src/Carbon/Traits/{$trait}.php",
            "<?php\ntrait {$trait} {}\n",
        );
    }
    file_put_contents($directory . '/composer.lock', json_encode([
        'packages' => [
            ['name' => 'saithink/saiadmin', 'version' => '6.1.1'],
            ['name' => 'topthink/think-orm', 'version' => $ormVersion],
            ['name' => 'nesbot/carbon', 'version' => '3.13.2'],
            [
                'name' => 'workerman/webman-framework',
                'version' => 'dev-master',
                'source' => ['reference' => 'fa352016aac4c9e21c8781cc127afd25ee144795'],
            ],
            [
                'name' => 'workerman/workerman',
                'version' => 'dev-master',
                'source' => ['reference' => '69bfc7765fff55bc3792560c715ae3ca8eadccb5'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
}

it('discovers SaiAdmin and installed plugin business sources and resources', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-profile-' . bin2hex(random_bytes(4));
    createSaiAdminProfileFixture($directory);

    try {
        $profile = new SaiAdminProfile($directory);
        expect($profile->assertSupported())
            ->toBe([
                'saithink/saiadmin' => '6.1.1',
                'topthink/think-orm' => 'v3.0.34',
                'nesbot/carbon' => '3.13.2',
                'workerman/webman-framework' => 'dev-master',
                'workerman/workerman' => 'dev-master',
            ])
            ->and($profile->sources())
            ->toContain('support', 'plugin/saiadmin', 'plugin/example/app')
            ->and($profile->dependencySources())
            ->toBe(['vendor'])
            ->and($profile->mergeCompilerSources([
                'main.php',
                'vendor',
                'vendor/nikic/fast-route/src/Route.php',
                '.typephp/build/fast-route-functions.php',
            ]))
            ->toBe([
                'main.php',
                'vendor',
                '.typephp/build/fast-route-functions.php',
                'app',
                'plugin/example/app',
                'plugin/saiadmin',
                'support',
            ])
            ->and($profile->runtimeResources())
            ->toContain('plugin/saiadmin/config', 'plugin/saiadmin/public', 'plugin/example/app/view');

        $manifest = $profile->writeCoverageManifest(
            ['app', 'support', 'plugin/saiadmin', 'plugin/example/app', '.typephp/build/login.php'],
            ['support/bootstrap.php', 'plugin/saiadmin/config', 'plugin/saiadmin/public',
                'plugin/example/app/view', 'plugin/saiadmin/app/controller/LoginController.php'],
            ['plugin/saiadmin/app/controller/LoginController.php' => '.typephp/build/login.php'],
        );
        expect($manifest['counts'])->toBe(['compiled' => 3, 'generated' => 1])
            ->and(file_exists($directory . '/.typephp/build/source-coverage.json'))->toBeTrue();
    } finally {
        removeSaiAdminProfileFixture($directory);
    }
});

it('fails closed on unsupported dependency drift or excluded business PHP', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-profile-' . bin2hex(random_bytes(4));
    createSaiAdminProfileFixture($directory, 'v4.0.0');

    try {
        $profile = new SaiAdminProfile($directory);
        expect(fn(): array => $profile->assertSupported())
            ->toThrow(RuntimeException::class, 'Unsupported topthink/think-orm version');

        $validLock = json_encode([
            'packages' => [
                ['name' => 'saithink/saiadmin', 'version' => '6.1.1'],
                ['name' => 'topthink/think-orm', 'version' => 'v3.0.34'],
                ['name' => 'nesbot/carbon', 'version' => '3.13.2'],
                [
                    'name' => 'workerman/webman-framework',
                    'version' => 'dev-master',
                    'source' => ['reference' => 'fa352016aac4c9e21c8781cc127afd25ee144795'],
                ],
                [
                    'name' => 'workerman/workerman',
                    'version' => 'dev-master',
                    'source' => ['reference' => '69bfc7765fff55bc3792560c715ae3ca8eadccb5'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents(
            $directory . '/composer.lock',
            str_replace(
                'fa352016aac4c9e21c8781cc127afd25ee144795',
                '0000000000000000000000000000000000000000',
                $validLock,
            ),
        );
        expect(fn(): array => (new SaiAdminProfile($directory))->assertSupported())
            ->toThrow(RuntimeException::class, 'Unsupported workerman/webman-framework source reference');

        file_put_contents($directory . '/composer.lock', $validLock);
        $profile = new SaiAdminProfile($directory);
        expect(fn(): array => $profile->writeCoverageManifest(
            ['app', 'support', 'plugin/saiadmin', 'plugin/example/app'],
            ['plugin/example/app/controller/ExampleController.php'],
            [],
        ))->toThrow(RuntimeException::class, 'excluded without a compiled AOT replacement');
    } finally {
        removeSaiAdminProfileFixture($directory);
    }
});
