<!-- markdownlint-disable MD013 MD024 MD060 -->

# W2 — Mehrsprachigkeit

| | |
|---|---|
| **Stufe** | 5 — Oberfläche |
| **Hängt ab von** | W1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 11.4 · E-16 |
| **Status** | **umgesetzt** — Deutsch ausgeliefert, Struktur für weitere Sprachen geprüft |

---

## Ziel

Die Oberfläche ist vollständig übersetzbar, ohne dass später Texte aus dem Code gefischt werden
müssen. Ausgeliefert wird zunächst Deutsch.

## Abgrenzung

- Keine Übersetzung der API. Sie bleibt sprachneutral und liefert stabile Fehlercodes (E-16).
- Keine zweite Sprache im ersten Wurf — nur die Struktur dafür.

## Abhängigkeiten und geteilte Dateien

Baut auf W1 auf. Die Fehlercodes stammen aus F2 und den A-Paketen; die Zuordnung Code → Text
wird hier gepflegt.

Angefasste geteilte Dateien:

| Datei | Was |
|---|---|
| `src/Domain/Access/Permission.php`, `RolePermissions.php` | `profile.preferences.read`/`.write`, beide Rollen (U-90) |
| `src/Domain/Policy/AdminPolicy.php` | `requireOwnPreferencesRead()`/`…Write()` |
| `src/Http/Handler/Admins/AdminResponder.php` | `preferences()` — die Handler liegen unter A4s Tag `admins` |
| `tests/Contract/AdminEndpointTest.php` | zählt jetzt acht Operationen unter `admins` |
| `tests/Unit/Domain/Access/RolePermissionsTest.php` | die zwei neuen Berechtigungen |
| `openapi/README.md` | Dateitabelle um W2 ergänzt |
| `frontend/src/app/AppProviders.tsx`, `frontend/src/pages/OverviewPage.tsx` | `LocaleProvider`, Sprachkarte, Pluralbeispiel |

Keine Migration: `mailadmin_admin_setting` (0050) hat bereits die Form, die eine Einstellung
braucht. Kein neuer Fehlercode, keine neue Abhängigkeit.

## Vorgaben

- Keine festverdrahteten Oberflächentexte.
- Fehlermeldungen aus dem `code`-Feld übersetzen; ist ein Code unbekannt, dient `title` bzw.
  `detail` als Rückfall, damit die Oberfläche auch bei neuen Fehlerarten benutzbar bleibt.
- Datum, Zahlen und Speichergrößen über `Intl`.
- Sprachwahl aus `Accept-Language`, überschreibbar je Administrator; Ablage in
  `mailadmin_admin_setting`.

## Artefakte

| Artefakt | Datei |
|---|---|
| Auflösung (keine Bibliothek, U-87) | `frontend/src/i18n/index.ts` — `t()` mit Interpolation und Pluralformen, `createFormatters()`/`useFormatters()` |
| Sprachdateien | `frontend/src/i18n/de.ts` (Rahmen, Typ-Wurzel), `frontend/src/i18n/de/<bereich>.ts` (je Ressourcenbereich, seit U-130), `frontend/src/i18n/de.errors.ts` (Fehlercodes) — `.ts` statt `.json`, damit `TranslationKey` aus der Datei folgt |
| Schlüsselkonvention (U-88) | `frontend/src/i18n/keyConvention.ts` |
| Sprachen und Aushandlung | `frontend/src/i18n/locales.ts` — `catalogues`, `Catalogue`, `negotiateLocale()` |
| Sprachwahl in der Oberfläche | `frontend/src/i18n/LocaleProvider.tsx`, `frontend/src/pages/LanguagePreferenceCard.tsx` |
| Abbildung Fehlercode → Text | `frontend/src/i18n/problemText.ts` (aus W1, unverändert) |
| Prüflauf (U-89, U-93) | `frontend/src/i18n/catalogueCheck.ts`, ausgeführt von `catalogue.test.ts` in `npm test`; ohne Vertragsbündel schlägt er fehl, Schlüssel in Kommentaren zählen nicht als verwendet |
| Vertrag | `openapi/paths/preferences.yaml`, `openapi/components/preferences.yaml` — `GET`/`PUT /admins/me/preferences`, `AdminPreferences`, `InterfaceLocale` |
| Anwendungsfälle | `src/Application/Admin/{GetOwnPreferences,SetOwnPreferences}.php`, `AdminPreferences` |
| Port + Umsetzung | `Application\Admin\AdminPreferenceStore`, `Infrastructure\Persistence\{SqlAdminPreferenceStore,InMemoryAdminPreferenceStore}` |
| Fachmodell | `Domain\Model\InterfaceLocale` |
| Handler | `src/Http/Handler/Admins/{GetOwnPreferences,SetOwnPreferences}Handler.php` |
| Verdrahtung | `config/di/preferences.php` |
| Protokoll | `admin.preferences.change` mit `before`/`after`, in der Transaktion; keiner bei unverändertem Stand (U-90) |

