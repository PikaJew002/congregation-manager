<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\EmailSending;
use App\Models\Team;
use App\Modules\Email\Actions\PaceDeliveries;
use App\Modules\Email\Actions\RunState;
use App\Modules\Email\Models\EmailRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sent: each run is one send of an email, with its finished text and one
 * delivery per person.
 */
class RunController extends Controller
{
    public function index(Team $current_team): Response
    {
        return Inertia::render('email/Runs', [
            'runs' => EmailRun::query()->forTeam($current_team)->withCount([
                'deliveries',
                'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])->with('email:id,name')->latest('scheduled_for')->limit(50)->get(),
        ]);
    }

    public function show(Team $current_team, EmailRun $run): Response
    {
        abort_unless($run->team_id === $current_team->id, 404);

        return Inertia::render('email/Run', [
            'run' => $run->load('email:id,name'),
            'deliveries' => $run->deliveries()->with('recipient')->orderBy('scheduled_at')->get(),
        ]);
    }

    public function cancel(Team $current_team, EmailRun $run): RedirectResponse
    {
        abort_unless($run->team_id === $current_team->id, 404);
        $run->update(['status' => 'cancelled']);
        $run->deliveries()->where('status', 'pending')->update(['status' => 'cancelled']);

        return back();
    }

    public function retry(Team $current_team, EmailRun $run, PaceDeliveries $pace): RedirectResponse
    {
        abort_unless($run->team_id === $current_team->id, 404);
        // A paused run is picked up with Resume, which also checks the account.
        abort_if(in_array($run->status, ['cancelled', 'paused'], true), 422);

        // Only people still on the list; a bounced or suppressed address would
        // fail again.
        $failed = $run->deliveries()->retryable()->orderBy('scheduled_at')->get();

        if ($failed->isEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is nobody to retry. Everyone whose email failed has been taken off the list.')]);

            return back();
        }

        // Retries go out at the same pace as the first send, starting now.
        $times = $pace->times(CarbonImmutable::now()->utc(), $failed->count());

        foreach ($failed->values() as $index => $delivery) {
            $delivery->update(['status' => 'pending', 'error' => null, 'queued_at' => null, 'scheduled_at' => $times[$index]]);
        }

        $run->update(['status' => 'sending']);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Retrying :count email.|Retrying :count emails.', $failed->count())]);

        return back();
    }

    public function resume(Team $current_team, EmailRun $run, RunState $state): RedirectResponse
    {
        abort_unless($run->team_id === $current_team->id, 404);
        abort_unless($run->status === 'paused', 422);

        $problem = EmailSending::problem($current_team);

        if ($problem !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Email sending still is not working. :problem', ['problem' => $problem])]);

            return back();
        }

        $state->resume($run);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sending resumed.')]);

        return back();
    }
}
