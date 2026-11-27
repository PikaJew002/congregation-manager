<?php

namespace App\Mcp;

use App\Support\Modules\ModuleRegistry;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

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
        $context = app(CongregationContext::class);
        $sections = ['Tools for one congregation, chosen when this connection was approved. You only see tools for features this congregation has turned on for you.'];

        if ($team = $context->current()) {
            $now = $team->localNow();
            $sections[] = "Dates: {$team->name} is in the {$team->localTimezone()} time zone, where it is now {$now->format('l, F j, Y, g:i A')} (today is {$now->toDateString()}). Work out dates such as \"this Saturday\" or \"tomorrow\" from that, and send dates as YYYY-MM-DD for that day there. Dates and times in results are in that time zone unless they end in an offset.";
        }

        foreach ($context->modules() as $module) {
            array_push($this->tools, ...$module->mcpTools());

            if ($module->mcpInstructions() !== '') {
                $sections[] = $module->name().': '.$module->mcpInstructions();
            }
        }

        $this->instructions = implode("\n\n", $sections);
    }

    /**
     * For tests and tooling: every module's tools, before filtering.
     *
     * @return list<class-string<Tool>>
     */
    public static function registeredTools(): array
    {
        $tools = [];
        foreach (app(ModuleRegistry::class)->all() as $module) {
            array_push($tools, ...$module->mcpTools());
        }

        return $tools;
    }
}
