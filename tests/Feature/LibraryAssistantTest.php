<?php

use App\Enums\LibraryRole;
use App\Models\AssistantCheckoutDraft;
use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LibraryAuthorizationSeeder::class);
});

function assistantUser(LibraryRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('the library assistant requires an authenticated user', function () {
    $this->get(route('assistant.index'))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('assistant.index'))
        ->assertOk();
});

test('the library assistant page shows whether the user can manage loans', function () {
    $member = assistantUser(LibraryRole::Member);
    $librarian = assistantUser(LibraryRole::Librarian);

    $this->actingAs($member)
        ->get(route('assistant.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('library/AssistantIndex')
            ->where('canManageLoans', false));

    $this->actingAs($librarian)
        ->get(route('assistant.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('library/AssistantIndex')
            ->where('canManageLoans', true));
});

test('the library assistant grounds catalogue replies in live search results', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    Book::factory()->create([
        'title' => 'Kindred',
        'author' => 'Octavia E. Butler',
        'total_copies' => 3,
        'available_copies' => 2,
    ]);

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_catalogue',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_library_books',
                                'arguments' => '{"query":"Kindred","limit":8}',
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Kindred by Octavia E. Butler has 2 available copies.',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs(assistantUser(LibraryRole::Member))
        ->postJson(route('assistant.store'), [
            'message' => 'Is Kindred available?',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Kindred by Octavia E. Butler has 2 available copies.')
        ->assertJsonPath('checkout', null);

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request): bool {
        $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && str_contains($payload, 'search_library_books')
            && str_contains($payload, 'Kindred')
            && str_contains($payload, 'available_copies');
    });
});

test('the library assistant retains recent conversation context for a follow-up book reference', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    $member = assistantUser(LibraryRole::Member);
    $book = Book::factory()->create([
        'title' => 'Kindred',
        'author' => 'Octavia E. Butler',
        'total_copies' => 4,
        'available_copies' => 4,
    ]);

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_initial_catalogue',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_library_books',
                                'arguments' => '{"query":"Kindred"}',
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Kindred by Octavia E. Butler has 4 available copies. Tell me if you want to continue with this book.',
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_follow_up_catalogue',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_library_book',
                                'arguments' => json_encode(['book_id' => $book->id], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Kindred is still available. A staff member can now prepare the checkout.',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs($member);

    $this->postJson(route('assistant.store'), [
        'message' => 'Find an available book to check out.',
    ])->assertOk();

    $this->postJson(route('assistant.store'), [
        'message' => 'Yes, I would like to book that one please.',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Kindred is still available. A staff member can now prepare the checkout.');

    Http::assertSentCount(4);
    Http::assertSent(function (Request $request): bool {
        $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);

        return str_contains($payload, 'Find an available book to check out.')
            && str_contains($payload, 'Kindred by Octavia E. Butler has 4 available copies.')
            && str_contains($payload, 'Yes, I would like to book that one please.');
    });
});

test('assistant conversation history is encrypted before it is stored in the session', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    $member = assistantUser(LibraryRole::Member);
    $message = 'Sensitive assistant conversation detail';

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_history_catalogue',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_library_books',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Sensitive assistant response detail',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs($member)
        ->postJson(route('assistant.store'), ['message' => $message])
        ->assertOk();

    $storedHistory = session('library-assistant.history.'.$member->id);

    expect($storedHistory)->toBeString()
        ->and($storedHistory)->not->toContain($message)
        ->and(Crypt::decryptString($storedHistory))->toContain($message);
});

test('the library assistant exposes only the signed-in user\'s loans to OpenAI', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    $member = assistantUser(LibraryRole::Member);
    $otherMember = assistantUser(LibraryRole::Member);
    $ownBook = Book::factory()->create(['title' => 'A User-Scoped Loan']);
    $otherBook = Book::factory()->create(['title' => 'A Private Other Loan']);
    Loan::factory()->for($ownBook)->for($member, 'borrower')->create();
    Loan::factory()->for($otherBook)->for($otherMember, 'borrower')->create();

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_my_loans',
                            'type' => 'function',
                            'function' => [
                                'name' => 'list_my_library_loans',
                                'arguments' => '{"status":"active"}',
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'You have one active loan: A User-Scoped Loan.',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs($member)
        ->postJson(route('assistant.store'), [
            'message' => 'Show my active loans.',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'You have one active loan: A User-Scoped Loan.');

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request): bool {
        return data_get($request->data(), 'tool_choice') === [
            'type' => 'function',
            'function' => ['name' => 'list_my_library_loans'],
        ];
    });
    Http::assertSent(function (Request $request) use ($ownBook, $otherBook): bool {
        $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);

        return str_contains($payload, '"role":"tool"')
            && str_contains($payload, $ownBook->title)
            && ! str_contains($payload, $otherBook->title);
    });
});

