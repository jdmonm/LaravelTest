<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, ClipboardList, Plus, Search } from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { PaginationLink } from '@/components/LibraryPagination.vue';
import LibraryPagination from '@/components/LibraryPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import Spinner from '@/components/ui/spinner/Spinner.vue';
import { usePendingMutation } from '@/composables/usePendingMutation';
import { dashboard } from '@/routes';
import {
    checkIn as checkInLoan,
    index as loansIndex,
    store as storeLoan,
} from '@/routes/loans';

type BookOption = {
    id: number;
    title: string;
    author: string;
    available_copies?: number;
};

type MemberOption = {
    id: number;
    name: string;
    email: string;
};

type Loan = {
    id: number;
    book: BookOption;
    borrower: MemberOption;
    checked_out_at: string | null;
    due_at: string | null;
    returned_at: string | null;
    is_overdue: boolean;
};

type PaginatedLoans = {
    data: Loan[];
    links: PaginationLink[];
};

const props = defineProps<{
    loans: PaginatedLoans;
    filters: {
        search: string;
        status: string;
    };
    canManageLoans: boolean;
    minDueDate: string;
    availableBooks: BookOption[];
    members: MemberOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Loans',
                href: loansIndex(),
            },
        ],
    },
});

const search = ref(props.filters.search);
const selectedStatus = ref(props.filters.status || 'all');
const isCheckoutDialogOpen = ref(false);
const checkInMutation = usePendingMutation();
const checkoutForm = useForm({
    book_id: '',
    borrower_id: '',
    due_at: '',
});

const visibleStatusLabel = computed(() => {
    if (selectedStatus.value === 'all') {
        return 'All statuses';
    }

    return (
        selectedStatus.value.charAt(0).toUpperCase() +
        selectedStatus.value.slice(1)
    );
});

