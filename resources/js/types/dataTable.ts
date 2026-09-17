export type SortDirection = 'asc' | 'desc';

export type SortState = {
    key: string;
    direction: SortDirection;
} | null;

export type ColumnAlign = 'start' | 'center' | 'end';

export type ColumnDef = {
    key: string;
    header: string;
    sortable?: boolean;
    align?: ColumnAlign;
    /** Tailwind width class, e.g. "w-40". Optional. */
    widthClass?: string;
    /** Hide below this breakpoint (mobile cards are preferred over cramming a wide table — spec 4/17). */
    hideBelow?: 'sm' | 'md' | 'lg';
};

export type PaginationMeta = {
    page: number;
    perPage: number;
    total: number;
};

export type FilterFieldType = 'text' | 'select' | 'date-range';

export type FilterOption = { value: string; label: string };

export type FilterFieldDef = {
    key: string;
    label: string;
    type: FilterFieldType;
    options?: FilterOption[];
    placeholder?: string;
};

export type FilterValue =
    | string
    | { from: string | null; to: string | null }
    | null;

export type FilterValues = Record<string, FilterValue>;

export type SavedFilter = {
    id: string;
    name: string;
    values: FilterValues;
    sort: SortState;
    createdAt: string;
};
