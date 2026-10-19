import Box from '@mui/material/Box';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TablePagination from '@mui/material/TablePagination';
import TableRow from '@mui/material/TableRow';
import TableSortLabel from '@mui/material/TableSortLabel';
import Typography from '@mui/material/Typography';
import { useEffect, type KeyboardEvent, type ReactNode } from 'react';

import { useTranslation } from '../i18n';

import { ErrorState } from './StateViews';
import { PER_PAGE_OPTIONS, type SortOrder } from './useTableState';

/**
 * The list view of this application: MUI's `Table` with `TableSortLabel` and
 * `TablePagination`, **not the `DataGrid`** (U-73).
 *
 * It renders and reports; it does not sort, filter or page anything itself.
 * Sorting and paging happen on the server (U-20), so the table is driven by
 * {@link useTableState} and the `meta` of the list response:
 *
 * ```tsx
 * const table = useTableState({ sortFields: DOMAIN_SORT, defaultSort: 'name', filters: { name: 'text' } });
 * const query = useQuery({ queryKey: ['domains', table.query], queryFn: ({ signal }) =>
 *   client.get('/domains', { query: table.query, signal }) });
 * <DataTable columns={…} rows={query.data?.items} meta={query.data?.meta} state={table} … />
 * ```
 *
 * A column is sortable only when it names a `sortField` — which is typed
 * against the same allowlist as the hook, so a column cannot offer a sort the
 * API does not have.
 *
 * Every text on and around the table is translated, MUI's built-in English
 * pagination labels included.
 *
 * A page behind the end — a bookmarked `?page=9`, or the last row of the last
 * page deleted — comes back empty with `total > 0`. That is not "nothing
 * here": the table moves to the last page instead of showing the empty text
 * (U-133).
 */

/** Read by screen readers, invisible on screen (what `@mui/utils` calls `visuallyHidden`). */
const VISUALLY_HIDDEN = {
  border: 0,
  clip: 'rect(0 0 0 0)',
  height: '1px',
  margin: '-1px',
  overflow: 'hidden',
  padding: 0,
  position: 'absolute',
  whiteSpace: 'nowrap',
  width: '1px',
} as const;

export interface DataTableColumn<Row, S extends string = string> {
  /** Stable id, used as React key. */
  readonly id: string;
  /** Column header, already translated. */
  readonly header: string;
  readonly render: (row: Row) => ReactNode;
  /** The `sort` value of the list operation; omit for a column that cannot be sorted. */
  readonly sortField?: S;
  readonly align?: 'left' | 'right' | 'center';
  /** Shrink to content, for action and status columns. */
  readonly narrow?: boolean;
}

export interface DataTablePaging {
  readonly page: number;
  readonly per_page: number;
  readonly total: number;
}

/** The part of {@link useTableState} the table drives. */
export interface DataTableState<S extends string> {
  readonly sort: S;
  readonly order: SortOrder;
  readonly setSort: (field: S) => void;
  readonly setPage: (page: number) => void;
  readonly setPerPage: (perPage: number) => void;
}

