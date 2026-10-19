import { isApiProblem } from '../../api/problem';
import type { components } from '../../api/schema';
import type { TranslationKey } from '../../i18n';

/**
 * Types and lookup tables of the accounts area (A2). The keys are written out
 * literally so that the catalogue check finds every one of them (U-88).
 */

export type Account = components['schemas']['Account'];
export type AccountQuota = components['schemas']['AccountQuota'];
export type PasswordFormat = components['schemas']['AccountPasswordFormat'];
export type AccountNamingScheme = components['schemas']['AccountNamingScheme'];

/** The values of the contract's `AccountPasswordFormat`, in the order the filter offers them. */
export const PASSWORD_FORMATS = [
  'bcrypt',
  'argon2id',
  'sha512crypt',
  'sha256crypt',
  'md5crypt',
  'descrypt',
  'sha1hex',
  'mysql_password',
  'plaintext',
  'unknown',
] as const satisfies readonly PasswordFormat[];

export const PASSWORD_FORMAT_KEYS: Readonly<Record<PasswordFormat, TranslationKey>> = {
  argon2id: 'account.list.passwordFormat.argon2id',
  bcrypt: 'account.list.passwordFormat.bcrypt',
  md5crypt: 'account.list.passwordFormat.md5crypt',
  sha256crypt: 'account.list.passwordFormat.sha256crypt',
  sha512crypt: 'account.list.passwordFormat.sha512crypt',
  descrypt: 'account.list.passwordFormat.descrypt',
  sha1hex: 'account.list.passwordFormat.sha1hex',
  mysql_password: 'account.list.passwordFormat.mysqlPassword',
  plaintext: 'account.list.passwordFormat.plaintext',
  unknown: 'account.list.passwordFormat.unknown',
};

export const NAMING_SCHEME_KEYS: Readonly<Record<AccountNamingScheme, TranslationKey>> = {
  dotted: 'account.list.namingScheme.dotted',
  fqun: 'account.list.namingScheme.fqun',
};

/**
 * Formats `pam_mysql` cannot verify: such an account cannot log in to IMAP or
 * SMTP until its password is set again (contract, `AccountPasswordFormat`).
 */
export function blocksMailLogin(format: PasswordFormat): boolean {
  return format === 'mysql_password' || format === 'plaintext';
}

/** The detail route of an account. The id is the stored Cyrus user name and is never taken apart. */
export function accountPath(id: string): string {
  return `/accounts/${encodeURIComponent(id)}`;
}

/** The list route, narrowed to a domain. */
export function accountListPath(domain: string | undefined): string {
  return domain === undefined ? '/accounts' : `/accounts?domain=${encodeURIComponent(domain)}`;
}

/** `true` when `error` is a problem document carrying `code`. */
export function hasCode(error: unknown, code: string): boolean {
  return isApiProblem(error) && error.code === code;
}
