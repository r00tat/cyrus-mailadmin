import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import Link from '@mui/material/Link';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { FilterField } from '../../components/FilterField';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { useTableState, type SortFieldOf } from '../../components/useTableState';
import { useTranslation } from '../../i18n';
import { DomainPicker } from '../accounts/DomainPicker';
import { accountPath } from '../accounts/labels';
import { useDomainParam } from '../accounts/useDomainParam';
import { listPath } from '../lists/listShared';

import { AliasCreateDialog } from './AliasCreateDialog';
import { CatchallCard } from './CatchallCard';
import { ENTRY_TYPES, ENTRY_TYPE_KEYS, UNKNOWN_REASON_KEYS, aliasPath, type AddressEntry } from './labels';
import { useReservedConfirmation } from './useReservedConfirmation';

/**
 * A3 — every row of a domain in `virtual`, classified.
 *
 * The four types of architecture 3.4 — an account's own address, alias,
 * catchall, forward —, the row that routes a mailing list (A5), and the rows
 * that fit none, which are **shown** with the reason (U-22, U-103), never
 * skipped. Any row except an account's own address and a list's row can be
 * removed one by one here; that is how the leftovers that block deleting a
 * domain are cleared (`domain.still_has_addresses`). A list's row links to
 * the list, which is changed and deleted only there.
 *
 * Aliases are created here and changed on their own page; the catchall has
 * its card above the list; forwards and vacation notices live on the account
 * page.
 */

const SORT = ['alias', 'type'] as const satisfies readonly SortFieldOf<'listDomainAddresses'>[];
const FILTERS = { alias: 'text', type: ENTRY_TYPES } as const;

export function AddressListPage(): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const { can } = useSession();
  const [domain, setDomain] = useDomainParam();
  const [creating, setCreating] = useState(false);
  const [removing, setRemoving] = useState<AddressEntry | null>(null);

  const table = useTableState({ sortFields: SORT, defaultSort: 'alias', filters: FILTERS });

  const entries = useQuery({
    queryKey: ['addresses', 'list', domain, table.query],
    queryFn: ({ signal }) =>
      client.get('/domains/{domain}/addresses', {
        pathParams: { domain: domain ?? '' },
        query: table.query,
        signal,
      }),
    enabled: domain !== undefined,
    placeholderData: keepPreviousData,
  });

  const columns: DataTableColumn<AddressEntry, (typeof SORT)[number]>[] = [
    {
      id: 'alias',
      header: t('address.list.column.alias'),
      sortField: 'alias',
      render: (entry) => <EntryLink entry={entry} />,
    },
    {
      id: 'type',
      header: t('address.list.column.type'),
      sortField: 'type',
      render: (entry) => (
        <Stack spacing={0.5} alignItems="flex-start">
          <Stack direction="row" spacing={0.5}>
            <Chip
              size="small"
              color={entry.type === 'unknown' ? 'warning' : 'default'}
              label={t(ENTRY_TYPE_KEYS[entry.type])}
            />
            {entry.reserved && <Chip size="small" variant="outlined" label={t('address.list.reserved')} />}
          </Stack>
          {entry.unknown_reason !== null && (
            <Typography variant="caption" color="text.secondary">
              {t(UNKNOWN_REASON_KEYS[entry.unknown_reason])}
            </Typography>
          )}
        </Stack>
      ),
    },
    {
      id: 'destinations',
      header: t('address.list.column.destinations'),
      render: (entry) => (
        <Typography variant="body2" sx={{ overflowWrap: 'anywhere' }}>
          {entry.destinations.length === 0 ? t('address.list.noDestination') : entry.destinations.join(', ')}
        </Typography>
      ),
    },
    {
      id: 'sender',
      header: t('address.list.column.sender'),
      render: (entry) => entry.sender_account ?? t('address.list.senderNone'),
    },
    {
      id: 'status',
      header: t('address.list.column.status'),
      narrow: true,
      render: (entry) =>
        entry.enabled ? (
          <Chip size="small" color="success" variant="outlined" label={t('address.list.status.enabled')} />
        ) : (
          <Chip size="small" label={t('address.list.status.disabled')} />
        ),
    },
    ...(can('address.delete')
      ? [
          {
            id: 'actions',
            header: t('address.list.column.actions'),
            narrow: true,
            align: 'right' as const,
            render: (entry: AddressEntry) =>
              // An account's own address goes with the account (U-83), a
              // list's row with the list (A5).
              entry.type === 'mailbox_address' || entry.type === 'mailing_list' ? null : (
                <IconButton
                  size="small"
                  aria-label={t('address.list.action.deleteEntry', { alias: entry.alias })}
                  onClick={() => setRemoving(entry)}
                >
                  <DeleteIcon fontSize="small" />
                </IconButton>
              ),
          },
        ]
      : []),
  ];

  return (
    <>
      <PageHeader
        title={t('address.list.title')}
        description={t('address.list.description')}
        actions={
          domain !== undefined && can('address.write') ? (
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreating(true)}>
              {t('address.list.action.createAlias')}
            </Button>
          ) : undefined
        }
      />

      <Stack spacing={2}>
        <DomainPicker value={domain} onChange={setDomain} queryRoot="addresses" />

        {domain === undefined ? (
          <Alert severity="info">{t('address.list.chooseDomain')}</Alert>
        ) : (
          <>
            <CatchallCard domain={domain} />
            <DataTable
              label={t('address.list.title')}
              columns={columns}
              rows={entries.data?.items}
              rowKey={(entry) => entry.id}
              meta={entries.data?.meta}
              state={table}
              loading={entries.isFetching}
              error={entries.error}
              onRetry={() => void entries.refetch()}
              emptyText={t('address.list.empty')}
              toolbar={
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                  <FilterField
                    label={t('address.list.filter.alias')}
                    value={table.filters.alias}
                    onChange={(value) => table.setFilter('alias', value)}
                  />
                  <TextField
                    select
                    label={t('address.list.filter.type')}
                    value={table.filters.type ?? ''}
                    onChange={(event) => table.setFilter('type', event.target.value)}
                    sx={{ minWidth: 200 }}
                  >
                    <MenuItem value="">{t('address.list.filter.allTypes')}</MenuItem>
                    {ENTRY_TYPES.map((type) => (
                      <MenuItem key={type} value={type}>
                        {t(ENTRY_TYPE_KEYS[type])}
                      </MenuItem>
                    ))}
                  </TextField>
                </Stack>
              }
            />
          </>
        )}
      </Stack>

      {domain !== undefined && creating && <AliasCreateDialog domain={domain} onClose={() => setCreating(false)} />}

      {domain !== undefined && removing !== null && (
        <EntryDeleteDialog domain={domain} entry={removing} onClose={() => setRemoving(null)} />
      )}
    </>
  );
}

