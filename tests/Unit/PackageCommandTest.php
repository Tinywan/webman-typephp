<?php

declare(strict_types=1);

it('rejects illegal output names or invalid paths', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();
    expect($command->getName())->toBe('typephp:package');
    expect($command->getDefinition()->hasOption('force'))->toBeTrue();
    expect($command->getDefinition()->hasOption('image'))->toBeTrue();
    expect($command->getDefinition()->hasOption('profile'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-dir'))->toBeTrue();
    expect($command->getDefinition()->hasOption('output-name'))->toBeTrue();
    expect($command->getDefinition()->hasOption('refresh-main'))->toBeTrue();
});

it('leaves the image option unset so configuration can supply it', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();

    expect($command->getDefinition()->getOption('image')->getDefault())->toBeNull();
});

it('ships the v0.1.3 builder image in the plugin configuration', function (): void {
    $pluginConfig = require dirname(__DIR__, 2) . '/src/config/plugin/tinywan/typephp/app.php';
    if (!is_array($pluginConfig)) {
        throw new RuntimeException('Plugin configuration must be an array.');
    }

    $dockerConfig = $pluginConfig['docker'] ?? null;
    if (!is_array($dockerConfig)) {
        throw new RuntimeException('Plugin Docker configuration must be an array.');
    }

    expect($dockerConfig['image'] ?? null)->toBe('tinywan/typephp-webman-builder:v0.1.3');
    expect($pluginConfig['build']['jobs'] ?? null)->toBe(4);
    expect($pluginConfig['runtime_resources'] ?? [])
        ->toContain('vendor/laravel/serializable-closure')
        ->toContain('vendor/phpmailer/phpmailer/get_oauth_token.php')
        ->toContain('vendor/symfony/cache')
        ->toContain('vendor/symfony/clock/Resources')
        ->toContain('vendor/symfony/console/Attribute')
        ->toContain('vendor/symfony/console/Command/InvokableCommand.php')
        ->toContain('vendor/symfony/console/Command/TraceableCommand.php')
        ->toContain('vendor/symfony/console/Completion')
        ->toContain('vendor/symfony/console/DataCollector')
        ->toContain('vendor/symfony/console/Helper/Table.php')
        ->toContain('vendor/symfony/error-handler')
        ->toContain('vendor/symfony/finder')
        ->toContain('vendor/symfony/http-foundation/Session/Storage/Handler/IdentityMarshaller.php')
        ->toContain('vendor/symfony/http-foundation/Session/Storage/Handler/MigratingSessionHandler.php')
        ->toContain('vendor/symfony/http-kernel/DataCollector')
        ->toContain('vendor/symfony/http-kernel/Fragment/InlineFragmentRenderer.php')
        ->toContain('vendor/symfony/http-kernel/Kernel.php')
        ->toContain('vendor/symfony/mime/Crypto')
        ->toContain('vendor/symfony/mime/HtmlToTextConverter/LeagueHtmlToMarkdownConverter.php')
        ->toContain('vendor/symfony/mime/Part')
        ->toContain('vendor/symfony/polyfill-intl-normalizer')
        ->toContain('vendor/symfony/process')
        ->toContain('vendor/symfony/string')
        ->toContain('vendor/symfony/translation/Util/ArrayConverter.php')
        ->toContain('vendor/symfony/var-dumper')
        ->toContain('vendor/symfony/var-exporter')
        ->toContain('vendor/symfony/translation/DataCollector')
        ->toContain('vendor/openspout/openspout')
        ->toContain('vendor/twig/twig')
        ->toContain('vendor/tinywan/storage')
        ->toContain('vendor/topthink/think-orm/stubs/load_stubs.php')
        ->toContain('vendor/topthink/think-orm/src/db/Mongo.php')
        ->toContain('vendor/topthink/think-orm/src/db/builder/Mongo.php')
        ->toContain('vendor/topthink/think-orm/src/db/connector/Mongo.php')
        ->toContain('vendor/topthink/think-orm/src/db/builder/Oracle.php')
        ->toContain('vendor/topthink/think-orm/src/db/builder/Pgsql.php')
        ->toContain('vendor/topthink/think-orm/src/db/builder/Sqlite.php')
        ->toContain('vendor/topthink/think-orm/src/db/builder/Sqlsrv.php')
        ->toContain('vendor/voku/portable-ascii')
        ->toContain('vendor/webman/channel')
        ->toContain('vendor/webman/console/src/Commands')
        ->toContain('vendor/webman/console/src/Messages.php')
        ->toContain('vendor/webman/console/src/Util.php')
        ->toContain('vendor/webman/database/src/support/MongoModel.php')
        ->toContain('vendor/webman/captcha/src/Font')
        ->toContain('vendor/workerman/channel')
        ->toContain('vendor/workerman/coroutine/src/Pool.php')
        ->toContain('vendor/workerman/coroutine/src/Utils/DestructionWatcher.php')
        ->toContain('vendor/voku/portable-ascii/src/voku/helper/data')
        ->toContain('vendor/zoujingli/ip2region/ip2region.xdb')
        ->toContain('plugin/saiadmin/utils/code/stub');
    expect($pluginConfig['ignore'] ?? [])
        ->toContain('vendor/symfony/console/Helper/Table.php')
        ->toContain('vendor/symfony/mime/HtmlToTextConverter/LeagueHtmlToMarkdownConverter.php')
        ->toContain('vendor/workerman/crontab/example');
    expect($pluginConfig['runtime_resources'] ?? [])
        ->not->toContain('plugin/saiadmin/app/controller/LoginController.php')
        ->not->toContain('plugin/saiadmin/app/controller/InstallController.php');
    expect($pluginConfig['ignore'] ?? [])
        ->not->toContain('plugin/saiadmin/app/controller/LoginController.php')
        ->not->toContain('plugin/saiadmin/app/controller/InstallController.php');
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

    expect($method->invoke($command, null, []))->toBe('tinywan/typephp-webman-builder:v0.1.3');
});

it('keeps new packaged ignore defaults when a project has stale published configuration', function (): void {
    $command = new \Tinywan\Typephp\Commands\PackageCommand();
    $method = new ReflectionMethod($command, 'mergePluginConfig');

    $merged = $method->invoke($command, [
        'docker' => ['image' => 'tinywan/typephp-webman-builder:v0.1.3'],
        'ignore' => ['vendor/carbonphp/carbon-doctrine-types'],
        'runtime_resources' => ['plugin/saiadmin/utils/code/stub'],
    ], [
        'docker' => ['image' => 'tinywan/typephp-webman-builder:v0.2.1'],
        'ignore' => ['app/model'],
        'runtime_resources' => ['public'],
    ]);

    expect($merged['docker']['image'])->toBe('tinywan/typephp-webman-builder:v0.2.1');
    expect($merged['ignore'])->toBe([
        'vendor/carbonphp/carbon-doctrine-types',
        'app/model',
    ]);
    expect($merged['runtime_resources'])->toBe([
        'plugin/saiadmin/utils/code/stub',
        'public',
    ]);
});
