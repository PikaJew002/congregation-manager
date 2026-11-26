<?php

namespace App\Mail\MailChannels;

use Carbon\CarbonImmutable;

/**
 * One event MailChannels reported about an email after accepting it, such as
 * delivered, hard-bounced or complained. Dispatched for each event a webhook
 * brings; each module that sends mail picks out the ones for its own emails by
 * request id.
 */
class DeliveryEvent
{
    public function __construct(
        public readonly string $event,
        public readonly ?string $requestId,
        public readonly ?string $email,
        public readonly CarbonImmutable $at,
        public readonly ?string $status = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): ?self
    {
        $event = $data['event'] ?? null;

        if (! is_string($event) || $event === '') {
            return null;
        }

        return new self(
            event: $event,
            requestId: is_string($data['request_id'] ?? null) ? $data['request_id'] : null,
            email: is_string($data['email'] ?? null) ? strtolower($data['email']) : null,
            at: is_numeric($data['timestamp'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $data['timestamp']) : CarbonImmutable::now(),
            status: isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : null,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
        );
    }
}
