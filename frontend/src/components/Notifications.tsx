import Alert from '@mui/material/Alert';
import Snackbar from '@mui/material/Snackbar';
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';

import { useTranslation } from '../i18n';

/**
 * Short confirmations after a change ("Domain angelegt.").
 *
 * One provider for the whole application (mounted in `AppProviders`), so that
 * a view can confirm a change and navigate away in the same breath — the
 * message outlives the view that sent it.
 *
 * **Only for success.** A failure stays where it happened, next to the input
 * that caused it (`ProblemAlert`, field errors); a toast that disappears after
 * a few seconds is the wrong place for something the user has to act on.
 */

export type Notify = (message: string) => void;

const NotificationContext = createContext<Notify | null>(null);

interface Message {
  readonly id: number;
  readonly text: string;
}

export function NotificationProvider({ children }: { children: ReactNode }): ReactNode {
  const { t } = useTranslation();
  const [message, setMessage] = useState<Message | null>(null);

  const notify = useCallback<Notify>((text) => {
    setMessage((current) => ({ id: (current?.id ?? 0) + 1, text }));
  }, []);

  return (
    <NotificationContext.Provider value={notify}>
      {children}
      <Snackbar
        key={message?.id}
        open={message !== null}
        autoHideDuration={5000}
        onClose={(_event, reason) => {
          if (reason !== 'clickaway') {
            setMessage(null);
          }
        }}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert
          severity="success"
          variant="filled"
          role="status"
          closeText={t('action.close')}
          onClose={() => setMessage(null)}
        >
          {message?.text}
        </Alert>
      </Snackbar>
    </NotificationContext.Provider>
  );
}

export function useNotify(): Notify {
  const notify = useContext(NotificationContext);

  if (notify === null) {
    throw new Error('useNotify() was called outside of <NotificationProvider>.');
  }

  return notify;
}
