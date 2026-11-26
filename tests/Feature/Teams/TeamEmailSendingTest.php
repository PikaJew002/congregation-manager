<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Mail\EmailSending;
use App\Models\Team;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamEmailSendingTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_PASSED = ['check_results' => ['spf' => ['verdict' => 'passed']]];

    public function test_owner_saves_the_key_encrypted_after_mailchannels_accepts_it(): void
    {
        Http::fake(['api.mailchannels.net/tx/v1/check-domain' => Http::response(self::CHECK_PASSED)]);
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->patch(route('teams.email-sending.update', $team->slug), $this->form([
                'mailchannels_api_key' => 'congregation-key',
                'sending_domain' => ' Shul.Example ',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit', $team->slug));

        $team->refresh();
        $this->assertSame('congregation-key', $team->mailchannels_api_key);
        $this->assertSame('shul.example', $team->sending_domain);
        $this->assertSame('acct_1', $team->mailchannels_account_id);
        $this->assertSame(250, $team->daily_email_limit);
        $this->assertNotNull($team->domain_checked_at);
        $this->assertNotSame('congregation-key', DB::table('teams')->where('id', $team->id)->value('mailchannels_api_key'));

        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'congregation-key')
            && $request['domain'] === 'shul.example');
    }

    public function test_a_key_mailchannels_refuses_is_not_saved(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(['errors' => ['Unauthorized']], 401)]);
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->patch(route('teams.email-sending.update', $team->slug), $this->form(['mailchannels_api_key' => 'wrong']))
            ->assertSessionHasErrors('mailchannels_api_key');

        $this->assertNull($team->fresh()->mailchannels_api_key);
    }

    public function test_a_blank_key_keeps_the_saved_one_and_clear_removes_it(): void
    {
        Http::fake();
        [$team, $owner] = $this->owner();
        $team->forceFill(['mailchannels_api_key' => 'saved-key', 'sending_domain' => 'shul.example', 'dkim_selector' => 'cm202611'])->save();

        $this->actingAs($owner)
            ->patch(route('teams.email-sending.update', $team->slug), $this->form(['daily_email_limit' => 500]))
            ->assertSessionHasNoErrors();

        $this->assertSame('saved-key', $team->fresh()->mailchannels_api_key);
        $this->assertSame('cm202611', $team->fresh()->dkim_selector);
        $this->assertSame(500, $team->fresh()->daily_email_limit);
        Http::assertNothingSent();

        $this->actingAs($owner)
            ->patch(route('teams.email-sending.update', $team->slug), $this->form(['clear_mailchannels_api_key' => true]))
            ->assertSessionHasNoErrors();

        $this->assertNull($team->fresh()->mailchannels_api_key);
        $this->assertNull($team->fresh()->dkim_selector);
    }

    public function test_changing_the_domain_clears_dkim(): void
    {
        Http::fake(['api.mailchannels.net/*' => Http::response(self::CHECK_PASSED)]);
        [$team, $owner] = $this->owner();
        $team->forceFill(['mailchannels_api_key' => 'saved-key', 'sending_domain' => 'shul.example', 'dkim_selector' => 'cm202611'])->save();

        $this->actingAs($owner)
            ->patch(route('teams.email-sending.update', $team->slug), $this->form(['sending_domain' => 'news.shul.example']))
            ->assertSessionHasNoErrors();

        $this->assertNull($team->fresh()->dkim_selector);
        Http::assertSent(fn ($request) => $request['domain'] === 'news.shul.example');
    }

    public function test_a_domain_must_be_a_bare_domain(): void
    {
        [$team, $owner] = $this->owner();

        foreach (['https://shul.example', 'office@shul.example', 'shul'] as $domain) {
            $this->actingAs($owner)
                ->patch(route('teams.email-sending.update', $team->slug), $this->form(['sending_domain' => $domain]))
                ->assertSessionHasErrors('sending_domain');
        }
    }

    public function test_members_cannot_change_email_sending_or_see_it(): void
    {
        [$team] = $this->owner();
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $this->actingAs($member)
            ->patch(route('teams.email-sending.update', $team->slug), $this->form())
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('teams.edit', $team->slug))
            ->assertInertia(fn (Assert $page) => $page->where('emailSending', null));
    }

    public function test_the_key_never_reaches_the_browser(): void
    {
        [$team, $owner] = $this->owner();
        $team->forceFill(['mailchannels_api_key' => 'secret-congregation-key', 'sending_domain' => 'shul.example'])->save();

        $response = $this->actingAs($owner)->get(route('teams.edit', $team->slug));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('emailSending.hasApiKey', true)
            ->where('emailSending.sendingDomain', 'shul.example'));
        $this->assertStringNotContainsString('secret-congregation-key', $response->getContent());
        $this->assertArrayNotHasKey('mailchannels_api_key', $team->fresh()->toArray());
    }

    public function test_the_general_team_update_cannot_set_the_key(): void
    {
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->patch(route('teams.update', $team->slug), ['name' => $team->name, 'mailchannels_api_key' => 'sneaky']);

        $this->assertNull($team->fresh()->mailchannels_api_key);
    }

    public function test_owner_creates_a_dkim_key_mailchannels_keeps(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 25));
        Http::fake(['api.mailchannels.net/tx/v1/domains/shul.example/dkim-keys' => Http::response([
            'domain' => 'shul.example',
            'selector' => 'cm202611',
            'status' => 'active',
            'dkim_dns_records' => [['name' => 'cm202611._domainkey.shul.example', 'type' => 'TXT', 'value' => 'v=DKIM1; k=rsa; p=PUBLIC']],
        ], 201)]);
        [$team, $owner] = $this->owner();
        $team->forceFill(['mailchannels_api_key' => 'saved-key', 'sending_domain' => 'shul.example'])->save();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.dkim', $team->slug))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit', $team->slug));

        $team->refresh();
        $this->assertSame('cm202611', $team->dkim_selector);
        $this->assertSame('v=DKIM1; k=rsa; p=PUBLIC', $team->dkim_dns_record['value']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['selector'] === 'cm202611'
            && ! array_key_exists('private_key', $request->data()));
    }

    public function test_an_existing_dkim_key_is_reused(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 25));
        Http::fake([
            'api.mailchannels.net/tx/v1/domains/shul.example/dkim-keys?*' => Http::response(['keys' => [[
                'selector' => 'cm202611',
                'public_key' => 'EXISTING',
            ]]]),
            'api.mailchannels.net/tx/v1/domains/shul.example/dkim-keys' => Http::response(null, 409),
        ]);
        [$team, $owner] = $this->owner();
        $team->forceFill(['mailchannels_api_key' => 'saved-key', 'sending_domain' => 'shul.example'])->save();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.dkim', $team->slug))
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'name' => 'cm202611._domainkey.shul.example',
            'type' => 'TXT',
            'value' => 'v=DKIM1; k=rsa; p=EXISTING',
        ], $team->fresh()->dkim_dns_record);
    }

    public function test_dkim_needs_a_saved_key_and_domain(): void
    {
        Http::fake();
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.dkim', $team->slug))
            ->assertSessionHasErrors('dkim');

        Http::assertNothingSent();
    }

    public function test_check_again_stores_each_verdict_and_reports_readiness(): void
    {
        Http::fake(['api.mailchannels.net/tx/v1/check-domain' => Http::sequence()
            ->push(['check_results' => [
                'dkim' => [['dkim_domain' => 'shul.example', 'dkim_selector' => 'cm202611', 'verdict' => 'failed', 'reason' => 'No DKIM record found']],
                'spf' => ['verdict' => 'passed', 'spfRecord' => 'v=spf1 include:_spf.google.com ~all'],
                'domain_lockdown' => ['verdict' => 'failed', 'reason' => 'No lockdown record'],
            ]])
            ->push(['check_results' => [
                'dkim' => [['dkim_domain' => 'shul.example', 'dkim_selector' => 'cm202611', 'verdict' => 'passed']],
                'spf' => ['verdict' => 'passed', 'spfRecord' => 'v=spf1 include:relay.mailchannels.net include:_spf.google.com ~all'],
                'domain_lockdown' => ['verdict' => 'passed'],
            ]])]);
        [$team, $owner] = $this->owner();
        $team->forceFill([
            ...TeamFactory::emailSendingAttributes(),
            'domain_check' => null,
            'domain_checked_at' => null,
        ])->save();

        $this->actingAs($owner)
            ->post(route('teams.email-sending.check', $team->slug))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertSame('DKIM and Domain Lockdown for shul.example are not passing. Fix the DNS records in the congregation settings, then check again.', EmailSending::problem($team->fresh()));

        $this->actingAs($owner)
            ->get(route('teams.edit', $team->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('emailSending.records.0.passed', false)
                ->where('emailSending.records.0.reason', 'No DKIM record found')
                ->where('emailSending.records.1.value', 'v=spf1 include:relay.mailchannels.net include:_spf.google.com ~all')
                ->where('emailSending.records.2.name', '_mailchannels.shul.example')
                ->where('emailSending.records.2.value', 'v=mc1 auth=acct_1'));

        $this->actingAs($owner)
            ->post(route('teams.email-sending.check', $team->slug))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertTrue(EmailSending::ready($team->fresh()));
        Http::assertSent(fn ($request) => $request['dkim_settings'] === [['dkim_domain' => 'shul.example', 'dkim_selector' => 'cm202611']]);
    }

    public function test_spf_suggests_a_new_record_when_the_domain_has_none(): void
    {
        [$team] = $this->owner();
        $team->forceFill([
            ...TeamFactory::emailSendingAttributes(),
            'domain_check' => ['spf' => ['verdict' => 'none', 'spfRecord' => null]],
        ])->save();

        $this->assertSame('v=spf1 include:relay.mailchannels.net ~all', EmailSending::records($team)[1]['value']);
        $this->assertFalse(EmailSending::ready($team));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'mailchannels_api_key' => '',
            'mailchannels_account_id' => 'acct_1',
            'sending_domain' => 'shul.example',
            'daily_email_limit' => 250,
            ...$overrides,
        ];
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
