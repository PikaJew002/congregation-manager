<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Support\Modules\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function __construct(private ModuleAccess $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if (! $team instanceof Team) {
            $team = $request->user()?->currentTeam;
        }

        abort_unless($team instanceof Team, 404);

        if (! $this->access->isEnabled($team, $key)) {
            abort(404);
        }

        $user = $request->user();

        abort_unless($user && $this->access->canUse($user, $team, $key), 403);

        return $next($request);
    }
}
