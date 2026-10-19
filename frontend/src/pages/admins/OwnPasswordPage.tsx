import Alert from '@mui/material/Alert';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import { useSession } from '../../auth/SessionContext';
import { FormActions, fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { PageHeader } from '../../components/PageHeader';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useTranslation } from '../../i18n';

/**
 * A4 — changing one's own password, for both roles
 * (`profile.password.write`, `PUT /admins/me/password`).
 *
 * The server revokes every refresh token of the administrator, this browser's
 * included: after a password change the sessions opened with the old one end.
 * The page therefore signs out once the change is through and sends the user
 * to the sign-in form, rather than leaving a session that will fail at the
 * next refresh.
 *
 * The repeated new password is the one check beyond "required" that the client
 * makes on its own: a typo here locks the user out of their own account, and
 * the server has no way to notice it.
 */

type AdminOwnPasswordRequest = components['schemas']['AdminOwnPasswordRequest'];

export function OwnPasswordPage(): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const { signOut } = useSession();

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [repeat, setRepeat] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const required = useRequiredCheck();

  const change = useMutation({
    mutationFn: (body: AdminOwnPasswordRequest) => client.put('/admins/me/password', { body }),
    onSuccess: async () => {
      notify(t('admin.ownPassword.saved'));

      try {
        await signOut();
      } catch {
        // The local token is gone whatever the server answered (SessionContext).
      }

      void navigate('/login', { replace: true });
    },
  });

  const fieldError = useFieldErrors(change.error, clientErrors);

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const errors: Record<string, string> = {
      ...required({ 'body.current_password': current, 'body.new_password': next, repeat }),
    };

    if (errors['repeat'] === undefined && errors['body.new_password'] === undefined && repeat !== next) {
      errors['repeat'] = t('admin.ownPassword.error.mismatch');
    }

    setClientErrors(errors);

    if (Object.keys(errors).length > 0) {
      return;
    }

    change.mutate({ current_password: current, new_password: next });
  };

  return (
    <>
      <PageHeader title={t('admin.ownPassword.title')} description={t('admin.ownPassword.description')} />
      <Paper variant="outlined" sx={{ p: 3, maxWidth: 560 }}>
        <form onSubmit={submit} noValidate>
          <Stack spacing={2}>
            <TextField
              label={t('admin.ownPassword.field.current')}
              required
              type="password"
              value={current}
              onChange={(event) => setCurrent(event.target.value)}
              disabled={change.isPending}
              autoComplete="current-password"
              {...fieldProps(fieldError, 'body.current_password')}
            />
            <TextField
              label={t('admin.ownPassword.field.new')}
              required
              type="password"
              value={next}
              onChange={(event) => setNext(event.target.value)}
              disabled={change.isPending}
              autoComplete="new-password"
              {...fieldProps(fieldError, 'body.new_password', t('admin.form.hint.password'))}
            />
            <TextField
              label={t('admin.ownPassword.field.repeat')}
              required
              type="password"
              value={repeat}
              onChange={(event) => setRepeat(event.target.value)}
              disabled={change.isPending}
              autoComplete="new-password"
              {...fieldProps(fieldError, 'repeat')}
            />
            <Alert severity="info">{t('admin.ownPassword.signOutNotice')}</Alert>
            <ProblemAlert error={change.error} />
            <FormActions
              pending={change.isPending}
              submitLabel={t('admin.ownPassword.submit')}
              onCancel={() => void navigate(-1)}
            />
          </Stack>
        </form>
      </Paper>
    </>
  );
}
