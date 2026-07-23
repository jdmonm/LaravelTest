<?php

use App\Enums\LibraryRole;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LibraryAuthorizationSeeder::class);
});

test('unavailable book checkout errors are associated with the selected book field', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole(LibraryRole::Librarian);

    $member = User::factory()->create();
    $member->assignRole(LibraryRole::Member);

    $book = Book::factory()->create([
        'total_copies' => 1,
        'available_copies' => 0,
    ]);

    $this->actingAs($librarian)
        ->from(route('loans.index'))
        ->post(route('loans.store'), [
            'book_id' => $book->id,
            'borrower_id' => $member->id,
            'due_at' => now()->addWeek()->toDateString(),
        ])
        ->assertSessionHasErrors([
            'book_id' => 'This book has no available copies.',
        ])
        ->assertRedirect(route('loans.index'));

    expect(Loan::query()->count())->toBe(0);
});