function updateFilters(status = selectedStatus.value): void {
    router.get(
        loansIndex.url(),
        {
            search: search.value || undefined,
            status: status === 'all' ? undefined : status,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function setStatus(status: AcceptableValue): void {
    if (status === null) {
        return;
    }

    selectedStatus.value = String(status);
    updateFilters(selectedStatus.value);
}

function openCheckoutDialog(): void {
    checkoutForm.reset();
    checkoutForm.clearErrors();
    isCheckoutDialogOpen.value = true;
}

function submitCheckout(): void {
    checkoutForm.post(storeLoan.url(), {
        preserveScroll: true,
        onSuccess: () => {
            isCheckoutDialogOpen.value = false;
        },
    });
}

function checkIn(loan: Loan): void {
    if (!checkInMutation.start(loan.id)) {
        return;
    }

    router.patch(
        checkInLoan.url(loan),
        {},
        {
            preserveScroll: true,
            onError: (errors) => checkInMutation.setError(loan.id, errors),
            onFinish: () => checkInMutation.finish(loan.id),
        },
    );
}

function loanStatus(loan: Loan): string {
    if (loan.returned_at) {
        return 'Returned';
    }

    return loan.is_overdue ? 'Overdue' : 'On loan';
}

function statusVariant(loan: Loan): 'default' | 'secondary' | 'destructive' {
    if (loan.is_overdue) {
        return 'destructive';
    }

    return loan.returned_at ? 'secondary' : 'default';
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'No due date';
    }

    const [year = 0, month = 1, day = 1] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}
</script>

<template>
    <Head title="Loans" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                as="h1"
                title="Loans"
                :description="
                    canManageLoans
                        ? 'Track check-outs, returns, and due dates.'
                        : 'Review your current and past loans.'
                "
            />
            <Button v-if="canManageLoans" @click="openCheckoutDialog">
                <Plus />
                Check out book
            </Button>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <form
                class="flex min-w-0 flex-1 gap-2"
                @submit.prevent="updateFilters()"
            >
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        class="pl-9"
                        placeholder="Search books or borrowers"
                        aria-label="Search loans"
                    />
                </div>
                <Button type="submit" variant="outline">Search</Button>
            </form>

            <Select
                :model-value="selectedStatus"
                @update:model-value="setStatus"
            >
                <SelectTrigger class="w-full sm:w-42">
                    <SelectValue :placeholder="visibleStatusLabel" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="overdue">Overdue</SelectItem>
                    <SelectItem value="returned">Returned</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <div
                v-if="loans.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center gap-3 px-6 text-center"
            >
                <ClipboardList class="size-8 text-muted-foreground" />
                <div>
                    <p class="font-medium">No loans found</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            canManageLoans
                                ? 'Check out a book when a member is ready.'
                                : 'Your borrowing history will appear here.'
                        }}
                    </p>
                </div>
                <Button
                    v-if="canManageLoans"
                    variant="outline"
                    @click="openCheckoutDialog"
                >
                    <Plus />
                    Check out book
                </Button>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-180 text-left text-sm">
                    <thead
                        class="border-b bg-muted/40 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Book</th>
                            <th class="px-4 py-3 font-medium">Borrower</th>
                            <th class="px-4 py-3 font-medium">Due date</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th
                                v-if="canManageLoans"
                                class="w-64 px-4 py-3 text-right font-medium"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="loan in loans.data"
                            :key="loan.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-4 align-top">
                                <p class="font-medium">{{ loan.book.title }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ loan.book.author }}
                                </p>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <p>{{ loan.borrower.name }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ loan.borrower.email }}
                                </p>
                            </td>
                            <td
                                class="px-4 py-4 align-top text-muted-foreground"
                            >
                                {{ formatDate(loan.due_at) }}
                            </td>
                            <td class="px-4 py-4 align-top">
                                <Badge :variant="statusVariant(loan)">
                                    {{ loanStatus(loan) }}
                                </Badge>
                            </td>
                            <td
                                v-if="canManageLoans"
                                class="px-4 py-4 align-top"
                            >
                                <div class="flex flex-col items-end gap-1">
                                    <Button
                                        v-if="!loan.returned_at"
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        :title="`Check in ${loan.book.title}`"
                                        :disabled="
                                            checkInMutation.isPending(loan.id)
                                        "
                                        @click="checkIn(loan)"
                                    >
                                        <Spinner
                                            v-if="
                                                checkInMutation.isPending(
                                                    loan.id,
                                                )
                                            "
                                        />
                                        <Check v-else />
                                        <span class="sr-only">
                                            {{
                                                checkInMutation.isPending(
                                                    loan.id,
                                                )
                                                    ? `Checking in ${loan.book.title}`
                                                    : `Check in ${loan.book.title}`
                                            }}
                                        </span>
                                    </Button>
                                    <p
                                        v-if="checkInMutation.errorFor(loan.id)"
                                        class="max-w-56 text-right text-xs text-destructive"
                                        role="alert"
                                    >
                                        {{ checkInMutation.errorFor(loan.id) }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <LibraryPagination :links="loans.links" ariaLabel="Loan pages" />
    </div>

    <Dialog v-model:open="isCheckoutDialogOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Check out a book</DialogTitle>
                <DialogDescription>
                    Select an available title and the member borrowing it.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-5" @submit.prevent="submitCheckout">
                <div class="grid gap-2">
                    <Label for="book">Book</Label>
                    <Select v-model="checkoutForm.book_id">
                        <SelectTrigger id="book" class="w-full">
                            <SelectValue
                                placeholder="Choose an available book"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="book in availableBooks"
                                :key="book.id"
                                :value="String(book.id)"
                            >
                                {{ book.title }} ({{ book.available_copies }}
                                available)
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="checkoutForm.errors.book_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="borrower">Member</Label>
                    <Select v-model="checkoutForm.borrower_id">
                        <SelectTrigger id="borrower" class="w-full">
                            <SelectValue placeholder="Choose a member" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="member in members"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.name }} - {{ member.email }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="checkoutForm.errors.borrower_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="due-at">Due date</Label>
                    <Input
                        id="due-at"
                        v-model="checkoutForm.due_at"
                        type="date"
                        :min="minDueDate"
                    />
                    <InputError :message="checkoutForm.errors.due_at" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="checkoutForm.processing">
                        Check out
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
