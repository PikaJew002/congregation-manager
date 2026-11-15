<?php

namespace App\Modules\WeeklyEmail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\WeeklyEmail\Actions\RenderWeeklyEmail;
use App\Modules\WeeklyEmail\Actions\SendWeeklyEmailMessage;
use App\Modules\WeeklyEmail\Jobs\SendWeeklyEmailJob;
use App\Modules\WeeklyEmail\Models\EmailDelivery;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

            EmailRecipient::query()->updateOrCreate(
                ['team_id' => $current_team->id, 'email' => Str::lower($email)],
                ['name' => $name ?: null, 'status' => 'subscribed', 'unsubscribed_at' => null],
            );
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

        $recipient->update([
            'name' => $data['name'] ?? null,
            'status' => $data['status'],
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
        ]);
    }

    public function updateTemplate(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'zoom_link' => ['nullable', 'url', 'max:2048'],
            'from_address' => ['required', 'email'],
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
            $sender->handle($settings, $recipient);
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
        return Inertia::render('weekly-email/Schedule', [
            'settings' => WeeklyEmailSetting::forTeam($current_team),
        ]);
    }

    public function updateSchedule(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'send_day' => ['required', 'integer', 'between:0,6'],
            'send_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'window_minutes' => ['required', 'integer', 'between:30,240'],
        ]);

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

    public function retryRun(Team $current_team, EmailRun $run): RedirectResponse
    {
        abort_unless($run->team_id === $current_team->id, 404);
        abort_if($run->status === 'cancelled', 422);

        if ($run->deliveries()->where('status', 'failed')->exists()) {
            $run->update(['status' => 'sending']);
        }

        $run->deliveries()->where('status', 'failed')->each(function (EmailDelivery $delivery) {
            $delivery->update(['status' => 'pending', 'error' => null]);
            SendWeeklyEmailJob::dispatch($delivery->id);
        });

        return back();
    }
}
