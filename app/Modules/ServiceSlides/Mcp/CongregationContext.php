<?php

namespace App\Modules\ServiceSlides\Mcp;

use App\Models\Team;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class CongregationContext
{
    public function resolve(Request $request): Team|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Sign in before using service slides.');
        }

        if (request()->attributes->get('service_slides.oauth') === true) {
            $teamId = request()->attributes->get('service_slides.oauth_team_id');
            $team = is_numeric($teamId)
                ? $user->teams()->where('teams.id', (int) $teamId)->first()
                : null;
        } else {
            $token = $user->currentAccessToken();

            if (! $token->can('service-slides') && ! $token->can('*')) {
                return Response::error('This token cannot use service slides.');
            }

            $teamId = null;

            foreach ($token->abilities as $ability) {
                if (is_string($ability) && str_starts_with($ability, 'congregation:')) {
                    $teamId = (int) substr($ability, strlen('congregation:'));
                }
            }

            $team = $teamId
                ? $user->teams()->where('teams.id', $teamId)->first()
                : $user->currentTeam;
        }

        if (! $team instanceof Team) {
            return Response::error('This connection is not tied to a congregation you belong to.');
        }

        if (! app(ModuleAccess::class)->canUse($user, $team, 'service-slides')) {
            return Response::error('Service slides are not available for this congregation.');
        }

        return $team;
    }
}
