<?php

namespace App\Mail\MailChannels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Calls to the MailChannels Email API other than sending, made with one
 * account's key.
 */
class MailChannelsApi
{
    public const BASE_URL = 'https://api.mailchannels.net/tx/v1';

    /** Failed webhook deliveries in a row before MailChannels emails the account. */
    public const WEBHOOK_FAILURE_THRESHOLD = 5;

    public function __construct(private string $apiKey) {}

    /**
     * Ask MailChannels whether a domain is set up to send: DKIM, SPF and Domain
     * Lockdown. A wrong key fails here, so this doubles as a key check.
     *
     * @return array<string, mixed> The check_results object.
     *
     * @throws MailChannelsException
     */
    public function checkDomain(string $domain, ?string $dkimSelector = null): array
    {
        $body = ['domain' => $domain];

        if ($dkimSelector) {
            $body['dkim_settings'] = [['dkim_domain' => $domain, 'dkim_selector' => $dkimSelector]];
        }

        $response = $this->send(fn (PendingRequest $request) => $request->post(self::BASE_URL.'/check-domain', $body));

        if ($response->failed()) {
            throw self::failure($response);
        }

        return (array) $response->json('check_results', []);
    }

    /**
     * Have MailChannels create and keep a DKIM key for the domain, and return
     * the TXT record to publish. When the selector already has a key, that
     * key's record is returned instead.
     *
     * @return array{name: string, type: string, value: string}
     *
     * @throws MailChannelsException
     */
    public function createDkimKey(string $domain, string $selector): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->post(
            self::BASE_URL.'/domains/'.rawurlencode($domain).'/dkim-keys',
            ['selector' => $selector, 'algorithm' => 'rsa', 'key_length' => 2048],
        ));

        if ($response->status() === 409) {
            $response = $this->send(fn (PendingRequest $request) => $request->get(
                self::BASE_URL.'/domains/'.rawurlencode($domain).'/dkim-keys',
                ['selector' => $selector, 'include_dns_record' => 'true'],
            ));

            if ($response->failed()) {
                throw self::failure($response);
            }

            return self::dnsRecord((array) $response->json('keys.0'), $domain, $selector);
        }

        if ($response->failed()) {
            throw self::failure($response);
        }

        return self::dnsRecord((array) $response->json(), $domain, $selector);
    }

    /**
     * Point the account's delivery events at a URL. MailChannels keeps one
     * webhook per account, so when the account already has one this returns
     * the URL it points at instead of changing it.
     *
     * @return string The URL the account's delivery events now go to.
     *
     * @throws MailChannelsException
     */
    public function enrollWebhook(string $url): string
    {
        // The endpoint goes in the query string. A JSON body must be an
        // object (an empty post is sent as [], which MailChannels refuses),
        // so it carries the failure threshold: MailChannels emails the
        // account when the webhook fails this many times in a row. A 201
        // means the webhook was created without the threshold.
        $response = $this->send(fn (PendingRequest $request) => $request->post(
            self::BASE_URL.'/webhook?endpoint='.rawurlencode($url),
            ['failure_threshold' => self::WEBHOOK_FAILURE_THRESHOLD],
        ));

        if ($response->status() === 409) {
            return $this->webhook() ?? $url;
        }

        if ($response->failed()) {
            throw self::failure($response);
        }

        return $url;
    }

    /**
     * The URL the account's delivery events go to, or null when there is none.
     *
     * @throws MailChannelsException
     */
    public function webhook(): ?string
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get(self::BASE_URL.'/webhook'));

        if ($response->failed()) {
            throw self::failure($response);
        }

        $first = $response->json('0.webhook') ?? $response->json('webhook');

        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * Whether MailChannels refused an email because the address is on its
     * suppression list. The wording is not documented, so this is a guess to
     * check against a real refusal.
     */
    public static function isSuppression(string $reason): bool
    {
        return Str::contains(Str::lower($reason), 'suppress');
    }

    public static function request(string $apiKey): PendingRequest
    {
        return Http::withHeaders(['X-Api-Key' => $apiKey])->acceptJson()->asJson()->timeout(30);
    }

    /**
     * The exception for a failed MailChannels response, sorted by whose
     * problem it is.
     */
    public static function failure(Response $response): MailChannelsException
    {
        $detail = self::detail($response);
        $lower = Str::lower($detail);

        if (Str::contains($lower, ['too fast'])) {
            return new SendingTooFast("MailChannels asked us to slow down: {$detail}");
        }

        if (Str::contains($lower, ['daily limit', 'quota'])) {
            return new AccountProblem("The MailChannels sending limit is used up: {$detail}");
        }

        if (self::isSuppression($lower)) {
            return new RecipientSuppressed("MailChannels will not send to this address: {$detail}");
        }

        return match (true) {
            in_array($response->status(), [401, 403], true) => new AccountProblem("MailChannels refused the account: {$detail}"),
            in_array($response->status(), [400, 413, 422], true) => new MessageRejected("MailChannels rejected the message: {$detail}"),
            default => new TemporaryFailure("MailChannels is not responding normally ({$response->status()}): {$detail}"),
        };
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws TemporaryFailure
     */
    private function send(callable $call): Response
    {
        try {
            return $call(self::request($this->apiKey));
        } catch (ConnectionException $exception) {
            throw new TemporaryFailure('Could not reach MailChannels: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The TXT record from a DKIM key, built from its public key when
     * MailChannels did not include the record itself.
     *
     * @param  array<string, mixed>  $key
     * @return array{name: string, type: string, value: string}
     *
     * @throws MailChannelsException
     */
    private static function dnsRecord(array $key, string $domain, string $selector): array
    {
        $record = $key['dkim_dns_records'][0] ?? null;

        if (is_array($record) && filled($record['name'] ?? null) && filled($record['value'] ?? null)) {
            return [
                'name' => (string) $record['name'],
                'type' => (string) ($record['type'] ?? 'TXT'),
                'value' => (string) $record['value'],
            ];
        }

        if (filled($key['public_key'] ?? null)) {
            return [
                'name' => "{$selector}._domainkey.{$domain}",
                'type' => 'TXT',
                'value' => 'v=DKIM1; k=rsa; p='.$key['public_key'],
            ];
        }

        throw new TemporaryFailure('MailChannels did not return the DKIM record for '.$domain.'.');
    }

    private static function detail(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            return implode(' ', array_map(fn ($error) => is_string($error) ? $error : json_encode($error), $errors));
        }

        return trim($response->body()) ?: $response->reason();
    }
}
