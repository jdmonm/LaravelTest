<?php

namespace App\Actions\Library;

use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckOutBook
{
    /**
     * Create a loan and reserve one available copy.
     */
    public function handle(Book $book, User $borrower, ?CarbonInterface $dueAt = null): Loan
    {
        return DB::transaction(function () use ($book, $borrower, $dueAt): Loan {
            $book = Book::query()->lockForUpdate()->findOrFail($book->id);

            if ($book->available_copies < 1) {
                throw ValidationException::withMessages([
                    'book' => 'This book has no available copies.',
                ]);
            }

            $loan = $book->loans()->create([
                'borrower_id' => $borrower->id,
                'checked_out_at' => now(),
                'due_at' => $dueAt ?? now()->addWeeks(2),
            ]);

            $book->decrement('available_copies');

            return $loan;
        });
    }
}
