<?php

declare(strict_types=1);

$path = '/opt/typephp/vendor/swoole/typephp/src/Parser/MethodCallTrait.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException("Unable to read {$path}");
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

if (substr_count($source, $before) !== 1) {
    throw new RuntimeException('Unexpected MethodCallTrait keyword-dispatch source');
}

$patched = str_replace($before, $after, $source);

$lateStaticBefore = <<<'PHP'
    ): ?string {
        if ($expr->args !== [] || !$this->classDef || !$this->methodDef) {
PHP;
$lateStaticAfter = <<<'PHP'
    ): ?string {
        // SaiAdmin overlay: preserve dynamic late-static dispatch.
        return null;

        if ($expr->args !== [] || !$this->classDef || !$this->methodDef) {
PHP;
if (str_contains($patched, 'private function parseExactLateStaticCall(')) {
    if (substr_count($patched, $lateStaticBefore) !== 1) {
        throw new RuntimeException('Unexpected MethodCallTrait late-static optimization source');
    }
    $patched = str_replace($lateStaticBefore, $lateStaticAfter, $patched);
}

if (file_put_contents($path, $patched) === false) {
    throw new RuntimeException("Unable to write {$path}");
}
