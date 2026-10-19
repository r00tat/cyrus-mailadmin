import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ApiProblem, type ProblemCode } from '../api/problem';

import { de } from './de';
import { createFormatters, createTranslator, defaultTranslator, interpolate, type TranslationKey } from './index';
import { negotiateLocale } from './locales';
import { describeUnknownError, useErrorCodeText } from './problemText';

describe('t()', () => {
  it('interpolates named placeholders and leaves unknown ones visible', () => {
    expect(defaultTranslator.t('overview.greeting', { username: 'anna' })).toBe('Angemeldet als anna.');
    expect(interpolate('{{a}} und {{b}}', { a: 'x' })).toBe('x und {{b}}');
  });

  it('formats numbers it interpolates for the language', () => {
    expect(interpolate('{{n}} Konten', { n: 12345 }, 'de')).toBe('12.345 Konten');
    expect(interpolate('{{n}} Konten', { n: '12345' }, 'de')).toBe('12345 Konten');
  });

  it('chooses the plural form by the CLDR rules of the language', () => {
    expect(defaultTranslator.t('overview.scopeCount', { count: 1 })).toBe('Eine Domain zugewiesen.');
    expect(defaultTranslator.t('overview.scopeCount', { count: 0 })).toBe('0 Domains zugewiesen.');
    expect(defaultTranslator.t('overview.scopeCount', { count: 2048 })).toBe('2.048 Domains zugewiesen.');
  });

  it('falls back to #other where a language has more categories than the catalogue', () => {
    // Polish has `few` and `many`; a catalogue that writes only `one` and
    // `other` still produces text rather than a key.
    const t = createTranslator({ 'x.y#one': 'one', 'x.y#other': '{{count}} other' }, 'pl').t;

    expect(t('x.y' as TranslationKey, { count: 3 })).toBe('3 other');
    expect(t('x.y' as TranslationKey, { count: 1 })).toBe('one');
  });

  it('shows the key itself rather than nothing when a text is missing', () => {
    const t = createTranslator({}, 'de').t;

    expect(t('signIn.title')).toBe('signIn.title');
  });
});

describe('the error mapping', () => {
  const problem = (code: string, detail?: string) =>
    new ApiProblem({
      status: 409,
      // Deliberately not narrowed: a newer backend sends codes this build's
      // types do not know, and that is the case under test.
      code: code as ProblemCode,
      title: 'Conflict',
      detail,
      errors: {},
      requestId: 'req-1',
    });

  it('translates on the code, not on the title', () => {
    const text = describeUnknownError(problem('domain.name_taken', 'A domain with this name exists.'), defaultTranslator);

    expect(text.message).toBe(de['errors.domain.name_taken']);
    expect(text.requestId).toBe('req-1');
  });

  it('translates the new account codes from A2', () => {
    expect(describeUnknownError(problem('account.limit_reached'), defaultTranslator).message)
      .toBe(de['errors.account.limit_reached']);
  });

  it('falls back to the detail for a code this build does not know', () => {
    const text = describeUnknownError(problem('domain.from_the_future', 'Something new went wrong.'), defaultTranslator);

    expect(text.message).toBe('Something new went wrong.');
  });

  it('falls back to the title when there is no detail, never to an empty line', () => {
    const text = describeUnknownError(problem('domain.from_the_future'), defaultTranslator);

    expect(text.message).toBe('Conflict');
  });

  it('has a sentence for anything that is not a problem document', () => {
    expect(describeUnknownError(new Error('boom'), defaultTranslator).message).toBe(de['errors.client.unknown']);
  });

  it('translates a bare code that arrives as a field, and shows an unknown one as it came', () => {
    const { result } = renderHook(() => useErrorCodeText());

    expect(result.current('domain.name_taken')).toBe(de['errors.domain.name_taken']);
    expect(result.current('mailstore.from_the_future')).toBe('mailstore.from_the_future');
  });
});

describe('the formatters', () => {
  const berlin = createFormatters(defaultTranslator, 'Europe/Berlin');

  it('show date and time in German form with the zone named', () => {
    expect(berlin.dateTime('2026-10-17T12:05:00Z')).toBe('17.10.2026, 14:05 MESZ');
    expect(berlin.dateTime('2026-01-17T12:05:00Z')).toBe('17.01.2026, 13:05 MEZ');
    expect(createFormatters(defaultTranslator, 'UTC').dateTime('2026-10-17T12:05:00Z')).toBe('17.10.2026, 12:05 UTC');
  });

  it('show a date alone', () => {
    expect(berlin.date('2026-10-17T23:30:00Z')).toBe('18.10.2026');
  });

  it('render an unreadable time as nothing rather than "Invalid Date"', () => {
    expect(berlin.dateTime('not a date')).toBe('');
  });

  it('format numbers with German separators', () => {
    expect(berlin.number(1234567.5)).toBe('1.234.567,5');
  });

  it('format sizes in binary steps', () => {
    expect(berlin.bytes(512)).toBe('512 B');
    expect(berlin.bytes(1536)).toBe('1,5 KiB');
    expect(berlin.bytes(1024 ** 3 * 2.25)).toBe('2,3 GiB');
    expect(berlin.kibibytes(1024 * 1024)).toBe('1 GiB');
    expect(berlin.bytes(1024 ** 5)).toBe('1.024 TiB');
  });

  it('move to the next unit when rounding reaches 1024', () => {
    expect(berlin.bytes(1048575)).toBe('1 MiB');
    expect(berlin.bytes(1023.96)).toBe('1 KiB');
    expect(berlin.kibibytes(1024 * 1024 - 1)).toBe('1 GiB');
    expect(berlin.bytes(1023.9 * 1024)).toBe('1.023,9 KiB');
  });

  it('show a date-only value as that calendar day in every zone', () => {
    expect(createFormatters(defaultTranslator, 'America/New_York').date('2026-10-17')).toBe('17.10.2026');
    expect(createFormatters(defaultTranslator, 'Pacific/Kiritimati').date('2026-10-17')).toBe('17.10.2026');
    expect(createFormatters(defaultTranslator, 'America/New_York').date('2026-10-17T02:00:00Z')).toBe('16.10.2026');
  });
});

describe('the language negotiation', () => {
  it('takes the first offered language from the browser list', () => {
    expect(negotiateLocale(['fr-FR', 'de-AT', 'en'])).toBe('de');
  });

  it('falls back to German when nothing matches', () => {
    expect(negotiateLocale(['fr', 'en-GB'])).toBe('de');
    expect(negotiateLocale([])).toBe('de');
  });
});
