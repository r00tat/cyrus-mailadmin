import DeleteIcon from '@mui/icons-material/DeleteOutline';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict, type Versioned } from '../../api/etag';
import type { components } from '../../api/schema';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ProblemAlert } from '../../components/ProblemAlert';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useTranslation } from '../../i18n';
import { hasCode } from '../accounts/labels';

import { EntryRows } from './EntryRows';
import { DestinationsField, SenderField, destinationsError, senderChoiceOf, senderValueOf, type SenderChoice } from './fields';
import { addressListPath, formatDestinations, parseDestinations, type Alias } from './labels';
import { useReservedConfirmation } from './useReservedConfirmation';

type AliasUpdateRequest = components['schemas']['AliasUpdateRequest'];

/**
 * A3 — one alias: its destinations, who may send as it, whether it is
 * enabled.
 *
 * Legacy data spreads an alias over several rows. When they cannot be written
 * back as one (`editable: false`, U-105, U-111) the rows are shown and the
 * form is not — they are removed one by one from the address list.
 *
 * `postmaster` and `abuse` (`requires_confirmation`) are asked about twice
 * before they are disabled or removed, and only then is `confirm_reserved`
 * sent (U-109).
 */
export function AliasDetailPage(): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const { can } = useSession();
  const { alias: address = '' } = useParams();

  const alias = useQuery({
    queryKey: ['addresses', 'alias', address],
    queryFn: ({ signal }) => client.getVersioned('/aliases/{alias}', { pathParams: { alias: address }, signal }),
  });

  const [deleting, setDeleting] = useState(false);
  const domain = alias.data?.data.domain;
  const listCrumb = { label: t('address.list.title'), to: addressListPath(domain) };

  if (alias.isPending) {
    return (
      <>
        <PageHeader title={address} breadcrumbs={[listCrumb, { label: address }]} />
        <LoadingState />
      </>
    );
  }

  if (alias.isError) {
    return (
      <>
        <PageHeader title={address} breadcrumbs={[listCrumb, { label: address }]} />
        <ErrorState error={alias.error} onRetry={() => void alias.refetch()} />
      </>
    );
  }

  const { data, etag } = alias.data;
  const reload = (): void => void alias.refetch();

  return (
    <>
      <PageHeader
        title={data.address}
        breadcrumbs={[listCrumb, { label: data.address }]}
        description={t('address.alias.description')}
        actions={
          can('address.delete') ? (
            <Button color="error" startIcon={<DeleteIcon />} onClick={() => setDeleting(true)}>
              {t('address.alias.action.delete')}
            </Button>
          ) : undefined
        }
      />

      <Stack spacing={3}>
        <Stack direction="row" spacing={1}>
          <Chip
            size="small"
            color={data.enabled ? 'success' : 'default'}
            variant={data.enabled ? 'outlined' : 'filled'}
            label={data.enabled ? t('address.list.status.enabled') : t('address.list.status.disabled')}
          />
          {data.reserved && <Chip size="small" variant="outlined" label={t('address.list.reserved')} />}
        </Stack>

        {data.editable ? (
          can('address.write') ? (
            <AliasForm alias={data} etag={etag} onReload={reload} />
          ) : (
            <AliasSummary alias={data} />
          )
        ) : (
          <Card variant="outlined">
            <CardContent>
              <Stack spacing={2}>
                <Alert severity="warning">{t('address.alias.notEditable')}</Alert>
                <EntryRows entries={data.entries} />
              </Stack>
            </CardContent>
          </Card>
        )}
      </Stack>

      {deleting && <AliasDeleteDialog alias={data} etag={etag} onReload={reload} onClose={() => setDeleting(false)} />}
    </>
  );
}

function AliasSummary({ alias }: { alias: Alias }): ReactNode {
  const { t } = useTranslation();

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={1}>
          <Typography>{t('address.alias.destinations', { destinations: alias.destinations.join(', ') })}</Typography>
          <Typography color="text.secondary">
            {alias.sender_account === null
              ? t('address.alias.senderNone')
              : t('address.alias.sender', { account: alias.sender_account })}
          </Typography>
        </Stack>
      </CardContent>
    </Card>
  );
}

