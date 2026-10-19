import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict, type Versioned } from '../../api/etag';
import type { components } from '../../api/schema';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ProblemAlert } from '../../components/ProblemAlert';
import { fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { useTranslation } from '../../i18n';
import { hasCode } from '../accounts/labels';

import { formatDestinations, parseDestinations, type Vacation } from './labels';

type VacationRequest = components['schemas']['VacationRequest'];

/** The contract's default for `interval_days`. */
const DEFAULT_INTERVAL_DAYS = 7;

/**
 * A3 — the vacation notice of one account, shown on the account page.
 *
 * The notice is rendered whole into the account's Sieve script by the server
 * (never patched, M3); this editor only knows the model: text, subject,
 * interval and the addresses it answers for. There is no period — Sieve
 * `vacation` has none (A3 point 7).
 *
 * A **foreign** active script — one mailadmin did not write — is shown
 * read-only and replaced only after the user confirms the takeover
 * (`take_over`, U-102). An **unreadable** one of ours is shown and replaced by
 * the next save without asking: it is ours.
 */
export function VacationCard({ accountId }: { accountId: string }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const { can } = useSession();
  const required = useRequiredCheck();

  const queryKey = ['addresses', 'vacation', accountId];
  const vacation = useQuery({
    queryKey,
    queryFn: ({ signal }) =>
      client.getVersioned('/accounts/{account}/vacation', { pathParams: { account: accountId }, signal }),
  });

  const current = vacation.data?.data;
  const etag = vacation.data?.etag ?? null;

  const [message, setMessage] = useState('');
  const [subject, setSubject] = useState('');
  const [intervalDays, setIntervalDays] = useState(String(DEFAULT_INTERVAL_DAYS));
  const [addresses, setAddresses] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const [confirmTakeOver, setConfirmTakeOver] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);

  useEffect(() => {
    if (current !== undefined) {
      setMessage(current.message ?? '');
      setSubject(current.subject ?? '');
      setIntervalDays(String(current.interval_days ?? DEFAULT_INTERVAL_DAYS));
      setAddresses(formatDestinations(current.addresses ?? []));
    }
  }, [current]);

  const reload = (): void => void vacation.refetch();

  const save = useMutation({
    mutationFn: (body: VacationRequest): Promise<Versioned<Vacation>> =>
      client.requestVersioned('put', '/accounts/{account}/vacation', {
        pathParams: { account: accountId },
        body,
        headers: ifMatch(etag),
      }),
    onSuccess: (result) => {
      setConfirmTakeOver(false);
      queryClient.setQueryData(queryKey, result);
      notify(t('address.vacation.saved'));
    },
    onError: (error) => {
      // The script became foreign since it was loaded: ask, do not overwrite.
      if (hasCode(error, 'vacation.foreign_script') || isVersionConflict(error)) {
        reload();
      }
    },
  });

  const remove = useMutation({
    mutationFn: (takeOver: boolean) =>
      client.delete('/accounts/{account}/vacation', {
        pathParams: { account: accountId },
        headers: ifMatch(etag),
        query: takeOver ? { take_over: true } : {},
      }),
    onSuccess: async () => {
      setConfirmRemove(false);
      await queryClient.invalidateQueries({ queryKey });
      notify(t('address.vacation.removed'));
    },
    onError: (error) => {
      if (hasCode(error, 'vacation.foreign_script') || isVersionConflict(error)) {
        reload();
      }
    },
  });

  const fieldError = useFieldErrors(save.error, clientErrors);
  const foreign = current?.state === 'foreign';

  const body = (takeOver: boolean): VacationRequest => {
    const list = parseDestinations(addresses);
    // Not a number goes out as `null` and comes back as a field error.
    const days = intervalDays.trim() === '' ? DEFAULT_INTERVAL_DAYS : Number(intervalDays);

    return {
      message,
      subject: subject.trim() === '' ? null : subject,
      interval_days: days,
      ...(list.length === 0 ? {} : { addresses: list }),
      take_over: takeOver,
    };
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({ 'body.message': message });

    setClientErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    if (foreign) {
      save.reset();
      setConfirmTakeOver(true);
    } else {
      save.mutate(body(false));
    }
  };

  const canWrite = can('address.write');
  const configured = current?.state === 'active' || foreign || current?.state === 'unreadable';

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6" component="h2">
            {t('address.vacation.title')}
          </Typography>

          {vacation.isPending && <LinearProgress aria-label={t('address.vacation.loading')} />}
          {vacation.isError && <ProblemAlert error={vacation.error} />}

          {current !== undefined && (
            <>
              <Typography color="text.secondary">
                {current.state === 'active'
                  ? t('address.vacation.state.active')
                  : current.state === 'none'
                    ? t('address.vacation.state.none')
                    : current.state === 'foreign'
                      ? t('address.vacation.state.foreign', { name: current.script_name ?? '' })
                      : t('address.vacation.state.unreadable', { name: current.script_name ?? '' })}
              </Typography>

              {current.script !== null && (
                <Box
                  component="pre"
                  aria-label={t('address.vacation.scriptLabel')}
                  sx={{ m: 0, p: 1.5, bgcolor: 'action.hover', borderRadius: 1, overflowX: 'auto', fontSize: '0.8125rem' }}
                >
                  {current.script}
                </Box>
              )}

              {canWrite ? (
                <form onSubmit={submit} noValidate>
                  <Stack spacing={2}>
                    <TextField
                      label={t('address.vacation.field.subject')}
                      value={subject}
                      onChange={(event) => setSubject(event.target.value)}
                      disabled={save.isPending}
                      slotProps={{ htmlInput: { maxLength: 255 } }}
                      {...fieldProps(fieldError, 'body.subject')}
                    />
                    <TextField
                      label={t('address.vacation.field.message')}
                      required
                      multiline
                      minRows={4}
                      value={message}
                      onChange={(event) => setMessage(event.target.value)}
                      disabled={save.isPending}
                      slotProps={{ htmlInput: { maxLength: 4000 } }}
                      {...fieldProps(fieldError, 'body.message')}
                    />
                    <TextField
                      label={t('address.vacation.field.interval')}
                      type="number"
                      value={intervalDays}
                      onChange={(event) => setIntervalDays(event.target.value)}
                      disabled={save.isPending}
                      slotProps={{ htmlInput: { min: 1, max: 60 } }}
                      {...fieldProps(fieldError, 'body.interval_days', t('address.vacation.field.intervalHint'))}
                    />
                    <TextField
                      label={t('address.vacation.field.addresses')}
                      multiline
                      minRows={2}
                      value={addresses}
                      onChange={(event) => setAddresses(event.target.value)}
                      disabled={save.isPending}
                      {...fieldProps(fieldError, 'body.addresses', t('address.vacation.field.addressesHint'))}
                    />
                    {!confirmTakeOver && <ProblemAlert error={save.error} onReload={reload} />}
                    <Stack direction="row" spacing={1} justifyContent="flex-end">
                      {configured && can('address.delete') && (
                        <Button color="error" disabled={save.isPending} onClick={() => setConfirmRemove(true)}>
                          {t('address.vacation.action.remove')}
                        </Button>
                      )}
                      <Button type="submit" variant="contained" disabled={save.isPending}>
                        {save.isPending ? t('action.saving') : t('address.vacation.action.save')}
                      </Button>
                    </Stack>
                  </Stack>
                </form>
              ) : (
                current.state === 'active' && (
                  <Stack spacing={1}>
                    {current.subject !== null && <Typography fontWeight={500}>{current.subject}</Typography>}
                    <Typography sx={{ whiteSpace: 'pre-wrap' }}>{current.message}</Typography>
                  </Stack>
                )
              )}
            </>
          )}
        </Stack>
      </CardContent>

      <ConfirmDialog
        open={confirmTakeOver}
        title={t('address.vacation.takeOver.title')}
        message={t('address.vacation.takeOver.message', { name: current?.script_name ?? '' })}
        confirmLabel={t('address.vacation.takeOver.confirm')}
        destructive
        pending={save.isPending}
        error={save.error}
        onConfirm={() => save.mutate(body(true))}
        onClose={() => {
          setConfirmTakeOver(false);
          save.reset();
        }}
      />

      <ConfirmDialog
        open={confirmRemove}
        title={t('address.vacation.confirmRemove.title')}
        message={
          foreign
            ? t('address.vacation.confirmRemove.foreign', { name: current?.script_name ?? '' })
            : t('address.vacation.confirmRemove.message')
        }
        confirmLabel={t('address.vacation.action.remove')}
        destructive
        pending={remove.isPending}
        error={remove.error}
        onConfirm={() => remove.mutate(foreign)}
        onClose={() => {
          setConfirmRemove(false);
          remove.reset();
        }}
      />
    </Card>
  );
}