## Zu klärende Punkte

Alle sieben sind beantwortet:

1. **Bibliothek.** → **U-87.** Keine. Die W1-Naht wird um Pluralformen (`Intl.PluralRules`),
   formatierte Zahlen in Platzhaltern und `Intl`-Formate erweitert; eine npm-Abhängigkeit wäre
   ein Eskalationsfall gewesen.
2. **Schlüsselkonvention.** → **U-88.** `<bereich>.<ansicht>.<element>`, Bereiche als Liste in
   `keyConvention.ts`, Pluralformen als `#one`/`#other`, Prüfung im Testlauf.
3. **Pluralformen und Interpolation.** → **U-87.** Beides ja; erstes Beispiel
   `overview.scopeCount`.
4. **Wo liegen die Fehlercode-Texte.** → **U-89.** Eigene Datei `de.errors.ts`.
5. **Prüfung in der Bauphase.** → **U-89.** Harter Abbruch in `npm test`, für fehlende **und**
   überzählige Schlüssel, Vertragscodes ohne Text und ungenutzte Oberflächenschlüssel.
6. **Englisch als zweite Sprache.** → **U-91.** Später; die Struktur ist geprüft, Englisch ist
   eine Datei und zwei Einträge.
7. **Zeitzone für Anzeigen.** → **U-92.** Zeitzone des Browsers, immer mit Zonenkürzel.

Sprachwahl je Verwalter: → **U-90.**

Nach dem Review: Vertragsprüfung ohne Bündel → **U-93** (Fehlschlag statt Überspringen);
Einheitenwahl bei Größen und reine Kalenderdaten → **U-94**.

### Ursprüngliche Fragen

1. **Bibliothek.** `react-i18next` ist verbreitet und mächtig, aber nicht klein. Eine schlanke
   eigene Lösung würde zu E-12 passen — braucht aber Pluralformen und Interpolation. Abwägen.
2. **Schlüsselkonvention.** Vorschlag `<bereich>.<ansicht>.<element>`, etwa
   `account.list.column.quota`. Festlegen, bevor die ersten Ansichten entstehen — nachträglich
   umbenennen ist teuer.
3. **Pluralformen und Interpolation.** Werden sie gebraucht? Bei Angaben wie „3 Konten" ja.
4. **Wo liegen die Fehlercode-Texte** — bei den übrigen Übersetzungen oder in einer eigenen
   Datei? Eigene Datei erleichtert den Abgleich mit dem Backend.
5. **Prüfung in der Bauphase.** Bricht ein fehlender Schlüssel den Build, oder gibt es nur eine
   Warnung? Ein harter Abbruch verhindert, dass Sprachdateien verrotten.
6. **Englisch als zweite Sprache** — für die Veröffentlichung (E-5) naheliegend. Von Anfang an
   mitpflegen oder später?
7. **Zeitzone für Anzeigen.** Das Revisionsprotokoll speichert vermutlich UTC. Anzeige in der
   lokalen Zeit des Browsers oder in einer konfigurierten Zone?

## Definition of Done

- [x] Keine sichtbare Zeichenkette steht im Code — alle Texte in `de.ts`/`de.errors.ts`; die
      Prüfung auf ungenutzte Schlüssel fand zwei W1-Reste, die entfernt sind.