test('staff can prepare an assistant checkout without mutating inventory', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    $librarian = assistantUser(LibraryRole::Librarian);
    $member = assistantUser(LibraryRole::Member);
    $book = Book::factory()->create([
        'total_copies' => 2,
        'available_copies' => 2,
    ]);
    $dueAt = now()->addWeek()->toDateString();

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_prepare_checkout',
                            'type' => 'function',
                            'function' => [
                                'name' => 'prepare_library_checkout',
                                'arguments' => json_encode([
                                    'book_id' => $book->id,
                                    'borrower_id' => $member->id,
                                    'due_at' => $dueAt,
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'The checkout is prepared. Review and confirm it before lending the book.',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs($librarian)
        ->postJson(route('assistant.store'), [
            'message' => "Prepare {$book->title} for {$member->name}.",
        ])
        ->assertOk()
        ->assertJsonPath('checkout.confirmation_token', AssistantCheckoutDraft::query()->value('id'))
        ->assertJsonPath('checkout.book.id', $book->id)
        ->assertJsonPath('checkout.borrower.id', $member->id)
        ->assertJsonPath('checkout.due_at', $dueAt);

    expect($book->refresh()->available_copies)->toBe(2)
        ->and(Loan::query()->count())->toBe(0)
        ->and(AssistantCheckoutDraft::query()->count())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return ! str_contains(
            json_encode($request->data(), JSON_THROW_ON_ERROR),
            (string) AssistantCheckoutDraft::query()->value('id'),
        );
    });
});

