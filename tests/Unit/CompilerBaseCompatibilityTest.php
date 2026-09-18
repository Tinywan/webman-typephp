<?php

declare(strict_types=1);

it('keeps the CompilerBase override compatible with the v0.8 native build platform contract', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $dockerfile = file_get_contents($repositoryRoot . '/docker/Dockerfile');
    $compilerBase = file_get_contents($repositoryRoot . '/docker/CompilerBase.php');
    $translator = file_get_contents($repositoryRoot . '/docker/Translator.php');
    $assignOpTrait = file_get_contents($repositoryRoot . '/docker/AssignOpTrait.php');
    $funcCallOptimizer = file_get_contents($repositoryRoot . '/docker/FuncCallOptimizer.php');
    $methodCallPatch = file_get_contents($repositoryRoot . '/docker/patch_method_call_trait.php');
    $nativeTypePatch = file_get_contents($repositoryRoot . '/docker/patch_native_type_compatibility_trait.php');
    $stubGenerator = file_get_contents($repositoryRoot . '/docker/gen_stub.php');
    if (
        $dockerfile === false
        || $compilerBase === false
        || $translator === false
        || $assignOpTrait === false
        || $funcCallOptimizer === false
        || $methodCallPatch === false
        || $nativeTypePatch === false
        || $stubGenerator === false
    ) {
        throw new RuntimeException('Unable to load CompilerBase Docker override files.');
    }

    expect($dockerfile)
        ->toContain('COPY CompilerBase.php /opt/typephp/vendor/swoole/typephp/src/CompilerBase.php')
        ->toContain('RUN php /tmp/patch_native_type_compatibility_trait.php')
        ->toContain('RUN php /tmp/patch_method_call_trait.php')
        ->toContain(
            'COPY FuncCallOptimizer.php /opt/typephp/vendor/swoole/typephp/src/Optimizer/FuncCallOptimizer.php',
        );

    foreach ([
        'use TypePhp\\Platform\\Ios;',
        'use TypePhp\\Platform\\Android;',
        'public function isIosTarget(): bool',
        'return $this->getPlatform() instanceof Ios;',
        'public function isAndroidTarget(): bool',
        'return $this->getPlatform() instanceof Android;',
        'if ($this->isIosTarget()) {',
        'return $this->getIosSdkDir();',
        'if ($this->isAndroidTarget()) {',
        'return $this->getAndroidSdkDir();',
        'protected function getRawVarType(string $name): string',
        'return Type::getReferencedType($this->getRawVarType($name));',
        'protected bool $varIntTypes = false;',
        'protected function getCalledCeExpr(): string',
        'protected function getCalledClassExpr(): string',
        '$this->context->needsCalledClass = true;',
        'zend_class_entry *const _typephp_called_ce = typephp_get_called_ce(this_);',
        'php::Str const _typephp_called_class = typephp_get_called_class(_typephp_called_ce);',
        'protected function parseThrow(mixed $expr): string',
        '[Type::VAR, Type::REF, Type::OBJECT]',
        "return 'php::throwValue(' . \$ex . ')';",
        "\$lateStaticCall = \$expr instanceof Expr\\StaticCall",
        "if (\$lateStaticCall && !\$this->isCurrentClassFinal()) {",
        "\$magicMethod = \$expr instanceof Expr\\StaticCall ? '__callStatic' : '__call';",
        'if ($classDef->hasMethod($magicMethod)) {',
        'return false;',
        "if (!\$nativeClass && strtolower(\$method) === 'toarray') {",
        "zval *' . \$info['name'] . ' = nullptr;",
        "typephp_get_static_property_cached(' . \$info['name']",
    ] as $required) {
        expect($compilerBase)->toContain($required);
    }

    expect($compilerBase)->not->toContain('$nativeTypes');
    expect($compilerBase)->not->toContain("\$info['classPtr']");
    expect($compilerBase)->not->toContain('$method . \'__call\'');
    expect($compilerBase)
        ->not
        ->toContain('Symbol::getCalledCe()')
        ->toContain('$cePtr = $this->getCalledCeExpr();')
        ->toContain('return $this->getCalledCeExpr();');
    expect($stubGenerator)
        ->toContain('function &refval(mixed &$value): mixed')
        ->toContain('return $value;')
        ->toContain('refval($declaredStrings)');
    expect($translator)
        ->toContain('protected function genArgumentDeclaration(ArgInfo $argInfo): string')
        ->toContain("return Type::REF . ' ' . \$argInfo->name;")
        ->toContain('$cppType = $argInfo->byRef')
        ->toContain('$argumentType = $argInfo->byRef')
        ->toContain("str_starts_with(basename(\$sourceFile), 'extension-')")
        ->toContain("return \$options->with('optimize', 0);")
        ->toContain('Compiling generated extension unit serially to bound peak memory')
        ->toContain('array_merge($serialObjects, $this->compileWithPcntl($sourceFiles, $job))')
        ->toContain('private function markClosureReferenceVariables(array $stmts): void')
        ->toContain('findInstanceOf($stmts, Node\Expr\Closure::class)')
        ->toContain('$this->context->localVars[$name] = Type::REF;')
        ->toMatch('/markClosureReferenceVariables\\(\\$v->stmts\\);\\s*}\\s*'
        . 'if \\(\\$this->functionDef->generator\\)/');
    expect($funcCallOptimizer)
        ->toContain('$cVar = $this->escapeVarName($var);')
        ->toContain('if (!$this->hasVar($cVar) && $var !== \'this\')')
        ->toContain('php::Var(\' . $cVar . \')');
    expect($assignOpTrait)
        ->toContain('$value = new Expr\ArrayDimFetch(')
        ->toContain('$this->parseAssignFinally($item->value, $value)')
        ->not->toContain('{$tmpVar}.item({$key})');
    expect($methodCallPatch)
        ->toContain('$useDeclaredToArray')
        ->toContain("\$this->objectTypeDeclaresMethod(\$receiverClass, '__call')")
        ->toContain('&& !$useObjectToArray')
        ->toContain("substr_count(\$source, \$before) !== 1");
    expect($nativeTypePatch)
        ->toContain('strcasecmp($class, $expected) === 0')
        ->toContain('$typedDirect')
        ->toContain('$typedProxy')
        ->toContain('Unexpected typed reference ABI boundary')
        ->toContain('substr_count($source, $before) !== 1');

    expect($compilerBase)->toMatch(
        '/public function getPhpDir\(\): string\s*\{\s*if \(\$this->isIosTarget\(\)\) \{.*?'
        . 'return \$this->getIosSdkDir\(\);\s*}\s*if \(\$this->isAndroidTarget\(\)\) \{.*?'
        . 'return \$this->getAndroidSdkDir\(\);\s*}\s*try/s',
    );
});
