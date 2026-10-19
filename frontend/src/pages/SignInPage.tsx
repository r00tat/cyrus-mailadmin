import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';

import { ApiProblem } from '../api/problem';
import { useDocumentTitle } from '../app/DocumentTitle';
import { useSession } from '../auth/SessionContext';
import { useTranslation } from '../i18n';
import { useProblemText } from '../i18n/problemText';

/**
 * The sign-in form.
 *
 * Validation is hand-written and stays that way (U-75): two required fields,
 * and everything else is decided by the server, which answers with a `code` and
 * a map of field paths. A form library would only duplicate rules that already
 * exist in the OpenAPI schema and could drift from them.
 *
 * The server's field errors (`Problem.errors`, keyed `body.username`) are put
 * back on the inputs they belong to. `auth.invalid_credentials` is not one of
 * them and never will be: it says nothing about which of the two was wrong,
 * because the endpoint answers a wrong password and an unknown name
 * identically.
 */

interface LocationState {
  from?: string;
}

export function SignInPage(): ReactNode {
  const { t } = useTranslation();
  const describe = useProblemText();
  const { status, signIn } = useSession();
  const navigate = useNavigate();
  const location = useLocation();

  const usernameId = useId();
  const passwordId = useId();

  useDocumentTitle('signIn.title');

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [localErrors, setLocalErrors] = useState<{ username?: string; password?: string }>({});

  const target = (location.state as LocationState | null)?.from ?? '/';

  if (status === 'authenticated') {
    return <Navigate to={target} replace />;
  }

  const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
    event.preventDefault();

    const problems: { username?: string; password?: string } = {};

    if (username.trim() === '') {
      problems.username = t('signIn.usernameRequired');
    }

    if (password === '') {
      problems.password = t('signIn.passwordRequired');
    }

    setLocalErrors(problems);

    if (Object.keys(problems).length > 0) {
      return;
    }

    setSubmitting(true);
    setError(null);

    void signIn({ username, password })
      .then(() => {
        // The password never survives the submit, not even in component state
        // that is about to be unmounted.
        setPassword('');
        void navigate(target, { replace: true });
      })
      .catch((cause: unknown) => {
        setError(cause);
      })
      .finally(() => {
        setSubmitting(false);
      });
  };

  const described = error === null ? null : describe(error);
  const serverFieldErrors = described?.fieldErrors ?? {};

  const usernameError = localErrors.username ?? serverFieldErrors['body.username'];
  const passwordError = localErrors.password ?? serverFieldErrors['body.password'];

  const rateLimited = error instanceof ApiProblem && error.status === 429;

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        p: 2,
        bgcolor: 'background.default',
      }}
    >
      <Card sx={{ width: '100%', maxWidth: 420 }}>
        <CardContent sx={{ p: 3 }}>
          <Stack spacing={2}>
            <Box>
              <Typography variant="h5" component="h1">
                {t('signIn.title')}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {t('signIn.subtitle')}
              </Typography>
            </Box>

            {described !== null && (
              <Alert severity={rateLimited ? 'warning' : 'error'} role="alert">
                <Stack spacing={0.5}>
                  <Typography variant="body2">{described.message}</Typography>
                  {described.requestId !== undefined && (
                    <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>
                      {t('state.error.requestId', { requestId: described.requestId })}
                    </Typography>
                  )}
                </Stack>
              </Alert>
            )}

            <form onSubmit={handleSubmit} noValidate>
              <Stack spacing={2}>
                <TextField
                  id={usernameId}
                  label={t('signIn.username')}
                  value={username}
                  onChange={(event) => setUsername(event.target.value)}
                  autoComplete="username"
                  autoFocus
                  required
                  fullWidth
                  error={usernameError !== undefined}
                  helperText={usernameError ?? ' '}
                  slotProps={{ htmlInput: { maxLength: 190 } }}
                />

                <TextField
                  id={passwordId}
                  label={t('signIn.password')}
                  type="password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  autoComplete="current-password"
                  required
                  fullWidth
                  error={passwordError !== undefined}
                  helperText={passwordError ?? ' '}
                  slotProps={{ htmlInput: { maxLength: 4096 } }}
                />

                <Button type="submit" variant="contained" size="large" disabled={submitting} fullWidth>
                  {submitting ? t('signIn.submitting') : t('signIn.submit')}
                </Button>
              </Stack>
            </form>
          </Stack>
        </CardContent>
      </Card>
    </Box>
  );
}
