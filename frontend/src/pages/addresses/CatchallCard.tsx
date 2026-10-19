import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useId, useState, type FormEvent, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict, type Versioned } from '../../api/etag';
import type { components } from '../../api/schema';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { useTranslation } from '../../i18n';

import { EntryRows } from './EntryRows';
import { DestinationsField, SenderField, destinationsError, senderChoiceOf, senderValueOf, type SenderChoice } from './fields';
import { formatDestinations, parseDestinations, type Catchall } from './labels';

type CatchallRequest = components['schemas']['CatchallRequest'];

/**
 * A3 — the catchall of a domain (`@<domain>`).
 *
 * The card explains the rule the contract states (U-108): a concrete address
 * wins while it has an enabled row; only an address without one — or whose
 * rows are all disabled — falls through to the catchall. Nobody may send as
 * the catchall unless a sending account is chosen (U-106).
 */
export function CatchallCard({ domain }: { domain: string }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const { can } = useSession();

  const queryKey = ['addresses', 'catchall', domain];
  const catchall = useQuery({
    queryKey,
    queryFn: ({ signal }) => client.getVersioned('/domains/{domain}/catchall', { pathParams: { domain }, signal }),
  });

  const [editing, setEditing] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);

  const current = catchall.data?.data;
  const etag = catchall.data?.etag ?? null;
  const reload = (): void => void catchall.refetch();

  const remove = useMutation({
    mutationFn: () => client.delete('/domains/{domain}/catchall', { pathParams: { domain }, headers: ifMatch(etag) }),
    onSuccess: async () => {
      setConfirmRemove(false);
      await queryClient.invalidateQueries({ queryKey: ['addresses'] });
      notify(t('address.catchall.removed'));
    },
    onError: (error) => {
      if (isVersionConflict(error)) {
        reload();
      }
    },
  });

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={1.5}>
          <Stack direction="row" justifyContent="space-between" alignItems="center" flexWrap="wrap" gap={1}>
            <Typography variant="h6" component="h2">
              {t('address.catchall.title')}
            </Typography>
            {current !== undefined && current.editable && (
              <Stack direction="row" spacing={1}>
                {can('address.write') && (
                  <Button size="small" onClick={() => setEditing(true)}>
                    {current.configured ? t('address.catchall.action.edit') : t('address.catchall.action.create')}
                  </Button>
                )}
                {current.configured && can('address.delete') && (
                  <Button size="small" color="error" onClick={() => setConfirmRemove(true)}>
                    {t('address.catchall.action.remove')}
                  </Button>
                )}
              </Stack>
            )}
          </Stack>

          <Typography variant="body2" color="text.secondary">
            {t('address.catchall.explanation', { domain })}
          </Typography>

          {catchall.isPending && <LinearProgress aria-label={t('address.catchall.loading')} />}
          {catchall.isError && <ProblemAlert error={catchall.error} />}

          {current !== undefined && !current.configured && (
            <Typography>{t('address.catchall.none')}</Typography>
          )}

          {current !== undefined && current.configured && (
            <Stack spacing={0.5}>
              <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                <Typography>{t('address.catchall.destinations', { destinations: current.destinations.join(', ') })}</Typography>
                {!current.enabled && <Chip size="small" label={t('address.list.status.disabled')} />}
              </Stack>
              <Typography variant="body2" color="text.secondary">
                {current.sender_account === null
                  ? t('address.catchall.senderNone')
                  : t('address.catchall.sender', { account: current.sender_account })}
              </Typography>
            </Stack>
          )}

          {current !== undefined && !current.editable && (
            <>
              <Alert severity="warning">{t('address.catchall.notEditable')}</Alert>
              <EntryRows entries={current.entries} />
            </>
          )}
        </Stack>
      </CardContent>

      {current !== undefined && editing && (
        <CatchallDialog
          domain={domain}
          current={current}
          etag={etag}
          onReload={reload}
          onClose={() => setEditing(false)}
        />
      )}

      <ConfirmDialog
        open={confirmRemove}
        title={t('address.catchall.confirmRemove.title')}
        message={t('address.catchall.confirmRemove.message', { domain })}
        destructive
        confirmLabel={t('address.catchall.action.remove')}
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

function CatchallDialog({
  domain,
  current,
  etag,
  onReload,
  onClose,
}: {
  domain: string;
  current: Catchall;
  etag: string | null;
  onReload: () => void;
  onClose: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const titleId = useId();
  const required = useRequiredCheck();

  const [destinations, setDestinations] = useState(formatDestinations(current.destinations));
  const [enabled, setEnabled] = useState(current.configured ? current.enabled : true);
  const [sender, setSender] = useState<SenderChoice>(senderChoiceOf(current.sender_account));
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const save = useMutation({
    mutationFn: (body: CatchallRequest): Promise<Versioned<Catchall>> =>
      client.requestVersioned('put', '/domains/{domain}/catchall', {
        pathParams: { domain },
        body,
        headers: ifMatch(etag),
      }),
    onSuccess: async (result) => {
      queryClient.setQueryData(['addresses', 'catchall', domain], result);
      await queryClient.invalidateQueries({ queryKey: ['addresses', 'list'] });
      notify(t('address.catchall.saved'));
      onClose();
    },
  });

  const fieldError = useFieldErrors(save.error, clientErrors);

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const list = parseDestinations(destinations);
    const missing = required({ 'body.destinations': list.length === 0 ? '' : 'given' });

    setClientErrors(missing);

    if (Object.keys(missing).length === 0) {
      save.mutate({ destinations: list, enabled, sender_account: senderValueOf(sender) ?? null });
    }
  };

  return (
    <Dialog open onClose={() => !save.isPending && onClose()} aria-labelledby={titleId} maxWidth="sm" fullWidth>
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{t('address.catchall.dialogTitle', { domain })}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <DestinationsField
              value={destinations}
              onChange={setDestinations}
              error={destinationsError(fieldError)}
              hint={t('address.catchall.destinationsHint')}
              disabled={save.isPending}
            />
            <SenderField
              value={sender}
              onChange={setSender}
              destinations={destinations}
              error={fieldError('body.sender_account')}
              disabled={save.isPending}
            />
            <FormControlLabel
              control={<Switch checked={enabled} onChange={(event) => setEnabled(event.target.checked)} />}
              label={t('address.catchall.enabled')}
            />
            <ProblemAlert error={save.error} onReload={onReload} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={save.isPending}>
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
