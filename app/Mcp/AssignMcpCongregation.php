<?php

namespace App\Mcp;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the OAuth approval screen, remembers which congregation the new
 * connection is for. The auth code copies it onto the token.
 */
class AssignMcpCongregation
{
    public function __construct(private CongregationContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $team = Team::query()->find($request->integer('team_id'));

        if (! $user instanceof User
            || ! $team instanceof Team
            || ! $user->belongsToTeam($team)
            || ! $this->context->canConnect($user, $team)) {
            return back()->withErrors([
                'team_id' => __('Choose a congregation you can use these tools for.'),
            ])->withInput();
        }

        $request->session()->put('mcp.oauth_team_id', $team->id);

        return $next($request);
    }
}
