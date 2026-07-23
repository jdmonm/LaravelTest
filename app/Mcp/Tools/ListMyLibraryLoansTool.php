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

#[Name('list-my-library-loans')]
#[Title('List my Library Desk loans')]
#[Description('Lists the authenticated user’s own loans, including due dates and current status. This tool is unavailable without an authenticated Library Desk user.')]
#[IsReadOnly]
#[IsIdempotent]
class ListMyLibraryLoansTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && ($user->can(LibraryPermission::ViewOwnLoans->value)
                || $user->can(LibraryPermission::ViewLoans->value));
    }

    public function handle(Request $request, LibraryAssistantData $library): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('An authenticated Library Desk user is required.');
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:all,active,overdue,returned'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return Response::structured([
            'loans' => $library->ownLoans(
                $user,
                $validated['status'] ?? 'all',
                $validated['limit'] ?? 10,
            ),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['all', 'active', 'overdue', 'returned'])
                ->description('Optional status filter.')
                ->default('all'),
            'limit' => $schema->integer()
                ->description('Maximum number of loans to return, between 1 and 20.')
                ->default(10),
        ];
    }
}
