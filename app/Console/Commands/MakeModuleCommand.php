<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name : The StudlyCase module name}';

    protected $description = 'Scaffold a congregation module';

    public function handle(): int
    {
        $studly = Str::studly($this->argument('name'));
        $key = Str::kebab($studly);
        $base = app_path('Modules/'.$studly);

        if (File::exists($base)) {
            $this->components->error("Module [{$studly}] already exists.");

            return self::FAILURE;
        }

        $replacements = [
            '{{studly}}' => $studly,
            '{{key}}' => $key,
            '{{label}}' => Str::headline($studly),
        ];

        foreach ([
            'Module.php.stub' => "{$base}/{$studly}Module.php",
            'ServiceProvider.php.stub' => "{$base}/{$studly}ServiceProvider.php",
            'routes-web.php.stub' => "{$base}/routes/web.php",
            'routes-webhooks.php.stub' => "{$base}/routes/webhooks.php",
            'Page.vue.stub' => resource_path("js/modules/{$key}/pages/Index.vue"),
        ] as $stub => $destination) {
            $contents = str_replace(
                array_keys($replacements),
                array_values($replacements),
                File::get(base_path('stubs/module/'.$stub)),
            );

            File::ensureDirectoryExists(dirname($destination));
            File::put($destination, $contents);
        }

        $configPath = config_path('modules.php');
        $config = File::get($configPath);
        $provider = "App\\Modules\\{$studly}\\{$studly}ServiceProvider::class";

        if (! str_contains($config, $provider)) {
            $config = str_replace(
                "'providers' => [",
                "'providers' => [\n        {$provider},",
                $config,
            );
            File::put($configPath, $config);
        }

        $this->components->info("Module [{$studly}] created. Register {$provider} if it is not already loaded from config/modules.php.");

        return self::SUCCESS;
    }
}
