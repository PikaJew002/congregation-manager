<?php

namespace App\Mail\MailChannels;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Event;

/**
 * Hands each event from a verified MailChannels webhook to whichever module
 * sent the email. Runs on the queue so the webhook answers at once.
 */
class DispatchDeliveryEvents implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public array $events) {}

    public function handle(): void
    {
        foreach ($this->events as $data) {
            $event = DeliveryEvent::fromPayload($data);

            if ($event !== null) {
                Event::dispatch($event);
            }
        }
    }
}
