import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import RefreshIcon from '@mui/icons-material/Refresh';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import { DataTable, type DataTableColumn } from '../../components/DataTable';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useFormatters, useTranslation, type TranslationKey } from '../../i18n';

/**
 * A6 — the reconciliation report (`GET /maintenance/reconciliation`).
 *
 * The run is synchronous, reads four tables whole and lists every user root
 * in Cyrus (U-115, U-116). It is therefore **started by the administrator**,
 * not by opening the page, and not retried automatically: a page that
 * triggered a full mailbox listing on every visit — or three times after a
 * `503` — would make the comparison the most expensive thing in the tool.
 * The last report stays in the query cache while the administrator moves
 * around, and "run again" asks for a fresh one.
 *
 * The findings are grouped by the eight categories of the contract, in the
 * contract's order. The summary names every category, with `0` where nothing
 * was found — "no findings" over zero mailboxes and over four thousand are
 * different statements, which is also why `checked` is shown.
 *
 * Nothing on this page changes anything (E-18).
 */

type Category = components['schemas']['ReconciliationCategory'];
type Finding = components['schemas']['ReconciliationFinding'];

/** The contract's order: mailbox problems first, then the address table. */
const CATEGORIES = [
  'account_without_mailbox',
  'mailbox_without_account',
  'shadow_mailbox',
  'unresolvable_account',
  'ambiguous_username',
  'virtual_owner_missing',
  'virtual_target_missing',
  'virtual_unclassified',
] as const satisfies readonly Category[];

const CATEGORY_KEYS: Record<Category, TranslationKey> = {
  account_without_mailbox: 'maintenance.reconciliation.category.accountWithoutMailbox',
  mailbox_without_account: 'maintenance.reconciliation.category.mailboxWithoutAccount',
  shadow_mailbox: 'maintenance.reconciliation.category.shadowMailbox',
  unresolvable_account: 'maintenance.reconciliation.category.unresolvableAccount',
  ambiguous_username: 'maintenance.reconciliation.category.ambiguousUsername',
  virtual_owner_missing: 'maintenance.reconciliation.category.virtualOwnerMissing',
  virtual_target_missing: 'maintenance.reconciliation.category.virtualTargetMissing',
  virtual_unclassified: 'maintenance.reconciliation.category.virtualUnclassified',
};

const CATEGORY_HINT_KEYS: Record<Category, TranslationKey> = {
  account_without_mailbox: 'maintenance.reconciliation.categoryHint.accountWithoutMailbox',
  mailbox_without_account: 'maintenance.reconciliation.categoryHint.mailboxWithoutAccount',
  shadow_mailbox: 'maintenance.reconciliation.categoryHint.shadowMailbox',
  unresolvable_account: 'maintenance.reconciliation.categoryHint.unresolvableAccount',
  ambiguous_username: 'maintenance.reconciliation.categoryHint.ambiguousUsername',
  virtual_owner_missing: 'maintenance.reconciliation.categoryHint.virtualOwnerMissing',
  virtual_target_missing: 'maintenance.reconciliation.categoryHint.virtualTargetMissing',
  virtual_unclassified: 'maintenance.reconciliation.categoryHint.virtualUnclassified',
};

const RECONCILIATION_QUERY_KEY = ['reconciliation', 'report'] as const;

function headingId(category: Category): string {
  return `reconciliation-${category}`;
}

/** `null` shown as a dash; a stored value shown exactly as stored. */
function Stored({ value }: { value: string | null }): ReactNode {
  const { t } = useTranslation();

  return value === null ? t('maintenance.repairs.detail.empty') : <Box component="span" sx={{ fontFamily: 'monospace' }}>{value}</Box>;
}

function FindingGroup({ category, findings }: { category: Category; findings: readonly Finding[] }): ReactNode {
  const { t } = useTranslation();

  const columns: DataTableColumn<Finding>[] = [
    { id: 'subject', header: t('maintenance.reconciliation.column.subject'), render: (finding) => <Stored value={finding.subject} /> },
    { id: 'domain', header: t('maintenance.reconciliation.column.domain'), render: (finding) => <Stored value={finding.domain} /> },
    { id: 'related', header: t('maintenance.reconciliation.column.related'), render: (finding) => <Stored value={finding.related} /> },
    {
      id: 'reason',
      header: t('maintenance.reconciliation.column.reason'),
      // A stable key refining the category, not a message (contract). Shown as
      // the key it is, so that it can be looked up rather than misread.
      render: (finding) => <Stored value={finding.reason} />,
    },
  ];

  return (
    <Box component="section" aria-labelledby={headingId(category)}>
      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
        <Typography variant="h6" component="h2" id={headingId(category)}>
          {t(CATEGORY_KEYS[category])}
        </Typography>
        <Chip size="small" color="warning" label={t('maintenance.reconciliation.findings.count', { count: findings.length })} />
      </Stack>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
        {t(CATEGORY_HINT_KEYS[category])}
      </Typography>
      <DataTable
        label={t(CATEGORY_KEYS[category])}
        columns={columns}
        rows={findings}
        rowKey={(finding) => `${finding.subject}\u0000${finding.related ?? ''}\u0000${finding.reason ?? ''}`}
      />
    </Box>
  );
}

