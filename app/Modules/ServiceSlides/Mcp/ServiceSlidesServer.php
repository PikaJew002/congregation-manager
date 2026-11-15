<?php

namespace App\Modules\ServiceSlides\Mcp;

use App\Modules\ServiceSlides\Mcp\Tools\AddAlias;
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
#[Instructions('Build service decks from the congregation slide library. Call list_templates to see each template\'s slots, match titles with search_library, then upsert_service_deck with titles per slot and generate_deck. Slots you do not send keep their current items, including weekly defaults. Reading spreadsheets or email is outside this server.')]
class ServiceSlidesServer extends Server
{
    protected array $tools = [
        SearchLibrary::class,
        ListTemplates::class,
        ListServices::class,
        GetService::class,
        UpsertServiceDeck::class,
        AddAlias::class,
        GenerateDeck::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
