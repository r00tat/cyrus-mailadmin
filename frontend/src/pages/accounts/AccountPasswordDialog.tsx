import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useId, useState, type FormEvent, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { ProblemAlert } from '../../components/ProblemAlert';
import { fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { useTranslation } from '../../i18n';

import { accountKey } from './accountQueries';

/**
 * Sets the password of an account (`PUT /accounts/{account}/password`).
 *
 * The server hashes with bcrypt, which `crypt(3)` and therefore `pam_mysql`
 * can read (E-13); an account in a legacy format moves to bcrypt with this.
 * The password rules are the server's (`auth.password_*`). The input lives in
 * this dialog's state only and is cleared when the dialog closes (U-74).
 */
export function AccountPasswordDialog({
  open,
  accountId,
  address,
  onClose,
}: {
  open: boolean;
  accountId: string;
  address: string;
  onClose: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();
  const notify = useNotify();
  const titleId = useId();
  const required = useRequiredCheck();

  const [password, setPassword] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const save = useMutation({
    mutationFn: (value: string) =>
      client.put('/accounts/{account}/password', { pathParams: { account: accountId }, body: { password: value } }),
    onSuccess: async () => {
      setPassword('');
      // The stored format changes (a legacy hash becomes bcrypt).
      await queryClient.invalidateQueries({ queryKey: accountKey(accountId) });
      await queryClient.invalidateQueries({ queryKey: ['accounts', 'list'] });
      notify(t('account.password.saved'));
      onClose();
    },
  });

  const fieldError = useFieldErrors(save.error, clientErrors);

  const close = (): void => {
    if (save.isPending) {
      return;
    }

    setPassword('');
    setClientErrors({});
    save.reset();
    onClose();
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({ 'body.password': password });

    setClientErrors(missing);

    if (Object.keys(missing).length === 0) {
      save.mutate(password);
    }
  };

  return (
    <Dialog open={open} onClose={close} aria-labelledby={titleId} maxWidth="sm" fullWidth>
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{t('account.password.title')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2}>
            <Typography variant="body2">{t('account.password.description', { address })}</Typography>
            <TextField
              label={t('account.password.field')}
              type="password"
              required
              autoFocus
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="new-password"
              disabled={save.isPending}
              {...fieldProps(fieldError, 'body.password')}
            />
            <ProblemAlert error={save.error} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={close} disabled={save.isPending}>
            {t('action.cancel')}
          </Button>
          <Button type="submit" variant="contained" disabled={save.isPending}>
            {save.isPending ? t('action.saving') : t('account.password.submit')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