/** The address cell: a link where the row has a page of its own. */
function EntryLink({ entry }: { entry: AddressEntry }): ReactNode {
  let to: string | null = null;

  if (entry.type === 'alias') {
    to = aliasPath(entry.alias);
  } else if (entry.type === 'mailing_list') {
    // The row's alias is the list address, which is the list's identifier.
    to = listPath(entry.alias);
  } else if (entry.type === 'forward') {
    // A forward row is keyed by the account's stored user name.
    to = accountPath(entry.alias);
  } else if (entry.type === 'mailbox_address' && entry.sender_account !== null) {
    to = accountPath(entry.sender_account);
  }

  if (to === null) {
    return <span style={{ overflowWrap: 'anywhere' }}>{entry.alias}</span>;
  }

  return (
    <Link component={RouterLink} to={to} sx={{ overflowWrap: 'anywhere' }}>
      {entry.alias}
    </Link>
  );
}

/**
 * Removes exactly one row by its fingerprint (U-103). A row that changed in
 * the meantime has another fingerprint and answers `404`; the list is then
 * reloaded.
 */
function EntryDeleteDialog({
  domain,
  entry,
  onClose,
}: {
  domain: string;
  entry: AddressEntry;
  onClose: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const reserved = useReservedConfirmation();

  const remove = useMutation({
    mutationFn: (confirmReserved: boolean) =>
      client.delete('/domains/{domain}/addresses/{entry}', {
        pathParams: { domain, entry: entry.id },
        query: confirmReserved ? { confirm_reserved: true } : {},
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['addresses'] });
      notify(t('address.list.deleteEntry.done', { alias: entry.alias }));
      onClose();
    },
    onError: (error) => {
      reserved.onError(error);
      void queryClient.invalidateQueries({ queryKey: ['addresses', 'list'] });
    },
  });

  return (
    <ConfirmDialog
      open
      title={t('address.list.deleteEntry.title')}
      message={
        reserved.asking ? (
          <Alert severity="warning">{t('address.reserved.message', { address: entry.alias })}</Alert>
        ) : (
          t('address.list.deleteEntry.message', {
            alias: entry.alias,
            destinations: entry.destinations.length === 0 ? t('address.list.noDestination') : entry.destinations.join(', '),
          })
        )
      }
      destructive
      {...(reserved.asking ? { confirmLabel: t('address.reserved.confirmRemove') } : {})}
      pending={remove.isPending}
      error={reserved.visibleError(remove.error)}
      onConfirm={() => remove.mutate(reserved.confirmed)}
      onClose={onClose}
    />
  );
}