- [x] Alle Fehlercodes der bestehenden Endpunkte haben eine Übersetzung, einschließlich der
      `account.*`-Codes aus A2 — geprüft gegen das gebündelte `ErrorCode`-Enum.
- [x] Ein unbekannter Fehlercode führt zu einer verständlichen Rückfallmeldung (`detail`, sonst
      `title`, sonst `errors.client.unknown`), nicht zu einer leeren Zeile.
- [x] Der Prüflauf meldet fehlende und überzählige Schlüssel und bricht `npm test` ab — auch
      dann, wenn das Vertragsbündel fehlt (U-93).
- [x] Datum und Größen erscheinen im deutschen Format (`17.10.2026, 14:05 MESZ`, `1,5 GiB`);
      Einheitengrenzen und reine Kalenderdaten sind abgesichert (U-94).
- [x] Sprachwahl aus der Browserliste, überschreibbar je Verwalter, abgelegt in
      `mailadmin_admin_setting`, mit Audit-Eintrag.

## Testfälle

| Stufe | Datei | Was |
|---|---|---|
| Unit (Frontend) | `frontend/src/i18n/translator.test.ts` | Interpolation, formatierte Zahlen, Pluralformen nach CLDR (auch `#other`-Rückfall für Polnisch), fehlender Schlüssel; **Fehlercode-Abbildung** inkl. A2-Code, **unbekanntem Code** mit `detail`- und `title`-Rückfall; **Datum mit Zone** (Berlin Sommer/Winter, UTC), Datum allein, ungültige Zeit, Zahlen, **Größen** in binären Stufen, **Rundung an der Einheitengrenze** (1 048 575 B = `1 MiB`), **reines Kalenderdatum** in New York und Kiritimati; Sprachaushandlung |
| Prüflauf | `frontend/src/i18n/catalogueCheck.test.ts` | jede Prüfung an einem beschädigten Katalog: **absichtlich entfernter Schlüssel**, überzähliger Schlüssel, Vertragscode ohne Text und Text ohne Code, Konventionsverstöße, ungenutzter Schlüssel, **Schlüssel nur im Kommentar gilt als ungenutzt**, Pluralgruppe ohne `#other`, unbenannter Platzhalter; **`contractEnum()` wirft** ohne Bündel und ohne Aufzählung |
| Prüflauf | `frontend/src/i18n/catalogue.test.ts` | die echten Kataloge gegen `ErrorCode` und `InterfaceLocale` aus dem Bündel (fehlt es, schlägt der Lauf fehl), gegeneinander, gegen die Konvention und gegen den Quelltext |
| Oberfläche | `frontend/src/i18n/languagePreference.test.tsx` | ganze Anwendung in jsdom: gespeicherte Wahl lesen, „Deutsch" wählen → `PUT` mit `{locale: "de"}`, Bestätigung, `lang`-Attribut; Zeitzonenhinweis; Pluralform in der Übersicht; ohne Berechtigung keine Karte und keine Anfrage |
| Anwendungsfall | `tests/Application/Admin/OwnPreferencesTest.php` | nichts gewählt = `null`, Domainmaster wählt selbst, Subjekt ist immer der Akteur, `null` entfernt die Zeile, Audit mit `before`/`after`, **unveränderter Stand schreibt nichts**, unbekannter gespeicherter Wert liest sich als `null` |
| Vertrag | `tests/Contract/OwnPreferencesEndpointTest.php` | beide Operationen durch die echte Kette gegen das Schema; `422` für nicht angebotene Sprache und fehlendes Feld; `401` ohne Token; `InterfaceLocale` im Vertrag = PHP-Enum; Schema = gesendete Darstellung |
| Vertrag | `tests/Contract/AdminEndpointTest.php` | die zwei Operationen unter `admins` mit ihren Berechtigungen, ohne `403` |
| Integration | `tests/Integration/Persistence/AdminPreferenceStoreIntegrationTest.php` | echtes MariaDB: Upsert unter nativen Prepares, `updated_at` in UTC, Löschen nur der eigenen Zeile, `NULL`/unbekannter Wert als „nichts gewählt" |
