import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useId, useState, type FormEvent, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { useSession } from '../../auth/SessionContext';
import { ProblemAlert } from '../../components/ProblemAlert';
import { fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { useFormatters, useTranslation } from '../../i18n';

import { QuotaInput, quotaChoiceOf, quotaKibOf, type QuotaChoice } from './QuotaInput';
import { accountQuotaKey, useAccountQuota } from './accountQueries';
import type { AccountQuota } from './labels';

/**
 * Quota limit and usage of one account, read live from Cyrus.
 *
 * Its own section with its own request because it costs an IMAP round trip
 * and can fail on its own — a missing mailbox (`account.mailbox_missing`) or
 * an unreachable mail store must not take the account page down with it.
 * Sizes are KiB on the wire and shown through `kibibytes()` (U-94).
 */
export function AccountQuotaCard({ accountId, writable }: { accountId: string; writable: boolean }): ReactNode {
  const { t } = useTranslation();
  const { kibibytes, number } = useFormatters();
  const { can } = useSession();
  const quota = useAccountQuota(accountId);
  const [editing, setEditing] = useState(false);

  const data = quota.data;
  const share = data !== undefined && data.limit_kib !== null && data.limit_kib > 0 && data.used_kib !== null
    ? Math.min(100, (data.used_kib / data.limit_kib) * 100)
    : null;

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Stack direction="row" justifyContent="space-between" alignItems="center">
            <Typography variant="h6" component="h2">
              {t('account.quota.title')}
            </Typography>
            {writable && can('account.write') && data !== undefined && (
              <Button size="small" onClick={() => setEditing(true)}>
                {t('account.quota.action.edit')}
              </Button>
            )}
          </Stack>

          {quota.isPending && <LinearProgress aria-label={t('account.quota.loading')} />}

          {quota.isError && <ProblemAlert error={quota.error} />}

          {data !== undefined && (
            <>
              <Typography>
                {data.used_kib === null
                  ? t('account.quota.usageUnknown')
                  : data.limit_kib === null
                    ? t('account.quota.usageUnlimited', { used: kibibytes(data.used_kib) })
                    : t('account.quota.usage', { used: kibibytes(data.used_kib), limit: kibibytes(data.limit_kib) })}
              </Typography>
              {share !== null && (
                <Stack spacing={0.5}>
                  <LinearProgress
                    variant="determinate"
                    value={share}
                    color={share >= 90 ? 'warning' : 'primary'}
                    aria-label={t('account.quota.share', { percent: number(Math.round(share)) })}
                  />
                  <Typography variant="body2" color="text.secondary">
                    {t('account.quota.share', { percent: number(Math.round(share)) })}
                  </Typography>
                </Stack>
              )}
            </>
          )}
        </Stack>
      </CardContent>

      {data !== undefined && (
        <QuotaDialog
          open={editing}
          accountId={accountId}
          current={data}
          onClose={() => setEditing(false)}
        />
      )}
    </Card>
  );
}

function QuotaDialog({
  open,
  accountId,
  current,
  onClose,
}: {
  open: boolean;
  accountId: string;
  current: AccountQuota;
  onClose: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const titleId = useId();
  const required = useRequiredCheck();

  const [choice, setChoice] = useState<QuotaChoice>(() => quotaChoiceOf(current.limit_kib));
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const save = useMutation({
    mutationFn: (limitKib: number | null) =>
      client.put('/accounts/{account}/quota', { pathParams: { account: accountId }, body: { limit_kib: limitKib } }),
    onSuccess: (result) => {
      queryClient.setQueryData(accountQuotaKey(accountId), result);
      notify(t('account.quota.saved'));
      onClose();
    },
  });

  const fieldError = useFieldErrors(save.error, clientErrors);

  const close = (): void => {
    if (save.isPending) {
      return;
    }

    save.reset();
    setClientErrors({});
    setChoice(quotaChoiceOf(current.limit_kib));
    onClose();
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = choice.mode === 'limit' ? required({ 'body.limit_kib': choice.amount }) : {};

    setClientErrors(missing);

    if (Object.keys(missing).length === 0) {
      save.mutate(quotaKibOf(choice) ?? null);
    }
  };

  return (
    <Dialog open={open} onClose={close} aria-labelledby={titleId} maxWidth="xs" fullWidth>
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{t('account.quota.dialogTitle')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2}>
            <Typography variant="body2" color="text.secondary">
              {t('account.quota.dialogHint')}
            </Typography>
            <QuotaInput value={choice} onChange={setChoice} {...fieldProps(fieldError, 'body.limit_kib')} />
            <ProblemAlert error={save.error} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={close} disabled={save.isPending}>
            {t('action.cancel')}
          </Button>
          <Button type="submit" variant="contained" disabled={save.isPending}>
            {save.isPending ? t('action.saving') : t('action.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