export function DataTable<Row, S extends string>({
  label,
  columns,
  rows,
  rowKey,
  meta,
  state,
  loading = false,
  error,
  onRetry,
  emptyText,
  toolbar,
  onRowClick,
}: {
  /** Accessible name of the table, already translated (usually the page title). */
  label: string;
  columns: readonly DataTableColumn<Row, S>[];
  /** `undefined` while the first page loads. */
  rows: readonly Row[] | undefined;
  rowKey: (row: Row) => string;
  /** The `meta` of the list response. Without it there is no pagination. */
  meta?: DataTablePaging | undefined;
  state?: DataTableState<S>;
  /** A request is in flight. Shown as a bar, the previous rows stay visible. */
  loading?: boolean;
  error?: unknown;
  onRetry?: () => void;
  /** Shown when the list is empty; defaults to `table.empty`. */
  emptyText?: string;
  /** Filters and actions above the table. */
  toolbar?: ReactNode;
  /** Makes rows clickable (keyboard included): usually navigation to the detail view. */
  onRowClick?: (row: Row) => void;
}): ReactNode {
  const { t } = useTranslation();

  const hasError = error !== null && error !== undefined;

  const lastPage = meta === undefined ? 1 : Math.max(1, Math.ceil(meta.total / Math.max(1, meta.per_page)));
  const behindTheEnd = meta !== undefined && rows !== undefined && rows.length === 0 && meta.total > 0 && meta.page > lastPage;
  const setPage = state?.setPage;

  useEffect(() => {
    if (behindTheEnd && !loading && setPage !== undefined) {
      setPage(lastPage);
    }
  }, [behindTheEnd, loading, lastPage, setPage]);

  return (
    <Paper variant="outlined">
      {toolbar !== undefined && <Box sx={{ p: 2, pb: 1 }}>{toolbar}</Box>}

      {hasError && (
        <Box sx={{ px: 2, pb: 1 }}>
          <ErrorState error={error} {...(onRetry === undefined ? {} : { onRetry })} />
        </Box>
      )}

      <Box sx={{ height: 4 }}>
        {loading && <LinearProgress aria-label={t('state.loading')} />}
      </Box>

      <TableContainer>
        <Table size="small" aria-label={label} aria-busy={loading}>
          <TableHead>
            <TableRow>
              {columns.map((column) => {
                const active = state !== undefined && column.sortField !== undefined && state.sort === column.sortField;

                return (
                  <TableCell
                    key={column.id}
                    align={column.align ?? 'left'}
                    sortDirection={active ? state.order : false}
                    sx={column.narrow === true ? { width: '1%', whiteSpace: 'nowrap' } : undefined}
                  >
                    {state !== undefined && column.sortField !== undefined ? (
                      <TableSortLabel
                        active={active}
                        direction={active ? state.order : 'asc'}
                        onClick={() => {
                          if (column.sortField !== undefined) {
                            state.setSort(column.sortField);
                          }
                        }}
                      >
                        {column.header}
                        {active && (
                          <Box component="span" sx={VISUALLY_HIDDEN}>
                            {state.order === 'desc' ? t('table.sort.descending') : t('table.sort.ascending')}
                          </Box>
                        )}
                      </TableSortLabel>
                    ) : (
                      column.header
                    )}
                  </TableCell>
                );
              })}
            </TableRow>
          </TableHead>
          <TableBody>
            {rows !== undefined && rows.length === 0 && !hasError && !behindTheEnd && (
              <TableRow>
                <TableCell colSpan={columns.length}>
                  <Typography color="text.secondary" sx={{ py: 2 }}>
                    {emptyText ?? t('table.empty')}
                  </Typography>
                </TableCell>
              </TableRow>
            )}
            {rows?.map((row) => (
              <TableRow
                key={rowKey(row)}
                hover={onRowClick !== undefined}
                {...(onRowClick === undefined
                  ? {}
                  : {
                      onClick: () => onRowClick(row),
                      onKeyDown: (event: KeyboardEvent) => {
                        if (event.key === 'Enter') {
                          onRowClick(row);
                        }
                      },
                      tabIndex: 0,
                      sx: { cursor: 'pointer' },
                    })}
              >
                {columns.map((column) => (
                  <TableCell key={column.id} align={column.align ?? 'left'}>
                    {column.render(row)}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>

      {meta !== undefined && state !== undefined && (
        <TablePagination
          component="div"
          count={meta.total}
          // MUI counts pages from 0, the contract from 1 (U-20).
          page={Math.max(0, Math.min(meta.page, lastPage) - 1)}
          rowsPerPage={meta.per_page}
          rowsPerPageOptions={[...PER_PAGE_OPTIONS]}
          onPageChange={(_event, page) => state.setPage(page + 1)}
          onRowsPerPageChange={(event) => state.setPerPage(Number.parseInt(event.target.value, 10))}
          labelRowsPerPage={t('table.pagination.rowsPerPage')}
          labelDisplayedRows={({ from, to, count }) => t('table.pagination.displayed', { from, to, count })}
          getItemAriaLabel={(type) => {
            switch (type) {
              case 'first':
                return t('table.pagination.first');
              case 'last':
                return t('table.pagination.last');
              case 'next':
                return t('table.pagination.next');
              default:
                return t('table.pagination.previous');
            }
          }}
        />
      )}
    </Paper>
  );
}
