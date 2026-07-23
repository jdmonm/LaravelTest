<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    BookOpenText,
    Bot,
    Check,
    Send,
    Sparkles,
    UserRound,
} from '@lucide/vue';
import { nextTick, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Spinner from '@/components/ui/spinner/Spinner.vue';
import { dashboard } from '@/routes';
import {
    index as assistantIndex,
    store as storeAssistantMessage,
} from '@/routes/assistant';
import { store as storeAssistantCheckout } from '@/routes/assistant/checkouts';

type ChatMessage = {
    id: number;
    role: 'assistant' | 'user';
    content: string;
};

type PreparedCheckout = {
    confirmation_token: string;
    book: {
        id: number;
        title: string;
        author: string;
        available_copies: number;
    };
    borrower: {
        id: number;
        name: string;
    };
    due_at: string;
};

const props = defineProps<{
    assistantAvailable: boolean;
    canManageLoans: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Assistant',
                href: assistantIndex(),
            },
        ],
    },
});

const messages = ref<ChatMessage[]>([
    {
        id: 1,
        role: 'assistant',
        content:
            'Ask me to find books, check live availability, or review your loans. Library staff can prepare a checkout for confirmation.',
    },
]);
const draft = ref('');
const error = ref('');
const isSending = ref(false);
const isConfirmingCheckout = ref(false);
const messageId = ref(2);
const transcript = ref<HTMLElement | null>(null);
const preparedCheckout = ref<PreparedCheckout | null>(null);

const suggestions = [
    'What books by Ursula K. Le Guin are available?',
    'Show my active loans.',
    ...(props.canManageLoans ? ['Find an available book to check out.'] : []),
];

async function sendMessage(message = draft.value): Promise<void> {
    const content = message.trim();

    if (!content || isSending.value || !props.assistantAvailable) {
        return;
    }

    error.value = '';
    messages.value.push({
        id: messageId.value++,
        role: 'user',
        content,
    });
    draft.value = '';
    isSending.value = true;
    await scrollTranscript();

    try {
        const response = await fetch(storeAssistantMessage.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ message: content }),
        });
        const payload = (await response.json()) as {
            message?: string;
            checkout?: PreparedCheckout | null;
        };

        if (!response.ok || !payload.message) {
            throw new Error(
                payload.message ||
                    'The assistant could not answer that request.',
            );
        }

        messages.value.push({
            id: messageId.value++,
            role: 'assistant',
            content: payload.message,
        });
        preparedCheckout.value = payload.checkout ?? null;
    } catch (caughtError) {
        error.value =
            caughtError instanceof Error
                ? caughtError.message
                : 'The assistant could not answer that request.';
    } finally {
        isSending.value = false;
        await scrollTranscript();
    }
}

async function confirmCheckout(): Promise<void> {
    if (!preparedCheckout.value || isConfirmingCheckout.value) {
        return;
    }

    error.value = '';
    isConfirmingCheckout.value = true;

    try {
        const response = await fetch(storeAssistantCheckout.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                checkout_token: preparedCheckout.value.confirmation_token,
            }),
        });
        const payload = (await response.json()) as { message?: string };

        if (!response.ok || !payload.message) {
            throw new Error(
                response.status === 429
                    ? retryAfterMessage(response)
                    : payload.message || 'The checkout could not be completed.',
            );
        }

        messages.value.push({
            id: messageId.value++,
            role: 'assistant',
            content: payload.message,
        });
        preparedCheckout.value = null;
    } catch (caughtError) {
        error.value =
            caughtError instanceof Error
                ? caughtError.message
                : 'The checkout could not be completed.';
    } finally {
        isConfirmingCheckout.value = false;
        await scrollTranscript();
    }
}

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function retryAfterMessage(response: Response): string {
    const retryAfter = response.headers.get('Retry-After');

    return retryAfter
        ? `Too many requests. Try again in ${retryAfter} seconds.`
        : 'Too many requests. Please try again shortly.';
}

async function scrollTranscript(): Promise<void> {
    await nextTick();

    transcript.value?.scrollTo({
        top: transcript.value.scrollHeight,
        behavior: 'smooth',
    });
}
</script>

