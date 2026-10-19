import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';

import { isVersionConflict } from '../api/etag';
import { useTranslation } from '../i18n';
import { useProblemText } from '../i18n/problemText';

/**
 * A failed request, said in one line: for forms, dialogs and table toolbars,
 * where `ErrorState` with its title would be too much.
 *
 * The text comes from the catalogue via `code` (`errors.<code>` in
 * `de.errors.ts`), with the response's own `detail`/`title` as fallback for a
 * code this build does not know (U-72) — never from the exception message.
 * The request id is always shown when there is one; it is what turns "it did
 * not work" into a log line.
 *
 * Field errors are **not** repeated here. They belong on their fields
 * (`useFieldErrors` in `./form`); this shows the problem as a whole.
 *
 * `onReload` is offered for a `412`: the object changed since it was loaded,
 * and the only sensible next step is to look at it again.
 */
export function ProblemAlert({
  error,
  onReload,
}: {
  error: unknown;
  onReload?: () => void;
}): ReactNode {
  const { t } = useTranslation();
  const describe = useProblemText();

  if (error === null || error === undefined) {
    return null;
  }

  const { message, requestId } = describe(error);
  const offerReload = onReload !== undefined && isVersionConflict(error);

  return (
    <Alert
      severity="error"
      role="alert"
      action={
        offerReload ? (
          <Button color="inherit" size="small" onClick={onReload}>
            {t('state.error.reload')}
          </Button>
        ) : undefined
      }
    >
      <Stack spacing={0.5}>
        <Typography variant="body2">{message}</Typography>
        {requestId !== undefined && (
          <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>
            {t('state.error.requestId', { requestId })}
          </Typography>
        )}
      </Stack>
    </Alert>
  );
}
