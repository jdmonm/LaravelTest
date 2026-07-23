<?php

use App\Models\Book;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a book exposes its active loans separately from returned loans', function () {
    $book = Book::factory()->create([
        'total_copies' => 3,
        'available_copies' => 2,
    ]);

    $activeLoan = Loan::factory()->for($book)->create();
    Loan::factory()->for($book)->create(['returned_at' => now()]);

    expect($book->activeLoans()->count())->toBe(1)
        ->and($activeLoan->book->is($book))->toBeTrue()
        ->and($book->available_copies)->toBe(2);
});
