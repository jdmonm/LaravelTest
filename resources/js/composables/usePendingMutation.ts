import { ref } from 'vue';

type MutationErrors = Record<string, string | undefined>;

export function usePendingMutation() {
    const pendingIds = ref<number[]>([]);
    const errors = ref<Record<number, string>>({});

    function isPending(id: number): boolean {
        return pendingIds.value.includes(id);
    }

    function errorFor(id: number): string | undefined {
        return errors.value[id];
    }

    function start(id: number): boolean {
        if (isPending(id)) {
            return false;
        }

        pendingIds.value = [...pendingIds.value, id];
        clearError(id);

        return true;
    }

    function setError(id: number, responseErrors: MutationErrors): void {
        errors.value = {
            ...errors.value,
            [id]:
                Object.values(responseErrors).find(Boolean) ??
                'Unable to complete this action.',
        };
    }

    function finish(id: number): void {
        pendingIds.value = pendingIds.value.filter(
            (pendingId) => pendingId !== id,
        );
    }

    function clearError(id: number): void {
        const nextErrors = { ...errors.value };
        delete nextErrors[id];
        errors.value = nextErrors;
    }

    return {
        clearError,
        errorFor,
        finish,
        isPending,
        setError,
        start,
    };
}
