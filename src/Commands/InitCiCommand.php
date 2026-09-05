<?php
/**
 * @desc TypePHP GitHub Actions CI 工作流初始化命令
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/05
 */
declare(strict_types=1);

namespace Tinywan\Typephp\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InitCiCommand extends Command
{
    protected static $defaultName = 'typephp:init-ci';
    protected static $defaultDescription = 'Generate GitHub Actions CI workflow for automatic multi-platform builds.';

    protected function configure(): void
    {
        $this->setName('typephp:init-ci')
            ->setDescription('Generate GitHub Actions CI workflow for automatic multi-platform builds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $targetDir = $basePath . '/.github/workflows';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $ciFile = $targetDir . '/typephp-build.yml';
        if (file_exists($ciFile)) {
            $output->writeln('<comment>[WARN] .github/workflows/typephp-build.yml already exists. Skipped.</comment>');
            return Command::SUCCESS;
        }

        $content = <<<'YAML'
name: TypePHP Automated Build & Release

on:
  push:
    tags:
      - 'v*'
  workflow_dispatch:

jobs:
  build-static:
    name: Build Linux Static Single Binary
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          tools: composer:v2
      - name: Install Composer Dependencies
        run: composer install --prefer-dist --no-progress
      - name: Build Full-Static Binary via TypePHP Builder
        run: |
          docker run --rm -v "$(pwd):/workspace" -w /workspace tinywan/typephp-builder:alpine
          tar -czvf webman-server-linux-x64-static.tar.gz -C dist .
      - name: Upload Release Asset
        uses: softprops/action-gh-release@v2
        if: startsWith(github.ref, 'refs/tags/')
        with:
          files: webman-server-linux-x64-static.tar.gz
YAML;

        file_put_contents($ciFile, $content);
        $output->writeln('<info>[OK] Created GitHub Actions workflow: .github/workflows/typephp-build.yml</info>');
        $output->writeln('<info>Push a git tag (e.g. v1.0.0) to trigger automatic releases!</info>');

        return Command::SUCCESS;
    }
}
