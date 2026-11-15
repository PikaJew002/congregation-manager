<?php

namespace Tests\Feature\Modules;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MakeModuleCommandTest extends TestCase
{
    public function test_it_scaffolds_a_module(): void
    {
        $base = app_path('Modules/DemoModule');
        $page = resource_path('js/modules/demo-module/pages/Index.vue');

        File::deleteDirectory($base);
        File::deleteDirectory(dirname($page, 2));

        $this->artisan('make:module', ['name' => 'DemoModule'])
            ->assertSuccessful();

        $this->assertFileExists($base.'/DemoModuleModule.php');
        $this->assertFileExists($base.'/DemoModuleServiceProvider.php');
        $this->assertFileExists($page);
        $this->assertStringContainsString(
            'App\\Modules\\DemoModule\\DemoModuleServiceProvider::class',
            File::get(config_path('modules.php')),
        );

        File::deleteDirectory($base);
        File::deleteDirectory(dirname($page, 2));

        $config = File::get(config_path('modules.php'));
        $config = str_replace("\n        App\\Modules\\DemoModule\\DemoModuleServiceProvider::class,", '', $config);
        File::put(config_path('modules.php'), $config);
    }
}
