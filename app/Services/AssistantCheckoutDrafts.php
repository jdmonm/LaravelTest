<?php

namespace App\Services;

use App\Actions\Library\CheckOutBook;
use App\Enums\LibraryPermission;
use App\Models\AssistantCheckoutDraft;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssistantCheckoutDrafts
{
    private const EXPIRATION_MINUTES = 15;

    public function __construct(
        private readonly CheckOutBook $checkOutBook,
        private readonly LibraryAssistantData $library,
    ) {
        //
    }

    /**
     * Create a short-lived confirmation token after validating a checkout.
     *
     * @return array{confirmation_token: string, checkout: array<string, mixed>}
     */
    public function prepare(User $user, int $bookId, int $borrowerId, ?string $dueAt = null): array
    {
        $checkout = $this->library->prepareCheckout($user, $bookId, $borrowerId, $dueAt);
        $draft = AssistantCheckoutDraft::query()->create([
            'prepared_by_id' => $user->id,
            'book_id' => $bookId,
            'borrower_id' => $borrowerId,
            'due_at' => $checkout['due_at'],
            'expires_at' => now()->addMinutes(self::EXPIRATION_MINUTES),
        ]);

        return [
            'confirmation_token' => (string) $draft->getKey(),
            'checkout' => $checkout,
        ];
    }

    /**
     * Atomically create or replay the loan associated with a checkout draft.
     */
    public function confirm(User $user, string $confirmationToken): Loan
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $confirmationToken): Loan {
            $draft = AssistantCheckoutDraft::query()
                ->lockForUpdate()
                ->find($confirmationToken);

            if (! $draft instanceof AssistantCheckoutDraft || $draft->prepared_by_id !== $user->id) {
                throw $this->invalidConfirmation();
            }

            if ($draft->loan_id !== null) {
                $loan = Loan::query()->find($draft->loan_id);

                if ($loan instanceof Loan) {
                    return $loan;
                }

                throw $this->invalidConfirmation();
            }

            if ($draft->expires_at->isPast()) {
                throw $this->invalidConfirmation();
            }

            $book = Book::query()->find($draft->book_id);
            $borrower = User::query()->find($draft->borrower_id);

            if (! $book instanceof Book || ! $borrower instanceof User) {
                throw $this->invalidConfirmation();
            }

            $loan = $this->checkOutBook->handle($book, $borrower, $draft->due_at);
            $draft->update(['loan_id' => $loan->id]);

            return $loan;
        });
    }

    private function authorize(User $user): void
    {
        if (! $user->can(LibraryPermission::ManageLoans->value)) {
            throw new AuthorizationException;
        }
    }

    private function invalidConfirmation(): ValidationException
    {
        return ValidationException::withMessages([
            'checkout_token' => 'This checkout confirmation is invalid or has expired.',
        ]);
    }
}
