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

#[Name('get-library-book')]
#[Title('Get Library Desk book details')]
#[Description('Returns a book description, copy counts, and current availability for one Library Desk catalogue record.')]
#[IsReadOnly]
#[IsIdempotent]
class GetLibraryBookTool extends Tool
{
    public function handle(Request $request, LibraryAssistantData $library): ResponseFactory
    {
        $validated = $request->validate([
            'book_id' => ['required', 'integer', 'min:1'],
        ]);

        return Response::structured([
            'book' => $library->catalogueBook($validated['book_id']),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'book_id' => $schema->integer()
                ->description('The Library Desk book identifier.')
                ->required(),
        ];
    }
}
