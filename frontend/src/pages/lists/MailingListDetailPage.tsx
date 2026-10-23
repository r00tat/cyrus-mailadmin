import DeleteIcon from '@mui/icons-material/Delete';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { hasPendingConfigurationRun } from '../../api/client';
import { ifMatch, isVersionConflict } from '../../api/etag';
import type { components } from '../../api/schema';
import { useSession } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { fieldProps, FormActions, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ProblemAlert } from '../../components/ProblemAlert';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useTranslation } from '../../i18n';

import { membersFieldError, parseMembers, sameMembers, type MailingList } from './listShared';

/**
 * A5 — one mailing list: its values, the change form and the delete.
 *
 * The address is the identifier (`/lists/{address}`) and read-only here; to
 * move a list to another address, it is deleted and created anew. Name, tag
 * and members are editable.
 *
 * The form sends only what changed (`PATCH`, absent means untouched). That
 * matters most for the members: a list whose membership is not touched is
 * written back byte for byte by the server, whatever separator the legacy row
 * used (U-56) — so the views send `members` only when the parsed lines differ
 * from what was loaded, never on every save.
 *
 * Entries that are not mail addresses (U-22) stay in the text area as they
 * are, and the warning above the form names them. Adding such an entry is
 * refused by the server; the refusal is shown on the member field.
 */

type MailingListUpdateRequest = components['schemas']['MailingListUpdateRequest'];

function detailKey(address: string): readonly unknown[] {
  return ['lists', 'detail', address];
}

export function MailingListDetailPage(): ReactNode {
  const { t } = useTranslation();
  const { address = '' } = useParams();
  const client = useApiClient();
  const [reloaded, setReloaded] = useState(false);

  const detail = useQuery({
    queryKey: detailKey(address),
    queryFn: ({ signal }) => client.getVersioned('/lists/{address}', { pathParams: { address }, signal }),
  });

  const breadcrumbs = [{ label: t('list.list.title'), to: '/lists' }, { label: address }];

  if (detail.isPending) {
    return (
      <>
        <PageHeader title={address} breadcrumbs={breadcrumbs} />
        <LoadingState />
      </>
    );
  }

  if (detail.isError) {
    return (
      <>
        <PageHeader title={address} breadcrumbs={breadcrumbs} />
        <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
      </>
    );
  }

  const reload = (): void => {
    setReloaded(true);
    void detail.refetch();
  };

  return (
    <MailingListDetail
      // Rebuilt from the server's state whenever its version changes.
      key={detail.data.etag ?? 'unversioned'}
      list={detail.data.data}
      etag={detail.data.etag}
      breadcrumbs={breadcrumbs}
      reloaded={reloaded}
      onDismissReloaded={() => setReloaded(false)}
      onReload={reload}
    />
  );
}

