<?php

declare(strict_types=1);

$path = '/opt/typephp/vendor/swoole/typephp/src/TypeSystem/NativeTypeCompatibilityTrait.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read NativeTypeCompatibilityTrait.php');
}

$before = 'return $internal && is_subclass_of($class, $expected);';
$after = 'return $internal && (strcasecmp($class, $expected) === 0'
    . ' || is_subclass_of($class, $expected));';
if (substr_count($source, $before) !== 1) {
    throw new RuntimeException('Unexpected native inheritance boundary in NativeTypeCompatibilityTrait.php');
}

$typedDirect = <<<'PHP'
                    if ($actualType === $expectedType
                        && ($rawType === $expectedType || $rawType === $argInfo->type)
                    ) {
                        return $var;
                    }
PHP;
$typedProxy = <<<'PHP'
                $reference = $this->addTmpVar(Type::REF);
                $wrapper = $this->genTmpVarName();
                $this->context->beforeStmtLines[] = $reference . ' = ' . $this->convertToRef($arg->value) . ';';
                $this->context->beforeStmtLines[] = 'php::RefWrap<' . $expectedType . '> '
                    . $wrapper . '(' . $reference . ');';
                $this->context->afterStmtLines[] = $wrapper . '.commit();';
                return $wrapper . '.typed()';
PHP;
foreach ([$typedDirect, $typedProxy] as $typedRefBoundary) {
    if (substr_count($source, $typedRefBoundary) !== 1) {
        throw new RuntimeException('Unexpected typed reference ABI boundary in NativeTypeCompatibilityTrait.php');
    }
    $source = str_replace($typedRefBoundary, '', $source);
}

$patched = str_replace($before, $after, $source);
if (file_put_contents($path, $patched) === false) {
    throw new RuntimeException('Unable to patch NativeTypeCompatibilityTrait.php');
}
