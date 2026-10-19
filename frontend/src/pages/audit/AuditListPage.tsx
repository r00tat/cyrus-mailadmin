import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useMemo, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { FilterField } from '../../components/FilterField';
import { PageHeader } from '../../components/PageHeader';
import { FILTER_PATTERN, useTableState, type SortFieldOf } from '../../components/useTableState';
import { useFormatters, useTranslation } from '../../i18n';

import { AuditEntryDialog, type AuditEntry, type AuditNarrowing } from './AuditEntryDialog';
import { AuditObject, AuditOutcomeChip, OUTCOMES, OUTCOME_KEYS, ROLE_KEYS } from './auditLabels';
import { browserTimeZone, fromLocalInput, toLocalInput, validInstant } from './auditTime';

/**
 * S4 — the audit trail (`GET /audit`).
 *
 * **Newest first** by default, as the contract has it (U-65). All ten filters
 * of U-65 are offered and live in the URL, so a narrowed trail survives a
 * reload and can be handed on as a link — the repair queue links here with
 * `?request_id=`, which is the parameter name the contract uses.
 *
 * Times are shown in the browser's zone with the zone named (U-92), and the
 * zone is stated above the list. The `since`/`until` inputs speak local time;
 * the URL and the request carry UTC (see `auditTime.ts`).
 *
 * The list holds whole entries; a row opens one in a dialog, whose links
 * narrow the list by request, by object or by operation.
 */

const SORT = ['recorded_at', 'actor', 'action', 'object', 'id'] as const satisfies readonly SortFieldOf<'listAuditEntries'>[];

const FILTERS = {
  actor: 'text',
  action: FILTER_PATTERN.DOTTED_PREFIX,
  object_type: FILTER_PATTERN.PLAIN_NAME,
  object_id: 'text',
  domain: 'text',
  outcome: OUTCOMES,
  request_id: 'text',
  part_of: 'text',
  since: 'text',
  until: 'text',
} as const;

type FilterName = keyof typeof FILTERS;

const FILTER_NAMES = Object.keys(FILTERS) as FilterName[];

/** The contract's `part_of`: a positive int64. Anything else in the URL is left out, not sent. */
const PART_OF = /^[1-9]\d{0,17}$/;

