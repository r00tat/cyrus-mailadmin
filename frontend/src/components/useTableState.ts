import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';

import type { components, operations } from '../api/schema';

/**
 * Sorting, filtering and paging of a server-side list, kept in the URL.
 *
 * **The server sorts, filters and pages** (U-20, U-73). This hook only holds
 * what to ask for and turns it into the query of the list operation:
 * `{ page, per_page, sort, order, ...filters }`. In the URL, because a list a
 * user has narrowed down should survive a reload, a shared link and the back
 * button from a detail view.
 *
 * **Sort fields come from an allowlist**, and the allowlist comes from the
 * contract: `sortFields` is typed against the `sort` enum of the operation
 * (`SortFieldOf<'listDomains'>`), so a field the API does not offer does not
 * compile. A `sort` value in the URL that is not on the list is ignored, not
 * forwarded — the server would answer `422`, and the URL is user input.
 * This is CLAUDE.md rule two seen from the client.
 *
 * Filters are declared by name: `'text'` for free text, a `RegExp` for free
 * text the contract constrains with a `pattern`, or the list of allowed values
 * for an enumeration (typed as that union). An empty filter is left out of the
 * query. A patterned filter whose value does not match stays in `filters` (the
 * field still shows what was typed) but is left out of `query` and named in
 * `invalidFilters`, so the view can say why instead of earning a `422` that a
 * reload of the URL would repeat (U-133). Changing sort, filter or page size goes back to page 1;
 * page 7 of a different question is not an answer.
 *
 * Two tables on one page pass different `prefix`es so that their parameters
 * do not collide in the URL.
 */

export type SortOrder = components['schemas']['SortOrder'];

/** The `sort` enum of a list operation, e.g. `SortFieldOf<'listDomains'>`. */
export type SortFieldOf<O extends keyof operations> = operations[O] extends {
  parameters: { query?: infer Q };
}
  ? NonNullable<Q> extends { sort?: infer S }
    ? Extract<NonNullable<S>, string>
    : never
  : never;

/** Page sizes offered. 200 is the contract's ceiling (`PerPage`, U-20). */
export const PER_PAGE_OPTIONS = [25, 50, 100, 200] as const;

/** The contract's default page size (`PerPage`). */
export const DEFAULT_PER_PAGE = 50;

export type FilterSpec = Readonly<Record<string, 'text' | RegExp | readonly string[]>>;

/**
 * The `pattern`s of the contract's dotted-name filters, for `FilterSpec`.
 * `DOTTED_PREFIX` allows a trailing dot because those filters match as a prefix
 * (`AuditAction`, `RepairOperationFilter`); `DOTTED_NAME` is the full name
 * (`RepairStepFilter`); `PLAIN_NAME` has no dots at all (`AuditObjectType`).
 */
export const FILTER_PATTERN = {
  DOTTED_PREFIX: /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\.?$/,
  DOTTED_NAME: /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/,
  PLAIN_NAME: /^[a-z][a-z0-9_]*$/,
} as const;

export type FilterValues<FS extends FilterSpec> = {
  readonly [K in keyof FS]?: FS[K] extends readonly (infer V)[] ? V : string;
};

export interface TableStateOptions<S extends string, FS extends FilterSpec> {
  /** The sort fields this view offers — a subset of the operation's `sort` enum. */
  readonly sortFields: readonly S[];
  readonly defaultSort: S;
  /** `asc` unless the contract says otherwise (the audit trail and repairs default to `desc`). */
  readonly defaultOrder?: SortOrder;
  readonly filters?: FS;
  /** For a second table on the same page. */
  readonly prefix?: string;
}

export interface TableQuery<S extends string> {
  readonly page: number;
  readonly per_page: number;
  readonly sort: S;
  readonly order: SortOrder;
}

export interface TableState<S extends string, FS extends FilterSpec> {
  readonly page: number;
  readonly perPage: number;
  readonly sort: S;
  readonly order: SortOrder;
  readonly filters: FilterValues<FS>;
  /** Patterned filters whose value does not match the contract and is therefore not sent. */
  readonly invalidFilters: ReadonlySet<keyof FS & string>;
  /** Ready to pass as `query` to the list operation. */
  readonly query: TableQuery<S> & FilterValues<FS>;
  /** Sorts by `field`; the same field again flips the direction. */
  readonly setSort: (field: S) => void;
  readonly setPage: (page: number) => void;
  readonly setPerPage: (perPage: number) => void;
  /** Sets one filter; an empty string removes it. */
  readonly setFilter: (name: keyof FS & string, value: string) => void;
}

