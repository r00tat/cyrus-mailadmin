import { createContext, useContext, useMemo } from 'react';

import { de } from './de';
import { PLURAL_SEPARATOR } from './keyConvention';

/**
 * Die Auflösung von Textschlüsseln (W2).
 *
 * **Eine eigene, schlanke Lösung statt `react-i18next`** (U-87). Gebraucht
 * werden Platzhalter, Pluralformen und eine Sprache je Sitzung — das sind
 * `Intl.PluralRules`, `Intl.NumberFormat` und vierzig Zeilen. Laden zur
 * Laufzeit, Namensräume und Spracherkennungs-Plugins braucht ein Werkzeug mit
 * einer Handvoll Ansichten und einer ausgelieferten Sprache nicht, und eine
 * neue Abhängigkeit wäre ein Eskalationsfall (E-12).
 *
 * Die Signatur ist die von W1 und bleibt: `useTranslation()` liefert
 * `{ t, language }`, `t('schlüssel', { name: 'wert' })`, Platzhalter als
 * `{{name}}`. Neu ist `count`: mit ihm wählt `t()` die Pluralform
 * `<schlüssel>#<kategorie>` nach den CLDR-Regeln der Sprache.
 */

type CatalogueKey = Extract<keyof typeof de, string>;

type PluralBase<K extends string> = K extends `${infer Base}${typeof PLURAL_SEPARATOR}${Intl.LDMLPluralRule}`
  ? Base
  : never;

/**
 * Was `t()` annimmt: jeder Schlüssel der Sprachdatei, wobei Pluralformen über
 * ihren Grundschlüssel aufgerufen werden — `t('overview.scopeCount', { count })`,
 * nie `t('overview.scopeCount#one')`.
 */
export type TranslationKey =
  | Exclude<CatalogueKey, `${string}${typeof PLURAL_SEPARATOR}${string}`>
  | PluralBase<CatalogueKey>;

export type TranslationValues = Readonly<Record<string, string | number>>;

export interface Translator {
  /**
   * Löst einen Schlüssel auf und setzt `{{name}}`-Platzhalter ein. Ist `count`
   * eine Zahl, wird die passende Pluralform gewählt.
   *
   * Als Eigenschaft deklariert, nicht als Methode: `const { t } = useTranslation()`
   * ist die übliche Schreibweise, und eine Methode dürfte so nicht vom Objekt
   * getrennt werden.
   */
  readonly t: (key: TranslationKey, values?: TranslationValues) => string;
  /** IETF-Sprachkennung, für `Intl` und das `lang`-Attribut. */
  readonly language: string;
}

const PLACEHOLDER = /\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g;

/**
 * Setzt Werte ein. Zahlen werden nach der Sprache formatiert — `1.234 Domains`,
 * nicht `1234 Domains`; wer eine Zahl unformatiert will (eine Jahreszahl, eine
 * Kennung), übergibt sie als Zeichenkette.
 */
export function interpolate(
  template: string,
  values: TranslationValues | undefined,
  language = 'de',
): string {
  if (values === undefined) {
    return template;
  }

  return template.replace(PLACEHOLDER, (match, name: string) => {
    const value = values[name];

    if (value === undefined) {
      return match;
    }

    return typeof value === 'number' ? new Intl.NumberFormat(language).format(value) : value;
  });
}

export function createTranslator(
  catalogue: Readonly<Record<string, string>>,
  language: string,
): Translator {
  const plurals = new Intl.PluralRules(language);

  const lookup = (key: string, values: TranslationValues | undefined): string | undefined => {
    const count = values?.['count'];

    if (typeof count === 'number') {
      const category = plurals.select(count);

      // `other` is the one category every language has; a catalogue that
      // writes only `#one` and `#other` covers German and English completely.
      return catalogue[`${key}${PLURAL_SEPARATOR}${category}`]
        ?? catalogue[`${key}${PLURAL_SEPARATOR}other`]
        ?? catalogue[key];
    }

    return catalogue[key];
  };

  return {
    language,
    t: (key: TranslationKey, values?: TranslationValues): string => {
      const template = lookup(key, values);

      if (template === undefined) {
        // Ein fehlender Schlüssel ist ein Fehler in der Sprachdatei, kein
        // Grund, die Oberfläche anzuhalten. Sichtbar bleibt der Schlüssel —
        // hässlich genug, um aufzufallen, harmlos genug, um weiterzuarbeiten.
        // Dass es dazu kommt, verhindern Typprüfung und `catalogue.test.ts`.
        if (import.meta.env.DEV) {
          console.warn(`i18n: kein Text für den Schlüssel "${key}".`);
        }

        return key;
      }

      return interpolate(template, values, language);
    },
  };
}

