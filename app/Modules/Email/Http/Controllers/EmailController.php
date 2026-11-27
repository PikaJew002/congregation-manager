<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\EmailSending;
use App\Mail\MailChannels\EmailSendingNotReady;
use App\Mail\MailChannels\MailChannelsException;
use App\Models\Team;
use App\Modules\Email\Actions\PaceDeliveries;
use App\Modules\Email\Actions\RenderEmail;
use App\Modules\Email\Actions\SendEmailMessage;
use App\Modules\Email\Http\Controllers\Concerns\ValidatesVariables;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use App\Modules\Email\Models\EmailSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The congregation's emails: recurring ones, such as the Shabbat Zoom invite,
 * and one-time ones, such as a monthly newsletter.
 */
class EmailController extends Controller
{
    use ValidatesVariables;

    /** How send_at travels to and from the form, in the congregation's time zone. */
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    private const DEFAULT_BODY = "Hello {{name}},\n\n\n\n---\n\n[Unsubscribe]({{unsubscribe_url}})";

    public function index(Team $current_team): Response
    {
        $lastSent = EmailRun::query()->forTeam($current_team)
            ->whereNotNull('email_id')
            ->groupBy('email_id')
            ->selectRaw('email_id, MAX(scheduled_for) as last_sent_at')
            ->pluck('last_sent_at', 'email_id');

        $emails = Email::query()->forTeam($current_team)->orderBy('name')->get()
            ->map(fn (Email $email) => [
                ...$this->present($email, $current_team),
                'last_sent_at' => isset($lastSent[$email->id]) ? CarbonImmutable::parse($lastSent[$email->id], 'UTC')->toIso8601String() : null,
            ]);

        return Inertia::render('email/Emails', [
            'emails' => $emails,
            'sendingProblem' => EmailSending::problem($current_team),
        ]);
    }

    public function create(Team $current_team): Response
    {
        $email = new Email([
            'name' => '',
            'status' => 'draft',
            'send_mode' => Email::RECURRING,
            'subject' => '',
            'body' => self::DEFAULT_BODY,
            'variables' => [],
            'send_days' => [],
            'send_time' => '09:00',
        ]);

        return $this->editor($current_team, $email);
    }

    public function edit(Team $current_team, Email $email): Response
    {
        abort_unless($email->team_id === $current_team->id, 404);

        return $this->editor($current_team, $email);
    }

    public function store(Request $request, Team $current_team, RenderEmail $renderer): RedirectResponse
    {
        $email = Email::query()->create([
            'team_id' => $current_team->id,
            ...$this->validated($request, $current_team, $renderer),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->savedMessage($email, $current_team)]);

        return to_route('email.edit', [$current_team->slug, $email]);
    }