function MailingListDetail({
  list,
  etag,
  breadcrumbs,
  reloaded,
  onDismissReloaded,
  onReload,
}: {
  list: MailingList;
  etag: string | null;
  breadcrumbs: readonly { label: string; to?: string }[];
  reloaded: boolean;
  onDismissReloaded: () => void;
  onReload: () => void;
}): ReactNode {
  const translator = useTranslation();
  const { t } = translator;
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const { can } = useSession();

  const [name, setName] = useState(list.name);
  const [tag, setTag] = useState(list.tag);
  const [members, setMembers] = useState(list.members.join('\n'));
  // The member text as it was sent: a refused entry's position refers to it.
  const [sentMembers, setSentMembers] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const required = useRequiredCheck();
  const [unchanged, setUnchanged] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const pathParams = { address: list.address };

  const update = useMutation({
    mutationFn: (body: MailingListUpdateRequest) =>
      client.requestVersioned('patch', '/lists/{address}', { pathParams, body, headers: ifMatch(etag) }),
    onSuccess: async (exchange) => {
      const saved = exchange.data;

      notify(
        hasPendingConfigurationRun(exchange)
          ? t('list.detail.savedPendingRun', { name: saved.name })
          : t('list.detail.saved', { name: saved.name }),
      );
      await queryClient.invalidateQueries({ queryKey: ['lists'] });
    },
  });

  const remove = useMutation({
    mutationFn: () => client.requestVersioned('delete', '/lists/{address}', { pathParams, headers: ifMatch(etag) }),
    onSuccess: async (exchange) => {
      setDeleting(false);
      notify(
        hasPendingConfigurationRun(exchange)
          ? t('list.detail.deletedPendingRun', { name: list.name })
          : t('list.detail.deleted', { name: list.name }),
      );
      queryClient.removeQueries({ queryKey: detailKey(list.address) });
      await queryClient.invalidateQueries({ queryKey: ['lists', 'list'] });
      void navigate('/lists', { replace: true });
    },
    onError: (error) => {
      if (isVersionConflict(error)) {
        setDeleting(false);
        onReload();
      }
    },
  });

  const fieldError = useFieldErrors(update.error, clientErrors);
  const membersError = membersFieldError(update.error, sentMembers, translator);

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({ 'body.name': name });

    setClientErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    const parsedMembers = parseMembers(members);
    const body: MailingListUpdateRequest = {
      ...(name.trim() === list.name ? {} : { name: name.trim() }),
      ...(tag.trim() === list.tag ? {} : { tag: tag.trim() }),
      ...(sameMembers(parsedMembers, list.members) ? {} : { members: parsedMembers }),
    };

    if (Object.keys(body).length === 0) {
      setUnchanged(true);

      return;
    }

    setUnchanged(false);
    setSentMembers(members);
    update.mutate(body);
  };

  const touched = (): void => setUnchanged(false);
  const canWrite = can('list.write');

  return (
    <>
      <PageHeader
        title={list.name}
        breadcrumbs={breadcrumbs}
        actions={
          can('list.delete') ? (
            <Button color="error" startIcon={<DeleteIcon />} onClick={() => setDeleting(true)}>
              {t('list.detail.action.delete')}
            </Button>
          ) : undefined
        }
      />

      <Stack spacing={3}>
        {reloaded && (
          <Alert severity="info" onClose={onDismissReloaded} closeText={t('action.close')}>
            {t('list.detail.reloaded')}
          </Alert>
        )}
        {!list.address_usable && <Alert severity="warning">{t('list.detail.addressUnusable')}</Alert>}
        {list.unusable_members.length > 0 && (
          <Alert severity="warning">
            {t('list.detail.unusableMembers', {
              count: list.unusable_members.length,
              members: list.unusable_members.join(', '),
            })}
          </Alert>
        )}

        <Paper variant="outlined" sx={{ p: 3 }}>
          <form onSubmit={submit} noValidate>
            <Stack spacing={2}>
              <TextField
                label={t('list.field.name')}
                required
                value={name}
                onChange={(event) => {
                  touched();
                  setName(event.target.value);
                }}
                disabled={!canWrite || update.isPending}
                autoComplete="off"
                {...fieldProps(fieldError, 'body.name', t('list.form.hint.name'))}
              />
              <TextField
                label={t('list.field.address')}
                value={list.address}
                disabled
                helperText={t('list.detail.hint.address')}
              />
              <TextField
                label={t('list.field.tag')}
                value={tag}
                onChange={(event) => {
                  touched();
                  setTag(event.target.value);
                }}
                disabled={!canWrite || update.isPending}
                {...fieldProps(fieldError, 'body.tag', t('list.form.hint.tag'))}
              />
              <TextField
                label={t('list.field.members')}
                value={members}
                onChange={(event) => {
                  touched();
                  setMembers(event.target.value);
                }}
                disabled={!canWrite || update.isPending}
                multiline
                minRows={6}
                slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
                error={membersError !== undefined}
                helperText={membersError ?? t('list.form.hint.members')}
              />
              {canWrite && (
                <>
                  {unchanged && <Typography color="text.secondary">{t('list.detail.unchanged')}</Typography>}
                  <ProblemAlert error={update.error} onReload={onReload} />
                  <FormActions pending={update.isPending} />
                </>
              )}
            </Stack>
          </form>
        </Paper>
      </Stack>

      <ConfirmDialog
        open={deleting}
        destructive
        title={t('list.delete.title', { name: list.name })}
        message={t('list.delete.message', { name: list.name, address: list.address })}
        pending={remove.isPending}
        error={remove.error}
        onConfirm={() => remove.mutate()}
        onClose={() => {
          setDeleting(false);
          remove.reset();
        }}
      />
    </>
  );
}
