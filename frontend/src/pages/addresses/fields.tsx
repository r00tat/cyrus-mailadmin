import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import type { ReactNode } from 'react';

import { useTranslation } from '../../i18n';

import { parseDestinations } from './labels';

/**
 * The two inputs every `virtual` form of this area has: the destinations and
 * the account that may send as the address (U-106).
 */

/** Field error for the destinations: the array as a whole or its first bad item. */
export function destinationsError(fieldError: (path: string) => string | undefined): string | undefined {
  const whole = fieldError('body.destinations');

  if (whole !== undefined) {
    return whole;
  }

  // At most 50 destinations (contract, `AddressDestinations`).
  for (let index = 0; index < 50; index += 1) {
    const item = fieldError(`body.destinations.${String(index)}`);

    if (item !== undefined) {
      return item;
    }
  }

  return undefined;
}

export function DestinationsField({
  value,
  onChange,
  error,
  hint,
  disabled = false,
}: {
  value: string;
  onChange: (value: string) => void;
  error: string | undefined;
  /** Already translated; replaced by the error while there is one. */
  hint?: string;
  disabled?: boolean;
}): ReactNode {
  const { t } = useTranslation();

  return (
    <TextField
      label={t('address.form.destinations')}
      required
      multiline
      minRows={2}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      error={error !== undefined}
      helperText={error ?? hint ?? t('address.form.destinationsHint')}
      disabled={disabled}
      slotProps={{ htmlInput: { spellCheck: false, autoCapitalize: 'off' } }}
    />
  );
}

/**
 * Who may send as the address. `auto` exists only when creating an alias:
 * the server then grants it to the only destination when that is an account
 * (U-106). Otherwise the choice is nobody or one of the destinations — the
 * sending account must be among them.
 */
export type SenderChoice = { readonly mode: 'auto' } | { readonly mode: 'none' } | { readonly mode: 'account'; readonly account: string };

const AUTO = 'auto';
const NONE = 'none';
// Destinations are prefixed so that one called `auto` or `none` stays distinct.
const ACCOUNT_PREFIX = '=';

export function senderChoiceOf(account: string | null): SenderChoice {
  return account === null ? { mode: 'none' } : { mode: 'account', account };
}

/** The request value: `undefined` (absent) for `auto`, `null` for nobody. */
export function senderValueOf(choice: SenderChoice): string | null | undefined {
  switch (choice.mode) {
    case 'auto':
      return undefined;
    case 'none':
      return null;
    default:
      return choice.account;
  }
}

export function SenderField({
  value,
  onChange,
  destinations,
  offerAuto = false,
  error,
  disabled = false,
}: {
  value: SenderChoice;
  onChange: (value: SenderChoice) => void;
  /** The destinations as currently typed. */
  destinations: string;
  offerAuto?: boolean;
  error: string | undefined;
  disabled?: boolean;
}): ReactNode {
  const { t } = useTranslation();
  const options = [...new Set(parseDestinations(destinations))];

  // Keep a stored sender visible even when it is no longer typed as a destination.
  if (value.mode === 'account' && !options.includes(value.account)) {
    options.push(value.account);
  }

  const selected = value.mode === 'account' ? `${ACCOUNT_PREFIX}${value.account}` : value.mode;

  return (
    <TextField
      select
      label={t('address.form.sender')}
      value={selected}
      onChange={(event) => {
        const raw = event.target.value;

        if (raw === AUTO) {
          onChange({ mode: 'auto' });
        } else if (raw === NONE) {
          onChange({ mode: 'none' });
        } else {
          onChange({ mode: 'account', account: raw.slice(ACCOUNT_PREFIX.length) });
        }
      }}
      error={error !== undefined}
      helperText={error ?? t('address.form.senderHint')}
      disabled={disabled}
    >
      {offerAuto && <MenuItem value={AUTO}>{t('address.form.senderAuto')}</MenuItem>}
      <MenuItem value={NONE}>{t('address.form.senderNone')}</MenuItem>
      {options.map((destination) => (
        <MenuItem key={destination} value={`${ACCOUNT_PREFIX}${destination}`}>
          {destination}
        </MenuItem>
      ))}
    </TextField>
  );
}
