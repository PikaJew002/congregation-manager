<?php

namespace App\Modules\Email\Listeners;

use App\Mail\MailChannels\DeliveryEvent;
use App\Modules\Email\Models\EmailDelivery;
use App\Modules\Email\Models\EmailRecipient;

/**
 * What MailChannels reported about an email after accepting it. Bounces
 * and complaints take the person off the list, so a bad address or someone
 * who reported the email as spam is not sent to again.
 *
 * Events can arrive more than once and in any order, so each change is safe
 * to repeat.
 */
class RecordDeliveryEvent
{
    /** Soft bounces on this many emails in a row make an address bounced. */
    public const SOFT_BOUNCES_TO_BOUNCE = 3;

    public function handle(DeliveryEvent $event): void
    {
        if ($event->requestId === null) {
            return;
        }

        $delivery = EmailDelivery::query()->with('recipient')->where('request_id', $event->requestId)->first();

        if (! $delivery) {
            return;
        }

        $recipient = $delivery->recipient;
        $reason = $this->reason($event);

        match ($event->event) {
            'delivered' => $this->delivered($delivery, $recipient),
            'hard-bounced' => $this->hardBounced($delivery, $recipient, $reason),
            'soft-bounced' => $this->softBounced($delivery, $recipient, $reason),
            'complained' => $this->complained($delivery, $recipient),
            'unsubscribed' => $this->unsubscribed($delivery, $recipient),
            'dropped' => $delivery->update(['last_event' => 'dropped', 'error' => 'MailChannels dropped this email: '.$reason]),
            default => null,
        };
    }

    private function delivered(EmailDelivery $delivery, ?EmailRecipient $recipient): void
    {
        $delivery->update(['delivered_at' => $delivery->delivered_at ?? now(), 'last_event' => 'delivered']);
        $recipient?->update(['soft_bounce_count' => 0]);
    }

    private function hardBounced(EmailDelivery $delivery, ?EmailRecipient $recipient, string $reason): void
    {
        $delivery->update(['status' => 'bounced', 'last_event' => 'hard-bounced', 'error' => 'Bounced: '.$reason]);
        $this->stop($recipient, 'bounced', 'The address bounced: '.$reason);
    }

    private function softBounced(EmailDelivery $delivery, ?EmailRecipient $recipient, string $reason): void
    {
        $repeat = $delivery->last_event === 'soft-bounced';
        $delivery->update(['last_event' => 'soft-bounced', 'error' => 'Temporarily refused: '.$reason]);

        if ($repeat || ! $recipient) {
            return;
        }

        $recipient->increment('soft_bounce_count');

        if ($recipient->soft_bounce_count >= self::SOFT_BOUNCES_TO_BOUNCE) {
            $this->stop($recipient, 'bounced', "The address refused our emails {$recipient->soft_bounce_count} times in a row: {$reason}");
        }
    }

    private function complained(EmailDelivery $delivery, ?EmailRecipient $recipient): void
    {
        $delivery->update(['last_event' => 'complained']);
        $this->stop($recipient, 'complained', 'Reported an email as spam.');
    }

    private function unsubscribed(EmailDelivery $delivery, ?EmailRecipient $recipient): void
    {
        $delivery->update(['last_event' => 'unsubscribed']);

        if ($recipient?->status === 'subscribed') {
            $recipient->update(['status' => 'unsubscribed', 'unsubscribed_at' => now(), 'status_reason' => null]);
        }
    }

    /**
     * Take someone off the list. A complaint outranks a bounce, so a bounce
     * never softens a complaint.
     */
    private function stop(?EmailRecipient $recipient, string $status, string $reason): void
    {
        if (! $recipient || $recipient->status === 'complained') {
            return;
        }

        $recipient->update(['status' => $status, 'status_reason' => $reason]);
    }

    private function reason(DeliveryEvent $event): string
    {
        return trim(implode(' ', array_filter([$event->status, $event->reason]))) ?: 'no reason given';
    }
}
