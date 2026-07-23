<?php

namespace App\Mcp\Resources;

use App\Services\LibraryAssistantData;
use JsonException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('library-catalogue')]
#[Title('Library Desk catalogue')]
#[Description('A read-only JSON snapshot of the first twenty Library Desk catalogue records and their current availability.')]
#[Uri('library-desk://catalogue/books')]
#[MimeType('application/json')]
class LibraryCatalogueResource extends Resource
{
    /**
     * @throws JsonException
     */
    public function handle(LibraryAssistantData $library): Response
    {
        return Response::text(json_encode(
            ['books' => $library->searchCatalogue(limit: 20)],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }
}