<template>
    <Head title="Assistant" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                as="h1"
                title="Library assistant"
                description="Search the catalogue, check availability, and manage the borrowing work you are authorized to do."
            />
            <Badge :variant="assistantAvailable ? 'secondary' : 'destructive'">
                <Sparkles class="size-3.5" />
                {{ assistantAvailable ? 'Ready' : 'Needs configuration' }}
            </Badge>
        </div>

        <div
            class="grid min-h-0 flex-1 gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]"
        >
            <section
                class="flex min-h-[34rem] flex-col overflow-hidden rounded-lg border bg-card"
                aria-label="Library assistant conversation"
            >
                <div
                    ref="transcript"
                    class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-4 sm:p-6"
                    aria-live="polite"
                >
                    <article
                        v-for="message in messages"
                        :key="message.id"
                        class="flex gap-3"
                        :class="
                            message.role === 'user'
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <div
                            v-if="message.role === 'assistant'"
                            class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary text-secondary-foreground"
                            aria-hidden="true"
                        >
                            <Bot class="size-4" />
                        </div>
                        <div
                            class="max-w-[85%] rounded-lg px-3.5 py-3 text-sm leading-6 whitespace-pre-wrap"
                            :class="
                                message.role === 'user'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-foreground'
                            "
                        >
                            {{ message.content }}
                        </div>
                        <div
                            v-if="message.role === 'user'"
                            class="flex size-8 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground"
                            aria-hidden="true"
                        >
                            <UserRound class="size-4" />
                        </div>
                    </article>

                    <div v-if="isSending" class="flex items-center gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary text-secondary-foreground"
                            aria-hidden="true"
                        >
                            <Bot class="size-4" />
                        </div>
                        <div
                            class="flex items-center gap-2 rounded-lg bg-muted px-3.5 py-3 text-sm text-muted-foreground"
                        >
                            <Spinner />
                            Checking Library Desk
                        </div>
                    </div>
                </div>

                <div class="border-t bg-background p-3 sm:p-4">
                    <p
                        v-if="error"
                        class="mb-3 text-sm text-destructive"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <p
                        v-if="!assistantAvailable"
                        class="mb-3 text-sm text-muted-foreground"
                    >
                        Add an OpenAI API key to enable the library assistant in
                        this environment.
                    </p>
                    <div
                        v-if="preparedCheckout"
                        class="mb-3 rounded-md border bg-muted/40 p-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    Review checkout
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ preparedCheckout.book.title }} by
                                    {{ preparedCheckout.book.author }} for
                                    {{ preparedCheckout.borrower.name }}. Due
                                    {{ preparedCheckout.due_at }}.
                                </p>
                            </div>
                            <Badge variant="secondary">
                                {{ preparedCheckout.book.available_copies }}
                                available
                            </Badge>
                        </div>
                        <Button
                            v-if="canManageLoans"
                            type="button"
                            class="mt-3"
                            :disabled="isConfirmingCheckout"
                            @click="confirmCheckout"
                        >
                            <Spinner v-if="isConfirmingCheckout" />
                            <Check v-else />
                            Confirm checkout
                        </Button>
                    </div>
                    <form
                        class="flex items-end gap-2"
                        @submit.prevent="sendMessage()"
                    >
                        <textarea
                            v-model="draft"
                            class="min-h-20 w-full resize-y rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!assistantAvailable || isSending"
                            maxlength="1000"
                            placeholder="Ask about books or borrowing"
                            aria-label="Ask the library assistant"
                            @keydown.enter.exact.prevent="sendMessage()"
                        />
                        <Button
                            type="submit"
                            size="icon"
                            :disabled="
                                !draft.trim() ||
                                !assistantAvailable ||
                                isSending
                            "
                            title="Send message"
                        >
                            <Spinner v-if="isSending" />
                            <Send v-else />
                            <span class="sr-only">Send message</span>
                        </Button>
                    </form>
                </div>
            </section>

            <aside class="space-y-4">
                <section class="rounded-lg border bg-card p-4">
                    <div class="flex items-center gap-2">
                        <BookOpenText class="size-4 text-muted-foreground" />
                        <h2 class="text-sm font-medium">Live library data</h2>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        Availability and loan status come from the current
                        Library Desk records.
                    </p>
                </section>

                <section class="rounded-lg border bg-card p-4">
                    <h2 class="text-sm font-medium">Try asking</h2>
                    <div class="mt-3 flex flex-col gap-2">
                        <Button
                            v-for="suggestion in suggestions"
                            :key="suggestion"
                            type="button"
                            variant="outline"
                            class="h-auto justify-start px-3 py-2 text-left text-sm font-normal whitespace-normal"
                            :disabled="!assistantAvailable || isSending"
                            @click="sendMessage(suggestion)"
                        >
                            {{ suggestion }}
                        </Button>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</template>
