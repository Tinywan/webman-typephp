<?php

declare(strict_types=1);

namespace Tinywan\Typephp\Compiler;

/**
 * Builds a project-local TypePHP bootstrap with bounded compiler compatibility
 * fixes. The installed compiler is never modified.
 */
final class NativeCompilerOverlay
{
    /**
     * @param array<string, string> $toolchain
     */
    public function prepare(array $toolchain, string $directory): string
    {
        $typephpHome = $toolchain['typephp_home'] ?? '';
        if ($typephpHome === '' || !is_file($typephpHome . '/bin/bootstrap.php')) {
            throw new \RuntimeException(
                'SaiAdmin native compilation requires a source-based tpc.php installation so bounded compiler '
                . 'compatibility overlays can be applied without modifying the installed compiler.',
            );
        }
        $autoload = $this->resolveAutoload($toolchain, $typephpHome);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create native compiler overlay: {$directory}");
        }

        $nativeTrait = $this->patchNativeClassSupportTrait($this->read($typephpHome
        . '/src/NativeClass/NativeClassSupportTrait.php'));
        $methodTrait = $this->patchMethodCallTrait($this->read($typephpHome . '/src/Parser/MethodCallTrait.php'));
        $typeTrait = $this->patchNativeTypeCompatibilityTrait($this->read($typephpHome
        . '/src/TypeSystem/NativeTypeCompatibilityTrait.php'));
        $preprocessor = $this->patchPreprocessor($this->read($typephpHome . '/src/Preprocessor.php'));
        $nativeTarget = $directory . '/NativeClassSupportTrait.php';
        $methodTarget = $directory . '/MethodCallTrait.php';
        $typeTarget = $directory . '/NativeTypeCompatibilityTrait.php';
        $preprocessorTarget = $directory . '/Preprocessor.php';
        $runner = $directory . '/tpc.php';
        $this->write($nativeTarget, $nativeTrait);
        $this->write($methodTarget, $methodTrait);
        $this->write($typeTarget, $typeTrait);
        $this->write($preprocessorTarget, $preprocessor);

        $bootstrap = var_export($typephpHome . '/bin/bootstrap.php', true);
        $autoload = var_export($autoload, true);
        $root = var_export($typephpHome, true);
        $runnerSource = <<<PHP
            <?php

            declare(strict_types=1);

            \$GLOBALS['_composer_autoload_path'] = {$autoload};
            require {$bootstrap};
            require __DIR__ . '/NativeClassSupportTrait.php';
            require __DIR__ . '/MethodCallTrait.php';
            require __DIR__ . '/NativeTypeCompatibilityTrait.php';
            require __DIR__ . '/Preprocessor.php';
            require {$root} . '/src/polyfills.php';
            require {$root} . '/src/gen_stub.php';
            require {$root} . '/src/compiler.php';

