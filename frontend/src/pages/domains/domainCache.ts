import type { QueryClient } from '@tanstack/react-query';

/** The cache key of one domain's detail, shared by the detail and the edit view. */
export function domainDetailQueryKey(name: string): readonly unknown[] {
  return ['domains', 'detail', name];
}

/**
 * After a domain stopped existing under `name` — deleted or renamed.
 *
 * The detail under the old name is **removed before anything is invalidated**:
 * it is still observed by the page that is about to navigate away, and an
 * invalidation would refetch it and earn a `404` for a name that is gone. The
 * domain name also lives in the domain options of the administrator forms,
 * in every account's user name and address and in the administrators'
 * assignments, so those caches are marked stale too. Nothing is awaited: the
 * caller navigates right after, and the next view fetches what it shows
 * (U-133).
 */
export function forgetDomainName(queryClient: QueryClient, name: string): void {
  queryClient.removeQueries({ queryKey: domainDetailQueryKey(name), exact: true });

  for (const root of ['domains', 'accounts', 'addresses', 'admins']) {
    void queryClient.invalidateQueries({ queryKey: [root] });
  }
}
