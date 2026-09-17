import { router } from '@inertiajs/vue3';
import type { FilterValues, SortState } from '@/types';

/**
 * The one place a page turns page/sort/filter UI state into the server
 * round-trip (spec 4: "ძიება, სორტირება და ფილტრები სერვერულია;
 * გამოიყენე pagination"). Every DataTable-backed screen should call
 * `pushTableState` instead of hand-rolling its own `router.get` so query
 * params stay consistently named (`page`, `sort`, `direction`, plus one
 * param per filter key) across modules.
 *
 * This intentionally does not fetch/own the resulting rows — the
 * controller's Inertia response updates the page's props as normal;
 * `preserveState`/`preserveScroll` keep the rest of the page (open drawers,
 * scroll position) stable across the request.
 */
export function pushTableState(
    baseUrl: string,
    state: {
        page: number;
        perPage: number;
        sort: SortState;
        filters: FilterValues;
    },
) {
    const params: Record<string, string> = {
        page: String(state.page),
        per_page: String(state.perPage),
    };

    if (state.sort) {
        params.sort = state.sort.key;
        params.direction = state.sort.direction;
    }

    for (const [key, value] of Object.entries(state.filters)) {
        if (value === null || value === '') continue;

        if (typeof value === 'object') {
            if (value.from) params[`${key}_from`] = value.from;
            if (value.to) params[`${key}_to`] = value.to;
        } else {
            params[key] = value;
        }
    }

    router.get(baseUrl, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
