<?php

namespace Tests\Feature\WeeklyEmail;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\WeeklyEmail\Actions\RenderWeeklyEmail;
use App\Modules\WeeklyEmail\Actions\WeeklyEmailDashboard;
use App\Modules\WeeklyEmail\Jobs\SendWeeklyEmailJob;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use App\Support\Modules\ModuleAccess;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WeeklyEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_without_a_grant_cannot_open_the_list(): void
    {
        [$team, $member] = $this->member();

        $this->actingAs($member)
            ->get(route('weekly-email.recipients', ['current_team' => $team->slug]))
            ->assertForbidden();
    }

    public function test_a_disabled_module_is_not_found(): void
    {
        [$team, $owner] = $this->owner();
        app(ModuleAccess::class)->setEnabled($team, 'weekly-email', false);

        $this->actingAs($owner)
            ->get(route('weekly-email.recipients', ['current_team' => $team->slug]))
            ->assertNotFound();
    }

    public function test_owner_can_open_each_page(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        [$team, $owner] = $this->owner();
        $recipient = EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $run = EmailRun::query()->create([
            'team_id' => $team->id,
            'scheduled_for' => now(),
            'status' => 'scheduled',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);

        $pages = [
            'Recipients' => [route('weekly-email.recipients', $team->slug), 'recipients'],
            'Template' => [route('weekly-email.template', $team->slug), 'settings'],
            'Schedule' => [route('weekly-email.schedule', $team->slug), 'settings'],
            'Runs' => [route('weekly-email.runs', $team->slug), 'runs'],
            'Run' => [route('weekly-email.runs.show', [$team->slug, $run]), 'deliveries'],
        ];

        foreach ($pages as $component => [$url, $prop]) {
            $this->assertFileExists(resource_path("js/modules/weekly-email/pages/{$component}.vue"));

            $this->actingAs($owner)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component("weekly-email/{$component}")->has($prop));
        }

        $this->assertFileExists(resource_path('js/modules/weekly-email/pages/Unsubscribe.vue'));

        $this->get(route('weekly-email.unsubscribe', $recipient->unsubscribe_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('weekly-email/Unsubscribe')
                ->where('email', 'a@example.com')
                ->where('unsubscribed', false));
    }

    public function test_first_visit_to_the_schedule_includes_column_defaults(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->get(route('weekly-email.schedule', $team->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.send_time', '09:00')
                ->where('settings.window_minutes', 150)
                ->where('settings.subject', 'This week on Zoom'));
    }

    public function test_test_send_goes_to_the_user_through_the_congregations_account(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['results' => [['index' => 0, 'message_id' => 'mc-1', 'status' => 'sent']]], 202)]);
        [$team, $owner] = $this->owner();
        $this->readyToSend($team);
        WeeklyEmailSetting::forTeam($team)->update(['subject' => 'This week', 'from_address' => 'office@shul.example']);

        $this->actingAs($owner)
            ->post(route('weekly-email.template.test', $team->slug))
            ->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'congregation-key')
            && $request['personalizations'][0]['to'][0]['email'] === $owner->email
            && $request['subject'] === '[Test] This week'
            && $request['campaign_id'] === 'test');
        $this->assertDatabaseCount('email_recipients', 0);
        $this->assertDatabaseCount('email_runs', 0);
    }

    public function test_test_send_says_what_is_missing_before_email_is_set_up(): void
    {
        Http::fake();
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('weekly-email.template.test', $team->slug))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    }

    public function test_a_sent_delivery_keeps_the_mailchannels_message_id(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['results' => [['index' => 0, 'message_id' => 'mc-42', 'status' => 'sent']]], 202)]);
        [$team] = $this->owner();
        $this->readyToSend($team);
        WeeklyEmailSetting::forTeam($team)->update(['from_address' => 'office@shul.example']);
        $recipient = EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $run = EmailRun::query()->create([
            'team_id' => $team->id,
            'scheduled_for' => now(),
            'status' => 'sending',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);
        $delivery = $run->deliveries()->create(['email_recipient_id' => $recipient->id, 'scheduled_at' => now(), 'status' => 'pending']);

        SendWeeklyEmailJob::dispatchSync($delivery->id);

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame('mc-42', $delivery->fresh()->message_id);
        Http::assertSent(fn ($request) => $request['campaign_id'] === 'weekly-'.$run->id
            && $request['transactional'] === false
            && $request['headers']['List-Unsubscribe'] === '<'.$recipient->unsubscribeUrl().'>');
    }

    public function test_the_from_address_must_be_on_the_sending_domain(): void
    {
        [$team, $owner] = $this->owner();
        $this->readyToSend($team);
        $template = ['subject' => 'Hi', 'body' => 'Body', 'from_name' => 'Shul'];

        $this->actingAs($owner)
            ->put(route('weekly-email.template.update', $team->slug), [...$template, 'from_address' => 'office@elsewhere.example'])
            ->assertSessionHasErrors('from_address');

        $this->actingAs($owner)
            ->put(route('weekly-email.template.update', $team->slug), [...$template, 'from_address' => 'office@shul.example'])
            ->assertSessionHasNoErrors();
    }

    public function test_unsubscribe_link_only_appears_where_the_template_places_it(): void
    {
        [$team] = $this->owner();
        $recipient = EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $settings = WeeklyEmailSetting::forTeam($team);
        $renderer = app(RenderWeeklyEmail::class);

        $settings->body = 'Hello {{name}}';
        $this->assertStringNotContainsString($recipient->unsubscribe_token, $renderer->handle($settings, $recipient)['text']);

        $settings->body = 'Hello {{name}}. Leave: {{unsubscribe_url}}';
        $text = $renderer->handle($settings, $recipient)['text'];
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

        SendWeeklyEmailJob::dispatchSync($delivery->id);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_preview_renders_unsaved_template(): void
    {
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->postJson(route('weekly-email.template.preview', $team->slug), [
                'subject' => 'Hi {{name}}',
                'body' => '**Join** {{zoom_link}}',
                'zoom_link' => 'https://zoom.example/j/1',
            ])
            ->assertOk()
            ->assertJsonPath('subject', 'Hi '.$owner->name)
            ->assertJsonPath('html', "<p><strong>Join</strong> <a href=\"https://zoom.example/j/1\">https://zoom.example/j/1</a></p>\n");

        $this->assertDatabaseCount('weekly_email_settings', 0);
    }

    public function test_dispatch_spreads_one_job_per_subscribed_recipient(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $this->readyToSend($team);
        WeeklyEmailSetting::forTeam($team)->update([
            'enabled' => true,
            'send_day' => CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York')->dayOfWeek,
            'send_time' => '09:00',
            'window_minutes' => 120,
            'zoom_link' => 'https://zoom.example/j/1',
        ]);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'b@example.com', 'status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));

        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();
        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 1);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_the_send_time_is_in_the_congregations_time_zone(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $team->update(['timezone' => 'America/Los_Angeles']);
        $this->readyToSend($team);
        WeeklyEmailSetting::forTeam($team)->update([
            'enabled' => true,
            'send_day' => CarbonImmutable::parse('2026-11-01', 'America/Los_Angeles')->dayOfWeek,
            'send_time' => '09:00',
            'window_minutes' => 60,
        ]);

        // 9:05 in New York is 6:05 in Los Angeles: not yet.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();
        $this->assertDatabaseCount('email_runs', 0);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/Los_Angeles'));
        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();
        $this->assertDatabaseCount('email_runs', 1);
    }

    public function test_dispatch_skips_a_congregation_that_cannot_send(): void
    {
        Http::fake();
        [$team] = $this->owner();
        $this->enableSundayNine($team);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();

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
        $this->enableSundayNine($team);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $team->forceFill(['domain_checked_at' => CarbonImmutable::parse('2026-10-30 09:00', 'America/New_York')])->save();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();
        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();

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
        $this->enableSundayNine($team);
        EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'status' => 'subscribed']);
        $team->forceFill(['domain_checked_at' => CarbonImmutable::parse('2026-11-01 07:00', 'America/New_York')])->save();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'America/New_York'));
        $this->artisan('weekly-email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'check-domain'));
    }

    public function test_the_schedule_cannot_be_turned_on_until_sending_is_set_up(): void
    {
        [$team, $owner] = $this->owner();
        $schedule = ['enabled' => true, 'send_day' => 0, 'send_time' => '09:00', 'window_minutes' => 60];

        $this->actingAs($owner)
            ->put(route('weekly-email.schedule.update', $team->slug), $schedule)
            ->assertSessionHasErrors('enabled');
        $this->assertFalse(WeeklyEmailSetting::forTeam($team)->enabled);

        $this->actingAs($owner)
            ->put(route('weekly-email.schedule.update', $team->slug), [...$schedule, 'enabled' => false])
            ->assertSessionHasNoErrors();

        $this->readyToSend($team);

        $this->actingAs($owner)
            ->put(route('weekly-email.schedule.update', $team->slug), $schedule)
            ->assertSessionHasNoErrors();
        $this->assertTrue(WeeklyEmailSetting::forTeam($team)->enabled);
    }

    public function test_the_dashboard_says_when_a_scheduled_email_cannot_send(): void
    {
        [$team] = $this->owner();
        WeeklyEmailSetting::forTeam($team)->update(['enabled' => true]);

        $card = app(WeeklyEmailDashboard::class)->handle($team);

        $this->assertSame('The weekly email cannot send', $card['attention'][0]['title']);
        $this->assertStringContainsString('MailChannels API key', $card['attention'][0]['detail']);
    }

    public function test_unsubscribe_link_opts_a_recipient_out(): void
    {
        [$team] = $this->owner();
        $recipient = EmailRecipient::query()->create([
            'team_id' => $team->id,
            'email' => 'a@example.com',
            'status' => 'subscribed',
        ]);

        $this->post(route('weekly-email.unsubscribe.store', $recipient->unsubscribe_token))
            ->assertRedirect();

        $this->assertSame('unsubscribed', $recipient->fresh()->status);
    }

    private function enableSundayNine(Team $team): void
    {
        WeeklyEmailSetting::forTeam($team)->update([
            'enabled' => true,
            'send_day' => CarbonImmutable::parse('2026-11-01', 'America/New_York')->dayOfWeek,
            'send_time' => '09:00',
            'window_minutes' => 60,
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
