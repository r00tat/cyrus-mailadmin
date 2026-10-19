import { useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';

import { useSession } from '../../auth/SessionContext';

/**
 * The domain a page of this area works on, kept in the URL as `?domain=` —
 * the parameter other areas use to link here (`/accounts?domain=example.test`,
 * pages/README.md section 2).
 *
 * A domain master without a parameter starts on the first domain of their
 * scope; a superuser chooses. Changing the domain drops the page number, so
 * page 7 of one domain does not become page 7 of another.
 */
export function useDomainParam(): [string | undefined, (domain: string | null) => void] {
  const [searchParams, setSearchParams] = useSearchParams();
  const { session } = useSession();

  const raw = searchParams.get('domain');
  const fallback = session?.role === 'domainmaster' ? session.domains[0] : undefined;
  const domain = raw !== null && raw.trim() !== '' ? raw : fallback;

  const setDomain = useCallback(
    (next: string | null) => {
      setSearchParams(
        (current) => {
          const params = new URLSearchParams(current);

          if (next === null || next === '') {
            params.delete('domain');
          } else {
            params.set('domain', next);
          }

          params.delete('page');

          return params;
        },
        { replace: true },
      );
    },
    [setSearchParams],
  );

  return [domain, setDomain];
}
