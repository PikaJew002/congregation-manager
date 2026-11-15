<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use Illuminate\Support\Facades\DB;

class AcceptTeamInvitation
{
    public function __construct(private ModuleAccess $access) {}

    public function handle(User $user, TeamInvitation $invitation): void
    {
        DB::transaction(function () use ($user, $invitation) {
            $team = $invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            foreach ($invitation->modules ?? [] as $moduleKey) {
                $this->access->grant($team, $user, $moduleKey);
            }

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });
    }
}
