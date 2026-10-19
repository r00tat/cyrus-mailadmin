import { act, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it } from 'vitest';

import { createMemoryTokenStore } from '../auth/tokenStore';
import { ApiClient } from '../api/client';
import { createFetchDouble, jsonResponse } from '../test/fetchDouble';
import { renderWithRouter } from '../test/renderWithRouter';

import { useTableState, type SortFieldOf, type TableState } from './useTableState';

/**
 * The URL-backed table state: what it reads, what it refuses to read, and what
 * it hands the API client.
 */

// The allowlist, typed against the contract: a field the API does not offer
// would not compile here.
const DOMAIN_SORT = ['name', 'max_accounts', 'domain_quota'] as const satisfies readonly SortFieldOf<'listDomains'>[];
const ADDRESS_TYPES = ['alias', 'forward', 'catchall'] as const;

type DomainTable = TableState<(typeof DOMAIN_SORT)[number], { name: 'text'; type: typeof ADDRESS_TYPES }>;

let latest: DomainTable | null = null;

function Harness(): ReactNode {
  const table = useTableState({
    sortFields: DOMAIN_SORT,
    defaultSort: 'name',
    filters: { name: 'text', type: ADDRESS_TYPES },
  });

  latest = table;

  return <output>{JSON.stringify(table.query)}</output>;
}

function current(): DomainTable {
  if (latest === null) {
    throw new Error('Harness has not rendered.');
  }

  return latest;
}

describe('useTableState', () => {
  it('starts from the defaults: page 1, 50 rows, the default sort ascending, no filters', () => {
    renderWithRouter(<Harness />);

    expect(current().query).toEqual({ page: 1, per_page: 50, sort: 'name', order: 'asc' });
  });

  it('reads sort, order, page, page size and filters from the URL', () => {
    renderWithRouter(<Harness />, {
      initialEntry: '/?sort=max_accounts&order=desc&page=3&per_page=100&name=exa&type=forward',
    });

    expect(current().query).toEqual({
      page: 3,
      per_page: 100,
      sort: 'max_accounts',
      order: 'desc',
      name: 'exa',
      type: 'forward',
    });
  });

  it('ignores a sort field that is not on the allowlist instead of forwarding it (CLAUDE.md rule 2)', () => {
    renderWithRouter(<Harness />, { initialEntry: '/?sort=password%3B%20DROP%20TABLE&order=sideways' });

    expect(current().sort).toBe('name');
    expect(current().order).toBe('asc');
  });

  it('ignores an allowed-looking sort the view did not offer, a bad page and an odd page size', () => {
    // `default_account_quota` is in the contract's enum, but this view does not offer it.
    renderWithRouter(<Harness />, { initialEntry: '/?sort=default_account_quota&page=0&per_page=7' });

    expect(current().query).toEqual({ page: 1, per_page: 50, sort: 'name', order: 'asc' });
  });

  it('drops an enumeration filter value the contract does not offer', () => {
    renderWithRouter(<Harness />, { initialEntry: '/?type=mailbox&name=%20%20' });

    expect(current().filters).toEqual({});
  });

  it('flips the direction on the same field, starts ascending on a new one, and goes back to page 1', () => {
    const { router } = renderWithRouter(<Harness />, { initialEntry: '/?page=4' });

    act(() => current().setSort('name'));
    expect(current().query).toMatchObject({ sort: 'name', order: 'desc', page: 1 });

    act(() => current().setSort('domain_quota'));
    expect(current().query).toMatchObject({ sort: 'domain_quota', order: 'asc', page: 1 });
    expect(router.state.location.search).toBe('?sort=domain_quota&order=asc');
  });

  it('sets and clears a filter, back to page 1, and keeps defaults out of the URL', () => {
    const { router } = renderWithRouter(<Harness />, { initialEntry: '/?page=2' });

    act(() => current().setFilter('name', 'example'));
    expect(current().query).toEqual({ page: 1, per_page: 50, sort: 'name', order: 'asc', name: 'example' });
    expect(router.state.location.search).toBe('?name=example');

    act(() => current().setFilter('name', ''));
    expect(router.state.location.search).toBe('');
  });

  it('pages and changes the page size', () => {
    const { router } = renderWithRouter(<Harness />);

    act(() => current().setPage(2));
    expect(router.state.location.search).toBe('?page=2');

    act(() => current().setPerPage(200));
    expect(current().query).toMatchObject({ page: 1, per_page: 200 });

    act(() => current().setPerPage(50));
    expect(router.state.location.search).toBe('');
  });

  it('keeps two tables apart with a prefix', () => {
    function Two(): ReactNode {
      const first = useTableState({ sortFields: DOMAIN_SORT, defaultSort: 'name' });
      const second = useTableState({ sortFields: DOMAIN_SORT, defaultSort: 'name', prefix: 'b_' });

      return (
        <>
          <output data-testid="first">{first.sort}</output>
          <output data-testid="second">{second.sort}</output>
        </>
      );
    }

    renderWithRouter(<Two />, { initialEntry: '/?b_sort=domain_quota' });

    expect(screen.getByTestId('first')).toHaveTextContent('name');
    expect(screen.getByTestId('second')).toHaveTextContent('domain_quota');
  });

  it('produces a query the typed client accepts as is and sends as the contract expects', async () => {
    const tokenStore = createMemoryTokenStore();
    const double = createFetchDouble(() => jsonResponse(200, { items: [], meta: { page: 1, per_page: 50, total: 0 } }));
    const client = new ApiClient({ baseUrl: '/api/v1', tokenStore, fetch: double.fetch });
    let sent = false;

    // Exactly the `listDomains` filters: the query type-checks against the operation.
    function DomainList(): ReactNode {
      const table = useTableState({ sortFields: DOMAIN_SORT, defaultSort: 'name', filters: { name: 'text' } });

      if (!sent) {
        sent = true;
        void client.get('/domains', { query: table.query });
      }

      return null;
    }

    renderWithRouter(<DomainList />, { initialEntry: '/?sort=domain_quota&order=desc&name=ex' });

    await waitFor(() => expect(double.calls).toHaveLength(1));
    expect(double.calls[0]?.url).toBe('/api/v1/domains?page=1&per_page=50&sort=domain_quota&order=desc&name=ex');
  });
});