function AliasForm({ alias, etag, onReload }: { alias: Alias; etag: string | null; onReload: () => void }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const required = useRequiredCheck();
  const reserved = useReservedConfirmation(alias.requires_confirmation);

  const [destinations, setDestinations] = useState(formatDestinations(alias.destinations));
  const [sender, setSender] = useState<SenderChoice>(senderChoiceOf(alias.sender_account));
  const [enabled, setEnabled] = useState(alias.enabled);
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const [confirmDisable, setConfirmDisable] = useState(false);

  useEffect(() => {
    setDestinations(formatDestinations(alias.destinations));
    setSender(senderChoiceOf(alias.sender_account));
    setEnabled(alias.enabled);
  }, [alias]);

  const save = useMutation({
    mutationFn: (body: AliasUpdateRequest): Promise<Versioned<Alias>> =>
      client.requestVersioned('patch', '/aliases/{alias}', {
        pathParams: { alias: alias.address },
        body,
        headers: ifMatch(etag),
      }),
    onSuccess: async (result) => {
      setConfirmDisable(false);
      reserved.reset();
      queryClient.setQueryData(['addresses', 'alias', alias.address], result);
      await queryClient.invalidateQueries({ queryKey: ['addresses', 'list'] });
      notify(t('address.alias.saved'));
    },
    onError: (error) => {
      reserved.onError(error);

      if (hasCode(error, 'address.reserved')) {
        setConfirmDisable(true);
      }
    },
  });

  const fieldError = useFieldErrors(save.error, clientErrors);

  const body = (confirmReserved: boolean): AliasUpdateRequest => ({
    destinations: parseDestinations(destinations),
    sender_account: senderValueOf(sender) ?? null,
    enabled,
    confirm_reserved: confirmReserved,
  });

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({ 'body.destinations': parseDestinations(destinations).length === 0 ? '' : 'given' });

    setClientErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    // Disabling postmaster or abuse is asked about before it is sent (U-109).
    if (alias.enabled && !enabled && alias.requires_confirmation) {
      save.reset();
      setConfirmDisable(true);

      return;
    }

    save.mutate(body(false));
  };

  return (
    <Card variant="outlined">
      <CardContent>
        <form onSubmit={submit} noValidate>
          <Stack spacing={2}>
            <DestinationsField
              value={destinations}
              onChange={setDestinations}
              error={destinationsError(fieldError)}
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
              control={<Switch checked={enabled} disabled={save.isPending} onChange={(event) => setEnabled(event.target.checked)} />}
              label={t('address.alias.field.enabled')}
            />
            {!enabled && <Typography variant="body2" color="text.secondary">{t('address.alias.disabledHint')}</Typography>}
            {!confirmDisable && <ProblemAlert error={save.error} onReload={onReload} />}
            <Stack direction="row" justifyContent="flex-end">
              <Button type="submit" variant="contained" disabled={save.isPending}>
                {save.isPending ? t('action.saving') : t('action.save')}
              </Button>
            </Stack>
          </Stack>
        </form>
      </CardContent>

      <ConfirmDialog
        open={confirmDisable}
        title={t('address.reserved.disableTitle')}
        message={<Alert severity="warning">{t('address.reserved.disableMessage', { address: alias.address })}</Alert>}
        confirmLabel={t('address.reserved.confirmDisable')}
        destructive
        pending={save.isPending}
        error={reserved.visibleError(save.error)}
        onConfirm={() => save.mutate(body(true))}
        onClose={() => {
          setConfirmDisable(false);
          save.reset();
        }}
      />
    </Card>
  );
}

function AliasDeleteDialog({
  alias,
  etag,
  onReload,
  onClose,
}: {
  alias: Alias;
  etag: string | null;
  onReload: () => void;
  onClose: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const reserved = useReservedConfirmation(alias.requires_confirmation);

  const remove = useMutation({
    mutationFn: (confirmReserved: boolean) =>
      client.delete('/aliases/{alias}', {
        pathParams: { alias: alias.address },
        headers: ifMatch(etag),
        query: confirmReserved ? { confirm_reserved: true } : {},
      }),
    onSuccess: async () => {
      queryClient.removeQueries({ queryKey: ['addresses', 'alias', alias.address] });
      await queryClient.invalidateQueries({ queryKey: ['addresses', 'list'] });
      notify(t('address.alias.deleted', { address: alias.address }));
      void navigate(addressListPath(alias.domain));
    },
    onError: (error) => {
      reserved.onError(error);

      if (isVersionConflict(error)) {
        onReload();
      }
    },
  });

  return (
    <ConfirmDialog
      open
      title={t('address.alias.deleteTitle')}
      message={
        <Stack spacing={1}>
          <span>{t('address.alias.deleteMessage', { address: alias.address })}</span>
          {reserved.asking && <Alert severity="warning">{t('address.reserved.message', { address: alias.address })}</Alert>}
        </Stack>
      }
      destructive
      {...(reserved.asking ? { confirmLabel: t('address.reserved.confirmRemove') } : {})}
      pending={remove.isPending}
      error={reserved.visibleError(remove.error)}
      onConfirm={() => remove.mutate(reserved.confirmed)}
      onClose={onClose}
    />
  );
}