export function ReconciliationPage(): ReactNode {
  const { t } = useTranslation();
  const { dateTime, number } = useFormatters();
  const client = useApiClient();
  const [requested, setRequested] = useState(false);

  const report = useQuery({
    queryKey: RECONCILIATION_QUERY_KEY,
    queryFn: ({ signal }) => client.get('/maintenance/reconciliation', { signal }),
    enabled: requested,
    staleTime: Infinity,
    retry: false,
  });

  const run = (): void => {
    if (requested) {
      void report.refetch();
    } else {
      setRequested(true);
    }
  };

  const data = report.data;

  const grouped = new Map<Category, Finding[]>();

  for (const finding of data?.findings ?? []) {
    const group = grouped.get(finding.category) ?? [];

    group.push(finding);
    grouped.set(finding.category, group);
  }

  const summaryRows = data === undefined ? [] : CATEGORIES.map((category) => ({ category, count: data.summary[category] }));

  const summaryColumns: DataTableColumn<(typeof summaryRows)[number]>[] = [
    {
      id: 'category',
      header: t('maintenance.reconciliation.summary.category'),
      render: (row) =>
        row.count > 0 ? (
          <Box component="a" href={`#${headingId(row.category)}`} sx={{ color: 'inherit' }}>
            {t(CATEGORY_KEYS[row.category])}
          </Box>
        ) : (
          t(CATEGORY_KEYS[row.category])
        ),
    },
    {
      id: 'count',
      header: t('maintenance.reconciliation.summary.count'),
      align: 'right',
      narrow: true,
      render: (row) => number(row.count),
    },
  ];

  const fetching = report.isFetching;

  return (
    <>
      <PageHeader
        title={t('maintenance.reconciliation.title')}
        description={t('maintenance.reconciliation.description')}
        actions={
          <Button
            variant="contained"
            startIcon={data === undefined ? <PlayArrowIcon /> : <RefreshIcon />}
            disabled={fetching}
            onClick={run}
          >
            {fetching
              ? t('maintenance.reconciliation.running')
              : data === undefined
                ? t('maintenance.reconciliation.run')
                : t('maintenance.reconciliation.rerun')}
          </Button>
        }
      />

      <Stack spacing={3}>
        {data === undefined && !fetching && !report.isError && (
          <Alert severity="info">{t('maintenance.reconciliation.notRun')}</Alert>
        )}

        {fetching && data === undefined && <LoadingState label={t('maintenance.reconciliation.running')} />}

        {report.isError && <ErrorState error={report.error} onRetry={run} />}

        {data !== undefined && (
          <>
            <Stack spacing={0.5}>
              <Typography variant="body2" color="text.secondary">
                {t('maintenance.reconciliation.checkedAt', { checkedAt: dateTime(data.checked_at) })}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {t('maintenance.reconciliation.checked', {
                  accounts: data.checked.accounts,
                  mailboxes: data.checked.mailboxes,
                  virtualRows: data.checked.virtual_rows,
                })}
              </Typography>
            </Stack>

            {data.findings.length === 0 && (
              <Alert severity="success">{t('maintenance.reconciliation.clean')}</Alert>
            )}

            <Box component="section" aria-label={t('maintenance.reconciliation.summary.title')}>
              <Typography variant="h6" component="h2" sx={{ mb: 1 }}>
                {t('maintenance.reconciliation.summary.title')}
              </Typography>
              <DataTable
                label={t('maintenance.reconciliation.summary.title')}
                columns={summaryColumns}
                rows={summaryRows}
                rowKey={(row) => row.category}
                loading={fetching}
              />
            </Box>

            {CATEGORIES.map((category) => {
              const findings = grouped.get(category);

              return findings === undefined ? null : (
                <FindingGroup key={category} category={category} findings={findings} />
              );
            })}
          </>
        )}
      </Stack>
    </>
  );
}
