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

function libraryUser(LibraryRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('unverified users are redirected away from Library Desk routes', function () {
    $user = User::factory()->unverified()->create();

    foreach (['dashboard', 'books.index', 'loans.index', 'access.index'] as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertRedirect(route('verification.notice'));
    }
});

test('members can browse the catalogue but cannot create books', function () {
    Book::factory()->create(['title' => 'The Left Hand of Darkness']);
    $member = libraryUser(LibraryRole::Member);

    $this->actingAs($member)
        ->get(route('books.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('library/BooksIndex')
            ->has('books.data', 1));

    $this->actingAs($member)
        ->post(route('books.store'), [
            'title' => 'A Memory Called Empire',
            'author' => 'Arkady Martine',
            'total_copies' => 2,
        ])
        ->assertForbidden();
});

test('librarians can add books and search the catalogue', function () {
    $librarian = libraryUser(LibraryRole::Librarian);
    Book::factory()->create(['title' => 'The Dispossessed', 'author' => 'Ursula K. Le Guin']);

    $this->actingAs($librarian)
        ->post(route('books.store'), [
            'title' => 'Kindred',
            'author' => 'Octavia E. Butler',
            'isbn' => '9780807083697',
            'total_copies' => 3,
        ])
        ->assertRedirect(route('books.index'));

    $this->assertDatabaseHas('books', [
        'title' => 'Kindred',
        'available_copies' => 3,
    ]);

    $this->actingAs($librarian)
        ->get(route('books.index', ['search' => 'Kindred']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('library/BooksIndex')
            ->has('books.data', 1)
            ->where('books.data.0.title', 'Kindred'));
});

test('updating inventory preserves availability for active loans', function () {
    $librarian = libraryUser(LibraryRole::Librarian);
    $borrower = libraryUser(LibraryRole::Member);
    $book = Book::factory()->create([
        'total_copies' => 3,
        'available_copies' => 2,
    ]);
    Loan::factory()->for($book)->for($borrower, 'borrower')->create();

    $this->actingAs($librarian)
        ->patch(route('books.update', $book), [
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'description' => $book->description,
            'published_year' => $book->published_year,
            'total_copies' => 4,
        ])
        ->assertRedirect(route('books.index'));

    expect($book->refresh()->total_copies)->toBe(4)
        ->and($book->available_copies)->toBe(3);
});

test('members only see their own loans', function () {
    $member = libraryUser(LibraryRole::Member);
    $otherMember = libraryUser(LibraryRole::Member);
    $ownLoan = Loan::factory()->for($member, 'borrower')->create();
    Loan::factory()->for($otherMember, 'borrower')->create();

    $this->actingAs($member)
        ->get(route('loans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('library/LoansIndex')
            ->has('loans.data', 1)
            ->where('loans.data.0.id', $ownLoan->id)
            ->where('canManageLoans', false));
});

test('loan responses provide date-only values and a server-calculated overdue status', function () {
    $librarian = libraryUser(LibraryRole::Librarian);
    $borrower = libraryUser(LibraryRole::Member);
    $book = Book::factory()->create();
    $overdueLoan = Loan::factory()->for($book)->for($borrower, 'borrower')->create([
        'checked_out_at' => now()->subDays(2),
        'due_at' => now()->subDay(),
    ]);
    $returnedLoan = Loan::factory()->for($book)->for($borrower, 'borrower')->create([
        'checked_out_at' => now()->subDay(),
        'due_at' => now()->subDays(3),
        'returned_at' => now(),
    ]);

    $this->actingAs($librarian)
        ->get(route('loans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('library/LoansIndex')
            ->where('loans.data.0.id', $returnedLoan->id)
            ->where('loans.data.0.due_at', $returnedLoan->due_at?->toDateString())
            ->where('loans.data.0.returned_at', $returnedLoan->returned_at?->toDateString())
            ->where('loans.data.0.is_overdue', false)
            ->where('loans.data.1.id', $overdueLoan->id)
            ->where('loans.data.1.due_at', $overdueLoan->due_at?->toDateString())
            ->where('loans.data.1.returned_at', null)
            ->where('loans.data.1.is_overdue', true));
});

test('librarians can check books out and back in', function () {
    $librarian = libraryUser(LibraryRole::Librarian);
    $member = libraryUser(LibraryRole::Member);
    $book = Book::factory()->create([
        'total_copies' => 1,
        'available_copies' => 1,
    ]);

    $this->actingAs($librarian)
        ->post(route('loans.store'), [
            'book_id' => $book->id,
            'borrower_id' => $member->id,
            'due_at' => now()->addWeek()->toDateString(),
        ])
        ->assertRedirect(route('loans.index'));

    expect($book->refresh()->available_copies)->toBe(0);

    $loan = Loan::query()->sole();

    $this->actingAs($librarian)
        ->patch(route('loans.check-in', $loan))
        ->assertRedirect(route('loans.index'));

    expect($book->refresh()->available_copies)->toBe(1)
        ->and($loan->refresh()->returned_at)->not->toBeNull();
});

test('administrators can change a member role', function () {
    $admin = libraryUser(LibraryRole::Admin);
    $member = libraryUser(LibraryRole::Member);

    $this->actingAs($admin)
        ->patch(route('access.update', $member), ['role' => LibraryRole::Librarian->value])
        ->assertRedirect(route('access.index'));

    expect($member->fresh()->hasRole(LibraryRole::Librarian))->toBeTrue();
});
