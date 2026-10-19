import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useId, useState, type FormEvent, type ReactNode } from 'react';

import { useTranslation } from '../i18n';

import { ProblemAlert } from './ProblemAlert';

/**
 * Asks before something happens — and, for the operations that destroy mail,
 * makes the user type the name of what is about to go.
 *
 * `typedConfirmation` is for deletes and renames that cannot be undone
 * (a domain, an account and its mailbox): the confirm button stays disabled
 * until the input matches **exactly**, case included. A click-through dialog
 * protects against a misclick; only a typed name protects against deleting the
 * wrong row of a long list, which is the accident this tool can least afford.
 *
 * The dialog does not close itself on confirm. `onConfirm` runs the mutation;
 * while `pending` the buttons are disabled and Escape does nothing, and a
 * failure is shown inside the dialog via the catalogue (`error`). The caller
 * closes it on success — so the user never sees a dialog vanish and wonder
 * whether it worked.
 */
export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel,
  destructive = false,
  typedConfirmation,
  pending = false,
  error,
  onConfirm,
  onClose,
}: {
  open: boolean;
  /** Already translated. */
  title: string;
  /** What will happen, already translated; name the object and the consequence. */
  message: ReactNode;
  /** Defaults to `action.delete` when `destructive`, `action.confirm` otherwise. */
  confirmLabel?: string;
  destructive?: boolean;
  /** The text the user must type to enable the confirm button. */
  typedConfirmation?: string;
  pending?: boolean;
  error?: unknown;
  onConfirm: () => void;
  onClose: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const [typed, setTyped] = useState('');
  const titleId = useId();
  const messageId = useId();

  const matches = typedConfirmation === undefined || typed === typedConfirmation;
  const label = confirmLabel ?? (destructive ? t('action.delete') : t('action.confirm'));

  const close = (): void => {
    if (pending) {
      return;
    }

    setTyped('');
    onClose();
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    if (matches && !pending) {
      onConfirm();
    }
  };

  return (
    <Dialog
      open={open}
      onClose={close}
      aria-labelledby={titleId}
      aria-describedby={messageId}
      maxWidth="sm"
      fullWidth
      slotProps={{ transition: { onExited: () => setTyped('') } }}
    >
      <form onSubmit={submit} noValidate>
        <DialogTitle id={titleId}>{title}</DialogTitle>
        <DialogContent>
          <Stack spacing={2}>
            <Typography id={messageId} component="div">
              {message}
            </Typography>

            {typedConfirmation !== undefined && (
              <>
                <Typography variant="body2">
                  {t('confirm.typeToConfirm', { value: typedConfirmation })}
                </Typography>
                <TextField
                  label={t('confirm.typedLabel')}
                  value={typed}
                  onChange={(event) => setTyped(event.target.value)}
                  autoComplete="off"
                  disabled={pending}
                  slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
                  autoFocus
                  fullWidth
                />
              </>
            )}

            <ProblemAlert error={error} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={close} disabled={pending}>
            {t('action.cancel')}
          </Button>
          <Button
            type="submit"
            variant="contained"
            color={destructive ? 'error' : 'primary'}
            disabled={!matches || pending}
          >
            {label}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
