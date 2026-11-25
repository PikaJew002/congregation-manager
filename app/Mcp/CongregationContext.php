<?php

namespace App\Mcp;

use App\Models\Team;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Works out which congregation an MCP connection acts for, and whether the
 * signed-in user may use a given module there.
 *
 * Connections are OAuth only: the congregation is picked on the approval
 * screen and saved on the token (AuthenticateMcp reads it).
 */
class CongregationContext
{
    public function __construct(
        private ModuleAccess $access,
        private ModuleRegistry $registry,
    ) {}

    public function resolve(Request $request, string $module): Team|Response
    {
        $user = $request->user();
        $name = $this->registry->get($module)?->name() ?? $module;

        if (! $user instanceof User) {
            return Response::error('Sign in before using Congregation Manager tools.');
        }

        $team = $this->team($user);

        if (! $team instanceof Team) {
            return Response::error('This connection is not tied to a congregation you belong to.');
        }

        if (! $this->access->canUse($user, $team, $module)) {
            return Response::error("{$name} is not available to you for this congregation.");
        }

        return $team;
    }

    /** Whether the current connection can use the module; hides tools it cannot. */
    public function available(string $module): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $team = $this->team($user);

        return $team instanceof Team && $this->access->canUse($user, $team, $module);
    }

    /** The congregation the current connection acts for, if any. */
    public function current(): ?Team
    {
        $user = auth()->user();

        return $user instanceof User ? $this->team($user) : null;
    }

    public function team(User $user): ?Team
    {
        if (request()->attributes->get('mcp.oauth') !== true) {
            return null;
        }

        $teamId = request()->attributes->get('mcp.oauth_team_id');

        return is_numeric($teamId) ? $user->teams()->where('teams.id', (int) $teamId)->first() : null;
    }

    /**
     * Modules that offer MCP tools.
     *
     * @return list<ModuleDefinition>
     */
    public function modules(): array
    {
        return array_values(array_filter($this->registry->all(), fn (ModuleDefinition $module) => $module->mcpTools() !== []));
    }

    /** Whether the user can use any MCP module in the congregation. */
    public function canConnect(User $user, Team $team): bool
    {
        foreach ($this->modules() as $module) {
            if ($this->access->canUse($user, $team, $module->key())) {
                return true;
            }
        }

        return false;
    }
}
