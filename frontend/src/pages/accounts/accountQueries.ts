import { useQuery } from '@tanstack/react-query';

import { useApiClient } from '../../api/ApiContext';

/**
 * The queries of one account, shared by the detail page and its sections so
 * that a change in one section updates the others from the same cache entry.
 */

export function accountKey(id: string): readonly unknown[] {
  return ['accounts', 'detail', id];
}

export function accountQuotaKey(id: string): readonly unknown[] {
  return ['accounts', 'quota', id];
}

/** The account with its `ETag` — every write on this page sends it back (U-19). */
export function useAccount(id: string) {
  const client = useApiClient();

  return useQuery({
    queryKey: accountKey(id),
    queryFn: ({ signal }) => client.getVersioned('/accounts/{account}', { pathParams: { account: id }, signal }),
  });
}

/**
 * Limit and usage, live from Cyrus (`GETQUOTAROOT`) — one IMAP round trip, so
 * a resource of its own and never part of a list (architecture 7.2).
 */
export function useAccountQuota(id: string, enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: accountQuotaKey(id),
    queryFn: ({ signal }) => client.get('/accounts/{account}/quota', { pathParams: { account: id }, signal }),
    enabled,
  });
}
