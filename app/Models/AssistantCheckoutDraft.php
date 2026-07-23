<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $prepared_by_id
 * @property int $book_id
 * @property int $borrower_id
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable $expires_at
 * @property int|null $loan_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property User $preparedBy
 * @property Book $book
 * @property User $borrower
 * @property Loan|null $loan
 */
#[Fillable([
    'prepared_by_id',
    'book_id',
    'borrower_id',
    'due_at',
    'expires_at',
    'loan_id',
])]
class AssistantCheckoutDraft extends Model
{
    use HasUuids;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * Get the staff member who prepared the checkout.
     *
     * @return BelongsTo<User, $this>
     */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    /**
     * Get the book to lend when this draft is confirmed.
     *
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Get the member who will borrow the book.
     *
     * @return BelongsTo<User, $this>
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    /**
     * Get the loan created by a successful confirmation.
     *
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_date',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
