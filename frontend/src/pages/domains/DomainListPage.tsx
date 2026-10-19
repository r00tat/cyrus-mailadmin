import AddIcon from '@mui/icons-material/Add';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import { useSession } from '../../auth/SessionContext';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { FilterField } from '../../components/FilterField';
import { PageHeader } from '../../components/PageHeader';
import { useTableState, type SortFieldOf } from '../../components/useTableState';
import { useFormatters, useTranslation } from '../../i18n';

import { EFFECTIVE_SCHEME_KEYS, domainPath } from './domainForm';

/**
 * A1 — the domains the actor may see. A domain master gets the same list,
 * narrowed by the server to their own domains (U-45), and no create button.
 */

type DomainListItem = components['schemas']['DomainListItem'];

const SORT = ['name', 'max_accounts', 'default_account_quota', 'domain_quota'] as const satisfies readonly SortFieldOf<'listDomains'>[];

const FILTERS = { name: 'text' } as const;

export function DomainListPage(): ReactNode {
  const { t } = useTranslation();
  const { number, kibibytes } = useFormatters();
  const client = useApiClient();
  const navigate = useNavigate();
  const { can } = useSession();

  const table = useTableState({ sortFields: SORT, defaultSort: 'name', filters: FILTERS });
  const domains = useQuery({
    queryKey: ['domains', 'list', table.query],
    queryFn: ({ signal }) => client.get('/domains', { query: table.query, signal }),
    placeholderData: keepPreviousData,
  });

  const quota = (value: number | null): string => (value === null ? t('domain.value.unlimited') : kibibytes(value));

  const columns: readonly DataTableColumn<DomainListItem, (typeof SORT)[number]>[] = [
    {
      id: 'name',
      header: t('domain.field.name'),
      sortField: 'name',
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center">
          <span>{row.name}</span>
          {!row.conventional_name && (
            <Chip size="small" color="warning" variant="outlined" label={t('domain.list.unconventionalName')} />
          )}
        </Stack>
      ),
    },
    {
      id: 'accounts',
      header: t('domain.field.accounts'),
      align: 'right',
      render: (row) =>
        row.max_accounts === null
          ? number(row.account_count)
          : t('domain.value.accountsOfLimit', { count: row.account_count, limit: row.max_accounts }),
    },
    {
      id: 'max_accounts',
      header: t('domain.field.maxAccounts'),
      sortField: 'max_accounts',
      align: 'right',
      render: (row) => (row.max_accounts === null ? t('domain.value.unlimited') : number(row.max_accounts)),
    },
    {
      id: 'default_quota',
      header: t('domain.field.defaultQuota'),
      sortField: 'default_account_quota',
      align: 'right',
      render: (row) => quota(row.default_account_quota_kib),
    },
    {
      id: 'domain_quota',
      header: t('domain.field.domainQuota'),
      sortField: 'domain_quota',
      align: 'right',
      render: (row) => quota(row.domain_quota_kib),
    },
    {
      id: 'scheme',
      header: t('domain.field.namingScheme'),
      render: (row) =>
        row.naming_scheme === 'inherited'
          ? t('domain.value.schemeInherited', { scheme: t(EFFECTIVE_SCHEME_KEYS[row.effective_naming_scheme]) })
          : t(EFFECTIVE_SCHEME_KEYS[row.effective_naming_scheme]),
    },
  ];

  return (
    <>
      <PageHeader
        title={t('domain.list.title')}
        actions={
          can('domain.write') ? (
            <Button component={RouterLink} to="/domains/new" variant="contained" startIcon={<AddIcon />}>
              {t('domain.list.action.create')}
            </Button>
          ) : undefined
        }
      />
      <DataTable
        label={t('domain.list.title')}
        columns={columns}
        rows={domains.data?.items}
        rowKey={(row) => row.name}
        meta={domains.data?.meta}
        state={table}
        loading={domains.isFetching}
        error={domains.error}
        onRetry={() => void domains.refetch()}
        emptyText={table.filters.name === undefined ? t('domain.list.empty') : t('domain.list.emptyFiltered')}
        toolbar={
          <FilterField
            label={t('domain.list.filter.name')}
            value={table.filters.name}
            onChange={(value) => table.setFilter('name', value)}
          />
        }
        onRowClick={(row) => void navigate(domainPath(row.name))}
      />
    </>
  );
}
