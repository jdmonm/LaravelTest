<?php

namespace App\Http\Controllers\Library;

use App\Enums\LibraryPermission;
use App\Exceptions\LibraryAssistantUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\ConfirmAssistantCheckoutRequest;
use App\Http\Requests\Library\StoreAssistantMessageRequest;
use App\Models\User;
use App\Services\AssistantCheckoutDrafts;
use App\Services\LibraryAssistant;
use App\Services\LibraryAssistantData;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

class AssistantController extends Controller
{
    private const HISTORY_SESSION_PREFIX = 'library-assistant.history';

    private const MAX_CONVERSATION_MESSAGES = 8;

    /**
     * Display the Library Desk assistant.
     */
    public function index(
        Request $request,
        LibraryAssistant $assistant,
    ): Response {
        $user = $this->user($request);
        $request->session()->forget($this->historyKey($user));

        return Inertia::render('library/AssistantIndex', [
            'assistantAvailable' => $assistant->isAvailable(),
            'canManageLoans' => $user->can(LibraryPermission::ManageLoans->value),
        ]);
    }

    /**
     * Generate a grounded Library Desk response.
     */
    public function store(
        StoreAssistantMessageRequest $request,
        LibraryAssistant $assistant,
    ): JsonResponse {
        $user = $this->user($request);
        $message = $request->string('message')->trim()->toString();

        try {
            $result = $assistant->answer(
                $user,
                $message,
                $this->conversationHistory($request, $user),
            );
            $this->storeConversationHistory($request, $user, $message, $result['message']);

            return response()->json($result);
        } catch (LibraryAssistantUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->status);
        }
    }

    /**
     * Confirm a checkout that staff explicitly reviewed in the assistant interface.
     */
    public function confirmCheckout(
        ConfirmAssistantCheckoutRequest $request,
        AssistantCheckoutDrafts $checkoutDrafts,
        LibraryAssistantData $library,
    ): JsonResponse {
        try {
            $loan = $checkoutDrafts->confirm(
                $this->user($request),
                $request->string('checkout_token')->toString(),
            );

            return response()->json([
                'message' => 'Book checked out successfully.',
                'loan' => $library->loan($loan),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->errors()['checkout_token'][0]
                    ?? 'The checkout could not be completed.',
            ], 422);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function conversationHistory(Request $request, User $user): array
    {
        $encryptedHistory = $request->session()->get($this->historyKey($user));

        if (! is_string($encryptedHistory)) {
            $request->session()->forget($this->historyKey($user));

            return [];
        }

        try {
            $storedHistory = json_decode(Crypt::decryptString($encryptedHistory), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException|DecryptException) {
            $request->session()->forget($this->historyKey($user));

            return [];
        }

        if (
            ! is_array($storedHistory)
            || ! is_string($storedHistory['permission_fingerprint'] ?? null)
            || ! is_array($storedHistory['messages'] ?? null)
            || ! hash_equals($this->permissionFingerprint($user), $storedHistory['permission_fingerprint'])
        ) {
            $request->session()->forget($this->historyKey($user));

            return [];
        }

        $history = [];

        foreach (array_slice($storedHistory['messages'], -self::MAX_CONVERSATION_MESSAGES) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;

            if (
                ! is_string($role)
                || ! in_array($role, ['assistant', 'user'], true)
                || ! is_string($content)
                || blank($content)
            ) {
                continue;
            }

            $history[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $history;
    }

    private function storeConversationHistory(Request $request, User $user, string $message, string $response): void
    {
        $history = [
            ...$this->conversationHistory($request, $user),
            ['role' => 'user', 'content' => $message],
            ['role' => 'assistant', 'content' => $response],
        ];

        try {
            $encryptedHistory = Crypt::encryptString(json_encode([
                'permission_fingerprint' => $this->permissionFingerprint($user),
                'messages' => array_slice($history, -self::MAX_CONVERSATION_MESSAGES),
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return;
        }

        $request->session()->put($this->historyKey($user), $encryptedHistory);
    }

    private function historyKey(User $user): string
    {
        return self::HISTORY_SESSION_PREFIX.'.'.(string) $user->getKey();
    }

    private function permissionFingerprint(User $user): string
    {
        return hash('sha256', $user->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->implode('|'));
    }
}
