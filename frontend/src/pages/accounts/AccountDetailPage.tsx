import DeleteIcon from '@mui/icons-material/DeleteOutline';
import KeyIcon from '@mui/icons-material/Key';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict } from '../../api/etag';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { DefinitionItem, DefinitionList } from '../../components/DefinitionList';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useFormatters, useTranslation } from '../../i18n';
import { ForwardsCard } from '../addresses/ForwardsCard';
import { VacationCard } from '../addresses/VacationCard';

import { AccountAccessCard } from './AccountAccessCard';
import { AccountPasswordDialog } from './AccountPasswordDialog';
import { AccountQuotaCard } from './AccountQuotaCard';
import { useAccount, useAccountQuota } from './accountQueries';
import {
  NAMING_SCHEME_KEYS,
  PASSWORD_FORMAT_KEYS,
  accountListPath,
  blocksMailLogin,
  type Account,
} from './labels';

/**
 * A2 (and the account half of A3) — one account.
 *
 * What the database knows (address, user name, scheme, hash format), the
 * login switch and service flags, the quota live from Cyrus, and — from A3 —
 * the account's forward and vacation notice. Each section loads and fails on
 * its own: an unreachable mail store costs the quota and the vacation notice,
 * not the page.
 *
 * An account whose stored user name contradicts its domain is shown but
 * offers no change (U-22): the mailbox it belongs to cannot be derived.
 */
export function AccountDetailPage(): ReactNode {
  const { t } = useTranslation();
  const { can } = useSession();
  const { account: accountId = '' } = useParams();
  const account = useAccount(accountId);
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const listCrumb = { label: t('account.list.title'), to: accountListPath(account.data?.data.domain) };

  if (account.isPending) {
    return (
      <>
        <PageHeader title={accountId} breadcrumbs={[listCrumb, { label: accountId }]} />
        <LoadingState />
      </>
    );
  }

  if (account.isError) {
    return (
      <>
        <PageHeader title={accountId} breadcrumbs={[listCrumb, { label: accountId }]} />
        <ErrorState error={account.error} onRetry={() => void account.refetch()} />
      </>
    );
  }

  const { data, etag } = account.data;
  const name = data.address ?? data.id;
  const writable = data.resolvable;
  const reload = (): void => void account.refetch();

  return (
    <>
      <PageHeader
        title={name}
        breadcrumbs={[listCrumb, { label: name }]}
        actions={
          writable ? (
            <>
              {can('account.password.write') && (
                <Button startIcon={<KeyIcon />} onClick={() => setPasswordOpen(true)}>
                  {t('account.detail.action.password')}
                </Button>
              )}
              {can('account.delete') && (
                <Button color="error" startIcon={<DeleteIcon />} onClick={() => setDeleteOpen(true)}>
                  {t('account.detail.action.delete')}
                </Button>
              )}
            </>
          ) : undefined
        }
      />

      <Stack spacing={3}>
        {!data.resolvable && <Alert severity="warning">{t('account.detail.unresolvable')}</Alert>}

        <Grid container spacing={3}>
          <Grid size={{ xs: 12, md: 6 }}>
            <AccountSummaryCard account={data} />
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            {writable ? (
              <AccountQuotaCard accountId={data.id} writable={writable} />
            ) : null}
          </Grid>
          <Grid size={12}>
            <AccountAccessCard
              account={data}
              etag={etag}
              writable={writable && can('account.write')}
              onReload={reload}
            />
          </Grid>
          {writable && can('address.read') && (
            <>
              <Grid size={{ xs: 12, md: 6 }}>
                <ForwardsCard accountId={data.id} />
              </Grid>
              <Grid size={{ xs: 12, md: 6 }}>
                <VacationCard accountId={data.id} />
              </Grid>
            </>
          )}
        </Grid>
      </Stack>

      {writable && (
        <>
          <AccountPasswordDialog
            open={passwordOpen}
            accountId={data.id}
            address={name}
            onClose={() => setPasswordOpen(false)}
          />
          <AccountDeleteDialog
            open={deleteOpen}
            account={data}
            etag={etag}
            onReload={reload}
            onClose={() => setDeleteOpen(false)}
          />
        </>
      )}
    </>
  );
}

