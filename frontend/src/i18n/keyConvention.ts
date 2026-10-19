/**
 * Die Schlüsselkonvention als Code (U-88), damit sie geprüft und nicht nur
 * beschrieben wird. Bindend für alle späteren Ansichten.
 *
 * `<bereich>.<ansicht>.<element>[.<detail>]`, jedes Segment `lowerCamelCase`,
 * optional `#<CLDR-Pluralkategorie>` am Ende. Rahmenbereiche dürfen eine Ebene
 * flacher sein (`signIn.title`), weil sie selbst die Ansicht sind.
 *
 * Fehlertexte folgen den Codes des Vertrags (`errors.<ressource>.<zustand>`,
 * `snake_case`, U-7) und den Regelschlüsselwörtern des Validators
 * (`errors.field.maxLength`) — dort gilt die Form des Vertrags, nicht diese.
 */

/** Bereiche, die einer Ressource der API entsprechen — Singular, wie die Berechtigungsfamilien. */
export const RESOURCE_AREAS = [
  'domain',
  'account',
  'address',
  'admin',
  'list',
  'audit',
  'maintenance',
] as const;

/** Bereiche, die keine Ressource sind: der Rahmen der Anwendung. */
export const FRAME_AREAS = [
  'app',
  'nav',
  'signIn',
  'signOut',
  'overview',
  'preferences',
  'state',
  'role',
  'format',
  'action',
  // Die geteilten Bausteine unter `components/` (U-130).
  'table',
  'confirm',
] as const;

export const PLURAL_SEPARATOR = '#';

export const PLURAL_CATEGORIES: readonly Intl.LDMLPluralRule[] = [
  'zero',
  'one',
  'two',
  'few',
  'many',
  'other',
];

const SEGMENT = '[a-z][a-zA-Z0-9]*';
const PLURAL = `(?:${PLURAL_SEPARATOR}(?:${PLURAL_CATEGORIES.join('|')}))?`;

const INTERFACE_KEY = new RegExp(`^(${SEGMENT})((?:\\.${SEGMENT}){1,4})${PLURAL}$`);
const ERROR_KEY = /^errors\.[a-z][a-z0-9_]*\.[a-z][a-zA-Z0-9_]*$/;

/** Warum ein Schlüssel gegen die Konvention verstößt, oder `null`. */
export function conventionViolation(key: string): string | null {
  if (key.startsWith('errors.')) {
    return ERROR_KEY.test(key) ? null : 'error keys are errors.<resource>.<state>';
  }

  const match = INTERFACE_KEY.exec(key);

  if (match === null) {
    return 'expected <area>.<view>.<element> in lowerCamelCase, optionally #<plural category>';
  }

  const area = match[1] ?? '';

  if ((RESOURCE_AREAS as readonly string[]).includes(area)) {
    // A resource area always names the view as well: `account.title` would be
    // ambiguous between the list and the detail the moment both exist.
    const depth = (match[2] ?? '').split('.').length - 1;

    return depth >= 2 ? null : `a resource area needs <area>.<view>.<element>; "${key}" is too short`;
  }

  if ((FRAME_AREAS as readonly string[]).includes(area)) {
    return null;
  }

  return `unknown area "${area}" — add it to keyConvention.ts if it is meant to exist`;
}

/** Der Grundschlüssel einer Pluralform, oder der Schlüssel selbst. */
export function baseKey(key: string): string {
  const index = key.indexOf(PLURAL_SEPARATOR);

  return index === -1 ? key : key.slice(0, index);
}
