import { screen, within } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { ApiProblem } from '../api/problem';
import { renderWithRouter } from '../test/renderWithRouter';

import { DataTable, type DataTableColumn, type DataTableState } from './DataTable';
import { useTableState } from './useTableState';

interface Row {
  readonly name: string;
  readonly accounts: number;
}

const ROWS: readonly Row[] = [
  { name: 'a.test', accounts: 3 },
  { name: 'b.test', accounts: 12 },
];

const COLUMNS: readonly DataTableColumn<Row, 'name' | 'max_accounts'>[] = [
  { id: 'name', header: 'Name', render: (row) => row.name, sortField: 'name' },
  { id: 'accounts', header: 'Konten', render: (row) => row.accounts, align: 'right' },
];

function stateDouble(overrides: Partial<DataTableState<'name' | 'max_accounts'>> = {}): DataTableState<'name' | 'max_accounts'> {
  return {
    sort: 'name',
    order: 'asc',
    setSort: vi.fn(),
    setPage: vi.fn(),
    setPerPage: vi.fn(),
    ...overrides,
  };
}

describe('DataTable', () => {
  it('renders the rows under a named table', () => {
    renderWithRouter(<DataTable label="Domains" columns={COLUMNS} rows={ROWS} rowKey={(row) => row.name} />);

    const table = screen.getByRole('table', { name: 'Domains' });

    expect(within(table).getAllByRole('row')).toHaveLength(3);
    expect(within(table).getByText('b.test')).toBeInTheDocument();
  });

  it('offers sorting only on columns that name an allowed sort field', async () => {
    const state = stateDouble();
    const { user } = renderWithRouter(
      <DataTable label="Domains" columns={COLUMNS} rows={ROWS} rowKey={(row) => row.name} state={state} />,
    );

    // The active column says how it is sorted, for a screen reader too.
    const sortButton = screen.getByRole('button', { name: /Name/ });

    expect(sortButton).toHaveTextContent('aufsteigend sortiert');
    expect(screen.getByRole('columnheader', { name: /Name/ })).toHaveAttribute('aria-sort', 'ascending');
    expect(within(screen.getByRole('columnheader', { name: 'Konten' })).queryByRole('button')).toBeNull();

    await user.click(sortButton);

    expect(state.setSort).toHaveBeenCalledWith('name');
  });

  it('pages with German labels and reports 1-based pages', async () => {
    const state = stateDouble();
    const { user } = renderWithRouter(
      <DataTable
        label="Domains"
        columns={COLUMNS}
        rows={ROWS}
        rowKey={(row) => row.name}
        state={state}
        meta={{ page: 1, per_page: 25, total: 60 }}
      />,
    );

    expect(screen.getByText('1–25 von 60')).toBeInTheDocument();
    expect(screen.getByText('Einträge je Seite')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Nächste Seite' }));

    expect(state.setPage).toHaveBeenCalledWith(2);
  });

  it('says so when there is nothing, and lets the view say it better', () => {
    const { rerender } = renderWithRouter(
      <DataTable label="Domains" columns={COLUMNS} rows={[]} rowKey={(row) => row.name} />,
    );

    expect(screen.getByText('Keine Einträge.')).toBeInTheDocument();

    rerender(<DataTable label="Domains" columns={COLUMNS} rows={[]} rowKey={(row) => row.name} emptyText="Noch keine Domain." />);

    expect(screen.getByText('Noch keine Domain.')).toBeInTheDocument();
  });

  it('goes to the last page when the page asked for lies behind the end', () => {
    const state = stateDouble();

    renderWithRouter(
      <DataTable
        label="Domains"
        columns={COLUMNS}
        rows={[]}
        rowKey={(row) => row.name}
        state={state}
        meta={{ page: 9, per_page: 25, total: 60 }}
      />,
    );

    expect(state.setPage).toHaveBeenCalledWith(3);
    expect(screen.queryByText('Keine Einträge.')).toBeNull();
  });

  it('stays on a page beyond the end while the answer is still loading', () => {
    const state = stateDouble();

    renderWithRouter(
      <DataTable
        label="Domains"
        columns={COLUMNS}
        rows={[]}
        rowKey={(row) => row.name}
        state={state}
        loading
        meta={{ page: 9, per_page: 25, total: 60 }}
      />,
    );

    expect(state.setPage).not.toHaveBeenCalled();
  });

  it('shows a failure through the catalogue, with request id and retry', async () => {
    const onRetry = vi.fn();
    const error = new ApiProblem({ status: 503, code: 'request.internal_error', requestId: 'req-42', errors: {} });
    const { user } = renderWithRouter(
      <DataTable label="Domains" columns={COLUMNS} rows={undefined} rowKey={(row) => row.name} error={error} onRetry={onRetry} />,
    );

    const alert = screen.getByRole('alert');

    expect(alert).toHaveTextContent('Vorgangsnummer: req-42');
    expect(alert).not.toHaveTextContent('request.internal_error');

    await user.click(within(alert).getByRole('button', { name: 'Erneut versuchen' }));

    expect(onRetry).toHaveBeenCalledOnce();
  });

  it('marks itself busy while loading, keeping the rows it has', () => {
    renderWithRouter(<DataTable label="Domains" columns={COLUMNS} rows={ROWS} rowKey={(row) => row.name} loading />);

    expect(screen.getByRole('table', { name: 'Domains' })).toHaveAttribute('aria-busy', 'true');
    expect(screen.getByRole('progressbar', { name: 'Wird geladen …' })).toBeInTheDocument();
    expect(screen.getByText('a.test')).toBeInTheDocument();
  });

  it('opens a row by click and by Enter', async () => {
    const onRowClick = vi.fn();
    const { user } = renderWithRouter(
      <DataTable label="Domains" columns={COLUMNS} rows={ROWS} rowKey={(row) => row.name} onRowClick={onRowClick} />,
    );

    await user.click(screen.getByText('a.test'));
    expect(onRowClick).toHaveBeenLastCalledWith(ROWS[0]);

    screen.getByText('b.test').closest('tr')?.focus();
    await user.keyboard('{Enter}');
    expect(onRowClick).toHaveBeenLastCalledWith(ROWS[1]);
  });

  it('drives the URL through useTableState end to end', async () => {
    function Wired(): ReactNode {
      const table = useTableState({ sortFields: ['name', 'max_accounts'] as const, defaultSort: 'name' });
      const [rows] = useState(ROWS);

      return (
        <DataTable
          label="Domains"
          columns={COLUMNS}
          rows={rows}
          rowKey={(row) => row.name}
          state={table}
          meta={{ page: table.page, per_page: table.perPage, total: 120 }}
        />
      );
    }

    const { user, router } = renderWithRouter(<Wired />);

    await user.click(screen.getByRole('button', { name: /Name/ }));
    expect(router.state.location.search).toBe('?sort=name&order=desc');

    await user.click(screen.getByRole('button', { name: 'Nächste Seite' }));
    expect(router.state.location.search).toBe('?sort=name&order=desc&page=2');
    expect(screen.getByText('51–100 von 120')).toBeInTheDocument();
  });
});
