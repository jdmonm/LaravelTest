<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    BookCopy,
    BookOpenCheck,
    Clock3,
    ClipboardList,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type MetricSet = {
    books: number;
    availableCopies: number;
    activeLoans: number;
    overdueLoans: number;
};

type RecentLoan = {
    id: number;
    book: {
        id: number;
        title: string;
        author: string;
    } | null;
    due_at: string | null;
    is_overdue: boolean;
};

defineProps<{
    metrics: MetricSet;
    recentLoans: RecentLoan[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();
const permissions = computed(() => page.props.auth.permissions);
const canViewBooks = computed(() => permissions.value.includes('books.view'));
const canViewLoans = computed(
    () =>
        permissions.value.includes('loans.view') ||
        permissions.value.includes('loans.view-own'),
);
const canViewAllLoans = computed(() =>
    permissions.value.includes('loans.view'),
);

function formatDate(value: string | null): string {
    if (!value) {
        return 'No due date';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                as="h1"
                title="Library overview"
                :description="
                    canViewAllLoans
                        ? 'Monitor inventory and current circulation.'
                        : 'Keep track of your library activity.'
                "
            />
            <div class="flex gap-2">
                <Button v-if="canViewBooks" as-child variant="outline">
                    <Link href="/books">
                        Browse catalogue
                        <ArrowRight />
                    </Link>
                </Button>
                <Button v-if="canViewLoans" as-child>
                    <Link href="/loans">
                        View loans
                        <ArrowRight />
                    </Link>
                </Button>
            </div>
        </div>

        <section
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            aria-label="Library metrics"
        >
            <div class="rounded-lg border p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">Titles</p>
                        <p class="mt-2 text-3xl font-semibold tabular-nums">
                            {{ metrics.books }}
                        </p>
                    </div>
                    <BookCopy class="size-5 text-muted-foreground" />
                </div>
            </div>
            <div class="rounded-lg border p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Available copies
                        </p>
                        <p class="mt-2 text-3xl font-semibold tabular-nums">
                            {{ metrics.availableCopies }}
                        </p>
                    </div>
                    <BookOpenCheck class="size-5 text-muted-foreground" />
                </div>
            </div>
            <div class="rounded-lg border p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Active loans
                        </p>
                        <p class="mt-2 text-3xl font-semibold tabular-nums">
                            {{ metrics.activeLoans }}
                        </p>
                    </div>
                    <Clock3 class="size-5 text-muted-foreground" />
                </div>
            </div>
            <div class="rounded-lg border p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">Overdue</p>
                        <p class="mt-2 text-3xl font-semibold tabular-nums">
                            {{ metrics.overdueLoans }}
                        </p>
                    </div>
                    <AlertTriangle class="size-5 text-destructive" />
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-lg border">
            <div class="flex items-center justify-between border-b px-5 py-4">
                <div>
                    <h2 class="text-base font-semibold">
                        {{
                            canViewAllLoans
                                ? 'Current circulation'
                                : 'Your active loans'
                        }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            canViewAllLoans
                                ? 'The five most recently checked-out titles.'
                                : 'Books currently checked out to you.'
                        }}
                    </p>
                </div>
                <ClipboardList class="size-5 text-muted-foreground" />
            </div>

            <div
                v-if="recentLoans.length === 0"
                class="flex min-h-48 items-center justify-center px-5 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    No active loans right now.
                </p>
            </div>
            <ul v-else class="divide-y">
                <li
                    v-for="loan in recentLoans"
                    :key="loan.id"
                    class="flex items-center justify-between gap-4 px-5 py-4"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ loan.book?.title ?? 'Removed book' }}
                        </p>
                        <p class="mt-1 truncate text-sm text-muted-foreground">
                            {{ loan.book?.author ?? 'Unknown author' }}
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <Badge
                            :variant="
                                loan.is_overdue ? 'destructive' : 'secondary'
                            "
                        >
                            {{ loan.is_overdue ? 'Overdue' : 'Due' }}
                        </Badge>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ formatDate(loan.due_at) }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
