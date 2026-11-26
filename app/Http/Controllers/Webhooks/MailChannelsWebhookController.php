<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\MailChannels\DispatchDeliveryEvents;
use App\Mail\MailChannels\InvalidWebhookSignature;
use App\Mail\MailChannels\TemporaryFailure;
use App\Mail\MailChannels\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The one webhook every MailChannels account points at: the platform's and
 * each congregation's. The signature proves an event came from MailChannels;
 * which email it is about comes from the event's request id, not the URL.
 */
class MailChannelsWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature): Response
    {
        try {
            $signature->verify($request);
        } catch (InvalidWebhookSignature $exception) {
            Log::warning('Refused a MailChannels webhook: '.$exception->getMessage());

            return response('Invalid signature.', 401);
        } catch (TemporaryFailure $exception) {
            report($exception);

            // MailChannels retries failed deliveries, so ask it to.
            return response('Try again later.', 503);
        }

        $events = json_decode($request->getContent(), true);

        if (! is_array($events) || ! array_is_list($events)) {
            return response('Expected a list of events.', 422);
        }

        $events = array_values(array_filter($events, 'is_array'));

        if ($events !== []) {
            DispatchDeliveryEvents::dispatch($events);
        }

        return response('', 200);
    }
}
