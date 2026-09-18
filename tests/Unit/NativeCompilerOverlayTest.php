<?php

declare(strict_types=1);

use Tinywan\Typephp\Compiler\NativeCompilerOverlay;

it('creates a fail-closed local compiler overlay without modifying TypePHP', function (): void {
    $directory = sys_get_temp_dir() . '/typephp-overlay-' . bin2hex(random_bytes(4));
    $typephp = $directory . '/typephp';
    $overlay = $directory . '/overlay';
    mkdir($typephp . '/bin', 0777, true);
    mkdir($typephp . '/src/NativeClass', 0777, true);
    mkdir($typephp . '/src/Parser', 0777, true);
    mkdir($typephp . '/src/TypeSystem', 0777, true);
    file_put_contents($directory . '/autoload.php', "<?php\n");
    file_put_contents($typephp . '/bin/bootstrap.php', "<?php\n");
    file_put_contents($typephp . '/src/compiler.php', "<?php\n");

    $nativeBlock = <<<'PHP'
                $kind = $nativeClass ? 'Native conversion method' : 'Conversion method';
                if ($function->argInfoList !== []) {
                    $this->fatalError($node, "{$kind} `{$class}::{$method}()` must not accept arguments");
                }
        PHP;
    $methodBlock = <<<'PHP'
                $nativeFunc = $this->getNativeMethod($expr, $class, $method);
                // A Native class exists but the method was not found; this may be a dynamic call

                        if (!isset(self::KEYWORD_METHOD_WITH_ARGUMENTS[$methodName]) && $expr->args !== []) {
                            $this->fatalError($expr, "The {$methodName} method does not accept parameters");
                        }
                        if ($methodName === 'toObject') {
                            return $this->genToObjectCall($expr, $object);
                        }
                        if ($methodName === 'toRef') {
                            return $this->genToRefCall($expr);
                        }
                        $receiverClass = $class;
                        if ($receiverClass === '' && !$this->isVarExpr($expr->var)) {
                            $receiverClass = $this->detectClassOfExpr($expr->var);
                        }
                        // A declared conversion method is called directly. Otherwise
                        // php::toArray() applies the PHP-compatible object-property
                        // fallback (and invokes a real toArray() method when present).
                        $useDeclaredToArray = $methodName === 'toArray'
                            && $receiverClass !== ''
                            && $this->objectTypeDeclaresMethod($receiverClass, $methodName);
                        if (!$useDeclaredToArray) {
                            return $this->genToConvertCall($object, $methodName, $receiverType);
                        }

            private function parseExactLateStaticCall(
                object $expr,
                string $method,
                string $methodPtr,
            ): ?string {
                if ($expr->args !== [] || !$this->classDef || !$this->methodDef) {
                    return null;
                }
            }
        PHP;
    file_put_contents($typephp . '/src/NativeClass/NativeClassSupportTrait.php', "<?php\n{$nativeBlock}\n");
    file_put_contents($typephp . '/src/Parser/MethodCallTrait.php', "<?php\n{$methodBlock}\n");
    file_put_contents(
        $typephp . '/src/TypeSystem/NativeTypeCompatibilityTrait.php',
        "<?php\nreturn \$internal && is_subclass_of(\$class, \$expected);\n",
    );
    $preprocessorBlock = <<<'PHP'
                    if (!$this->isInternalFunction($funcName)) {
                        $this->symbolCallInFile[$this->file][] = $this->getFunctionDependencySymbol($funcName);
                    }
        PHP;
    file_put_contents($typephp . '/src/Preprocessor.php', "<?php\n{$preprocessorBlock}\n");

    try {
        $runner = new NativeCompilerOverlay()->prepare([
            'typephp_home' => $typephp,
            'tpc' => $directory . '/bin/tpc.php',
        ], $overlay);

        expect($runner)->toBe($overlay . '/tpc.php');
        expect(file_get_contents($typephp . '/src/NativeClass/NativeClassSupportTrait.php'))
            ->not
            ->toContain("strtolower(\$method) === 'toarray'");
        expect(file_get_contents($overlay . '/NativeClassSupportTrait.php'))
            ->toContain("strtolower(\$method) === 'toarray'");
        expect(file_get_contents($overlay . '/MethodCallTrait.php'))
            ->toContain('$useDynamicToArray =')
            ->toContain("Carbon's addDays-style unit API")
            ->toContain('SaiAdmin overlay: preserve dynamic late-static dispatch.');
        expect(file_get_contents($overlay . '/NativeTypeCompatibilityTrait.php'))
            ->toContain('strcasecmp($class, $expected) === 0');
        expect(file_get_contents($overlay . '/Preprocessor.php'))
            ->toContain('$unqualified =')
            ->toContain('$this->getFunctionDependencySymbol($sourceName)');
        expect(file_get_contents($runner))->toContain("require __DIR__ . '/Preprocessor.php';");
    } finally {
        if (is_dir($directory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($directory);
        }
    }
});
