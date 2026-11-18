<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ServiceSlidesOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_discovery_documents_advertise_oauth_for_claude(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/services')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp/services'))
            ->assertJsonPath('authorization_servers.0', url('/'))
            ->assertJsonPath('scopes_supported.0', 'mcp:use');

        $metadata = $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', url('/'))
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'));

        $this->assertEqualsCanonicalizing(
            ['authorization_code', 'refresh_token'],
            $metadata->json('grant_types_supported'),
        );
        $this->assertEqualsCanonicalizing(
            ['none', 'client_secret_basic', 'client_secret_post'],
            $metadata->json('token_endpoint_auth_methods_supported'),
        );

        $this->getJson('/.well-known/oauth-authorization-server/mcp/services')
            ->assertOk()
            ->assertJsonPath('issuer', url('/'));
    }

    public function test_dynamic_registration_accepts_claude_and_loopback_callbacks(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none')
            ->assertJsonPath('scope', 'mcp:use')
            ->assertJsonMissing(['client_secret']);

        $this->postJson('/oauth/register', [
            'client_name' => 'Claude Code',
            'redirect_uris' => ['http://127.0.0.1:3118/callback', 'http://localhost:4782/callback'],
        ])->assertCreated();

        $this->postJson('/oauth/register', [
            'client_name' => 'Cursor',
            'redirect_uris' => ['cursor://anysphere.cursor-mcp/oauth/callback'],
        ])->assertCreated();

        $this->postJson('/oauth/register', [
            'client_name' => 'Elsewhere',
            'redirect_uris' => ['https://evil.example/callback'],
        ])->assertBadRequest()
            ->assertJsonPath('error', 'invalid_redirect_uri');
    }

    public function test_a_signed_in_client_can_plan_only_the_approved_congregation(): void
    {
        [$team, $owner] = $this->owner();
        $personal = $owner->personalTeam();
        $this->song($team, 'Kumi Ori');
        $this->song($personal, 'Private Hymn');

        $clientId = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated()->json('client_id');

        [$verifier, $challenge] = $this->pkce();
        $consent = $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => url('/mcp/services'),
        ]));

        $consent->assertOk();
        $consent->assertSee('Beth Shalom');
        $consent->assertSee('claude.ai');
        $consent->assertSee('Plan service slides for one congregation');

        preg_match('/name="auth_token" value="([^"]+)"/', $consent->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $approved = $this->actingAs($owner)->post('/oauth/authorize', [
            'auth_token' => $matches[1],
            'client_id' => $clientId,
            'team_id' => $team->id,
        ]);

        $location = $approved->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://claude.ai/api/mcp/auth_callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state'] ?? null);
        $this->assertIsString($query['code'] ?? null);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code' => $query['code'],
            'code_verifier' => $verifier,
            'resource' => url('/mcp/services'),
        ])->assertOk();

        $accessToken = $tokens->json('access_token');
        $refreshToken = $tokens->json('refresh_token');
        $this->assertIsString($accessToken);
        $this->assertIsString($refreshToken);

        $granted = Passport::token()->newQuery()->where('user_id', $owner->id)->where('revoked', false)->first();
        $this->assertNotNull($granted);
        $this->assertSame($team->id, (int) $granted->team_id);

        $owner->switchTeam($personal);

        $this->callTool($accessToken, 'Kumi')
            ->assertOk()
            ->assertSee('Kumi Ori')
            ->assertDontSee('Private Hymn');

        $this->actingAs($owner)
            ->get(route('service-slides.settings', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.0.name', 'Claude')
                ->where('connections.0.id', $granted->id));

        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'resource' => url('/mcp/services'),
        ])->assertOk();

        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ])->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');

        $current = Passport::token()->newQuery()->where('user_id', $owner->id)->where('revoked', false)->first();
        $this->assertNotNull($current);
        $this->assertNotSame($granted->id, $current->id);
        $this->assertSame($team->id, (int) $current->team_id);
        $this->assertTrue($granted->fresh()->revoked);

        $this->callTool($refreshed->json('access_token'), 'Kumi')->assertSee('Kumi Ori');

        $this->actingAs($owner)
            ->delete(route('service-slides.connections.destroy', [$team, $current->id]))
            ->assertRedirect();

        $this->assertTrue($current->fresh()->revoked);
        $this->callTool($refreshed->json('access_token'), 'Kumi')->assertUnauthorized();
    }

    public function test_approval_rejects_a_congregation_the_user_does_not_belong_to(): void
    {
        [$team, $owner] = $this->owner();
        $other = Team::factory()->create(['name' => 'Elsewhere']);
        $clientId = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->json('client_id');

        [, $challenge] = $this->pkce();
        $consent = $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        preg_match('/name="auth_token" value="([^"]+)"/', $consent->getContent(), $matches);

        $this->actingAs($owner)
            ->from('/oauth/authorize')
            ->post('/oauth/authorize', [
                'auth_token' => $matches[1],
                'client_id' => $clientId,
                'team_id' => $other->id,
            ])
            ->assertRedirect('/oauth/authorize')
            ->assertSessionHasErrors('team_id');

        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertNotSame($other->id, $team->id);
    }

    public function test_a_sanctum_token_can_still_call_the_endpoint(): void
    {
        [$team, $owner] = $this->owner();
        $this->song($team, 'Kumi Ori');
        $token = $owner->createToken('script', ['service-slides', 'congregation:'.$team->id]);

        $this->callTool($token->plainTextToken, 'Kumi')
            ->assertOk()
            ->assertSee('Kumi Ori');
    }

    /**
     * @return array{0: Team, 1: User}
     */
    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Beth Shalom']);
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->switchTeam($team);

        return [$team, $user];
    }

    private function song(Team $team, string $title): void
    {
        SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => $title,
            'original_path' => 'slides/song.odp',
            'odp_path' => 'slides/song.odp',
            'slide_count' => 1,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pkce(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    private function callTool(string $token, string $query): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->postJson('/mcp/services', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search-library',
                'arguments' => ['query' => $query],
            ],
        ]);
    }
}
