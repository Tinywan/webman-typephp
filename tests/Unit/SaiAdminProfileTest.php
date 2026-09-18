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

function createSaiAdminProfileFixture(
    string $directory,
    string $ormVersion = 'v3.0.34',
    string $saiAdminVersion = '6.1.1',
    string $webmanVersion = 'dev-master',
    string $workermanVersion = 'dev-master',
): void {
    foreach ([
        'app/controller',
        'app/model',
        'support',
        'plugin/saiadmin/app/controller',
        'vendor/saithink/saiadmin/src/plugin/saiadmin/app/controller',
        'plugin/saiadmin/config',
        'plugin/saiadmin/db/data',
        'plugin/saiadmin/public',
        'plugin/example/app/controller',
        'plugin/example/app/view',
        'vendor/nesbot/carbon/src/Carbon/Traits',
    ] as $path) {
        mkdir($directory . '/' . $path, 0777, true);
    }
    foreach ([
        'app/controller/HomeController.php',
        'app/model/Test.php',
        'support/Request.php',
        'plugin/saiadmin/app/controller/LoginController.php',
        'plugin/example/app/controller/ExampleController.php',
    ] as $path) {
        file_put_contents($directory . '/' . $path, "<?php\nclass Fixture {}\n");
    }
    file_put_contents(
        $directory . '/vendor/saithink/saiadmin/src/plugin/saiadmin/app/controller/LoginController.php',
        "<?php\nclass Fixture {}\n",
    );
    file_put_contents($directory . '/support/bootstrap.php', "<?php\nreturn true;\n");
    file_put_contents($directory . '/plugin/saiadmin/config/app.php', "<?php\nreturn [];\n");
    file_put_contents($directory . '/plugin/saiadmin/db/data/demo.php', "<?php\nreturn [];\n");
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
            ['name' => 'saithink/saiadmin', 'version' => $saiAdminVersion],
            ['name' => 'topthink/think-orm', 'version' => $ormVersion],
            ['name' => 'nesbot/carbon', 'version' => '3.13.2'],
            [
                'name' => 'workerman/webman-framework',
                'version' => $webmanVersion,
            ],
            [
                'name' => 'workerman/workerman',
                'version' => $workermanVersion,
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
            ->toContain('plugin/saiadmin/config', 'plugin/saiadmin/public', 'plugin/example/app/view')
            ->and($profile->filterUserIgnores([
                'app/model',
                'app',
                'app/view',
                'support',
                'support/bootstrap.php',
                'vendor/example/tests',
            ]))
            ->toBe(['app/view', 'support/bootstrap.php', 'vendor/example/tests']);

        $manifest = $profile->writeCoverageManifest(
            ['app', 'support', 'plugin/saiadmin', 'plugin/example/app', '.typephp/build/login.php'],
            [
                'support/bootstrap.php',
                'plugin/saiadmin/config',
                'plugin/saiadmin/public',
                'plugin/example/app/view',
                'plugin/saiadmin/app/controller/LoginController.php',
            ],
            ['plugin/saiadmin/app/controller/LoginController.php' => '.typephp/build/login.php'],
        );
        expect($manifest['counts'])
            ->toBe(['compiled' => 4, 'generated' => 1])
            ->and(file_exists($directory . '/.typephp/build/source-coverage.json'))
            ->toBeTrue();
    } finally {
        removeSaiAdminProfileFixture($directory);
    }
});

it('does not reject SaiAdmin by version before applying structural compatibility rules', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-profile-' . bin2hex(random_bytes(4));

    try {
        createSaiAdminProfileFixture($directory, saiAdminVersion: '6.1.5');
        expect(new SaiAdminProfile($directory)->assertSupported()['saithink/saiadmin'])->toBe('6.1.5');

        foreach (['6.1.0', '6.1.6-beta.1', '6.2.0'] as $version) {
            $lock = file_get_contents($directory . '/composer.lock');
            file_put_contents($directory . '/composer.lock', str_replace('6.1.5', $version, (string) $lock));
            expect(new SaiAdminProfile($directory)->assertSupported()['saithink/saiadmin'])->toBe($version);
            file_put_contents($directory . '/composer.lock', (string) $lock);
        }
    } finally {
        removeSaiAdminProfileFixture($directory);
    }
});

it('rejects a composer version that does not match the installed SaiAdmin source', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-profile-' . bin2hex(random_bytes(4));
    createSaiAdminProfileFixture($directory, saiAdminVersion: '6.1.5');

    try {
        file_put_contents(
            $directory . '/plugin/saiadmin/app/controller/LoginController.php',
            "<?php\nclass ChangedFixture {}\n",
        );
        expect(fn(): array => new SaiAdminProfile($directory)->assertSupported())
            ->toThrow(RuntimeException::class, 'Installed SaiAdmin source does not match composer.lock package');
    } finally {
        removeSaiAdminProfileFixture($directory);
    }
});

it('uses the upstream Composer constraint without an extra Webman runtime gate', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-saiadmin-profile-' . bin2hex(random_bytes(4));

    try {
        createSaiAdminProfileFixture($directory, webmanVersion: 'v2.2.4', workermanVersion: 'v5.2.2');
        expect(new SaiAdminProfile($directory)->assertSupported())->toBe([
            'saithink/saiadmin' => '6.1.1',
            'topthink/think-orm' => 'v3.0.34',
            'nesbot/carbon' => '3.13.2',
        ]);
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

        $lock = (string) file_get_contents($directory . '/composer.lock');
        file_put_contents($directory . '/composer.lock', str_replace('v4.0.0', 'v3.0.34', $lock));
        $profile = new SaiAdminProfile($directory);
        expect(fn(): array => $profile->writeCoverageManifest(
            ['app', 'support', 'plugin/saiadmin', 'plugin/example/app'],
            ['plugin/example/app/controller/ExampleController.php'],
            [],
        ))
            ->toThrow(RuntimeException::class, 'excluded without a compiled AOT replacement');
    } finally {
        removeSaiAdminProfileFixture($directory);
    }
});
