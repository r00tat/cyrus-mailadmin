import ClearIcon from '@mui/icons-material/Clear';
import SearchIcon from '@mui/icons-material/Search';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import { useEffect, useRef, useState, type ReactNode } from 'react';

import { useTranslation } from '../i18n';

/**
 * A free-text filter for a server-side list.
 *
 * It keeps its own input and hands the value on after a short pause, so that
 * typing `example` asks the server once rather than seven times. Enter applies
 * at once. The value it is given (from the URL, via `useTableState`) wins when
 * it changes from outside — the back button, a link.
 */

export const FILTER_DELAY_MS = 300;

export function FilterField({
  label,
  value,
  onChange,
  maxLength = 255,
  errorText,
}: {
  /** Already translated, e.g. `t('domain.list.filter.name')`. */
  label: string;
  value: string | undefined;
  onChange: (value: string) => void;
  /** The `maxLength` of the filter parameter in the contract. */
  maxLength?: number;
  /** Why the value is not applied (already translated), e.g. it misses the contract's `pattern`. */
  errorText?: string | undefined;
}): ReactNode {
  const { t } = useTranslation();
  const [draft, setDraft] = useState(value ?? '');
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const latest = useRef(onChange);

  latest.current = onChange;

  // Follow the URL when it changes from elsewhere.
  useEffect(() => {
    setDraft(value ?? '');
  }, [value]);

  useEffect(
    () => () => {
      if (timer.current !== null) {
        clearTimeout(timer.current);
      }
    },
    [],
  );

  const commit = (next: string): void => {
    if (timer.current !== null) {
      clearTimeout(timer.current);
      timer.current = null;
    }

    if (next !== (value ?? '')) {
      latest.current(next);
    }
  };

  const schedule = (next: string): void => {
    if (timer.current !== null) {
      clearTimeout(timer.current);
    }

    timer.current = setTimeout(() => {
      timer.current = null;
      commit(next);
    }, FILTER_DELAY_MS);
  };

  return (
    <TextField
      label={label}
      type="search"
      value={draft}
      error={errorText !== undefined}
      helperText={errorText}
      onChange={(event) => {
        setDraft(event.target.value);
        schedule(event.target.value);
      }}
      onKeyDown={(event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          commit(draft);
        }
      }}
      slotProps={{
        htmlInput: { maxLength },
        input: {
          startAdornment: (
            <InputAdornment position="start">
              <SearchIcon fontSize="small" aria-hidden />
            </InputAdornment>
          ),
          endAdornment:
            draft === '' ? undefined : (
              <InputAdornment position="end">
                <IconButton
                  size="small"
                  edge="end"
                  aria-label={t('table.filter.clear')}
                  onClick={() => {
                    setDraft('');
                    commit('');
                  }}
                >
                  <ClearIcon fontSize="small" />
                </IconButton>
              </InputAdornment>
            ),
        },
      }}
    />
  );
}
