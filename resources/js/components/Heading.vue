<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    as?: 'h1' | 'h2';
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    as: 'h2',
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <component
            :is="as"
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </component>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
