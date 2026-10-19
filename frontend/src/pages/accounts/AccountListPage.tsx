import AddIcon from '@mui/icons-material/Add';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { useSession } from '../../auth/SessionContext';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { FilterField } from '../../components/FilterField';
import { PageHeader } from '../../components/PageHeader';
import { useTableState, type SortFieldOf } from '../../components/useTableState';
import { useTranslation } from '../../i18n';

import { DomainPicker } from './DomainPicker';
import {
  NAMING_SCHEME_KEYS,
  PASSWORD_FORMATS,
  PASSWORD_FORMAT_KEYS,
  accountPath,
  type Account,
} from './labels';
import { useDomainParam } from './useDomainParam';

/**
 * A2 — the accounts of one domain.
 *
 * The list comes from the database alone; quota and usage are **not** here
 * (they cost an IMAP round trip per row, architecture 7.2) but on the detail
 * page. Every row shows the format of its stored password hash, and the list
 * filters by it (E-13) — this is where an operator sees how many accounts
 * still carry a legacy format.
 *
 * A row whose stored user name contradicts its domain is shown, marked, and
 * not hidden (U-22).
 */

const SORT = ['name', 'password_format'] as const satisfies readonly SortFieldOf<'listAccounts'>[];
const FILTERS = { name: 'text', password_format: PASSWORD_FORMATS } as const;

export function AccountListPage(): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const { can } = useSession();
  const navigate = useNavigate();
  const [domain, setDomain] = useDomainParam();

  const table = useTableState({ sortFields: SORT, defaultSort: 'name', filters: FILTERS });

  const accounts = useQuery({
    queryKey: ['accounts', 'list', domain, table.query],
    queryFn: ({ signal }) =>
      client.get('/domains/{domain}/accounts', {
        pathParams: { domain: domain ?? '' },
        query: table.query,
        signal,
      }),
    enabled: domain !== undefined,
    placeholderData: keepPreviousData,
  });

  const columns: DataTableColumn<Account, (typeof SORT)[number]>[] = [
    {
      id: 'address',
      header: t('account.list.column.address'),
      sortField: 'name',
      render: (account) => (
        <Stack direction="row" spacing={1} alignItems="center">
          <span>{account.address ?? account.id}</span>
          {!account.resolvable && (
            <Chip size="small" color="warning" label={t('account.list.unresolvable')} />
          )}
        </Stack>
      ),
    },
    {
      id: 'username',
      header: t('account.list.column.username'),
      render: (account) => <Box component="span" sx={{ fontFamily: 'monospace' }}>{account.id}</Box>,
    },
    {
      id: 'scheme',
      header: t('account.list.column.namingScheme'),
      render: (account) => t(NAMING_SCHEME_KEYS[account.naming_scheme]),
    },
    {
      id: 'passwordFormat',
      header: t('account.list.column.passwordFormat'),
      sortField: 'password_format',
      render: (account) => (
        <Chip
          size="small"
          variant={account.password_format_current ? 'filled' : 'outlined'}
          color={account.password_format_weak ? 'warning' : 'default'}
          label={t(PASSWORD_FORMAT_KEYS[account.password_format])}
          {...(account.password_format_weak ? { title: t('account.list.passwordFormatWeak') } : {})}
        />
      ),
    },
    {
      id: 'status',
      header: t('account.list.column.status'),
      narrow: true,
      render: (account) =>
        account.enabled ? (
          <Chip size="small" color="success" variant="outlined" label={t('account.list.status.enabled')} />
        ) : (
          <Chip size="small" color="default" label={t('account.list.status.disabled')} />
        ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t('account.list.title')}
        description={t('account.list.description')}
        actions={
          domain !== undefined && can('account.write') ? (
            <Button
              component={RouterLink}
              to={`/accounts/new?domain=${encodeURIComponent(domain)}`}
              variant="contained"
              startIcon={<AddIcon />}
            >
              {t('account.list.action.create')}
            </Button>
          ) : undefined
        }
      />

      <Stack spacing={2}>
        <DomainPicker value={domain} onChange={setDomain} queryRoot="accounts" />

        {domain === undefined ? (
          <Alert severity="info">{t('account.list.chooseDomain')}</Alert>
        ) : (
          <DataTable
            label={t('account.list.title')}
            columns={columns}
            rows={accounts.data?.items}
            rowKey={(account) => account.id}
            meta={accounts.data?.meta}
            state={table}
            loading={accounts.isFetching}
            error={accounts.error}
            onRetry={() => void accounts.refetch()}
            emptyText={t('account.list.empty')}
            onRowClick={(account) => void navigate(accountPath(account.id))}
            toolbar={
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                <FilterField
                  label={t('account.list.filter.name')}
                  value={table.filters.name}
                  onChange={(value) => table.setFilter('name', value)}
                />
                <TextField
                  select
                  label={t('account.list.filter.passwordFormat')}
                  value={table.filters.password_format ?? ''}
                  onChange={(event) => table.setFilter('password_format', event.target.value)}
                  sx={{ minWidth: 220 }}
                >
                  <MenuItem value="">{t('account.list.filter.allFormats')}</MenuItem>
                  {PASSWORD_FORMATS.map((format) => (
                    <MenuItem key={format} value={format}>
                      {t(PASSWORD_FORMAT_KEYS[format])}
                    </MenuItem>
                  ))}
                </TextField>
              </Stack>
            }
          />
        )}
      </Stack>
    </>
  );
}