            const TYPEPHP_PHP_SCRIPT_ENTRY = true;
            main(\$argc, \$argv);
            PHP;
        $this->write($runner, $runnerSource . "\n");
        return $runner;
    }

    /**
     * @param array<string, string> $toolchain
     */
    private function resolveAutoload(array $toolchain, string $typephpHome): string
    {
        $tpc = $toolchain['tpc'] ?? '';
        foreach ([dirname(dirname($tpc)) . '/autoload.php', $typephpHome . '/vendor/autoload.php'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Unable to locate the Composer autoloader used by TypePHP.');
    }

    private function patchNativeClassSupportTrait(string $source): string
    {
        $before = <<<'PHP'
            $kind = $nativeClass ? 'Native conversion method' : 'Conversion method';
                    if ($function->argInfoList !== []) {
                        $this->fatalError($node, "{$kind} `{$class}::{$method}()` must not accept arguments");
                    }
            PHP;
        $after = <<<'PHP'
            $kind = $nativeClass ? 'Native conversion method' : 'Conversion method';
                    if ($function->argInfoList !== []) {
                        if (!$nativeClass && strtolower($method) === 'toarray') {
                            return;
                        }
                        $this->fatalError($node, "{$kind} `{$class}::{$method}()` must not accept arguments");
                    }
            PHP;
        if (str_contains($source, $after)) {
            return $source;
        }
        if (substr_count($source, $before) !== 1) {
            throw new \RuntimeException('Unsupported TypePHP NativeClassSupportTrait structure.');
        }
        return str_replace($before, $after, $source);
    }

    private function patchMethodCallTrait(string $source): string
    {
        $nativeLookupBefore = <<<'PHP'
                    $nativeFunc = $this->getNativeMethod($expr, $class, $method);
                    // A Native class exists but the method was not found; this may be a dynamic call
            PHP;
        $nativeLookupAfter = <<<'PHP'
                    // Do not descend into an internal parent when a compiled class
                    // provides __call(): PHP dispatches the missing method to that magic
                    // method. This is used by Carbon's addDays-style unit API.
                    if ($this->hasClass($class)) {
                        $lookupClass = $class;
                        $hasMagicCall = false;
                        while ($this->hasClass($lookupClass)) {
                            $lookupDef = $this->getClass($lookupClass);
                            if ($lookupDef->hasMethod($method)) {
                                $hasMagicCall = false;
                                break;
                            }
                            $hasMagicCall = $hasMagicCall || $lookupDef->hasMethod('__call');
                            if ($lookupDef->extends === '' || !$this->hasClass($lookupDef->extends)) {
                                break;
                            }
                            $lookupClass = $lookupDef->extends;
                        }
                        if ($hasMagicCall) {
                            throw new DynamicCall();
                        }
                    }

                    $nativeFunc = $this->getNativeMethod($expr, $class, $method);
                    // A Native class exists but the method was not found; this may be a dynamic call
            PHP;
        if (!str_contains($source, "Carbon's addDays-style unit API")) {
            if (substr_count($source, $nativeLookupBefore) !== 1) {
                throw new \RuntimeException('Unsupported TypePHP native method lookup structure.');
            }
            $source = str_replace($nativeLookupBefore, $nativeLookupAfter, $source);
        }

        $before = <<<'PHP'
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
            PHP;
        $after = <<<'PHP'
            $receiverClass = $class;
                            if ($receiverClass === '' && !$this->isVarExpr($expr->var)) {
                                $receiverClass = $this->detectClassOfExpr($expr->var);
                            }
                            // A declared toArray(...) API is an ordinary PHP method, not
                            // the argument-free TypePHP conversion keyword.
                            $useDeclaredToArray = $methodName === 'toArray'
                                && $receiverClass !== ''
                                && $this->objectTypeDeclaresMethod($receiverClass, $methodName);
                            $useDynamicToArray = $methodName === 'toArray'
                                && ($receiverClass === ''
                                    || $receiverType === Type::VAR
                                    || $this->objectTypeDeclaresMethod($receiverClass, '__call'));
                            $useObjectToArray = $useDeclaredToArray || $useDynamicToArray;
                            if (!isset(self::KEYWORD_METHOD_WITH_ARGUMENTS[$methodName])
                                && $expr->args !== []
                                && !$useObjectToArray
                            ) {
                                $this->fatalError($expr, "The {$methodName} method does not accept parameters");
                            }
                            if ($methodName === 'toObject') {
                                return $this->genToObjectCall($expr, $object);
                            }
                            if ($methodName === 'toRef') {
                                return $this->genToRefCall($expr);
                            }
                            if (!$useObjectToArray) {
                                return $this->genToConvertCall($object, $methodName, $receiverType);
                            }
            PHP;
        if (!str_contains($source, '$useDynamicToArray =')) {
            if (substr_count($source, $before) !== 1) {
                throw new \RuntimeException('Unsupported TypePHP MethodCallTrait structure.');
            }
            $source = str_replace($before, $after, $source);
        }

        $lateStaticMarker = '// SaiAdmin overlay: preserve dynamic late-static dispatch.';
        if (
            str_contains($source, 'private function parseExactLateStaticCall(')
            && !str_contains($source, $lateStaticMarker)
        ) {
            $needle = <<<'PHP'
                    ): ?string {
                        if ($expr->args !== [] || !$this->classDef || !$this->methodDef) {
                PHP;
            $replacement = <<<'PHP'
                    ): ?string {
                        // SaiAdmin overlay: preserve dynamic late-static dispatch.
                        return null;

                        if ($expr->args !== [] || !$this->classDef || !$this->methodDef) {
                PHP;
            if (substr_count($source, $needle) !== 1) {
                throw new \RuntimeException('Unsupported TypePHP late-static optimization structure.');
            }
            $source = str_replace($needle, $replacement, $source);
        }
        return $source;
    }

    private function patchNativeTypeCompatibilityTrait(string $source): string
    {
        $before = 'return $internal && is_subclass_of($class, $expected);';
        $after = 'return $internal && (strcasecmp($class, $expected) === 0 || is_subclass_of($class, $expected));';
        if (str_contains($source, $after)) {
            return $source;
        }
        if (substr_count($source, $before) !== 1) {
            throw new \RuntimeException('Unsupported TypePHP native inheritance structure.');
        }
        return str_replace($before, $after, $source);
    }

    private function patchPreprocessor(string $source): string
    {
        $before = <<<'PHP'
                        if (!$this->isInternalFunction($funcName)) {
                            $this->symbolCallInFile[$this->file][] = $this->getFunctionDependencySymbol($funcName);
                        }
            PHP;
        $after = <<<'PHP'
                        if (!$this->isInternalFunction($funcName)) {
                            $this->symbolCallInFile[$this->file][] = $this->getFunctionDependencySymbol($funcName);
                        }
                        // PHP resolves an unqualified function in the current namespace
                        // before falling back to the global function. The converter uses
                        // that fallback when it finds a compiled global helper, so its
                        // declaration header must be part of the same dependency set.
                        $sourceName = $node->name->toString();
                        $unqualified = !str_contains($sourceName, '\\');
                        $imported = $unqualified && isset($this->useFunctions[strtolower($sourceName)]);
                        if ($this->namespace !== ''
                            && !$node->name instanceof Node\Name\FullyQualified
                            && $unqualified
                            && !$imported
                        ) {
                            $this->symbolCallInFile[$this->file][] = $this->getFunctionDependencySymbol($sourceName);
                        }
            PHP;
        if (str_contains($source, 'The converter uses' . PHP_EOL . '            // that fallback')) {
            return $source;
        }
        if (substr_count($source, $before) !== 1) {
            throw new \RuntimeException('Unsupported TypePHP Preprocessor function dependency structure.');
        }
        return str_replace($before, $after, $source);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Unable to read TypePHP compiler source: {$path}");
        }
        return $content;
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Unable to write native compiler overlay: {$path}");
        }
    }
}
