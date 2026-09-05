<?php

namespace Tinywan\Typephp\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tinywan\Typephp\Compiler\ProjectGenerator;

class PackageCommand extends Command
{
    protected static $defaultName = 'typephp:package';
    protected static $defaultDescription = 'Compile Webman application into native binary using TypePHP AOT.';

    protected function configure(): void
    {
        $this->setName('typephp:package')
            ->setDescription('Compile Webman application into native binary using TypePHP AOT')
            ->addOption('docker', null, InputOption::VALUE_OPTIONAL, 'Use Docker build environment (default: true)', 'true')
            ->addOption('image', null, InputOption::VALUE_OPTIONAL, 'Custom Docker builder image', null)
            ->addOption('full-static', null, InputOption::VALUE_NONE, 'Build Musl-based full static single binary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>[TypePHP] Preparing build files for Webman project...</info>');

        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $generator = new ProjectGenerator($basePath);

        // 1. 生成 main.php
        $generator->generateMain();
        $output->writeln('<comment>[1/3] Generated AOT entrypoint: main.php</comment>');

        // 2. 生成 project.linux.yml
        $generator->generateProjectYml();
        $output->writeln('<comment>[2/3] Generated compiler config: project.linux.yml</comment>');

        // 3. 执行 Docker 编译
        $useDocker = filter_var($input->getOption('docker'), FILTER_VALIDATE_BOOLEAN);
        if ($useDocker) {
            $image = $input->getOption('image') ?: 'tinywan/typephp-builder:alpine';
            $output->writeln("<info>[3/3] Launching Docker build container ({$image})...</info>");

            // 检查 docker 命令是否存在
            exec('docker --version 2>&1', $dockerCheck, $code);
            if ($code !== 0) {
                $output->writeln('<error>[ERROR] Docker is not installed or not in PATH! Please install Docker or run native build.</error>');
                return Command::FAILURE;
            }

            $workspace = escapeshellarg($basePath);
            $dockerCmd = "docker run --rm -v {$workspace}:/workspace -w /workspace {$image}";
            $output->writeln("<comment>Running: {$dockerCmd}</comment>");

            passthru($dockerCmd, $exitCode);
            if ($exitCode === 0) {
                $output->writeln('<info>🎉 Build successfully! Output binary: dist/webman-server</info>');
                $output->writeln('<info>Run command: ./dist/webman-server start</info>');
                return Command::SUCCESS;
            } else {
                $output->writeln('<error>[ERROR] Docker build failed with exit code ' . $exitCode . '</error>');
                return Command::FAILURE;
            }
        }

        $output->writeln('<error>[ERROR] Native build without Docker is not fully configured yet. Please use Docker mode.</error>');
        return Command::FAILURE;
    }
}
