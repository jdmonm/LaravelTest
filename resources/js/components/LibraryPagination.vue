<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

defineProps<{
    links: PaginationLink[];
    ariaLabel: string;
}>();

function paginationLabel(label: string): string {
    return label
        .replace('&laquo; Previous', 'Previous')
        .replace('Next &raquo;', 'Next')
        .replace(/&laquo;|&raquo;/g, '');
}
</script>

<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-wrap justify-center gap-1"
        :aria-label="ariaLabel"
    >
        <template v-for="link in links" :key="link.label">
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
</template>
