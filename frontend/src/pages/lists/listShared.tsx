import type { components } from '../../api/schema';
import { ApiProblem } from '../../api/problem';
import { errorAtOrBelow } from '../../components/form';
import type { TranslationKey, Translator } from '../../i18n';
import { describeUnknownError } from '../../i18n/problemText';

/**
 * Pieces the mailing list views share (A5).
 *
 * None of them states that a change waits for the next configuration run. That
 * holds only in alias delivery mode, and the server says it per answer in
 * `X-Mailadmin-Effect`; the confirmations follow that header
 * (`hasPendingConfigurationRun`), so the transport mode never shows it.
 */

export type MailingList = components['schemas']['MailingList'];

/** The detail route of a list. The address is the identifier and is encoded. */
export function listPath(address: string): string {
  return `/lists/${encodeURIComponent(address)}`;
}

/** One member per line; empty lines dropped, order and duplicates kept (two entries are two deliveries). */
export function parseMembers(raw: string): string[] {
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '');
}

export function sameMembers(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((entry, index) => entry === b[index]);
}

/**
 * The 1-based line of the text area that holds entry `index` of
 * `parseMembers(raw)` — empty lines are not sent, so the two can differ.
 */
export function memberLine(raw: string, index: number): number | undefined {
  let seen = 0;
  const lines = raw.split('\n');

  for (let line = 0; line < lines.length; line++) {
    if (lines[line]!.trim() === '') {
      continue;
    }

    if (seen === index) {
      return line + 1;
    }

    seen++;
  }

  return undefined;
}

/** Member codes reported at an entry's position, and the text that names its line. */
const MEMBER_LINE_TEXTS: Readonly<Partial<Record<string, TranslationKey>>> = {
  'list.member_outside_latin1': 'list.form.error.memberOutsideLatin1',
  'list.member_not_deliverable': 'list.form.error.memberNotDeliverable',
};

const MEMBER_ENTRY = /^body\.members\.(\d+)$/;

/**
 * The text under the member field for a failed write, or `undefined`.
 *
 * A member the `lists` column cannot store, or the delivery script cannot
 * deliver to, comes back at its position (`body.members.<n>`). It may be an entry stored long ago that nobody typed
 * in this form, so the text names the line it sits on in `raw`, the text area
 * as it was sent.
 */
export function membersFieldError(error: unknown, raw: string, translator: Translator): string | undefined {
  if (error === null) {
    return undefined;
  }

  if (error instanceof ApiProblem) {
    for (const [field, reason] of Object.entries(error.errors)) {
      const entry = MEMBER_ENTRY.exec(field);

      const key = MEMBER_LINE_TEXTS[reason];

      if (entry === null || key === undefined) {
        continue;
      }

      const line = memberLine(raw, Number(entry[1]));

      if (line !== undefined) {
        return translator.t(key, { line });
      }
    }
  }

  return errorAtOrBelow(describeUnknownError(error, translator).fieldErrors, 'body.members');
}
