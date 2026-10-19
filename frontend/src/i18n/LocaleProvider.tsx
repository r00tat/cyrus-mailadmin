import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

import { useApiClient } from '../api/ApiContext';
import type { components } from '../api/schema';
import { useSession } from '../auth/SessionContext';

import { TranslationProvider, createTranslator, type Translator } from './index';
import { browserLanguages, catalogues, negotiateLocale, type Locale } from './locales';

/**
 * Welche Sprache gerade gilt, und woher sie kommt (U-90).
 *
 * 1. Hat der angemeldete Verwalter eine Sprache gewählt
 *    (`GET /admins/me/preferences`), gilt sie.
 * 2. Sonst die erste angebotene Sprache aus der Wunschliste des Browsers — der
 *    Liste, die er als `Accept-Language` sendet.
 * 3. Sonst Deutsch.
 *
 * Vor der Anmeldung und nach der Abmeldung gilt Stufe 2: die Wahl gehört dem
 * Verwalter, nicht dem Gerät, und wird deshalb nirgends im Browser abgelegt.
 */

export type AdminPreferences = components['schemas']['AdminPreferences'];

export const OWN_PREFERENCES_QUERY_KEY = ['admins', 'me', 'preferences'] as const;

const PreferredLocaleContext = createContext<(locale: Locale | null) => void>(() => undefined);

export function LocaleProvider({
  translator: fixed,
  children,
}: {
  /** Nur für Tests: eine feste Auflösung statt der ausgehandelten. */
  translator?: Translator | undefined;
  children: ReactNode;
}): ReactNode {
  const [preferred, setPreferred] = useState<Locale | null>(null);
  const locale = preferred ?? negotiateLocale(browserLanguages());

  const translator = useMemo(
    () => fixed ?? createTranslator(catalogues[locale], locale),
    [fixed, locale],
  );

  useEffect(() => {
    // Screen readers and hyphenation follow `lang`; the shell ships `de`.
    document.documentElement.lang = translator.language;
  }, [translator.language]);

  return (
    <PreferredLocaleContext.Provider value={setPreferred}>
      <TranslationProvider value={translator}>{children}</TranslationProvider>
    </PreferredLocaleContext.Provider>
  );
}

export function useOwnPreferences() {
  const client = useApiClient();
  const { status, can } = useSession();

  return useQuery({
    queryKey: OWN_PREFERENCES_QUERY_KEY,
    queryFn: ({ signal }) => client.get('/admins/me/preferences', { signal }),
    enabled: status === 'authenticated' && can('profile.preferences.read'),
    staleTime: 5 * 60_000,
  });
}

export function useSetOwnPreferences() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (preferences: AdminPreferences) =>
      client.request('put', '/admins/me/preferences', { body: preferences }),
    onSuccess: (stored) => {
      queryClient.setQueryData(OWN_PREFERENCES_QUERY_KEY, stored);
    },
  });
}

/**
 * Trägt die gespeicherte Wahl in den {@link LocaleProvider} ein. Sitzt unter
 * der Sitzung, weil es sie braucht; der Provider sitzt darüber, weil auch die
 * Anmeldeseite und die Fehlergrenze übersetzt werden.
 */
export function PreferredLocaleSync(): null {
  const setPreferred = useContext(PreferredLocaleContext);
  const { status } = useSession();
  const { data } = useOwnPreferences();

  const chosen = status === 'authenticated' ? (data?.locale ?? null) : null;

  useEffect(() => {
    setPreferred(chosen);
  }, [setPreferred, chosen]);

  return null;
}
