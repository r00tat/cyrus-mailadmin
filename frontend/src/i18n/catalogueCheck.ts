import ts from 'typescript';

import { PLURAL_SEPARATOR, baseKey, conventionViolation } from './keyConvention';

/**
 * Die Prüfungen über Sprachdateien als reine Funktionen (W2, U-89).
 *
 * Ausgeführt werden sie in `catalogue.test.ts` und damit in `npm test`: ein
 * fehlender oder überzähliger Schlüssel **bricht** den Lauf, er warnt nicht.
 * Dass sie hier stehen und nicht im Test, hat einen Grund: so lässt sich jede
 * Prüfung an einem absichtlich beschädigten Katalog zeigen
 * (`catalogueCheck.test.ts`) — eine Prüfung, die nie anschlägt, ist von einer
 * kaputten nicht zu unterscheiden.
 */

export interface KeyReport {
  /** Erwartet, aber nicht vorhanden. */
  readonly missing: readonly string[];
  /** Vorhanden, aber nicht erwartet. */
  readonly surplus: readonly string[];
}

export function compareKeys(expected: Iterable<string>, actual: Iterable<string>): KeyReport {
  const want = new Set(expected);
  const have = new Set(actual);

  return {
    missing: [...want].filter((key) => !have.has(key)).sort(),
    surplus: [...have].filter((key) => !want.has(key)).sort(),
  };
}

/**
 * Namensräume unter `errors.`, die nicht aus dem Vertrag stammen: Zustände im
 * Browser und die Regelschlüsselwörter in `Problem.errors`. Keiner kollidiert
 * mit einem Code — U-7 kennt kein Präfix `client` oder `field`.
 */
const CLIENT_ERROR_NAMESPACES = ['errors.client.', 'errors.field.'];

/**
 * Fehlercodes des Vertrags gegen die Fehlertexte: jeder Code braucht einen
 * Text, jeder Text einen Code.
 */
export function errorCatalogueReport(contractCodes: readonly string[], keys: Iterable<string>): KeyReport {
  const translated = [...keys]
    .filter((key) => key.startsWith('errors.'))
    .filter((key) => !CLIENT_ERROR_NAMESPACES.some((prefix) => key.startsWith(prefix)));

  return compareKeys(contractCodes.map((code) => `errors.${code}`), translated);
}

/** Schlüssel, die gegen die Konvention verstoßen, mit Grund. */
export function conventionReport(keys: Iterable<string>): readonly string[] {
  const problems: string[] = [];

  for (const key of keys) {
    const violation = conventionViolation(key);

    if (violation !== null) {
      problems.push(`${key}: ${violation}`);
    }
  }

  return problems;
}

/** Eine Quelldatei für die Suche nach Schlüsseln; der Name bestimmt, ob JSX gilt. */
export interface SourceText {
  readonly name: string;
  readonly text: string;
}

/**
 * Alle vollständigen Zeichenkettenliterale einer Quelldatei: `'…'`, `"…"`,
 * JSX-Attributwerte und Template-Literale ohne Einsetzung.
 *
 * Gelesen wird über den Parser von TypeScript (schon Entwicklungsabhängigkeit),
 * nicht per Textsuche: ein Schlüssel, der nur noch in einem Kommentar oder
 * einem Doc-Block steht, gilt so nicht als verwendet (Review W2).
 */
function stringLiterals(source: SourceText): Set<string> {
  const kind = source.name.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS;
  const file = ts.createSourceFile(source.name, source.text, ts.ScriptTarget.Latest, false, kind);
  const found = new Set<string>();

  const visit = (node: ts.Node): void => {
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
      found.add(node.text);
    }

    ts.forEachChild(node, visit);
  };

  visit(file);

  return found;
}

/**
 * Oberflächenschlüssel, die im Code nirgends wörtlich stehen — die
 * „überzähligen" Schlüssel einer Sprachdatei.
 *
 * Gesucht wird der Grundschlüssel als Zeichenkettenliteral **im Code**;
 * Kommentare zählen nicht. Deshalb gilt für Aufrufstellen: **Schlüssel
 * wörtlich hinschreiben**, auch in Zuordnungstabellen (`ROLE_KEYS`), nie
 * zusammensetzen. `errors.*` ist ausgenommen; diese Schlüssel entstehen per
 * Konstruktion aus dem `code` einer Antwort und werden gegen den Vertrag
 * geprüft.
 */
