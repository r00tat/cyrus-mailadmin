import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import { useCallback, useMemo, type ReactNode } from 'react';

import { useTranslation } from '../i18n';
import { describeUnknownError } from '../i18n/problemText';

/**
 * Form helpers — by hand, without a form library (U-73).
 *
 * The division of labour is fixed: **the client checks required fields, the
 * server checks everything else** (U-9 validates against the contract). A
 * second set of rules in the browser would drift from the schema. What the
 * server rejects comes back as `Problem.errors`, keyed by field path
 * (`body.username`, `body.services.imap`) with the validator's keyword as the
 * reason; `describeUnknownError` translates the reason through
 * `errors.field.<keyword>`, and the helpers here put it on the field.
 *
 * Field paths are the contract's: `body.<property>` for a request body,
 * `query.<name>` for a query parameter. Use the same path for the client-side
 * required check, so one lookup serves both.
 *
 * ```tsx
 * const [clientErrors, setClientErrors] = useState<FieldErrors>({});
 * const required = useRequiredCheck();
 * const fieldError = useFieldErrors(mutation.error, clientErrors);
 *
 * const submit = (event) => {
 *   event.preventDefault();
 *   const missing = required({ 'body.name': name });
 *   setClientErrors(missing);
 *   if (Object.keys(missing).length === 0) mutation.mutate({ name });
 * };
 *
 * <TextField label={t('domain.create.field.name')} required value={name} … {...fieldProps(fieldError, 'body.name')} />
 * <ProblemAlert error={mutation.error} />
 * <FormActions pending={mutation.isPending} onCancel={close} />
 * ```
 *
 * Inputs stay as they are when a request fails, including after a session
 * expiry (U-74); nothing here stores them anywhere.
 */

/** Field path → translated reason. */
export type FieldErrors = Readonly<Record<string, string>>;

/**
 * The error text for a field path, or `undefined`. A client-side error wins
 * over a server one, because it is the newer statement about the input.
 */
export function useFieldErrors(
  error: unknown,
  clientErrors: FieldErrors = {},
): (path: string) => string | undefined {
  const translator = useTranslation();

  const serverErrors = useMemo(
    () => (error === null || error === undefined ? {} : describeUnknownError(error, translator).fieldErrors),
    [error, translator],
  );

  return useCallback(
    (path: string) => clientErrors[path] ?? serverErrors[path],
    [clientErrors, serverErrors],
  );
}

/**
 * The client-side check: every path whose value is empty — `undefined`, `null`
 * or a string of only whitespace — gets the text of `errors.field.required`,
 * the same text the server's `required` produces.
 */
export function useRequiredCheck(): (values: Readonly<Record<string, unknown>>) => FieldErrors {
  const { t } = useTranslation();

  return useCallback(
    (values) => {
      const missing: Record<string, string> = {};

      for (const [path, value] of Object.entries(values)) {
        if (value === undefined || value === null || (typeof value === 'string' && value.trim() === '')) {
          missing[path] = t('errors.field.required');
        }
      }

      return missing;
    },
    [t],
  );
}

/**
 * `error` and `helperText` for an MUI `TextField`. A hint the field shows
 * normally (`hint`) is replaced by the error while there is one.
 */
export function fieldProps(
  fieldError: (path: string) => string | undefined,
  path: string,
  hint?: ReactNode,
): { error: boolean; helperText: ReactNode } {
  const message = fieldError(path);

  return { error: message !== undefined, helperText: message ?? hint };
}

/**
 * Cancel and submit, in that order, right-aligned. The submit button is a real
 * `type="submit"`, so Enter in a field submits the form.
 */
export function FormActions({
  pending = false,
  submitLabel,
  onCancel,
  disabled = false,
}: {
  pending?: boolean;
  /** Defaults to `action.save`. */
  submitLabel?: string;
  onCancel?: () => void;
  disabled?: boolean;
}): ReactNode {
  const { t } = useTranslation();

  return (
    <Stack direction="row" spacing={1} justifyContent="flex-end">
      {onCancel !== undefined && (
        <Button onClick={onCancel} disabled={pending}>
          {t('action.cancel')}
        </Button>
      )}
      <Button type="submit" variant="contained" disabled={pending || disabled}>
        {pending ? t('action.saving') : (submitLabel ?? t('action.save'))}
      </Button>
    </Stack>
  );
}

/**
 * The first error at `prefix` or below it. The server reports one bad entry of
 * a list as `body.default_folders.3` or `body.members.2`; the form has one
 * field for the whole list, and that field shows it.
 */
export function errorAtOrBelow(errors: Readonly<Record<string, string>>, prefix: string): string | undefined {
  const key = Object.keys(errors).find((path) => path === prefix || path.startsWith(`${prefix}.`));

  return key === undefined ? undefined : errors[key];
}
