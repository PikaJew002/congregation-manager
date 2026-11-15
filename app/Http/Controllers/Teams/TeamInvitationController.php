<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\RespondToTeamInvitationRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $invitation = $team->invitations()->create([
            'email' => $request->validated('email'),
            'role' => TeamRole::from($request->validated('role')),
            'modules' => array_values($request->validated('modules') ?? []),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Resend a pending invitation.
     */
    public function resend(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id && $invitation->isPending(), 404);

        Gate::authorize('inviteMember', $team);

        $invitation->update([
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the public invitation page.
     */
    public function show(Request $request, TeamInvitation $invitation): Response|RedirectResponse
    {
        abort_unless($invitation->isPending(), 410);

        $user = $request->user();

        if ($user && strcasecmp($user->email, $invitation->email) === 0) {
            return Inertia::render('invitations/Show', [
                'invitation' => $this->invitationPayload($invitation),
                'canAccept' => true,
            ]);
        }

        $accountExists = User::query()->where('email', $invitation->email)->exists();

        return Inertia::render('invitations/Show', [
            'invitation' => $this->invitationPayload($invitation),
            'canAccept' => false,
            'accountExists' => $accountExists,
        ]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, AcceptTeamInvitation $acceptTeamInvitation): RedirectResponse
    {
        $user = $request->user();

        abort_unless(strcasecmp($user->email, $invitation->email) === 0, 403);

        $acceptTeamInvitation->handle($user, $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }

    /**
     * @return array{code: string, email: string, congregation: string, role: string}
     */
    private function invitationPayload(TeamInvitation $invitation): array
    {
        return [
            'code' => $invitation->code,
            'email' => $invitation->email,
            'congregation' => $invitation->team->name,
            'role' => $invitation->role->label(),
        ];
    }
}
