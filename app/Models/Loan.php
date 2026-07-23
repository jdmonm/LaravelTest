<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $book_id
 * @property int $borrower_id
 * @property CarbonImmutable $checked_out_at
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property Book $book
 * @property User $borrower
 */
#[Fillable([
    'book_id',
    'borrower_id',
    'checked_out_at',
    'due_at',
    'returned_at',
])]
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory;

    /**
     * Get the book that was loaned.
     *
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Get the user borrowing the book.
     *
     * @return BelongsTo<User, $this>
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    /**
     * Determine whether the active loan is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->returned_at === null && $this->due_at?->isPast() === true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_out_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }
}
