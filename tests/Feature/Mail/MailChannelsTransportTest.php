<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailChannelsTransportTest extends TestCase
{
    public function test_it_posts_a_single_recipient_to_mailchannels(): void
    {
        Http::fake([
            'api.mailchannels.net/*' => Http::response(['success' => true], 202),
        ]);

        config([
            'mail.mailers.mailchannels.key' => 'test-key',
            'mail.mailers.mailchannels.dkim_domain' => 'example.com',
            'mail.mailers.mailchannels.dkim_selector' => 'mailchannels',
            'mail.mailers.mailchannels.dkim_private_key' => 'private-key',
        ]);

        Mail::mailer('mailchannels')->raw('Hello there', function ($message) {
            $message->to('member@example.com', 'Member')
                ->from('news@example.com', 'News')
                ->replyTo('reply@example.com')
                ->subject('Weekly link');
        });

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.mailchannels.net/tx/v1/send'
                && $request->hasHeader('X-Api-Key', 'test-key')
                && $body['personalizations'][0]['to'][0]['email'] === 'member@example.com'
                && $body['personalizations'][0]['dkim_domain'] === 'example.com'
                && $body['subject'] === 'Weekly link'
                && count($body['personalizations'][0]['to']) === 1;
        });
    }
}
