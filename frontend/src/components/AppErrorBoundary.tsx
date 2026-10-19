import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import { Component, type ErrorInfo, type ReactNode } from 'react';

import { useTranslation } from '../i18n';

/**
 * Last resort for a render that throws.
 *
 * Without one, a single bad render blanks the page, and a blank page in an
 * administration tool looks exactly like an outage. React has no hook form of
 * this, so it stays a class.
 *
 * **Nothing from the error reaches the screen.** A stack trace can carry a URL
 * with a token in it, and the rule that exception messages are not shown to the
 * user (CLAUDE.md, "Fehler") does not stop being true on the client side.
 */

interface Props {
  children: ReactNode;
}

interface State {
  failed: boolean;
}

class ErrorBoundaryInner extends Component<Props & { fallback: ReactNode }, State> {
  override state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    // The console is the developer's channel, not the user's. In a production
    // build this is the only trace, and it stays local to the browser.
    console.error('Unhandled error while rendering.', error, info.componentStack);
  }

  override render(): ReactNode {
    return this.state.failed ? this.props.fallback : this.props.children;
  }
}

function CrashScreen(): ReactNode {
  const { t } = useTranslation();

  return (
    <Box sx={{ p: 3, maxWidth: 640, mx: 'auto' }}>
      <Alert
        severity="error"
        role="alert"
        action={
          <Button color="inherit" size="small" onClick={() => window.location.reload()}>
            {t('state.crash.reload')}
          </Button>
        }
      >
        <AlertTitle>{t('state.crash.title')}</AlertTitle>
        {t('state.crash.detail')}
      </Alert>
    </Box>
  );
}

export function AppErrorBoundary({ children }: Props): ReactNode {
  return <ErrorBoundaryInner fallback={<CrashScreen />}>{children}</ErrorBoundaryInner>;
}
