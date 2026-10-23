import AddIcon from '@mui/icons-material/Add';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { useSession } from '../../auth/SessionContext';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { FilterField } from '../../components/FilterField';
import { PageHeader } from '../../components/PageHeader';
import { useTableState, type SortFieldOf } from '../../components/useTableState';
import { useTranslation } from '../../i18n';

import { listPath, type MailingList } from './listShared';
import { MailingListCreateDialog } from './MailingListCreateDialog';

/**
 * A5 — the `lists` table, made visible. Rows written before mailadmin may
 * hold values it would not accept; they are listed and flagged, never hidden
 * (U-22).
 *
 * Open to every session with `list.read`: a domain master sees the lists
 * whose address is in their domains, the server scopes the rows.
 */

const SORT = ['name', 'tag', 'address'] as const satisfies readonly SortFieldOf<'listMailingLists'>[];

const FILTERS = { name: 'text', address: 'text', member: 'text', tag: 'text' } as const;

export function MailingListListPage(): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const navigate = useNavigate();
  const { can } = useSession();
  const [creating, setCreating] = useState(false);

  const table = useTableState({ sortFields: SORT, defaultSort: 'name', filters: FILTERS });
  const lists = useQuery({
    queryKey: ['lists', 'list', table.query],
    queryFn: ({ signal }) => client.get('/lists', { query: table.query, signal }),
    placeholderData: keepPreviousData,
  });

  const columns: readonly DataTableColumn<MailingList, (typeof SORT)[number]>[] = [
    { id: 'name', header: t('list.field.name'), sortField: 'name', render: (row) => row.name },
    {
      id: 'address',
      header: t('list.field.address'),
      sortField: 'address',
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center">
          <span>{row.address}</span>
          {!row.address_usable && (
            <Chip size="small" color="warning" variant="outlined" label={t('list.list.addressUnusable')} />
          )}
        </Stack>
      ),
    },
    { id: 'tag', header: t('list.field.tag'), sortField: 'tag', render: (row) => row.tag },
    {
      id: 'members',
      header: t('list.field.members'),
      align: 'right',
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center" justifyContent="flex-end">
          <span>{t('list.list.memberCount', { count: row.members.length })}</span>
          {row.unusable_members.length > 0 && (
            <Chip
              size="small"
              color="warning"
              variant="outlined"
              label={t('list.list.unusableCount', { count: row.unusable_members.length })}
            />
          )}
        </Stack>
      ),
    },
  ];

  const filtered = Object.values(table.filters).some((value) => value !== undefined);

  return (
    <>
      <PageHeader
        title={t('list.list.title')}
        actions={
          can('list.write') ? (
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreating(true)}>
              {t('list.list.action.create')}
            </Button>
          ) : undefined
        }
      />
      <DataTable
        label={t('list.list.title')}
        columns={columns}
        rows={lists.data?.items}
        rowKey={(row) => row.address}
        meta={lists.data?.meta}
        state={table}
        loading={lists.isFetching}
        error={lists.error}
        onRetry={() => void lists.refetch()}
        emptyText={filtered ? t('list.list.emptyFiltered') : t('list.list.empty')}
        toolbar={
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <FilterField
              label={t('list.list.filter.name')}
              value={table.filters.name}
              onChange={(value) => table.setFilter('name', value)}
            />
            <FilterField
              label={t('list.list.filter.address')}
              value={table.filters.address}
              onChange={(value) => table.setFilter('address', value)}
            />
            <FilterField
              label={t('list.list.filter.member')}
              value={table.filters.member}
              onChange={(value) => table.setFilter('member', value)}
            />
            <FilterField
              label={t('list.list.filter.tag')}
              value={table.filters.tag}
              onChange={(value) => table.setFilter('tag', value)}
            />
          </Stack>
        }
        onRowClick={(row) => void navigate(listPath(row.address))}
      />
      <MailingListCreateDialog open={creating} onClose={() => setCreating(false)} />
    </>
  );
}
