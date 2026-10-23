import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useApiClient } from '../../api/ApiContext';
import { hasPendingConfigurationRun } from '../../api/client';
import type { components } from '../../api/schema';
import { fieldProps, FormActions, useFieldErrors, useRequiredCheck, type FieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useTranslation } from '../../i18n';

import { listPath, membersFieldError, parseMembers } from './listShared';

/**
 * A5 — a new mailing list, in a dialog on the list page.
 *
 * A dialog rather than `/lists/new`: the address is the identifier, and the
 * list routes keep the level below `/lists/` for addresses alone. The name is
 * free display text.
 *
 * Every member must be a mail address — a security rule, not tidiness: in
 * alias delivery mode the column is rendered into an aliases file where
 * `|command` is a command (U-57). The server enforces it; a refused member
 * comes back as `body.members.<n>` and is shown on the member field, with the
 * line where the server names one (`membersFieldError`).
 */

type MailingListCreateRequest = components['schemas']['MailingListCreateRequest'];

export function MailingListCreateDialog({ open, onClose }: { open: boolean; onClose: () => void }): ReactNode {
  const translator = useTranslation();
  const { t } = translator;
  const client = useApiClient();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const notify = useNotify();
  const titleId = useId();

  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [tag, setTag] = useState('');
  const [members, setMembers] = useState('');
  // The member text as it was sent: a refused entry's position refers to it.
  const [sentMembers, setSentMembers] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const required = useRequiredCheck();

  const create = useMutation({
    mutationFn: (body: MailingListCreateRequest) => client.requestVersioned('post', '/lists', { body }),
    onSuccess: async (exchange) => {
      const list = exchange.data;

      reset();
      onClose();
      notify(
        hasPendingConfigurationRun(exchange)
          ? t('list.create.savedPendingRun', { name: list.name })
          : t('list.create.saved', { name: list.name }),
      );
      await queryClient.invalidateQueries({ queryKey: ['lists'] });
      void navigate(listPath(list.address));
    },
  });

  const fieldError = useFieldErrors(create.error, clientErrors);
  const membersError = membersFieldError(create.error, sentMembers, translator);

  function reset(): void {
    setName('');
    setAddress('');
    setTag('');
    setMembers('');
    setSentMembers('');
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

    const missing = required({ 'body.name': name, 'body.address': address });

    setClientErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    setSentMembers(members);
    create.mutate({
      name: name.trim(),
      address: address.trim(),
      tag: tag.trim(),
      members: parseMembers(members),
    });
  };

  return (
    <Dialog open={open} onClose={close} aria-labelledby={titleId} maxWidth="sm" fullWidth>
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{t('list.create.title')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField
              label={t('list.field.name')}
              required
              value={name}
              onChange={(event) => setName(event.target.value)}
              disabled={create.isPending}
              autoComplete="off"
              slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
              {...fieldProps(fieldError, 'body.name', t('list.form.hint.name'))}
            />
            <TextField
              label={t('list.field.address')}
              required
              value={address}
              onChange={(event) => setAddress(event.target.value)}
              disabled={create.isPending}
              autoComplete="off"
              slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
              {...fieldProps(fieldError, 'body.address')}
            />
            <TextField
              label={t('list.field.tag')}
              value={tag}
              onChange={(event) => setTag(event.target.value)}
              disabled={create.isPending}
              {...fieldProps(fieldError, 'body.tag', t('list.form.hint.tag'))}
            />
            <TextField
              label={t('list.field.members')}
              value={members}
              onChange={(event) => setMembers(event.target.value)}
              disabled={create.isPending}
              multiline
              minRows={4}
              slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
              error={membersError !== undefined}
              helperText={membersError ?? t('list.form.hint.members')}
            />
            <ProblemAlert error={create.error} />
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <FormActions pending={create.isPending} submitLabel={t('list.create.submit')} onCancel={close} />
        </DialogActions>
      </form>
    </Dialog>
  );
}
