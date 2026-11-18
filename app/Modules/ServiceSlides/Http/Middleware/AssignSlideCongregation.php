<?php

namespace App\Modules\ServiceSlides\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssignSlideCongregation
{
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
            || ! app(ModuleAccess::class)->canUse($user, $team, 'service-slides')) {
            return back()->withErrors([
                'team_id' => __('Choose a congregation you can plan slides for.'),
            ])->withInput();
        }

        $request->session()->put('service_slides.oauth_team_id', $team->id);

        return $next($request);
    }
}
