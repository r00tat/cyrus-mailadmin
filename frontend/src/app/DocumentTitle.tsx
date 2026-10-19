import { useEffect } from 'react';

import { useTranslation, type TranslationKey } from '../i18n';

/**
 * Sets `document.title` from a translation key.
 *
 * `index.html` ships the product name as a static title because the shell is
 * served before any script runs; that value is the fallback, and E-16 is
 * satisfied because it is a name, not a sentence. Everything a user reads as
 * language comes through here.
 */
export function useDocumentTitle(pageKey: TranslationKey): void {
  const { t } = useTranslation();

  useDocumentTitleText(t(pageKey));
}

/**
 * The same for a page title that is already text — a translated heading with
 * a value in it, or the name of the object a detail view shows. `PageHeader`
 * uses it so that the tab and the heading always say the same thing.
 */
export function useDocumentTitleText(page: string): void {
  const { t } = useTranslation();

  useEffect(() => {
    const previous = document.title;

    document.title = t('app.title', { page });

    return () => {
      document.title = previous;
    };
  }, [t, page]);
}
