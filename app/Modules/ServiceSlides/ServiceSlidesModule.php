<?php

namespace App\Modules\ServiceSlides;

use App\Models\Team;
use App\Modules\ServiceSlides\Actions\SlidesDashboard;
use App\Modules\ServiceSlides\Mcp\Tools\AddAlias;
use App\Modules\ServiceSlides\Mcp\Tools\AddLibraryItem;
use App\Modules\ServiceSlides\Mcp\Tools\CreateTemplate;
use App\Modules\ServiceSlides\Mcp\Tools\GenerateDeck;
use App\Modules\ServiceSlides\Mcp\Tools\GetLibraryFile;
use App\Modules\ServiceSlides\Mcp\Tools\GetService;
use App\Modules\ServiceSlides\Mcp\Tools\ListServices;
use App\Modules\ServiceSlides\Mcp\Tools\ListTemplates;
use App\Modules\ServiceSlides\Mcp\Tools\SearchLibrary;
use App\Modules\ServiceSlides\Mcp\Tools\SetPreset;
use App\Modules\ServiceSlides\Mcp\Tools\UpsertServiceDeck;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ProvidesDashboard;
use Illuminate\Console\Scheduling\Schedule;

class ServiceSlidesModule implements ModuleDefinition, ProvidesDashboard
{
    public function key(): string
    {
        return 'service-slides';
    }

    public function name(): string
    {
        return 'Service slides';
    }

    public function icon(): string
    {
        return 'music';
    }

    public function navItems(): array
    {
        return [
            ['title' => 'Slide library', 'route' => 'service-slides.library'],
            ['title' => 'Slide templates', 'route' => 'service-slides.templates'],
            ['title' => 'Services', 'route' => 'service-slides.services'],
            ['title' => 'Slide history', 'route' => 'service-slides.history'],
            ['title' => 'Slide access', 'route' => 'service-slides.settings'],
        ];
    }

    public function schedule(Schedule $schedule): void {}

    public function mcpTools(): array
    {
        return [
            SearchLibrary::class,
            ListTemplates::class,
            CreateTemplate::class,
            SetPreset::class,
            ListServices::class,
            GetService::class,
            UpsertServiceDeck::class,
            AddAlias::class,
            AddLibraryItem::class,
            GenerateDeck::class,
            GetLibraryFile::class,
        ];
    }

    public function mcpInstructions(): string
    {
        return 'Build service decks from the congregation slide library. Call list-templates to see each template\'s slides and ordered steps. create-template builds a template from a base ODP or PPTX file (base64, a Dropbox link, or a Google Drive share/view/edit link, including Drive MCP file links). set-preset replaces that template\'s arrangement: copy the current steps and insert a slot where it belongs. A slide step names a template slide by its label. A slot step has a key, label, kind, and repeat (liturgy slots are false; the music songs slot is true), plus an optional separator label and default library titles. Slots you omit from set-preset are removed. A saved preset applies to decks created afterward; existing decks keep their entries. Then match titles with search-library, upsert-service-deck with titles per slot, and generate-deck. search-library matches titles, aliases, and the words on the slides, and returns at most 25 matches; pass nextCursor back as cursor with the same query, kind, and scope to read the next page. Slots you do not send to upsert-service-deck keep their current items, including weekly defaults. Each template is ODP or PPTX, and its slots only match library items of the same format: songs must be ODP and liturgy must be PPTX. Add a missing library file with add-library-item. To hand someone a file, use get-library-file for a library item or the downloadUrl get-service gives each generated deck; links expire after 10 minutes, so share them rather than fetching them. The same title can name both a song and a liturgy item; a second active item of the same kind cannot reuse it. Omit title to take it from the first line of the first slide; for songs, a missing author comes from the second line. Reading spreadsheets or email is outside this server.';
    }

    public function dashboard(Team $team): array
    {
        return app(SlidesDashboard::class)->handle($team);
    }

    public function dashboardRoute(): string
    {
        return 'service-slides.services';
    }
}
