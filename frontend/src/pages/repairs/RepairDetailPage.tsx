import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { useSession } from '../../auth/SessionContext';
import { DataTable } from '../../components/DataTable';
import { DefinitionItem, DefinitionList, Monospace } from '../../components/DefinitionList';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useFormatters, useTranslation } from '../../i18n';
import { useErrorCodeText } from '../../i18n/problemText';

import {
  REPAIRS_QUERY_ROOT,
  RepairActionDialog,
  availableActions,
  type RepairAction,
  type RepairEntry,
} from './repairActions';
import { RepairStatusChip } from './RepairStatusChip';

/**
 * A6 — one repair-queue entry (`GET /maintenance/repairs/{repair}`).
 *
 * `intended`, `reached` and `reason` are English operator notes and **not a
 * contract** (E-16): they are shown as recorded, in their own section that
 * says so, never as a message of this interface. `last_error` is a contract
 * error code and goes through the catalogue.
 *
 * The request id leads into the audit trail filtered by that request — the
 * same key that finds the log lines (S4) — for a session that may read it.
 */

function RepairDetails({ entry }: { entry: RepairEntry }): ReactNode {
  const { t } = useTranslation();
  const { dateTime, number } = useFormatters();
  const { can } = useSession();
  const codeText = useErrorCodeText();

  const empty = t('maintenance.repairs.detail.empty');
  const parameters = Object.entries(entry.parameters).map(([name, value]) => ({ name, value }));

  return (
    <Stack spacing={3}>
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Typography variant="h6" component="h2" sx={{ mb: 1.5 }}>
          {t('maintenance.repairs.detail.section.state')}
        </Typography>
        <DefinitionList>
          <DefinitionItem label={t('maintenance.repairs.detail.field.status')}>
            <RepairStatusChip entry={entry} />
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.recordedAt')}>{dateTime(entry.recorded_at)}</DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.operation')}>
            <Monospace>{entry.operation}</Monospace>
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.step')}>
            <Monospace>{entry.step}</Monospace>
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.subject')}>{entry.subject}</DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.requestId')}>
            {entry.request_id === null ? (
              empty
            ) : (
              <Stack direction="row" spacing={2} alignItems="baseline" flexWrap="wrap" useFlexGap>
                <Monospace>{entry.request_id}</Monospace>
                {can('audit.read') && (
                  <Link component={RouterLink} to={`/audit?request_id=${encodeURIComponent(entry.request_id)}`}>
                    {t('maintenance.repairs.detail.auditLink')}
                  </Link>
                )}
              </Stack>
            )}
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.attempts')}>{number(entry.attempts)}</DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.lastAttemptAt')}>
            {entry.last_attempt_at === null ? empty : dateTime(entry.last_attempt_at)}
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.lastError')}>
            {entry.last_error === null ? (
              empty
            ) : (
              <Stack component="span" spacing={0.25}>
                <span>{codeText(entry.last_error)}</span>
                <Typography component="span" variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>
                  {entry.last_error}
                </Typography>
              </Stack>
            )}
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.resolvedAt')}>
            {entry.resolved_at === null ? empty : dateTime(entry.resolved_at)}
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.resolvedBy')}>{entry.resolved_by ?? empty}</DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.note')}>
            {entry.note === null || entry.note === '' ? empty : <Box component="span" sx={{ whiteSpace: 'pre-wrap' }}>{entry.note}</Box>}
          </DefinitionItem>
        </DefinitionList>
      </Paper>

      <Paper variant="outlined" sx={{ p: 2 }}>
        <Typography variant="h6" component="h2">
          {t('maintenance.repairs.detail.section.operator')}
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
          {t('maintenance.repairs.detail.section.operatorHint')}
        </Typography>
        <DefinitionList>
          <DefinitionItem label={t('maintenance.repairs.detail.field.intended')}>
            <Monospace>{entry.intended}</Monospace>
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.reached')}>
            <Monospace>{entry.reached}</Monospace>
          </DefinitionItem>
          <DefinitionItem label={t('maintenance.repairs.detail.field.reason')}>
            <Monospace>{entry.reason}</Monospace>
          </DefinitionItem>
        </DefinitionList>
      </Paper>

      <Box component="section">
        <Typography variant="h6" component="h2" sx={{ mb: 1 }}>
          {t('maintenance.repairs.detail.section.parameters')}
        </Typography>
        <DataTable
          label={t('maintenance.repairs.detail.section.parameters')}
          columns={[
            {
              id: 'name',
              header: t('maintenance.repairs.detail.parameters.name'),
              narrow: true,
              render: (row) => <Monospace>{row.name}</Monospace>,
            },
            {
              id: 'value',
              header: t('maintenance.repairs.detail.parameters.value'),
              render: (row) => <Monospace>{row.value}</Monospace>,
            },
          ]}
          rows={parameters}
          rowKey={(row) => row.name}
          emptyText={t('maintenance.repairs.detail.parameters.none')}
        />
      </Box>
    </Stack>
  );
}

export function RepairDetailPage(): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const { can } = useSession();
  const { repair = '' } = useParams();
  const [action, setAction] = useState<RepairAction | null>(null);

  const entry = useQuery({
    queryKey: [REPAIRS_QUERY_ROOT, 'detail', repair],
    queryFn: ({ signal }) => client.get('/maintenance/repairs/{repair}', { pathParams: { repair }, signal }),
  });

  const offered = entry.data === undefined ? { retry: false, resolve: false } : availableActions(entry.data);
  const mayWrite = can('maintenance.write');

  return (
    <>
      <PageHeader
        title={t('maintenance.repairs.detail.title', { id: repair })}
        breadcrumbs={[
          { label: t('maintenance.repairs.title'), to: '/maintenance/repairs' },
          { label: t('maintenance.repairs.detail.title', { id: repair }) },
        ]}
        {...(mayWrite && entry.data !== undefined && offered.resolve
          ? {
              actions: (
                <>
                  {offered.retry && (
                    <Button variant="contained" onClick={() => entry.data && setAction({ kind: 'retry', entry: entry.data })}>
                      {t('maintenance.repairs.action.retry')}
                    </Button>
                  )}
                  <Button variant="outlined" onClick={() => entry.data && setAction({ kind: 'resolve', entry: entry.data })}>
                    {t('maintenance.repairs.action.resolve')}
                  </Button>
                </>
              ),
            }
          : {})}
      />

      {entry.isPending && <LoadingState />}
      {entry.isError && <ErrorState error={entry.error} onRetry={() => void entry.refetch()} />}
      {entry.data !== undefined && <RepairDetails entry={entry.data} />}

      <RepairActionDialog action={action} onClose={() => setAction(null)} />
    </>
  );
}
