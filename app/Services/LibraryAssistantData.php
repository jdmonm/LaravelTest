<?php

namespace App\Services;

use App\Actions\Library\CheckOutBook;
use App\Enums\LibraryPermission;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LibraryAssistantData
{
    /**
     * Search the public catalogue exposed by the local MCP server.
     *
     * @return list<array<string, mixed>>
     */
    public function searchCatalogue(string $query = '', int $limit = 8): array
    {
        return array_values(Book::query()
            ->when($query !== '', static function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder
                        ->where('title', 'like', "%{$query}%")
                        ->orWhere('author', 'like', "%{$query}%")
                        ->orWhere('isbn', 'like', "%{$query}%");
                });
            })
            ->orderBy('title')
            ->limit($this->limit($limit))
            ->get()
            ->map(fn (Book $book): array => $this->bookSummary($book))
            ->values()
            ->all());
    }

    /**
     * Search the catalogue that is available to the signed-in user.
     *
     * @return list<array<string, mixed>>
     */
    public function searchBooks(User $user, string $query = '', int $limit = 8): array
    {
        $this->authorize($user, LibraryPermission::ViewBooks);

        return $this->searchCatalogue($query, $limit);
    }

    /**
     * Return an individual catalogue record available to the signed-in user.
     *
     * @return array<string, mixed>
     */
    public function book(User $user, int $bookId): array
    {
        $this->authorize($user, LibraryPermission::ViewBooks);

        return $this->catalogueBook($bookId);
    }

    /**
     * Return a single catalogue record exposed by the local MCP server.
     *
     * @return array<string, mixed>
     */
    public function catalogueBook(int $bookId): array
    {
        $book = Book::query()->find($bookId);

        if ($book === null) {
            throw ValidationException::withMessages([
                'book_id' => 'The selected book does not exist.',
            ]);
        }

        return [
            ...$this->bookSummary($book),
            'description' => $book->description,
        ];
    }

    /**
     * Return only the signed-in user's borrowing history.
     *
     * @return list<array<string, mixed>>
     */
    public function ownLoans(User $user, string $status = 'all', int $limit = 10): array
    {
        if (
            ! $user->can(LibraryPermission::ViewOwnLoans->value)
            && ! $user->can(LibraryPermission::ViewLoans->value)
        ) {
            throw new AuthorizationException;
        }

        return array_values(Loan::query()
            ->with('book:id,title,author,isbn,available_copies,total_copies')
            ->where('borrower_id', $user->id)
            ->when($status === 'active', static function (Builder $query): void {
                $query->whereNull('returned_at')->where(function (Builder $query): void {
                    $query->whereNull('due_at')->orWhere('due_at', '>=', now());
                });
            })
            ->when($status === 'overdue', static function (Builder $query): void {
                $query->whereNull('returned_at')->where('due_at', '<', now());
            })
            ->when($status === 'returned', static function (Builder $query): void {
                $query->whereNotNull('returned_at');
            })
            ->latest('checked_out_at')
            ->limit($this->limit($limit))
            ->get()
            ->map(fn (Loan $loan): array => $this->loanSummary($loan))
            ->values()
            ->all());
    }

    /**
     * Find possible borrowers for a staff checkout workflow.
     *
     * @return list<array<string, int|string>>
     */
    public function searchMembers(User $user, string $query, int $limit = 8): array
    {
        $this->authorize($user, LibraryPermission::ManageLoans);

        return array_values(User::query()
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit($this->limit($limit))
            ->get(['id', 'name'])
            ->map(static fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
            ])
            ->values()
            ->all());
    }

    /**
     * Validate a checkout without changing stock, so the user can confirm it explicitly.
     *
     * @return array<string, mixed>
     */
    public function prepareCheckout(User $user, int $bookId, int $borrowerId, ?string $dueAt = null): array
    {
        $this->authorize($user, LibraryPermission::ManageLoans);

        $book = Book::query()->find($bookId);
        $borrower = User::query()->find($borrowerId);

        if ($book === null || $borrower === null) {
            throw ValidationException::withMessages([
                $book === null ? 'book_id' : 'borrower_id' => 'The selected record does not exist.',
            ]);
        }

        if ($book->available_copies < 1) {
            throw ValidationException::withMessages([
                'book_id' => 'This book has no available copies.',
            ]);
        }

        $resolvedDueAt = $this->dueAt($dueAt);

        return [
            'book' => $this->bookSummary($book),
            'borrower' => [
                'id' => $borrower->id,
                'name' => $borrower->name,
            ],
            'due_at' => $resolvedDueAt->toDateString(),
        ];
    }

    /**
     * Confirm a staff checkout with the existing inventory-safe action.
     */
    public function checkOut(User $user, int $bookId, int $borrowerId, ?string $dueAt = null): Loan
    {
        $this->authorize($user, LibraryPermission::ManageLoans);

        $book = Book::query()->find($bookId);
        $borrower = User::query()->find($borrowerId);

        if ($book === null || $borrower === null) {
            throw ValidationException::withMessages([
                $book === null ? 'book_id' : 'borrower_id' => 'The selected record does not exist.',
            ]);
        }

        return app(CheckOutBook::class)->handle($book, $borrower, $this->dueAt($dueAt));
    }

    /**
     * @return array<string, mixed>
     */
    public function loan(Loan $loan): array
    {
        $loan->loadMissing('book:id,title,author,isbn,available_copies,total_copies', 'borrower:id,name');

        return [
            ...$this->loanSummary($loan),
            'borrower' => [
                'id' => $loan->borrower->id,
                'name' => $loan->borrower->name,
            ],
        ];
    }

    private function authorize(User $user, LibraryPermission $permission): void
    {
        if (! $user->can($permission->value)) {
            throw new AuthorizationException;
        }
    }

    private function dueAt(?string $dueAt): CarbonInterface
    {
        $validated = Validator::validate([
            'due_at' => $dueAt,
        ], [
            'due_at' => ['nullable', 'date', 'after:today'],
        ]);

        return isset($validated['due_at'])
            ? now()->parse($validated['due_at'])
            : now()->addWeeks(2);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function bookSummary(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'published_year' => $book->published_year,
            'total_copies' => $book->total_copies,
            'available_copies' => $book->available_copies,
            'availability' => $book->available_copies > 0 ? 'available' : 'unavailable',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanSummary(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'book' => $this->bookSummary($loan->book),
            'checked_out_at' => $loan->checked_out_at->toDateString(),
            'due_at' => $loan->due_at?->toDateString(),
            'returned_at' => $loan->returned_at?->toDateString(),
            'status' => $loan->returned_at !== null
                ? 'returned'
                : ($loan->isOverdue() ? 'overdue' : 'active'),
        ];
    }

    private function limit(int $limit): int
    {
        return min(max($limit, 1), 20);
    }
}