export function AuditListPage(): ReactNode {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const client = useApiClient();
  const [, setSearchParams] = useSearchParams();
  const [selected, setSelected] = useState<AuditEntry | null>(null);

  const table = useTableState({ sortFields: SORT, defaultSort: 'recorded_at', defaultOrder: 'desc', filters: FILTERS });

  const query = useMemo(() => {
    const { part_of: partOf, since, until, ...rest } = table.query;
    const validSince = validInstant(since);
    const validUntil = validInstant(until);

    return {
      ...rest,
      ...(partOf !== undefined && PART_OF.test(partOf) ? { part_of: Number(partOf) } : {}),
      ...(validSince === undefined ? {} : { since: validSince }),
      ...(validUntil === undefined ? {} : { until: validUntil }),
    };
  }, [table.query]);

  const entries = useQuery({
    queryKey: ['audit', 'list', query],
    queryFn: ({ signal }) => client.get('/audit', { query, signal }),
    placeholderData: keepPreviousData,
  });

  /**
   * Several filters at once. `setFilter` cannot be called in a row for this:
   * each call starts from the URL as it was rendered, so the second would undo
   * the first. Sort, order and page size stay; the page goes back to 1.
   */
  const replaceFilters = (filters: AuditNarrowing): void => {
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current);

        for (const name of [...FILTER_NAMES, 'page']) {
          next.delete(name);
        }

        for (const [name, value] of Object.entries(filters)) {
          if (typeof value === 'string' && value !== '') {
            next.set(name, value);
          }
        }

        return next;
      },
      { replace: true },
    );
  };

  const anyFilter = FILTER_NAMES.some((name) => table.filters[name] !== undefined);

  const columns: DataTableColumn<AuditEntry, (typeof SORT)[number]>[] = [
    {
      id: 'recorded_at',
      header: t('audit.list.column.recordedAt'),
      sortField: 'recorded_at',
      render: (entry) => <Box component="span" sx={{ whiteSpace: 'nowrap' }}>{dateTime(entry.recorded_at)}</Box>,
    },
    {
      id: 'actor',
      header: t('audit.list.column.actor'),
      sortField: 'actor',
      render: (entry) => (
        <Stack spacing={0.25}>
          <span>{entry.actor}</span>
          {entry.actor_role !== null && entry.actor_role !== undefined && (
            <Typography variant="caption" color="text.secondary">
              {t(ROLE_KEYS[entry.actor_role])}
            </Typography>
          )}
        </Stack>
      ),
    },
    {
      id: 'action',
      header: t('audit.list.column.action'),
      sortField: 'action',
      render: (entry) => <Box component="span" sx={{ fontFamily: 'monospace' }}>{entry.action}</Box>,
    },
    {
      id: 'object',
      header: t('audit.list.column.object'),
      sortField: 'object',
      render: (entry) => <AuditObject type={entry.object_type} id={entry.object_id} />,
    },
    {
      id: 'domain',
      header: t('audit.list.column.domain'),
      render: (entry) => entry.domain ?? '',
    },
    {
      id: 'outcome',
      header: t('audit.list.column.outcome'),
      narrow: true,
      render: (entry) => <AuditOutcomeChip outcome={entry.outcome} />,
    },
    {
      id: 'request_id',
      header: t('audit.list.column.requestId'),
      render: (entry) => (
        <Box component="span" sx={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>
          {entry.request_id ?? ''}
        </Box>
      ),
    },
  ];

  const dateInput = (name: 'since' | 'until', label: string): ReactNode => (
    <TextField
      type="datetime-local"
      label={label}
      value={toLocalInput(table.filters[name])}
      onChange={(event) => table.setFilter(name, fromLocalInput(event.target.value, name))}
      slotProps={{ inputLabel: { shrink: true } }}
    />
  );

  return (
    <>
      <PageHeader title={t('audit.list.title')} description={t('audit.list.description')} />

      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
        {t('audit.list.timeZone', { zone: browserTimeZone() })}
      </Typography>

      <DataTable
        label={t('audit.list.title')}
        columns={columns}
        rows={entries.data?.items}
        rowKey={(entry) => String(entry.id)}
        meta={entries.data?.meta}
        state={table}
        loading={entries.isFetching}
        error={entries.error}
        onRetry={() => void entries.refetch()}
        emptyText={t('audit.list.empty')}
        onRowClick={setSelected}
        toolbar={
          <Stack spacing={2}>
            <Box
              sx={{
                display: 'grid',
                gap: 2,
                gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(5, 1fr)' },
              }}
            >
              <FilterField
                label={t('audit.list.filter.actor')}
                value={table.filters.actor}
                onChange={(value) => table.setFilter('actor', value)}
                maxLength={190}
              />
              <FilterField
                label={t('audit.list.filter.action')}
                value={table.filters.action}
                onChange={(value) => table.setFilter('action', value)}
                maxLength={64}
                errorText={table.invalidFilters.has('action') ? t('table.filter.invalidDotted') : undefined}
              />
              <FilterField
                label={t('audit.list.filter.objectType')}
                value={table.filters.object_type}
                onChange={(value) => table.setFilter('object_type', value)}
                maxLength={32}
                errorText={table.invalidFilters.has('object_type') ? t('table.filter.invalidPlain') : undefined}
              />
              <FilterField
                label={t('audit.list.filter.objectId')}
                value={table.filters.object_id}
                onChange={(value) => table.setFilter('object_id', value)}
                maxLength={320}
              />
              <FilterField
                label={t('audit.list.filter.domain')}
                value={table.filters.domain}
                onChange={(value) => table.setFilter('domain', value)}
                maxLength={255}
              />
              <TextField
                select
                label={t('audit.list.filter.outcome')}
                value={table.filters.outcome ?? ''}
                onChange={(event) => table.setFilter('outcome', event.target.value)}
              >
                <MenuItem value="">{t('audit.list.filter.outcomeAll')}</MenuItem>
                {OUTCOMES.map((outcome) => (
                  <MenuItem key={outcome} value={outcome}>
                    {t(OUTCOME_KEYS[outcome])}
                  </MenuItem>
                ))}
              </TextField>
              <FilterField
                label={t('audit.list.filter.requestId')}
                value={table.filters.request_id}
                onChange={(value) => table.setFilter('request_id', value)}
                maxLength={64}
              />
              <FilterField
                label={t('audit.list.filter.partOf')}
                value={table.filters.part_of}
                onChange={(value) => table.setFilter('part_of', value)}
                maxLength={19}
              />
              {dateInput('since', t('audit.list.filter.since'))}
              {dateInput('until', t('audit.list.filter.until'))}
            </Box>
            {anyFilter && (
              <Box>
                <Button size="small" onClick={() => replaceFilters({})}>
                  {t('audit.list.filter.reset')}
                </Button>
              </Box>
            )}
          </Stack>
        }
      />

      <AuditEntryDialog
        entry={selected}
        onClose={() => setSelected(null)}
        onNarrow={(narrowing) => {
          setSelected(null);
          replaceFilters(narrowing);
        }}
      />
    </>
  );
}
