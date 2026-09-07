<?php

/**
 * @desc dist 产物配置净化器：剥离对 phar 扩展类的运行期依赖
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/07
 */
declare(strict_types=1);

namespace Tinywan\Typephp\Compiler;

/**
 * 把 dist/config 下 PHP 配置里的 `Phar::CONST` 类常量表达式替换成等值整数字面量。
 *
 * 产物 config 会被 webman 在运行期逐文件 include；若宿主 PHP 运行时没有 phar 扩展，
 * 顶层的 `'phar_format' => Phar::PHAR` 会直接抛 `Uncaught Error: Class "Phar" not found`，
 * 进程在加载配置阶段即崩溃。而 webman/console 等插件配置里的 Phar 常量只是“打 phar 包”
 * 这类构建期元数据，AOT 部署运行时根本用不到真实的 Phar 类，替换成字面量后语义不变、
 * 任何宿主环境都能加载。
 */
final class DistConfigSanitizer
{
    /**
     * PHP 内置 Phar 常量 => 等值整数（与 ext/phar 的常量值一致，稳定不变）
     */
    private const PHAR_CONSTANTS = [
        'PHAR' => 0,
        'TAR' => 1,
        'ZIP' => 2,
        'NONE' => 0,
        'GZ' => 0x1000,
        'BZ2' => 0x2000,
        'MD5' => 0x0001,
        'SHA1' => 0x0002,
        'SHA256' => 0x0003,
        'SHA512' => 0x0004,
        'OPENSSL' => 0x0010,
    ];

    /**
     * 净化整个 dist config 目录中的 PHP 配置文件。
     *
     * @param string $configDir dist/config 目录的绝对路径
     * @return list<string> 被修改文件的相对路径（相对 $configDir），升序
     */
    public function sanitizeDirectory(string $configDir): array
    {
        $changedFiles = [];
        if (!is_dir($configDir)) {
            return $changedFiles;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($configDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $path = (string) $fileInfo->getPathname();
            if (strtolower((string) $fileInfo->getExtension()) !== 'php') {
                continue;
            }
            $original = (string) file_get_contents($path);
            if (!str_contains($original, 'Phar::')) {
                continue;
            }
            $cleaned = $this->sanitizePhpSource($original);
            if ($cleaned === $original) {
                continue;
            }
            if (file_put_contents($path, $cleaned) === false) {
                throw new \RuntimeException('Unable to write sanitized dist config file: ' . $path);
            }
            $changedFiles[] = str_replace('\\', '/', ltrim(substr($path, strlen($configDir)), '/\\'));
        }
        sort($changedFiles);
        return $changedFiles;
    }

    /**
     * 把一段 PHP 源码中非限定形式的 `Phar::CONST` 替换为整数字面量。
     *
     * 采用 token 级替换：注释/字符串在 tokenizer 中是完整 token，内部出现的
     * `Phar::PHAR` 不会被误改；命名空间前缀形式（如 `A\Phar::X`）同样不会匹配，
     * 避免破坏不属于全局 Phar 类的代码。
     */
    public function sanitizePhpSource(string $source): string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        /** @var array<int, string> $replacements 常量 token 下标 => 替换字面量 */
        $replacements = [];
        /** @var array<int, true> $drop 需要整体丢弃的 token 下标（Phar / ::） */
        $drop = [];

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'Phar') {
                continue;
            }
            // 仅处理“全局 Phar 类”访问：前面若紧跟 T_STRING/T_NAME_*/\，说明它是
            // 命名空间限定名的一部分（如 A\Phar），不能当作 PHP 内置 Phar 处理。
            if ($this->hasNamePrefix($tokens, $index)) {
                continue;
            }
            $doubleColon = $tokens[$index + 1] ?? null;
            $constantToken = $tokens[$index + 2] ?? null;
            $constantName = is_array($constantToken) ? $constantToken[1] : null;
            $doubleColonText = is_array($doubleColon) ? $doubleColon[1] : $doubleColon;
            if (
                $doubleColonText !== '::'
                || $constantName === null
                || !is_array($constantToken)
                || $constantToken[0] !== T_STRING
            ) {
                continue;
            }
            $literal = self::PHAR_CONSTANTS[$constantName] ?? null;
            if ($literal === null) {
                continue;
            }
            $drop[$index] = true;
            $drop[$index + 1] = true;
            $replacements[$index + 2] = (string) $literal;
            $index += 2;
        }

        if ($replacements === [] && $drop === []) {
            return $source;
        }

        $output = '';
        for ($index = 0; $index < $count; $index++) {
            if (isset($drop[$index])) {
                continue;
            }
            if (isset($replacements[$index])) {
                $output .= $replacements[$index];
                continue;
            }
            $token = $tokens[$index];
            $output .= is_array($token) ? $token[1] : $token;
        }
        return $output;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function hasNamePrefix(array $tokens, int $index): bool
    {
        $previous = $index > 0 ? $tokens[$index - 1] : null;
        if ($previous === null) {
            return false;
        }
        if (!is_array($previous)) {
            // 单字符 token：仅 '\'（T_NS_SEPARATOR）需要再往前看一级
            return $previous === '\\' && $this->isNameToken($tokens, $index - 2);
        }
        return in_array($previous[0], [
            T_STRING,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_QUALIFIED,
            T_NAME_RELATIVE,
        ], true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isNameToken(array $tokens, int $index): bool
    {
        if ($index < 0) {
            return false;
        }
        $token = $tokens[$index];
        if (!is_array($token)) {
            return $token === '\\';
        }
        return in_array($token[0], [
            T_STRING,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_QUALIFIED,
            T_NAME_RELATIVE,
            T_NS_SEPARATOR,
        ], true);
    }
}
