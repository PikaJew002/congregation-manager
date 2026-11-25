<?php

namespace Tests\Feature\WeeklyEmail;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\WeeklyEmail\Actions\RenderWeeklyEmail;
use App\Modules\WeeklyEmail\Jobs\SendWeeklyEmailJob;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use App\Support\Modules\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_test_send_goes_to_the_user_without_touching_the_list_or_runs(): void
    {
        config(['mail.mailers.mailchannels' => ['transport' => 'array']]);
        [$team, $owner] = $this->owner();
        WeeklyEmailSetting::forTeam($team)->update(['subject' => 'This week']);

        $this->actingAs($owner)
            ->post(route('weekly-email.template.test', $team->slug))
            ->assertRedirect();

        $messages = Mail::mailer('mailchannels')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame($owner->email, $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertSame('[Test] This week', $messages[0]->getOriginalMessage()->getSubject());
        $this->assertDatabaseCount('email_recipients', 0);
        $this->assertDatabaseCount('email_runs', 0);
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
