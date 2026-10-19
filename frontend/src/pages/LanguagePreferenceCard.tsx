import Alert from '@mui/material/Alert';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';

import { useTranslation, type TranslationKey } from '../i18n';
import { useOwnPreferences, useSetOwnPreferences } from '../i18n/LocaleProvider';
import {
  SUPPORTED_LOCALES,
  browserLanguages,
  negotiateLocale,
  type Locale,
} from '../i18n/locales';
import { useProblemText } from '../i18n/problemText';

/**
 * Die eigene Sprachwahl (W2, U-90) und der Hinweis, in welcher Zeitzone Zeiten
 * erscheinen (U-92).
 *
 * Mit einer ausgelieferten Sprache ist die Auswahl schmal — „wie im Browser"
 * oder „Deutsch" —, aber sie ist der vollständige Weg: lesen, speichern,
 * übernehmen. Die zweite Sprache ist danach eine Datei, keine Oberfläche.
 */

/** Wörtlich, damit die Prüfung auf ungenutzte Schlüssel sie findet. */
const LOCALE_NAME_KEYS: Readonly<Record<Locale, TranslationKey>> = {
  de: 'preferences.localeName.de',
};

/** Die Auswahl „wie im Browser" — `null` im Vertrag, ein leerer Wert im Formular. */
const FOLLOW_BROWSER = '';

export function LanguagePreferenceCard(): ReactNode {
  const { t } = useTranslation();
  const describe = useProblemText();
  const preferences = useOwnPreferences();
  const save = useSetOwnPreferences();

  const browserLocale = negotiateLocale(browserLanguages());
  const timeZone = new Intl.DateTimeFormat().resolvedOptions().timeZone;
  const current = preferences.data?.locale ?? FOLLOW_BROWSER;

  return (
    <Card>
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6" component="h2">
            {t('preferences.language.title')}
          </Typography>

          <TextField
            select
            label={t('preferences.language.label')}
            value={current}
            disabled={preferences.isPending || save.isPending}
            onChange={(event) => {
              const value = event.target.value;

              save.mutate({ locale: value === FOLLOW_BROWSER ? null : (value as Locale) });
            }}
            // „Wie im Browser" is the empty value and must still be shown.
            slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
            sx={{ maxWidth: 360 }}
          >
            <MenuItem value={FOLLOW_BROWSER}>
              {t('preferences.language.followBrowser', { language: t(LOCALE_NAME_KEYS[browserLocale]) })}
            </MenuItem>
            {SUPPORTED_LOCALES.map((locale) => (
              <MenuItem key={locale} value={locale}>
                {t(LOCALE_NAME_KEYS[locale])}
              </MenuItem>
            ))}
          </TextField>

          {save.isSuccess && <Alert severity="success">{t('preferences.language.saved')}</Alert>}
          {save.isError && <Alert severity="error">{describe(save.error).message}</Alert>}
          {preferences.isError && <Alert severity="error">{describe(preferences.error).message}</Alert>}

          <Typography variant="body2" color="text.secondary">
            {t('preferences.language.timeZone', { timeZone })}
          </Typography>
        </Stack>
      </CardContent>
    </Card>
  );
}
