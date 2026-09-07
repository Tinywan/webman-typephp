<?php

declare(strict_types=1);

use Tinywan\Typephp\Compiler\DistConfigSanitizer;

function writePhpFile(string $directory, string $relativePath, string $content): string
{
    $file = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, $content);
    return $file;
}

it('replaces unqualified Phar class constants with equivalent integer literals', function (): void {
    $sanitizer = new DistConfigSanitizer();
    $source = <<<'PHP'
<?php
return [
    'phar_format' => Phar::PHAR, // Phar archive format: Phar::PHAR, Phar::TAR, Phar::ZIP
    'phar_compression' => Phar::NONE, // Phar::NONE, Phar::GZ, Phar::BZ2
    'signature_algorithm' => Phar::SHA256, // Phar::MD5, Phar::SHA1, Phar::SHA256
];
PHP;

    $result = $sanitizer->sanitizePhpSource($source);

    expect($result)
        ->toContain("'phar_format' => 0,")
        ->toContain("'phar_compression' => 0,")
        ->toContain("'signature_algorithm' => 3,")
        // 注释里的 Phar::* 文本保持不变
        ->toContain('// Phar archive format: Phar::PHAR, Phar::TAR, Phar::ZIP')
        ->toContain('// Phar::MD5, Phar::SHA1, Phar::SHA256')
        ->not->toContain('Phar::PHAR, //');
});

it('does not touch namespaced or non-mapped Phar references', function (): void {
    $sanitizer = new DistConfigSanitizer();
    $source = <<<'PHP'
<?php
return [
    'app' => SomeVendor\Phar::DRIVER,
    'ns' => \Phar::CUSTOM,
    'plain' => 42,
];
PHP;

    $result = $sanitizer->sanitizePhpSource($source);

    expect($result)
        ->toContain('SomeVendor\Phar::DRIVER')
        ->toContain('\\Phar::CUSTOM')
        ->toContain("'plain' => 42,");
});

it('maps the full Phar constant set', function (): void {
    $sanitizer = new DistConfigSanitizer();
    $source = "<?php return ['a' => Phar::PHAR, 'b' => Phar::TAR, 'c' => Phar::ZIP, "
        . "'d' => Phar::NONE, 'e' => Phar::GZ, 'f' => Phar::BZ2, "
        . "'g' => Phar::MD5, 'h' => Phar::SHA1, 'i' => Phar::SHA256, "
        . "'j' => Phar::SHA512, 'k' => Phar::OPENSSL];";
    $result = $sanitizer->sanitizePhpSource($source);
    expect($result)->toBe(
        "<?php return ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 0, 'e' => 4096, 'f' => 8192, "
        . "'g' => 1, 'h' => 2, 'i' => 3, 'j' => 4, 'k' => 16];",
    );
});

it('sanitizes a whole dist config directory and reports changed files', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-sanitize-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    try {
        $console = writePhpFile($directory, 'plugin/webman/console/app.php', <<<'PHP'
<?php
return [
    'enable' => true,
    'phar_format' => Phar::PHAR,
    'phar_compression' => Phar::NONE,
    'signature_algorithm' => Phar::SHA256,
];
PHP);
        $untouched = writePhpFile($directory, 'app.php', <<<'PHP'
<?php
return ['debug' => true];
PHP);
        $readme = writePhpFile($directory, 'notes.txt', 'Phar::PHAR should never be read as config');

        $sanitizer = new DistConfigSanitizer();
        $changed = $sanitizer->sanitizeDirectory($directory);

        expect($changed)->toBe(['plugin/webman/console/app.php']);
        expect(file_get_contents($console))->toContain("'phar_format' => 0,");
        expect(file_get_contents($untouched))->toContain("'debug' => true");
        expect(file_get_contents($readme))->toContain('Phar::PHAR');
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
});
