<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\Email\Actions\EmailDashboard;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use App\Modules\ServiceSlides\Models\Service;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Support\Modules\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardCardsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, Nov 25, 2026, 10:00 in New York.
        $this->travelTo(CarbonImmutable::parse('2026-11-25 15:00:00', 'UTC'));

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create(['timezone' => 'America/New_York']);
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    }

    public function test_an_owner_sees_a_card_for_every_enabled_module(): void
    {
        $this->dashboard($this->owner)->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('cards', 3)
            ->where('cards', fn ($cards) => collect($cards)->pluck('key')->sort()->values()->all() === ['email', 'service-slides', 'zoom-recordings']),
        );
    }

    public function test_a_member_only_sees_cards_for_modules_they_were_granted(): void
    {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $this->dashboard($member)->assertInertia(fn (Assert $page) => $page->has('cards', 0));

        app(ModuleAccess::class)->grant($this->team, $member, 'email');

        $this->dashboard($member)->assertInertia(fn (Assert $page) => $page
            ->has('cards', 1)
            ->where('cards.0.key', 'email'));
    }

    public function test_a_module_turned_off_for_the_congregation_has_no_card(): void
    {
        app(ModuleAccess::class)->setEnabled($this->team, 'zoom-recordings', false);

        $this->dashboard($this->owner)->assertInertia(fn (Assert $page) => $page
            ->has('cards', 2)
            ->where('cards', fn ($cards) => ! collect($cards)->contains('key', 'zoom-recordings')));
    }

    public function test_the_recordings_card_shows_the_latest_recording_and_failures(): void
    {
        $this->recording('a', 'completed', '2026-11-14 15:00:00', ['speaker' => 'Rabbi Levi']);
        $this->recording('b', 'completed', '2026-11-21 15:00:00', ['speaker' => 'Rabbi Cohen', 'torah_portion' => 'Vayetzei']);
        $this->recording('c', 'transcoding', '2026-11-22 15:00:00');
        $this->recording('d', 'failed', '2026-11-23 15:00:00', ['failed_step' => 'download']);
        $this->recording('e', 'completed', '2026-11-24 15:00:00', ['archived_at' => now()]);

        $card = $this->card('zoom-recordings');

        $this->assertSame('Shabbat Morning Service - Nov 21, 2026', $card['headline']['title']);
        $this->assertSame('Rabbi Cohen · Vayetzei', $card['headline']['detail']);
        $this->assertSame(['2', '1', '1'], array_column($card['stats'], 'value'));
        $this->assertCount(1, $card['attention']);
        $this->assertSame('danger', $card['attention'][0]['tone']);
        $this->assertStringContainsString('downloading from Zoom', $card['attention'][0]['detail']);
    }

    public function test_the_slides_card_shows_how_ready_the_next_service_is(): void
    {
        $template = SlideTemplate::query()->create(['team_id' => $this->team->id, 'name' => 'Music', 'file_path' => 'templates/music.odp']);

        $past = $this->service('2026-11-21', 'Last Shabbat');
        $past->decks()->create(['slide_template_id' => $template->id, 'name' => 'Music', 'status' => 'draft']);

        $next = $this->service('2026-11-28', 'Shabbat Vayishlach');
        $next->decks()->create(['slide_template_id' => $template->id, 'name' => 'Music', 'status' => 'ready', 'stale' => false, 'generated_path' => 'decks/1.odp', 'generated_at' => now()]);
        $next->decks()->create(['slide_template_id' => $template->id, 'name' => 'Liturgy', 'status' => 'draft']);

        $later = $this->service('2026-12-05', 'Shabbat Vayeshev');
        $later->decks()->create(['slide_template_id' => $template->id, 'name' => 'Music', 'status' => 'ready', 'stale' => false, 'blocked' => true, 'generated_path' => 'decks/2.odp']);

        $card = $this->card('service-slides');

        $this->assertSame('Shabbat Vayishlach', $card['headline']['title']);
        $this->assertSame(['label' => '1 of 2 ready', 'tone' => 'warning'], $card['headline']['status']);
        $this->assertSame('1 of 2', $card['stats'][0]['value']);
        $this->assertSame(['Shabbat Vayishlach', 'Shabbat Vayeshev'], array_column($card['upcoming']['items'], 'title'));
        $this->assertSame('Needs fixing', $card['upcoming']['items'][1]['status']['label']);
        $this->assertSame(['danger', 'warning'], array_column($card['attention'], 'tone'));
        $this->assertCount(1, $card['activity']);
    }

    public function test_the_email_card_shows_the_next_send_and_failed_deliveries(): void
    {
        $this->email(['name' => 'Shabbat invite', 'send_days' => [5]]);
        $this->email(['name' => 'Sunday school', 'send_days' => [0]]);
        $ann = EmailRecipient::query()->create(['team_id' => $this->team->id, 'email' => 'ann@example.com']);
        EmailRecipient::query()->create(['team_id' => $this->team->id, 'email' => 'bo@example.com', 'status' => 'unsubscribed', 'unsubscribed_at' => now()->subDays(3)]);

        $run = EmailRun::query()->create(['team_id' => $this->team->id, 'scheduled_for' => '2026-11-20 14:00:00', 'status' => 'completed', 'subject' => 'Shabbat on Zoom', 'body' => 'Hi']);
        $run->deliveries()->create(['email_recipient_id' => $ann->id, 'scheduled_at' => '2026-11-20 14:00:00', 'status' => 'sent']);
        $cy = EmailRecipient::query()->create(['team_id' => $this->team->id, 'email' => 'cy@example.com']);
        $run->deliveries()->create(['email_recipient_id' => $cy->id, 'scheduled_at' => '2026-11-20 14:01:00', 'status' => 'failed']);

        $card = $this->card('email');

        $this->assertSame('Next email Friday, Nov 27 at 9:00 AM', $card['headline']['title']);
        $this->assertSame('Shabbat invite', $card['headline']['detail']);
        $this->assertSame(['2', '1', '1 of 2'], array_column($card['stats'], 'value'));
        $this->assertSame('1 email failed to send', $card['attention'][0]['title']);
    }

    public function test_the_email_card_does_not_create_settings(): void
    {
        $card = $this->card('email');

        $this->assertSame('Not scheduled', $card['headline']['title']);
        $this->assertDatabaseMissing('email_settings', ['team_id' => $this->team->id]);
    }

    public function test_the_next_send_moves_to_next_week_once_todays_time_has_passed(): void
    {
        $email = new Email(['send_mode' => 'recurring', 'send_days' => [3], 'send_time' => '09:00']);
        $dashboard = app(EmailDashboard::class);

        $this->assertSame('2026-12-02 09:00', $dashboard->nextSend([$email], $this->team)[1]->format('Y-m-d H:i'));

        $email->send_time = '11:00';
        $this->assertSame('2026-11-25 11:00', $dashboard->nextSend([$email], $this->team)[1]->format('Y-m-d H:i'));
    }

    /**
     * A scheduled recurring email at 9:00.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function email(array $attributes): Email
    {
        return Email::query()->create([
            'team_id' => $this->team->id,
            'status' => 'scheduled',
            'send_mode' => 'recurring',
            'subject' => 'Shabbat on Zoom',
            'body' => 'Hi',
            'send_time' => '09:00',
            ...$attributes,
        ]);
    }

    private function dashboard(User $user)
    {
        return $this->actingAs($user)->get(route('dashboard', $this->team->slug))->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $key): array
    {
        $cards = $this->dashboard($this->owner)->viewData('page')['props']['cards'];

        return collect($cards)->firstWhere('key', $key);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recording(string $uuid, string $status, string $recordedAt, array $attributes = []): ZoomRecording
    {
        return ZoomRecording::query()->create([
            'team_id' => $this->team->id,
            'meeting_uuid' => $uuid,
            'title' => 'Zoom meeting',
            'status' => $status,
            'recorded_at' => $recordedAt,
            ...$attributes,
        ]);
    }

    private function service(string $date, string $title): Service
    {
        return Service::query()->create(['team_id' => $this->team->id, 'service_date' => $date, 'title' => $title]);
    }
}
