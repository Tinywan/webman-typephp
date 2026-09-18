<?php

/**
 * @desc TypePHP 编译环境诊断命令
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
use Tinywan\Typephp\Compiler\NativeToolchain;

// @mago-ignore lint:cyclomatic-complexity -- Docker and native diagnostics are independent explicit checks.
class DoctorCommand extends Command
{
    protected static $defaultName = 'typephp:doctor';
    protected static $defaultDescription = 'Check local system environment for TypePHP compilation.';

    protected function configure(): void
    {
        $this
            ->setName('typephp:doctor')
            ->setDescription('Check local system environment for TypePHP compilation')
            ->addOption(
                'target',
                null,
                InputOption::VALUE_REQUIRED,
                'Toolchain to check: docker, native, or all',
                'docker',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>=== TypePHP Environment Diagnostic Tool ===</info>');
        $allPassed = true;
        $target = (string) $input->getOption('target');
        if (!in_array($target, ['docker', 'native', 'all'], true)) {
            $output->writeln("<error>[FAIL] Unknown doctor target: {$target}</error>");
            return Command::FAILURE;
        }

        // 1. PHP Version
        $phpVer = PHP_VERSION;
        $isPhpOk = version_compare($phpVer, '8.4', '>=') && version_compare($phpVer, '8.6', '<');
        if ($isPhpOk) {
            $output->writeln("• PHP Version: {$phpVer} <info>[OK]</info>");
        } else {
            $output->writeln("• PHP Version: {$phpVer} <error>[FAIL - Requires >= 8.4 and < 8.6]</error>");
            $allPassed = false;
        }

        if ($target === 'docker' || $target === 'all') {
            $dockerProcess = new Process(['docker', '--version']);
            $dockerProcess->run();
            if ($dockerProcess->isSuccessful()) {
                $output->writeln(
                    '• Docker: ' . trim($dockerProcess->getOutput()) . ' <info>[OK - Required for portable-dir]</info>',
                );
            } else {
                $output->writeln(
                    '• Docker: Not found or not running <error>[FAIL - Required for portable-dir build]</error>',
                );
                $allPassed = false;
            }
        }

        if ($target === 'native' || $target === 'all') {
            $pluginConfig = require dirname(__DIR__) . '/config/plugin/tinywan/typephp/app.php';
            $nativeConfig = is_array($pluginConfig) && is_array($pluginConfig['native'] ?? null)
                ? $pluginConfig['native']
                : [];
            if (function_exists('config')) {
                $configured = config('plugin.tinywan.typephp.app.native', []);
                if (is_array($configured)) {
                    $nativeConfig = array_replace($nativeConfig, $configured);
                }
            }
            try {
                $toolchain = new NativeToolchain()->resolve($nativeConfig);
                $output->writeln("• Native PHP embed: {$toolchain['php_version']} <info>[OK]</info>");
                $output->writeln("• TypePHP: {$toolchain['tpc_version']} <info>[OK]</info>");
                $output->writeln('• PHPX runtime: ' . $toolchain['phpx_home'] . ' <info>[OK]</info>');
                $output->writeln('• C++ compiler: ' . $toolchain['cxx'] . ' <info>[OK]</info>');
            } catch (\RuntimeException $exception) {
                $output->writeln('<error>• Native toolchain: [FAIL] ' . $exception->getMessage() . '</error>');
                $allPassed = false;
            }
        } elseif ($target === 'docker') {
            $clangProcess = new Process(['clang', '--version']);
            $clangProcess->run();
            if ($clangProcess->isSuccessful()) {
                $output->writeln(
                    '• Host Clang Compiler: Installed <comment>[Optional - Docker build bypasses host compiler]</comment>',
                );
            } else {
                $output->writeln(
                    '• Host Clang Compiler: Not installed <info>[OK - Handled inside Docker builder]</info>',
                );
            }
        }

        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }
}
