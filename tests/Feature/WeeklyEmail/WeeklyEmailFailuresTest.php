<?php

namespace Tests\Feature\WeeklyEmail;

use App\Enums\TeamRole;
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
