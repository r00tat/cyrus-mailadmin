import { useCallback } from 'react';

import { ApiProblem } from '../api/problem';

import { useTranslation, type TranslationKey, type Translator } from './index';

/**
 * Bildet ein `problem+json` auf lesbaren Text ab.
 *
 * Die Regel steht in `openapi/README.md` und in Architektur 11.4: **übersetzt
 * wird `code`, nicht `title`.** Kennt dieser Build den Code nicht — weil das
 * Backend neuer ist als das Frontend —, wird `detail` oder `title` aus der
 * Antwort gezeigt. Das ist Englisch und damit nicht schön, aber es ist wahr und
 * lässt die Oberfläche benutzbar. Der stille Fall, ein leerer Dialog, ist die
 * Variante, die hier vermieden wird.
 */

export interface ProblemText {
  /** Der Satz, der dem Menschen gezeigt wird. */
  readonly message: string;
  /** Für die Rückfrage; leer, wenn die Antwort keine Vorgangsnummer trug. */
  readonly requestId: string | undefined;
  /** Feldpfad (`body.username`) auf übersetzten Grund. */
  readonly fieldErrors: Readonly<Record<string, string>>;
}

/** `<resource>.<state>`, the form of a contract error code (U-7). Validator keywords have no dot. */
const CONTRACT_CODE = /^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/;

function describeProblem(problem: ApiProblem, translator: Translator): ProblemText {
  const key = `errors.${problem.code}` as TranslationKey;
  const translated = translator.t(key);

  // `t()` gibt den Schlüssel zurück, wenn er unbekannt ist — das ist das
  // Signal, auf den Text aus der Antwort auszuweichen.
  const message = translated !== key
    ? translated
    : (problem.detail ?? problem.title ?? translator.t('errors.client.unknown'));

  const fieldErrors: Record<string, string> = {};

  for (const [field, reason] of Object.entries(problem.errors)) {
    // The reason is a validator keyword (`maxLength`), or — where a use case
    // refuses one field — a contract code (`list.tag_outside_latin1`). A code
    // has its text in the catalogue already; a keyword has it under `field`.
    const reasonKey = (CONTRACT_CODE.test(reason) ? `errors.${reason}` : `errors.field.${reason}`) as TranslationKey;
    const reasonText = translator.t(reasonKey);

    fieldErrors[field] = reasonText !== reasonKey ? reasonText : translator.t('errors.field.unknown');
  }

  return { message, requestId: problem.requestId, fieldErrors };
}

export function describeUnknownError(error: unknown, translator: Translator): ProblemText {
  if (error instanceof ApiProblem) {
    return describeProblem(error, translator);
  }

  return {
    message: translator.t('errors.client.unknown'),
    requestId: undefined,
    fieldErrors: {},
  };
}

export function useProblemText(): (error: unknown) => ProblemText {
  const translator = useTranslation();

  return (error: unknown) => describeUnknownError(error, translator);
}

/**
 * Der Katalogtext eines nackten Fehlercodes — eines Codes, der als *Feld*
 * einer Darstellung ankommt statt als Problemdokument:
 * `HealthCheckDetails.code`, `RepairEntry.last_error`.
 *
 * Dieselbe Regel wie oben (E-16): übersetzt wird über `errors.<code>`; einen
 * Code, den dieser Build nicht kennt, zeigt die Oberfläche so, wie er kam —
 * das ist wahr und im Log auffindbar.
 */
export function useErrorCodeText(): (code: string) => string {
  const { t } = useTranslation();

  return useCallback(
    (code: string) => {
      const key = `errors.${code}` as TranslationKey;
      const text = t(key);

      return text === key ? code : text;
    },
    [t],
  );
}
