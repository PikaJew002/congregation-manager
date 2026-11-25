<?php

namespace App\Support\Modules;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Mcp\Server\Tool;

interface ModuleDefinition
{
    public function key(): string;

    public function name(): string;

    /**
     * Lucide icon name used by the sidebar.
     */
    public function icon(): string;

    /**
     * Navigation items for the current congregation.
     *
     * @return list<array{title: string, route: string, params?: array<string, mixed>}>
     */
    public function navItems(): array;

    public function schedule(Schedule $schedule): void;

    /**
     * Tools this module adds to the congregation MCP server.
     *
     * @return list<class-string<Tool>>
     */
    public function mcpTools(): array;

    /**
     * How to use this module's tools, added to the server instructions.
     */
    public function mcpInstructions(): string;
}