export function unusedKeys(keys: Iterable<string>, sources: readonly SourceText[]): readonly string[] {
  const literals = new Set<string>();

  for (const source of sources) {
    for (const literal of stringLiterals(source)) {
      literals.add(literal);
    }
  }

  const unused = new Set<string>();

  for (const key of keys) {
    if (key.startsWith('errors.')) {
      continue;
    }

    const base = baseKey(key);

    if (!literals.has(base)) {
      unused.add(base);
    }
  }

  return [...unused].sort();
}

interface Bundle {
  components?: { schemas?: Record<string, { enum?: unknown } | undefined> };
}

/**
 * Die Werte einer Aufzählung aus dem gebündelten Vertrag
 * (`var/cache/openapi.bundled.json`).
 *
 * Fehlt das Bündel oder die Aufzählung, **wirft** die Funktion, statt die
 * Vertragsprüfung zu überspringen: ein übersprungener Test ließ `npm test`
 * auf einem frischen Checkout grün enden, ohne dass geprüft wurde (U-93).
 * `bundleText` ist `null`, wenn die Datei nicht gelesen werden konnte.
 */
export function contractEnum(bundleText: string | null, name: string): string[] {
  if (bundleText === null) {
    throw new Error(
      'var/cache/openapi.bundled.json is missing. Build the contract first '
      + '(php bin/openapi-bundle.php); the catalogue checks against the contract cannot be skipped.',
    );
  }

  const values = (JSON.parse(bundleText) as Bundle).components?.schemas?.[name]?.enum;

  if (!Array.isArray(values)) {
    throw new Error(`The contract bundle has no enum ${name}.`);
  }

  return values.filter((value): value is string => typeof value === 'string');
}

/** Pluralgruppen ohne `#other` — die eine Kategorie, die jede Sprache hat. */
export function pluralReport(keys: Iterable<string>): readonly string[] {
  const all = new Set(keys);
  const bases = new Set([...all].filter((key) => key.includes(PLURAL_SEPARATOR)).map(baseKey));

  return [...bases]
    .filter((base) => !all.has(`${base}${PLURAL_SEPARATOR}other`) || all.has(base))
    .sort();
}

/** Platzhalter, deren Name nicht der Form `{{name}}` folgt. */
export function placeholderReport(catalogue: Readonly<Record<string, string>>): readonly string[] {
  const problems: string[] = [];

  for (const [key, value] of Object.entries(catalogue)) {
    for (const match of value.matchAll(/\{\{([^}]*)\}\}/g)) {
      if (!/^\s*[a-zA-Z0-9_]+\s*$/.test(match[1] ?? '')) {
        problems.push(key);
      }
    }
  }

  return problems;
}

/**
 * Die Aufteilung der Sprachdatei nach Bereichen (U-130).
 *
 * Gemeldet wird
 * - ein Ressourcenbereich ohne eigene Datei und eine Datei für einen Bereich,
 *   der keiner ist,
 * - ein Schlüssel in `de/<bereich>.ts`, der nicht mit `<bereich>.` beginnt,
 * - ein Schlüssel eines Ressourcenbereichs im Rahmen (`deInterface`).
 *
 * Zusammen heißt das: jeder Schlüssel hat genau eine Datei, die ihn tragen
 * darf. Zwei parallel arbeitende Bereiche können sich so weder in einer Datei
 * noch über einen doppelten Schlüssel ins Gehege kommen — beim Zusammenfügen
 * mit `...` würde ein doppelter Schlüssel sonst still den anderen überschreiben.
 */
export function areaFileReport(
  resourceAreas: readonly string[],
  areaFiles: Readonly<Record<string, Readonly<Record<string, string>>>>,
  frameKeys: Iterable<string>,
): readonly string[] {
  const problems: string[] = [];

  for (const area of resourceAreas) {
    if (!Object.hasOwn(areaFiles, area)) {
      problems.push(`${area}: resource area without its own catalogue file`);
    }
  }

  for (const [area, catalogue] of Object.entries(areaFiles)) {
    if (!resourceAreas.includes(area)) {
      problems.push(`${area}: catalogue file for something that is not a resource area`);
    }

    for (const key of Object.keys(catalogue)) {
      if (!key.startsWith(`${area}.`)) {
        problems.push(`${key}: does not belong in the file of area "${area}"`);
      }
    }
  }

  for (const key of frameKeys) {
    const area = key.split('.')[0] ?? '';

    if (resourceAreas.includes(area)) {
      problems.push(`${key}: belongs in the file of area "${area}", not in the frame`);
    }
  }

  return problems;
}
