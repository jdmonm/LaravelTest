<?php

use App\Enums\LibraryRole;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

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

test('loan form receives the server-calculated minimum due date', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole(LibraryRole::Librarian);

    $this->actingAs($librarian)
        ->get(route('loans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('library/LoansIndex')
            ->where('minDueDate', today()->addDay()->toDateString()));
});
