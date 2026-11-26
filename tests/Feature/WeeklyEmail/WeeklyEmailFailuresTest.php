<?php

namespace Tests\Feature\WeeklyEmail;

use App\Enums\TeamRole;
use App\Mail\EmailSending;
use App\Models\Team;
use App\Models\User;
use App\Modules\WeeklyEmail\Actions\RunState;
use App\Modules\WeeklyEmail\Actions\WeeklyEmailDashboard;
use App\Modules\WeeklyEmail\Jobs\SendWeeklyEmailJob;
use App\Modules\WeeklyEmail\Models\EmailDelivery;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WeeklyEmailFailuresTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'api.mailchannels.net/tx/v1/send';

    private const ACCEPTED = ['results' => [['index' => 0, 'message_id' => 'mc-1', 'status' => 'sent']]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 14:00:00'));
    }

    public function test_a_temporary_failure_waits_and_tries_again_then_gives_up(): void
    {
        Http::fake([self::SEND_URL => Http::response('Bad Gateway', 502)]);
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);

        $this->send($delivery, $team);

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->queued_at);
        $this->assertTrue($delivery->scheduled_at->eq(now()->addMinute()));
        $this->assertStringStartsWith('Will try again:', $delivery->error);

        $this->travel(1)->minutes();
        $this->send($delivery, $team);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertTrue($delivery->fresh()->scheduled_at->eq(now()->addMinutes(5)));

        $this->travel(5)->minutes();
        $this->send($delivery, $team);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(3, $delivery->fresh()->attempts);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_sending_too_fast_moves_the_rest_of_the_run_back(): void
    {
        Http::fake([self::SEND_URL => Http::response(['errors' => ['550 5.7.1 [STF] Sending too fast.']], 400)]);
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);
        $next = $this->delivery($run, now()->addSeconds(10));

        $this->send($delivery, $team);

        $this->assertTrue($next->fresh()->scheduled_at->eq(now()->addSeconds(130)));
        $this->assertNull($next->fresh()->queued_at);
        $this->assertSame('pending', $delivery->fresh()->status);
    }

    public function test_an_account_problem_pauses_the_run_and_leaves_the_rest_pending(): void
    {
        Http::fake([self::SEND_URL => Http::response(['errors' => ['550 5.7.1 This sender has exceeded their daily limit for email api requests.']], 403)]);
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);
        $next = $this->delivery($run, now()->addSeconds(10));
        $next->update(['queued_at' => now()]);

        $this->send($delivery, $team);

        $run->refresh();
        $this->assertSame('paused', $run->status);
        $this->assertStringContainsString('sending limit is used up', $run->pause_reason);
        $this->assertSame(['pending', 'pending'], $run->deliveries()->pluck('status')->all());
        $this->assertNull($next->fresh()->queued_at);

        // A job already queued for the paused run does nothing.
        $this->travel(10)->seconds();
        $this->send($next, $team);
        Http::assertSentCount(1);

        $card = app(WeeklyEmailDashboard::class)->handle($team);
        $this->assertSame('Sending paused', $card['headline']['title']);
        $this->assertSame('Sending the weekly email is paused', $card['attention'][0]['title']);
    }

    public function test_a_broken_setup_pauses_the_run(): void
    {
        Http::fake();
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);
        $team->forceFill(['mailchannels_api_key' => null])->save();

        $this->send($delivery, $team);

        $this->assertSame('paused', $run->fresh()->status);
        $this->assertStringContainsString('API key', $run->fresh()->pause_reason);
        Http::assertNothingSent();
    }

    public function test_a_rejected_message_fails_only_that_email(): void
    {
        Http::fake([self::SEND_URL => Http::response(['errors' => ['Invalid email address']], 400)]);
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);
        $this->delivery($run, now()->addSeconds(10));

        $this->send($delivery, $team);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('sending', $run->fresh()->status);
    }

    public function test_a_suppression_refusal_takes_the_person_off_the_list(): void
    {
        Http::fake([self::SEND_URL => Http::response(['request_id' => 'req-1', 'results' => [['index' => 0, 'status' => 'failed', 'reason' => 'Recipient is on the suppression list']]], 202)]);
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);

        $this->send($delivery, $team);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('suppressed', $delivery->recipient->fresh()->status);
        $this->assertStringContainsString('suppression list', $delivery->recipient->fresh()->status_reason);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_a_sent_email_keeps_the_request_id_delivery_events_carry(): void
    {
        Http::fake([self::SEND_URL => Http::response(['request_id' => 'req-77', ...self::ACCEPTED], 202)]);
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);

        $this->send($delivery, $team);

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame('req-77', $delivery->fresh()->request_id);
        $this->assertSame('mc-1', $delivery->fresh()->message_id);
    }

    public function test_a_job_for_a_delivery_moved_later_waits_for_its_new_time(): void
    {
        Http::fake([self::SEND_URL => Http::response(self::ACCEPTED, 202)]);
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run, now()->addMinutes(2));

        $this->send($delivery, $team);

        $this->assertSame('pending', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_resume_repaces_the_rest_from_now_once_the_account_works(): void
    {
        Queue::fake();
        [$team, $owner] = $this->owner();
        $run = $this->makeRun($team);
        $this->delivery($run);
        $this->delivery($run, now()->addSeconds(10));
        app(RunState::class)->pause($run, 'The MailChannels sending limit is used up.');
        $team->forceFill(['mailchannels_api_key' => null])->save();

        $this->travel(3)->hours();

        $this->actingAs($owner)
            ->post(route('weekly-email.runs.resume', [$team->slug, $run]))
            ->assertInertiaFlash('toast.type', 'error');
        $this->assertSame('paused', $run->fresh()->status);

        $team->forceFill(['mailchannels_api_key' => 'congregation-key'])->save();

        $this->actingAs($owner)
            ->post(route('weekly-email.runs.resume', [$team->slug, $run]))
            ->assertInertiaFlash('toast.type', 'success');

        $run->refresh();
        $this->assertSame('sending', $run->status);
        $this->assertNull($run->pause_reason);
        $times = $run->deliveries()->orderBy('scheduled_at')->pluck('scheduled_at');
        $this->assertTrue($times[0]->eq(now()));
        $this->assertGreaterThanOrEqual(5, $times[0]->diffInSeconds($times[1]));
    }

    public function test_retry_is_refused_while_paused(): void
    {
        [$team, $owner] = $this->owner();
        $run = $this->makeRun($team, 'paused');

        $this->actingAs($owner)
            ->post(route('weekly-email.runs.retry', [$team->slug, $run]))
            ->assertStatus(422);
    }

    public function test_a_run_paused_a_day_past_its_send_time_is_cancelled(): void
    {
        Queue::fake();
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);
        app(RunState::class)->pause($run, 'Wrong key.');

        $this->travel(23)->hours();
        $this->artisan('weekly-email:send-due')->assertSuccessful();
        $this->assertSame('paused', $run->fresh()->status);

        $this->travel(2)->hours();
        $this->artisan('weekly-email:send-due')->assertSuccessful();

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertStringContainsString('Wrong key.', $delivery->fresh()->error);
        Queue::assertNothingPushed();
    }

    public function test_retry_and_the_dashboard_leave_out_people_taken_off_the_list(): void
    {
        Queue::fake();
        [$team, $owner] = $this->owner();
        $run = $this->makeRun($team, 'completed');
        $stillSubscribed = $this->delivery($run);
        $suppressed = $this->delivery($run);
        $bounced = $this->delivery($run);
        $this->delivery($run)->update(['status' => 'sent']);
        $stillSubscribed->update(['status' => 'failed']);
        $suppressed->update(['status' => 'failed']);
        $suppressed->recipient->update(['status' => 'suppressed', 'status_reason' => 'On the suppression list']);
        $bounced->update(['status' => 'bounced']);
        $bounced->recipient->update(['status' => 'bounced', 'status_reason' => 'No such user']);

        $card = app(WeeklyEmailDashboard::class)->handle($team);
        $titles = array_column($card['attention'], 'title');
        $this->assertContains('1 weekly email failed to send', $titles);
        $this->assertContains('2 people were taken off the email list', $titles);
        $removed = collect($card['attention'])->firstWhere('title', '2 people were taken off the email list');
        $this->assertSame('warning', $removed['tone']);
        $this->assertSame('After the last send: 1 bounced, 1 refused by MailChannels. The reasons are on the email list.', $removed['detail']);

        $this->actingAs($owner)
            ->post(route('weekly-email.runs.retry', [$team->slug, $run]))
            ->assertInertiaFlash('toast.message', 'Retrying 1 email.');

        $this->assertSame('pending', $stillSubscribed->fresh()->status);
        $this->assertSame('failed', $suppressed->fresh()->status);
    }

    public function test_retry_with_nobody_left_on_the_list_says_so(): void
    {
        [$team, $owner] = $this->owner();
        $run = $this->makeRun($team, 'completed');
        $delivery = $this->delivery($run);
        $delivery->update(['status' => 'failed']);
        $delivery->recipient->update(['status' => 'suppressed']);

        $this->actingAs($owner)
            ->post(route('weekly-email.runs.retry', [$team->slug, $run]))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertSame('failed', $delivery->fresh()->status);
        $titles = array_column(app(WeeklyEmailDashboard::class)->handle($team)['attention'], 'title');
        $this->assertNotContains('1 weekly email failed to send', $titles);
    }

    public function test_a_spam_complaint_makes_the_taken_off_item_urgent(): void
    {
        [$team] = $this->owner();
        $run = $this->makeRun($team, 'completed');
        $delivery = $this->delivery($run);
        $delivery->update(['status' => 'sent']);
        $delivery->recipient->update(['status' => 'complained']);

        $card = app(WeeklyEmailDashboard::class)->handle($team);

        $this->assertSame('1 person was taken off the email list', $card['attention'][0]['title']);
        $this->assertSame('danger', $card['attention'][0]['tone']);
        $this->assertStringContainsString('1 reported it as spam', $card['attention'][0]['detail']);
    }

    public function test_a_job_for_someone_taken_off_the_list_says_why_it_was_skipped(): void
    {
        Http::fake();
        [$team] = $this->owner();
        $run = $this->makeRun($team);
        $delivery = $this->delivery($run);
        $delivery->recipient->update(['status' => 'bounced']);

        $this->send($delivery, $team);

        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame('The address bounced before this email went out.', $delivery->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_sending_is_rechecked_about_once_a_day_for_scheduled_congregations(): void
    {
        Http::fake(['api.mailchannels.net/tx/v1/check-domain' => Http::response(['check_results' => [
            'dkim' => [['dkim_domain' => 'shul.example', 'dkim_selector' => 'cm202611', 'verdict' => 'passed']],
            'spf' => ['verdict' => 'failed', 'reason' => 'No SPF record'],
            'domain_lockdown' => ['verdict' => 'passed'],
        ]])]);
        [$scheduled] = $this->owner();
        WeeklyEmailSetting::forTeam($scheduled)->update(['enabled' => true, 'send_day' => 5, 'send_time' => '09:00']);
        [$notScheduled] = $this->owner();

        $this->artisan('weekly-email:check-sending')->assertSuccessful();
        Http::assertNothingSent();

        $this->travel(25)->hours();
        $this->artisan('weekly-email:check-sending')->assertSuccessful();
        $this->artisan('weekly-email:check-sending')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->header('X-Api-Key')[0] === 'congregation-key');
        $this->assertFalse(EmailSending::ready($scheduled->fresh()));
        $this->assertTrue($notScheduled->fresh()->domain_checked_at->lt(now()->subDay()));

        $card = app(WeeklyEmailDashboard::class)->handle($scheduled->fresh());
        $this->assertSame('The weekly email cannot send', $card['attention'][0]['title']);
        $this->assertStringContainsString('the email due Friday, Nov 6 will not go out', $card['attention'][0]['detail']);
    }

    private function send(EmailDelivery $delivery, Team $team): void
    {
        $delivery->update(['queued_at' => now()]);
        SendWeeklyEmailJob::dispatchSync($delivery->id, $team->id);
    }

    private function makeRun(Team $team, string $status = 'sending'): EmailRun
    {
        return EmailRun::query()->create([
            'team_id' => $team->id,
            'scheduled_for' => now(),
            'status' => $status,
            'subject' => 'Hello',
            'body' => 'Body',
        ]);
    }

    private function delivery(EmailRun $run, ?CarbonImmutable $at = null): EmailDelivery
    {
        $recipient = EmailRecipient::query()->create([
            'team_id' => $run->team_id,
            'email' => uniqid('person').'@example.com',
            'status' => 'subscribed',
        ]);

        return $run->deliveries()->create([
            'email_recipient_id' => $recipient->id,
            'scheduled_at' => $at ?? now(),
            'status' => 'pending',
        ]);
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
        WeeklyEmailSetting::forTeam($team)->update(['from_address' => 'office@shul.example']);

        return [$team, $user];
    }
}