test('the library assistant creates only one checkout draft per message', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    $librarian = assistantUser(LibraryRole::Librarian);
    $member = assistantUser(LibraryRole::Member);
    $firstBook = Book::factory()->create();
    $secondBook = Book::factory()->create();
    $dueAt = now()->addWeek()->toDateString();

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_first_checkout',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'prepare_library_checkout',
                                    'arguments' => json_encode([
                                        'book_id' => $firstBook->id,
                                        'borrower_id' => $member->id,
                                        'due_at' => $dueAt,
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_second_checkout',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'prepare_library_checkout',
                                    'arguments' => json_encode([
                                        'book_id' => $secondBook->id,
                                        'borrower_id' => $member->id,
                                        'due_at' => $dueAt,
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'The first checkout is ready for review.',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs($librarian)
        ->postJson(route('assistant.store'), [
            'message' => 'Prepare both books for this member.',
        ])
        ->assertOk()
        ->assertJsonPath('checkout.book.id', $firstBook->id)
        ->assertJsonPath('checkout.borrower.id', $member->id);

    expect(AssistantCheckoutDraft::query()->count())->toBe(1)
        ->and(AssistantCheckoutDraft::query()->value('book_id'))->toBe($firstBook->id);
});

test('only staff can explicitly confirm an assistant checkout', function () {
    $member = assistantUser(LibraryRole::Member);
    $librarian = assistantUser(LibraryRole::Librarian);
    $book = Book::factory()->create([
        'total_copies' => 1,
        'available_copies' => 1,
    ]);
    $draft = AssistantCheckoutDraft::query()->create([
        'prepared_by_id' => $librarian->id,
        'book_id' => $book->id,
        'borrower_id' => $member->id,
        'due_at' => now()->addWeek()->toDateString(),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($member)
        ->postJson(route('assistant.checkouts.store'), [
            'checkout_token' => $draft->id,
        ])
        ->assertForbidden();

    $response = $this->actingAs($librarian)
        ->postJson(route('assistant.checkouts.store'), [
            'checkout_token' => $draft->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Book checked out successfully.')
        ->assertJsonPath('loan.book.id', $book->id)
        ->assertJsonPath('loan.borrower.id', $member->id);

    $loanId = $response->json('loan.id');

    $this->actingAs($librarian)
        ->postJson(route('assistant.checkouts.store'), [
            'checkout_token' => $draft->id,
        ])
        ->assertOk()
        ->assertJsonPath('loan.id', $loanId);

    expect($book->refresh()->available_copies)->toBe(0)
        ->and(Loan::query()->count())->toBe(1)
        ->and($draft->refresh()->loan_id)->toBe($loanId);
});

test('checkout confirmation remains available after the chat request budget is exhausted', function () {
    config()->set('services.openai.api_key', null);
    $librarian = assistantUser(LibraryRole::Librarian);
    $member = assistantUser(LibraryRole::Member);
    $book = Book::factory()->create();
    $draft = AssistantCheckoutDraft::query()->create([
        'prepared_by_id' => $librarian->id,
        'book_id' => $book->id,
        'borrower_id' => $member->id,
        'due_at' => now()->addWeek()->toDateString(),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($librarian);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson(route('assistant.store'), [
            'message' => "Assistant request {$attempt}",
        ])->assertStatus(503);
    }

    $this->postJson(route('assistant.checkouts.store'), [
        'checkout_token' => $draft->id,
    ])
        ->assertOk()
        ->assertJsonPath('loan.book.id', $book->id);
});

test('an expired or staff-owned checkout token cannot create a loan', function () {
    $firstLibrarian = assistantUser(LibraryRole::Librarian);
    $secondLibrarian = assistantUser(LibraryRole::Librarian);
    $member = assistantUser(LibraryRole::Member);
    $book = Book::factory()->create();
    $expiredDraft = AssistantCheckoutDraft::query()->create([
        'prepared_by_id' => $firstLibrarian->id,
        'book_id' => $book->id,
        'borrower_id' => $member->id,
        'due_at' => now()->addWeek()->toDateString(),
        'expires_at' => now()->subMinute(),
    ]);
    $activeDraft = AssistantCheckoutDraft::query()->create([
        'prepared_by_id' => $firstLibrarian->id,
        'book_id' => $book->id,
        'borrower_id' => $member->id,
        'due_at' => now()->addWeek()->toDateString(),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($firstLibrarian)
        ->postJson(route('assistant.checkouts.store'), [
            'checkout_token' => $expiredDraft->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This checkout confirmation is invalid or has expired.');

    $this->actingAs($secondLibrarian)
        ->postJson(route('assistant.checkouts.store'), [
            'checkout_token' => $activeDraft->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This checkout confirmation is invalid or has expired.');

    expect($book->refresh()->available_copies)->toBe($book->total_copies)
        ->and(Loan::query()->count())->toBe(0);
});

test('the assistant chat limit follows the user across IP addresses', function () {
    config()->set('services.openai.api_key', null);
    $member = assistantUser(LibraryRole::Member);

    $this->actingAs($member);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->withServerVariables([
            'REMOTE_ADDR' => $attempt % 2 === 0 ? '198.51.100.1' : '203.0.113.1',
        ])->postJson(route('assistant.store'), [
            'message' => "Assistant request {$attempt}",
        ])->assertStatus(503);
    }

    $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.1',
    ])->postJson(route('assistant.store'), [
        'message' => 'This request exceeds the user limit.',
    ])->assertTooManyRequests();
});

test('the assistant discards privileged conversation history after a role change', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    $librarian = assistantUser(LibraryRole::Librarian);
    $member = assistantUser(LibraryRole::Member);
    $book = Book::factory()->create(['title' => 'Permission-Safe Catalogue Book']);

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_staff_members',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_library_members',
                                'arguments' => json_encode(['query' => $member->name], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => "Privileged member lookup: {$member->name}.",
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_member_catalogue',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_library_books',
                                'arguments' => json_encode(['query' => $book->title], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'The catalogue result is available.',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs($librarian)
        ->postJson(route('assistant.store'), [
            'message' => 'Find this member for a checkout.',
        ])
        ->assertOk();

    $librarian->syncRoles(LibraryRole::Member);
    $librarian = $librarian->fresh();

    $this->actingAs($librarian)
        ->postJson(route('assistant.store'), [
            'message' => 'Show the catalogue result.',
        ])
        ->assertOk();

    Http::assertSent(function (Request $request) use ($member): bool {
        $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);

        return str_contains($payload, 'Show the catalogue result.')
            && ! str_contains($payload, 'Privileged member lookup')
            && ! str_contains($payload, $member->name);
    });
});

test('the assistant returns a final response after four tool rounds', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    $book = Book::factory()->create(['title' => 'Four-Round Book']);
    $toolResponse = static fn (string $id): array => [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $id,
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_library_book',
                        'arguments' => json_encode(['book_id' => $book->id], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ],
        ]],
    ];

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push($toolResponse('call_one'))
            ->push($toolResponse('call_two'))
            ->push($toolResponse('call_three'))
            ->push($toolResponse('call_four'))
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Four tool rounds completed successfully.',
                    ],
                ]],
            ]),
    ]);

    $this->actingAs(assistantUser(LibraryRole::Member))
        ->postJson(route('assistant.store'), [
            'message' => 'Check this book carefully.',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Four tool rounds completed successfully.');

    Http::assertSentCount(5);
    Http::assertSent(function (Request $request): bool {
        return data_get($request->data(), 'tool_choice') === 'none';
    });
});

test('the library assistant returns a safe response when the provider rejects a request', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Provider quota exceeded'],
        ], 429),
    ]);

    $this->actingAs(assistantUser(LibraryRole::Member))
        ->postJson(route('assistant.store'), [
            'message' => 'Find a book.',
        ])
        ->assertStatus(503)
        ->assertJsonPath('message', 'The Library Desk assistant is temporarily unavailable. Please try again.');
});

test('the library assistant returns a safe response when the provider connection fails', function () {
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.model', 'gpt-4o-mini-2024-07-18');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');

    Http::fake(function (): never {
        throw new ConnectionException('Connection failed.');
    });

    $this->actingAs(assistantUser(LibraryRole::Member))
        ->postJson(route('assistant.store'), [
            'message' => 'Find a book.',
        ])
        ->assertStatus(503)
        ->assertJsonPath('message', 'The Library Desk assistant is temporarily unavailable. Please try again.');
});

test('the library assistant reports a safe error when OpenAI is not configured', function () {
    config()->set('services.openai.api_key', null);

    $this->actingAs(assistantUser(LibraryRole::Member))
        ->postJson(route('assistant.store'), [
            'message' => 'How can I browse the catalogue?',
        ])
        ->assertStatus(503)
        ->assertJsonPath('message', 'The Library Desk assistant is not configured yet.');

    Http::assertNothingSent();
});
