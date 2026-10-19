import DeleteIcon from '@mui/icons-material/Delete';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict } from '../../api/etag';
import type { components } from '../../api/schema';
import { useSession, type AdminRole } from '../../auth/SessionContext';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { FormActions, fieldProps, useFieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ProblemAlert } from '../../components/ProblemAlert';
import { ErrorState, LoadingState } from '../../components/StateViews';
import { useTranslation } from '../../i18n';

import {
  DomainAssignmentField,
  DomainScope,
  PasswordFormat,
  ROLES,
  ROLE_KEYS,
  useErrorAtOrBelow,
  type Admin,
} from './adminShared';

/**
 * A4 — one administrator: what the data says about them, the change form and
 * the delete. Superuser-only like the list.
 *
 * The change form sends only what changed. Role and domains travel together
 * when either changes, because `domainadmin` is rewritten from both: a
 * domain master's assignment that still names a domain which no longer exists
 * is then refused (`admin.unknown_domain`) until that entry is removed — the
 * form marks it so the user can.
 *
 * `If-Match` is honoured but optional on this resource; the views send it
 * anyway, so that two superusers do not overwrite each other.
 */

type AdminUpdateRequest = components['schemas']['AdminUpdateRequest'];

function detailKey(username: string): readonly unknown[] {
  return ['admins', 'detail', username];
}

export function AdminDetailPage(): ReactNode {
  const { t } = useTranslation();
  const { username = '' } = useParams();
  const client = useApiClient();
  const [reloaded, setReloaded] = useState(false);

  const detail = useQuery({
    queryKey: detailKey(username),
    queryFn: ({ signal }) => client.getVersioned('/admins/{username}', { pathParams: { username }, signal }),
  });

  const breadcrumbs = [{ label: t('admin.list.title'), to: '/admins' }, { label: username }];

  if (detail.isPending) {
    return (
      <>
        <PageHeader title={username} breadcrumbs={breadcrumbs} />
        <LoadingState />
      </>
    );
  }

  if (detail.isError) {
    return (
      <>
        <PageHeader title={username} breadcrumbs={breadcrumbs} />
        <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
      </>
    );
  }

  const reload = (): void => {
    setReloaded(true);
    void detail.refetch();
  };

  return (
    <AdminDetail
      // Rebuilt from the server's state whenever its version changes.
      key={detail.data.etag ?? 'unversioned'}
      admin={detail.data.data}
      etag={detail.data.etag}
      breadcrumbs={breadcrumbs}
      reloaded={reloaded}
      onDismissReloaded={() => setReloaded(false)}
      onReload={reload}
    />
  );
}

function sameList(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((entry, index) => entry === b[index]);
}

