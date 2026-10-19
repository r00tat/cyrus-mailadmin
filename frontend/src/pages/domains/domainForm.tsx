import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';

import type { components } from '../../api/schema';
import { fieldProps, type FieldErrors } from '../../components/form';
import { useTranslation, type TranslationKey } from '../../i18n';

import { QuotaField, parseQuota, quotaInputFrom, type QuotaInput } from './QuotaField';

/**
 * The fields a domain is created and edited with (A1), shared by the create
 * and the edit page. The name is not among the edit fields: renaming is its
 * own, confirmed operation on the detail page.
 *
 * Inputs are kept as the user typed them; turning them into the request body
 * happens once, on submit, in {@link parseDomainValues}. Only "is it a number"
 * is checked here — the range and every other rule are the server's (U-73).
 */

export type Domain = components['schemas']['Domain'];
export type DomainNamingScheme = components['schemas']['DomainNamingScheme'];
export type DomainEffectiveNamingScheme = components['schemas']['DomainEffectiveNamingScheme'];

/** The detail route of a domain. */
export function domainPath(name: string): string {
  return `/domains/${encodeURIComponent(name)}`;
}

export const NAMING_SCHEMES: readonly DomainNamingScheme[] = ['inherited', 'dotted', 'fqun'];

/** Written out so the unused-key check finds every key. */
export const NAMING_SCHEME_KEYS: Readonly<Record<DomainNamingScheme, TranslationKey>> = {
  inherited: 'domain.scheme.value.inherited',
  dotted: 'domain.scheme.value.dotted',
  fqun: 'domain.scheme.value.fqun',
};

export const EFFECTIVE_SCHEME_KEYS: Readonly<Record<DomainEffectiveNamingScheme, TranslationKey>> = {
  dotted: 'domain.scheme.value.dotted',
  fqun: 'domain.scheme.value.fqun',
};

export interface DomainFormValues {
  readonly maxAccounts: string;
  readonly defaultQuota: QuotaInput;
  readonly domainQuota: QuotaInput;
  /** One folder per line. */
  readonly folders: string;
  readonly namingScheme: DomainNamingScheme;
  readonly freeNames: boolean;
  readonly freeAddress: boolean;
}

export const EMPTY_DOMAIN_VALUES: DomainFormValues = {
  maxAccounts: '',
  defaultQuota: quotaInputFrom(null),
  domainQuota: quotaInputFrom(null),
  folders: '',
  namingScheme: 'inherited',
  freeNames: false,
  freeAddress: false,
};

export function domainValuesFrom(domain: Domain): DomainFormValues {
  return {
    maxAccounts: domain.max_accounts === null ? '' : String(domain.max_accounts),
    defaultQuota: quotaInputFrom(domain.default_account_quota_kib),
    domainQuota: quotaInputFrom(domain.domain_quota_kib),
    folders: domain.default_folders.join('\n'),
    namingScheme: domain.naming_scheme,
    freeNames: domain.free_names,
    freeAddress: domain.free_address,
  };
}

export interface ParsedDomainValues {
  readonly max_accounts: number | null;
  readonly default_account_quota_kib: number | null;
  readonly domain_quota_kib: number | null;
  readonly default_folders: string[];
  readonly naming_scheme: DomainNamingScheme;
  readonly free_names: boolean;
  readonly free_address: boolean;
}

/** An empty input is "no limit" (`null`); anything else has to be a whole number. */
function parseCount(raw: string): number | null | undefined {
  const value = raw.trim();

  if (value === '') {
    return null;
  }

  return /^\d{1,10}$/.test(value) ? Number.parseInt(value, 10) : undefined;
}

export function parseFolders(raw: string): string[] {
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '');
}

/**
 * The request values, or the field errors that keep the form from being sent.
 * The error paths are the contract's (`body.max_accounts`), so a server error
 * for the same field lands in the same place.
 */
export function parseDomainValues(
  values: DomainFormValues,
  notANumber: string,
): { parsed: ParsedDomainValues | null; errors: FieldErrors } {
  const maxAccounts = parseCount(values.maxAccounts);
  const defaultQuota = parseQuota(values.defaultQuota);
  const domainQuota = parseQuota(values.domainQuota);

  const errors: Record<string, string> = {};

  if (maxAccounts === undefined) {
    errors['body.max_accounts'] = notANumber;
  }

  if (defaultQuota === undefined) {
    errors['body.default_account_quota_kib'] = notANumber;
  }

  if (domainQuota === undefined) {
    errors['body.domain_quota_kib'] = notANumber;
  }

  if (maxAccounts === undefined || defaultQuota === undefined || domainQuota === undefined) {
    return { parsed: null, errors };
  }

  return {
    parsed: {
      max_accounts: maxAccounts,
      default_account_quota_kib: defaultQuota,
      domain_quota_kib: domainQuota,
      default_folders: parseFolders(values.folders),
      naming_scheme: values.namingScheme,
      free_names: values.freeNames,
      free_address: values.freeAddress,
    },
    errors,
  };
}

