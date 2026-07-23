<?php

namespace App\Mcp\Tools;

use App\Enums\LibraryPermission;
use App\Models\User;
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

#[Name('prepare-library-checkout')]
#[Title('Prepare a Library Desk checkout')]
#[Description('Validates a prospective checkout for an authorized librarian or admin without changing inventory. A Library Desk user must explicitly confirm the checkout in the application.')]
#[IsReadOnly]
#[IsIdempotent]
class PrepareLibraryCheckoutTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && $user->can(LibraryPermission::ManageLoans->value);
    }

    public function handle(Request $request, LibraryAssistantData $library): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('An authenticated librarian or administrator is required.');
        }

        $validated = $request->validate([
            'book_id' => ['required', 'integer', 'min:1'],
            'borrower_id' => ['required', 'integer', 'min:1'],
            'due_at' => ['nullable', 'date', 'after:today'],
        ]);

        return Response::structured([
            'checkout' => $library->prepareCheckout(
                $user,
                $validated['book_id'],
                $validated['borrower_id'],
                $validated['due_at'] ?? null,
            ),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'book_id' => $schema->integer()
                ->description('The available book identifier.')
                ->required(),
            'borrower_id' => $schema->integer()
                ->description('The member identifier who will borrow the book.')
                ->required(),
            'due_at' => $schema->string()
                ->description('Optional future due date in YYYY-MM-DD format.'),
        ];
    }
}
