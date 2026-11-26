<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ProvidesDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Team $current_team, ModuleRegistry $registry, ModuleAccess $access): Response
    {
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'cards' => $this->cards($request, $current_team, $registry, $access),
        ]);
    }

    /**
     * One card for each module this person can open in the congregation.
     *
     * @return list<array<string, mixed>>
     */
    private function cards(Request $request, Team $team, ModuleRegistry $registry, ModuleAccess $access): array
    {
        $cards = [];

        foreach ($registry->all() as $module) {
            if (! $module instanceof ProvidesDashboard || ! $access->canUse($request->user(), $team, $module->key())) {
                continue;
            }

            $cards[] = [
                'key' => $module->key(),
                'name' => $module->name(),
                'icon' => $module->icon(),
                'href' => route($module->dashboardRoute(), $team->slug),
                ...$module->dashboard($team),
            ];
        }

        return $cards;
    }
}
