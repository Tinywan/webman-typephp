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
use Symfony\Component\Process\Process;

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
        $allPassed = true;

        // 1. PHP Version
        $phpVer = PHP_VERSION;
        $isPhpOk = version_compare($phpVer, '8.4', '>=') && version_compare($phpVer, '8.6', '<');
        if ($isPhpOk) {
            $output->writeln("• PHP Version: {$phpVer} <info>[OK]</info>");
        } else {
            $output->writeln("• PHP Version: {$phpVer} <error>[FAIL - Requires >= 8.4 and < 8.6]</error>");
            $allPassed = false;
        }

        // 2. Docker Check (必须可用)
        $dockerProcess = new Process(['docker', '--version']);
        $dockerProcess->run();
        if ($dockerProcess->isSuccessful()) {
            $output->writeln('• Docker: ' . trim($dockerProcess->getOutput()) . ' <info>[OK - Required for Phase 1]</info>');
        } else {
            $output->writeln('• Docker: Not found or not running <error>[FAIL - Docker is required for portable-dir build]</error>');
            $allPassed = false;
        }

        // 3. Clang (宿主机非必需提示)
        $clangProcess = new Process(['clang', '--version']);
        $clangProcess->run();
        if ($clangProcess->isSuccessful()) {
            $output->writeln('• Host Clang Compiler: Installed <comment>[Optional - Containerized build bypasses host compiler]</comment>');
        } else {
            $output->writeln('• Host Clang Compiler: Not installed <info>[OK - Handled inside Docker builder]</info>');
        }

        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }
}
