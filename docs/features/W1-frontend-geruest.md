<!-- markdownlint-disable MD013 MD024 MD060 -->

# W1 — Frontend-Gerüst

| | |
|---|---|
| **Stufe** | 5 — Oberfläche |
| **Hängt ab von** | F2, S2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 11 · E-15 |
| **Status** | **umgesetzt** (U-71 … U-75); Fundament der Fachansichten nachgezogen (U-130) |

---

## Ziel

Eine lauffähige Single-Page-Anwendung: Build, Routing, generierter API-Client,
Anmeldeablauf, Fehlerbehandlung, Navigation und Grundlayout. Die fachlichen Ansichten kommen
mit den A-Paketen.

## Abgrenzung

- Keine fachlichen Ansichten — die gehören zu A1 bis A6.
- Keine Mehrsprachigkeit — das ist **W2**. W1 legt aber schon Textschlüssel an statt fester
  Zeichenketten.
- Kein Offline-Betrieb, kein Service Worker, kein Manifest (E-6).

## Abhängigkeiten und geteilte Dateien

Braucht ein Schema aus F2 und die Endpunkte aus S2. Legt die Muster fest, denen die
fachlichen Ansichten folgen.

## Vorgaben

- **Zugriffstoken im `localStorage`** (E-15). Das **Erneuerungstoken bleibt im
  HttpOnly-Cookie** — Eskalationsfall, wenn jemand es anders will.
- Der API-Client wird aus `openapi.yaml` typisiert erzeugt; kein handgepflegtes API-Modul.
- **Material UI ist gesetzt** (U-35). Schriften und Symbole werden lokal gebündelt
  (`@fontsource/roboto`, `@mui/icons-material`) — **kein Google-Fonts-CDN**, auch nicht als
  Rückfall.
- **`script-src 'self'` bleibt unangetastet.** Erzeugt der Bundler Inline-**Skripte**, wird der
  Bundler angepasst, nicht die Policy. Dass `style-src` seit U-35 `'unsafe-inline'` trägt, ist
  keine Erlaubnis, weiter zu lockern — jede zusätzliche Lockerung bleibt Eskalationsfall.
- **Die Shell muss die Sicherheits-Header tragen.** `index.html` wird heute statisch
  ausgeliefert und bekommt deshalb **keine** CSP; die setzt nur der Front-Controller für
  `/api/*`. W1 schließt das (offener Punkt **O-13**) — über einen `Header set` in
  `public/.htaccess` plus die vHost-Beispiele oder durch Auslieferung der Shell über den
  Front-Controller. Ohne diesen Schritt ist die CSP für das Frontend wirkungslos, und die
  Abwägung aus U-35 hängt in der Luft.
- Berechtigungen kommen aus `GET /auth/me`; die Oberfläche blendet aus, das Backend entscheidet.
- Abmelden räumt `localStorage` **und** Cookie ab.

## Artefakte

- `frontend/` mit Build-Konfiguration
- Generierung der Typen aus dem Schema, als Build-Schritt
- `frontend/src/api/client.ts` — setzt `Authorization`, wertet `problem+json` aus, behandelt
  `401` mit einmaliger Erneuerung
- Anmeldeansicht, Navigation, Layout, Fehler- und Ladezustände
- Auslieferung nach `public/assets/` mit Inhalts-Hash im Dateinamen

## Zu klärende Punkte

Alle Punkte sind entschieden. Was über das Paket hinausreicht, steht als U-Entscheidung in
[05-umsetzungsentscheidungen.md](../05-umsetzungsentscheidungen.md); was paketlokal bleibt, steht
hier als **Annahme** und ist ausdrücklich zur Korrektur gedacht.

1. ~~**Build-Werkzeug.**~~ Entschieden: **Vite** (U-17).
2. ~~**UI-Bibliothek.**~~ Entschieden: **Material UI** (U-35).
3. ~~**Tabellen.**~~ Entschieden: **MUIs `Table`** mit `TableSortLabel` und `TablePagination`,
   **kein `DataGrid`** (U-73). Sortierung und Paginierung kommen vom Server (U-20); damit bleibt
   vom `DataGrid` nur die Darstellung übrig. Das Theme richtet die Kopfzeilen bereits dafür ein —
   die erste Listenansicht (A1) findet die Entscheidung vorbereitet vor, nicht umgesetzt.
