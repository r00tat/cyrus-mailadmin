import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';

import { useDocumentTitle } from '../app/DocumentTitle';
import { useTranslation } from '../i18n';

/**
 * Client-side 404. Reached when the router knows no such route — the web server
 * hands every unknown path to the shell so that deep links work
 * (architecture 11.1), which means this page, not Apache, is what a mistyped
 * URL produces.
 */
export function NotFoundPage(): ReactNode {
  const { t } = useTranslation();

  useDocumentTitle('state.notFound.title');

  return (
    <Box sx={{ py: 6, textAlign: 'center' }}>
      <Stack spacing={2} alignItems="center">
        <Typography variant="h5" component="h1">
          {t('state.notFound.title')}
        </Typography>
        <Typography color="text.secondary">{t('state.notFound.detail')}</Typography>
        <Button component={RouterLink} to="/" variant="contained">
          {t('state.notFound.back')}
        </Button>
      </Stack>
    </Box>
  );
}
