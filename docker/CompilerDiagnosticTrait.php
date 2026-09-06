<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Diagnostics;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;

trait CompilerDiagnosticTrait
{
    /**
     * Report a compiler fatal error.
     */
    public function error(string $msg): never
    {
        $this->getDiagnosticReporter()->fatal($msg);
    }

    public function fatalError(Node $node, string $msg): never
    {
        $this->error("{$msg} in {$this->file}:{$node->getStartLine()}");
    }

    protected function warning(Node $node, string $msg): void
    {
        $this->getDiagnosticReporter()->warning($node, $this->file, $msg);
    }

    protected function fatalCompileTimeAttribute(
        Node $target,
        string $attribute,
        string $message,
        ?Node $source = null,
        ?string $conflictAttribute = null,
        ?Node $conflictSource = null,
    ): never {
        $this->error(CompileTimeAttributeDiagnostic::format(
            $message,
            $attribute,
            $target,
            $this->file,
            $source,
            $conflictAttribute,
            $conflictSource,
        ));
    }

    protected function errorUndefinedVariable(Variable $node): never
    {
        $this->fatalError($node, "The variable `\${$node->name}` is undefined");
    }

    protected function warningUndefinedBehavior(Node $expr): void
    {
        $this->warning($expr, 'Use this expression carefully, which may be inconsistent with the dynamic execution behavior');
    }

    protected function dump(Node $node): void
    {
        if ($this->debugLine == $node->getStartLine()) {
            var_dump($node);
        }
    }

    protected function foundStrayCode(Node $node): void
    {
        // Top-level initialization statements (e.g. Fiber::initContext(), Context::initDriver(), Coroutine::init())
        // are allowed and skipped during compilation; they are executed at runtime in the entrypoint.
    }
}
