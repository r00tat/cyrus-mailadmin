import AddIcon from '@mui/icons-material/Add';
import KeyIcon from '@mui/icons-material/Key';
import Button from '@mui/material/Button';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { useSession, type AdminRole } from '../../auth/SessionContext';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { FilterField } from '../../components/FilterField';
import { PageHeader } from '../../components/PageHeader';
import { useTableState, type SortFieldOf } from '../../components/useTableState';
import { useTranslation } from '../../i18n';

import { AdminCreateDialog } from './AdminCreateDialog';
import { DomainScope, PasswordFormat, ROLES, ROLE_KEYS, adminPath, type Admin } from './adminShared';

/**
 * A4 — every administrator, superuser-only. The list shows the stored state
 * as it is (U-22): passwords still in a legacy hash format and assignments to
 * domains that no longer exist are marked, not hidden.
 */

const SORT = ['username', 'role'] as const satisfies readonly SortFieldOf<'listAdmins'>[];

const FILTERS = { username: 'text', role: ['superuser', 'domainmaster'] } as const;

/** The empty choice of the role filter: every role. */
const ANY_ROLE = '';

export function AdminListPage(): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const navigate = useNavigate();
  const { can } = useSession();
  const [creating, setCreating] = useState(false);

  const table = useTableState({ sortFields: SORT, defaultSort: 'username', filters: FILTERS });
  const admins = useQuery({
    queryKey: ['admins', 'list', table.query],
    queryFn: ({ signal }) => client.get('/admins', { query: table.query, signal }),
    placeholderData: keepPreviousData,
  });

  const columns: readonly DataTableColumn<Admin, (typeof SORT)[number]>[] = [
    { id: 'username', header: t('admin.field.username'), sortField: 'username', render: (row) => row.username },
    { id: 'role', header: t('admin.field.role'), sortField: 'role', render: (row) => t(ROLE_KEYS[row.role]) },
    { id: 'domains', header: t('admin.field.domains'), render: (row) => <DomainScope admin={row} /> },
    { id: 'password', header: t('admin.field.passwordFormat'), render: (row) => <PasswordFormat admin={row} /> },
  ];

  const filtered = table.filters.username !== undefined || table.filters.role !== undefined;

  return (
    <>
      <PageHeader
        title={t('admin.list.title')}
        actions={
          <>
            {can('profile.password.write') && (
              <Button component={RouterLink} to="/admins/me/password" startIcon={<KeyIcon />}>
                {t('admin.list.action.ownPassword')}
              </Button>
            )}
            {can('admin.write') && (
              <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreating(true)}>
                {t('admin.list.action.create')}
              </Button>
            )}
          </>
        }
      />
      <DataTable
        label={t('admin.list.title')}
        columns={columns}
        rows={admins.data?.items}
        rowKey={(row) => row.username}
        meta={admins.data?.meta}
        state={table}
        loading={admins.isFetching}
        error={admins.error}
        onRetry={() => void admins.refetch()}
        emptyText={filtered ? t('admin.list.emptyFiltered') : t('admin.list.empty')}
        toolbar={
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <FilterField
              label={t('admin.list.filter.username')}
              value={table.filters.username}
              onChange={(value) => table.setFilter('username', value)}
              maxLength={50}
            />
            <TextField
              select
              label={t('admin.list.filter.role')}
              value={table.filters.role ?? ANY_ROLE}
              onChange={(event) => table.setFilter('role', event.target.value)}
              slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
              sx={{ minWidth: 220 }}
            >
              <MenuItem value={ANY_ROLE}>{t('admin.list.filter.anyRole')}</MenuItem>
              {ROLES.map((role: AdminRole) => (
                <MenuItem key={role} value={role}>
                  {t(ROLE_KEYS[role])}
                </MenuItem>
              ))}
            </TextField>
          </Stack>
        }
        onRowClick={(row) => void navigate(adminPath(row.username))}
      />
      <AdminCreateDialog open={creating} onClose={() => setCreating(false)} />
    </>
  );
}