function AdminDetail({
  admin,
  etag,
  breadcrumbs,
  reloaded,
  onDismissReloaded,
  onReload,
}: {
  admin: Admin;
  etag: string | null;
  breadcrumbs: readonly { label: string; to?: string }[];
  reloaded: boolean;
  onDismissReloaded: () => void;
  onReload: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const { session, can } = useSession();

  const [role, setRole] = useState<AdminRole>(admin.role);
  const [domains, setDomains] = useState<string[]>(admin.domains);
  const [password, setPassword] = useState('');
  const [unchanged, setUnchanged] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const isSelf = session?.username === admin.username;
  const pathParams = { username: admin.username };

  const update = useMutation({
    mutationFn: (body: AdminUpdateRequest) =>
      client.patch('/admins/{username}', { pathParams, body, headers: ifMatch(etag) }),
    onSuccess: async () => {
      setPassword('');
      notify(t('admin.detail.saved', { username: admin.username }));
      await queryClient.invalidateQueries({ queryKey: ['admins'] });
    },
  });

  const remove = useMutation({
    mutationFn: () => client.delete('/admins/{username}', { pathParams, headers: ifMatch(etag) }),
    onSuccess: async () => {
      setDeleting(false);
      notify(t('admin.detail.deleted', { username: admin.username }));
      queryClient.removeQueries({ queryKey: detailKey(admin.username) });
      await queryClient.invalidateQueries({ queryKey: ['admins', 'list'] });
      void navigate('/admins', { replace: true });
    },
    onError: (error) => {
      if (isVersionConflict(error)) {
        setDeleting(false);
        onReload();
      }
    },
  });

  const fieldError = useFieldErrors(update.error);
  const domainsError = useErrorAtOrBelow(update.error, 'body.domains');

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const assignmentChanged = role !== admin.role || (role === 'domainmaster' && !sameList(domains, admin.domains));

    const body: AdminUpdateRequest = {
      ...(assignmentChanged ? { role, domains: role === 'domainmaster' ? domains : [] } : {}),
      ...(password === '' ? {} : { password }),
    };

    if (Object.keys(body).length === 0) {
      setUnchanged(true);

      return;
    }

    setUnchanged(false);
    update.mutate(body);
  };

  const canWrite = can('admin.write');

  return (
    <>
      <PageHeader
        title={admin.username}
        breadcrumbs={breadcrumbs}
        actions={
          can('admin.delete') ? (
            <Tooltip title={isSelf ? t('admin.detail.selfDeletion') : ''}>
              {/* A disabled button fires no events; the span carries the tooltip. */}
              <span>
                <Button color="error" startIcon={<DeleteIcon />} disabled={isSelf} onClick={() => setDeleting(true)}>
                  {t('admin.detail.action.delete')}
                </Button>
              </span>
            </Tooltip>
          ) : undefined
        }
      />

      <Stack spacing={3}>
        {reloaded && (
          <Alert severity="info" onClose={onDismissReloaded} closeText={t('action.close')}>
            {t('admin.detail.reloaded')}
          </Alert>
        )}

        <Paper variant="outlined" sx={{ p: 3 }}>
          <Stack spacing={2}>
            <Typography variant="h6" component="h2">
              {t('admin.detail.section.state')}
            </Typography>
            <Property label={t('admin.field.role')}>{t(ROLE_KEYS[admin.role])}</Property>
            <Property label={t('admin.field.domains')}>
              <DomainScope admin={admin} />
            </Property>
            {admin.all_domains && admin.role === 'domainmaster' && (
              <Alert severity="info">{t('admin.detail.wildcardDomainmaster')}</Alert>
            )}
            {admin.orphaned_domains.length > 0 && (
              <Alert severity="warning">
                {t('admin.detail.orphaned', { count: admin.orphaned_domains.length, domains: admin.orphaned_domains.join(', ') })}
              </Alert>
            )}
            <Property label={t('admin.field.passwordFormat')}>
              <PasswordFormat admin={admin} />
            </Property>
            {admin.password_legacy && <Alert severity="info">{t('admin.passwordFormat.legacyHint')}</Alert>}
          </Stack>
        </Paper>

        {canWrite && (
          <Paper variant="outlined" sx={{ p: 3 }}>
            <form onSubmit={submit} noValidate>
              <Stack spacing={2}>
                <Typography variant="h6" component="h2">
                  {t('admin.detail.section.change')}
                </Typography>
                <TextField
                  select
                  label={t('admin.field.role')}
                  value={role}
                  onChange={(event) => {
                    setUnchanged(false);
                    setRole(event.target.value as AdminRole);
                  }}
                  disabled={update.isPending}
                  sx={{ maxWidth: 320 }}
                  {...fieldProps(fieldError, 'body.role', isSelf ? t('admin.detail.hint.ownRole') : undefined)}
                >
                  {ROLES.map((entry) => (
                    <MenuItem key={entry} value={entry}>
                      {t(ROLE_KEYS[entry])}
                    </MenuItem>
                  ))}
                </TextField>
                {role === 'domainmaster' && (
                  <DomainAssignmentField
                    value={domains}
                    onChange={(next) => {
                      setUnchanged(false);
                      setDomains(next);
                    }}
                    orphaned={admin.orphaned_domains}
                    disabled={update.isPending}
                    error={domainsError !== undefined}
                    helperText={
                      domainsError
                      ?? (admin.orphaned_domains.length > 0 ? t('admin.form.hint.domainsOrphaned') : t('admin.form.hint.domains'))
                    }
                  />
                )}
                <TextField
                  label={t('admin.detail.field.newPassword')}
                  type="password"
                  value={password}
                  onChange={(event) => {
                    setUnchanged(false);
                    setPassword(event.target.value);
                  }}
                  disabled={update.isPending}
                  autoComplete="new-password"
                  sx={{ maxWidth: 420 }}
                  {...fieldProps(fieldError, 'body.password', t('admin.detail.hint.newPassword'))}
                />
                {unchanged && <Typography color="text.secondary">{t('admin.detail.unchanged')}</Typography>}
                <ProblemAlert error={update.error} onReload={onReload} />
                <FormActions pending={update.isPending} />
              </Stack>
            </form>
          </Paper>
        )}
      </Stack>

      <ConfirmDialog
        open={deleting}
        destructive
        title={t('admin.delete.title', { username: admin.username })}
        message={t('admin.delete.message', { username: admin.username })}
        typedConfirmation={admin.username}
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

function Property({ label, children }: { label: string; children: ReactNode }): ReactNode {
  return (
    <Stack spacing={0.25}>
      <Typography variant="overline" color="text.secondary" component="div">
        {label}
      </Typography>
      <Typography component="div">{children}</Typography>
    </Stack>
  );
}
