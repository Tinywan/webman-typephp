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
use Symfony\Component\Console\Output\OutputInterface;

class DoctorCommand extends Command
{
    protected static $defaultName = 'typephp:doctor';
    protected static $defaultDescription = 'Check local system environment for TypePHP compilation.';

    protected function configure(): void
    {
        $this->setName('typephp:doctor')
            ->setDescription('Check local system environment for TypePHP compilation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>=== TypePHP Environment Diagnostic Tool ===</info>');

        // 1. PHP Version
        $phpVer = PHP_VERSION;
        $output->writeln("• PHP Version: {$phpVer} " . (version_compare($phpVer, '8.5', '>=') ? '<info>[OK]</info>' : '<error>[FAIL - Requires >= 8.5]</error>'));

        // 2. Docker Check
        exec('docker --version 2>&1', $dockerOut, $dockerCode);
        if ($dockerCode === 0) {
            $output->writeln('• Docker: ' . ($dockerOut[0] ?? 'Installed') . ' <info>[OK - Recommended]</info>');
        } else {
            $output->writeln('• Docker: Not found <comment>[WARN - Docker is strongly recommended]</comment>');
        }

        // 3. Clang
        exec('clang --version 2>&1', $clangOut, $clangCode);
        if ($clangCode === 0) {
            $output->writeln('• Clang Compiler: ' . ($clangOut[0] ?? 'Installed') . ' <info>[OK]</info>');
        } else {
            $output->writeln('• Clang Compiler: Not installed <comment>[Note: Docker build mode can bypass this]</comment>');
        }

        return Command::SUCCESS;
    }
}
