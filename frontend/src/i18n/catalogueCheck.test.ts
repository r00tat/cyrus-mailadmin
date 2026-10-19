import { describe, expect, it } from 'vitest';

import {
  areaFileReport,
  compareKeys,
  contractEnum,
  conventionReport,
  errorCatalogueReport,
  placeholderReport,
  pluralReport,
  unusedKeys,
} from './catalogueCheck';
import { de } from './de';

/**
 * Each check, shown to fire on a deliberately damaged catalogue. A check that
 * never reports anything cannot be told apart from one that does not work.
 */

describe('the catalogue checks', () => {
  it('report a key deliberately removed from a second language', () => {
    const damaged: Record<string, string> = { ...de };

    delete damaged['signIn.title'];

    expect(compareKeys(Object.keys(de), Object.keys(damaged))).toEqual({
      missing: ['signIn.title'],
      surplus: [],
    });
  });

  it('report a key a second language has and German does not', () => {
    const damaged = { ...de, 'signIn.extra': 'Extra' };

    expect(compareKeys(Object.keys(de), Object.keys(damaged)).surplus).toEqual(['signIn.extra']);
  });

  it('report a contract code without text, and a text without code', () => {
    const report = errorCatalogueReport(
      ['request.not_found', 'domain.brand_new'],
      ['errors.request.not_found', 'errors.domain.gone_away', 'errors.client.network', 'errors.field.required'],
    );

    expect(report).toEqual({ missing: ['errors.domain.brand_new'], surplus: ['errors.domain.gone_away'] });
  });

  it('report a key that breaks the convention', () => {
    expect(conventionReport([
      'account.list.column.quota',
      'signIn.title',
      'overview.scopeCount#one',
      'errors.request.not_found',
      'errors.field.maxLength',
      'Account.list.title',
      'account.title',
      'account.list.column_quota',
      'widget.list.title',
      'overview.count#several',
    ])).toEqual([
      expect.stringMatching(/^Account\.list\.title:/),
      expect.stringMatching(/^account\.title: a resource area/),
      expect.stringMatching(/^account\.list\.column_quota:/),
      expect.stringMatching(/^widget\.list\.title: unknown area/),
      expect.stringMatching(/^overview\.count#several:/),
    ]);
  });

  it('report a key that no source file names', () => {
    const sources = [
      { name: 'a.tsx', text: "t('signIn.title')" },
      { name: 'b.ts', text: "const KEYS = { a: \"role.superuser\" };" },
      { name: 'c.ts', text: 't(`overview.scopeCount`, { count })' },
    ];

    expect(unusedKeys(
      ['signIn.title', 'role.superuser', 'overview.scopeCount#one', 'overview.scopeCount#other', 'signIn.lost', 'errors.x.y'],
      sources,
    )).toEqual(['signIn.lost']);
  });

  it('report a plural group without #other, or with a bare base key beside it', () => {
    expect(pluralReport(['a.b#one', 'c.d#one', 'c.d#other', 'e.f', 'e.f#other'])).toEqual(['a.b', 'e.f']);
  });

  it('report an unnamed placeholder', () => {
    expect(placeholderReport({ 'a.b': 'Hallo {{}}', 'c.d': 'Hallo {{ name }}' })).toEqual(['a.b']);
  });

  it('report a key that only a comment or a doc block names', () => {
    const sources = [
      {
        name: 'a.tsx',
        text: [
          "// t('signIn.title') used to be here",
          "/** Example: t('overview.scopeCount', { count }) */",
          "const note = 'see role.superuser'; /* 'role.domainmaster' */",
          "export const View = () => <p title=\"signIn.used\">{t('signIn.alsoUsed')}</p>;",
        ].join('\n'),
      },
    ];

    expect(unusedKeys(
      ['signIn.title', 'overview.scopeCount#other', 'role.superuser', 'role.domainmaster', 'signIn.used', 'signIn.alsoUsed'],
      sources,
    )).toEqual(['overview.scopeCount', 'role.domainmaster', 'role.superuser', 'signIn.title']);
  });

  it('refuse to report success when the contract bundle is missing', () => {
    expect(() => contractEnum(null, 'ErrorCode')).toThrow(/openapi\.bundled\.json/);
  });

  it('refuse to report success when the bundle lacks the enum', () => {
    const bundle = JSON.stringify({ components: { schemas: { Other: { enum: ['x'] } } } });

    expect(() => contractEnum(bundle, 'ErrorCode')).toThrow(/ErrorCode/);
  });

  it('report a key filed under the wrong area, an area key in the frame, and a missing area file (U-130)', () => {
    const report = areaFileReport(
      ['domain', 'account', 'audit'],
      {
        domain: { 'domain.list.title': 'Domains', 'account.list.title': 'Konten' },
        account: { 'account.detail.title': 'Konto' },
        overview: { 'overview.extra.title': 'x' },
      },
      ['nav.domains', 'domain.list.empty'],
    );

    expect(report).toEqual([
      'audit: resource area without its own catalogue file',
      'account.list.title: does not belong in the file of area "domain"',
      'overview: catalogue file for something that is not a resource area',
      'domain.list.empty: belongs in the file of area "domain", not in the frame',
    ]);
  });

  it('accept area files that keep to their own prefix', () => {
    expect(areaFileReport(['domain'], { domain: { 'domain.list.title': 'Domains' } }, ['nav.domains'])).toEqual([]);
  });

  it('read an enum out of the contract bundle', () => {
    const bundle = JSON.stringify({ components: { schemas: { InterfaceLocale: { type: 'string', enum: ['de', 'en'] } } } });

    expect(contractEnum(bundle, 'InterfaceLocale')).toEqual(['de', 'en']);
  });
});
