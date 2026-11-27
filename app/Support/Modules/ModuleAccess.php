<?php

namespace App\Support\Modules;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamModule;
use App\Models\TeamModuleUser;
use App\Models\User;

class ModuleAccess
{
    public function __construct(private ModuleRegistry $registry) {}

    public function isEnabled(Team $team, string $key): bool
    {
        if (! $this->registry->has($key)) {
            return false;
        }

        $setting = TeamModule::query()
            ->where('team_id', $team->id)
            ->where('module_key', $key)
            ->first();

        return $setting->enabled ?? true;
    }

    public function canUse(User $user, Team $team, string $key): bool
    {
        if (! $this->isEnabled($team, $key)) {
            return false;
        }

        $role = $user->teamRole($team);

        if ($role?->isAtLeast(TeamRole::SuperAdmin)) {
            return true;
        }

        return TeamModuleUser::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('module_key', $key)
            ->exists();
    }

    public function grant(Team $team, User $user, string $key): void
    {
        if (! $this->registry->has($key)) {
            return;
        }

        TeamModuleUser::query()->firstOrCreate([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'module_key' => $key,
        ]);
    }

    public function revoke(Team $team, User $user, string $key): void
    {
        TeamModuleUser::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('module_key', $key)
            ->delete();
    }

    /**
     * @param  list<string>  $keys
     */
    public function syncGrants(Team $team, User $user, array $keys): void
    {
        $keys = array_values(array_intersect($keys, $this->registry->keys()));

        TeamModuleUser::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->whereNotIn('module_key', $keys)
            ->delete();

        foreach ($keys as $key) {
            $this->grant($team, $user, $key);
        }
    }

    public function setEnabled(Team $team, string $key, bool $enabled): void
    {
        if (! $this->registry->has($key)) {
            return;
        }

        TeamModule::query()->updateOrCreate(
            ['team_id' => $team->id, 'module_key' => $key],
            ['enabled' => $enabled],
        );
    }

    /**
     * @return list<string>
     */
    public function grantsFor(Team $team, User $user): array
    {
        return array_values(TeamModuleUser::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (TeamModuleUser $grant) => $grant->module_key)
            ->all());
    }

    /**
     * Sidebar payload for modules the user can open.
     *
     * @return list<array{key: string, name: string, icon: string, items: list<array{title: string, href: string}>}>
     */
    public function navigationFor(User $user, Team $team): array
    {
        $navigation = [];

        foreach ($this->registry->all() as $module) {
            if (! $this->canUse($user, $team, $module->key())) {
                continue;
            }

            $items = [];

            foreach ($module->navItems() as $item) {
                $params = array_merge(['current_team' => $team->slug], $item['params'] ?? []);
                $items[] = [
                    'title' => $item['title'],
                    'href' => route($item['route'], $params),
                ];
            }

            $navigation[] = [
                'key' => $module->key(),
                'name' => $module->name(),
                'icon' => $module->icon(),
                'items' => $items,
            ];
        }

        return $navigation;
    }
}
