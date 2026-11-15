<?php

namespace App\Support\Modules;

use Illuminate\Console\Scheduling\Schedule;

class ModuleRegistry
{
    /**
     * @var array<string, ModuleDefinition>
     */
    protected array $modules = [];

    public function register(ModuleDefinition $module): void
    {
        $this->modules[$module->key()] = $module;
    }

    /**
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $key): ?ModuleDefinition
    {
        return $this->modules[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->modules);
    }

    public function schedule(Schedule $schedule): void
    {
        foreach ($this->modules as $module) {
            $module->schedule($schedule);
        }
    }
}
