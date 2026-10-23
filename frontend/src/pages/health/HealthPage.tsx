import RefreshIcon from '@mui/icons-material/Refresh';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useFormatters, useTranslation, type TranslationKey } from '../../i18n';
import { useErrorCodeText } from '../../i18n/problemText';


/**
 * A6 — the detailed health view (`GET /maintenance/health`, U-121).
 *
 * Every probe runs when the page asks; the answer is never cached on the
 * server, so the view does not cache it either (`staleTime: 0`) and offers a
 * button to probe again. The endpoint always answers `200`; the verdict is in
 * `status`, and a failed probe carries a contract error code that is
 * translated through the catalogue like any other.
 */

type HealthCheck = components['schemas']['HealthCheckDetails'];
type ListDeliverySettings = components['schemas']['ListDeliverySettings'];

const LIST_DELIVERY_MODE_KEYS: Record<ListDeliverySettings['mode'], TranslationKey> = {
  alias: 'maintenance.health.listDelivery.mode.alias',
  transport: 'maintenance.health.listDelivery.mode.transport',
};

const LIST_DELIVERY_HEADING_ID = 'health-list-delivery';

const COMPONENT_KEYS: Record<HealthCheck['component'], TranslationKey> = {
  database: 'maintenance.health.component.database',
  imap: 'maintenance.health.component.imap',
  managesieve: 'maintenance.health.component.managesieve',
};

const STATUS_KEYS: Record<HealthCheck['status'], TranslationKey> = {
  ok: 'maintenance.health.status.ok',
  error: 'maintenance.health.status.error',
  skipped: 'maintenance.health.status.skipped',
};

const STATUS_COLOURS: Record<HealthCheck['status'], 'success' | 'error' | 'default'> = {
  ok: 'success',
  error: 'error',
  skipped: 'default',
};

const HEALTH_QUERY_KEY = ['health', 'details'] as const;

export function HealthPage(): ReactNode {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const client = useApiClient();
  const codeText = useErrorCodeText();

  const health = useQuery({
    queryKey: HEALTH_QUERY_KEY,
    queryFn: ({ signal }) => client.get('/maintenance/health', { signal }),
    staleTime: 0,
  });

  const columns: DataTableColumn<HealthCheck>[] = [
    {
      id: 'component',
      header: t('maintenance.health.column.component'),
      render: (check) => t(COMPONENT_KEYS[check.component]),
    },
    {
      id: 'status',
      header: t('maintenance.health.column.status'),
      narrow: true,
      render: (check) => (
        <Chip size="small" color={STATUS_COLOURS[check.status]} label={t(STATUS_KEYS[check.status])} />
      ),
    },
    {
      id: 'code',
      header: t('maintenance.health.column.code'),
      render: (check) =>
        check.code === null ? (
          t('maintenance.health.noCode')
        ) : (
          <Stack spacing={0.25}>
            <span>{codeText(check.code)}</span>
            <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>
              {check.code}
            </Typography>
          </Stack>
        ),
    },
    {
      id: 'duration',
      header: t('maintenance.health.column.duration'),
      align: 'right',
      narrow: true,
      render: (check) => t('maintenance.health.duration', { milliseconds: check.duration_ms }),
    },
  ];

  return (
    <>
      <PageHeader
        title={t('maintenance.health.title')}
        description={t('maintenance.health.description')}
        actions={
          <Button
            variant="outlined"
            startIcon={<RefreshIcon />}
            disabled={health.isFetching}
            onClick={() => void health.refetch()}
          >
            {t('maintenance.health.refresh')}
          </Button>
        }
      />

      {health.isPending && <LoadingState />}

      {health.isError && health.data === undefined && (
        <ErrorState error={health.error} onRetry={() => void health.refetch()} />
      )}

      {health.data !== undefined && (
        <Stack spacing={2}>
          <Alert severity={health.data.status === 'ok' ? 'success' : 'warning'}>
            {health.data.status === 'ok'
              ? t('maintenance.health.overall.ok')
              : t('maintenance.health.overall.degraded')}
          </Alert>

          {health.isError && <ErrorState error={health.error} />}

          <Box>
            <Typography variant="body2" color="text.secondary">
              {t('maintenance.health.checkedAt', { checkedAt: dateTime(health.data.checked_at) })}
            </Typography>
          </Box>

          <DataTable
            label={t('maintenance.health.title')}
            columns={columns}
            rows={health.data.checks}
            rowKey={(check) => check.component}
            loading={health.isFetching}
          />

          <ListDelivery settings={health.data.list_delivery} />
        </Stack>
      )}
    </>
  );
}

/**
 * The list delivery settings (A6): a setting, not a probe, so they do not
 * change the verdict above. A transport domain that is also a managed domain
 * is a configuration error — Postfix would deliver locally and the pipe
 * transport would never see the mail — and gets a warning of its own.
 */
function ListDelivery({ settings }: { settings: ListDeliverySettings }): ReactNode {
  const { t } = useTranslation();

  return (
    <Box component="section" aria-labelledby={LIST_DELIVERY_HEADING_ID}>
      <Typography id={LIST_DELIVERY_HEADING_ID} variant="h6" component="h2" gutterBottom>
        {t('maintenance.health.listDelivery.title')}
      </Typography>
      <Stack spacing={1}>
        <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'max-content 1fr', columnGap: 2, rowGap: 0.5 }}>
          <Typography component="dt" variant="body2" color="text.secondary">
            {t('maintenance.health.listDelivery.mode')}
          </Typography>
          <Typography component="dd" variant="body2" sx={{ m: 0 }}>
            {t(LIST_DELIVERY_MODE_KEYS[settings.mode])}
          </Typography>
          <Typography component="dt" variant="body2" color="text.secondary">
            {t('maintenance.health.listDelivery.transportDomain')}
          </Typography>
          <Typography component="dd" variant="body2" sx={{ m: 0, fontFamily: 'monospace' }}>
            {settings.transport_domain}
          </Typography>
        </Box>
        {settings.transport_domain_is_managed === true && (
          <Alert severity="warning">
            {t('maintenance.health.listDelivery.managed', { domain: settings.transport_domain })}
          </Alert>
        )}
        {settings.transport_domain_is_managed === null && (
          <Alert severity="info">{t('maintenance.health.listDelivery.managedUnknown')}</Alert>
        )}
      </Stack>
    </Box>
  );
}
