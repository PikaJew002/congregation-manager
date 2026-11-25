<?php

namespace App\Mcp;

use App\Support\Modules\ModuleRegistry;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * One MCP server for a congregation. Each module contributes its own tools
 * and instructions (ModuleDefinition::mcpTools and mcpInstructions); tools for
 * modules the connection cannot use are not listed.
 */
#[Name('Congregation Manager')]
#[Version('2.0.0')]
class CongregationServer extends Server
{
    protected array $tools = [];

    protected array $resources = [];

    protected array $prompts = [];

    /** Every tool on one page: some clients do not follow tools/list cursors. */
    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        $sections = ['Tools for one congregation, chosen when this connection was approved. You only see tools for features this congregation has turned on for you.'];

        foreach (app(CongregationContext::class)->modules() as $module) {
            array_push($this->tools, ...$module->mcpTools());

            if ($module->mcpInstructions() !== '') {
                $sections[] = $module->name().': '.$module->mcpInstructions();
            }
        }

        $this->instructions = implode("\n\n", $sections);
    }

    /** For tests and tooling: every module's tools, before filtering. */
    public static function registeredTools(): array
    {
        return collect(app(ModuleRegistry::class)->all())->flatMap->mcpTools()->values()->all();
    }
}
