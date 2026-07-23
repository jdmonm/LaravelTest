<?php

namespace App\Actions\Library;

use App\Models\Book;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInLoan
{
    /**
     * Close a loan and return one copy to inventory.
     */
    public function handle(Loan $loan): Loan
    {
        return DB::transaction(function () use ($loan): Loan {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->returned_at !== null) {
                throw ValidationException::withMessages([
                    'loan' => 'This loan has already been checked in.',
                ]);
            }

            $book = Book::query()->lockForUpdate()->findOrFail($loan->book_id);

            $loan->update(['returned_at' => now()]);
            $book->increment('available_copies');

            return $loan->refresh();
        });
    }
}
