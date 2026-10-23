import type { components } from '../../api/schema';
import type { TranslationKey } from '../../i18n';

/**
 * Types and lookup tables of the addresses area (A3). The keys are written
 * out literally so that the catalogue check finds every one of them (U-88).
 */

export type AddressEntry = components['schemas']['AddressEntry'];
export type AddressEntryType = components['schemas']['AddressEntryType'];
export type AddressUnknownReason = components['schemas']['AddressUnknownReason'];
export type Alias = components['schemas']['Alias'];
export type Catchall = components['schemas']['Catchall'];
export type Forwards = components['schemas']['Forwards'];
export type Vacation = components['schemas']['Vacation'];

/** The four types of architecture 3.4, mailing list rows (A5), plus the rows that fit none (U-22). */
export const ENTRY_TYPES = [
  'mailbox_address',
  'alias',
  'catchall',
  'forward',
  'mailing_list',
  'unknown',
] as const satisfies readonly AddressEntryType[];

export const ENTRY_TYPE_KEYS: Readonly<Record<AddressEntryType, TranslationKey>> = {
  mailbox_address: 'address.list.type.mailboxAddress',
  alias: 'address.list.type.alias',
  catchall: 'address.list.type.catchall',
  forward: 'address.list.type.forward',
  mailing_list: 'address.list.type.mailingList',
  unknown: 'address.list.type.unknown',
};

export const UNKNOWN_REASON_KEYS: Readonly<Record<AddressUnknownReason, TranslationKey>> = {
  no_alias: 'address.list.unknownReason.noAlias',
  no_destination: 'address.list.unknownReason.noDestination',
  alias_is_not_an_address: 'address.list.unknownReason.aliasIsNotAnAddress',
  catchall_without_domain: 'address.list.unknownReason.catchallWithoutDomain',
  unusable_value: 'address.list.unknownReason.unusableValue',
};

/** The detail route of an alias; the address is URL-encoded (`info%40example.test`). */
export function aliasPath(address: string): string {
  return `/addresses/aliases/${encodeURIComponent(address)}`;
}

/** The list route, narrowed to a domain. */
export function addressListPath(domain: string | undefined): string {
  return domain === undefined ? '/addresses' : `/addresses?domain=${encodeURIComponent(domain)}`;
}

/**
 * Destinations as typed: one per line, or separated by commas, semicolons or
 * blanks. Empty pieces are dropped; everything else — duplicates, loops,
 * names that are no account — is the server's to judge (U-104, U-112).
 */
export function parseDestinations(text: string): string[] {
  return text.split(/[\s,;]+/).filter((piece) => piece !== '');
}

/** Destinations as the field shows them: one per line. */
export function formatDestinations(destinations: readonly string[]): string {
  return destinations.join('\n');
}