4. ~~**Formulare und Validierung.**~~ Entschieden: **von Hand, ohne Bibliothek** (U-73). Der
   Client prüft Pflichtfelder, alles Weitere entscheidet der Server; `Problem.errors` wird über
   den Feldpfad (`body.username`) auf das Eingabefeld gelegt und der Grund (`required`, `enum`,
   `maxLength`) über `errors.field.<grund>` übersetzt. Die Abbildung sitzt in
   `frontend/src/i18n/problemText.ts` und gilt für alle Masken.
5. ~~**Theme.**~~ Entschieden: **eigenes `createTheme`** mit fester, gedeckter Palette (U-73).
6. ~~**Verhalten bei parallelen `401`.**~~ Entschieden: **eine gebündelte Erneuerung, ein
   Wiederholungsversuch** (U-74). Das ist keine Optimierung — zwei gleichzeitige Einlösungen
   desselben Erneuerungstokens sehen für S2 wie ein *wiederverwendetes* Token aus und lösen die
   Diebstahlerkennung aus.
7. ~~**Abgelaufene Sitzung mitten im Formular.**~~ Entschieden: **Eingaben bleiben stehen, nichts
   wird zwischengespeichert** (U-74). Eingaben in diesem Werkzeug enthalten regelmäßig Passwörter.
8. ~~**Zugänglichkeit.**~~ Entschieden: **Tastaturbedienbarkeit und benannte Bedienelemente als
   Mindestmaß** (U-73). Fehler in `role="alert"`, Ladezustände in `role="status"`, jede Schaltfläche
   mit zugänglichem Namen. Kein WCAG-Konformitätsanspruch — der wäre ohne Prüfwerkzeug eine
   Behauptung.

### Annahmen

- **Annahme A1 — Der Basispfad ist zur Bauzeit bekannt.** `MAILADMIN_BASE_PATH` geht als Vites
  `base` in den Bau ein (U-71). Eine Installation, die dieselben Dateien unter zwei verschiedenen
  Pfaden ausliefern will, müsste zweimal bauen. Das ist für ein Verwaltungswerkzeug hinter
  Anmeldung als unwahrscheinlich angenommen.
- **Annahme A2 — Ein Konto, ein Browser-Tab-Verbund.** Der Tokenspeicher hört auf das
  `storage`-Ereignis, damit ein Abmelden in einem Tab die anderen nicht mit einem Token
  zurücklässt. Zwei *verschiedene* Anmeldungen in zwei Tabs desselben Browsers sind damit nicht
  möglich — sie teilen sich einen `localStorage`-Eintrag. Das entspricht dem Altsystem und ist
  als gewollt angenommen.
- **Annahme A3 — Die Sprachwahl kommt in W2.** W1 liefert Deutsch fest verdrahtet als *einzigen*
  Katalog, aber über die Naht aus U-72. `Accept-Language` und `mailadmin_admin_setting` sind
  ausdrücklich nicht umgesetzt.
- **Annahme A4 — Ein `ErrorCode` gehört zum Paket, das ihn einträgt.** W1 hat den Katalog für
  alle bis heute vorhandenen Codes gefüllt, einschließlich derer aus A1, A4 und A5. Für jeden
  weiteren gilt: wer den Code in `openapi/components/common.yaml` einträgt, trägt den deutschen
  Text in `frontend/src/i18n/de.ts` ein. Der Test `errorCatalogue.test.ts` erzwingt es.

## Definition of Done

- ~~Anmeldung, Navigation und Abmeldung funktionieren gegen die echte API.~~ **Erfüllt** — der
  Ablauf läuft in `src/app/appFlow.test.tsx` gegen die vollständige Anwendung; ersetzt ist nur das
  Netz (U-75).
- ~~Ein abgelaufenes Token wird einmalig erneuert; scheitert das, erscheint die Anmeldung.~~
  **Erfüllt**, mit Bündelung paralleler `401` (U-74), geprüft in `src/api/client.test.ts`.
- ~~Fehler aus `problem+json` erscheinen verständlich, mit Request-Id für Rückfragen.~~
  **Erfüllt** — übersetzt über `code`, mit Textrückfall bei unbekanntem Code.
- ~~Der Build erzeugt keine Inline-Skripte; `script-src 'self'` greift ohne Ausnahme.~~
  **Erfüllt und erzwungen** — `frontend/scripts/check-build.mjs` bricht den Bau ab.
