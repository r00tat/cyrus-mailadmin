import Box from '@mui/material/Box';
import Breadcrumbs from '@mui/material/Breadcrumbs';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';

import { useDocumentTitleText } from '../app/DocumentTitle';
import { useTranslation } from '../i18n';

/**
 * The head of every area page: breadcrumbs, the one `h1`, an optional line of
 * explanation and the page's primary actions.
 *
 * It also sets `document.title` from the same text, so the browser tab and the
 * heading cannot disagree — and an area page needs no `useDocumentTitle` of its
 * own.
 *
 * Breadcrumbs are given as already translated labels; the last one is the
 * current page and is rendered as text with `aria-current="page"`. The
 * overview is not added automatically: a list page has no breadcrumbs at all,
 * a detail page names its list (`Domains › example.test`).
 */

export interface Breadcrumb {
  readonly label: string;
  /** Router path. Omit for the current page. */
  readonly to?: string;
}

export function PageHeader({
  title,
  breadcrumbs = [],
  description,
  actions,
}: {
  /** The page heading, already translated (`t('domain.list.title')`) or an object name. */
  title: string;
  breadcrumbs?: readonly Breadcrumb[];
  description?: ReactNode;
  /** Buttons for the page as a whole (create, delete). Right-aligned on wide screens. */
  actions?: ReactNode;
}): ReactNode {
  const { t } = useTranslation();

  useDocumentTitleText(title);

  return (
    <Stack spacing={1} sx={{ mb: 3 }}>
      {breadcrumbs.length > 0 && (
        <Breadcrumbs aria-label={t('nav.breadcrumbs')} expandText={t('nav.breadcrumbs')}>
          {breadcrumbs.map((crumb, index) =>
            crumb.to !== undefined && index < breadcrumbs.length - 1 ? (
              <Link key={`${crumb.to}-${String(index)}`} component={RouterLink} to={crumb.to} underline="hover" color="inherit">
                {crumb.label}
              </Link>
            ) : (
              <Typography key={`current-${String(index)}`} color="text.primary" aria-current="page">
                {crumb.label}
              </Typography>
            ),
          )}
        </Breadcrumbs>
      )}

      <Box
        sx={{
          display: 'flex',
          flexWrap: 'wrap',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 2,
        }}
      >
        <Typography variant="h5" component="h1">
          {title}
        </Typography>
        {actions !== undefined && (
          <Stack direction="row" spacing={1}>
            {actions}
          </Stack>
        )}
      </Box>

      {description !== undefined && (
        <Typography color="text.secondary">{description}</Typography>
      )}
    </Stack>
  );
}
