import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict, type Versioned } from '../../api/etag';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { useTranslation } from '../../i18n';

import { EntryRows } from './EntryRows';
import { DestinationsField, destinationsError } from './fields';
import { formatDestinations, parseDestinations, type Forwards } from './labels';

/**
 * A3 — the forward of one account, shown on the account page.
 *
 * One row in `virtual` keyed by the account's stored user name, never a
 * Sieve `redirect` (U-107). `keep_copy` keeps a copy in the mailbox; without
 * it the vacation notice never answers, because the mail never arrives. In the
 * `fqun` scheme the copy is unavoidable (`copy_required`), so the switch is
 * fixed there rather than offered and refused.
 */
export function ForwardsCard({ accountId }: { accountId: string }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const { can } = useSession();
  const required = useRequiredCheck();

  const queryKey = ['addresses', 'forwards', accountId];
  const forwards = useQuery({
    queryKey,
    queryFn: ({ signal }) =>
      client.getVersioned('/accounts/{account}/forwards', { pathParams: { account: accountId }, signal }),
  });

  const current = forwards.data?.data;
  const etag = forwards.data?.etag ?? null;

  const [destinations, setDestinations] = useState('');
  const [keepCopy, setKeepCopy] = useState(true);
  const [enabled, setEnabled] = useState(true);
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const [confirmRemove, setConfirmRemove] = useState(false);

  useEffect(() => {
    if (current !== undefined) {
      setDestinations(formatDestinations(current.destinations));
      setKeepCopy(current.configured ? current.keep_copy : true);
      setEnabled(current.configured ? current.enabled : true);
    }
  }, [current]);

  const reload = (): void => void forwards.refetch();

  const save = useMutation({
    mutationFn: (body: { destinations: string[]; keep_copy: boolean; enabled: boolean }): Promise<Versioned<Forwards>> =>
      client.requestVersioned('put', '/accounts/{account}/forwards', {
        pathParams: { account: accountId },
        body,
        headers: ifMatch(etag),
      }),
    onSuccess: async (result) => {
      queryClient.setQueryData(queryKey, result);
      await queryClient.invalidateQueries({ queryKey: ['addresses', 'list'] });
      notify(t('address.forwards.saved'));
    },
  });

  const remove = useMutation({
    mutationFn: () =>
      client.delete('/accounts/{account}/forwards', { pathParams: { account: accountId }, headers: ifMatch(etag) }),
    onSuccess: async () => {
      setConfirmRemove(false);
      await queryClient.invalidateQueries({ queryKey });
      await queryClient.invalidateQueries({ queryKey: ['addresses', 'list'] });
      notify(t('address.forwards.removed'));
    },
    onError: (error) => {
      if (isVersionConflict(error)) {
        reload();
      }
    },
  });

  const fieldError = useFieldErrors(save.error, clientErrors);

  // Where the scheme requires the copy, the switch shows it ticked and the
  // request carries it — whatever the stored row says. A legacy row without the
  // copy would otherwise be sent back as `keep_copy: false` and refused.
  const effectiveKeepCopy = current?.copy_required === true || keepCopy;

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const list = parseDestinations(destinations);
    const missing = required({ 'body.destinations': list.length === 0 ? '' : 'given' });

    setClientErrors(missing);

    if (Object.keys(missing).length === 0) {
      save.mutate({ destinations: list, keep_copy: effectiveKeepCopy, enabled });
    }
  };

  const canWrite = can('address.write');

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6" component="h2">
            {t('address.forwards.title')}
          </Typography>

          {forwards.isPending && <LinearProgress aria-label={t('address.forwards.loading')} />}
          {forwards.isError && <ProblemAlert error={forwards.error} />}

          {current !== undefined && !current.configured && (
            <Typography color="text.secondary">{t('address.forwards.none')}</Typography>
          )}

          {current !== undefined && !current.editable && (
            <>
              <Alert severity="warning">{t('address.forwards.notEditable')}</Alert>
              <EntryRows entries={current.entries} />
            </>
          )}

          {current !== undefined && current.editable && canWrite && (
            <form onSubmit={submit} noValidate>
              <Stack spacing={2}>
                <DestinationsField
                  value={destinations}
                  onChange={setDestinations}
                  error={destinationsError(fieldError)}
                  hint={t('address.forwards.destinationsHint')}
                  disabled={save.isPending}
                />
                <FormControlLabel
                  control={
                    <Checkbox
                      checked={effectiveKeepCopy}
                      disabled={current.copy_required || save.isPending}
                      onChange={(event) => setKeepCopy(event.target.checked)}
                    />
                  }
                  label={t('address.forwards.keepCopy')}
                />
                {current.copy_required ? (
                  <Typography variant="body2" color="text.secondary">
                    {t('address.forwards.copyRequired')}
                  </Typography>
                ) : (
                  !keepCopy && <Alert severity="info">{t('address.forwards.noCopyHint')}</Alert>
                )}
                <FormControlLabel
                  control={
                    <Switch
                      checked={enabled}
                      disabled={save.isPending}
                      onChange={(event) => setEnabled(event.target.checked)}
                    />
                  }
                  label={t('address.forwards.enabled')}
                />
                <ProblemAlert error={save.error} onReload={reload} />
                <Stack direction="row" spacing={1} justifyContent="flex-end">
                  {current.configured && can('address.delete') && (
                    <Button color="error" disabled={save.isPending} onClick={() => setConfirmRemove(true)}>
                      {t('address.forwards.action.remove')}
                    </Button>
                  )}
                  <Button type="submit" variant="contained" disabled={save.isPending}>
                    {save.isPending ? t('action.saving') : t('address.forwards.action.save')}
                  </Button>
                </Stack>
              </Stack>
            </form>
          )}

          {current !== undefined && current.configured && current.editable && !canWrite && (
            <Stack spacing={0.5}>
              {current.destinations.map((destination) => (
                <Typography key={destination}>{destination}</Typography>
              ))}
              <Typography variant="body2" color="text.secondary">
                {current.keep_copy ? t('address.forwards.withCopy') : t('address.forwards.withoutCopy')}
              </Typography>
            </Stack>
          )}
        </Stack>
      </CardContent>

      <ConfirmDialog
        open={confirmRemove}
        title={t('address.forwards.confirmRemove.title')}
        message={t('address.forwards.confirmRemove.message')}
        destructive
        confirmLabel={t('address.forwards.action.remove')}
        pending={remove.isPending}
        error={remove.error}
        onConfirm={() => remove.mutate()}
        onClose={() => {
          setConfirmRemove(false);
          remove.reset();
        }}
      />
    </Card>
  );
}
