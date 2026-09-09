<?php

/**
 * @desc TypePHP 打包构建命令
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/05
 */
declare(strict_types=1);

namespace Tinywan\Typephp\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Tinywan\Typephp\Compiler\ProjectGenerator;

// @mago-ignore lint:cyclomatic-complexity -- The command deliberately coordinates validation, manifest generation, and Docker invocation.
class PackageCommand extends Command
{
    private const DEFAULT_BUILDER_IMAGE = 'tinywan/typephp-webman-builder:v0.1.2';

    protected static $defaultName = 'typephp:package';
    protected static $defaultDescription = 'Build Webman project into a Linux x86_64 portable-dir using TypePHP Docker builder.';

    protected function configure(): void
    {
        $this
            ->setName('typephp:package')
            ->setDescription('Build Webman project into a Linux x86_64 portable-dir using TypePHP Docker builder')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Docker builder image reference', null)
            ->addOption(
                'output-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Target output directory relative to project root',
                'dist',
            )
            ->addOption('output-name', null, InputOption::VALUE_REQUIRED, 'Target executable name', 'webman-server')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing output directory if it already exists',
            )
            ->addOption(
                'refresh-main',
                null,
                InputOption::VALUE_NONE,
                'Force refresh main.php from the latest TypePHP stub (backups existing to main.php.bak)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pluginConfig = [];
        if (function_exists('config')) {
            $configuredPluginConfig = config('plugin.tinywan.typephp.app', []);
            if (is_array($configuredPluginConfig)) {
                $pluginConfig = $configuredPluginConfig;
            }
        }

        $optionImage = $input->getOption('image');
        $image = $this->resolveBuilderImage(is_string($optionImage) ? $optionImage : null, $pluginConfig);
        $outputDir = trim((string) $input->getOption('output-dir'), '/\\');
        $outputName = (string) $input->getOption('output-name');
        $force = (bool) $input->getOption('force');

        // 1. 严格参数校验，防止任何路径穿越或格式错误
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $outputName)) {
            $output->writeln(
                "<error>[ERROR] Invalid output-name: '{$outputName}'. Only alphanumeric and ._- allowed.</error>",
            );
            return Command::FAILURE;
        }

        if (
            $outputDir === ''
            || $outputDir === '.typephp'
            || str_starts_with($outputDir, '.typephp/')
            || str_contains($outputDir, '..')
        ) {
            $output->writeln(
                "<error>[ERROR] Invalid output-dir: '{$outputDir}'. Relative path without .. and not inside .typephp required.</error>",
            );
            return Command::FAILURE;
        }

        // 镜像引用格式检查（防止注入）
        if (!preg_match(
            '/^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:\/[a-z0-9]+(?:[._-][a-z0-9]+)*)*(?::[a-zA-Z0-9_.-]+)?(?:@sha256:[a-f0-9]{64})?$/',
            $image,
        )) {
            $output->writeln("<error>[ERROR] Invalid Docker image reference: '{$image}'.</error>");
            return Command::FAILURE;
        }

        $basePath = (string) (function_exists('base_path') ? base_path() : getcwd());
        $targetDist = $basePath . DIRECTORY_SEPARATOR . $outputDir;

        // 2. 已有输出目录保护检查
        if (is_dir($targetDist) && !$force) {
            $output->writeln("<error>[ERROR] Target output directory '{$outputDir}' already exists!</error>");
            $output->writeln('<comment>Use --force (-f) flag if you explicitly wish to overwrite it.</comment>');
            return Command::FAILURE;
        }

        // 3. 检查 Docker 环境
        $dockerCheck = new Process(['docker', '--version']);
        $dockerCheck->run();
        if (!$dockerCheck->isSuccessful()) {
            $output->writeln(
                '<error>[ERROR] Docker is required for TypePHP Phase 1 build but not found or not running!</error>',
            );
            return Command::FAILURE;
        }

        $output->writeln('<info>[TypePHP] Preparing build files for Webman project...</info>');

        // 4. 确保工作目录与暂存区
        $stageBuildDir = $basePath . DIRECTORY_SEPARATOR . '.typephp' . DIRECTORY_SEPARATOR . 'build';
        if (!is_dir($stageBuildDir)) {
            mkdir($stageBuildDir, 0777, true);
        }

        $generator = new ProjectGenerator($basePath);

        try {
            // 生成或刷新 main.php
            $refreshMain = (bool) $input->getOption('refresh-main');
            $generator->generateMain(null, $refreshMain);
            if ($refreshMain) {
                $output->writeln(
                    '<comment>[1/3] Refreshed AOT entrypoint: main.php (backup saved to main.php.bak)</comment>',
                );
            } else {
                $output->writeln('<comment>[1/3] Verified AOT entrypoint: main.php</comment>');
            }

            // 动态合并配置并生成 project.linux.yml
            $extraConfig = [
                'build' => [
                    'output_name' => $outputName,
                ],
            ];
            $extraConfig = array_replace_recursive($pluginConfig, $extraConfig);
            $projectYmlPath = $generator->generateProjectYml($extraConfig);
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>[ERROR] ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // 确保 project.linux.yml 保存在项目根目录，供 tpc 以项目根目录为基准直接解析
        copy($projectYmlPath, $stageBuildDir . DIRECTORY_SEPARATOR . 'project.linux.yml');
        $output->writeln('<comment>[2/3] Generated compiler config: project.linux.yml</comment>');

        // 汇总各类 AOT 生成源（平铺守卫源、静态属性补丁源、可变参数闭包补丁源）
        $flattenedHashes = $this->collectGeneratedHashes($basePath, ProjectGenerator::GUARDED_SOURCES);
        $patchedHashes = $this->collectGeneratedHashes($basePath, ProjectGenerator::NULLABLE_STATIC_SOURCES);
        $variadicHashes = $this->collectGeneratedHashes($basePath, ProjectGenerator::VARIADIC_HANDLER_SOURCES);
        $this->reportGeneratedSources($output, 'flattened', $flattenedHashes);
        $this->reportGeneratedSources($output, 'nullable-static', $patchedHashes);
        $this->reportGeneratedSources($output, 'variadic-handler', $variadicHashes);

        // 生成 build-manifest.json 记录元数据
        $manifest = [
            'phase' => 1,
            'target' => 'linux-x86_64-portable-dir',
            'builder_image' => $image,
            'output_name' => $outputName,
            'output_dir' => $outputDir,
            'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'inputs' => [
                'main_hash' => file_exists($basePath . '/main.php') ? sha1_file($basePath . '/main.php') : '',
                'config_hash' => sha1_file($projectYmlPath),
                'flattened_hashes' => $flattenedHashes,
                'nullable_static_hashes' => $patchedHashes,
                'variadic_handler_hashes' => $variadicHashes,
            ],
        ];
        file_put_contents($stageBuildDir . DIRECTORY_SEPARATOR . 'build-manifest.json', json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        // 5. 编排并运行 Docker 命令（参数数组隔离，绝无字符串拼接）
        $output->writeln("<info>[3/3] Running TypePHP AOT compilation container ({$image})...</info>");

        $dockerArgs = [
            'docker',
            'run',
            '--rm',
            '--platform',
            'linux/amd64',
            '-v',
            $basePath . ':/workspace',
            '-w',
            '/workspace',
            '-e',
            'TYPEPHP_OUTPUT_DIR=' . $outputDir,
            '-e',
            'TYPEPHP_OUTPUT_NAME=' . $outputName,
            '-e',
            'TYPEPHP_FORCE=' . ($force ? '1' : '0'),
            $image,
        ];

        $process = new Process($dockerArgs, $basePath, null, null, 1800);
        $process->run(function ($type, $buffer) use ($output): void {
            $output->write($buffer);
        });

        if ($process->isSuccessful()) {
            $output->writeln("<info>🎉 Successfully built portable-dir: {$outputDir}/{$outputName}</info>");
            $output->writeln("<info>Run command: cd $outputDir && ./start.sh start</info>");

            // 净化 dist/config：把 `Phar::CONST` 类常量替换为等值整数，避免宿主运行时
            // 缺少 phar 扩展时，webman 运行期 include 配置抛 Class "Phar" not found。
            $sanitizedConfigDir = $basePath . DIRECTORY_SEPARATOR . $outputDir . DIRECTORY_SEPARATOR . 'config';
            if (is_dir($sanitizedConfigDir)) {
                $sanitized = new \Tinywan\Typephp\Compiler\DistConfigSanitizer()->sanitizeDirectory(
                    $sanitizedConfigDir,
                );
                if ($sanitized !== []) {
                    $output->writeln(
                        '<comment>[post] Neutralized phar class constants in '
                        . count($sanitized)
                        . ' config file(s): '
                        . implode(', ', $sanitized)
                        . '</comment>',
                    );
                }
            }

            return Command::SUCCESS;
        }

        $output->writeln('<error>[ERROR] TypePHP build failed with exit code ' . $process->getExitCode() . '</error>');
        return Command::FAILURE;
    }

    /**
     * @param array<string, string> $targets 生成目标相对路径 => 哈希
     * @return array<string, string>
     */
    private function collectGeneratedHashes(string $basePath, array $targets): array
    {
        $hashes = [];
        foreach ($targets as $target) {
            $file = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
            if (is_file($file)) {
                $hashes[$target] = (string) sha1_file($file);
            }
        }
        return $hashes;
    }

    /**
     * @param array<array-key, mixed> $pluginConfig
     */
    private function resolveBuilderImage(?string $optionImage, array $pluginConfig): string
    {
        if ($optionImage !== null) {
            return $optionImage;
        }

        $dockerConfig = $pluginConfig['docker'] ?? null;
        if (!is_array($dockerConfig)) {
            return self::DEFAULT_BUILDER_IMAGE;
        }

        $configuredImage = $dockerConfig['image'] ?? null;
        return is_string($configuredImage) ? $configuredImage : self::DEFAULT_BUILDER_IMAGE;
    }

    /**
     * @param array<string, string> $hashes
     */
    private function reportGeneratedSources(OutputInterface $output, string $label, array $hashes): void
    {
        if ($hashes !== []) {
            $output->writeln(
                '<comment>[2/3] Generated '
                . $label
                . ' AOT sources: '
                . implode(', ', array_keys($hashes))
                . '</comment>',
            );
        }
    }
}
