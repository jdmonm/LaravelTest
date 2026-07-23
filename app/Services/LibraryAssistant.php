<?php

namespace App\Services;

use App\Enums\LibraryPermission;
use App\Exceptions\LibraryAssistantUnavailableException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use UnexpectedValueException;

class LibraryAssistant
{
    private const MAX_TOOL_ROUNDS = 4;

    private const MAX_CONVERSATION_MESSAGES = 8;

    private const SEARCH_BOOKS_TOOL = 'search_library_books';

    private const GET_BOOK_TOOL = 'get_library_book';

    private const LIST_MY_LOANS_TOOL = 'list_my_library_loans';

    private const SEARCH_MEMBERS_TOOL = 'search_library_members';

    private const PREPARE_CHECKOUT_TOOL = 'prepare_library_checkout';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are the Library Desk assistant. Help users find books, check availability, review their own borrowing history, and help authorized staff prepare a checkout.

        Use the available Library Desk tools before making any factual claim about catalogue records, availability, loans, members, or checkout eligibility. Never invent book identifiers, member identifiers, availability, or loan details.

        The loan list is always scoped to the signed-in user's own loans. For a request to show the user's loans, always call list_my_library_loans and never use a catalogue tool. Do not infer or disclose other borrowers' loans. Member search and checkout preparation are only available to authorized staff.

        Recent user and assistant messages may be provided as conversation context. Use them to resolve unambiguous references such as “that one”, “the first book”, or “it”. If the context identifies a book or member, use a live Library Desk tool to verify the current record before responding. Do not search for a pronoun literally. If the reference is ambiguous, ask a concise clarifying question.

        A checkout preparation is not a completed checkout. Prepare no more than one checkout per user message. When a checkout tool returns a prepared checkout, clearly tell the user that they must review and explicitly confirm it in Library Desk. Never claim a book was checked out until the application confirms it.
        PROMPT;

    public function __construct(
        private readonly AssistantCheckoutDrafts $checkoutDrafts,
        private readonly LibraryAssistantData $library,
    ) {
        //
    }

    public function isAvailable(): bool
    {
        return filled($this->apiKey())
            && filled($this->model())
            && filled($this->baseUrl());
    }

