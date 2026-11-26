<?php

namespace App\Modules\WeeklyEmail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\CongregationMailer;
use App\Mail\EmailSending;
use App\Mail\MailChannels\EmailSendingNotReady;
use App\Mail\MailChannels\MailChannelsException;
use App\Models\Team;
use App\Modules\WeeklyEmail\Actions\PaceDeliveries;
use App\Modules\WeeklyEmail\Actions\RenderWeeklyEmail;
use App\Modules\WeeklyEmail\Actions\RunState;
use App\Modules\WeeklyEmail\Actions\SendWeeklyEmailMessage;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WeeklyEmailController extends Controller
{
    public function recipients(Team $current_team): Response
    {
        return Inertia::render('weekly-email/Recipients', [
            'recipients' => EmailRecipient::query()->forTeam($current_team)->orderBy('email')->get(),
        ]);
    }

    public function storeRecipients(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'string'],
        ]);

        foreach (preg_split('/\r\n|\r|\n/', $data['entries']) as $line) {
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

    public function updateRecipient(Request $request, Team $current_team, EmailRecipient $recipient): RedirectResponse
    {
        abort_unless($recipient->team_id === $current_team->id, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['subscribed', 'unsubscribed'])],
        ]);

        if ($recipient->status === 'complained' && $data['status'] === 'subscribed') {
            throw ValidationException::withMessages(['status' => __('This person reported the weekly email as spam. If they ask to get it again, remove them and add them again.')]);
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

    public function destroyRecipient(Team $current_team, EmailRecipient $recipient): RedirectResponse
    {
        abort_unless($recipient->team_id === $current_team->id, 404);
        $recipient->delete();

        return back();
    }

    public function template(Team $current_team): Response
    {
        return Inertia::render('weekly-email/Template', [
            'settings' => WeeklyEmailSetting::forTeam($current_team),
            'sendingProblem' => EmailSending::problem($current_team),
            'sendingDomain' => $current_team->sending_domain,
        ]);
    }

    public function updateTemplate(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'zoom_link' => ['nullable', 'url', 'max:2048'],
            'from_address' => ['required', 'email', function (string $attribute, mixed $value, Closure $fail) use ($current_team) {
                if (is_string($value) && ! CongregationMailer::isOnSendingDomain($current_team, $value)) {
                    $fail(__('The from address must be on the congregation\'s sending domain, :domain.', ['domain' => $current_team->sending_domain]));
                }
            }],
            'from_name' => ['required', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email'],
        ]);

        WeeklyEmailSetting::forTeam($current_team)->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back();
    }

    public function previewTemplate(Request $request, Team $current_team, RenderWeeklyEmail $renderer): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'zoom_link' => ['nullable', 'string', 'max:2048'],
        ]);

        $settings = new WeeklyEmailSetting([
            'subject' => $data['subject'] ?? '',
            'body' => $data['body'] ?? '',
            'zoom_link' => $data['zoom_link'] ?? null,
        ]);
        $recipient = new EmailRecipient(['email' => $request->user()->email, 'name' => $request->user()->name]);
        $rendered = $renderer->handle($settings, $recipient);

        return response()->json([
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
        ]);
    }

    public function sendTest(Request $request, Team $current_team, SendWeeklyEmailMessage $sender): RedirectResponse
    {
        $settings = WeeklyEmailSetting::forTeam($current_team);
        $settings->subject = '[Test] '.$settings->subject;
        $recipient = new EmailRecipient(['email' => $request->user()->email, 'name' => $request->user()->name]);

        try {
            $sender->handle($current_team, $settings, $recipient, 'test');
        } catch (EmailSendingNotReady|MailChannelsException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Test email failed: :error', ['error' => $exception->getMessage()])]);

            return back();
        } catch (Throwable $exception) {
            report($exception);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Test email failed: :error', ['error' => $exception->getMessage()])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test email sent to :email.', ['email' => $recipient->email])]);

        return back();
    }

    public function schedule(Team $current_team): Response
    {
        $subscribers = EmailRecipient::query()->forTeam($current_team)->where('status', 'subscribed')->count();

        return Inertia::render('weekly-email/Schedule', [
            'settings' => WeeklyEmailSetting::forTeam($current_team),
            'sendingProblem' => EmailSending::problem($current_team),
            'subscribers' => $subscribers,
            'estimatedMinutes' => PaceDeliveries::estimatedMinutes($subscribers),
            'dailyEmailLimit' => $current_team->daily_email_limit,
        ]);
    }

    public function updateSchedule(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'send_day' => ['required', 'integer', 'between:0,6'],
            'send_time' => ['required', 'date_format:H:i'],
        ]);

        if ($data['enabled'] && ($problem = EmailSending::problem($current_team)) !== null) {
            throw ValidationException::withMessages(['enabled' => __('The weekly email cannot be turned on yet. :problem', ['problem' => $problem])]);
        }

        WeeklyEmailSetting::forTeam($current_team)->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Schedule saved.')]);

        return back();
    }

    public function runs(Team $current_team): Response
    {
        return Inertia::render('weekly-email/Runs', [
            'runs' => EmailRun::query()->forTeam($current_team)->withCount([
                'deliveries',
                'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])->latest('scheduled_for')->limit(50)->get(),
        ]);
    }

    public function showRun(Team $current_team, EmailRun $run): Response
    {
        abort_unless($run->team_id === $current_team->id, 404);

        return Inertia::render('weekly-email/Run', [
            'run' => $run,
            'deliveries' => $run->deliveries()->with('recipient')->orderBy('scheduled_at')->get(),
        ]);
    }

    public function cancelRun(Team $current_team, EmailRun $run): RedirectResponse
    {
        abort_unless($run->team_id === $current_team->id, 404);
        $run->update(['status' => 'cancelled']);
        $run->deliveries()->where('status', 'pending')->update(['status' => 'cancelled']);

        return back();
    }

    public function retryRun(Team $current_team, EmailRun $run, PaceDeliveries $pace): RedirectResponse
    {
        abort_unless($run->team_id === $current_team->id, 404);
        // A paused run is picked up with Resume, which also checks the account.
        abort_if(in_array($run->status, ['cancelled', 'paused'], true), 422);

        $failed = $run->deliveries()->where('status', 'failed')->orderBy('scheduled_at')->get();

        if ($failed->isEmpty()) {
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

    public function resumeRun(Team $current_team, EmailRun $run, RunState $state): RedirectResponse
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