export function DomainFields({
  values,
  onChange,
  fieldError,
  folderError,
  effectiveScheme,
  disabled = false,
}: {
  values: DomainFormValues;
  onChange: (patch: Partial<DomainFormValues>) => void;
  fieldError: (path: string) => string | undefined;
  /** The error for the folder list or any single folder in it. */
  folderError?: string | undefined;
  /** What `inherited` resolves to today, when known (edit). */
  effectiveScheme?: DomainEffectiveNamingScheme | undefined;
  disabled?: boolean;
}): ReactNode {
  const { t } = useTranslation();

  return (
    <Stack spacing={3}>
      <Stack spacing={2}>
        <Typography variant="subtitle1" component="h2">
          {t('domain.form.section.limits')}
        </Typography>
        <TextField
          label={t('domain.field.maxAccounts')}
          value={values.maxAccounts}
          onChange={(event) => onChange({ maxAccounts: event.target.value })}
          disabled={disabled}
          slotProps={{ htmlInput: { inputMode: 'numeric' } }}
          sx={{ maxWidth: 240 }}
          {...fieldProps(fieldError, 'body.max_accounts', t('domain.form.hint.maxAccounts'))}
        />
        <QuotaField
          label={t('domain.field.defaultQuota')}
          value={values.defaultQuota}
          onChange={(defaultQuota) => onChange({ defaultQuota })}
          disabled={disabled}
          {...fieldProps(fieldError, 'body.default_account_quota_kib', t('domain.form.hint.defaultQuota'))}
        />
        <QuotaField
          label={t('domain.field.domainQuota')}
          value={values.domainQuota}
          onChange={(domainQuota) => onChange({ domainQuota })}
          disabled={disabled}
          {...fieldProps(fieldError, 'body.domain_quota_kib', t('domain.form.hint.domainQuota'))}
        />
      </Stack>

      <Stack spacing={2}>
        <Typography variant="subtitle1" component="h2">
          {t('domain.form.section.mailboxes')}
        </Typography>
        <TextField
          select
          label={t('domain.field.namingScheme')}
          value={values.namingScheme}
          onChange={(event) => onChange({ namingScheme: event.target.value as DomainNamingScheme })}
          disabled={disabled}
          sx={{ maxWidth: 420 }}
          {...fieldProps(
            fieldError,
            'body.naming_scheme',
            effectiveScheme === undefined
              ? t('domain.form.hint.namingScheme')
              : t('domain.form.hint.namingSchemeEffective', { scheme: t(EFFECTIVE_SCHEME_KEYS[effectiveScheme]) }),
          )}
        >
          {NAMING_SCHEMES.map((scheme) => (
            <MenuItem key={scheme} value={scheme}>
              {t(NAMING_SCHEME_KEYS[scheme])}
            </MenuItem>
          ))}
        </TextField>
        <TextField
          label={t('domain.field.defaultFolders')}
          value={values.folders}
          onChange={(event) => onChange({ folders: event.target.value })}
          disabled={disabled}
          multiline
          minRows={3}
          sx={{ maxWidth: 420 }}
          error={folderError !== undefined}
          helperText={folderError ?? t('domain.form.hint.defaultFolders')}
        />
      </Stack>

      <Stack spacing={1}>
        <Typography variant="subtitle1" component="h2">
          {t('domain.form.section.legacy')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {t('domain.form.hint.legacy')}
        </Typography>
        <FormControlLabel
          control={
            <Checkbox
              checked={values.freeNames}
              onChange={(event) => onChange({ freeNames: event.target.checked })}
              disabled={disabled}
            />
          }
          label={t('domain.field.freeNames')}
        />
        <FormControlLabel
          control={
            <Checkbox
              checked={values.freeAddress}
              onChange={(event) => onChange({ freeAddress: event.target.checked })}
              disabled={disabled}
            />
          }
          label={t('domain.field.freeAddress')}
        />
      </Stack>

    </Stack>
  );
}

