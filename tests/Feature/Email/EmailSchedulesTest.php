<?php

namespace Tests\Feature\Email;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\Email\Actions\RenderEmail;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use App\Modules\Email\Models\EmailSetting;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Many emails per congregation, recurring or one-time, with shared and
 * per-email variables and dates worked out from the send time.
 */
class EmailSchedulesTest extends TestCase
{
    use RefreshDatabase;

    private const ZOOM_TEMPLATE = <<<'TEXT'
        Baruch Haba is inviting you to a scheduled Zoom meeting.

        Topic: Shabbat Service Baruch Haba
        Time: {{date:saturday|M j, Y}} 11:00 AM

        Join Zoom Meeting
        {{zoom_url}}

        Meeting ID: {{meeting_id_formatted}}
        Passcode: {{passcode}}

        ---

        One tap mobile
        +13092053325,,{{meeting_id}}#,,,,*{{passcode}}#
        TEXT;

    public function test_saturday_is_the_coming_saturday_or_the_same_day(): void
    {
        $renderer = app(RenderEmail::class);
        $at = fn (string $local) => CarbonImmutable::parse($local, 'America/New_York');

        $this->assertSame('Nov 28, 2026', $renderer->resolve('{{date:saturday|M j, Y}}', [], $at('2026-11-26 09:00')));
        $this->assertSame('Nov 28, 2026', $renderer->resolve('{{date:saturday|M j, Y}}', [], $at('2026-11-27 23:30')));
        $this->assertSame('Nov 28, 2026', $renderer->resolve('{{date:saturday|M j, Y}}', [], $at('2026-11-28 09:00')));
        $this->assertSame('Dec 5, 2026', $renderer->resolve('{{date:next saturday|M j, Y}}', [], $at('2026-11-28 09:00')));
        $this->assertSame('Saturday, November 28', $renderer->resolve('{{ date : saturday | l, F j }}', [], $at('2026-11-26 09:00')));
        $this->assertSame('Nov 26, 2026', $renderer->resolve('{{date}}', [], $at('2026-11-26 09:00')));
        $this->assertSame('Nov 28, 2026', $renderer->resolve('{{date:saturday}}', [], $at('2026-11-26 09:00')));
    }

    public function test_variables_fill_in_and_leave_the_personal_placeholders(): void
    {
        $renderer = app(RenderEmail::class);
        $text = $renderer->resolve(
            '{{topic}} for {{name}} at {{zoom_url}}: {{unknown}} {{unsubscribe_url}}',
            ['topic' => 'Shabbat', 'zoom_url' => 'https://zoom.example/j/1', 'name' => 'ignored'],
            CarbonImmutable::now(),
        );

        $this->assertSame('Shabbat for {{name}} at https://zoom.example/j/1: {{unknown}} {{unsubscribe_url}}', $text);
    }

    public function test_problems_name_missing_variables_and_unreadable_dates(): void
    {
        $problems = app(RenderEmail::class)->problems('{{zoom_url}} {{passcode}} {{name}} {{date:saturday}} {{date:not a day at all}}', ['zoom_url' => 'x']);

        $this->assertCount(2, $problems);
        $this->assertStringStartsWith('{{passcode}} is not a variable.', $problems[0]);
        $this->assertStringContainsString('“not a day at all” is not a date', $problems[1]);
    }

    public function test_an_emails_own_variable_replaces_the_shared_one(): void
    {
        [$team] = $this->owner();
        EmailSetting::forTeam($team)->update(['variables' => [
            ['key' => 'zoom_url', 'value' => 'https://zoom.example/shared'],
            ['key' => 'passcode', 'value' => '111'],
        ]]);
        $email = $this->email($team, ['variables' => [['key' => 'passcode', 'value' => '222']]]);

        $this->assertSame(
            ['zoom_url' => 'https://zoom.example/shared', 'passcode' => '222'],
            app(RenderEmail::class)->variablesFor($email, EmailSetting::forTeam($team)),
        );
    }

