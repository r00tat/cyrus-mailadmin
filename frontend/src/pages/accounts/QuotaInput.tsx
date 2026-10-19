import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormLabel from '@mui/material/FormLabel';
import MenuItem from '@mui/material/MenuItem';
import Radio from '@mui/material/Radio';
import RadioGroup from '@mui/material/RadioGroup';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useId, type ReactNode } from 'react';

import { useTranslation } from '../../i18n';

/**
 * A quota limit as a user enters it: an amount in MiB or GiB, "no limit", or —
 * when creating an account — "the domain's default". The API speaks kibibytes
 * (`SETQUOTA … STORAGE`), so the conversion lives here and nowhere else.
 *
 * Local to the accounts-addresses stream. The domain forms have their own
 * `QuotaField` with a different interaction; why the two stay apart is
 * decision U-132.
 *
 * Only the client half of the rules is here: an amount is required when a
 * limit is chosen. Whether it is large enough, or fits the domain's
 * allowance, is the server's answer (U-79, U-81).
 */

export type QuotaUnit = 'mebibyte' | 'gibibyte';

export interface QuotaChoice {
  readonly mode: 'default' | 'unlimited' | 'limit';
  /** The amount as typed, for `limit`. */
  readonly amount: string;
  readonly unit: QuotaUnit;
}

const KIB_PER_UNIT: Readonly<Record<QuotaUnit, number>> = {
  mebibyte: 1024,
  gibibyte: 1024 * 1024,
};

/**
 * The choice for an existing limit: GiB when it is a whole number of them,
 * otherwise MiB — **unrounded**. A KiB count divided by 1024 is a finite binary
 * fraction, so `String()` gives a decimal that `quotaKibOf` turns back into the
 * same KiB count. Rounding here would make the dialog write a different limit
 * back on a save that changed nothing (U-133).
 */
export function quotaChoiceOf(limitKib: number | null): QuotaChoice {
  if (limitKib === null) {
    return { mode: 'unlimited', amount: '', unit: 'gibibyte' };
  }

  if (limitKib % KIB_PER_UNIT.gibibyte === 0) {
    return { mode: 'limit', amount: String(limitKib / KIB_PER_UNIT.gibibyte), unit: 'gibibyte' };
  }

  return { mode: 'limit', amount: String(limitKib / KIB_PER_UNIT.mebibyte), unit: 'mebibyte' };
}

/**
 * The limit in KiB: `null` for no limit, `undefined` for the domain's default.
 * An amount that is not a number becomes `0`, which the server refuses with a
 * field error — the client does not invent a rule of its own.
 */
export function quotaKibOf(choice: QuotaChoice): number | null | undefined {
  switch (choice.mode) {
    case 'default':
      return undefined;
    case 'unlimited':
      return null;
    default: {
      const amount = Number.parseFloat(choice.amount.replace(',', '.'));

      return Number.isFinite(amount) ? Math.round(amount * KIB_PER_UNIT[choice.unit]) : 0;
    }
  }
}

export function QuotaInput({
  value,
  onChange,
  defaultLabel,
  error,
  helperText,
  disabled = false,
}: {
  value: QuotaChoice;
  onChange: (next: QuotaChoice) => void;
  /** Offer "the domain's default" with this label (already translated); omit to offer only a limit or none. */
  defaultLabel?: string;
  error?: boolean;
  helperText?: ReactNode;
  disabled?: boolean;
}): ReactNode {
  const { t } = useTranslation();
  const labelId = useId();

  return (
    <FormControl disabled={disabled}>
      <FormLabel id={labelId}>{t('account.quota.field.label')}</FormLabel>
      <RadioGroup
        aria-labelledby={labelId}
        value={value.mode}
        onChange={(event) => onChange({ ...value, mode: event.target.value as QuotaChoice['mode'] })}
      >
        {defaultLabel !== undefined && <FormControlLabel value="default" control={<Radio />} label={defaultLabel} />}
        <FormControlLabel value="unlimited" control={<Radio />} label={t('account.quota.field.unlimited')} />
        <FormControlLabel value="limit" control={<Radio />} label={t('account.quota.field.limit')} />
      </RadioGroup>
      {value.mode === 'limit' && (
        <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
          <TextField
            label={t('account.quota.field.amount')}
            type="number"
            required
            value={value.amount}
            onChange={(event) => onChange({ ...value, amount: event.target.value })}
            error={error === true}
            helperText={helperText}
            disabled={disabled}
            slotProps={{ htmlInput: { min: 1, step: 'any' } }}
          />
          <TextField
            select
            label={t('account.quota.field.unit')}
            value={value.unit}
            onChange={(event) => onChange({ ...value, unit: event.target.value as QuotaUnit })}
            disabled={disabled}
            sx={{ minWidth: 100 }}
          >
            <MenuItem value="mebibyte">{t('account.quota.unit.mebibyte')}</MenuItem>
            <MenuItem value="gibibyte">{t('account.quota.unit.gibibyte')}</MenuItem>
          </TextField>
        </Stack>
      )}
    </FormControl>
  );
}
