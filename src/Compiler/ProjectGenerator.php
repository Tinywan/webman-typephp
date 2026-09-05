<?php

namespace Tinywan\Typephp\Compiler;

class ProjectGenerator
{
    protected string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    /**
     * 生成 main.php AOT 入口
     */
    public function generateMain(string $targetFile = null): string
    {
        $targetFile = $targetFile ?: $this->basePath . DIRECTORY_SEPARATOR . 'main.php';
        $stubPath = dirname(__DIR__) . '/Stubs/main.php.stub';
        if (!file_exists($targetFile)) {
            copy($stubPath, $targetFile);
        }
        return $targetFile;
    }

    /**
     * 分析项目并生成 project.linux.yml
     */
    public function generateProjectYml(array $extraConfig = []): string
    {
        $sources = [
            'main.php',
            'app',
            'vendor/workerman/workerman/src',
            'vendor/workerman/webman-framework/src',
            'vendor/workerman/coroutine/src',
            'vendor/psr',
            'vendor/nikic/fast-route/src/functions.php',
            'vendor/nikic/fast-route/src/BadRouteException.php',
            'vendor/nikic/fast-route/src/DataGenerator.php',
            'vendor/nikic/fast-route/src/Dispatcher.php',
            'vendor/nikic/fast-route/src/Route.php',
            'vendor/nikic/fast-route/src/RouteCollector.php',
            'vendor/nikic/fast-route/src/RouteParser.php',
            'vendor/nikic/fast-route/src/RouteParser/Std.php',
            'vendor/nikic/fast-route/src/DataGenerator/RegexBasedAbstract.php',
            'vendor/nikic/fast-route/src/DataGenerator/GroupCountBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/RegexBasedAbstract.php',
            'vendor/nikic/fast-route/src/Dispatcher/GroupCountBased.php',
        ];

        // 自动探测 monolog
        if (is_dir($this->basePath . '/vendor/monolog/monolog/src')) {
            $sources[] = 'vendor/monolog/monolog/src';
        }

        // 默认忽略列表
        $ignores = $extraConfig['ignore'] ?? [
            'config',
            'public',
            'runtime',
            'app/view',
            'app/model',
            'app/process/Monitor.php',
            'support',
            'vendor/workerman/webman-framework/src/support/bootstrap.php',
            'vendor/workerman/webman-framework/src/start.php',
            'vendor/workerman/webman-framework/src/windows.php',
            'vendor/workerman/webman-framework/src/Install.php',
            'vendor/nikic/fast-route/src/bootstrap.php',
            'vendor/nikic/fast-route/src/DataGenerator/CharCountBased.php',
            'vendor/nikic/fast-route/src/DataGenerator/GroupPosBased.php',
            'vendor/nikic/fast-route/src/DataGenerator/MarkBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/CharCountBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/GroupPosBased.php',
            'vendor/nikic/fast-route/src/Dispatcher/MarkBased.php',
            'vendor/workerman/coroutine/tests',
            'vendor/workerman/coroutine/stubs',
            'vendor/workerman/coroutine/src/Barrier/Swow.php',
            'vendor/workerman/coroutine/src/Channel/Swow.php',
            'vendor/workerman/coroutine/src/Context/Swow.php',
            'vendor/workerman/coroutine/src/Coroutine/Swow.php',
            'vendor/workerman/coroutine/src/WaitGroup/Swow.php',
            'vendor/workerman/workerman/src/Events/Swow.php',
            'vendor/workerman/coroutine/src/Pool.php',
            'vendor/workerman/coroutine/src/Utils/DestructionWatcher.php',
        ];

        $outputName = $extraConfig['build']['output_name'] ?? 'webman-server';

        $yaml = "name: {$outputName}\n\n";
        $yaml .= "sources:\n";
        foreach (array_unique($sources) as $source) {
            $yaml .= "  - {$source}\n";
        }

        $yaml .= "\nignore:\n";
        foreach (array_unique($ignores) as $ignore) {
            $yaml .= "  - {$ignore}\n";
        }

        $yaml .= "\noutput: build/{$outputName}\n";
        $yaml .= "mode: bin\n";
        $yaml .= "optimize: 2\n";
        $yaml .= "job: 1\n";
        $yaml .= "debug: false\n";

        $ymlPath = $this->basePath . DIRECTORY_SEPARATOR . 'project.linux.yml';
        file_put_contents($ymlPath, $yaml);
        return $ymlPath;
    }
}
