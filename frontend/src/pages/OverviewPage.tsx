import Alert from '@mui/material/Alert';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';

import { useDocumentTitle } from '../app/DocumentTitle';
import { useSession, type AdminRole } from '../auth/SessionContext';
import { useFormatters, useTranslation, type TranslationKey } from '../i18n';

import { LanguagePreferenceCard } from './LanguagePreferenceCard';

/**
 * What the scaffold can already show: who is signed in, what they may do, and
 * which domains they reach.
 *
 * It is not a placeholder for its own sake — it renders `GET /auth/me` in full,
 * which is the one thing every later view depends on and the fastest way to see
 * that role, scope and permissions arrive correctly.
 */

const ROLE_KEYS: Record<AdminRole, TranslationKey> = {
  superuser: 'role.superuser',
  domainmaster: 'role.domainmaster',
};

export function OverviewPage(): ReactNode {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const { session, can } = useSession();

  useDocumentTitle('overview.title');

  if (session === null) {
    return null;
  }

  // An empty list means two different things depending on the role, and the
  // contract is explicit that they must not look alike: a superuser has no
  // scope because they need none, a domain master with an empty list reaches
  // nothing at all.
  const scope = session.role === 'superuser'
    ? t('overview.scopeAll')
    : session.domains.length === 0
      ? t('overview.scopeNone')
      : session.domains.join(', ');

  return (
    <Stack spacing={3}>
      <Typography variant="h5" component="h1">
        {t('overview.title')}
      </Typography>

      <Card>
        <CardContent>
          <Stack spacing={2}>
            <Typography>{t('overview.greeting', { username: session.username })}</Typography>

            <Stack spacing={0.5}>
              <Typography variant="overline" color="text.secondary">
                {t('overview.roleLabel')}
              </Typography>
              <Typography>{t(ROLE_KEYS[session.role])}</Typography>
            </Stack>

            <Stack spacing={0.5}>
              <Typography variant="overline" color="text.secondary">
                {t('overview.scopeLabel')}
              </Typography>
              <Typography>{scope}</Typography>
              {session.role !== 'superuser' && session.domains.length > 0 && (
                <Typography variant="body2" color="text.secondary">
                  {t('overview.scopeCount', { count: session.domains.length })}
                </Typography>
              )}
            </Stack>

            <Stack spacing={0.5}>
              <Typography variant="overline" color="text.secondary">
                {t('overview.permissionsLabel')}
              </Typography>
              <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1}>
                {session.permissions.map((permission) => (
                  <Chip key={permission} label={permission} size="small" />
                ))}
              </Stack>
            </Stack>

            <Stack spacing={0.5}>
              <Typography variant="overline" color="text.secondary">
                {t('overview.sessionExpiresLabel')}
              </Typography>
              <Typography>{dateTime(session.expires_at)}</Typography>
            </Stack>
          </Stack>
        </CardContent>
      </Card>

      {can('profile.preferences.write') && <LanguagePreferenceCard />}

      <Alert severity="info">{t('overview.pendingViews')}</Alert>
    </Stack>
  );
}