function positiveInteger(raw: string | null): number | null {
  if (raw === null || !/^[1-9]\d{0,8}$/.test(raw)) {
    return null;
  }

  return Number.parseInt(raw, 10);
}

export function useTableState<const S extends string, const FS extends FilterSpec = Record<never, never>>(
  options: TableStateOptions<S, FS>,
): TableState<S, FS> {
  const { sortFields, defaultSort, defaultOrder = 'asc', filters: filterSpec, prefix = '' } = options;
  const [searchParams, setSearchParams] = useSearchParams();

  const name = useCallback((parameter: string) => `${prefix}${parameter}`, [prefix]);

  const state = useMemo(() => {
    const rawSort = searchParams.get(name('sort'));
    const sort = rawSort !== null && (sortFields as readonly string[]).includes(rawSort) ? (rawSort as S) : defaultSort;

    const rawOrder = searchParams.get(name('order'));
    const order: SortOrder = rawOrder === 'asc' || rawOrder === 'desc' ? rawOrder : defaultOrder;

    const page = positiveInteger(searchParams.get(name('page'))) ?? 1;

    const rawPerPage = positiveInteger(searchParams.get(name('per_page')));
    const perPage = rawPerPage !== null && (PER_PAGE_OPTIONS as readonly number[]).includes(rawPerPage)
      ? rawPerPage
      : DEFAULT_PER_PAGE;

    const filters: Record<string, string> = {};
    const sent: Record<string, string> = {};
    const invalid = new Set<keyof FS & string>();

    for (const [filter, spec] of Object.entries(filterSpec ?? {})) {
      const value = searchParams.get(name(filter));

      if (value === null || value.trim() === '') {
        continue;
      }

      // An enumeration filter only takes values the contract offers; anything
      // else in the URL is dropped rather than earning a 422.
      if (Array.isArray(spec) && !spec.includes(value)) {
        continue;
      }

      filters[filter] = value;

      if (spec instanceof RegExp && !spec.test(value)) {
        invalid.add(filter);
        continue;
      }

      sent[filter] = value;
    }

    return {
      sort,
      order,
      page,
      perPage,
      filters: filters as FilterValues<FS>,
      sent: sent as FilterValues<FS>,
      invalidFilters: invalid,
    };
  }, [searchParams, name, sortFields, defaultSort, defaultOrder, filterSpec]);

  const update = useCallback(
    (changes: Record<string, string | null>) => {
      setSearchParams(
        (current) => {
          const next = new URLSearchParams(current);

          for (const [parameter, value] of Object.entries(changes)) {
            if (value === null || value === '') {
              next.delete(name(parameter));
            } else {
              next.set(name(parameter), value);
            }
          }

          return next;
        },
        { replace: true },
      );
    },
    [setSearchParams, name],
  );

  const setSort = useCallback(
    (field: S) => {
      const order: SortOrder = field === state.sort ? (state.order === 'asc' ? 'desc' : 'asc') : 'asc';

      update({ sort: field, order, page: null });
    },
    [state.sort, state.order, update],
  );

  const setPage = useCallback(
    (page: number) => {
      update({ page: page <= 1 ? null : String(page) });
    },
    [update],
  );

  const setPerPage = useCallback(
    (perPage: number) => {
      update({ per_page: perPage === DEFAULT_PER_PAGE ? null : String(perPage), page: null });
    },
    [update],
  );

  const setFilter = useCallback(
    (filter: keyof FS & string, value: string) => {
      update({ [filter]: value.trim() === '' ? null : value, page: null });
    },
    [update],
  );

  const query = useMemo(
    () => ({
      page: state.page,
      per_page: state.perPage,
      sort: state.sort,
      order: state.order,
      ...state.sent,
    }),
    [state],
  );

  return {
    page: state.page,
    perPage: state.perPage,
    sort: state.sort,
    order: state.order,
    filters: state.filters,
    invalidFilters: state.invalidFilters,
    query,
    setSort,
    setPage,
    setPerPage,
    setFilter,
  };
}
