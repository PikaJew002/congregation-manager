<?php

namespace App\Mail;

use App\Mail\MailChannels\MailChannelsApi;
use App\Mail\MailChannels\MailChannelsException;
use App\Models\Team;

/**
 * Whether a congregation's own MailChannels account is set up to send: a key,
 * a sending domain, a DKIM key MailChannels holds for it, and DKIM, SPF and
 * Domain Lockdown passing MailChannels' domain check.
 */
class EmailSending
{
    /** How long a passing domain check is trusted before it is run again. */
    public const CHECK_TTL_HOURS = 24;

    public const SPF_INCLUDE = 'include:relay.mailchannels.net';

    /** Why the congregation cannot send yet, or null when it can. */
    public static function problem(Team $team): ?string
    {
        return match (true) {
            blank($team->mailchannels_api_key) => 'Add the congregation\'s MailChannels API key in the congregation settings.',
            blank($team->sending_domain) => 'Add the congregation\'s sending domain in the congregation settings.',
            blank($team->dkim_selector) => 'Set up DKIM for '.$team->sending_domain.' in the congregation settings.',
            $team->domain_checked_at === null => 'Check the DNS records for '.$team->sending_domain.' in the congregation settings.',
            default => self::failedCheck($team),
        };
    }

    public static function ready(Team $team): bool
    {
        return self::problem($team) === null;
    }

    /**
     * Run MailChannels' domain check and store the result. Without $force, a
     * result younger than a day is kept. When MailChannels cannot be reached,
     * the last result stands.
     *
     * @throws MailChannelsException when $force is set and the check could not run.
     */
    public static function check(Team $team, bool $force = false): void
    {
        if (blank($team->mailchannels_api_key) || blank($team->sending_domain)) {
            return;
        }

        if (! $force && $team->domain_checked_at?->gt(now()->subHours(self::CHECK_TTL_HOURS))) {
            return;
        }

        try {
            $results = (new MailChannelsApi((string) $team->mailchannels_api_key))
                ->checkDomain((string) $team->sending_domain, $team->dkim_selector);
        } catch (MailChannelsException $exception) {
            if ($force) {
                throw $exception;
            }

            report($exception);

            return;
        }

        $team->forceFill(['domain_check' => $results, 'domain_checked_at' => now()])->save();
    }

    /**
     * The DNS records the congregation must publish, each with MailChannels'
     * last verdict on it, for the settings page.
     *
     * @return list<array{key: string, label: string, name: string|null, type: string, value: string|null, passed: bool|null, reason: string|null}>
     */
    public static function records(Team $team): array
    {
        $domain = $team->sending_domain;

        if (blank($domain)) {
            return [];
        }

        $check = $team->domain_checked_at ? (array) $team->domain_check : null;
        $dkim = self::dkimResult($team);
        $spf = $check['spf'] ?? null;
        $lockdown = $check['domain_lockdown'] ?? null;

        return [
            [
                'key' => 'dkim',
                'label' => 'DKIM',
                'name' => $team->dkim_dns_record['name'] ?? null,
                'type' => 'TXT',
                'value' => $team->dkim_dns_record['value'] ?? null,
                'passed' => $team->dkim_selector && $check !== null ? ($dkim['verdict'] ?? null) === 'passed' : null,
                'reason' => $dkim['reason'] ?? null,
            ],
            [
                'key' => 'spf',
                'label' => 'SPF',
                'name' => $domain,
                'type' => 'TXT',
                'value' => self::spfValue($spf['spfRecord'] ?? null),
                'passed' => is_array($spf) ? ($spf['verdict'] ?? null) === 'passed' : null,
                'reason' => is_array($spf) ? ($spf['reason'] ?? $spf['spfRecordError'] ?? null) : null,
            ],
            [
                'key' => 'domain_lockdown',
                'label' => 'Domain Lockdown',
                'name' => '_mailchannels.'.$domain,
                'type' => 'TXT',
                'value' => filled($team->mailchannels_account_id) ? 'v=mc1 auth='.$team->mailchannels_account_id : null,
                'passed' => is_array($lockdown) ? ($lockdown['verdict'] ?? null) === 'passed' : null,
                'reason' => is_array($lockdown) ? ($lockdown['reason'] ?? null) : null,
            ],
        ];
    }

    private static function failedCheck(Team $team): ?string
    {
        $failed = collect(self::records($team))
            ->filter(fn (array $record) => $record['passed'] !== true)
            ->pluck('label');

        if ($failed->isEmpty()) {
            return null;
        }

        return $failed->join(', ', ' and ').' for '.$team->sending_domain.' '.($failed->count() === 1 ? 'is' : 'are').' not passing. Fix the DNS records in the congregation settings, then check again.';
    }

    /**
     * The domain check's result for the congregation's own DKIM selector.
     *
     * @return array<string, mixed>|null
     */
    private static function dkimResult(Team $team): ?array
    {
        foreach ((array) ($team->domain_check['dkim'] ?? []) as $result) {
            if (is_array($result) && ($result['dkim_selector'] ?? null) === $team->dkim_selector) {
                return $result;
            }
        }

        return null;
    }

    /**
     * The SPF record to publish: the domain's current record with MailChannels
     * added, or a new record when there is none.
     */
    private static function spfValue(?string $current): string
    {
        $current = trim((string) $current);

        if ($current === '') {
            return 'v=spf1 '.self::SPF_INCLUDE.' ~all';
        }

        if (str_contains($current, self::SPF_INCLUDE)) {
            return $current;
        }

        return (string) preg_replace('/^v=spf1\b/i', 'v=spf1 '.self::SPF_INCLUDE, $current, 1);
    }
}
