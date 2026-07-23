<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { BookOpen, Pencil, Plus, Search, Trash2 } from '@lucide/vue';
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
import { dashboard } from '@/routes';
import {
    destroy as destroyBook,
    index as booksIndex,
    store as storeBook,
    update as updateBook,
} from '@/routes/books';

type Book = {
    id: number;
    title: string;
    author: string;
    isbn: string | null;
    description: string | null;
    published_year: number | null;
    total_copies: number;
    available_copies: number;
};

type PaginatedBooks = {
    data: Book[];
    links: PaginationLink[];
    total: number;
};

const props = defineProps<{
    books: PaginatedBooks;
    filters: {
        search: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Catalogue',
                href: booksIndex(),
            },
        ],
    },
});

const page = usePage();
const search = ref(props.filters.search);
const isDialogOpen = ref(false);
const editingBook = ref<Book | null>(null);
const canCreateBooks = computed(() =>
    page.props.auth.permissions.includes('books.create'),
);
const canUpdateBooks = computed(() =>
    page.props.auth.permissions.includes('books.update'),
);
const canDeleteBooks = computed(() =>
    page.props.auth.permissions.includes('books.delete'),
);

const form = useForm({
    title: '',
    author: '',
    isbn: '',
    description: '',
    published_year: '',
    total_copies: 1,
});

function submitSearch(): void {
    router.get(
        booksIndex.url(),
        { search: search.value || undefined },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function openCreateDialog(): void {
    editingBook.value = null;
    form.reset();
    form.clearErrors();
    form.total_copies = 1;
    isDialogOpen.value = true;
}

function openEditDialog(book: Book): void {
    editingBook.value = book;
    form.clearErrors();
    form.title = book.title;
    form.author = book.author;
    form.isbn = book.isbn ?? '';
    form.description = book.description ?? '';
    form.published_year = book.published_year?.toString() ?? '';
    form.total_copies = book.total_copies;
    isDialogOpen.value = true;
}

function submitBook(): void {
    if (editingBook.value) {
        form.patch(updateBook.url(editingBook.value), {
            preserveScroll: true,
            onSuccess: () => {
                isDialogOpen.value = false;
            },
        });

        return;
    }

    form.post(storeBook.url(), {
        preserveScroll: true,
        onSuccess: () => {
            isDialogOpen.value = false;
        },
    });
}

function deleteBook(book: Book): void {
    if (!window.confirm(`Delete “${book.title}” from the catalogue?`)) {
        return;
    }

    router.delete(destroyBook.url(book), { preserveScroll: true });
}

function inventoryVariant(book: Book): 'default' | 'secondary' | 'destructive' {
    if (book.available_copies === 0) {
        return 'destructive';
    }

    if (book.available_copies < book.total_copies) {
        return 'secondary';
    }

    return 'default';
}
</script>

<template>
    <Head title="Catalogue" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                as="h1"
                title="Catalogue"
                description="Search the library collection and manage inventory."
            />
            <Button v-if="canCreateBooks" @click="openCreateDialog">
                <Plus />
                Add book
            </Button>
        </div>

        <form
            class="flex w-full max-w-xl items-center gap-2"
            @submit.prevent="submitSearch"
        >
            <div class="relative min-w-0 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="pl-9"
                    placeholder="Search title, author, or ISBN"
                    aria-label="Search catalogue"
                />
            </div>
            <Button type="submit" variant="outline">Search</Button>
        </form>

        <div class="overflow-hidden rounded-lg border">
            <div
                v-if="books.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center gap-3 px-6 text-center"
            >
                <BookOpen class="size-8 text-muted-foreground" />
                <div>
                    <p class="font-medium">No books found</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Try a different search or add the first title to the
                        collection.
                    </p>
                </div>
                <Button
                    v-if="canCreateBooks"
                    variant="outline"
                    @click="openCreateDialog"
                >
                    <Plus />
                    Add book
                </Button>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-180 text-left text-sm">
                    <thead
                        class="border-b bg-muted/40 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Title</th>
                            <th class="px-4 py-3 font-medium">Author</th>
                            <th class="px-4 py-3 font-medium">Publication</th>
                            <th class="px-4 py-3 font-medium">Availability</th>
                            <th
                                v-if="canUpdateBooks || canDeleteBooks"
                                class="w-24 px-4 py-3 text-right font-medium"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="book in books.data"
                            :key="book.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-4 align-top">
                                <p class="font-medium">{{ book.title }}</p>
                                <p
                                    v-if="book.isbn"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    ISBN {{ book.isbn }}
                                </p>
                            </td>
                            <td
                                class="px-4 py-4 align-top text-muted-foreground"
                            >
                                {{ book.author }}
                            </td>
                            <td
                                class="px-4 py-4 align-top text-muted-foreground"
                            >
                                {{ book.published_year ?? 'Unknown' }}
                            </td>
                            <td class="px-4 py-4 align-top">
                                <Badge :variant="inventoryVariant(book)">
                                    {{ book.available_copies }} of
                                    {{ book.total_copies }} available
                                </Badge>
                            </td>
                            <td
                                v-if="canUpdateBooks || canDeleteBooks"
                                class="px-4 py-4 align-top"
                            >
                                <div class="flex justify-end gap-1">
                                    <Button
                                        v-if="canUpdateBooks"
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        :title="`Edit ${book.title}`"
                                        @click="openEditDialog(book)"
                                    >
                                        <Pencil />
                                        <span class="sr-only"
                                            >Edit {{ book.title }}</span
                                        >
                                    </Button>
                                    <Button
                                        v-if="canDeleteBooks"
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        :title="`Delete ${book.title}`"
                                        @click="deleteBook(book)"
                                    >
                                        <Trash2 class="text-destructive" />
                                        <span class="sr-only"
                                            >Delete {{ book.title }}</span
                                        >
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <LibraryPagination :links="books.links" ariaLabel="Catalogue pages" />
    </div>

    <Dialog v-model:open="isDialogOpen">
        <DialogContent
            class="max-h-[calc(100vh-2rem)] overflow-y-auto sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle>{{
                    editingBook ? 'Edit book' : 'Add book'
                }}</DialogTitle>
                <DialogDescription>
                    Keep catalogue details and copy counts accurate for
                    circulation.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-5" @submit.prevent="submitBook">
                <div class="grid gap-2">
                    <Label for="title">Title</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="author">Author</Label>
                        <Input
                            id="author"
                            v-model="form.author"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.author" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="isbn">ISBN</Label>
                        <Input
                            id="isbn"
                            v-model="form.isbn"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.isbn" />
                    </div>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="published-year">Published year</Label>
                        <Input
                            id="published-year"
                            v-model="form.published_year"
                            type="number"
                            min="1000"
                            :max="new Date().getFullYear()"
                        />
                        <InputError :message="form.errors.published_year" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="total-copies">Total copies</Label>
                        <Input
                            id="total-copies"
                            v-model.number="form.total_copies"
                            type="number"
                            min="1"
                            required
                        />
                        <InputError :message="form.errors.total_copies" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="description">Description</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="4"
                        class="min-h-24 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        {{ editingBook ? 'Save changes' : 'Add book' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
