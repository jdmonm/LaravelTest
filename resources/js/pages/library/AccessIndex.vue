<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { KeyRound, Search, ShieldCheck } from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

type Member = {
    id: number;
    name: string;
    email: string;
    roles: string[];
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedUsers = {
    data: Member[];
    links: PaginationLink[];
};

const props = defineProps<{
    users: PaginatedUsers;
    filters: {
        search: string;
    };
    roles: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Access',
                href: '/access',
            },
        ],
    },
});

const page = usePage();
const search = ref(props.filters.search);
const roleMutation = usePendingMutation();

function submitSearch(): void {
    router.get(
        '/access',
        { search: search.value || undefined },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function updateRole(member: Member, role: AcceptableValue): void {
    if (role === null || String(role) === member.roles[0]) {
        return;
    }

    if (!roleMutation.start(member.id)) {
        return;
    }

    router.patch(
        `/access/${member.id}/role`,
        { role: String(role) },
        {
            preserveScroll: true,
            onError: (errors) => roleMutation.setError(member.id, errors),
            onFinish: () => roleMutation.finish(member.id),
        },
    );
}

function roleLabel(role: string): string {
    return role.charAt(0).toUpperCase() + role.slice(1);
}

function paginationLabel(label: string): string {
    return label
        .replace('&laquo; Previous', 'Previous')
        .replace('Next &raquo;', 'Next')
        .replace(/&laquo;|&raquo;/g, '');
}
</script>

<template>
    <Head title="Access" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                title="Access control"
                description="Assign the library role that determines each member's capabilities."
            />
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <ShieldCheck class="size-4" />
                Roles update immediately
            </div>
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
                    placeholder="Search name or email"
                    aria-label="Search members"
                />
            </div>
            <Button type="submit" variant="outline">Search</Button>
        </form>

        <div class="overflow-hidden rounded-lg border">
            <div
                v-if="users.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center gap-3 px-6 text-center"
            >
                <KeyRound class="size-8 text-muted-foreground" />
                <div>
                    <p class="font-medium">No members found</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Adjust the search to find a library member.
                    </p>
                </div>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-170 text-left text-sm">
                    <thead
                        class="border-b bg-muted/40 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Member</th>
                            <th class="px-4 py-3 font-medium">
                                Current access
                            </th>
                            <th class="w-56 px-4 py-3 font-medium">
                                Library role
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="member in users.data"
                            :key="member.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-4">
                                <p class="font-medium">{{ member.name }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ member.email }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-1.5">
                                    <Badge
                                        v-for="role in member.roles"
                                        :key="role"
                                        variant="secondary"
                                    >
                                        {{ roleLabel(role) }}
                                    </Badge>
                                    <span
                                        v-if="member.roles.length === 0"
                                        class="text-sm text-muted-foreground"
                                    >
                                        No role assigned
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="grid gap-2">
                                    <div class="flex items-center gap-2">
                                        <Select
                                            :model-value="member.roles[0] ?? ''"
                                            :disabled="
                                                member.id ===
                                                    page.props.auth.user.id ||
                                                roleMutation.isPending(
                                                    member.id,
                                                )
                                            "
                                            @update:model-value="
                                                (role) =>
                                                    updateRole(member, role)
                                            "
                                        >
                                            <SelectTrigger class="w-full">
                                                <SelectValue
                                                    placeholder="Choose a role"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="role in roles"
                                                    :key="role"
                                                    :value="role"
                                                >
                                                    {{ roleLabel(role) }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <Spinner
                                            v-if="
                                                roleMutation.isPending(
                                                    member.id,
                                                )
                                            "
                                            class="shrink-0"
                                        />
                                    </div>
                                    <p
                                        v-if="roleMutation.errorFor(member.id)"
                                        class="text-xs text-destructive"
                                        role="alert"
                                    >
                                        {{ roleMutation.errorFor(member.id) }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav
            v-if="users.links.length > 3"
            class="flex flex-wrap justify-center gap-1"
            aria-label="Member pages"
        >
            <template v-for="link in users.links" :key="link.label">
                <Button v-if="!link.url" variant="ghost" size="sm" disabled>
                    {{ paginationLabel(link.label) }}
                </Button>
                <Button
                    v-else
                    as-child
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                >
                    <Link :href="link.url" preserve-scroll>
                        {{ paginationLabel(link.label) }}
                    </Link>
                </Button>
            </template>
        </nav>
    </div>
</template>
