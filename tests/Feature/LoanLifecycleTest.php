<?php

use App\Actions\Library\CheckInLoan;
use App\Actions\Library\CheckOutBook;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('checking out and checking in a book keeps inventory in sync', function () {
    $book = Book::factory()->create([
        'total_copies' => 1,
        'available_copies' => 1,
    ]);
    $borrower = User::factory()->create();

    $loan = app(CheckOutBook::class)->handle($book, $borrower, now()->addWeek());

    expect($book->refresh()->available_copies)->toBe(0)
        ->and($loan->borrower->is($borrower))->toBeTrue()
        ->and($loan->returned_at)->toBeNull();

    app(CheckInLoan::class)->handle($loan);

    expect($book->refresh()->available_copies)->toBe(1)
        ->and($loan->refresh()->returned_at)->not->toBeNull();
});

test('a book without available copies cannot be checked out', function () {
    $book = Book::factory()->create(['available_copies' => 0]);

    expect(fn() => app(CheckOutBook::class)->handle($book, User::factory()->create()))
        ->toThrow(ValidationException::class);
});

test('only active loans past their due date are overdue', function () {
    $overdueLoan = Loan::factory()->create(['due_at' => now()->subDay()]);
    $returnedLoan = Loan::factory()->create([
        'due_at' => now()->subDay(),
        'returned_at' => now(),
    ]);

    expect($overdueLoan->isOverdue())->toBeTrue()
        ->and($returnedLoan->isOverdue())->toBeFalse();
});
