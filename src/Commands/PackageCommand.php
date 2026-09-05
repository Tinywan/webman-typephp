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

class PackageCommand extends Command
{
    protected static $defaultName = 'typephp:package';
    protected static $defaultDescription = 'Build Webman project into a Linux x86_64 portable-dir using TypePHP Docker builder.';

    protected function configure(): void
    {
        $this->setName('typephp:package')
            ->setDescription('Build Webman project into a Linux x86_64 portable-dir using TypePHP Docker builder')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Docker builder image reference', 'tinywan/typephp-webman-builder:alpine')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Target output directory relative to project root', 'dist')
            ->addOption('output-name', null, InputOption::VALUE_REQUIRED, 'Target executable name', 'webman-server')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing output directory if it already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $image = (string)$input->getOption('image');
        $outputDir = trim((string)$input->getOption('output-dir'), '/\\');
        $outputName = (string)$input->getOption('output-name');
        $force = (bool)$input->getOption('force');

        // 1. 严格参数校验，防止任何路径穿越或格式错误
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $outputName)) {
            $output->writeln("<error>[ERROR] Invalid output-name: '{$outputName}'. Only alphanumeric and ._- allowed.</error>");
            return Command::FAILURE;
        }

        if ($outputDir === '' || $outputDir === '.typephp' || str_starts_with($outputDir, '.typephp/') || str_contains($outputDir, '..')) {
            $output->writeln("<error>[ERROR] Invalid output-dir: '{$outputDir}'. Relative path without .. and not inside .typephp required.</error>");
            return Command::FAILURE;
        }

        // 镜像引用格式检查（防止注入）
        if (!preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:\/[a-z0-9]+(?:[._-][a-z0-9]+)*)*(?::[a-zA-Z0-9_.-]+)?$/', $image)) {
            $output->writeln("<error>[ERROR] Invalid Docker image reference: '{$image}'.</error>");
            return Command::FAILURE;
        }

        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $targetDist = $basePath . DIRECTORY_SEPARATOR . $outputDir;

        // 2. 已有输出目录保护检查
        if (is_dir($targetDist) && !$force) {
            $output->writeln("<error>[ERROR] Target output directory '{$outputDir}' already exists!</error>");
            $output->writeln("<comment>Use --force (-f) flag if you explicitly wish to overwrite it.</comment>");
            return Command::FAILURE;
        }

        // 3. 检查 Docker 环境
        $dockerCheck = new Process(['docker', '--version']);
        $dockerCheck->run();
        if (!$dockerCheck->isSuccessful()) {
            $output->writeln('<error>[ERROR] Docker is required for TypePHP Phase 1 build but not found or not running!</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>[TypePHP] Preparing build files for Webman project...</info>');

        // 4. 确保工作目录与暂存区
        $stageBuildDir = $basePath . DIRECTORY_SEPARATOR . '.typephp' . DIRECTORY_SEPARATOR . 'build';
        if (!is_dir($stageBuildDir)) {
            mkdir($stageBuildDir, 0777, true);
        }

        $generator = new ProjectGenerator($basePath);

        // 生成 main.php
        $generator->generateMain();
        $output->writeln('<comment>[1/3] Generated AOT entrypoint: main.php</comment>');

        // 动态合并配置并生成 project.linux.yml
        $extraConfig = [
            'build' => [
                'output_name' => $outputName,
            ]
        ];
        if (function_exists('config')) {
            $pluginConfig = config('plugin.tinywan.typephp.app', []);
            $extraConfig = array_replace_recursive($pluginConfig, $extraConfig);
        }
        $projectYmlPath = $generator->generateProjectYml($extraConfig);

        // 拷贝 project.linux.yml 到 .typephp/build/
        copy($projectYmlPath, $stageBuildDir . DIRECTORY_SEPARATOR . 'project.linux.yml');
        $output->writeln('<comment>[2/3] Generated compiler config: .typephp/build/project.linux.yml</comment>');

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
            ],
        ];
        file_put_contents(
            $stageBuildDir . DIRECTORY_SEPARATOR . 'build-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // 5. 编排并运行 Docker 命令（参数数组隔离，绝无字符串拼接）
        $output->writeln("<info>[3/3] Running TypePHP AOT compilation container ({$image})...</info>");

        $dockerArgs = [
            'docker',
            'run',
            '--rm',
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
            $image
        ];

        $process = new Process($dockerArgs, $basePath, null, null, 1800);
        $process->run(function ($type, $buffer) use ($output): void {
            $output->write($buffer);
        });

        if ($process->isSuccessful()) {
            $output->writeln("<info>🎉 Successfully built portable-dir: {$outputDir}/{$outputName}</info>");
            $output->writeln("<info>Run command: ./$outputDir/$outputName start</info>");
            return Command::SUCCESS;
        }

        $output->writeln('<error>[ERROR] TypePHP build failed with exit code ' . $process->getExitCode() . '</error>');
        return Command::FAILURE;
    }
}
