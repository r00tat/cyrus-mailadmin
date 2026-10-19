import { useCallback, useState } from 'react';

import { hasCode } from '../accounts/labels';

/**
 * The second question before removing or disabling `postmaster` or `abuse`
 * (RFC 2142, U-109).
 *
 * `confirm_reserved=true` is never sent on the first attempt unless the
 * resource already says it is needed (`Alias.requires_confirmation`). When the
 * server answers `409 address.reserved`, the dialog asks again — explicitly,
 * naming the address — and only the second confirmation sends the flag. The
 * refusal itself is not shown as an error then: it is the question.
 */
export function useReservedConfirmation(known = false): {
  /** Send `confirm_reserved` with the next attempt. */
  readonly confirmed: boolean;
  /** The server refused for this reason and the user has to be asked. */
  readonly asking: boolean;
  /** Feed every failure through this. */
  readonly onError: (error: unknown) => void;
  /** The error to show: `undefined` while the reserved question replaces it. */
  readonly visibleError: (error: unknown) => unknown;
  readonly reset: () => void;
} {
  const [asking, setAsking] = useState(false);

  const onError = useCallback((error: unknown) => {
    if (hasCode(error, 'address.reserved')) {
      setAsking(true);
    }
  }, []);

  const visibleError = useCallback(
    (error: unknown) => ((asking || known) && hasCode(error, 'address.reserved') ? undefined : error),
    [asking, known],
  );

  const reset = useCallback(() => setAsking(false), []);

  return { confirmed: known || asking, asking: known || asking, onError, visibleError, reset };
}