- ~~Die ausgelieferte `index.html` trägt die Sicherheits-Header aus Architektur 5.7 (O-13).~~
  **Erfüllt** — `public/.htaccess` und beide vHost-Beispiele; `bin/check-deployment.php` weist es
  über eine echte HTTP-Anfrage nach, `tests/Unit/Deployment/ShellSecurityHeadersTest.php` hält die
  vier Fassungen deckungsgleich. **O-13 ist geschlossen.**
- ~~Kein Netzwerkzugriff auf einen fremden Ursprung.~~ **Erfüllt** — Roboto und die Symbole sind
  lokal gebündelt; der Bauprüfer meldet jede CDN-Referenz in Shell und Bündel.
- ~~Keine feste Zeichenkette in der Oberfläche.~~ **Erfüllt** — `frontend/src/i18n/de.ts` ist die
  einzige Datei mit deutschen Sätzen.

## Testfälle

| Stufe | Datei | Was geprüft wird |
|---|---|---|
| Unit | `frontend/src/api/client.test.ts` | Bearer-Kopf, `problem+json`, `Retry-After`, Netzfehler, Abbruch, `401`-Erneuerung, **Bündelung**, ein Wiederholungsversuch, Sitzungsverlust genau einmal |
| Unit | `frontend/src/auth/tokenStore.test.ts` | nur das Zugriffstoken im `localStorage`, Ablaufzeitpunkt, Abmelden räumt ab, unlesbarer Wert wird verworfen |
| Unit | `frontend/src/i18n/errorCatalogue.test.ts` | jeder `ErrorCode` des Vertrags hat Text, jeder Text einen Code |
| Komponente | `frontend/src/pages/SignInPage.test.tsx` | leere Felder, `refresh_delivery: cookie`, kein Passwort in der URL, übersetzte und zurückfallende Fehler, Feldfehler, Missbrauchsschutz, Netzfehler |
| Anwendung | `frontend/src/app/appFlow.test.tsx` | anmelden, navigieren, abmelden, **kein Token danach**; Abmelden bei fehlgeschlagenem Serveraufruf; Neuladen mit gültigem und mit abgelehntem Token; clientseitige 404 |
| Unit (PHP) | `tests/Unit/Deployment/ShellSecurityHeadersTest.php` | die vier Fassungen der Sicherheits-Header sind deckungsgleich, `script-src` bleibt streng |
| Bauzeit | `frontend/scripts/check-build.mjs` | kein Inline-Skript, kein `javascript:`, kein fremder Ursprung, Inhalts-Hash überall |

## Was W1 hinterlässt

Für die fachlichen Pakete (A1–A6) und für W2:

| Anschlussstelle | Datei |
|---|---|
| Typisierter API-Client (`client.get('/domains', { query })`) | `frontend/src/api/client.ts` |
| Fehlerobjekt mit `code`, Feldfehlern und Vorgangsnummer | `frontend/src/api/problem.ts` |
| Sitzung, Rolle, Berechtigungen (`can()` — nur zum Ausblenden) | `frontend/src/auth/SessionContext.tsx` |
| ~~Route eintragen~~ | seit U-130: `frontend/src/pages/<ansicht>/routes.tsx` |
| ~~Navigationseintrag mit Berechtigung~~ | seit U-130: vollständig in `frontend/src/app/navigation.tsx` |
| Lade- und Fehlerdarstellung | `frontend/src/components/StateViews.tsx` |
| ~~Textschlüssel~~ | seit U-130: Rahmen in `frontend/src/i18n/de.ts`, Fachbereiche in `frontend/src/i18n/de/<bereich>.ts` |
| **Naht für W2** | `frontend/src/i18n/index.ts` — Signatur ist die von `react-i18next` |

## Fundament der Fachansichten (U-130)

Nachgezogen, damit drei Arbeitsstränge die Ansichten von A1–A6 und S4 gleichzeitig im selben
Arbeitsverzeichnis bauen können, ohne dieselbe Datei zu bearbeiten. Die verbindlichen Regeln
für die Stränge stehen englisch in [`frontend/src/pages/README.md`](../../frontend/src/pages/README.md).

| Strang | Pakete | Seitenverzeichnisse | Sprachdateien |
|---|---|---|---|
| `domains-admins-lists` | A1, A4, A5 | `pages/domains`, `pages/admins`, `pages/lists` | `i18n/de/domain.ts`, `admin.ts`, `list.ts` |
| `accounts-addresses` | A2, A3 | `pages/accounts`, `pages/addresses` | `i18n/de/account.ts`, `address.ts` |
| `operations` | A6, S4 | `pages/repairs`, `pages/reconciliation`, `pages/health`, `pages/audit` | `i18n/de/maintenance.ts`, `audit.ts` |

