import { ref, watch } from 'vue';
import type { FilterValues, SavedFilter, SortState } from '@/types';

/**
 * Saved filters (spec 4: "მომხმარებელს შეუძლია ფილტრების შენახვა").
 *
 * Persisted client-side (localStorage, namespaced per table + signed-in
 * user id) for now — see docs/decisions.md DEC-049: no `saved_filters`
 * table exists yet, so this is the interim, honest implementation. A module
 * that needs saved filters to sync across the user's devices should add a
 * real `SavedFilter` table (tenant + user scoped, per docs/architecture.md
 * §4) and swap this composable's storage for a server round-trip without
 * changing its public API.
 */
export function useSavedFilters(tableKey: string, userId: string) {
    const storageKey = `oda-saved-filters:${userId}:${tableKey}`;
    const savedFilters = ref<SavedFilter[]>([]);

    function load() {
        try {
            const raw = localStorage.getItem(storageKey);
            savedFilters.value = raw ? (JSON.parse(raw) as SavedFilter[]) : [];
        } catch {
            savedFilters.value = [];
        }
    }

    function persist() {
        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify(savedFilters.value),
            );
        } catch {
            // Storage full/unavailable (private browsing) — saved filters are a
            // convenience, never block the underlying filter/sort action.
        }
    }

    load();
    watch(savedFilters, persist, { deep: true });

    function save(name: string, values: FilterValues, sort: SortState) {
        savedFilters.value.push({
            id: crypto.randomUUID?.() ?? `${Date.now()}`,
            name,
            values,
            sort,
            createdAt: new Date().toISOString(),
        });
    }

    function remove(id: string) {
        savedFilters.value = savedFilters.value.filter((f) => f.id !== id);
    }

    return { savedFilters, save, remove };
}
