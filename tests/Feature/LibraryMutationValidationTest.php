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

test('check-in validation errors are associated with a loan', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole(LibraryRole::Librarian);

    $member = User::factory()->create();
    $member->assignRole(LibraryRole::Member);

    $book = Book::factory()->create(['available_copies' => 1]);
    $loan = Loan::factory()
        ->for($book)
        ->for($member, 'borrower')
        ->create(['returned_at' => now()]);

    $this->actingAs($librarian)
        ->from(route('loans.index'))
        ->patch(route('loans.check-in', $loan))
        ->assertSessionHasErrors([
            'loan' => 'This loan has already been checked in.',
        ])
        ->assertRedirect(route('loans.index'));
});

test('role update validation errors are associated with a role', function () {
    $admin = User::factory()->create();
    $admin->assignRole(LibraryRole::Admin);

    $this->actingAs($admin)
        ->from(route('access.index'))
        ->patch(route('access.update', $admin), [
            'role' => LibraryRole::Member->value,
        ])
        ->assertSessionHasErrors([
            'role' => 'You cannot remove your own administrator access.',
        ])
        ->assertRedirect(route('access.index'));
});
