<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\Email\Models\EmailRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The congregation's one email list. Every email goes to everyone subscribed.
 */
class RecipientController extends Controller
{
    public function index(Team $current_team): Response
    {
        return Inertia::render('email/Recipients', [
            'recipients' => EmailRecipient::query()->forTeam($current_team)->orderBy('email')->get(),
        ]);
    }

    public function store(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'string'],
        ]);

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $data['entries'])) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$email, $name] = array_pad(array_map('trim', explode(',', $line, 2)), 2, null);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipient = EmailRecipient::query()->firstOrNew(['team_id' => $current_team->id, 'email' => Str::lower($email)]);
            $recipient->name = $name ?: $recipient->name;

            // Someone who reported the email as spam has to sign up again
            // themselves; adding them back in bulk does not resubscribe them.
            if ($recipient->status !== 'complained') {
                $recipient->fill(['status' => 'subscribed', 'status_reason' => null, 'soft_bounce_count' => 0, 'unsubscribed_at' => null]);
            }

            $recipient->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recipients saved.')]);

        return back();
    }

    public function update(Request $request, Team $current_team, EmailRecipient $recipient): RedirectResponse
    {
        abort_unless($recipient->team_id === $current_team->id, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['subscribed', 'unsubscribed'])],
        ]);

        if ($recipient->status === 'complained' && $data['status'] === 'subscribed') {
            throw ValidationException::withMessages(['status' => __('This person reported an email as spam. If they ask to get it again, remove them and add them again.')]);
        }

        $recipient->update([
            'name' => $data['name'] ?? null,
            'status' => $data['status'],
            'status_reason' => null,
            'soft_bounce_count' => 0,
            'unsubscribed_at' => $data['status'] === 'unsubscribed' ? now() : null,
        ]);

        return back();
    }

    public function destroy(Team $current_team, EmailRecipient $recipient): RedirectResponse
    {
        abort_unless($recipient->team_id === $current_team->id, 404);
        $recipient->delete();

        return back();
    }
}
