<?php

namespace Tests\Feature\Mail;

use App\Enums\TeamRole;
use App\Mail\EmailSending;
use App\Mail\MailChannels\DeliveryEvent;
use App\Mail\MailChannels\WebhookSignature;
use App\Models\Team;
use App\Models\User;
use App\Modules\Email\Models\EmailDelivery;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MailChannelsWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secretKey;

    private string $publicKeyPem;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        // An ed25519 SubjectPublicKeyInfo: fixed 12-byte header, then the key.
        $spki = hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($pair);
        $this->publicKeyPem = "-----BEGIN PUBLIC KEY-----\n".base64_encode($spki)."\n-----END PUBLIC KEY-----\n";

        Http::fake(['api.mailchannels.net/tx/v1/webhook/public-key*' => Http::response(['id' => 'mckey', 'key' => $this->publicKeyPem])]);
    }

    public function test_a_signed_delivery_event_is_recorded(): void
    {
        $delivery = $this->delivery();

        $this->postSigned([$this->event('delivered', $delivery)])->assertOk();

        $this->assertNotNull($delivery->fresh()->delivered_at);
        $this->assertSame('delivered', $delivery->fresh()->last_event);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'public-key') && $request['id'] === 'mckey');
    }

    public function test_the_unquoted_parameters_form_from_mailchannels_docs_is_accepted(): void
    {
        $delivery = $this->delivery();

        $this->postSigned([$this->event('delivered', $delivery)], quotedParamsInBase: false)->assertOk();

        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_requests_that_are_not_genuine_are_refused(): void
    {
        Event::fake([DeliveryEvent::class]);
        $body = json_encode([['event' => 'delivered', 'request_id' => 'r', 'timestamp' => time(), 'customer_handle' => 'c']]);
        $headers = $this->signedHeaders($body);

        // Body changed after signing.
        $this->call('POST', '/webhooks/mailchannels', [], [], [], $this->server($headers), $body.' ')->assertStatus(401);

        // Signed with another key.
        $other = $this->signedHeaders($body, secretKey: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->call('POST', '/webhooks/mailchannels', [], [], [], $this->server($other), $body)->assertStatus(401);

        // Too old.
        $old = $this->signedHeaders($body, created: time() - 600);
        $this->call('POST', '/webhooks/mailchannels', [], [], [], $this->server($old), $body)->assertStatus(401);

        // Not signed at all.
        $this->call('POST', '/webhooks/mailchannels', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);

        Event::assertNotDispatched(DeliveryEvent::class);
    }

    public function test_a_hard_bounce_takes_the_person_off_the_list(): void
    {
        $delivery = $this->delivery();

        $this->postSigned([$this->event('hard-bounced', $delivery, ['status' => '5.1.1', 'reason' => 'No such user'])])->assertOk();

        $this->assertSame('bounced', $delivery->fresh()->status);
        $recipient = $delivery->recipient->fresh();
        $this->assertSame('bounced', $recipient->status);
        $this->assertStringContainsString('No such user', $recipient->status_reason);
    }

    public function test_a_complaint_takes_the_person_off_the_list_and_outranks_a_later_bounce(): void
    {
        $delivery = $this->delivery();

        $this->postSigned([
            $this->event('complained', $delivery),
            $this->event('hard-bounced', $delivery, ['reason' => 'Mailbox full']),
        ])->assertOk();

        $this->assertSame('complained', $delivery->recipient->fresh()->status);
    }

    public function test_an_unsubscribe_through_mailchannels_unsubscribes(): void
    {
        $delivery = $this->delivery();

        $this->postSigned([$this->event('unsubscribed', $delivery)])->assertOk();

        $this->assertSame('unsubscribed', $delivery->recipient->fresh()->status);
        $this->assertNotNull($delivery->recipient->fresh()->unsubscribed_at);
    }

    public function test_soft_bounces_on_three_runs_in_a_row_make_an_address_bounced(): void
    {
        $recipient = null;

        foreach (range(1, 3) as $week) {
            $delivery = $this->delivery($recipient);
            $recipient = $delivery->recipient;

            // The same event twice counts once.
            $this->postSigned([$this->event('soft-bounced', $delivery, ['reason' => 'Mailbox full'])])->assertOk();
            $this->postSigned([$this->event('soft-bounced', $delivery, ['reason' => 'Mailbox full'])])->assertOk();

            $this->assertSame($week === 3 ? 'bounced' : 'subscribed', $recipient->fresh()->status);
        }

        $this->assertSame(3, $recipient->fresh()->soft_bounce_count);
    }

    public function test_a_delivery_between_soft_bounces_starts_the_count_again(): void
    {
        $first = $this->delivery();
        $this->postSigned([$this->event('soft-bounced', $first)])->assertOk();
        $second = $this->delivery($first->recipient);
        $this->postSigned([$this->event('delivered', $second)])->assertOk();

        $this->assertSame(0, $first->recipient->fresh()->soft_bounce_count);
    }

    public function test_events_for_other_mail_are_ignored(): void
    {
        $delivery = $this->delivery();

        $this->postSigned([
            ['event' => 'hard-bounced', 'request_id' => 'platform-password-reset', 'email' => 'x@example.com', 'timestamp' => time(), 'customer_handle' => 'c'],
            ['event' => 'test', 'timestamp' => time(), 'customer_handle' => 'c'],
        ])->assertOk();

        $this->assertSame('subscribed', $delivery->recipient->fresh()->status);
    }

    public function test_connect_points_the_account_at_the_apps_webhook(): void
    {
        Http::fake(['api.mailchannels.net/tx/v1/webhook?*' => Http::response(null, 204)]);
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.delivery-events', $team->slug))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertTrue(EmailSending::deliveryEventsConnected($team->fresh()));
        // MailChannels refuses a JSON array body, which an empty post sends.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'endpoint='.rawurlencode(route('webhooks.mailchannels')))
            && $request->body() === '{"failure_threshold":5}');
    }

    public function test_a_webhook_created_without_its_failure_threshold_still_connects(): void
    {
        Http::fake(['api.mailchannels.net/tx/v1/webhook?*' => Http::response(null, 201)]);
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.delivery-events', $team->slug))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertTrue(EmailSending::deliveryEventsConnected($team->fresh()));
    }

    public function test_an_account_already_pointing_at_the_app_counts_as_connected(): void
    {
        Http::fake([
            'api.mailchannels.net/tx/v1/webhook?*' => Http::response(null, 409),
            'api.mailchannels.net/tx/v1/webhook' => Http::response([['webhook' => route('webhooks.mailchannels'), 'failure_threshold' => null]]),
        ]);
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.delivery-events', $team->slug))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertTrue(EmailSending::deliveryEventsConnected($team->fresh()));
    }

    public function test_an_account_pointing_elsewhere_says_where(): void
    {
        Http::fake([
            'api.mailchannels.net/tx/v1/webhook?*' => Http::response(null, 409),
            'api.mailchannels.net/tx/v1/webhook' => Http::response([['webhook' => 'https://elsewhere.example/hook']]),
        ]);
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.delivery-events', $team->slug))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertFalse(EmailSending::deliveryEventsConnected($team->fresh()));
        $this->actingAs($owner)
            ->get(route('teams.edit', $team->slug))
            ->assertInertia(fn ($page) => $page
                ->where('emailSending.deliveryEvents.connected', false)
                ->where('emailSending.deliveryEvents.url', 'https://elsewhere.example/hook'));
    }

    public function test_raw_keys_are_read_from_pem_or_plain_base64(): void
    {
        $raw = random_bytes(32);

        $this->assertSame($raw, WebhookSignature::rawKey(base64_encode($raw)));
        $this->assertSame($raw, WebhookSignature::rawKey("-----BEGIN PUBLIC KEY-----\n".base64_encode(hex2bin('302a300506032b6570032100').$raw)."\n-----END PUBLIC KEY-----"));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function postSigned(array $events, bool $quotedParamsInBase = true): TestResponse
    {
        $body = (string) json_encode($events);

        return $this->call('POST', '/webhooks/mailchannels', [], [], [], $this->server($this->signedHeaders($body, quotedParamsInBase: $quotedParamsInBase)), $body);
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $body, ?int $created = null, ?string $secretKey = null, bool $quotedParamsInBase = true): array
    {
        $created ??= time();
        $digest = 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
        $params = '("content-digest");created='.$created.';alg="ed25519";keyid="mckey"';
        $baseParams = $quotedParamsInBase ? $params : '("content-digest");created='.$created.';alg=ed25519;keyid=mckey';
        $base = "\"content-digest\": {$digest}\n\"@signature-params\": {$baseParams}";
        $signature = base64_encode(sodium_crypto_sign_detached($base, $secretKey ?? $this->secretKey));

        return [
            'Content-Digest' => $digest,
            'Signature-Input' => "sig_1738775282={$params}",
            'Signature' => "sig_1738775282=:{$signature}:",
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function event(string $event, EmailDelivery $delivery, array $extra = []): array
    {
        return [
            'event' => $event,
            'request_id' => $delivery->request_id,
            'email' => 'office@shul.example',
            'customer_handle' => 'acct_1',
            'timestamp' => time(),
            ...$extra,
        ];
    }

    private function delivery(?EmailRecipient $recipient = null): EmailDelivery
    {
        $team = $recipient?->team ?? Team::factory()->create();
        $recipient ??= EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $run = EmailRun::query()->create([
            'team_id' => $team->id,
            'scheduled_for' => now()->addMinutes(EmailRun::query()->count()),
            'status' => 'completed',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);

        return $run->deliveries()->create([
            'email_recipient_id' => $recipient->id,
            'scheduled_at' => now(),
            'sent_at' => now(),
            'status' => 'sent',
            'request_id' => 'req-'.uniqid(),
        ])->load('recipient');
    }

    /**
     * @return array{0: Team, 1: User}
     */
    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->forceFill(TeamFactory::emailSendingAttributes())->save();
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->switchTeam($team);

        return [$team, $user];
    }
}
