import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import type { AdminRole } from '../../auth/SessionContext';
import { FormActions, fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useTranslation } from '../../i18n';

import { DomainAssignmentField, ROLES, ROLE_KEYS, adminPath, useErrorAtOrBelow } from './adminShared';

/**
 * A4 — a new administrator, in a dialog on the list.
 *
 * A dialog rather than a page because the URL space under `/admins/` belongs
 * to the administrators: a legacy name may be anything without a slash
 * (`AdminUsername`), so `/admins/new` could name one.
 *
 * The password is set by the superuser and passed on out of band; it is not
 * kept anywhere once the dialog closes (U-74). Role and domains have to agree
 * — a domain master needs a domain, a superuser gets none — and the server
 * says so if they do not (`admin.role_scope_mismatch`).
 */

type AdminCreateRequest = components['schemas']['AdminCreateRequest'];

export function AdminCreateDialog({ open, onClose }: { open: boolean; onClose: () => void }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const titleId = useId();

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState<AdminRole>('domainmaster');
  const [domains, setDomains] = useState<string[]>([]);
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const required = useRequiredCheck();

  const create = useMutation({
    mutationFn: (body: AdminCreateRequest) => client.post('/admins', { body }),
    onSuccess: async (admin) => {
      reset();
      onClose();
      notify(t('admin.create.saved', { username: admin.username }));
      await queryClient.invalidateQueries({ queryKey: ['admins'] });
      void navigate(adminPath(admin.username));
    },
  });

  const fieldError = useFieldErrors(create.error, clientErrors);
  const domainsError = useErrorAtOrBelow(create.error, 'body.domains');

  function reset(): void {
    setUsername('');
    setPassword('');
    setRole('domainmaster');
    setDomains([]);
    setClientErrors({});
    create.reset();
  }

  const close = (): void => {
    if (create.isPending) {
      return;
    }

    reset();
    onClose();
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({ 'body.username': username, 'body.password': password });

    setClientErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    create.mutate({
      username: username.trim(),
      password,
      role,
      ...(role === 'domainmaster' ? { domains } : {}),
    });
  };

  return (
    <Dialog open={open} onClose={close} aria-labelledby={titleId} maxWidth="sm" fullWidth>
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{t('admin.create.title')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField
              label={t('admin.field.username')}
              required
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              disabled={create.isPending}
              autoComplete="off"
              slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
              {...fieldProps(fieldError, 'body.username', t('admin.create.hint.username'))}
            />
            <TextField
              label={t('admin.field.password')}
              required
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              disabled={create.isPending}
              autoComplete="new-password"
              {...fieldProps(fieldError, 'body.password', t('admin.form.hint.password'))}
            />
            <TextField
              select
              label={t('admin.field.role')}
              value={role}
              onChange={(event) => setRole(event.target.value as AdminRole)}
              disabled={create.isPending}
              {...fieldProps(fieldError, 'body.role')}
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
                onChange={setDomains}
                disabled={create.isPending}
                error={domainsError !== undefined}
                helperText={domainsError ?? t('admin.form.hint.domains')}
              />
            )}
            <ProblemAlert error={create.error} />
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <FormActions pending={create.isPending} submitLabel={t('admin.create.submit')} onCancel={close} />
        </DialogActions>
      </form>
    </Dialog>
  );
}
