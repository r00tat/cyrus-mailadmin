import Alert from '@mui/material/Alert';
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
import type { components } from '../../api/schema';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { FormActions, fieldProps, useFieldErrors } from '../../components/form';
import { useNotify } from '../../components/Notifications';
import { ProblemAlert } from '../../components/ProblemAlert';
import { useTranslation } from '../../i18n';

/**
 * Retrying and resolving a repair-queue entry (A6), shared by the list and the
 * detail view.
 *
 * Neither operation takes `If-Match` — the contract declares none, because the
 * server checks the state the entry describes under the entry's lock (U-125)
 * and answers `409` with a stable code when it moved on. Those codes are shown
 * in the dialog through the catalogue; the dialog stays open so the
 * administrator reads why. A refusal also reloads the list and the entry —
 * "already resolved" or "state changed" means the page shows a state that is
 * no longer true, and every further attempt would fail the same way (U-133).
 *
 * Retrying a `mailstore.delete_mailbox` step deletes a mailbox with its mail.
 * That is the one retry that destroys mail, so it asks for the mailbox's name
 * to be typed, like deleting an account does. Every other retry is confirmed
 * with a click.
 */

export type RepairEntry = components['schemas']['RepairEntry'];

type ResolveBody = components['schemas']['RepairResolveRequest'];

export type RepairAction ={ readonly kind: 'retry' | 'resolve'; readonly entry: RepairEntry };

/** The step whose retry deletes a mailbox (A6, "Wer Einträge schreibt"). */
const MAILBOX_DELETING_STEP = 'mailstore.delete_mailbox';

/** The contract's `maxLength` of `RepairResolveRequest.note`. */
const NOTE_MAX_LENGTH = 500;

export const REPAIRS_QUERY_ROOT = 'repairs';

/**
 * After a success: the answer is the entry's new state, the rest is reloaded.
 * After a refusal: everything is reloaded, because the entry moved on without
 * this page — the dialog keeps its error regardless.
 */
function useRefreshRepairs(): { succeeded: (entry: RepairEntry) => Promise<void>; failed: () => void } {
  const queryClient = useQueryClient();

  return {
    succeeded: async (entry) => {
      queryClient.setQueryData([REPAIRS_QUERY_ROOT, 'detail', String(entry.id)], entry);
      await queryClient.invalidateQueries({ queryKey: [REPAIRS_QUERY_ROOT] });
    },
    failed: () => {
      void queryClient.invalidateQueries({ queryKey: [REPAIRS_QUERY_ROOT] });
    },
  };
}

function RetryDialog({ entry, onClose }: { entry: RepairEntry; onClose: () => void }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const notify = useNotify();
  const refresh = useRefreshRepairs();

  const retry = useMutation({
    mutationFn: () =>
      client.post('/maintenance/repairs/{repair}/retry', { pathParams: { repair: String(entry.id) } }),
    onSuccess: async (updated) => {
      await refresh.succeeded(updated);
      notify(t('maintenance.repairs.retry.success'));
      onClose();
    },
    onError: refresh.failed,
  });

  const deletesMail = entry.step === MAILBOX_DELETING_STEP;

  return (
    <ConfirmDialog
      open
      title={t('maintenance.repairs.retry.title')}
      message={
        <Stack spacing={1}>
          <span>
            {t('maintenance.repairs.retry.message', { id: String(entry.id), step: entry.step, subject: entry.subject })}
          </span>
          {/* Part of the question, not an event: `note`, so the failure below stays the one alert. */}
          {deletesMail && <Alert severity="warning" role="note">{t('maintenance.repairs.retry.deleteWarning')}</Alert>}
        </Stack>
      }
      confirmLabel={t('maintenance.repairs.action.retry')}
      destructive={deletesMail}
      {...(deletesMail ? { typedConfirmation: entry.subject } : {})}
      pending={retry.isPending}
      error={retry.error}
      onConfirm={() => retry.mutate()}
      onClose={onClose}
    />
  );
}

function ResolveDialog({ entry, onClose }: { entry: RepairEntry; onClose: () => void }): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();
  const notify = useNotify();
  const refresh = useRefreshRepairs();
  const titleId = useId();
  const [note, setNote] = useState('');

  const resolve = useMutation({
    mutationFn: (text: string) => {
      // Always a JSON body, `{}` without a note: a body-less POST is a 422 (A6).
      const body: ResolveBody = text === '' ? {} : { note: text };
      return client.post('/maintenance/repairs/{repair}/resolve', {
        pathParams: { repair: String(entry.id) },
        body,
      });
    },
    onSuccess: async (updated) => {
      await refresh.succeeded(updated);
      notify(t('maintenance.repairs.resolve.success'));
      onClose();
    },
    onError: refresh.failed,
  });

  const fieldError = useFieldErrors(resolve.error);

  const close = (): void => {
    if (!resolve.isPending) {
      onClose();
    }
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    if (!resolve.isPending) {
      resolve.mutate(note.trim());
    }
  };

  return (
    <Dialog open onClose={close} aria-labelledby={titleId} maxWidth="sm" fullWidth>
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{t('maintenance.repairs.resolve.title')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2}>
            <Typography>{t('maintenance.repairs.resolve.message', { id: String(entry.id) })}</Typography>
            <TextField
              label={t('maintenance.repairs.resolve.note')}
              value={note}
              onChange={(event) => setNote(event.target.value)}
              disabled={resolve.isPending}
              multiline
              minRows={3}
              fullWidth
              slotProps={{ htmlInput: { maxLength: NOTE_MAX_LENGTH } }}
              {...fieldProps(fieldError, 'body.note', t('maintenance.repairs.resolve.noteHint'))}
            />
            <ProblemAlert error={resolve.error} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <FormActions
            pending={resolve.isPending}
            submitLabel={t('maintenance.repairs.action.resolve')}
            onCancel={close}
          />
        </DialogActions>
      </form>
    </Dialog>
  );
}

/** The open dialog for `action`, or nothing. Mounted fresh per action, so no input leaks between entries. */
export function RepairActionDialog({
  action,
  onClose,
}: {
  action: RepairAction | null;
  onClose: () => void;
}): ReactNode {
  if (action === null) {
    return null;
  }

  return action.kind === 'retry' ? (
    <RetryDialog key={`retry-${String(action.entry.id)}`} entry={action.entry} onClose={onClose} />
  ) : (
    <ResolveDialog key={`resolve-${String(action.entry.id)}`} entry={action.entry} onClose={onClose} />
  );
}

/** Which actions an entry offers to a session that may write. */
export function availableActions(entry: RepairEntry): { retry: boolean; resolve: boolean } {
  return {
    retry: entry.status === 'open' && entry.retryable,
    resolve: entry.status === 'open',
  };
}
