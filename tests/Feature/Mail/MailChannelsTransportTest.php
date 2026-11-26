<?php

namespace Tests\Feature\Mail;

use App\Mail\CongregationMailer;
use App\Mail\MailChannels\AccountProblem;
use App\Mail\MailChannels\EmailSendingNotReady;
use App\Mail\MailChannels\MessageRejected;
use App\Mail\MailChannels\TemporaryFailure;
use App\Mail\Transport\MailChannelsTransport;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailChannelsTransportTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_platform_mail_leaves_transactional_and_tracking_to_mailchannels(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['results' => []], 202)]);
        config(['mail.mailers.mailchannels.key' => 'platform-key']);

        Mail::mailer('mailchannels')->raw('Hello', fn ($message) => $message->to('a@example.com')->from('app@example.com')->subject('Reset'));

        Http::assertSent(fn ($request) => ! array_key_exists('transactional', $request->data())
            && ! array_key_exists('tracking_settings', $request->data()));
    }

    public function test_congregation_mail_is_signed_by_selector_with_tracking_off(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['results' => [['index' => 0, 'message_id' => 'mc-7', 'status' => 'sent']]], 202)]);
        $team = Team::factory()->readyToSendEmail()->create();

        $sent = CongregationMailer::for($team)->raw('Hello', function ($message) {
            $message->to('a@example.com')->from('office@shul.example')->subject('Weekly');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader(MailChannelsTransport::CAMPAIGN_HEADER, 'weekly 12');
        });

        $this->assertSame('mc-7', $sent->getMessageId());
        Http::assertSent(function ($request) {
            $body = $request->data();
            $personalization = $body['personalizations'][0];

            return $request->hasHeader('X-Api-Key', 'congregation-key')
                && $personalization['dkim_domain'] === 'shul.example'
                && $personalization['dkim_selector'] === 'cm202611'
                && ! array_key_exists('dkim_private_key', $personalization)
                && $body['transactional'] === false
                && $body['tracking_settings']['click_tracking']['enable'] === false
                && $body['tracking_settings']['open_tracking']['enable'] === false
                && $body['campaign_id'] === 'weekly-12'
                && ! array_key_exists('headers', $body);
        });
    }

    public function test_congregation_mailer_needs_the_congregations_own_setup(): void
    {
        Http::fake();
        config(['mail.mailers.mailchannels.key' => 'platform-key']);
        $team = Team::factory()->create();

        $this->expectException(EmailSendingNotReady::class);

        CongregationMailer::for($team);
    }

    #[DataProvider('failures')]
    public function test_failures_are_sorted_by_whose_problem_they_are(int $status, string $body, string $expected): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response($body, $status)]);
        config(['mail.mailers.mailchannels.key' => 'platform-key']);

        try {
            Mail::mailer('mailchannels')->raw('Hello', fn ($message) => $message->to('a@example.com')->from('app@example.com')->subject('Hi'));
            $this->fail('Expected an exception.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expected, $exception);
        }
    }

    /**
     * @return array<string, array{int, string, class-string}>
     */
    public static function failures(): array
    {
        return [
            'wrong key' => [401, '{"errors":["Unauthorized"]}', AccountProblem::class],
            'lockdown' => [403, '{"errors":["Domain Lockdown check failed"]}', AccountProblem::class],
            'quota' => [403, '{"errors":["550 5.7.1 This sender has exceeded their daily limit for email api requests."]}', AccountProblem::class],
            'bad message' => [400, '{"errors":["Invalid email address"]}', MessageRejected::class],
            'too fast' => [400, '{"errors":["550 5.7.1 [STF] Sending too fast."]}', TemporaryFailure::class],
            'outage' => [502, 'Bad Gateway', TemporaryFailure::class],
        ];
    }

    public function test_a_failed_result_in_an_accepted_response_is_a_rejection(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['results' => [['index' => 0, 'status' => 'failed', 'reason' => 'suppressed']]], 202)]);
        config(['mail.mailers.mailchannels.key' => 'platform-key']);

        $this->expectException(MessageRejected::class);

        Mail::mailer('mailchannels')->raw('Hello', fn ($message) => $message->to('a@example.com')->from('app@example.com')->subject('Hi'));
    }
}
