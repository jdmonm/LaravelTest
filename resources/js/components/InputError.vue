<script setup lang="ts">
defineProps<{
    id?: string;
    message?: string;
}>();
</script>

<template>
    <div v-if="message">
        <p
            :id="id"
            class="text-sm text-red-600 dark:text-red-500"
            role="alert"
            aria-live="assertive"
        >
            {{ message }}
        </p>
    </div>
</template>