    /**
     * Ask OpenAI to answer with permission-aware Library Desk data.
     *
     * @param  array<int, mixed>  $history
     * @return array{message: string, checkout: array<string, mixed>|null}
     *
     * @throws LibraryAssistantUnavailableException
     */
    public function answer(User $user, string $message, array $history = []): array
    {
        if (! $this->isAvailable()) {
            throw LibraryAssistantUnavailableException::notConfigured();
        }

        try {
            $messages = [
                [
                    'role' => 'system',
                    'content' => self::SYSTEM_PROMPT,
                ],
                ...$this->conversationMessages($history),
                [
                    'role' => 'user',
                    'content' => $message,
                ],
            ];
            $checkout = null;
            $toolRounds = 0;

            while (true) {
                $assistantMessage = $this->assistantMessage($this->chat([
                    'model' => $this->model(),
                    'messages' => $messages,
                    'tools' => $this->toolsFor($user, $checkout === null),
                    'tool_choice' => $this->toolChoice($user, $message, $toolRounds),
                    'parallel_tool_calls' => false,
                    'temperature' => 0,
                ]));

                $toolCalls = $assistantMessage['tool_calls'] ?? [];

                if (! is_array($toolCalls) || $toolCalls === []) {
                    $content = $assistantMessage['content'] ?? null;

                    if (! is_string($content) || blank($content)) {
                        throw new UnexpectedValueException('OpenAI returned an empty assistant message.');
                    }

                    return [
                        'message' => Str::limit(trim($content), 4000, ''),
                        'checkout' => $checkout,
                    ];
                }

                if ($toolRounds >= self::MAX_TOOL_ROUNDS) {
                    throw new UnexpectedValueException('The assistant exceeded the allowed number of Library Desk tool calls.');
                }

                $messages[] = [
                    'role' => 'assistant',
                    'content' => is_string($assistantMessage['content'] ?? null)
                        ? $assistantMessage['content']
                        : null,
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $toolCall) {
                    if (! is_array($toolCall) || ! is_string($toolCall['id'] ?? null)) {
                        throw new UnexpectedValueException('OpenAI returned an invalid tool call.');
                    }

                    $result = $this->callTool($user, $toolCall, $checkout === null);

                    if ($checkout === null && $result['checkout'] !== null) {
                        $checkout = $result['checkout'];
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => json_encode($result['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ];
                }

                $toolRounds++;
            }
        } catch (LibraryAssistantUnavailableException $exception) {
            throw $exception;
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
            report($exception);

            throw LibraryAssistantUnavailableException::providerUnavailable();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function chat(array $payload): array
    {
        $response = $this->client()
            ->post('chat/completions', $payload)
            ->throw();

        $json = $response->json();

        if (! is_array($json)) {
            throw new UnexpectedValueException('OpenAI returned an invalid response.');
        }

        return $json;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout());
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function assistantMessage(array $response): array
    {
        $message = data_get($response, 'choices.0.message');

        if (! is_array($message)) {
            throw new UnexpectedValueException('OpenAI returned a response without an assistant message.');
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $toolCall
     * @return array{data: array<string, mixed>, checkout: array<string, mixed>|null}
     */
    private function callTool(User $user, array $toolCall, bool $canPrepareCheckout): array
    {
        $name = data_get($toolCall, 'function.name');
        $encodedArguments = data_get($toolCall, 'function.arguments');

        if (! is_string($name) || ! is_string($encodedArguments)) {
            throw new UnexpectedValueException('OpenAI returned an invalid tool call.');
        }

        try {
            $arguments = json_decode($encodedArguments, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($arguments)) {
                throw new UnexpectedValueException('OpenAI returned invalid tool arguments.');
            }

            return match ($name) {
                self::SEARCH_BOOKS_TOOL => $this->searchedBooks($user, $arguments),
                self::GET_BOOK_TOOL => $this->foundBook($user, $arguments),
                self::LIST_MY_LOANS_TOOL => $this->listedOwnLoans($user, $arguments),
                self::SEARCH_MEMBERS_TOOL => $this->searchedMembers($user, $arguments),
                self::PREPARE_CHECKOUT_TOOL => $canPrepareCheckout
                    ? $this->preparedCheckout($user, $arguments)
                    : $this->checkoutAlreadyPrepared(),
                default => [
                    'data' => ['error' => 'That Library Desk operation is unavailable for this user.'],
                    'checkout' => null,
                ],
            };
        } catch (AuthorizationException) {
            return [
                'data' => ['error' => 'You do not have permission to use that Library Desk operation.'],
                'checkout' => null,
            ];
        } catch (ValidationException $exception) {
            return [
                'data' => ['error' => $this->validationMessage($exception)],
                'checkout' => null,
            ];
        } catch (JsonException|UnexpectedValueException) {
            return [
                'data' => ['error' => 'The assistant sent invalid arguments for that Library Desk operation.'],
                'checkout' => null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{data: array<string, mixed>, checkout: null}
     */
    private function searchedBooks(User $user, array $arguments): array
    {
        $validated = $this->validated($arguments, [
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return [
            'data' => ['books' => $this->library->searchBooks(
                $user,
                $validated['query'] ?? '',
                $validated['limit'] ?? 8,
            )],
            'checkout' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{data: array<string, mixed>, checkout: null}
     */
    private function foundBook(User $user, array $arguments): array
    {
        $validated = $this->validated($arguments, [
            'book_id' => ['required', 'integer', 'min:1'],
        ]);

        return [
            'data' => ['book' => $this->library->book($user, $validated['book_id'])],
            'checkout' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{data: array<string, mixed>, checkout: null}
     */
    private function listedOwnLoans(User $user, array $arguments): array
    {
        $validated = $this->validated($arguments, [
            'status' => ['nullable', 'in:all,active,overdue,returned'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return [
            'data' => ['loans' => $this->library->ownLoans(
                $user,
                $validated['status'] ?? 'all',
                $validated['limit'] ?? 10,
            )],
            'checkout' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{data: array<string, mixed>, checkout: null}
     */
    private function searchedMembers(User $user, array $arguments): array
    {
        $validated = $this->validated($arguments, [
            'query' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return [
            'data' => ['members' => $this->library->searchMembers(
                $user,
                $validated['query'],
                $validated['limit'] ?? 8,
            )],
            'checkout' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{data: array<string, mixed>, checkout: array<string, mixed>}
     */
    private function preparedCheckout(User $user, array $arguments): array
    {
        $validated = $this->validated($arguments, [
            'book_id' => ['required', 'integer', 'min:1'],
            'borrower_id' => ['required', 'integer', 'min:1'],
            'due_at' => ['nullable', 'date', 'after:today'],
        ]);
        $preparedCheckout = $this->checkoutDrafts->prepare(
            $user,
            $validated['book_id'],
            $validated['borrower_id'],
            $validated['due_at'] ?? null,
        );

        return [
            'data' => ['checkout' => $preparedCheckout['checkout']],
            'checkout' => [
                'confirmation_token' => $preparedCheckout['confirmation_token'],
                ...$preparedCheckout['checkout'],
            ],
        ];
    }

    /**
     * @return array{data: array<string, string>, checkout: null}
     */
    private function checkoutAlreadyPrepared(): array
    {
        return [
            'data' => [
                'error' => 'A checkout is already prepared for this message. Ask the user to review and confirm it before preparing another checkout.',
            ],
            'checkout' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validated(array $arguments, array $rules): array
    {
        return Validator::validate($arguments, $rules);
    }

    private function validationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (isset($messages[0])) {
                return $messages[0];
            }
        }

        return 'The Library Desk operation could not be validated.';
    }

    /**
     * Restrict session history to plain recent turns before sending it to OpenAI.
     *
     * @param  array<int, mixed>  $history
     * @return list<array{role: string, content: string}>
     */
    private function conversationMessages(array $history): array
    {
        $messages = [];

        foreach (array_slice($history, -self::MAX_CONVERSATION_MESSAGES) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;

            if (! in_array($role, ['assistant', 'user'], true) || ! is_string($content) || blank($content)) {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => Str::limit(trim($content), 4000, ''),
            ];
        }

        return $messages;
    }

    /**
     * Make explicit own-loan requests deterministic without broadening access.
     *
     * @return array<string, mixed>|string
     */
    private function toolChoice(User $user, string $message, int $round): array|string
    {
        if ($round >= self::MAX_TOOL_ROUNDS) {
            return 'none';
        }

        if ($round > 0) {
            return 'auto';
        }

        if ($this->canListOwnLoans($user) && $this->isOwnLoanRequest($message)) {
            return [
                'type' => 'function',
                'function' => ['name' => self::LIST_MY_LOANS_TOOL],
            ];
        }

        return 'required';
    }

    private function isOwnLoanRequest(string $message): bool
    {
        $normalized = Str::ascii(Str::lower($message));
        $mentionsLoan = str_contains($normalized, 'loan') || str_contains($normalized, 'prestamo');
        $mentionsSelf = preg_match('/\b(my|mine|mis|mio|mia|mios|mias)\b/', $normalized) === 1;

        return $mentionsLoan && $mentionsSelf;
    }

    private function canListOwnLoans(User $user): bool
    {
        return $user->can(LibraryPermission::ViewOwnLoans->value)
            || $user->can(LibraryPermission::ViewLoans->value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolsFor(User $user, bool $canPrepareCheckout = true): array
    {
        $tools = [
            $this->searchBooksTool(),
            $this->getBookTool(),
        ];

        if ($this->canListOwnLoans($user)) {
            $tools[] = $this->listMyLoansTool();
        }

        if ($user->can(LibraryPermission::ManageLoans->value)) {
            $tools[] = $this->searchMembersTool();

            if ($canPrepareCheckout) {
                $tools[] = $this->prepareCheckoutTool();
            }
        }

        return $tools;
    }

    /**
     * @return array<string, mixed>
     */
    private function searchBooksTool(): array
    {
        return $this->tool(
            self::SEARCH_BOOKS_TOOL,
            'Searches the live catalogue by title, author, or ISBN. Use before answering questions about books or availability.',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional title, author, or ISBN search term.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of books to return, from 1 to 20.',
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getBookTool(): array
    {
        return $this->tool(
            self::GET_BOOK_TOOL,
            'Gets the live details and availability for one book identifier.',
            [
                'book_id' => [
                    'type' => 'integer',
                    'description' => 'The Library Desk book identifier.',
                ],
            ],
            ['book_id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function listMyLoansTool(): array
    {
        return $this->tool(
            self::LIST_MY_LOANS_TOOL,
            'Lists only the signed-in user’s own loans. Use for questions about their borrowing history or due dates.',
            [
                'status' => [
                    'type' => 'string',
                    'enum' => ['all', 'active', 'overdue', 'returned'],
                    'description' => 'Optional loan status filter.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of loans to return, from 1 to 20.',
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function searchMembersTool(): array
    {
        return $this->tool(
            self::SEARCH_MEMBERS_TOOL,
            'Searches members by name for an authorized staff checkout workflow. Use only when a staff user wants to prepare a checkout.',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'Member name search term.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of members to return, from 1 to 20.',
                ],
            ],
            ['query'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareCheckoutTool(): array
    {
        return $this->tool(
            self::PREPARE_CHECKOUT_TOOL,
            'Validates a checkout for authorized staff without changing inventory. Use only after identifying one available book and one member. The user must explicitly confirm the prepared checkout in the application.',
            [
                'book_id' => [
                    'type' => 'integer',
                    'description' => 'The available book identifier.',
                ],
                'borrower_id' => [
                    'type' => 'integer',
                    'description' => 'The member identifier who will borrow the book.',
                ],
                'due_at' => [
                    'type' => 'string',
                    'description' => 'Optional future due date in YYYY-MM-DD format.',
                ],
            ],
            ['book_id', 'borrower_id'],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private function apiKey(): string
    {
        return (string) config('services.openai.api_key', '');
    }

    private function model(): string
    {
        return (string) config('services.openai.model', '');
    }

    private function baseUrl(): string
    {
        return (string) config('services.openai.base_url', '');
    }

    private function timeout(): int
    {
        $timeout = (int) config('services.openai.request_timeout', 20);

        return max(1, $timeout);
    }
}
