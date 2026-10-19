import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useId, type ReactNode } from 'react';

import { useFormatters, useTranslation, type TranslationKey } from '../../i18n';

/**
 * A size limit in KiB, entered as an amount and a unit.
 *
 * The contract counts in kibibytes because `SETQUOTA … STORAGE` does (U-94);
 * nobody thinks in them. The field converts on the way in and out and reads
 * the value back through `kibibytes()`, so a mistyped unit shows up as
 * "1 TiB" before it is saved. Empty means "no limit" (`null`).
 *
 * Built locally for A1 (U-130, section 1 of `pages/README.md`); the account
 * views have their own `QuotaInput` with a different interaction; why the two
 * stay apart is decision U-132.
 */

export type QuotaUnit = 'kib' | 'mib' | 'gib';

export interface QuotaInput {
  readonly amount: string;
  readonly unit: QuotaUnit;
}

const FACTOR: Readonly<Record<QuotaUnit, number>> = { kib: 1, mib: 1024, gib: 1024 * 1024 };

const UNIT_KEYS: Readonly<Record<QuotaUnit, TranslationKey>> = {
  kib: 'domain.quota.unit.kib',
  mib: 'domain.quota.unit.mib',
  gib: 'domain.quota.unit.gib',
};

const UNITS: readonly QuotaUnit[] = ['kib', 'mib', 'gib'];

/** The largest unit that shows the stored value without a fraction. */
export function quotaInputFrom(kib: number | null): QuotaInput {
  if (kib === null) {
    return { amount: '', unit: 'mib' };
  }

  if (kib > 0 && kib % FACTOR.gib === 0) {
    return { amount: String(kib / FACTOR.gib), unit: 'gib' };
  }

  if (kib % FACTOR.mib === 0) {
    return { amount: String(kib / FACTOR.mib), unit: 'mib' };
  }

  return { amount: String(kib), unit: 'kib' };
}

/** KiB, `null` for "no limit", `undefined` when the amount is not a number. */
export function parseQuota(input: QuotaInput): number | null | undefined {
  const raw = input.amount.trim().replace(',', '.');

  if (raw === '') {
    return null;
  }

  if (!/^\d{1,12}(\.\d{1,6})?$/.test(raw)) {
    return undefined;
  }

  return Math.round(Number(raw) * FACTOR[input.unit]);
}

export function QuotaField({
  label,
  value,
  onChange,
  disabled = false,
  error,
  helperText,
}: {
  /** Already translated. */
  label: string;
  value: QuotaInput;
  onChange: (value: QuotaInput) => void;
  disabled?: boolean;
  error: boolean;
  helperText: ReactNode;
}): ReactNode {
  const { t } = useTranslation();
  const { kibibytes } = useFormatters();
  const unitId = useId();

  const parsed = parseQuota(value);
  const readBack = parsed === null || parsed === undefined || error
    ? helperText
    : t('domain.quota.readBack', { value: kibibytes(parsed) });

  return (
    <Stack direction="row" spacing={1} alignItems="flex-start">
      <TextField
        label={label}
        value={value.amount}
        onChange={(event) => onChange({ ...value, amount: event.target.value })}
        disabled={disabled}
        error={error}
        helperText={readBack}
        slotProps={{ htmlInput: { inputMode: 'decimal' } }}
        sx={{ maxWidth: 240 }}
      />
      <TextField
        select
        id={unitId}
        label={t('domain.quota.unitLabel')}
        value={value.unit}
        onChange={(event) => onChange({ ...value, unit: event.target.value as QuotaUnit })}
        disabled={disabled}
        sx={{ minWidth: 110 }}
      >
        {UNITS.map((unit) => (
          <MenuItem key={unit} value={unit}>
            {t(UNIT_KEYS[unit])}
          </MenuItem>
        ))}
      </TextField>
    </Stack>
  );
}
