import Alert from '@mui/material/Alert';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import { ifMatch, isVersionConflict } from '../../api/etag';
import { isApiProblem } from '../../api/problem';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { fieldProps, useFieldErrors, type FieldErrors } from '../../components/form';
import { useTranslation } from '../../i18n';

import { forgetDomainName } from './domainCache';
import type { Domain } from './domainForm';

/**
 * Renaming a domain: the most expensive operation in the application, and the
 * one the legacy system got wrong (architecture 8.1). Every mailbox of the
 * domain is renamed in Cyrus before the database is touched, and every account
 * gets a new user name.
 *
 * So it is a confirmed operation in two senses: the dialog names the
 * consequences and wants the **current** name typed, and the request carries
 * `confirm_rename: true`, without which the server refuses (U-49).
 *
 * Three ways it can end besides success, each said differently:
 *
 *  - `409 domain.rename_incomplete` — half done; the dialog closes and the page
 *    shows it prominently, with the way to the repair queue (`onIncomplete`);
 *  - `412` — someone else changed the domain; the page reloads it and the
 *    dialog stays open with the explanation (`onReload`);
 *  - anything else (`503`, `409 name_taken`, a field error) stays in the
 *    dialog, on the field where it has one.
 */

/** `true` for the failure that leaves the rename half done (`409 domain.rename_incomplete`). */
export function isRenameIncomplete(error: unknown): boolean {
  return isApiProblem(error) && error.code === 'domain.rename_incomplete';
}

export function RenameDomainDialog({
  open,
  domain,
  etag,
  onClose,
  onReload,
  onIncomplete,
  onRenamed,
}: {
  open: boolean;
  domain: Domain;
  etag: string | null;
  onClose: () => void;
  onReload: () => void;
  onIncomplete: (error: unknown) => void;
  onRenamed: (newName: string) => void;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const queryClient = useQueryClient();

  const [newName, setNewName] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const rename = useMutation({
    mutationFn: (name: string) =>
      client.patch('/domains/{domain}', {
        pathParams: { domain: domain.name },
        body: { name, confirm_rename: true },
        headers: ifMatch(etag),
      }),
    onSuccess: (renamed) => {
      setNewName('');
      // The domain name is part of every account's user name and every
      // address of the domain: those lists are stale too. The old name's
      // detail is dropped, not refetched — it would answer 404.
      forgetDomainName(queryClient, domain.name);
      onRenamed(renamed.name);
    },
    onError: (error) => {
      if (isRenameIncomplete(error)) {
        setNewName('');
        rename.reset();
        void queryClient.invalidateQueries({ queryKey: ['domains'] });
        onIncomplete(error);
      } else if (isVersionConflict(error)) {
        onReload();
      }
    },
  });

  const fieldError = useFieldErrors(rename.error, clientErrors);

  const confirm = (): void => {
    const trimmed = newName.trim();

    if (trimmed === '') {
      setClientErrors({ 'body.name': t('errors.field.required') });

      return;
    }

    if (trimmed === domain.name) {
      setClientErrors({ 'body.name': t('domain.rename.error.sameName') });

      return;
    }

    setClientErrors({});
    rename.mutate(trimmed);
  };

  const close = (): void => {
    setNewName('');
    setClientErrors({});
    rename.reset();
    onClose();
  };

  return (
    <ConfirmDialog
      open={open}
      destructive
      title={t('domain.rename.title', { name: domain.name })}
      confirmLabel={t('domain.rename.submit')}
      message={
        <Stack spacing={2}>
          <Alert severity="warning">
            <Stack spacing={1}>
              <span>{t('domain.rename.consequence.mailboxes', { count: domain.account_count })}</span>
              <span>{t('domain.rename.consequence.logins')}</span>
              <span>{t('domain.rename.consequence.external')}</span>
            </Stack>
          </Alert>
          <TextField
            label={t('domain.rename.field.newName')}
            required
            value={newName}
            onChange={(event) => setNewName(event.target.value)}
            disabled={rename.isPending}
            autoComplete="off"
            slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
            fullWidth
            {...fieldProps(fieldError, 'body.name')}
          />
        </Stack>
      }
      typedConfirmation={domain.name}
      pending={rename.isPending}
      error={rename.error}
      onConfirm={confirm}
      onClose={close}
    />
  );
}
