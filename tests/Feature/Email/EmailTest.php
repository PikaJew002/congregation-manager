<?php

namespace Tests\Feature\Email;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\Email\Actions\EmailDashboard;
use App\Modules\Email\Actions\RenderEmail;
use App\Modules\Email\Jobs\SendEmailJob;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use App\Modules\Email\Models\EmailSetting;
use App\Support\Modules\ModuleAccess;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_without_a_grant_cannot_open_the_list(): void
    {
        [$team, $member] = $this->member();

        $this->actingAs($member)
            ->get(route('email.recipients', ['current_team' => $team->slug]))
            ->assertForbidden();
    }

    public function test_a_disabled_module_is_not_found(): void
    {
        [$team, $owner] = $this->owner();
        app(ModuleAccess::class)->setEnabled($team, 'email', false);

        $this->actingAs($owner)
            ->get(route('email.recipients', ['current_team' => $team->slug]))
            ->assertNotFound();
    }

    public function test_owner_can_open_each_page(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->withoutVite();
        [$team, $owner] = $this->owner();
        $recipient = EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $email = $this->sundayNine($team);
        $run = EmailRun::query()->create([
            'team_id' => $team->id,
            'email_id' => $email->id,
            'scheduled_for' => now(),
            'status' => 'scheduled',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);

        $pages = [
            'Emails' => [route('email.index', $team->slug), 'emails'],
            'Edit' => [route('email.create', $team->slug), 'email'],
            'Recipients' => [route('email.recipients', $team->slug), 'recipients'],
            'Settings' => [route('email.settings', $team->slug), 'settings'],
            'Runs' => [route('email.runs', $team->slug), 'runs'],
            'Run' => [route('email.runs.show', [$team->slug, $run]), 'deliveries'],
        ];

        foreach ($pages as $component => [$url, $prop]) {
            $this->assertFileExists(resource_path("js/modules/email/pages/{$component}.vue"));

            $this->actingAs($owner)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component("email/{$component}")->has($prop));
        }

        $this->actingAs($owner)
            ->get(route('email.edit', [$team->slug, $email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('email/Edit')
                ->where('email.id', $email->id)
                ->where('email.send_days', [0])
                ->has('runs', 1));

        $this->assertFileExists(resource_path('js/modules/email/pages/Unsubscribe.vue'));

        $this->get(route('email.unsubscribe', $recipient->unsubscribe_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('email/Unsubscribe')
                ->where('email', 'a@example.com')
                ->where('unsubscribed', false));
    }

    public function test_old_weekly_email_links_redirect(): void
    {
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)->get("/{$team->slug}/weekly-email/schedule")->assertRedirect("/{$team->slug}/email");
        $this->actingAs($owner)->get("/{$team->slug}/weekly-email/recipients")->assertRedirect("/{$team->slug}/email/recipients");
        $this->actingAs($owner)->get("/{$team->slug}/weekly-email/runs/12")->assertRedirect("/{$team->slug}/email/runs/12");
    }

    public function test_test_send_goes_to_the_user_through_the_congregations_account(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['results' => [['index' => 0, 'message_id' => 'mc-1', 'status' => 'sent']]], 202)]);
        [$team, $owner] = $this->owner();
        $this->readyToSend($team);
        EmailSetting::forTeam($team)->update(['from_address' => 'office@shul.example', 'variables' => [['key' => 'topic', 'value' => 'Shabbat']]]);
        $email = $this->sundayNine($team, ['subject' => 'This week: {{topic}}']);

        $this->actingAs($owner)
            ->post(route('email.test', [$team->slug, $email]))
            ->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'congregation-key')
            && $request['personalizations'][0]['to'][0]['email'] === $owner->email
            && $request['subject'] === '[Test] This week: Shabbat'
            && $request['campaign_id'] === 'test');
        $this->assertDatabaseCount('email_recipients', 0);
        $this->assertDatabaseCount('email_runs', 0);
    }

    public function test_test_send_says_what_is_missing_before_email_is_set_up(): void
    {
        Http::fake();
        [$team, $owner] = $this->owner();
        $email = $this->sundayNine($team, ['status' => 'draft']);

        $this->actingAs($owner)
            ->post(route('email.test', [$team->slug, $email]))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    }

    public function test_a_sent_delivery_keeps_the_mailchannels_message_id(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['results' => [['index' => 0, 'message_id' => 'mc-42', 'status' => 'sent']]], 202)]);
        [$team] = $this->owner();
        $this->readyToSend($team);
        EmailSetting::forTeam($team)->update(['from_address' => 'office@shul.example']);
        $recipient = EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $run = EmailRun::query()->create([
            'team_id' => $team->id,
            'scheduled_for' => now(),
            'status' => 'sending',
            'subject' => 'Hello {{name}}',
            'body' => 'Body',
        ]);
        $delivery = $run->deliveries()->create(['email_recipient_id' => $recipient->id, 'scheduled_at' => now(), 'status' => 'pending']);

        SendEmailJob::dispatchSync($delivery->id);

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame('mc-42', $delivery->fresh()->message_id);
        Http::assertSent(fn ($request) => $request['campaign_id'] === 'email-run-'.$run->id
            && $request['subject'] === 'Hello friend'
            && $request['transactional'] === false
            && $request['headers']['List-Unsubscribe'] === '<'.$recipient->unsubscribeUrl().'>');
    }

    public function test_the_from_address_must_be_on_the_sending_domain(): void
    {
        [$team, $owner] = $this->owner();
        $this->readyToSend($team);
        $sender = ['from_name' => 'Shul', 'variables' => []];

        $this->actingAs($owner)
            ->put(route('email.settings.update', $team->slug), [...$sender, 'from_address' => 'office@elsewhere.example'])
            ->assertSessionHasErrors('from_address');

        $this->actingAs($owner)
            ->put(route('email.settings.update', $team->slug), [...$sender, 'from_address' => 'office@shul.example'])
            ->assertSessionHasNoErrors();
    }

    public function test_unsubscribe_link_only_appears_where_the_template_places_it(): void
    {
        [$team] = $this->owner();
        $recipient = EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $renderer = app(RenderEmail::class);

        $this->assertStringNotContainsString($recipient->unsubscribe_token, $renderer->personalize('Hi', 'Hello {{name}}', $recipient)['text']);

        $text = $renderer->personalize('Hi', 'Hello {{name}}. Leave: {{unsubscribe_url}}', $recipient)['text'];
        $this->assertSame(1, substr_count($text, $recipient->unsubscribeUrl()));
    }

    public function test_run_completes_when_its_last_delivery_fails(): void
    {
        [$team] = $this->owner();
        $run = EmailRun::query()->create([
            'team_id' => $team->id,
            'scheduled_for' => now(),
            'status' => 'sending',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);
        $delivery = $run->deliveries()->create(['email_recipient_id' => null, 'scheduled_at' => now(), 'status' => 'pending']);

        SendEmailJob::dispatchSync($delivery->id);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_preview_renders_unsaved_email_with_its_variables(): void
    {
        [$team, $owner] = $this->owner();
        EmailSetting::forTeam($team)->update(['variables' => [['key' => 'zoom_url', 'value' => 'https://zoom.example/j/1']]]);

        $this->actingAs($owner)
            ->postJson(route('email.preview', $team->slug), [
                'subject' => 'Hi {{name}}',
                'body' => "**Join** {{zoom_url}}\nPasscode: {{passcode}}",
                'variables' => [['key' => 'passcode', 'value' => '777']],
            ])
            ->assertOk()
            ->assertJsonPath('subject', 'Hi '.$owner->name)
            ->assertJsonPath('problems', [])
            ->assertJsonPath('html', "<p><strong>Join</strong> <a href=\"https://zoom.example/j/1\">https://zoom.example/j/1</a><br />\nPasscode: 777</p>\n");

        $this->assertDatabaseCount('emails', 0);
    }

    public function test_dispatch_creates_one_delivery_per_subscribed_recipient(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $this->readyToSend($team);
        $this->sundayNine($team);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'b@example.com', 'status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));

        $this->artisan('email:dispatch-due')->assertSuccessful();
        $this->artisan('email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 1);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_the_send_time_is_in_the_congregations_time_zone(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $team->update(['timezone' => 'America/Los_Angeles']);
        $this->readyToSend($team);
        $this->sundayNine($team);

        // 9:05 in New York is 6:05 in Los Angeles: not yet.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();
        $this->assertDatabaseCount('email_runs', 0);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/Los_Angeles'));
        $this->artisan('email:dispatch-due')->assertSuccessful();
        $this->assertDatabaseCount('email_runs', 1);
    }

    public function test_dispatch_skips_a_congregation_that_cannot_send(): void
    {
        Http::fake();
        [$team] = $this->owner();
        $this->sundayNine($team);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 0);
        Http::assertNothingSent();
    }

    public function test_dispatch_rechecks_a_day_old_domain_check_and_skips_when_it_now_fails(): void
    {
        Http::fake(['api.mailchannels.net/tx/v1/check-domain' => Http::response(['check_results' => [
            'dkim' => [['dkim_domain' => 'shul.example', 'dkim_selector' => 'cm202611', 'verdict' => 'passed']],
            'spf' => ['verdict' => 'failed', 'reason' => 'No SPF record'],
            'domain_lockdown' => ['verdict' => 'passed'],
        ]])]);
        [$team] = $this->owner();
        $this->readyToSend($team);
        $this->sundayNine($team);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $team->forceFill(['domain_checked_at' => CarbonImmutable::parse('2026-10-30 09:00', 'America/New_York')])->save();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();
        $this->artisan('email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 0);
        Http::assertSentCount(1);
        $this->assertSame('failed', $team->fresh()->domain_check['spf']['verdict']);
    }

    public function test_dispatch_uses_a_fresh_passing_check_without_asking_mailchannels(): void
    {
        Http::fake();
        Mail::fake();
        [$team] = $this->owner();
        $this->readyToSend($team);
        $this->sundayNine($team);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $team->forceFill(['domain_checked_at' => CarbonImmutable::parse('2026-11-01 07:00', 'America/New_York')])->save();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'check-domain'));
    }

    public function test_an_email_cannot_be_scheduled_until_sending_is_set_up(): void
    {
        [$team, $owner] = $this->owner();
        $email = [
            'name' => 'Shabbat invite',
            'send_mode' => 'recurring',
            'status' => 'scheduled',
            'subject' => 'Shabbat',
            'body' => 'Join us',
            'send_days' => [5],
            'send_time' => '09:00',
        ];

        $this->actingAs($owner)
            ->post(route('email.store', $team->slug), $email)
            ->assertSessionHasErrors('status');
        $this->assertDatabaseCount('emails', 0);

        $this->actingAs($owner)
            ->post(route('email.store', $team->slug), [...$email, 'status' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->readyToSend($team);
        $saved = Email::query()->sole();

        $this->actingAs($owner)
            ->put(route('email.update', [$team->slug, $saved]), $email)
            ->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $saved->fresh()->status);
    }

    public function test_the_dashboard_says_when_a_scheduled_email_cannot_send(): void
    {
        [$team] = $this->owner();
        $this->sundayNine($team);

        $card = app(EmailDashboard::class)->handle($team);

        $this->assertSame('Scheduled emails cannot send', $card['attention'][0]['title']);
        $this->assertStringContainsString('MailChannels API key', $card['attention'][0]['detail']);
    }

    public function test_someone_who_complained_is_not_put_back_on_the_list(): void
    {
        [$team, $owner] = $this->owner();
        $recipient = EmailRecipient::query()->create([
            'team_id' => $team->id,
            'email' => 'a@example.com',
            'status' => 'complained',
            'status_reason' => 'Reported an email as spam.',
        ]);

        $this->actingAs($owner)
            ->patch(route('email.recipients.update', [$team->slug, $recipient]), ['name' => null, 'status' => 'subscribed'])
            ->assertSessionHasErrors('status');

        $this->actingAs($owner)
            ->post(route('email.recipients.store', $team->slug), ['entries' => "a@example.com, Ann\nb@example.com"])
            ->assertRedirect();

        $this->post(route('email.unsubscribe.store', $recipient->unsubscribe_token));

        $this->assertSame('complained', $recipient->fresh()->status);
        $this->assertSame('Ann', $recipient->fresh()->name);
        $this->assertSame('subscribed', EmailRecipient::query()->where('email', 'b@example.com')->value('status'));
    }

    public function test_pasted_recipients_can_use_any_line_ending(): void
    {
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('email.recipients.store', $team->slug), ['entries' => "a@example.com, Ann\r\nb@example.com\rc@example.com\n"])
            ->assertRedirect();

        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            EmailRecipient::query()->forTeam($team)->orderBy('email')->pluck('email')->all(),
        );
    }

    public function test_a_bounced_address_can_be_resubscribed(): void
    {
        [$team, $owner] = $this->owner();
        $recipient = EmailRecipient::query()->create([
            'team_id' => $team->id,
            'email' => 'a@example.com',
            'status' => 'bounced',
            'status_reason' => 'The address bounced.',
            'soft_bounce_count' => 3,
        ]);

        $this->actingAs($owner)
            ->patch(route('email.recipients.update', [$team->slug, $recipient]), ['name' => null, 'status' => 'subscribed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('subscribed', $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->status_reason);
        $this->assertSame(0, $recipient->fresh()->soft_bounce_count);
    }

    public function test_unsubscribe_link_opts_a_recipient_out(): void
    {
        [$team] = $this->owner();
        $recipient = EmailRecipient::query()->create([
            'team_id' => $team->id,
            'email' => 'a@example.com',
            'status' => 'subscribed',
        ]);

        $this->post(route('email.unsubscribe.store', $recipient->unsubscribe_token))
            ->assertRedirect();

        $this->assertSame('unsubscribed', $recipient->fresh()->status);
    }

    /**
     * A recurring email that goes out Sundays at 9:00.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sundayNine(Team $team, array $attributes = []): Email
    {
        return Email::query()->create([
            'team_id' => $team->id,
            'name' => 'Weekly email',
            'status' => 'scheduled',
            'send_mode' => 'recurring',
            'subject' => 'This week on Zoom',
            'body' => 'Hello {{name}}',
            'send_days' => [0],
            'send_time' => '09:00',
            ...$attributes,
        ]);
    }

    private function readyToSend(Team $team): void
    {
        $team->forceFill(TeamFactory::emailSendingAttributes())->save();
    }

    /**
     * @return array{0: Team, 1: User}
     */
    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->switchTeam($team);

        return [$team, $user];
    }

    /**
     * @return array{0: Team, 1: User}
     */
    private function member(): array
    {
        [$team] = $this->owner();
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        return [$team, $member];
    }
}
