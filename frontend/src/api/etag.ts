/**
 * Optimistic concurrency, client half (U-19, architecture 7.1).
 *
 * Every `GET` of a single resource answers with a weak `ETag` over exactly the
 * representation it returned. Every `PATCH` and `DELETE` that the contract
 * declares with `If-Match` **requires** it: without the header the backend
 * answers `428 request.precondition_required`, with a stale one
 * `412 request.precondition_failed`. That is how two administrators are kept
 * from overwriting each other — and why a view must send back the tag of what
 * the user actually looked at, not one fetched just before the write.
 *
 * Usage:
 *
 * ```ts
 * const { data, etag } = await client.getVersioned('/domains/{domain}', { pathParams });
 * await client.patch('/domains/{domain}', { pathParams, body, headers: ifMatch(etag) });
 * ```
 *
 * A `412` is not a failure to hide: the catalogue text says that someone else
 * changed the object, and the view reloads it so the user decides again.
 */

/** A response body together with the version it represents. */
export interface Versioned<T> {
  readonly data: T;
  /** The `ETag` header, verbatim (`W/"…"`), or `null` when the response had none. */
  readonly etag: string | null;
}

/**
 * The `If-Match` header for a conditional write.
 *
 * Throws when there is no tag. Sending the write anyway would earn a `428`
 * from the server; the real mistake is in the view, which loaded the object
 * through `get()` instead of `getVersioned()`, and that is where it should be
 * reported.
 */
export function ifMatch(etag: string | null | undefined): Record<string, string> {
  if (etag === null || etag === undefined || etag.trim() === '') {
    throw new Error(
      'No ETag to send as If-Match. Load the resource with getVersioned() before changing or deleting it (U-19).',
    );
  }

  return { 'If-Match': etag };
}

/** `true` when a write failed because the object changed since it was loaded. */
export function isVersionConflict(error: unknown): boolean {
  return (
    typeof error === 'object'
    && error !== null
    && 'code' in error
    && error.code === 'request.precondition_failed'
  );
}
