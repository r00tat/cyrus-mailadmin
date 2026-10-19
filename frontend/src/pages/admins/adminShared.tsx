import Autocomplete from '@mui/material/Autocomplete';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { useApiClient } from '../../api/ApiContext';
import type { components } from '../../api/schema';
import type { AdminRole } from '../../auth/SessionContext';
import { errorAtOrBelow } from '../../components/form';
import { useTranslation, type TranslationKey } from '../../i18n';
import { describeUnknownError } from '../../i18n/problemText';

/**
 * Pieces the administrator views share (A4): labels for role and hash format,
 * the domain assignment as it is shown, and the picker it is edited with.
 */

export type Admin = components['schemas']['Admin'];
export type AdminPasswordFormat = components['schemas']['AdminPasswordFormat'];

export const ROLES: readonly AdminRole[] = ['superuser', 'domainmaster'];

/** The frame already names the roles (`role.*`); the administrator views reuse those words. */
export const ROLE_KEYS: Readonly<Record<AdminRole, TranslationKey>> = {
  superuser: 'role.superuser',
  domainmaster: 'role.domainmaster',
};

export const PASSWORD_FORMAT_KEYS: Readonly<Record<AdminPasswordFormat, TranslationKey>> = {
  argon2id: 'admin.passwordFormat.argon2id',
  bcrypt: 'admin.passwordFormat.bcrypt',
  md5crypt: 'admin.passwordFormat.md5crypt',
  sha256crypt: 'admin.passwordFormat.sha256crypt',
  sha512crypt: 'admin.passwordFormat.sha512crypt',
  descrypt: 'admin.passwordFormat.descrypt',
  sha1hex: 'admin.passwordFormat.sha1hex',
  mysql_password: 'admin.passwordFormat.mysqlPassword',
  plaintext: 'admin.passwordFormat.plaintext',
  unknown: 'admin.passwordFormat.unknown',
};

/**
 * The server's error for `prefix` or an element below it: a bad domain in the
 * assignment comes back as `body.domains.2`, the form has one field for all.
 */
export function useErrorAtOrBelow(error: unknown, prefix: string): string | undefined {
  const translator = useTranslation();

  if (error === null || error === undefined) {
    return undefined;
  }

  return errorAtOrBelow(describeUnknownError(error, translator).fieldErrors, prefix);
}

/** The detail route of an administrator. */
export function adminPath(username: string): string {
  return `/admins/${encodeURIComponent(username)}`;
}

/** The hash format, and whether it is one webcyradm left behind (E-13). */
export function PasswordFormat({ admin }: { admin: Admin }): ReactNode {
  const { t } = useTranslation();

  return (
    <Stack direction="row" spacing={1} alignItems="center">
      <span>{t(PASSWORD_FORMAT_KEYS[admin.password_format])}</span>
      {admin.password_legacy && (
        <Tooltip title={t('admin.passwordFormat.legacyHint')}>
          <Chip size="small" color="warning" variant="outlined" label={t('admin.passwordFormat.legacy')} />
        </Tooltip>
      )}
    </Stack>
  );
}

/**
 * Which domains an administrator reaches, as the data says (U-22): the
 * wildcard as "all domains", every named domain, and an assignment whose
 * domain no longer exists marked rather than dropped.
 */
export function DomainScope({ admin }: { admin: Admin }): ReactNode {
  const { t } = useTranslation();
  const orphaned = new Set(admin.orphaned_domains);

  if (admin.all_domains && admin.domains.length === 0) {
    return <span>{t('admin.scope.all')}</span>;
  }

  return (
    <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap alignItems="center">
      {admin.all_domains && <Chip size="small" label={t('admin.scope.all')} />}
      {admin.domains.map((domain) =>
        orphaned.has(domain) ? (
          <Tooltip key={domain} title={t('admin.scope.orphanedHint')}>
            <Chip
              size="small"
              color="warning"
              variant="outlined"
              label={t('admin.scope.orphanedChip', { domain })}
            />
          </Tooltip>
        ) : (
          <Chip key={domain} size="small" label={domain} />
        ),
      )}
      {!admin.all_domains && admin.domains.length === 0 && <span>{t('admin.scope.none')}</span>}
    </Stack>
  );
}

/**
 * The domain assignment of a domain master. Offers the existing domains (one
 * page of 200, the contract's ceiling) and still accepts a typed name, so an
 * installation with more domains is not stuck; the server checks every name
 * (`admin.unknown_domain`). An assigned domain that no longer exists is shown
 * in warning colour so it can be removed.
 *
 * A typed name counts when the field loses focus (`autoSelect`), not only on
 * Enter: clicking "save" straight after typing must not drop the domain the
 * administrator meant to assign (U-133).
 */
export function DomainAssignmentField({
  value,
  onChange,
  orphaned = [],
  disabled = false,
  error,
  helperText,
}: {
  value: readonly string[];
  onChange: (value: string[]) => void;
  orphaned?: readonly string[];
  disabled?: boolean;
  error: boolean;
  helperText: ReactNode;
}): ReactNode {
  const { t } = useTranslation();
  const client = useApiClient();

  const options = useQuery({
    queryKey: ['domains', 'options'],
    queryFn: ({ signal }) => client.get('/domains', { query: { per_page: 200, sort: 'name' }, signal }),
    staleTime: 60_000,
  });

  const orphanedSet = new Set(orphaned);

  return (
    <Autocomplete
      multiple
      freeSolo
      autoSelect
      options={(options.data?.items ?? []).map((domain) => domain.name)}
      value={[...value]}
      onChange={(_event, next) => onChange([...new Set(next.map((entry) => entry.trim()).filter((entry) => entry !== ''))])}
      disabled={disabled}
      loading={options.isPending}
      loadingText={t('state.loading')}
      noOptionsText={t('admin.form.domainsNoOptions')}
      renderValue={(selected, getItemProps) =>
        selected.map((domain, index) => {
          const { key, ...itemProps } = getItemProps({ index });

          return (
            <Chip
              key={key}
              size="small"
              label={orphanedSet.has(domain) ? t('admin.scope.orphanedChip', { domain }) : domain}
              color={orphanedSet.has(domain) ? 'warning' : 'default'}
              {...itemProps}
            />
          );
        })
      }
      renderInput={(params) => (
        <TextField {...params} label={t('admin.field.domains')} error={error} helperText={helperText} />
      )}
    />
  );
}