    public function test_a_run_holds_the_finished_text_for_its_send_time(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        EmailSetting::forTeam($team)->update(['variables' => [['key' => 'zoom_url', 'value' => 'https://zoom.example/j/123']]]);
        $this->email($team, [
            'subject' => 'Shabbat Service – {{date:saturday|M j}}',
            'body' => self::ZOOM_TEMPLATE,
            'send_days' => [4],
            'variables' => [
                ['key' => 'meeting_id', 'value' => '12345678901'],
                ['key' => 'meeting_id_formatted', 'value' => '123 4567 8901'],
                ['key' => 'passcode', 'value' => '777'],
            ],
        ]);
        $this->subscribe($team, 1);

        $this->dispatchAt('2026-11-26 09:05');

        $run = EmailRun::query()->sole();
        $this->assertSame('Shabbat Service – Nov 28', $run->subject);
        $this->assertStringContainsString('Time: Nov 28, 2026 11:00 AM', $run->body);
        $this->assertStringContainsString("Join Zoom Meeting\nhttps://zoom.example/j/123", $run->body);
        $this->assertStringContainsString('Meeting ID: 123 4567 8901', $run->body);
        $this->assertStringContainsString('+13092053325,,12345678901#,,,,*777#', $run->body);
        $this->assertStringNotContainsString('{{', $run->body);

        // Changing the template later does not change what this run sends.
        Email::query()->sole()->update(['body' => 'Changed']);
        $this->assertStringContainsString('Nov 28, 2026', $run->fresh()->body);
    }

    public function test_the_html_keeps_each_line_on_its_own_line(): void
    {
        [$team] = $this->owner();
        $recipient = EmailRecipient::query()->create(['team_id' => $team->id, 'email' => 'a@example.com']);

        $html = app(RenderEmail::class)->personalize('Hi', "Topic: Shabbat\nTime: Nov 28", $recipient)['html'];

        $this->assertSame("<p>Topic: Shabbat<br />\nTime: Nov 28</p>\n", $html);
    }

    public function test_two_emails_at_the_same_time_each_get_a_run(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $this->email($team, ['name' => 'Shabbat invite']);
        $this->email($team, ['name' => 'Torah study']);
        $this->subscribe($team, 2);

        $this->dispatchAt('2026-11-26 09:05');
        $this->dispatchAt('2026-11-26 09:06');

        $this->assertSame(2, EmailRun::query()->count());
        $this->assertSame(4, DB::table('email_deliveries')->count());
    }

    public function test_a_recurring_email_goes_out_on_each_chosen_day(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $this->email($team, ['send_days' => [4, 5]]);
        $this->subscribe($team, 1);

        $this->dispatchAt('2026-11-26 09:05');
        $this->dispatchAt('2026-11-27 09:05');
        $this->dispatchAt('2026-11-28 09:05');

        $this->assertSame(
            ['2026-11-26 09:00', '2026-11-27 09:00'],
            EmailRun::query()->orderBy('scheduled_for')->get()
                ->map(fn (EmailRun $run) => $run->scheduled_for->setTimezone('America/New_York')->format('Y-m-d H:i'))
                ->all(),
        );
    }

    public function test_a_one_time_email_goes_out_once_at_its_time(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $email = $this->email($team, [
            'send_mode' => 'once',
            'send_days' => null,
            'send_time' => null,
            'send_at' => CarbonImmutable::parse('2026-12-01 19:00', 'America/New_York')->utc(),
        ]);
        $this->subscribe($team, 1);

        $this->dispatchAt('2026-12-01 18:59');
        $this->assertSame(0, EmailRun::query()->count());

        $this->dispatchAt('2026-12-01 19:00');
        $this->dispatchAt('2026-12-01 19:01');

        $this->assertSame(1, EmailRun::query()->count());
        $this->assertSame($email->id, EmailRun::query()->sole()->email_id);
        $this->assertSame('sent', $email->fresh()->status);
    }

    public function test_a_one_time_email_missed_by_a_day_goes_back_to_draft(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $email = $this->email($team, [
            'send_mode' => 'once',
            'send_at' => CarbonImmutable::parse('2026-12-01 19:00', 'America/New_York')->utc(),
        ]);
        $this->subscribe($team, 1);

        $this->dispatchAt('2026-12-02 19:01');

        $this->assertSame(0, EmailRun::query()->count());
        $this->assertSame('draft', $email->fresh()->status);
    }

