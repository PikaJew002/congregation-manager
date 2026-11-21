<?php

namespace App\Modules\ServiceSlides\Mcp;

use App\Modules\ServiceSlides\Mcp\Tools\AddAlias;
use App\Modules\ServiceSlides\Mcp\Tools\AddLibraryItem;
use App\Modules\ServiceSlides\Mcp\Tools\GenerateDeck;
use App\Modules\ServiceSlides\Mcp\Tools\GetService;
use App\Modules\ServiceSlides\Mcp\Tools\ListServices;
use App\Modules\ServiceSlides\Mcp\Tools\ListTemplates;
use App\Modules\ServiceSlides\Mcp\Tools\SearchLibrary;
use App\Modules\ServiceSlides\Mcp\Tools\UpsertServiceDeck;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Service slides')]
#[Version('1.0.0')]
#[Instructions('Build service decks from the congregation slide library. Call list-templates to see each template\'s slots, match titles with search-library, then upsert-service-deck with titles per slot and generate-deck. Slots you do not send keep their current items, including weekly defaults. Each template is ODP or PPTX, and its slots only match library items of the same format: songs are ODP and liturgy is usually PPTX. Add a missing ODP or PPTX file with add-library-item by sending the file as base64 or a Dropbox link. Omit title to take it from the first line of the first slide; for songs, a missing author comes from the second line. Reading spreadsheets or email is outside this server.')]
class ServiceSlidesServer extends Server
{
    protected array $tools = [
        SearchLibrary::class,
        ListTemplates::class,
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
