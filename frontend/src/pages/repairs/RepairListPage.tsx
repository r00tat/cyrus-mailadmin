import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { useSession } from '../../auth/SessionContext';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { FilterField } from '../../components/FilterField';
import { PageHeader } from '../../components/PageHeader';
import { FILTER_PATTERN, useTableState, type SortFieldOf } from '../../components/useTableState';
import { useFormatters, useTranslation } from '../../i18n';

import {
  REPAIRS_QUERY_ROOT,
  RepairActionDialog,
  availableActions,
  type RepairAction,
  type RepairEntry,
} from './repairActions';
import { RepairStatusChip } from './RepairStatusChip';

/**
 * A6 — the repair queue (`GET /maintenance/repairs`).
 *
 * Newest first, as the contract defaults (`id`, `desc`). The status filter
 * offers the contract's two values; `operation` matches as a prefix on the
 * server, so `account.` finds everything account operations left behind.
 *
 * Retry and resolve are offered per row to a session with
 * `maintenance.write`; a row opens the entry with everything it records.
 */

const SORT = ['id', 'recorded_at', 'operation'] as const satisfies readonly SortFieldOf<'listRepairs'>[];
const STATUSES = ['open', 'resolved'] as const;
const FILTERS = { status: STATUSES, operation: FILTER_PATTERN.DOTTED_PREFIX, step: FILTER_PATTERN.DOTTED_NAME } as const;

export function RepairListPage(): ReactNode {
  const { t } = useTranslation();
  const { dateTime, number } = useFormatters();
  const client = useApiClient();
  const navigate = useNavigate();
  const { can } = useSession();
  const [action, setAction] = useState<RepairAction | null>(null);

  const table = useTableState({ sortFields: SORT, defaultSort: 'id', defaultOrder: 'desc', filters: FILTERS });

  const repairs = useQuery({
    queryKey: [REPAIRS_QUERY_ROOT, 'list', table.query],
    queryFn: ({ signal }) => client.get('/maintenance/repairs', { query: table.query, signal }),
    placeholderData: keepPreviousData,
  });

  const mayWrite = can('maintenance.write');

  const columns: DataTableColumn<RepairEntry, (typeof SORT)[number]>[] = [
    {
      id: 'id',
      header: t('maintenance.repairs.column.id'),
      sortField: 'id',
      narrow: true,
      render: (entry) => String(entry.id),
    },
    {
      id: 'recorded_at',
      header: t('maintenance.repairs.column.recordedAt'),
      sortField: 'recorded_at',
      render: (entry) => dateTime(entry.recorded_at),
    },
    {
      id: 'operation',
      header: t('maintenance.repairs.column.operation'),
      sortField: 'operation',
      render: (entry) => <Box component="span" sx={{ fontFamily: 'monospace' }}>{entry.operation}</Box>,
    },
    {
      id: 'step',
      header: t('maintenance.repairs.column.step'),
      render: (entry) => <Box component="span" sx={{ fontFamily: 'monospace' }}>{entry.step}</Box>,
    },
    {
      id: 'subject',
      header: t('maintenance.repairs.column.subject'),
      render: (entry) => entry.subject,
    },
    {
      id: 'status',
      header: t('maintenance.repairs.column.status'),
      narrow: true,
      render: (entry) => <RepairStatusChip entry={entry} />,
    },
    {
      id: 'attempts',
      header: t('maintenance.repairs.column.attempts'),
      align: 'right',
      narrow: true,
      render: (entry) => number(entry.attempts),
    },
  ];

  if (mayWrite) {
    columns.push({
      id: 'actions',
      header: t('maintenance.repairs.column.actions'),
      narrow: true,
      render: (entry) => {
        const offered = availableActions(entry);

        if (!offered.resolve) {
          return null;
        }

        return (
          // The row itself navigates; a click on a button must not.
          <Stack
            direction="row"
            spacing={1}
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
          >
            {offered.retry ? (
              <Button
                size="small"
                aria-label={t('maintenance.repairs.action.retryEntry', { id: String(entry.id) })}
                onClick={() => setAction({ kind: 'retry', entry })}
              >
                {t('maintenance.repairs.action.retry')}
              </Button>
            ) : (
              <Chip size="small" variant="outlined" label={t('maintenance.repairs.notRetryable')} />
            )}
            <Button
              size="small"
              aria-label={t('maintenance.repairs.action.resolveEntry', { id: String(entry.id) })}
              onClick={() => setAction({ kind: 'resolve', entry })}
            >
              {t('maintenance.repairs.action.resolve')}
            </Button>
          </Stack>
        );
      },
    });
  }

  return (
    <>
      <PageHeader title={t('maintenance.repairs.title')} description={t('maintenance.repairs.description')} />

      <DataTable
        label={t('maintenance.repairs.title')}
        columns={columns}
        rows={repairs.data?.items}
        rowKey={(entry) => String(entry.id)}
        meta={repairs.data?.meta}
        state={table}
        loading={repairs.isFetching}
        error={repairs.error}
        onRetry={() => void repairs.refetch()}
        emptyText={t('maintenance.repairs.empty')}
        onRowClick={(entry) => void navigate(`/maintenance/repairs/${encodeURIComponent(String(entry.id))}`)}
        toolbar={
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField
              select
              label={t('maintenance.repairs.filter.status')}
              value={table.filters.status ?? ''}
              onChange={(event) => table.setFilter('status', event.target.value)}
              sx={{ minWidth: 160 }}
            >
              <MenuItem value="">{t('maintenance.repairs.filter.statusAll')}</MenuItem>
              <MenuItem value="open">{t('maintenance.repairs.status.open')}</MenuItem>
              <MenuItem value="resolved">{t('maintenance.repairs.status.resolved')}</MenuItem>
            </TextField>
            <FilterField
              label={t('maintenance.repairs.filter.operation')}
              value={table.filters.operation}
              onChange={(value) => table.setFilter('operation', value)}
              maxLength={64}
              errorText={table.invalidFilters.has('operation') ? t('table.filter.invalidDotted') : undefined}
            />
            <FilterField
              label={t('maintenance.repairs.filter.step')}
              value={table.filters.step}
              onChange={(value) => table.setFilter('step', value)}
              maxLength={64}
              errorText={table.invalidFilters.has('step') ? t('table.filter.invalidDotted') : undefined}
            />
          </Stack>
        }
      />

      <RepairActionDialog action={action} onClose={() => setAction(null)} />
    </>
  );
}