function AccountSummaryCard({ account }: { account: Account }): ReactNode {
  const { t } = useTranslation();

  const rows: readonly { readonly label: string; readonly value: ReactNode }[] = [
    { label: t('account.detail.field.address'), value: account.address ?? t('account.detail.noAddress') },
    {
      label: t('account.detail.field.username'),
      value: <Box component="span" sx={{ fontFamily: 'monospace' }}>{account.id}</Box>,
    },
    { label: t('account.detail.field.domain'), value: account.domain },
    { label: t('account.detail.field.namingScheme'), value: t(NAMING_SCHEME_KEYS[account.naming_scheme]) },
    {
      label: t('account.detail.field.passwordFormat'),
      value: (
        <Chip
          size="small"
          color={account.password_format_weak ? 'warning' : 'default'}
          label={t(PASSWORD_FORMAT_KEYS[account.password_format])}
        />
      ),
    },
    ...(account.legacy_prefix === ''
      ? []
      : [{ label: t('account.detail.field.legacyPrefix'), value: account.legacy_prefix }]),
  ];

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6" component="h2">
            {t('account.detail.summaryTitle')}
          </Typography>
          <DefinitionList>
            {rows.map((row) => (
              <DefinitionItem key={row.label} label={row.label}>
                {row.value}
              </DefinitionItem>
            ))}
          </DefinitionList>
          {!account.password_format_current && (
            <Alert severity={blocksMailLogin(account.password_format) ? 'error' : 'info'}>
              {blocksMailLogin(account.password_format)
                ? t('account.detail.passwordFormatBlocksLogin')
                : t('account.detail.passwordFormatLegacy')}
            </Alert>
          )}
        </Stack>
      </CardContent>
    </Card>
  );
}

/**
 * Deleting an account removes its mailbox and every message in it; there is
 * no undelete (U-83). The dialog therefore names the usage from the quota
 * resource, says that the domain's catchall loses the account as a
 * destination (U-128), and wants the address typed.
 */
function AccountDeleteDialog({
  open,
  account,
  etag,
  onReload,
  onClose,
}: {
  open: boolean;
  account: Account;
  etag: string | null;
  onReload: () => void;
  onClose: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const { kibibytes } = useFormatters();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const quota = useAccountQuota(account.id, open);
  const name = account.address ?? account.id;

  const remove = useMutation({
    mutationFn: () =>
      client.delete('/accounts/{account}', { pathParams: { account: account.id }, headers: ifMatch(etag) }),
    onSuccess: async () => {
      queryClient.removeQueries({ queryKey: ['accounts', 'detail', account.id] });
      queryClient.removeQueries({ queryKey: ['accounts', 'quota', account.id] });
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounts', 'list'] }),
        queryClient.invalidateQueries({ queryKey: ['addresses'] }),
        queryClient.invalidateQueries({ queryKey: ['domains'] }),
      ]);
      notify(t('account.delete.done', { address: name }));
      void navigate(accountListPath(account.domain));
    },
    onError: (error) => {
      if (isVersionConflict(error)) {
        onReload();
      }
    },
  });

  const usedKib = quota.data?.used_kib;
  const usage = usedKib === undefined || usedKib === null
    ? t('account.delete.usageUnknown')
    : t('account.delete.usage', { used: kibibytes(usedKib) });

  return (
    <ConfirmDialog
      open={open}
      title={t('account.delete.title')}
      message={
        <Stack spacing={1}>
          <span>{t('account.delete.message', { address: name })}</span>
          <span>{usage}</span>
          <span>{t('account.delete.catchall')}</span>
        </Stack>
      }
      destructive
      typedConfirmation={name}
      pending={remove.isPending}
      error={remove.error}
      onConfirm={() => remove.mutate()}
      onClose={() => {
        remove.reset();
        onClose();
      }}
    />
  );
}