    public function update(Request $request, Team $current_team, Email $email, RenderEmail $renderer): RedirectResponse
    {
        abort_unless($email->team_id === $current_team->id, 404);

        if ($email->status === 'sent') {
            throw ValidationException::withMessages(['status' => __('This email has been sent. Duplicate it to send it again.')]);
        }

        $email->update($this->validated($request, $current_team, $renderer));

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->savedMessage($email, $current_team)]);

        return back();
    }

    public function destroy(Team $current_team, Email $email): RedirectResponse
    {
        abort_unless($email->team_id === $current_team->id, 404);

        // Its runs stay under Sent, with the text they went out with.
        $email->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email deleted.')]);

        return to_route('email.index', $current_team->slug);
    }

    public function duplicate(Team $current_team, Email $email): RedirectResponse
    {
        abort_unless($email->team_id === $current_team->id, 404);

        $copy = $email->replicate();
        $copy->fill([
            'name' => Str::limit('Copy of '.$email->name, 255, ''),
            'status' => 'draft',
            'send_at' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copied. This copy is a draft until you schedule it.')]);

        return to_route('email.edit', [$current_team->slug, $copy]);
    }

    /**
     * The email as it would go out next, filled in for the person looking,
     * with anything that would go wrong.
     */
    public function preview(Request $request, Team $current_team, RenderEmail $renderer): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'variables' => ['nullable', 'array', 'max:'.self::MAX_VARIABLES],
            'variables.*.key' => ['nullable', 'string', 'max:64'],
            'variables.*.value' => ['nullable', 'string', 'max:2000'],
            'send_mode' => ['nullable', Rule::in([Email::RECURRING, Email::ONCE])],
            'send_days' => ['nullable', 'array'],
            'send_days.*' => ['integer', 'between:0,6'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'send_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
        ]);

        $email = new Email([
            'subject' => $data['subject'] ?? '',
            'body' => $data['body'] ?? '',
            'variables' => $this->cleanVariables(array_filter($data['variables'] ?? [], fn ($variable) => filled($variable['key'] ?? null))),
            'send_mode' => $data['send_mode'] ?? Email::RECURRING,
            'send_days' => $data['send_days'] ?? [],
            'send_time' => $data['send_time'] ?? null,
            'send_at' => $this->toUtc($data['send_at'] ?? null, $current_team),
        ]);

        $variables = $renderer->variablesFor($email, EmailSetting::forTeam($current_team));
        $at = $this->sendsAt($email, $current_team);
        $recipient = new EmailRecipient(['email' => $request->user()->email, 'name' => $request->user()->name]);
        $rendered = $renderer->personalize(
            $renderer->resolve($email->subject, $variables, $at),
            $renderer->resolve($email->body, $variables, $at),
            $recipient,
        );

        return response()->json([
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'sendsAt' => $at->toIso8601String(),
            'problems' => $renderer->problems($email->subject."\n".$email->body, $variables),
        ]);
    }

    public function sendTest(Request $request, Team $current_team, Email $email, RenderEmail $renderer, SendEmailMessage $sender): RedirectResponse
    {
        abort_unless($email->team_id === $current_team->id, 404);

        $settings = EmailSetting::forTeam($current_team);
        $variables = $renderer->variablesFor($email, $settings);
        $at = $this->sendsAt($email, $current_team);
        $recipient = new EmailRecipient(['email' => $request->user()->email, 'name' => $request->user()->name]);

        try {
            $sender->handle(
                $current_team,
                $settings,
                '[Test] '.$renderer->resolve($email->subject, $variables, $at),
                $renderer->resolve($email->body, $variables, $at),
                $recipient,
                'test',
            );
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

    private function editor(Team $team, Email $email): Response
    {
        $subscribers = EmailRecipient::query()->forTeam($team)->where('status', 'subscribed')->count();
        $settings = EmailSetting::forTeam($team);

        return Inertia::render('email/Edit', [
            'email' => $email->exists ? $this->present($email, $team) : [...$this->present($email, $team), 'id' => null],
            'sharedVariables' => $settings->variables ?? [],
            'sender' => ['from_name' => $settings->from_name, 'from_address' => $settings->from_address],
            'runs' => $email->exists
                ? $email->runs()->withCount([
                    'deliveries',
                    'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
                ])->latest('scheduled_for')->limit(5)->get()
                : [],
            'sendingProblem' => EmailSending::problem($team),
            'subscribers' => $subscribers,
            'estimatedMinutes' => PaceDeliveries::estimatedMinutes($subscribers),
            'dailyEmailLimit' => $team->daily_email_limit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Email $email, Team $team): array
    {
        $next = $email->status === 'scheduled' ? $email->nextSendAt($team) : null;

        return [
            'id' => $email->id,
            'name' => $email->name,
            'status' => $email->status,
            'send_mode' => $email->send_mode,
            'subject' => $email->subject,
            'body' => $email->body,
            'variables' => $email->variables ?? [],
            'send_days' => $email->sendDays(),
            'send_time' => $email->send_time ? substr($email->send_time, 0, 5) : '09:00',
            'send_at' => $email->send_at?->setTimezone($team->localTimezone())->format(self::LOCAL_FORMAT),
            'next_send_at' => $next?->toIso8601String(),
        ];
    }

    /**
     * The attributes to save, checked against each other: a scheduled email
     * needs a time to go out, a working sending setup, and every placeholder
     * it uses to have a value.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Team $team, RenderEmail $renderer): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'send_mode' => ['required', Rule::in([Email::RECURRING, Email::ONCE])],
            'status' => ['required', Rule::in(['draft', 'scheduled'])],
            'send_now' => ['sometimes', 'boolean'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
            'send_days' => ['nullable', 'array'],
            'send_days.*' => ['integer', 'between:0,6'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'send_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
            ...$this->variableRules(),
        ], $this->variableMessages());

        $recurring = $data['send_mode'] === Email::RECURRING;
        $sendNow = ! $recurring && ($data['send_now'] ?? false);
        $status = $sendNow ? 'scheduled' : $data['status'];
        $sendAt = $sendNow ? CarbonImmutable::now()->utc() : $this->toUtc($data['send_at'] ?? null, $team);
        $days = array_values(array_unique(array_map('intval', $data['send_days'] ?? [])));
        sort($days);

        $errors = [];

        if ($recurring && blank($data['send_time'] ?? null)) {
            $errors['send_time'] = __('Choose the time it goes out.');
        }

        if ($recurring && $status === 'scheduled' && $days === []) {
            $errors['send_days'] = __('Choose at least one day for it to go out.');
        }

        if (! $recurring && $status === 'scheduled' && ! $sendNow) {
            if ($sendAt === null) {
                $errors['send_at'] = __('Choose when it goes out, or send it now.');
            } elseif ($sendAt->lt(CarbonImmutable::now()->subMinute())) {
                $errors['send_at'] = __('That time has passed. Choose a later time, or send it now.');
            }
        }

        if ($status === 'scheduled' && ($problem = EmailSending::problem($team)) !== null) {
            $errors['status'] = __('This email cannot be scheduled yet. :problem', ['problem' => $problem]);
        }

        $variables = $this->cleanVariables($data['variables'] ?? []);
        $available = array_merge(EmailSetting::forTeam($team)->variableMap(), Email::mapVariables($variables));

        foreach (['subject', 'body'] as $field) {
            if (($problems = $renderer->problems($data[$field], $available)) !== []) {
                $errors[$field] = implode(' ', $problems);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $data['name'],
            'status' => $status,
            'send_mode' => $data['send_mode'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'variables' => $variables,
            'send_days' => $recurring ? $days : null,
            'send_time' => $recurring ? $data['send_time'] : null,
            'send_at' => $recurring ? null : $sendAt,
        ];
    }

    /** When the email would go out next, or now when it has no time yet: what dates count from. */
    private function sendsAt(Email $email, Team $team): CarbonImmutable
    {
        return $email->nextSendAt($team) ?? CarbonImmutable::now()->setTimezone($team->localTimezone());
    }

    private function toUtc(?string $local, Team $team): ?CarbonImmutable
    {
        if (blank($local)) {
            return null;
        }

        return CarbonImmutable::createFromFormat(self::LOCAL_FORMAT, $local, $team->localTimezone())?->utc();
    }

    private function savedMessage(Email $email, Team $team): string
    {
        if ($email->status !== 'scheduled') {
            return __('Saved as a draft. It will not go out until you schedule it.');
        }

        $next = $email->nextSendAt($team);

        if (! $email->isRecurring() && $next !== null && $next->lte(CarbonImmutable::now()->addMinute())) {
            return __('Sending now. Follow it under Sent.');
        }

        return $next ? __('Saved. Next goes out :when.', ['when' => $next->format('l, M j \a\t g:i A')]) : __('Saved.');
    }
}
