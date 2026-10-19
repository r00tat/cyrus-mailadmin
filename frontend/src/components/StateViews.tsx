import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';

import { useTranslation } from '../i18n';
import { useProblemText } from '../i18n/problemText';

/**
 * The two states every view has besides its content: waiting, and failed.
 *
 * They live here so that eight feature packages render them the same way, and
 * so that the one detail that matters operationally — showing the request id —
 * cannot be forgotten in one view out of forty. A user who reports "it said
 * something went wrong" is not reporting anything; a user who reports the
 * request id hands over the key to the log line (`Problem.request_id`).
 */

export function LoadingState({ label }: { label?: string }): ReactNode {
  const { t } = useTranslation();

  return (
    <Box
      sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 2, py: 6 }}
      role="status"
      aria-live="polite"
    >
      <CircularProgress size={24} aria-hidden />
      <Typography color="text.secondary">{label ?? t('state.loading')}</Typography>
    </Box>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }): ReactNode {
  const { t } = useTranslation();
  const describe = useProblemText();
  const { message, requestId } = describe(error);

  return (
    <Alert
      severity="error"
      role="alert"
      action={
        onRetry === undefined ? undefined : (
          <Button color="inherit" size="small" onClick={onRetry}>
            {t('state.error.retry')}
          </Button>
        )
      }
    >
      <AlertTitle>{t('state.error.title')}</AlertTitle>
      <Stack spacing={0.5}>
        <Typography variant="body2">{message}</Typography>
        {requestId !== undefined && (
          <>
            <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>
              {t('state.error.requestId', { requestId })}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              {t('state.error.requestIdHint')}
            </Typography>
          </>
        )}
      </Stack>
    </Alert>
  );
}

/**
 * What a view shows when the session lacks the permission it needs.
 *
 * Courtesy, like hiding the navigation entry: the backend refuses the request
 * anyway. It exists so that a deep link or a bookmark from a superuser's
 * browser does not land a domain master on a page full of `403` messages.
 */
export function ForbiddenState(): ReactNode {
  const { t } = useTranslation();

  return (
    <Alert severity="warning" role="alert">
      <AlertTitle>{t('state.forbidden.title')}</AlertTitle>
      {t('state.forbidden.detail')}
    </Alert>
  );
}
