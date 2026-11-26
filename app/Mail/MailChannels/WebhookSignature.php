<?php

namespace App\Mail\MailChannels;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Checks that a webhook request really came from MailChannels: an RFC 9421
 * HTTP message signature (ed25519) over the request's Content-Digest, made in
 * the last few minutes.
 *
 * The key is MailChannels' public key named by the signature's keyid, fetched
 * from their API and cached. It is the same for every MailChannels account, so
 * one endpoint serves the platform and every congregation.
 */
class WebhookSignature
{
    /** Signatures older (or further in the future) than this are refused. */
    public const MAX_AGE_SECONDS = 300;

    public const KEY_CACHE_SECONDS = 86400;

    /**
     * @throws InvalidWebhookSignature when the request is not genuine.
     * @throws TemporaryFailure when MailChannels' key could not be fetched.
     */
    public function verify(Request $request, ?int $now = null): void
    {
        $body = $request->getContent();

        $this->checkDigest((string) $request->header('Content-Digest'), $body);

        [$label, $components, $params, $rawParams] = $this->parseInput((string) $request->header('Signature-Input'));

        $created = (int) ($params['created'] ?? 0);

        if ($created === 0 || abs(($now ?? time()) - $created) > self::MAX_AGE_SECONDS) {
            throw new InvalidWebhookSignature('The signature is missing its time or is too old.');
        }

        if (($params['alg'] ?? 'ed25519') !== 'ed25519') {
            throw new InvalidWebhookSignature('Only ed25519 signatures are accepted.');
        }

        if (! in_array('content-digest', $components, true)) {
            throw new InvalidWebhookSignature('The signature does not cover the body.');
        }

        $keyId = (string) ($params['keyid'] ?? '');

        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $keyId) !== 1) {
            throw new InvalidWebhookSignature('The signature names no usable key.');
        }

        $signature = $this->signature((string) $request->header('Signature'), $label);
        $key = $this->publicKey($keyId);

        // MailChannels' docs show the parameters line both as sent (with
        // quotes, as RFC 9421 says) and without quotes; accept either.
        foreach (array_unique([$rawParams, (string) preg_replace('/(alg|keyid)="([^"]*)"/', '$1=$2', $rawParams)]) as $paramsLine) {
            $base = $this->signatureBase($request, $components, $paramsLine);

            if (sodium_crypto_sign_verify_detached($signature, $base, $key)) {
                return;
            }
        }

        throw new InvalidWebhookSignature('The signature does not match.');
    }

    private function checkDigest(string $header, string $body): void
    {
        if (preg_match('/(?:^|,)\s*sha-256=:([^:]+):/i', $header, $match) !== 1) {
            throw new InvalidWebhookSignature('The request has no sha-256 Content-Digest.');
        }

        if (! hash_equals(base64_encode(hash('sha256', $body, true)), $match[1])) {
            throw new InvalidWebhookSignature('The body does not match its Content-Digest.');
        }
    }

    /**
     * @return array{0: string, 1: list<string>, 2: array<string, string>, 3: string}
     *                                                                                The label, covered components, parameters, and the raw value after the label.
     */
    private function parseInput(string $header): array
    {
        if (preg_match('/^\s*([A-Za-z0-9_-]+)=(\(([^)]*)\)(.*))$/', $header, $match) !== 1) {
            throw new InvalidWebhookSignature('The Signature-Input header is missing or malformed.');
        }

        preg_match_all('/"([^"]+)"/', $match[3], $names);
        $params = [];

        foreach (array_filter(explode(';', $match[4])) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $params[strtolower($name)] = trim($value, '"');
        }

        return [$match[1], array_map('strtolower', $names[1]), $params, trim($match[2])];
    }

    /**
     * @return non-empty-string
     */
    private function signature(string $header, string $label): string
    {
        if (preg_match('/(?:^|,)\s*'.preg_quote($label, '/').'=:([^:]+):/', $header, $match) !== 1) {
            throw new InvalidWebhookSignature('The Signature header has no signature for '.$label.'.');
        }

        $signature = base64_decode($match[1], true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidWebhookSignature('The signature is not a valid ed25519 signature.');
        }

        return $signature;
    }

    /**
     * @param  list<string>  $components
     */
    private function signatureBase(Request $request, array $components, string $paramsLine): string
    {
        $lines = [];

        foreach ($components as $component) {
            $value = match ($component) {
                '@method' => strtoupper($request->getMethod()),
                '@path' => $request->getPathInfo(),
                '@authority' => strtolower($request->getHttpHost()),
                '@target-uri' => $request->fullUrl(),
                default => str_starts_with($component, '@')
                    ? throw new InvalidWebhookSignature("The signature covers {$component}, which is not supported.")
                    : trim((string) $request->header($component)),
            };

            $lines[] = "\"{$component}\": {$value}";
        }

        $lines[] = "\"@signature-params\": {$paramsLine}";

        return implode("\n", $lines);
    }

    /**
     * MailChannels' public key as the 32 raw bytes sodium expects.
     *
     * @return non-empty-string
     *
     * @throws TemporaryFailure
     */
    private function publicKey(string $keyId): string
    {
        $encoded = Cache::remember('mailchannels-webhook-key:'.$keyId, self::KEY_CACHE_SECONDS, function () use ($keyId) {
            try {
                $response = Http::acceptJson()->timeout(10)->get(MailChannelsApi::BASE_URL.'/webhook/public-key', ['id' => $keyId]);
            } catch (\Throwable $exception) {
                throw new TemporaryFailure('Could not fetch the MailChannels webhook key: '.$exception->getMessage(), previous: $exception);
            }

            if ($response->status() === 404) {
                throw new InvalidWebhookSignature("MailChannels has no webhook key {$keyId}.");
            }

            if ($response->failed() || ! is_string($response->json('key'))) {
                throw new TemporaryFailure('Could not fetch the MailChannels webhook key ('.$response->status().').');
            }

            return $response->json('key');
        });

        return self::rawKey((string) $encoded);
    }

    /**
     * Accepts a PEM or base64 SubjectPublicKeyInfo, or 32 raw bytes in base64.
     *
     * @return non-empty-string
     */
    public static function rawKey(string $encoded): string
    {
        $base64 = (string) preg_replace('/-----[^-]+-----|\s+/', '', $encoded);
        $bytes = base64_decode($base64, true);

        if ($bytes === false) {
            throw new RuntimeException('The MailChannels webhook key is not base64.');
        }

        // An ed25519 SubjectPublicKeyInfo is a 12-byte header and the 32-byte key.
        if (strlen($bytes) === 44) {
            $bytes = substr($bytes, 12);
        }

        if (strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('The MailChannels webhook key is not an ed25519 key.');
        }

        return $bytes;
    }
}
