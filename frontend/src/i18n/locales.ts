import type { components } from '../api/schema';

import { de } from './de';

/**
 * Die Sprachen, die dieser Build ausliefert, und die Wahl zwischen ihnen
 * (W2, U-90, U-91).
 *
 * **`catalogues` und `InterfaceLocale` im Vertrag sind dieselbe Liste.** Der
 * Typ unten erzwingt die eine Richtung beim Übersetzen — ein Wert im Vertrag
 * ohne Sprachdatei ist ein Typfehler —, `catalogue.test.ts` prüft beide
 * Richtungen gegen das gebündelte Schema (U-89).
 *
 * Ausgeliefert wird nur Deutsch (U-91). Englisch ist später eine Datei
 * `en.ts` vom Typ {@link Catalogue}, ein Eintrag hier und ein Wert im Vertrag.
 */

export type Locale = components['schemas']['InterfaceLocale'];

/** Jede Sprachdatei hat genau die Schlüssel der deutschen — nicht mehr, nicht weniger. */
export type Catalogue = Readonly<Record<keyof typeof de, string>>;

export const catalogues: Readonly<Record<Locale, Catalogue>> = { de };

export const DEFAULT_LOCALE: Locale = 'de';

export const SUPPORTED_LOCALES: readonly Locale[] = Object.keys(catalogues) as Locale[];

function isLocale(value: string): value is Locale {
  return Object.hasOwn(catalogues, value);
}

/**
 * Die erste angebotene Sprache aus der Wunschliste des Browsers.
 *
 * `navigator.languages` ist genau die Liste, die der Browser als
 * `Accept-Language` sendet — sie im Client auszuwerten statt auf dem Server
 * spart eine Anfrage und wirkt schon auf der Anmeldeseite, bevor es eine
 * Sitzung gibt (U-90). Erst der volle Tag (`de-AT`), dann die Hauptsprache
 * (`de`); passt nichts, gilt Deutsch.
 */
export function negotiateLocale(preferred: readonly string[]): Locale {
  for (const tag of preferred) {
    const normalised = tag.trim().toLowerCase();

    if (isLocale(normalised)) {
      return normalised;
    }

    const primary = normalised.split('-')[0] ?? '';

    if (isLocale(primary)) {
      return primary;
    }
  }

  return DEFAULT_LOCALE;
}

/** Die Wunschliste des Browsers; leer, wo es keinen gibt. */
export function browserLanguages(): readonly string[] {
  if (typeof navigator === 'undefined') {
    return [];
  }

  // Optional in older engines and in some test environments.
  const languages: readonly string[] = navigator.languages ?? [];

  if (languages.length > 0) {
    return languages;
  }

  return typeof navigator.language === 'string' ? [navigator.language] : [];
}
