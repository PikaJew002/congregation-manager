<?php

namespace App\Modules\WeeklyEmail\Actions;

use App\Mail\EmailSending;
use App\Models\Team;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use Carbon\CarbonImmutable;

/**
 * The Weekly email card on the dashboard: when the next email goes out, how
 * the last one went, and the size of the list.
 */
class WeeklyEmailDashboard
{
    /**
     * @return array<string, mixed> The card, shaped as ProvidesDashboard describes.
     */
    public function handle(Team $team): array
    {
        // Read without forTeam(), which would create a settings row.
        $settings = WeeklyEmailSetting::query()->where('team_id', $team->id)->first();
        $subscribers = EmailRecipient::query()->forTeam($team)->where('status', 'subscribed')->count();
        $unsubscribed = EmailRecipient::query()->forTeam($team)
            ->where('status', 'unsubscribed')
            ->where('unsubscribed_at', '>=', now()->subDays(30))
            ->count();

        $runs = EmailRun::query()->forTeam($team)
            ->withCount([
                'deliveries',
                'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->latest('scheduled_for')
            ->limit(3)
            ->get();

        $last = $runs->first();
        $sending = $last && in_array($last->status, ['scheduled', 'sending'], true);
        $next = $settings?->enabled ? $this->nextSend($settings, $team) : null;

        $attention = [];

        if ($last && $last->failed_count > 0) {
            $attention[] = [
                'title' => trans_choice(':count weekly email failed to send|:count weekly emails failed to send', $last->failed_count),
                'detail' => 'In the send for '.$this->local($last->scheduled_for, $team)->format('M j').'. Retry it from Email runs.',
                'href' => route('weekly-email.runs.show', [$team->slug, $last]),
                'tone' => 'danger',
            ];
        }

        if ($settings?->enabled && ($problem = EmailSending::problem($team)) !== null) {
            $attention[] = [
                'title' => 'The weekly email cannot send',
                'detail' => $problem,
                'href' => route('teams.edit', $team->slug),
                'tone' => 'danger',
            ];
        }

        if ($settings?->enabled && $subscribers === 0) {
            $attention[] = [
                'title' => 'The weekly email has no subscribers',
                'detail' => 'The schedule is on, but nobody is on the email list.',
                'href' => route('weekly-email.recipients', $team->slug),
                'tone' => 'warning',
            ];
        }

        return [
            'headline' => match (true) {
                $sending => [
                    'title' => 'Sending now',
                    'detail' => "{$last->sent_count} of {$last->deliveries_count} sent so far",
                    'status' => ['label' => 'Sending', 'tone' => 'warning'],
                ],
                $next !== null => [
                    'title' => 'Next email '.$next->format('l, M j \a\t g:i A'),
                    'detail' => $settings->subject,
                    'status' => ['label' => 'Scheduled', 'tone' => 'success'],
                ],
                default => [
                    'title' => 'Not scheduled',
                    'detail' => 'Turn the schedule on in Email schedule to start sending.',
                    'status' => ['label' => 'Off', 'tone' => 'muted'],
                ],
            },
            'stats' => [
                ['label' => 'Subscribers', 'value' => (string) $subscribers],
                ['label' => 'Unsubscribed (30 days)', 'value' => (string) $unsubscribed, 'tone' => 'muted'],
                [
                    'label' => 'Last send',
                    'value' => $last ? "{$last->sent_count} of {$last->deliveries_count}" : '—',
                    'tone' => $last && $last->failed_count > 0 ? 'danger' : 'muted',
                ],
            ],
            'attention' => $attention,
            'activity' => $runs->map(fn (EmailRun $run) => [
                'at' => CarbonImmutable::parse($run->scheduled_for)->toIso8601String(),
                'title' => 'Weekly email '.$run->status,
                'detail' => "{$run->sent_count} of {$run->deliveries_count} sent".($run->failed_count ? ", {$run->failed_count} failed" : ''),
                'href' => route('weekly-email.runs.show', [$team->slug, $run]),
            ])->values()->all(),
        ];
    }

    /**
     * The next start of the send window, in the congregation's time zone.
     * Once today's window has opened, the next one is a week later.
     */
    public function nextSend(WeeklyEmailSetting $settings, Team $team, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $localNow = ($now ?? CarbonImmutable::now())->setTimezone($team->localTimezone());
        $daysAhead = ((int) $settings->send_day - $localNow->dayOfWeek + 7) % 7;
        $start = $localNow->addDays($daysAhead)->setTimeFromTimeString($settings->send_time);

        return $start->lt($localNow) ? $start->addWeek() : $start;
    }

    private function local(mixed $moment, Team $team): CarbonImmutable
    {
        return CarbonImmutable::parse($moment)->setTimezone($team->localTimezone());
    }
}
