<?php

namespace App\Mcp\Tools;

use App\Services\LibraryAssistantData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-library-books')]
#[Title('Search Library Desk catalogue')]
#[Description('Searches Library Desk books by title, author, or ISBN and returns live availability counts.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchLibraryBooksTool extends Tool
{
    public function handle(Request $request, LibraryAssistantData $library): ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return Response::structured([
            'books' => $library->searchCatalogue(
                $validated['query'] ?? '',
                $validated['limit'] ?? 8,
            ),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Optional title, author, or ISBN search term.'),
            'limit' => $schema->integer()
                ->description('Maximum number of books to return, between 1 and 20.')
                ->default(8),
        ];
    }
}