    public function test_send_now_goes_out_on_the_next_tick(): void
    {
        Mail::fake();
        [$team, $owner] = $this->owner();
        $this->readyToSend($team);
        $this->subscribe($team, 1);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'America/New_York'));

        $this->actingAs($owner)
            ->post(route('email.store', $team->slug), [
                'name' => 'December newsletter',
                'send_mode' => 'once',
                'status' => 'draft',
                'send_now' => true,
                'subject' => 'News for {{date|F}}',
                'body' => 'Hello {{name}}',
            ])
            ->assertSessionHasNoErrors();

        $email = Email::query()->sole();
        $this->assertSame('scheduled', $email->status);

        $this->dispatchAt('2026-12-01 10:00');

        $this->assertSame('News for December', EmailRun::query()->sole()->subject);
        $this->assertSame('sent', $email->fresh()->status);
    }

    public function test_saving_checks_placeholders_and_times(): void
    {
        [$team, $owner] = $this->owner();
        $this->readyToSend($team);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'America/New_York'));
        $valid = [
            'name' => 'Shabbat invite',
            'send_mode' => 'recurring',
            'status' => 'scheduled',
            'subject' => 'Shabbat',
            'body' => 'Join {{zoom_url}}',
            'send_days' => [5],
            'send_time' => '09:00',
            'variables' => [['key' => 'zoom_url', 'value' => 'https://zoom.example']],
        ];
        $post = fn (array $changes) => $this->actingAs($owner)->post(route('email.store', $team->slug), [...$valid, ...$changes]);

        $post(['variables' => []])->assertSessionHasErrors('body');
        $post(['subject' => 'On {{date:someday soon}}'])->assertSessionHasErrors('subject');
        $post(['variables' => [['key' => 'name', 'value' => 'x']]])->assertSessionHasErrors('variables.0.key');
        $post(['variables' => [['key' => 'Zoom-URL', 'value' => 'x']]])->assertSessionHasErrors('variables.0.key');
        $post(['send_days' => []])->assertSessionHasErrors('send_days');
        $post(['send_mode' => 'once', 'send_at' => '2026-11-30T10:00'])->assertSessionHasErrors('send_at');
        $post(['send_mode' => 'once', 'send_at' => null])->assertSessionHasErrors('send_at');
        $this->assertSame(0, Email::query()->count());

        $post(['send_mode' => 'once', 'send_at' => '2026-12-03T19:30'])->assertSessionHasNoErrors();
        $this->assertSame(
            '2026-12-04 00:30',
            Email::query()->sole()->send_at->format('Y-m-d H:i'),
        );
    }

    public function test_a_sent_email_cannot_change_but_can_be_duplicated(): void
    {
        [$team, $owner] = $this->owner();
        $email = $this->email($team, ['name' => 'November newsletter', 'send_mode' => 'once', 'status' => 'sent', 'send_at' => now()]);

        $this->actingAs($owner)
            ->put(route('email.update', [$team->slug, $email]), ['name' => 'Changed', 'send_mode' => 'once', 'status' => 'draft', 'subject' => 'X', 'body' => 'Y'])
            ->assertSessionHasErrors('status');

        $this->actingAs($owner)
            ->post(route('email.duplicate', [$team->slug, $email]))
            ->assertRedirect();

        $copy = Email::query()->whereKeyNot($email->id)->sole();
        $this->assertSame('Copy of November newsletter', $copy->name);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->send_at);
        $this->assertSame($email->body, $copy->body);
    }

    public function test_deleting_an_email_keeps_what_it_sent(): void
    {
        [$team, $owner] = $this->owner();
        $email = $this->email($team);
        $run = EmailRun::query()->create(['team_id' => $team->id, 'email_id' => $email->id, 'scheduled_for' => now(), 'status' => 'completed', 'subject' => 'S', 'body' => 'B']);

        $this->actingAs($owner)->delete(route('email.destroy', [$team->slug, $email]))->assertRedirect();

        $this->assertNull($run->fresh()->email_id);
    }

    public function test_a_shared_variable_an_email_uses_cannot_be_removed(): void
    {
        [$team, $owner] = $this->owner();
        $this->readyToSend($team);
        EmailSetting::forTeam($team)->update(['from_address' => 'office@shul.example', 'variables' => [['key' => 'zoom_url', 'value' => 'https://zoom.example']]]);
        $this->email($team, ['name' => 'Shabbat invite', 'body' => 'Join {{zoom_url}}']);
        $sender = ['from_name' => 'Shul', 'from_address' => 'office@shul.example'];

        $this->actingAs($owner)
            ->put(route('email.settings.update', $team->slug), [...$sender, 'variables' => []])
            ->assertSessionHasErrors(['variables' => 'These emails use a variable you removed or renamed: Shabbat invite. Change those emails first, or give them their own value.']);

        $this->actingAs($owner)
            ->put(route('email.settings.update', $team->slug), [...$sender, 'variables' => [['key' => 'zoom_url', 'value' => 'https://zoom.example/new']]])
            ->assertSessionHasNoErrors();
        $this->assertSame(['zoom_url' => 'https://zoom.example/new'], EmailSetting::forTeam($team)->variableMap());
    }

    public function test_the_daily_limit_counts_every_email_sent_that_day(): void
    {
        Mail::fake();
        [$team] = $this->owner();
        $team->forceFill(['daily_email_limit' => 5])->save();
        $this->email($team, ['name' => 'Morning', 'send_time' => '09:00']);
        $this->email($team, ['name' => 'Evening', 'send_time' => '18:00']);
        $this->subscribe($team, 3);

        $this->dispatchAt('2026-11-26 09:05');
        $this->dispatchAt('2026-11-26 18:05');

        $this->assertSame(['Morning'], EmailRun::query()->with('email')->get()->pluck('email.name')->all());
    }

    public function test_the_migration_turns_the_weekly_email_into_the_first_email(): void
    {
        [$team, $owner] = $this->owner();
        $migration = require app_path('Modules/Email/database/migrations/2026_12_02_100000_turn_weekly_email_into_emails.php');
        $migration->down();

        DB::table('weekly_email_settings')->insert([
            'team_id' => $team->id,
            'enabled' => true,
            'subject' => 'This week on Zoom',
            'body' => 'Join: {{zoom_link}}',
            'zoom_link' => 'https://zoom.example/j/9',
            'from_address' => 'office@shul.example',
            'from_name' => 'Shul',
            'send_day' => 5,
            'send_time' => '09:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::table('email_runs')->insertGetId(['team_id' => $team->id, 'scheduled_for' => now(), 'status' => 'completed', 'subject' => 'This week on Zoom', 'body' => 'Join: {{zoom_link}}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('team_modules')->insert(['team_id' => $team->id, 'module_key' => 'weekly-email', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('team_module_user')->insert(['team_id' => $team->id, 'user_id' => $owner->id, 'module_key' => 'weekly-email', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $email = Email::query()->sole();
        $this->assertSame(['Weekly email', 'scheduled', 'recurring', [5], '09:00', 'Join: {{zoom_link}}'], [$email->name, $email->status, $email->send_mode, $email->send_days, $email->send_time, $email->body]);
        $this->assertSame(['zoom_link' => 'https://zoom.example/j/9'], EmailSetting::forTeam($team)->variableMap());
        $this->assertSame('Shul', EmailSetting::forTeam($team)->from_name);
        $this->assertSame($email->id, EmailRun::query()->find($runId)->email_id);
        $this->assertSame('Join: https://zoom.example/j/9', EmailRun::query()->find($runId)->body);
        $this->assertSame('email', DB::table('team_modules')->where('team_id', $team->id)->value('module_key'));
        $this->assertSame('email', DB::table('team_module_user')->where('team_id', $team->id)->value('module_key'));
    }

    /**
     * Run the dispatcher at a New York time, with a fresh sending check.
     */
    private function dispatchAt(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'America/New_York'));

        Team::query()->each(fn (Team $team) => $team->forceFill([
            ...TeamFactory::emailSendingAttributes(),
            'domain_checked_at' => now(),
        ])->save());

        $this->artisan('email:dispatch-due')->assertSuccessful();
    }

    /**
     * A scheduled recurring email, Thursdays at 9:00 unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function email(Team $team, array $attributes = []): Email
    {
        return Email::query()->create([
            'team_id' => $team->id,
            'name' => 'Shabbat invite',
            'status' => 'scheduled',
            'send_mode' => 'recurring',
            'subject' => 'Shabbat',
            'body' => 'Hello {{name}}',
            'send_days' => [4],
            'send_time' => '09:00',
            ...$attributes,
        ]);
    }

    private function subscribe(Team $team, int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            EmailRecipient::query()->create(['team_id' => $team->id, 'email' => "person{$index}@example.com", 'status' => 'subscribed']);
        }
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
}
