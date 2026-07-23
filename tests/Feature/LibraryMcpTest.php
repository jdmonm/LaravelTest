<?php

use App\Enums\LibraryRole;
use App\Mcp\Resources\LibraryCatalogueResource;
use App\Mcp\Servers\LibraryDeskServer;
use App\Mcp\Tools\GetLibraryBookTool;
use App\Mcp\Tools\ListMyLibraryLoansTool;
use App\Mcp\Tools\PrepareLibraryCheckoutTool;
use App\Mcp\Tools\SearchLibraryBooksTool;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LibraryAuthorizationSeeder::class);
});

function mcpLibraryUser(LibraryRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('the Library Desk MCP server exposes live catalogue data', function () {
    $book = Book::factory()->create([
        'title' => 'The Dispossessed',
        'author' => 'Ursula K. Le Guin',
        'total_copies' => 3,
        'available_copies' => 2,
    ]);

    LibraryDeskServer::tool(SearchLibraryBooksTool::class, [
        'query' => 'Dispossessed',
    ])
        ->assertOk()
        ->assertName('search-library-books')
        ->assertSee(['The Dispossessed', 'available_copies', '2']);

    LibraryDeskServer::tool(GetLibraryBookTool::class, [
        'book_id' => $book->id,
    ])
        ->assertOk()
        ->assertSee(['The Dispossessed', 'Ursula K. Le Guin']);

    LibraryDeskServer::resource(LibraryCatalogueResource::class)
        ->assertOk()
        ->assertSee(['The Dispossessed', 'available']);
});

test('unauthenticated local MCP sessions cannot access loan or checkout tools', function () {
    $book = Book::factory()->create();
    $member = User::factory()->create();

    LibraryDeskServer::tool(ListMyLibraryLoansTool::class)
        ->assertHasErrors(['not found']);

    LibraryDeskServer::tool(PrepareLibraryCheckoutTool::class, [
        'book_id' => $book->id,
        'borrower_id' => $member->id,
    ])
        ->assertHasErrors(['not found']);
});

test('authenticated users can use the MCP loan tools only within their authority', function () {
    $member = mcpLibraryUser(LibraryRole::Member);
    $otherMember = mcpLibraryUser(LibraryRole::Member);
    $book = Book::factory()->create();
    $loan = Loan::factory()->for($book)->for($member, 'borrower')->create();
    Loan::factory()->for($book)->for($otherMember, 'borrower')->create();

    LibraryDeskServer::actingAs($member)->tool(ListMyLibraryLoansTool::class)
        ->assertOk()
        ->assertSee([$loan->book->title])
        ->assertDontSee([$otherMember->name]);

    LibraryDeskServer::actingAs($member)->tool(PrepareLibraryCheckoutTool::class, [
        'book_id' => $book->id,
        'borrower_id' => $member->id,
    ])
        ->assertHasErrors(['not found']);
});

test('staff can prepare a checkout through MCP without changing inventory', function () {
    $librarian = mcpLibraryUser(LibraryRole::Librarian);
    $member = mcpLibraryUser(LibraryRole::Member);
    $book = Book::factory()->create([
        'total_copies' => 2,
        'available_copies' => 2,
    ]);

    LibraryDeskServer::actingAs($librarian)->tool(PrepareLibraryCheckoutTool::class, [
        'book_id' => $book->id,
        'borrower_id' => $member->id,
    ])
        ->assertOk()
        ->assertSee([$book->title, $member->name]);

    expect($book->refresh()->available_copies)->toBe(2)
        ->and(Loan::query()->count())->toBe(0);
});
