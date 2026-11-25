<?php

namespace App\Mcp;

/**
 * For tools that belong to one module. The tool is only listed for
 * connections that can use that module; each tool still checks again with
 * CongregationContext::resolve() when it runs.
 */
trait ModuleTool
{
    abstract protected function module(): string;

    public function shouldRegister(CongregationContext $context): bool
    {
        return $context->available($this->module());
    }
}
