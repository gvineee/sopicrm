<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/types';

const props = defineProps<{ meta: PaginationMeta }>();
const emit = defineEmits<{ 'update:page': [number] }>();

const totalPages = computed(() =>
    Math.max(1, Math.ceil(props.meta.total / props.meta.perPage)),
);

const rangeLabel = computed(() => {
    if (props.meta.total === 0) return '0 შედეგი';
    const from = (props.meta.page - 1) * props.meta.perPage + 1;
    const to = Math.min(props.meta.page * props.meta.perPage, props.meta.total);
    return `${from}–${to} / ${props.meta.total}`;
});
</script>

<template>
    <div class="flex items-center justify-between gap-3 text-sm">
        <span class="text-muted-foreground">{{ rangeLabel }}</span>
        <div class="flex items-center gap-1">
            <Button
                variant="outline"
                size="icon-sm"
                :disabled="meta.page <= 1"
                aria-label="წინა გვერდი"
                @click="emit('update:page', meta.page - 1)"
            >
                <ChevronLeft class="size-4" />
            </Button>
            <span class="text-muted-foreground min-w-16 text-center">
                {{ meta.page }} / {{ totalPages }}
            </span>
            <Button
                variant="outline"
                size="icon-sm"
                :disabled="meta.page >= totalPages"
                aria-label="შემდეგი გვერდი"
                @click="emit('update:page', meta.page + 1)"
            >
                <ChevronRight class="size-4" />
            </Button>
        </div>
    </div>
</template>
