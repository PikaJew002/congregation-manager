<?php

namespace Tests\Feature\Modules;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleRegistry::class)->register(new class implements ModuleDefinition
        {
            public function key(): string
            {
                return 'example';
            }

            public function name(): string
            {
                return 'Example';
            }

            public function icon(): string
            {
                return 'mail';
            }

            public function navItems(): array
            {
                return [];
            }

            public function schedule(Schedule $schedule): void {}
        });
    }

    public function test_super_admins_can_use_an_enabled_module_without_a_grant(): void
    {
        [$team, $admin] = $this->congregation(TeamRole::SuperAdmin);

        $this->assertTrue(app(ModuleAccess::class)->canUse($admin, $team, 'example'));
    }

    public function test_members_need_a_grant(): void
    {
        [$team, $member] = $this->congregation(TeamRole::Member);
        $access = app(ModuleAccess::class);

        $this->assertFalse($access->canUse($member, $team, 'example'));

        $access->grant($team, $member, 'example');

        $this->assertTrue($access->canUse($member, $team, 'example'));
    }

    public function test_a_disabled_module_is_hidden_from_everyone(): void
    {
        [$team, $admin] = $this->congregation(TeamRole::SuperAdmin);
        $access = app(ModuleAccess::class);

        $access->setEnabled($team, 'example', false);

        $this->assertFalse($access->isEnabled($team, 'example'));
        $this->assertFalse($access->canUse($admin, $team, 'example'));
    }

    /**
     * @return array{0: Team, 1: User}
     */
    private function congregation(TeamRole $role): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => $role->value]);

        return [$team, $user];
    }
}
