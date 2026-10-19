import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
  areaFileReport,
  compareKeys,
  contractEnum,
  conventionReport,
  errorCatalogueReport,
  placeholderReport,
  pluralReport,
  type SourceText,
  unusedKeys,
} from './catalogueCheck';
import { de, deAreas, deInterface } from './de';
import { RESOURCE_AREAS } from './keyConvention';
import { catalogues } from './locales';

/**
 * The check that keeps the language files from rotting (W2, U-89). It runs in
 * `npm test`, and every finding **fails** the run — a warning would be read
 * once and then never again.
 *
 * The contract half reads the **bundled** contract, a build artefact under
 * `var/cache` that is not checked in. When it is absent those tests **fail**
 * (U-93): a skipped check let `npm test` end green on a fresh checkout, and a
 * check that cannot run must never report success. Build the contract first,
 * as `npm run typecheck` and `npm run build` already require.
 */

// Relative to the vitest root, which is `frontend/`. `import.meta.url` is not
// usable here: under jsdom the module URL is an http one, not a file one.
const ROOT = process.cwd();
const BUNDLE = resolve(ROOT, '../var/cache/openapi.bundled.json');

function readBundle(): string | null {
  try {
    return readFileSync(BUNDLE, 'utf8');
  } catch {
    return null;
  }
}

/**
 * Directories that hold catalogues rather than code: `src/i18n/<locale>/` with
 * the per-area files (U-130). A key in a catalogue does not count as using it.
 */
const CATALOGUE_DIRECTORIES = new Set(Object.keys(catalogues).map((locale) => resolve(ROOT, 'src/i18n', locale)));

/** Every source file that may use a key: not tests, not catalogues, not generated types. */
function applicationSources(directory: string): SourceText[] {
  const found: SourceText[] = [];

  for (const entry of readdirSync(directory)) {
    const path = join(directory, entry);

    if (statSync(path).isDirectory()) {
      if (entry !== 'test' && !CATALOGUE_DIRECTORIES.has(resolve(path))) {
        found.push(...applicationSources(path));
      }

      continue;
    }

    const isSource = /\.(ts|tsx)$/.test(entry) && !/\.test\.(ts|tsx)$/.test(entry) && !entry.endsWith('.d.ts');
    const isCatalogue = /^de(\.[a-z]+)?\.ts$/.test(entry) || entry === 'catalogueCheck.ts';

    if (isSource && !isCatalogue) {
      found.push({ name: path, text: readFileSync(path, 'utf8') });
    }
  }

  return found;
}

describe('the catalogues against the contract', () => {
  it('translate every contract error code and nothing else', () => {
    const report = errorCatalogueReport(contractEnum(readBundle(), 'ErrorCode'), Object.keys(de));

    expect(report.missing, `No translation for: ${report.missing.join(', ')}`).toEqual([]);
    expect(report.surplus, `Translated but not in the contract: ${report.surplus.join(', ')}`).toEqual([]);
  });

  it('exist for exactly the languages the contract offers', () => {
    const report = compareKeys(contractEnum(readBundle(), 'InterfaceLocale'), Object.keys(catalogues));

    expect(report.missing, `InterfaceLocale without a catalogue: ${report.missing.join(', ')}`).toEqual([]);
    expect(report.surplus, `Catalogue the server would refuse to store: ${report.surplus.join(', ')}`).toEqual([]);
  });
});

describe('every catalogue', () => {
  for (const [locale, catalogue] of Object.entries(catalogues)) {
    it(`${locale}: has exactly the keys of the German one`, () => {
      const report = compareKeys(Object.keys(de), Object.keys(catalogue));

      expect(report).toEqual({ missing: [], surplus: [] });
    });

    it(`${locale}: has no empty text`, () => {
      const empty = Object.entries(catalogue)
        .filter(([, value]) => value.trim() === '')
        .map(([key]) => key);

      expect(empty).toEqual([]);
    });

    it(`${locale}: names every placeholder it interpolates`, () => {
      expect(placeholderReport(catalogue)).toEqual([]);
    });
  }
});

describe('the German catalogue', () => {
  it('follows the key convention (U-88)', () => {
    expect(conventionReport(Object.keys(de))).toEqual([]);
  });

  it('gives every plural group an #other and no bare base key', () => {
    expect(pluralReport(Object.keys(de))).toEqual([]);
  });

  it('keeps every area in its own file and out of the frame (U-130)', () => {
    expect(areaFileReport(RESOURCE_AREAS, deAreas, Object.keys(deInterface))).toEqual([]);
  });

  it('has no key that the application never uses', () => {
    const unused = unusedKeys(Object.keys(de), applicationSources(resolve(ROOT, 'src')));

    expect(unused, `Unused keys: ${unused.join(', ')}`).toEqual([]);
  });
});