Angefasste geteilte Dateien:

| Datei | Was |
|---|---|
| `frontend/src/api/client.ts` | `getVersioned()`/`requestVersioned()` mit `ETag`; `put`, `patch`, `delete` |
| `frontend/src/app/routes.tsx` | mischt die Routenlisten der neun Seitenverzeichnisse ein |
| `frontend/src/app/AppProviders.tsx` | `NotificationProvider` |
| `frontend/src/app/DocumentTitle.tsx` | `useDocumentTitleText()` für Titel, die schon Text sind |
| `frontend/src/layout/AppLayout.tsx` | Navigation aus `app/navigation.tsx`, in Gruppen, aktiv auch auf Unterseiten |
| `frontend/src/components/StateViews.tsx` | `ForbiddenState` |
| `frontend/src/i18n/de.ts`, `keyConvention.ts` | Rahmentexte der Bausteine; Rahmenbereiche `table`, `confirm`; Einmischen der Bereichsdateien |
| `frontend/src/i18n/catalogueCheck.ts`, `catalogue.test.ts` | `areaFileReport()`; `i18n/<sprache>/` zählt nicht als Anwendungscode |

Neu:

| Baustein | Datei | Test |
|---|---|---|
| Serverseitige Liste | `components/DataTable.tsx`, `components/useTableState.ts`, `components/FilterField.tsx` | `DataTable.test.tsx`, `useTableState.test.tsx`, `FilterField.test.tsx` |
| Bestätigung, auch mit eingetipptem Namen | `components/ConfirmDialog.tsx` | `ConfirmDialog.test.tsx` |
| Formularhilfen | `components/form.tsx` | `form.test.tsx` |
| Fehler über den Katalog | `components/ProblemAlert.tsx` | `ProblemAlert.test.tsx` |
| Seitenkopf mit Pfad | `components/PageHeader.tsx` | `PageHeader.test.tsx` |
| Erfolgsmeldungen | `components/Notifications.tsx` | `Notifications.test.tsx` |
| `If-Match` | `api/etag.ts` | `api/etag.test.ts` |
| Navigation, Routen, Berechtigungen | `app/navigation.tsx`, `auth/RequirePermission.tsx`, `pages/*/routes.tsx` | `app/navigation.test.tsx` |
| Hilfsmittel für Bausteintests | `test/renderWithRouter.tsx` | — |

Nachgezogen bei der Zusammenführung der drei Stränge (U-132):

| Baustein | Datei | Test |
|---|---|---|
| Optionaler Anfragekörper typisiert (`requestBody?:`) | `api/client.ts` | `api/client.test.ts` |
| `X-Mailadmin-Effect` neben dem `ETag` (`Exchange.effect`, `hasPendingConfigurationRun()`) | `api/client.ts` | `api/client.test.ts` |
| Beschriftete Werteliste (`DefinitionList`, `DefinitionItem`, `Monospace`) | `components/DefinitionList.tsx` | `DefinitionList.test.tsx` |
| Fehler einer Listenposition auf dem Listenfeld (`errorAtOrBelow`) | `components/form.tsx` | `form.test.tsx` |
| Katalogtext eines Fehlercodes als Feld (`useErrorCodeText`) | `i18n/problemText.ts` | `i18n/translator.test.ts` |
| Eigenes Passwort im Kontomenü, für beide Rollen | `layout/AppLayout.tsx` | `app/navigation.test.tsx` |

Nachgezogen aus dem Review der Fachansichten (U-133):

| Baustein | Datei | Test |
|---|---|---|
| Freitextfilter mit Vertragsmuster (`RegExp` im `FilterSpec`, `FILTER_PATTERN`, `invalidFilters`) | `components/useTableState.ts` | `pages/audit/AuditListPage.test.tsx`, `pages/repairs/RepairListPage.test.tsx` |
| Begründung am Filterfeld (`errorText`) | `components/FilterField.tsx` | wie oben |
| Seite hinter dem Ende springt auf die letzte Seite | `components/DataTable.tsx` | `DataTable.test.tsx` |
| Abfragecache in der Testhilfe (`RenderedApp.queryClient`) | `test/renderApp.tsx` | `pages/domains/domains.test.tsx` |
