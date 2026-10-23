import Alert from '@mui/material/Alert';
import type { ReactNode } from 'react';

import type { components } from '../../api/schema';
import { useTranslation } from '../../i18n';

/**
 * Pieces the mailing list views share (A5).
 */

export type MailingList = components['schemas']['MailingList'];

/** The detail route of a list. The address is the identifier and is encoded. */
export function listPath(address: string): string {
  return `/lists/${encodeURIComponent(address)}`;
}

/** One member per line; empty lines dropped, order and duplicates kept (two entries are two deliveries). */
export function parseMembers(raw: string): string[] {
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '');
}

export function sameMembers(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((entry, index) => entry === b[index]);
}

/**
 * The fact every list view has to carry (U-59, E-4): mailadmin writes the
 * table, the configuration management renders the aliases file from it. Until
 * the next configuration run a new list does not deliver and a deleted one
 * still does.
 *
 * The pages state it permanently, because it is a property of the system, not
 * of one answer. The confirmation after a write follows the answer instead:
 * the contract says per response in `X-Mailadmin-Effect` whether the change
 * waits for a configuration run, and the client hands that header back
 * (`hasPendingConfigurationRun`).
 */
export function ConfigRunHint(): ReactNode {
  const { t } = useTranslation();

  return (
    <Alert severity="info" sx={{ mb: 3 }}>
      {t('list.hint.configRun')}
    </Alert>
  );
}
