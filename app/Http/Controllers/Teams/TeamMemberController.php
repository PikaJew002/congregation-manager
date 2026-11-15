<?php

namespace App\Http\Controllers\Teams;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Update the specified team member's role.
     */
    public function update(UpdateTeamMemberRequest $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $newRole = TeamRole::from($request->validated('role'));
        $membership = $team->memberships()->where('user_id', $user->id)->firstOrFail();

        abort_if($membership->role === TeamRole::Owner, 403, __('The congregation owner cannot be changed.'));

        if ($membership->role->isAtLeast(TeamRole::SuperAdmin) && ! $newRole->isAtLeast(TeamRole::SuperAdmin)) {
            abort_if($team->privilegedMemberCount() <= 1, 403, __('A congregation must keep at least one super admin.'));
        }

        $membership->update(['role' => $newRole]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        abort_if($team->owner()?->is($user), 403, __('The congregation owner cannot be removed.'));

        $membership = $team->memberships()->where('user_id', $user->id)->firstOrFail();

        if ($membership->role->isAtLeast(TeamRole::SuperAdmin)) {
            abort_if($team->privilegedMemberCount() <= 1, 403, __('A congregation must keep at least one super admin.'));
        }

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($team)) {
            $fallback = $user->personalTeam() ?? $user->fallbackTeam($team);

            if ($fallback) {
                $user->switchTeam($fallback);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Grant or revoke a module for a member.
     */
    public function updateModule(Request $request, Team $team, User $user, ModuleAccess $access): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        abort_unless($user->belongsToTeam($team), 404);

        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(app(ModuleRegistry::class)->keys())],
            'enabled' => ['required', 'boolean'],
        ]);

        if ($data['enabled']) {
            $access->grant($team, $user, $data['module']);
        } else {
            $access->revoke($team, $user, $data['module']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Module access updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
