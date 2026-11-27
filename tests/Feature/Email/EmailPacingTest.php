<?php

namespace Tests\Feature\Email;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\Email\Actions\EmailDashboard;
use App\Modules\Email\Actions\PaceDeliveries;
use App\Modules\Email\Actions\QueueDueDeliveries;
use App\Modules\Email\Jobs\SendEmailJob;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailDelivery;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailPacingTest extends TestCase
{
    use RefreshDatabase;

    private const SUNDAY_NINE = '2026-11-01 09:00';

    public function test_emails_are_5_to_15_seconds_apart(): void
    {
        $start = CarbonImmutable::parse(self::SUNDAY_NINE);
        $times = app(PaceDeliveries::class)->times($start, 50);

        $this->assertCount(50, $times);
        $this->assertTrue($times[0]->eq($start));

        for ($index = 1; $index < 50; $index++) {
            $gap = $times[$index - 1]->diffInSeconds($times[$index]);
            $this->assertGreaterThanOrEqual(5, $gap);
            $this->assertLessThanOrEqual(15, $gap);
        }

        $this->assertSame(10, PaceDeliveries::estimatedMinutes(60));
        $this->assertSame(0, PaceDeliveries::estimatedMinutes(1));
    }

    public function test_a_run_is_paced_from_the_send_time_with_every_subscriber_once(): void
    {
        Queue::fake();
        $team = $this->readyTeam();
        $this->subscribers($team, 20);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:30', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();

        $deliveries = EmailDelivery::query()->orderBy('scheduled_at')->get();
        $this->assertCount(20, $deliveries);
        $this->assertSame(20, $deliveries->pluck('email_recipient_id')->unique()->count());
        $this->assertTrue($deliveries->first()->scheduled_at->eq(CarbonImmutable::parse('2026-11-01 09:00:30', 'America/New_York')));
        $this->assertTrue($deliveries->every(fn (EmailDelivery $delivery) => $delivery->queued_at === null));
        Queue::assertNothingPushed();
    }

    public function test_recipients_are_not_always_in_the_same_order(): void
    {
        Queue::fake();
        $team = $this->readyTeam();
        $this->subscribers($team, 30);
        $orders = [];

        foreach (['2026-11-01', '2026-11-08', '2026-11-15'] as $sunday) {
            $this->travelTo(CarbonImmutable::parse("{$sunday} 09:01", 'America/New_York'));
            $this->artisan('email:dispatch-due')->assertSuccessful();
            $run = EmailRun::query()->latest('id')->first();
            $orders[] = $run->deliveries()->orderBy('scheduled_at')->pluck('email_recipient_id')->all();
        }

        $this->assertGreaterThan(1, count(array_unique(array_map('serialize', $orders))));
    }

    public function test_a_late_start_is_paced_from_now_and_a_very_late_one_is_skipped(): void
    {
        Queue::fake();
        $team = $this->readyTeam();
        $this->subscribers($team, 3);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:31', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();
        $this->assertDatabaseCount('email_runs', 0);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:20', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 1);
        $first = EmailDelivery::query()->orderBy('scheduled_at')->first();
        $this->assertTrue($first->scheduled_at->eq(CarbonImmutable::parse('2026-11-01 09:20', 'America/New_York')));
    }

    public function test_a_list_over_the_daily_limit_is_not_sent_and_says_why(): void
    {
        Queue::fake();
        $team = $this->readyTeam();
        $team->forceFill(['daily_email_limit' => 2])->save();
        $this->subscribers($team, 3);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:01', 'America/New_York'));
        $this->artisan('email:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('email_runs', 0);
        $card = app(EmailDashboard::class)->handle($team);
        $this->assertSame('The email list is over the daily sending limit', $card['attention'][0]['title']);
    }

    public function test_send_due_queues_only_the_next_minute_and_only_once(): void
    {
        Queue::fake();
        $team = $this->readyTeam();
        $run = $this->makeRun($team);
        $soon = $this->delivery($run, '2026-11-01 09:00:40');
        $later = $this->delivery($run, '2026-11-01 09:05:00');

        $this->travelTo(CarbonImmutable::parse(self::SUNDAY_NINE, 'America/New_York'));
        $this->artisan('email:send-due')->assertSuccessful();
        $this->artisan('email:send-due')->assertSuccessful();

        Queue::assertPushed(SendEmailJob::class, 1);
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->deliveryId === $soon->id
            && $job->teamId === $team->id
            && CarbonImmutable::parse($job->delay)->eq(CarbonImmutable::parse('2026-11-01 09:00:40', 'America/New_York')));
        $this->assertNotNull($soon->fresh()->queued_at);
        $this->assertNull($later->fresh()->queued_at);
        $this->assertSame('sending', $run->fresh()->status);
    }

    public function test_a_stalled_delivery_is_queued_again(): void
    {
        Queue::fake();
        $team = $this->readyTeam();
        $run = $this->makeRun($team, 'sending');
        $delivery = $this->delivery($run, self::SUNDAY_NINE);
        $delivery->update(['queued_at' => CarbonImmutable::parse(self::SUNDAY_NINE, 'America/New_York')->utc()]);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:10', 'America/New_York'));
        app(QueueDueDeliveries::class)->handle();
        Queue::assertNothingPushed();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:16', 'America/New_York'));
        app(QueueDueDeliveries::class)->handle();
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_cancelled_runs_are_not_queued(): void
    {
        Queue::fake();
        $team = $this->readyTeam();
        $run = $this->makeRun($team, 'cancelled');
        $this->delivery($run, self::SUNDAY_NINE);

        $this->travelTo(CarbonImmutable::parse(self::SUNDAY_NINE, 'America/New_York'));
        $this->artisan('email:send-due')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_one_congregations_sends_are_serial_and_capped(): void
    {
        $middleware = (new SendEmailJob(1, 7))->middleware();

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('email-team-7', $middleware[0]->key);
        $this->assertInstanceOf(RateLimited::class, $middleware[1]);
    }

    public function test_someone_who_unsubscribed_after_the_run_started_is_skipped(): void
    {
        Http::fake();
        $team = $this->readyTeam();
        $run = $this->makeRun($team, 'sending');
        $delivery = $this->delivery($run, self::SUNDAY_NINE);
        $delivery->recipient->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        SendEmailJob::dispatchSync($delivery->id, $team->id);

        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_retry_paces_failed_emails_from_now_instead_of_sending_them_at_once(): void
    {
        Queue::fake();
        [$team, $owner] = $this->owner();
        $run = $this->makeRun($team, 'completed');
        foreach (range(1, 3) as $index) {
            $this->delivery($run, self::SUNDAY_NINE)->update(['status' => 'failed', 'error' => 'boom', 'queued_at' => now()]);
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York'));
        $this->actingAs($owner)
            ->post(route('email.runs.retry', [$team->slug, $run]))
            ->assertRedirect();

        Queue::assertNothingPushed();
        $this->assertSame('sending', $run->fresh()->status);
        $times = $run->deliveries()->orderBy('scheduled_at')->get();
        $this->assertTrue($times->every(fn (EmailDelivery $delivery) => $delivery->status === 'pending' && $delivery->queued_at === null));
        $this->assertTrue($times->first()->scheduled_at->eq(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York')));
        $this->assertGreaterThanOrEqual(5, $times[0]->scheduled_at->diffInSeconds($times[1]->scheduled_at));
    }

    public function test_the_editor_says_how_long_the_send_takes(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->withoutVite();
        [$team, $owner] = $this->owner();
        $this->subscribers($team, 61);

        $this->actingAs($owner)
            ->get(route('email.create', $team->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscribers', 61)
                ->where('estimatedMinutes', 10)
                ->where('dailyEmailLimit', 100));
    }

    private function readyTeam(): Team
    {
        [$team] = $this->owner();
        $team->forceFill(TeamFactory::emailSendingAttributes())->save();
        $team->forceFill(['domain_checked_at' => CarbonImmutable::parse('2026-12-01')])->save();
        Email::query()->create([
            'team_id' => $team->id,
            'name' => 'Weekly email',
            'status' => 'scheduled',
            'send_mode' => 'recurring',
            'subject' => 'This week on Zoom',
            'body' => 'Hello {{name}}',
            'send_days' => [CarbonImmutable::parse('2026-11-01')->dayOfWeek],
            'send_time' => '09:00',
        ]);

        return $team;
    }

    private function subscribers(Team $team, int $count): void
    {
        foreach (range(1, $count) as $index) {
            EmailRecipient::query()->create(['team_id' => $team->id, 'email' => "person{$index}@example.com", 'status' => 'subscribed']);
        }
    }

    private function makeRun(Team $team, string $status = 'scheduled'): EmailRun
    {
        return EmailRun::query()->create([
            'team_id' => $team->id,
            'scheduled_for' => CarbonImmutable::parse(self::SUNDAY_NINE, 'America/New_York')->utc(),
            'status' => $status,
            'subject' => 'Hello',
            'body' => 'Body',
        ]);
    }

    private function delivery(EmailRun $run, string $at): EmailDelivery
    {
        $recipient = EmailRecipient::query()->create([
            'team_id' => $run->team_id,
            'email' => uniqid('person').'@example.com',
            'status' => 'subscribed',
        ]);

        return $run->deliveries()->create([
            'email_recipient_id' => $recipient->id,
            'scheduled_at' => CarbonImmutable::parse($at, 'America/New_York')->utc(),
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
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->switchTeam($team);

        return [$team, $user];
    }
}