export const defaultTranslator: Translator = createTranslator(de, 'de');

const TranslationContext = createContext<Translator>(defaultTranslator);

export const TranslationProvider = TranslationContext.Provider;

export function useTranslation(): Translator {
  return useContext(TranslationContext);
}

// --- Formate ------------------------------------------------------------------

export interface Formatters {
  /** Datum und Uhrzeit mit Zeitzonenkürzel: `17.10.2026, 14:05 MESZ`. */
  readonly dateTime: (value: Date | string) => string;
  /** Nur das Datum: `17.10.2026`. */
  readonly date: (value: Date | string) => string;
  readonly number: (value: number) => string;
  /** Eine Größe in Byte, in binären Stufen: `1,5 GiB`. */
  readonly bytes: (value: number) => string;
  /** Eine Größe in KiB, wie Cyrus und `domain.quota` sie angeben. */
  readonly kibibytes: (value: number) => string;
}

const SIZE_KEYS = [
  'format.size.byte',
  'format.size.kibibyte',
  'format.size.mebibyte',
  'format.size.gibibyte',
  'format.size.tebibyte',
] as const satisfies readonly TranslationKey[];

function toDate(value: Date | string): Date | null {
  const date = typeof value === 'string' ? new Date(value) : value;

  return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Ein reines Kalenderdatum (`2026-10-17`, OpenAPI `format: date`). `Date`
 * liest es als Mitternacht UTC; in der Zone des Browsers formatiert, wäre es
 * westlich von UTC der Vortag. Es wird deshalb in UTC formatiert — es ist ein
 * Tag, kein Zeitpunkt (U-94).
 */
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Zahlen, Zeiten und Größen über `Intl` (Architektur 11.4).
 *
 * **Zeitzone (U-92):** die des Browsers. Die API liefert UTC mit `Z`; gezeigt
 * wird die Ortszeit dessen, der hinsieht, und zwar immer mit Zonenkürzel —
 * damit zwei Verwalter in verschiedenen Zonen, die über einen Protokolleintrag
 * sprechen, merken, dass sie verschiedene Uhren lesen. `timeZone` gibt es nur
 * für Tests, die eine feste Zone brauchen.
 */
export function createFormatters(translator: Translator, timeZone?: string): Formatters {
  const { language, t } = translator;

  const dateTimeFormat = new Intl.DateTimeFormat(language, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
    ...(timeZone === undefined ? {} : { timeZone }),
  });
  const dateFormat = new Intl.DateTimeFormat(language, {
    dateStyle: 'medium',
    ...(timeZone === undefined ? {} : { timeZone }),
  });
  const calendarDateFormat = new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeZone: 'UTC' });
  const numberFormat = new Intl.NumberFormat(language);
  const sizeFormat = new Intl.NumberFormat(language, { maximumFractionDigits: 1 });

  // The unit is chosen on the value as it will be shown, rounded to one
  // decimal like `sizeFormat` — otherwise 1048575 B is `1.024 KiB`, not `1 MiB`.
  const shown = (value: number): number => Math.round(value * 10) / 10;

  const bytes = (value: number): string => {
    let scaled = value;
    let step = 0;

    while (Math.abs(shown(scaled)) >= 1024 && step < SIZE_KEYS.length - 1) {
      scaled /= 1024;
      step += 1;
    }

    return t(SIZE_KEYS[step] ?? 'format.size.byte', { value: sizeFormat.format(scaled) });
  };

  return {
    dateTime: (value) => {
      const date = toDate(value);

      return date === null ? '' : dateTimeFormat.format(date);
    },
    date: (value) => {
      const date = toDate(value);

      if (date === null) {
        return '';
      }

      return typeof value === 'string' && DATE_ONLY.test(value)
        ? calendarDateFormat.format(date)
        : dateFormat.format(date);
    },
    number: (value) => numberFormat.format(value),
    bytes,
    kibibytes: (value) => bytes(value * 1024),
  };
}

export function useFormatters(): Formatters {
  const translator = useTranslation();

  return useMemo(() => createFormatters(translator), [translator]);
}
