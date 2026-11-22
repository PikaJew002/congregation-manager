<?php

namespace App\Modules\ServiceSlides\Mcp;

use App\Modules\ServiceSlides\Mcp\Tools\AddAlias;
use App\Modules\ServiceSlides\Mcp\Tools\AddLibraryItem;
use App\Modules\ServiceSlides\Mcp\Tools\CreateTemplate;
use App\Modules\ServiceSlides\Mcp\Tools\GenerateDeck;
use App\Modules\ServiceSlides\Mcp\Tools\GetService;
use App\Modules\ServiceSlides\Mcp\Tools\ListServices;
use App\Modules\ServiceSlides\Mcp\Tools\ListTemplates;
use App\Modules\ServiceSlides\Mcp\Tools\SearchLibrary;
use App\Modules\ServiceSlides\Mcp\Tools\SetPreset;
use App\Modules\ServiceSlides\Mcp\Tools\UpsertServiceDeck;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Service slides')]
#[Version('1.1.0')]
#[Instructions('Build service decks from the congregation slide library. Call list-templates to see each template\'s slides and ordered steps. create-template builds a template from a base ODP or PPTX file (base64, a Dropbox link, or a Google Drive share/view/edit link, including Drive MCP file links). set-preset replaces that template\'s arrangement: copy the current steps and insert a slot where it belongs. A slide step names a template slide by its label. A slot step has a key, label, kind, and repeat (liturgy slots are false; the music songs slot is true), plus an optional separator label and default library titles. Slots you omit from set-preset are removed. A saved preset applies to decks created afterward; existing decks keep their entries. Then match titles with search-library, upsert-service-deck with titles per slot, and generate-deck. Slots you do not send to upsert-service-deck keep their current items, including weekly defaults. Each template is ODP or PPTX, and its slots only match library items of the same format: songs must be ODP and liturgy must be PPTX. Add a missing library file with add-library-item. The same title can name both a song and a liturgy item; a second active item of the same kind cannot reuse it. Omit title to take it from the first line of the first slide; for songs, a missing author comes from the second line. Reading spreadsheets or email is outside this server.')]
class ServiceSlidesServer extends Server
{
    protected array $tools = [
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
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
