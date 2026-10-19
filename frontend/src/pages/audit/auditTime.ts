/**
 * The bridge between the browser's `datetime-local` input and the `since` /
 * `until` filters of `GET /audit`.
 *
 * The input speaks the browser's local time without a zone
 * (`2026-10-17T14:05`); the contract wants RFC 3339 **with** a zone and reads
 * it in UTC (U-65). The URL keeps the UTC form, so a shared link means the
 * same moment for a colleague in another zone (U-92) — each sees it in their
 * own time, with the zone named.
 *
 * `until` is inclusive and the input has minute resolution, so "until 14:05"
 * is sent as 14:05:59 — the whole minute the administrator picked, not just
 * its first second.
 */

const RFC3339 = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/;

const LOCAL_INPUT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;

function pad(value: number): string {
  return String(value).padStart(2, '0');
}

/** A URL value the server will accept, or `undefined` — the URL is user input. */
export function validInstant(value: string | undefined): string | undefined {
  if (value === undefined || !RFC3339.test(value) || Number.isNaN(Date.parse(value))) {
    return undefined;
  }

  return value;
}

/** The input's value (local wall-clock time) for a stored instant. */
export function toLocalInput(value: string | undefined): string {
  const instant = validInstant(value);

  if (instant === undefined) {
    return '';
  }

  const date = new Date(instant);

  return `${String(date.getFullYear())}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** The instant to store for an input value; `''` clears the filter. */
export function fromLocalInput(value: string, bound: 'since' | 'until'): string {
  if (!LOCAL_INPUT.test(value)) {
    return '';
  }

  // Without a zone, `Date` reads a date-time string as local time — which is
  // exactly what the input means.
  const date = new Date(`${value}:${bound === 'until' ? '59' : '00'}`);

  if (Number.isNaN(date.getTime())) {
    return '';
  }

  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** The time zone the browser shows times in, for the hint above the list. */
export function browserTimeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone;
}
