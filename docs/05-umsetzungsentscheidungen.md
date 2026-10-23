<!-- markdownlint-disable MD013 MD024 MD060 -->

# Umsetzungsentscheidungen

Dieses Dokument enthält die **übergreifenden** Festlegungen, die während der Implementierung
getroffen wurden, weil sie mehr als ein Arbeitspaket betreffen und deshalb nicht im Paket
entschieden werden können.

Die Nummerierung ist `U-n` (Umsetzung), um sie von den strukturellen Entscheidungen `E-1 … E-18`
in `docs/internal/02-entscheidungen.md` zu unterscheiden.

> **Stand:** 2026-08-15
>
> **Alle Einträge sind ohne Rückfrage nach gängiger Praxis entschieden worden** und ausdrücklich
> zur Korrektur gedacht. Jeder Eintrag nennt die Alternative und was ihre Wahl kosten würde —
> damit eine Korrektur abschätzbar bleibt. Paket-lokale Annahmen stehen jeweils im Abschnitt
> *Zu klärende Punkte* des betreffenden Dokuments unter `docs/features/`.

---

## Wie dieses Dokument zu lesen ist

| Feld | Bedeutung |
|---|---|
| **Frage** | Was offen war |
| **Entscheidung** | Was gilt |
| **Warum** | Die Begründung, knapp |
| **Alternative** | Was sonst infrage kam |
| **Korrekturkosten** | Was ein Umschwenken später bedeutet |

---

## U-1 — PHP 8.3 als Mindestversion

**Frage:** F1/1 — 8.1 (breite Kompatibilität) oder 8.3 (readonly classes, typed constants)?

**Entscheidung:** **PHP 8.3.** `composer.json` verlangt `"php": ">=8.3"`.

**Warum:** Die Zielumgebung hat 8.3. Das Fachmodell besteht überwiegend aus unveränderlichen
Value Objects — `readonly class` (8.2) und typisierte Klassenkonstanten (8.3) sind dort keine
Kosmetik, sondern ersparen Boilerplate, den man sonst prüfen muss. Ein Werkzeug für genau einen
Serveraufbau (README, „Voraussetzungen") gewinnt nichts durch Kompatibilität mit Versionen, die
niemand einsetzt.

**Alternative:** 8.1. Nötig nur, wenn mailadmin auf Distributionen mit älterem PHP laufen soll
(Debian 11: 7.4, Ubuntu 22.04: 8.1).

**Korrekturkosten:** Gering, solange früh entschieden. `readonly class` → `readonly`-Eigenschaften
je Feld; typisierte Konstanten → untypisiert. Mechanisch, aber im ganzen `src/Domain`-Baum.

---

## U-2 — OpenAPI 3.0.3, nicht 3.1

**Frage:** F2/1 — 3.1 (aktuell) oder 3.0 (Validator-Unterstützung)?

**Entscheidung:** **OpenAPI 3.0.3.** Das Schema deklariert `openapi: 3.0.3`.

**Warum:** Die Architektur nennt `league/openapi-psr7-validator` als gesetzt (Abschnitt 3.1). Der
Validator stützt sich auf `cebe/php-openapi` und unterstützt 3.1 nur unvollständig — 3.1 wählen
hieße also, die Abhängigkeit zu tauschen oder Validierung an der Eingabegrenze aufzugeben. Beides
kostet mehr als der Verzicht auf 3.1. Was 3.1 zusätzlich könnte, wird hier nicht gebraucht:
`webhooks` (keine), JSON-Schema-2020-12-Vollumfang (nicht benötigt), `null` als Typ (in 3.0 über
`nullable: true` abgedeckt).

Das Architekturdokument nennt an mehreren Stellen 3.1. **Dieser Punkt weicht bewusst ab.**

**Alternative:** 3.1 mit einem anderen Validator (etwa `opis/json-schema` plus eigene
Request-Bindung). Mehr Eigencode an einer sicherheitsrelevanten Stelle.

**Korrekturkosten:** Hoch, wenn spät. F2/1 sagt es selbst: „ein späterer Wechsel ist teuer".
Betroffen wären jede Schemadatei, der Validator und die Typgenerierung.

---

## U-3 — Das Schema wird auf mehrere Dateien verteilt und gebündelt

**Frage:** Nicht im Paketdokument gestellt, aber blockierend: `openapi/openapi.yaml` wird laut F2
von **jedem** A-Paket erweitert. Eine einzige Datei, an der sechs Pakete gleichzeitig arbeiten,
erzeugt fortlaufend Konflikte.

**Entscheidung:** Der Vertrag bleibt **ein logisches Dokument**, liegt aber physisch verteilt:

```text
openapi/
  openapi.yaml              Wurzel: info, servers, security, tags, $ref auf paths/*
  paths/
    health.yaml             F2
    auth.yaml               S2
    domains.yaml            A1
    accounts.yaml           A2
    addresses.yaml          A3
    admins.yaml             A4
    lists.yaml              A5
    maintenance.yaml        A6
    audit.yaml              S4
  components/
    common.yaml             F2 — Problem, Pagination, ETag, Fehlercode-Enum
    domain.yaml             A1
    account.yaml            A2
    …                       je Paket eine Datei
```

`bin/openapi-bundle` löst alle `$ref` auf und schreibt ein einziges Dokument nach
`var/cache/openapi.bundled.yaml`. **Dieses gebündelte Dokument** verwenden Validator,
Routenkompilierung, Typgenerierung und Vertragstests — nie die verteilten Quellen.

**Warum:** Externe `$ref` sind normales OpenAPI und von `cebe/php-openapi` wie von
`openapi-typescript` unterstützt. Ein Bündelschritt macht daraus zusätzlich einen einzigen
Parse-Vorgang zur Laufzeit (die Architektur verlangt ohnehin einen Cache, Abschnitt 4) und einen
Ort, an dem Widersprüche zwischen den Teildateien auffallen. Regel drei aus `CLAUDE.md` — „der
Vertrag zuerst" — bleibt gewahrt: es gibt weiterhin genau eine Wahrheit, sie ist nur nicht in
einer Datei eingesperrt.

**Alternative:** Eine Datei und serialisierte Bearbeitung. Verhindert Parallelarbeit an den
A-Paketen, also den größten Teil des Vorhabens.

**Korrekturkosten:** Gering. `bin/openapi-bundle` kann das Ergebnis jederzeit als eine Datei
nach `openapi/openapi.yaml` schreiben, wenn die Verteilung nicht gewünscht ist.

---

## U-4 — Handler werden über eine Konvention zugeordnet, nicht über eine Registry

**Frage:** Nicht gestellt, aber blockierend: eine zentrale Tabelle `operationId` → Handlerklasse
wäre eine weitere Datei, die alle Pakete gleichzeitig anfassen.

**Entscheidung:** Die Zuordnung ist **rein konventionell** und wird zur Bauzeit geprüft:

```text
operationId  +  erster Eintrag in tags   →   Klassenname

listDomains  +  tags: [domains]          →   Mailadmin\Http\Handler\Domains\ListDomainsHandler
setAccountPassword + tags: [accounts]    →   Mailadmin\Http\Handler\Accounts\SetAccountPasswordHandler
```

Regel: `Mailadmin\Http\Handler\{UpperCamel(tag)}\{UpperCamel(operationId)}Handler`.

`bin/compile-routes` liest das gebündelte Schema, bildet jede Operation nach dieser Regel ab und
bricht ab, wenn die Klasse fehlt. Anschließend scannt es `src/Http/Handler/` und bricht ab, wenn
eine Handlerklasse existiert, zu der keine Operation gehört. Damit ist die Forderung aus F2
(„Operation ohne Handler und Handler ohne Operation brechen den Build") ohne eine geteilte Datei
erfüllt.

**Warum:** Es gibt keine Registry, die in Konflikt geraten kann, und keine Möglichkeit, einen
Handler zu registrieren, ohne dass er im Vertrag steht. Die Konvention ist zudem beim Lesen
sofort auflösbar.

**Alternative:** Explizite Registry (`config/routes.php`). Freier in der Benennung, aber ein
Nadelöhr für parallele Arbeit und eine zusätzliche Stelle, an der man etwas vergessen kann.

**Korrekturkosten:** Gering. Der Kompilierer könnte eine optionale Abweichungsliste
(`x-handler` je Operation) zusätzlich auswerten. Ist bewusst nicht eingebaut, um die Konvention
nicht zu verwässern.

---

## U-5 — Container-Definitionen liegen paketweise in `config/di/`

**Frage:** Nicht gestellt, aber blockierend: eine Datei `config/container.php` für alle Pakete.

**Entscheidung:** PHP-DI mit Autowiring. Nur Bindungen, die Autowiring nicht auflösen kann
(Interface → Implementierung, Werte aus der Konfiguration), werden erklärt — und zwar in
`config/di/<paket>.php`, jede Datei gibt ein Array zurück. Der Container lädt `config/di/*.php`
per `glob` und mischt sie. Reihenfolge alphabetisch; doppelte Schlüssel sind ein Fehler und
brechen den Start.

**Warum:** Jedes Paket besitzt genau eine eigene Datei. Keine Konflikte, und man sieht je Paket,
was es an Verdrahtung braucht.

**Alternative:** Eine Datei. Wird bei 20 Paketen unübersichtlich und ist ein Konfliktpunkt.

**Korrekturkosten:** Trivial — die Dateien lassen sich jederzeit zusammenkopieren.

---

## U-6 — Migrationen haben feste Nummernbereiche je Paket

**Frage:** Nicht gestellt, aber blockierend: fortlaufend nummerierte Migrationen kollidieren,
wenn mehrere Pakete gleichzeitig eine anlegen.

**Entscheidung:** Jedes Paket bekommt einen reservierten Bereich. Dateiname
`migrations/NNNN_<beschreibung>.sql`.

| Bereich | Paket | Inhalt |
|---|---|---|
| 0001–0009 | F3 | `mailadmin_schema_version`, `mailadmin_setting` |
| 0010–0019 | S2 | `mailadmin_refresh_token`, `mailadmin_token_denylist`, `mailadmin_login_attempt` |
| 0020–0029 | S4 | `mailadmin_audit` |
| 0030–0039 | A1 | `mailadmin_domain_setting` |
| 0040–0049 | A6 | `mailadmin_repair_queue` |
| 0050–0059 | A4 | `mailadmin_admin_setting` (u.a. Sprachpräferenz, E-16) |
| 0060–0069 | A2 | `mailadmin_account_status` (Kontosperre, U-127) |
| 0070–0099 | frei | für später |

**Warum:** Konfliktfreie Parallelarbeit ohne Koordination. Lücken in der Nummerierung sind
unschädlich, weil der Migrator nach Dateinamen sortiert und den erreichten Stand je Migration
protokolliert, nicht als Höchstnummer.

**Alternative:** Zeitstempel-Nummerierung (`20260726_143000_…`). Auch konfliktfrei, aber die
Reihenfolge ist dann nicht mehr am Namen ablesbar, und `Date.now()`-artige Namen erschweren
Codereview.

**Korrekturkosten:** Keine — Bereiche sind eine Verabredung, keine Technik.

---

## U-7 — Fehlercodes: `<ressource>.<zustand>`, aufgezählt im Schema

**Frage:** F2/2 — Namensschema der Fehlercodes.

**Entscheidung:** `<ressource>.<zustand>`, beides `snake_case`, wie in der Architektur
vorgeschlagen (`account.quota_exceeded`, `domain.not_found`). Zusätzlich:

- Die vollständige Liste steht als `enum` im Schema (`components/common.yaml`,
  Schema `ErrorCode`). Ein Code, der nicht im `enum` steht, ist im Test ein Fehler.
- Ressourcenpräfixe: `auth`, `domain`, `account`, `address`, `alias`, `catchall`, `forward`,
  `vacation`, `admin`, `list`, `quota`, `mailstore`, `sieve`, `migration`, `request`.
- Querschnittscodes ohne Ressource tragen das Präfix `request` (`request.validation_failed`,
  `request.precondition_failed`, `request.rate_limited`).

**Warum:** Ein `enum` im Vertrag macht die Codeliste testbar und gibt dem Frontend eine
erschöpfende Menge zu übersetzender Schlüssel — sonst verrottet die Sprachdatei (Architektur
11.4).

**Alternative:** Freie Zeichenkette. Billiger, aber genau der Wildwuchs, den F2/2 befürchtet.

**Korrekturkosten:** Gering, aber es ist eine brechende Vertragsänderung, sobald Clients auf
Codes reagieren.

---

## U-8 — Bauschritte laufen über Composer-Skripte, mit Prüfsumme gegen Drift

**Frage:** F2/3 — Wann läuft der Build, und wie verhindert man eine veraltete Routentabelle in
Produktion?

**Entscheidung:**

- `composer build` bündelt das Schema und kompiliert die Routentabelle. Zusätzlich als
  `post-install-cmd` und `post-update-cmd`, damit ein Deployment es nicht vergessen kann.
- Die kompilierte Datei enthält den SHA-256 der gebündelten Spezifikation.
- Beim Start vergleicht die Anwendung diese Prüfsumme.
  - `MAILADMIN_ENV=dev`: Abweichung löst eine Neukompilierung aus.
  - sonst: Abweichung verweigert den Dienst mit `503` und dem Code `migration.build_stale`.
- Ein `Makefile` bündelt die üblichen Abläufe (`make build`, `make test`, `make lint`,
  `make up`), ruft aber nur die Composer- und npm-Skripte auf. Es ist Bequemlichkeit, keine
  zweite Wahrheit.

**Warum:** Die Prüfsumme ist der Vorschlag aus F2/3 und die einzige Variante, die auch dann
greift, wenn jemand die Dateien von Hand kopiert. Verweigerung statt stiller Neukompilierung in
Produktion, weil Kompilieren unter Last dasselbe Nebenläufigkeitsproblem hätte wie die Migration
(Architektur 10.2).

**Alternative:** Nur CI. Schützt nicht vor manuellem Deployment.

**Korrekturkosten:** Gering.

---

## U-9 — Antwortvalidierung und Redoc nur außerhalb der Produktion

**Frage:** F2/4 und F2/5.

**Entscheidung:** Beides an `MAILADMIN_ENV` gebunden:

| | `dev` / `test` | `prod` |
|---|---|---|
| Anfragevalidierung | an | **an** |
| Antwortvalidierung | an | aus |
| Redoc unter `/api/docs` | an | aus (`404`) |

Ein Schalter `MAILADMIN_API_DOCS=1` kann Redoc in Produktion ausdrücklich einschalten; ohne ihn
ist der Pfad nicht vorhanden.

**Warum:** Genau die Bestätigung, um die F2/4 bittet, und die Vorsicht, die F2/5 nahelegt: die
Spezifikation verrät die vollständige Angriffsfläche. Anfragevalidierung bleibt auch in
Produktion an — das ist die Eingabegrenze (Architektur 4).

**Alternative:** Redoc immer an. Bei einem Administrationswerkzeug hinter Anmeldung verteidigbar,
aber ohne Nutzen für den Betrieb.

**Korrekturkosten:** Trivial.

---

## U-10 — API-Version: `/api/v1` als Präfix, v2 würde parallel laufen

**Frage:** F2/6.

**Entscheidung:** `/api/v1` bleibt im Pfad. Ein späteres v2 läuft **parallel** unter `/api/v2`;
es gibt keinen harten Schnitt. Der Vertrag verwendet dazu von Anfang an einen `servers`-Eintrag
mit `/api/v1` als Basis, und die Routentabelle trägt die Version, nicht der Handler-Namensraum.

**Warum:** Ein generierter TypeScript-Client hängt am Vertrag; ein harter Schnitt würde jeden
Client gleichzeitig brechen. Da bis auf die eigene SPA noch kein Client existiert, ist das heute
billig zu vorbereiten und später teuer nachzurüsten.

**Alternative:** Harter Schnitt. Weniger Code, aber ein Zwangs-Upgrade für alle Clients
gleichzeitig.

**Korrekturkosten:** Keine, solange kein v2 existiert.

---

## U-11 — Umgebungsvariablen: `getenv()` in Produktion, `phpdotenv` nur in der Entwicklung

**Frage:** F1/2.

**Entscheidung:** Die Anwendung liest ausschließlich über ein eigenes `Config`-Objekt, das
`$_ENV`/`getenv()` kapselt. `vlucas/phpdotenv` kommt als **`require-dev`** hinzu und wird nur
geladen, wenn `MAILADMIN_ENV` nicht `prod` ist **und** das Paket installiert ist **und** eine
`.env` existiert. In Produktion ist keine `.env` beteiligt.

**Warum:** Genau der in F1/2 vorgeschlagene Zuschnitt. Die Abhängigkeit landet nicht im
Produktionsbaum, die Entwicklung braucht aber nicht bei jedem Aufruf ein Dutzend `export`.
Rechtfertigung gegenüber E-12: `require-dev`, also kein Zuwachs an ausgelieferter
Angriffsfläche.

**Alternative:** Nur `getenv()`. Ein Entwicklungsaufwand, der sich täglich wiederholt.

**Korrekturkosten:** Trivial — das `Config`-Objekt ist die einzige Leseschnittstelle.

---

## U-12 — Logziel `stderr`, umschaltbar auf Datei

**Frage:** F1/3.

**Entscheidung:** Vorgabe ist `stderr` (Monolog `StreamHandler` auf `php://stderr`), Format JSON.
`MAILADMIN_LOG_TARGET` kann auf einen Dateipfad gesetzt werden; dann schreibt ein
`RotatingFileHandler` dorthin. Ist das Ziel nicht beschreibbar, fällt die Anwendung auf `stderr`
zurück und meldet das einmalig — sie startet nicht wegen eines Logziels nicht.

**Warum:** F1/3 stellt zutreffend fest, dass `stderr` bei Auslieferung als Verzeichnis im
Web-Root (E-8) nicht selbstverständlich sichtbar ist — bei PHP-FPM landet es im FPM-Errorlog, was
je nach Aufbau reicht oder nicht. Die Vorgabe bleibt containerfreundlich, der Betreiber kann
umschalten.

**Alternative:** Datei als Vorgabe. Braucht ein beschreibbares Verzeichnis, das man erst
einrichten muss.

**Korrekturkosten:** Trivial.

---

## U-13 — `var/` ist Pflicht und beschreibbar; ein fehlender Cache ist ein Fehler

**Frage:** F1/4.

**Entscheidung:** `var/cache/` gehört der Anwendung. Vorgabe ist `<projekt>/var/cache`,
überschreibbar über `MAILADMIN_CACHE_DIR`. Ist das Verzeichnis nicht beschreibbar:

- `MAILADMIN_ENV=dev`: Fehler beim Start mit klarer Meldung.
- `prod`: **Fehler beim Start**, nicht stiller Verzicht.

**Warum:** Der Cache ist in Produktion keine Optimierung, sondern die Voraussetzung dafür, dass
Prüfsumme (U-8) und Routentabelle überhaupt existieren. „Stiller Verzicht" hieße, pro Anfrage
YAML zu parsen — ein Leistungsproblem, das erst unter Last auffällt. Ein Fehler beim Start fällt
sofort auf.

Die Artefakte in `var/cache/` sind **Build-Ergebnisse** und werden vom Deployment mitgeliefert,
nicht zur Laufzeit erzeugt. `var/` steht in `.gitignore`.

**Alternative:** Rückfall auf Parsen zur Laufzeit. Verschiebt ein Konfigurationsproblem in ein
Leistungsproblem.

**Korrekturkosten:** Gering.

---

## U-14 — Zeit: UTC überall, Formatierung erst im Frontend

**Frage:** F1/5.

**Entscheidung:**

- PHP läuft mit `date_default_timezone_set('UTC')`, gesetzt im Front-Controller und in `bin/*`.
- Die Datenbankverbindung setzt `SET time_zone = '+00:00'`.
- Alle neuen Spalten für Zeitpunkte sind `DATETIME` und tragen UTC. **Kein `TIMESTAMP`** — dessen
  implizite Zeitzonenumrechnung hängt von der Serverkonfiguration ab.
- Die API liefert Zeitpunkte als ISO-8601 mit `Z` (`2026-07-26T14:30:00Z`).
- Die Anzeige in Ortszeit ist ausschließlich Sache des Frontends, über `Intl` (Architektur 11.4).

**Warum:** Revisionsprotokoll und Tokengültigkeit müssen über Zeitumstellungen hinweg eindeutig
sein. Eine einzige Zeitzone im ganzen System ist die einzige Variante, bei der man beim Lesen
eines Werts nicht wissen muss, wer ihn geschrieben hat.

Bestandsspalten werden nicht angefasst (E-1). Wo sie Ortszeit enthalten, wird das beim Lesen
dokumentiert behandelt, nicht korrigiert.

**Alternative:** Serverzeitzone. Spart nichts und bricht bei Zeitumstellung.

**Korrekturkosten:** Hoch, wenn spät — betrifft jede Zeitspalte und jeden Vergleich.

---

## U-15 — Namensraum `Mailadmin\`, PSR-4 auf `src/`

**Frage:** Nicht gestellt, aber nötig für alle Pakete.

**Entscheidung:** `"Mailadmin\\": "src/"` und `"Mailadmin\\Tests\\": "tests/"`. Die
Verzeichnisstruktur unterhalb `src/` folgt der Architektur, Abschnitt 3, eins zu eins:
`Http/`, `Application/`, `Domain/`, `Infrastructure/{Persistence,Mailstore,Security}/`.

**Korrekturkosten:** Trivial vor dem ersten Release, danach für Erweiterungen brechend.

---

## U-16 — Werkzeuge für Qualitätssicherung

**Frage:** Nicht gestellt. `CLAUDE.md` und die globale Konfiguration verlangen, vor dem Commit
einen Linter laufen zu lassen — es muss also einen geben.

**Entscheidung:**

| Zweck | Werkzeug | Einordnung |
|---|---|---|
| Tests PHP | `phpunit/phpunit` | `require-dev` |
| Statische Analyse | `phpstan/phpstan`, Stufe 8 (`max` für `src/Domain`) | `require-dev` |
| Codestil | `friendsofphp/php-cs-fixer`, Regelsatz `@PSR12` | `require-dev` |
| Tests Frontend | `vitest` | `devDependencies` |
| Linter Frontend | `eslint` mit `typescript-eslint` | `devDependencies` |
| Formatierung Frontend | `prettier` | `devDependencies` |
| Vertragstests | PHPUnit gegen das gebündelte Schema | — |

**Warum:** Alles `require-dev`/`devDependencies`, also kein Widerspruch zu E-12 — die
Entscheidung betrifft die ausgelieferte Abhängigkeitsliste, nicht die Werkbank. PHPStan auf Stufe
8 ist bei einem Projekt ohne ORM die günstigste Absicherung gegen `null`-Fehler in der
Abbildung Altschema → Fachmodell.

`schemathesis` (Architektur 4) ist als Python-Werkzeug **nicht** eingerichtet: es wäre eine
zweite Sprachumgebung im Bauablauf. Die Vertragstests laufen stattdessen als PHPUnit-Tests, die
jeden Endpunkt gegen das gebündelte Schema fahren. Das deckt „jeder Endpunkt gegen
`openapi.yaml`" (Architektur 12.3) ab, aber **nicht** den property-basierten Teil. Offener Punkt,
siehe unten.

**Korrekturkosten:** Gering.

---

## U-17 — Frontend: Vite, React 19, TanStack Query, react-i18next, React Router

> **Die Zeile „Gestaltung" ist durch [U-35](#u-35--material-ui-als-komponentenbibliothek-style-src-unsafe-inline)
> überholt.** Es wird **Material UI** eingesetzt, und die CSP erlaubt dafür `'unsafe-inline'` in
> `style-src`. Der übrige Eintrag gilt unverändert. Das unten stehende Argument gegen eine
> Komponentenbibliothek ist als Beschreibung des Preises weiterhin richtig — es hat die Abwägung
> nur nicht mehr entschieden.

**Frage:** Nicht gestellt; die Architektur nennt React, TanStack Query und `react-i18next`
jeweils als „etwa".

**Entscheidung:**

| Zweck | Wahl |
|---|---|
| Bündler | Vite |
| Oberfläche | React 19 mit TypeScript |
| Serverzustand | TanStack Query v5 |
| Routing | React Router v7 (Datenmodus, ohne Framework-Modus) |
| Mehrsprachigkeit | `react-i18next` |
| Typen | `openapi-typescript` (dev) |
| ~~Gestaltung~~ | ~~reines CSS mit Custom Properties, keine UI-Bibliothek~~ → **Material UI (U-35)** |

**Warum:** Alle vier Laufzeitpakete sind in der Architektur bereits vorgezeichnet. Keine
UI-Bibliothek, weil der Umfang klein ist (Tabellen, Formulare, Dialoge) und weil eine
Komponentenbibliothek der häufigste Weg ist, auf dem Inline-Stile und damit ein Bedarf nach
`unsafe-inline` in die CSP kommen — die laut E-15 tragend ist. Vite ist so konfiguriert, dass es
keine Inline-Skripte erzeugt (`build.modulePreload.polyfill = false`, kein
`vite-plugin-html`-Inlining); ein Test prüft das gebaute `index.html` darauf.

> Der letzte Satz gilt weiterhin und ist von U-35 **nicht** berührt: aufgeweicht wird
> ausschließlich `style-src`. Inline-**Skripte** bleiben verboten, und der Test dazu bleibt.

**Alternative:** Eine Komponentenbibliothek (MUI, Mantine). Schneller zu ansehnlichen Masken,
aber CSP-Konflikte und ein großer Abhängigkeitsbaum. — **Diese Alternative ist mit U-35 gewählt
worden**, einschließlich beider genannter Kosten.

**Korrekturkosten:** Mittel. Ein späterer Wechsel der Gestaltungsebene betrifft jede Ansicht.

---

## U-18 — Ein Konto wird über eine synthetische ID angesprochen

**Frage:** Nicht gestellt, aber der Vertrag verlangt es: die Architektur nennt
`GET /accounts/{id}`, das Altschema hat für `accountuser` aber keinen numerischen
Primärschlüssel, sondern `username` als Schlüssel.

**Entscheidung:** `{id}` in den Kontopfaden ist der **URL-kodierte Cyrus-Benutzername** aus
`accountuser.username`, nicht eine neue Zahl. Der Vertrag nennt den Parameter deshalb `accountId`
mit `type: string`.

**Warum:** Eine numerische ID würde eine neue Spalte an `accountuser` verlangen — eine
Bestandstabelle, die Postfix und `pam_mysql` lesen. Additiv wäre das erlaubt (`ALTER TABLE …
ADD COLUMN … AUTO_INCREMENT` allerdings nicht ohne Schlüssel), aber es schafft einen zweiten
Identitätsbegriff für dasselbe Konto — und Regel eins aus `CLAUDE.md` warnt genau davor, dass ein
Konto schon mehrere Schreibweisen hat. Der gespeicherte Benutzername ist bereits eindeutig und
stabil.

Wichtig: Der Wert wird **nie zerlegt**. Er geht als Ganzes ins Repository, das daraus über
`accountuser.domain_name` eine `MailboxIdentity` baut (Architektur 3.3).

**Alternative:** Numerische Ersatz-ID in einer eigenen Tabelle `mailadmin_account_id`. Sauberere
URLs, aber eine Abbildung, die konsistent gehalten werden muss, obwohl webcyradm parallel
Konten anlegen kann.

**Korrekturkosten:** Hoch, sobald Clients existieren — es ist die Gestalt jeder Konto-URL.

---

## U-19 — ETag über einen Feldschnappschuss, nicht über die ganze Zeile

**Frage:** Nicht gestellt. Die Architektur (7.1) verlangt `ETag`/`If-Match`, sagt aber nur „Hash
des Datensatzes".

**Entscheidung:** Der `ETag` ist `W/"<sha256 der kanonisch serialisierten Antwortdarstellung>"` —
also ein Hash über genau die Felder, die die API ausliefert, in fester Reihenfolge. Ein
schwacher ETag, weil die Darstellung, nicht die Bytefolge gemeint ist.

**Warum:** Ein Hash über die vollständige Datenbankzeile würde bei jeder Änderung an Spalten
umschlagen, die die API nicht zeigt — auch bei solchen, die webcyradm im Parallelbetrieb
schreibt. Das erzeugte `412`-Fehler ohne fachlichen Anlass. Ein Hash über die Darstellung ist
genau das, was der Client gesehen hat.

**Korrekturkosten:** Gering.

---

## U-20 — Paginierung: Vorgabe 50, Höchstwert 200

**Frage:** Nicht gestellt. Die Architektur nennt `?page`/`?per_page` ohne Werte.

**Entscheidung:** `page` beginnt bei 1, `per_page` hat die Vorgabe 50 und den Höchstwert 200,
beides im Schema erzwungen. Die Antwort trägt `meta: {page, per_page, total}`. Sortierung
ausschließlich über `sort` mit einem `enum` je Ressource, Richtung über `order: asc|desc`.

**Warum:** 200 ist die Grenze, bis zu der eine Kontenliste ohne Quota-Abfragen (Architektur 7.2)
in einer Anfrage vertretbar bleibt. Der `enum`-Zwang ist Regel zwei aus `CLAUDE.md`.

**Korrekturkosten:** Trivial.

---

## U-21 — Passwortregeln

**Frage:** Nicht gestellt; die Architektur (14, S-8) verlangt „Passwortregeln zentral im
Fachmodell", nennt sie aber nicht.

**Entscheidung:** Ein Value Object `Password` erzwingt:

- Mindestlänge **12** Zeichen für `adminuser`, **10** für `accountuser`.
- Höchstlänge **72 Byte** für `accountuser` — bcrypt schneidet darüber stillschweigend ab. Ein
  längeres Passwort wird **abgelehnt**, nicht abgeschnitten.
- Keine Zeichenklassen-Pflicht, kein Ablaufdatum. Stattdessen eine Sperrliste der 10 000
  häufigsten Passwörter und eine Prüfung, dass das Passwort nicht den Benutzernamen oder den
  Domainnamen enthält.
- Keine Prüfung gegen einen externen Dienst (kein „Have I Been Pwned"-Abruf) — die Anwendung
  spricht mit keinem Fremdsystem.

**Warum:** Zeichenklassen-Pflichten und Ablaufdaten sind seit NIST SP 800-63B nicht mehr
empfohlen; Länge plus Sperrliste ist wirksamer. Die 72-Byte-Grenze ist keine Stilfrage: bcrypt
ignoriert alles darüber, sodass ein Nutzer mit einem sehr langen Passwort ein deutlich
schwächeres Geheimnis hätte, als er glaubt. Bei `accountuser` niedriger als bei `adminuser`, weil
dort Endnutzer betroffen sind, die ihr Passwort in Mail-Clients eintragen.

**Alternative:** Strengere Regeln. Führt erfahrungsgemäß zu notierten Passwörtern.

**Korrekturkosten:** Trivial — eine Klasse.

---

## U-22 — Vorhandene webcyradm-Daten werden nicht bereinigt, aber sichtbar gemacht

**Frage:** Nicht gestellt; ergibt sich aus E-18, betrifft aber jedes Lesepaket.

**Entscheidung:** Jede Liste, die Bestandsdaten zeigt, führt einen Eintrag, der nicht in das
Fachmodell passt, als eigenen Zustand mit — nicht als Fehler und nicht ausgeblendet:

- `virtual`-Zeilen, die in kein Muster passen: Typ `unknown`, angezeigt (`CLAUDE.md`, Regel zu
  `virtual`).
- Passwort-Hashes in Altverfahren: Feld `password_format` in der Kontodarstellung, filterbar
  (Architektur 5.5).
- Konten, deren Namensschema von der Domainvorgabe abweicht: Feld `naming_scheme` je Konto,
  erkannt, nicht korrigiert (E-14).
- Mailboxen ohne Kontoeintrag: über `GET /maintenance/reconciliation` (A6).

**Warum:** E-18 sagt, es findet keine Bereinigung statt. Damit das keine Blindstelle wird, muss
der Zustand in der Oberfläche stehen. Ein Eintrag, der stillschweigend übergangen wird, ist
schlimmer als einer, der als „unbekannt" auffällt.

**Korrekturkosten:** Keine.

---

## U-23 — Lizenz: AGPL-3.0-or-later

**Frage:** Architektur, Abschnitt 15 — noch festzulegen.

**Entscheidung:** **AGPL-3.0-or-later**, `LICENSE` im Wurzelverzeichnis, `"license": "AGPL-3.0-or-later"`
in `composer.json` und `package.json`.

**Warum:** mailadmin ist eine Neuentwicklung ohne Codeübernahme, also frei in der Wahl. Der
Vorgänger web-cyradm steht unter der GPL — eine Copyleft-Lizenz hält den Nachfolger im selben
Ökosystem und ist gegenüber dessen Gemeinschaft die naheliegende Geste. AGPL statt GPL, weil es
sich um eine Serveranwendung handelt: die GPL greift bei Software, die über das Netz benutzt,
aber nicht verteilt wird, nicht.

**Alternative:** MIT oder Apache-2.0, wenn eine Einbettung in kommerzielle Hosting-Produkte
erwünscht ist. Das ist eine geschäftliche, keine technische Frage — **hier am ehesten zu prüfen.**

**Korrekturkosten:** Vor dem ersten Release trivial, danach nur mit Zustimmung aller
Mitwirkenden möglich. **Diese Entscheidung sollte bewusst bestätigt werden.**

---

## U-24 — Umfang der ersten Fassung

**Frage:** Architektur, Abschnitt 15 — Umfang des ersten Release.

**Entscheidung:** Wie dort vorgeschlagen: **Parität mit webcyradm plus Revisionsprotokoll und
Abgleichsansicht.** Konkret enthalten sind alle Pakete F1–F5, S1–S4, M1–M3, A1–A6, W1–W2.

Ausdrücklich **nicht** in der ersten Fassung:

| Weggelassen | Grund |
|---|---|
| Massen-Passwortrücksetzung (`POST /maintenance/password-resets`) | Nach E-18 in eine spätere Fassung verschoben. Der Pfad steht nicht im Vertrag. |
| Abgleichsansicht gegen die Salt-Konfiguration | E-9. |
| Endnutzer-Selfservice | E-7. |
| Dovecot-Unterstützung | E-2 — die Schnittstelle ist da, die Implementierung nicht. |
| Property-basierte Vertragstests (Schemathesis) | Siehe U-16. |

**Korrekturkosten:** Keine — es ist eine Reihenfolgeentscheidung.

---

## U-25 — Das gesamte Projektverzeichnis liegt im Web-Root; Schutz über `.htaccess`

**Frage:** Vom Projekteigner nachgereicht: „Ich möchte die App dann in dem Webroot hosten können,
d.h. alle Ordner sind ggf. übers Web erreichbar und müssen mit `.htaccess` geschützt werden."

**Entscheidung:** Das Deployment-Modell ist **ein Verzeichnis im Web-Root** (so bereits E-8), und
zwar das **vollständige** Projektverzeichnis — nicht nur `public/`. Der Schutz aller übrigen
Verzeichnisse erfolgt über `.htaccess`, in drei Lagen:

1. **Umleitung im Wurzelverzeichnis.** `.htaccess` im Projektwurzelverzeichnis schreibt jede
   Anfrage nach `public/` um. Damit existiert `…/src/Config/Config.php` als URL gar nicht —
   der Server sucht unter `public/src/Config/Config.php` und findet nichts. Zusätzlich
   `Options -Indexes` und eine Sperre für Dateien, die mit einem Punkt beginnen (`.env`,
   `.php-cs-fixer.cache`).
2. **Sperre je Verzeichnis.** Jedes nicht-öffentliche Verzeichnis (`src/`, `config/`, `bin/`,
   `migrations/`, `openapi/`, `tests/`, `var/`, `vendor/`, `docs/`, `frontend/`,
   `node_modules/`) trägt eine eigene `.htaccess` mit `Require all denied`. Diese Lage greift
   auch dann, wenn `mod_rewrite` fehlt.
3. **Freigabe nur in `public/`.** `public/.htaccess` erteilt `Require all granted` und enthält
   die Front-Controller-Umschreibung sowie die Cache-Regeln aus Architektur 11.3.

**Warum drei Lagen:** Jede einzelne kann durch Serverkonfiguration ausfallen. Lage 1 braucht
`mod_rewrite` und `AllowOverride FileInfo`, Lage 2 nur `AllowOverride AuthConfig`/`Limit`. Zwei
unabhängige Mechanismen sind hier billig.

**Das verbleibende Risiko wird benannt, nicht wegdiskutiert:** Steht der vHost auf
`AllowOverride None`, werden **alle** `.htaccess`-Dateien ignoriert und das gesamte
Projektverzeichnis ist lesbar. Apache liefert `.php`-Dateien dann zwar ausgeführt statt im
Quelltext aus, aber `.env`, `composer.lock`, `var/cache/` und `docs/` wären offen. Dagegen:

- **`AllowOverride All` (mindestens `FileInfo Limit AuthConfig Options=Indexes`) ist eine
  dokumentierte Installationsvoraussetzung**, kein Hinweis am Rande.
- **`bin/check-deployment.php`** prüft eine laufende Installation von außen: es fordert eine
  Liste von Pfaden an, die nicht lesbar sein dürfen, und meldet jeden, der mit `200` antwortet.
  Damit ist die Annahme überprüfbar statt geglaubt.
- **Es liegen keine Geheimnisse in Dateien.** Die Konfiguration kommt aus der Umgebung (U-11);
  in Produktion ist keine `.env` beteiligt. Ein Ausfall des `.htaccess`-Schutzes legt damit
  Quelltext offen — unangenehm, aber bei einer quelloffenen Anwendung (U-23) kein
  Geheimnisverlust. Genau das war der Zweck der Trennung „Konfiguration aus der Umgebung".

**Für nginx** gibt es kein `.htaccess`. `docs/deployment/nginx.conf.example` enthält die
gleichwertige `location`-Konfiguration; die Installationsanleitung nennt sie als Pflicht, weil
dort keine Datei im Verzeichnisbaum den Schutz übernehmen kann.

**Ein Test hält die Lage 2 vollständig:** `tests/Unit/Deployment/` prüft, dass jedes
Verzeichnis der obersten Ebene außer `public/` eine sperrende `.htaccess` besitzt. Legt ein
späteres Paket ein neues Verzeichnis an (etwa `frontend/` in W1), schlägt der Test fehl, statt
dass die Lücke unbemerkt bleibt.

**Alternative:** `public/` als DocumentRoot des vHosts. Sicherer, weil kein
`.htaccess`-Mechanismus beteiligt ist, verlangt aber Zugriff auf die vHost-Konfiguration. Genau
den setzt der Projekteigner nicht voraus. Die Alternative bleibt die **empfohlene** Variante und
wird in der Installationsanleitung als solche beschrieben — der `.htaccess`-Weg ist der
unterstützte Rückfall, nicht die Empfehlung.

**Korrekturkosten:** Keine. Beide Varianten funktionieren mit demselben Code; `public/` als
DocumentRoot ignoriert die Wurzel-`.htaccess` schlicht.

---

## U-26 — Reihenfolge der Middleware-Kette

**Frage:** Nicht gestellt. F1 nennt die vier Middlewares, nicht ihre Reihenfolge.

**Entscheidung:** **RequestId → SecurityHeaders → ErrorHandler → JsonBody.**

**Warum:** Eine Antwort, die der `ErrorHandler` selbst **erzeugt**, läuft nicht mehr durch die
Middlewares, die innerhalb von ihm liegen. Stünde `SecurityHeaders` innen, ginge jede
`500`-Antwort ohne Content-Security-Policy hinaus — ausgerechnet die Antwort, die ein Browser am
ehesten darstellt. `RequestId` bleibt ganz außen, damit auch eine Fehlerantwort die Kennung
trägt, die im Log steht.

Das Paketdokument F1 listet die vier in der Reihenfolge RequestId, ErrorHandler,
SecurityHeaders, JsonBody. **Diese Abweichung ist Absicht** und im Code begründet
(`config/di/core.php`), abgesichert durch einen Test.

**Korrekturkosten:** Trivial — eine Zeile. Aber die Begründung sollte man vorher entkräften.

---

## U-27 — Was am Rand der Anwendung nicht geglaubt wird

**Frage:** Nicht gestellt. Betrifft jede Anfrage.

**Entscheidung:**

- **`X-Forwarded-For` / `X-Forwarded-Proto` werden nicht ausgewertet.** Es gibt keine
  Konfiguration vertrauenswürdiger Proxys, und ohne die ist ein `X-Forwarded-*`-Header
  Benutzereingabe. Betroffen sind die Missbrauchszählung je IP (Architektur 5.6) und absolute
  `Location`-Header. **Läuft die Installation hinter einem Reverse Proxy, ist das nachzurüsten** —
  mit einer Liste vertrauenswürdiger Absender, nicht durch blindes Vertrauen.
- **Ein vom Client mitgeschickter `X-Request-Id` wird nur übernommen, wenn er
  `^[A-Za-z0-9._-]{8,64}$` erfüllt**, sonst ersetzt. Ein ungeprüfter Header ließe einen Client
  Logzeilen fälschen.
- **`$_FILES` wird ignoriert.** Kein Endpunkt nimmt Dateien entgegen.

**Warum:** Alle drei Punkte sind Stellen, an denen Eingabe unbemerkt zu Vertrauen wird.

**Korrekturkosten:** Gering, aber der Proxy-Punkt muss **vor** der Inbetriebnahme hinter einem
Proxy geklärt sein — sonst zählt der Missbrauchsschutz alle Anfragen auf eine IP.

---

## U-28 — Geheimnisse im Log: Maskierung nach Feldnamen, mit Ausnahmeliste

**Frage:** Nicht gestellt. `CLAUDE.md` verlangt, dass Passwörter, Hashes und Token nie geloggt
werden — ein Vorsatz braucht einen Mechanismus.

**Entscheidung:** Ein Monolog-Processor maskiert Kontextfelder, deren Name eine von rund 16
bekannten Zeichenfolgen **enthält** (`password`, `token`, `secret`, `hash`, …). Dazu:

- **Eine Ausnahmeliste mit exakter Übereinstimmung**: `password_format`, `hash_format`,
  `hash_algorithm`, `token_type`. Diese Felder **müssen** sichtbar bleiben — U-22 verlangt genau,
  dass das Hash-Format angezeigt wird. Die Liste greift nur bei exakter Gleichheit, nicht als
  Präfix.
- **Objekte im Kontext werden durch ihren Klassennamen ersetzt, nicht durchlaufen.** Sonst
  serialisiert Monolog die Eigenschaften, und ein Wertobjekt mit einem Hash darin wäre an der
  Namensprüfung vorbei. **Folge für alle Pakete:** `['exception' => $e]` verliert den Stacktrace —
  übergib Skalare.
- **Ausnahmemeldungen werden geloggt** (nie ausgeliefert). Der Processor kann nicht in eine
  Zeichenkette hineinsehen: **niemals ein Geheimnis in eine Ausnahmemeldung schreiben.**

**Warum:** Teilstring-Prüfung fängt `db_password`, `passwordHash`, `smtp_relay_password`, ohne
dass jemand eine Liste pflegt. Die Ausnahmeliste ist der Preis dafür und ist kurz genug, um
prüfbar zu bleiben.

**Korrekturkosten:** Trivial — eine Klasse.

---

## U-29 — Kleinere Festlegungen aus dem Projektgerüst

Einzeln zu klein für einen eigenen Eintrag, zusammen die Grundlage, auf der alles läuft.

| # | Festlegung | Grund |
|---|---|---|
| a | `firebase/php-jwt` in **^7.1** statt ^6.10 | Composer verweigert alle 6.x wegen der Sicherheitsmeldung `PKSA-y2cr-5h3j-g3ys`. Gleiches Paket, nur neuere Reihe — kein Zuwachs an Abhängigkeiten. |
| b | **`MAILADMIN_ENV` ist die einzige Pflichtvariable**, ohne Vorgabewert. Leer zählt als nicht gesetzt. | U-9, U-11 und U-13 verzweigen alle darauf. Falsch zu raten ist in beide Richtungen schädlich. |
| c | `ServerRequestFactory` und `ResponseEmitter` selbst geschrieben (~110 Zeilen) | `nyholm/psr7` enthält keine Fabrik aus den Superglobals; die läge in `nyholm/psr7-server`. Eigencode statt Erweiterung einer geschlossenen Liste (E-12). |
| d | Ein **zweiter, containerfreier Fehlerpfad** (`StartupFailureResponse`) | Ein Konfigurationsfehler passiert, **bevor** die Middleware-Kette existiert. Er liefert dieselbe `problem+json`, dieselben Header und eine eigene Request-Id. |
| e | CLI-Skripte heißen `bin/*.php` | Sonst erfassen PHPStan und PHP-CS-Fixer sie mit ihren Vorgabemustern nicht. |
| f | PHPStan läuft in **zwei Durchgängen** (`analyse:app` Stufe 8, `analyse:domain` Stufe max), mit `--memory-limit=1G` | PHPStan kennt nur eine Stufe je Lauf. Der Speicherwert, weil die Vorgabe von 128 MB den Parallelbetrieb abstürzen lässt. |
| g | **PHP-DI kompiliert den Container nicht** | Das Kompilat entstünde zur Laufzeit in `var/cache` — Widerspruch zu U-13 („Bauartefakte") und U-8 („kein Kompilieren unter Last"). Gehört, wenn überhaupt, in `composer build`. |
| h | `.gitignore` nutzt `/var/*` mit `!/var/.gitignore` und `!/var/.htaccess` | Ein pauschales `/var/` lässt sich von innen nicht aufheben; U-13 braucht das Verzeichnis im Checkout, U-25 die Sperrdatei darin. |
| i | **Kein `Makefile`** (U-8 stellt eines in Aussicht) | Es würde Bauschritte umhüllen, die es noch nicht gibt. Die Composer-Skripte reichen; ein `Makefile` kann jederzeit dazukommen. |
| j | Kommentare in `.env.example` auf **Englisch** | Konfiguration wird als Code behandelt (Konvention aus `CLAUDE.md`). |

---

## U-30 — Kein Redoc; der Vertrag wird als JSON ausgeliefert

**Frage:** F2/5 fragt, ob Redoc unter `/api/docs` ausgeliefert wird. U-9 sagte „ja, außerhalb der
Produktion". Bei der Umsetzung stellte sich heraus, dass das nicht geht.

**Entscheidung:** **Redoc wird nicht ausgeliefert.** `/api/docs` leitet auf
`/api/docs/openapi.json` um, das den gebündelten Vertrag als JSON liefert — abgeschaltet in
Produktion, über `MAILADMIN_API_DOCS=1` einschaltbar.

**Warum:** Redoc rendert über styled-components und braucht `style-src 'unsafe-inline'`. Die CSP
trägt nach E-15 das Restrisiko des Zugriffstokens im `localStorage`; sie für einen
Dokumentationsbetrachter aufzuweichen wäre ein Eskalationsfall
(`04-detailkonzept.md`, Abschnitt 5) und die Sache nicht wert. Ein beliebiger Betrachter kann auf
die JSON-Datei gerichtet werden und läuft dann im eigenen Ursprung.

**Damit ist U-9 in diesem Punkt korrigiert.** Die übrigen Festlegungen aus U-9
(Anfragevalidierung immer an, Antwortvalidierung nur außerhalb der Produktion) gelten
unverändert.

> **Nachtrag: Das obige Argument ist durch [U-35](#u-35--material-ui-als-komponentenbibliothek-style-src-unsafe-inline)
> entfallen.** Die CSP erlaubt seither `'unsafe-inline'` in `style-src`; styled-components wäre
> kein Hindernis mehr. Die Entscheidung „kein Redoc" bleibt vorerst bestehen, ruht aber nur noch
> auf dem zweiten Grund — ein Megabyte gebündeltes JavaScript im Repository für einen Betrachter,
> den man auch auf `/api/docs/openapi.json` richten kann. Ob das trägt, entscheidet der
> Projekteigner unter **O-12**.

**Korrekturkosten:** Gering. ~~Der Preis wäre die CSP.~~ Seit U-35 nur noch die zusätzliche
Abhängigkeit.

---

## U-31 — Festlegungen aus dem API-Vertrag

Vom F2-Paket getroffen, weil sie den Vertrag für alle Folgepakete prägen.

| # | Festlegung | Grund |
|---|---|---|
| a | **Zwei Bündelartefakte:** `openapi.bundled.yaml` (Prüfsummenbasis, Eingabe für `openapi-typescript`) und `.json` (Laufzeit) | `json_decode` statt YAML-Parse je Anfrage — genau die Cache-Forderung aus Architektur 4. |
| b | **Zwei Prüfsummen** in `routes.php`: über das Bündel (überall geprüft) und über die Quelldateien (nur in der Entwicklung) | Erkennt sowohl ein veraltetes Deployment als auch eine im Editor geänderte Teildatei. |
| c | **Antwortvalidierung protokolliert, ändert die Antwort nicht** | Eine Dokumentationslücke soll nicht wie ein Ausfall aussehen. Erzwungen wird sie in den Vertragstests, nicht im Betrieb. |
| d | **Der Dispatcher ruft keinen Handler ohne Validierungsmarke auf** | Wären Validator und Router über den Mount-Präfix uneinig, liefe sonst eine **ungeprüfte** Anfrage in einen Handler. Jetzt `500` statt still. |
| e | `MAILADMIN_ENV=test` darf Artefakte neu bauen (U-8 nannte nur `dev`) | Ein Testlauf muss aus einem frischen Checkout funktionieren. `test` ist nie eine ausgelieferte Installation; der Verweigerungspfad für `prod` bleibt getestet. |
| f | Fehlender oder ungültiger Token → **`401` mit `WWW-Authenticate: Bearer`**, nicht `422` | Ein fehlender Token ist kein Formfehler des Anfragekörpers. |
| g | **Im `errors`-Feld und im Log stehen nur Feldpfad und verletztes Schlüsselwort**, nie der Wert | Die Meldungen des Validators interpolieren den eingesandten Wert — bei einem Passwortfeld stünde das Passwort in der Antwort. Ein Test prüft das. |
| h | **Kein `default`-Antwortobjekt**; jede Operation zählt ihre Statuscodes auf, `500` eingeschlossen | Mit `default` sähe die Antwortvalidierung einen undeklarierten Statuscode nicht. |
| i | `justinrainbow/json-schema` wird **ausdrücklich** in `require` aufgenommen | Der Vertrags-Linter prüft zusätzlich gegen das offizielle OpenAPI-3.0-Metaschema. Das Paket war bereits transitiv da; undeklariert könnte ein `composer update` es entziehen. Kein neues Paket im Baum, nur eine ehrliche Deklaration (E-12). |

---

## U-32 — Festlegungen aus dem Domänenmodell

| # | Festlegung | Grund |
|---|---|---|
| a | **Zwei Türen je Wertobjekt:** `fromString()` streng für neue Eingaben, `fromStoredValue()` duldsam für Bestandszeilen, dazu `isConventional()` | U-22 auf Wertobjektebene. Eine unkonventionelle Bestandsadresse bleibt lesbar und fällt auf, statt eine laufende Mailbox unsichtbar zu machen. |
| b | **Groß-/Kleinschreibung wird erhalten, Vergleiche sind case-insensitiv** | `accountuser.username` ist `varchar binary`. Ein kleingeschrieben erzeugter Name träfe beim `renamemailbox` das falsche Postfach. |
| c | **Punycode ist die gespeicherte Form**; Unicode-Domains werden abgelehnt | Die Umwandlung bräuchte `ext-intl` — Widerspruch zu E-12. Sie gehört an den Rand; der Browser kann es. |
| d | **`0` bei Quota heißt zweierlei, je nach Herkunft:** aus Cyrus null Byte, aus einer Bestandsspalte unbegrenzt | Zwei getrennte Fabrikmethoden statt einer magischen Zahl. „Unbegrenzt" und „nicht gemessen" sind ebenfalls verschiedene Zustände. |
| e | Der `+`-Trenner (`local+detail@domain`) wird erkannt, aber **nie automatisch angewandt** | Ob eine Adresse mit `+` als eigenes Konto oder als Detailadresse gilt, entscheidet die Fachlichkeit, nicht das Wertobjekt. |
| f | Reservierte Adressen (`postmaster` u.a.) sind eine **Feststellung, kein Verbot** | A3 entscheidet, ob gewarnt oder verweigert wird. |
| g | Sieve-Skripte mit CRLF, Vacation-Text immer als `text:`-Literal mit Dot-Stuffing, kein `:regex`, Vorgabe `:days` = 7 | Das Altsystem verwendete 1 Tag — das erzeugt bei Mailinglisten eine Antwortflut. |

**Zwei Punkte sind gegen die Testumgebung zu bestätigen** und stehen deshalb unten als offene
Punkte: die Form des Ordnernamens im `fqun`-Schema und die Spool-Pfad-Darstellung.

---

## U-33 — Festlegungen zum Passwortverfahren

Ergänzt U-21. Das Zielformat je Tabelle (Argon2id für `adminuser`, bcrypt für `accountuser`) ist
davon **nicht** berührt — es bleibt nach E-3 unverhandelbar.

| # | Festlegung | Grund |
|---|---|---|
| a | **bcrypt-Kostenfaktor 10** (Untergrenze 10, Obergrenze 15); **Argon2id 64 MiB / 4 / 1** | `pam_mysql` zahlt den bcrypt-Aufwand bei **jeder** IMAP- und SMTP-Anmeldung, nicht nur beim Setzen. Vor einer Erhöhung auf der Zielhardware messen. |
| b | **Es gibt nirgends einen Algorithmus-Parameter.** Das Verfahren folgt aus dem `PasswordSubject` im `Password`-Objekt | Ein Aufrufer kann für `accountuser` kein `crypt(3)`-fremdes Verfahren wählen, weil er gar keine Wahl hat. Zusätzlich prüft der Hasher das erzeugte Ergebnis nach und ruft für `accountuser` `crypt()` mit demselben Aufruf auf, den `pam_mysql` macht. |
| c | **Absolute Obergrenze 4096 Byte** für jedes Subjekt | U-21 nennt für `adminuser` keine Obergrenze. Unbegrenzte Eingabe ist ein billiger Hashing-DoS. |
| d | **Steuerzeichen und ungültiges UTF-8 werden abgelehnt** | Ein NUL-Byte schneidet das Geheimnis innerhalb von `crypt(3)`/`pam_mysql` ab — das tatsächlich wirksame Passwort wäre kürzer als das geprüfte. Leerzeichen, auch führende und schließende, bleiben erlaubt und werden nicht beschnitten. |
| e | Mindestlänge zählt **Zeichen**, Höchstlänge zählt **Byte** | Die Zeichengrenze ist die Nutzeraussage, die Byte-Grenze ist die technische von bcrypt (72 Byte). |
| f | **Zwei Erkennungsregeln:** 13 Zeichen aus dem crypt-Alphabet sind DES-crypt, 40 Hexzeichen sind SHA1 — **nicht** Klartext. Alles Hash-Förmige, aber Unbekannte (`$argon2i$`, `$y$`, `{SSHA}`) ist `Unknown`, nie `Plaintext`. Eine leere Spalte ist `Unknown` | Sonst könnte sich jemand, der die Tabelle lesen kann, mit dem abgetippten Hash anmelden. Dafür gibt es einen Test. |
| g | **Die Sperrliste umfasst 12 261 Einträge**, nicht die reinen Top-10 000 | Befund bei der Umsetzung: von den 10 000 häufigsten Passwörtern sind nur **51** mindestens 10 Zeichen lang und **10** mindestens 12 — gegen die Mindestlängen aus U-21 ist die Liste zu über 99 % unerreichbar. Deshalb zusätzlich der 10–72-Zeichen-Ausschnitt der Top-100 000. Quelle SecLists (MIT), im Dateikopf ausgewiesen. Keine neue Abhängigkeit. |
| h | Erzeugte Passwörter nutzen ein **diktierbares Alphabet** (ohne `0 O 1 l I`), Form `Xk9m-Pq4t-Wz7n-Rb3d`, rund 93 Bit | Ein Administrator gibt ein neues Passwort oft mündlich oder telefonisch weiter. Lesbarkeit schlägt Dichte, solange die Entropie hoch genug bleibt. |
| i | Beim Rehash gilt: **scheitert die heutige Regelprüfung an einem Altpasswort, wird nicht neu gehasht und trotzdem angemeldet** | E-13 sagt, Bestandshashes bleiben gültig. Ein Nutzer mit einem alten, kurzen Passwort darf sich nicht plötzlich nicht mehr anmelden, nur weil die Regeln strenger geworden sind. |

**Nicht überprüft:** ob im Produktivbestand tatsächlich MySQL-`PASSWORD()`- oder Klartext-Hashes
vorkommen (S1, offene Punkte 5 und 6). Das hätte einen Lesezugriff auf die Produktivdatenbank
verlangt, und der Nutzen wäre gering: die Unterstützung kostet je zwei Zeilen, ihr Weglassen
würde genau die Konten aussperren, die Architektur 5.5 aufzählt.

---

## U-34 — Festlegungen zu Persistenz und Migrator

| # | Festlegung | Grund |
|---|---|---|
| a | **Keine Transaktion um eine Migration.** Stattdessen erzwungene Idempotenz: der Prüfer verlangt `IF NOT EXISTS` bei `CREATE TABLE`, `CREATE INDEX` und jedem `ALTER … ADD`, sowie `INSERT IGNORE`/`ON DUPLICATE KEY UPDATE` | MySQL und MariaDB committen DDL implizit. Ein `ROLLBACK` nimmt eine angelegte Tabelle nicht zurück — es sieht nur so aus. Idempotenz ist die einzige Absicherung, die tatsächlich greift. |
| b | **Die additive Prüfung arbeitet nach Erlaubnisliste, nicht nach Verbotsliste** | Eine Verbotsliste müsste `CREATE OR REPLACE VIEW`, `CONVERT TO CHARACTER SET`, `DELIMITER` und künftige Dialekterweiterungen vorhersehen. Regel 1 aus `CLAUDE.md` soll nicht an Fantasie hängen. 30 abgelehnte und 9 akzeptierte Statements sind getestet. |
| c | **Zielsystem ist MariaDB**, nicht MySQL 8 | `ADD COLUMN IF NOT EXISTS` gibt es nur in MariaDB. Damit hängt (a) an dieser Annahme. Sie deckt sich mit README, „Voraussetzungen". |
| d | **Jede Sortier-Allowlist braucht einen Tiebreaker** | Ohne eindeutige Zweitsortierung kann eine Zeile auf Seite 1 **und** auf Seite 2 erscheinen. |
| e | Allowlist-**Werte** müssen `spalte` oder `alias.spalte` sein — auch unsere eigenen | Kein Ausdruck kommt je in ein `ORDER BY`. Wer nach einem Ausdruck sortieren will, wählt ihn unter Alias aus. Das ist Regel 2, technisch erzwungen: `OrderBy` hat einen privaten Konstruktor und genau eine Fabrik, die eine Allowlist verlangt. |
| f | **`sql_mode` wird nicht gesetzt** | Die Sitzung teilt sich den Server mit Postfix und `pam_mysql`. |
| g | **Keine JOINs zwischen `mailadmin_*` und Bestandstabellen** — zwei Abfragen, Zuordnung in PHP | Kollation und Zeichensatz der Bestandstabellen sind nicht unter unserer Kontrolle (siehe O-11). |
| h | Rückstand im Schema ergibt `503` mit `Retry-After: 30` und `migration.schema_outdated`; **`/health` antwortet weiter** | Ein Monitoring muss unterscheiden können zwischen „Dienst tot" und „Dienst verweigert bewusst". |
| i | Keine Rückwärtsmigration | Architektur 10.2 verlangt vorwärtsgerichtete Migrationen. Ein `down`-Pfad, der nur additive Schritte zurücknimmt, wäre ein Werkzeug für genau den Fall, den E-1 verbietet. |

---

## U-35 — Material UI als Komponentenbibliothek; `style-src 'unsafe-inline'`

**Frage:** Vom Projekteigner nachgereicht: Material UI soll als Komponentenbibliothek für die
Oberfläche eingesetzt werden. U-17 hatte sich gegen jede UI-Bibliothek entschieden, und zwar mit
der Content-Security-Policy als Hauptargument. Diese Entscheidung wird hiermit korrigiert.

**Entscheidung:**

1. **Material UI (`@mui/material`) ist die Komponentenbibliothek des Frontends.** Damit entfällt
   die Festlegung „reines CSS mit Custom Properties, keine UI-Bibliothek" aus U-17. Die übrigen
   Festlegungen aus U-17 — Vite, React 19, TanStack Query, React Router v7, `react-i18next`,
   `openapi-typescript` — gelten unverändert.
2. **Die Content-Security-Policy wird um `'unsafe-inline'` in `style-src` erweitert**, und nur
   dort:

   ```text
   default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
   img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';
   base-uri 'none'; object-src 'none'
   ```

   `script-src 'self'` bleibt unangetastet, `unsafe-eval` kommt nirgends hinzu.
3. **Schriften und Symbole werden mitgeliefert, nicht nachgeladen.** MUIs Standardanleitung bindet
   Roboto und die Material Icons über das Google-Fonts-CDN ein. Das ist hier ausgeschlossen —
   `default-src 'self'` verbietet es ohnehin, und „keine Fremdinhalte im Frontend" (Architektur
   5.2) ist keine Frage der Bequemlichkeit. Verwendet werden `@fontsource/roboto` und
   `@mui/icons-material`, beide als lokale Abhängigkeit gebündelt.
4. **Keine Inline-Stile über `sx` hinaus schleift die Regel nicht weiter auf:** `script-src`
   bleibt der Teil der Policy, der nicht verhandelbar ist. Ein Bundler, der Inline-**Skripte**
   erzeugt, wird weiterhin geändert, nicht die Policy.

**Warum die Aufweichung vertretbar ist:** MUI stylt zur Laufzeit über Emotion, das `<style>`
-Elemente in das Dokument schreibt. Die MUI-Dokumentation ist dazu unmissverständlich: für
statisch ausgelieferte Seiten — genau unser Fall nach E-6 — ist `'unsafe-inline'` *„the only
option"*; ein Nonce-Verfahren beschreibt MUI ausschließlich für serverseitiges Rendering.

Entscheidend ist, **was die CSP hier eigentlich trägt**. Das Restrisiko aus E-15 ist das
Zugriffstoken im `localStorage`, und an dieses Token kommt ein Angreifer nur über
**Skriptausführung**. Genau die verhindert `script-src 'self'` — und dieser Teil bleibt
unverändert. Die Aufweichung erlaubt injizierte Stile, nicht injizierte Skripte.

Der verbleibende Rest ist CSS-basierte Exfiltration (Attributselektoren plus eine externe URL).
Auch die bleibt weitgehend blockiert, weil `img-src 'self' data:`, `connect-src 'self'` und
`default-src 'self'` keinen externen Ursprung zulassen — CSS kann Daten dann nirgendwohin senden.
Was tatsächlich verloren geht, ist die Absicherung gegen Verunstaltung der Oberfläche und gegen
Clickjacking-artige Umdekoration innerhalb der eigenen Seite. Das ist ein realer, aber deutlich
kleinerer Verlust als der Wortlaut „CSP aufgeweicht" nahelegt.

**Was dadurch schlechter wird, ausdrücklich benannt:**

- Die CSP ist nicht mehr die „wichtigste Einzelmaßnahme gegen XSS" (Architektur 5.2) in der
  bisherigen Reinform. Sie bleibt es für Skripte — aber die Formulierung, sie enthalte *kein*
  `unsafe-inline`, stimmt nicht mehr und wurde überall nachgezogen.
- Die Abhängigkeitsfläche des Frontends wächst erheblich. E-12 betrifft die PHP-Seite, ist hier
  also nicht verletzt; der Zusammenhang ist trotzdem zu sehen: MUI und `@mui/icons-material` sind
  die mit Abstand größten Einzelabhängigkeiten des Projekts.
- Die Bindung an einen Anbieter mit jährlichem Major-Zyklus ist bei einem Wartenden ein
  Wartungsposten. Das ist der Preis für schnellere Masken.

**Folge für U-30 (kein Redoc):** Dessen Begründung war ausschließlich die CSP — Redoc rendert
über styled-components und hätte `style-src 'unsafe-inline'` gebraucht. **Dieses Hindernis
existiert nicht mehr.** Die Entscheidung bleibt vorerst bestehen, ruht aber jetzt allein auf dem
zweiten Argument: ein Megabyte gebündeltes JavaScript im Repository für einen Betrachter, den man
auch auf `/api/docs/openapi.json` richten kann. Ob das genügt, ist neu zu entscheiden — als
**O-12** aufgenommen, nicht hier miterledigt.

**Alternative 1 — Nonce, Shell über den Front-Controller.** Der Front-Controller liefert
`index.html` statt Apache und injiziert je Anfrage ein Nonce in Header und
`<meta name="csp-nonce">`; Emotion erhält es über `createCache({ nonce })`. Hielte den Wortlaut
der bisherigen Regel und schlösse nebenbei die Lücke, dass die statisch ausgelieferte Shell
**heute gar keine CSP trägt** (siehe unten). Kostet eine Änderung an der Auslieferung und ist
für reines Client-Rendering von MUI nicht dokumentiert.

**Alternative 2 — Pigment CSS**, MUIs Zero-Runtime-Engine: extrahiert das CSS zur Bauzeit, die
CSP bliebe vollständig streng. Verworfen, weil MUI sie selbst als *„currently in the early alpha
stage of development"* führt. Die Gestaltungsebene betrifft nach U-17 jede Ansicht; sie auf eine
Alpha-Schnittstelle zu stellen, ist bei einem Wartenden kein guter Tausch.

**Nebenbefund, unabhängig von MUI:** Die CSP wird allein von
`Mailadmin\Http\Middleware\SecurityHeadersMiddleware` gesetzt, also nur auf `/api/*`. Die statisch
über `public/.htaccess` ausgelieferte `index.html` — ausgerechnet das Dokument, in dem eine
XSS-Lücke wirksam würde — bekommt weder von dort noch von den vHost-Beispielen einen
CSP-Header. Diese Lücke besteht schon vor dieser Entscheidung und ist als **O-13** aufgenommen;
W1 muss sie schließen, sonst ist die ganze Abwägung dieses Eintrags gegenstandslos.

**Korrekturkosten:** Für MUI hoch — nach U-17 betrifft die Gestaltungsebene jede Ansicht; ein
späterer Ausbau bedeutet, jede Maske neu zu bauen. Für die CSP gering: die Policy steht als
Konstante in `src/Http/SecurityHeaders.php`, ein Zurücknehmen ist eine Zeile plus Tests — nur ist
sie dann eben unvereinbar mit MUI.

---

## U-36 — Die Persistenz-Integrationstests bekommen eine eigene, leere Datenbank

**Frage:** Nicht gestellt, aber beim Hochfahren von F5 sofort sichtbar geworden. Die Tests unter
`tests/Integration/Persistence/` legen Tabellen an und löschen sie wieder. `DatabaseTestCase`
hat dafür eine Sicherung: es **überspringt**, wenn die Bestandstabellen der Zieldatenbank Zeilen
enthalten — sonst würde ein versehentlich auf die Produktivdatenbank gerichteter Lauf `domain`
und `accountuser` löschen.

Genau diese Sicherung greift aber auch gegen F5, denn F5s Datenbank **soll** Fixtures enthalten:
jeder andere Integrationstest braucht sie. Eine Datenbank, zwei widersprüchliche Anforderungen.

**Entscheidung:** Eine **zweite, absichtlich leere Datenbank** `mail_test` im
Testumgebungs-Container (`docker/db/init/30-disposable-database.sql`).
`DatabaseTestCase` liest neu `MAILADMIN_TEST_DB_DISPOSABLE_DSN` und verwendet sie **statt**
`MAILADMIN_TEST_DB_DSN`, wenn sie gesetzt ist. Ist sie es nicht, ändert sich nichts.

Die neue Datenbank bekommt zusätzlich `DROP` — die Bestandsdatenbank ausdrücklich **nicht**.
Das Vorenthalten von `DROP` auf `mail` macht aus Regel eins in `CLAUDE.md` („Migrationen sind
additiv") etwas, das der Server erzwingt statt etwas, das ein Reviewer bemerken muss. Die Tests
brauchen das Gegenteil; sie in eine eigene Datenbank zu setzen ist der Weg, beides zu behalten.

**Warum:** Die Sicherung ist richtig und bleibt unverändert. Der Ausweg kostet eine Datei und
eine Umgebungsvariable, und ohne ihn übersprang ein vollständiger Lauf **19 von 92**
Integrationstests, darunter den Nachweis, dass der Migrator gegen das Altschema durchläuft — eine
Zusage aus F5s Definition of Done.

**Alternative:** Die Sicherung um eine Ausnahme für F5 erweitern (etwa
`MAILADMIN_TEST_DB_IS_DISPOSABLE=1`). Billiger, aber es verlegt die Entscheidung „diese Datenbank
darf zerstört werden" von der Zieladresse in einen Schalter — und ein Schalter wandert.

**Korrekturkosten:** Trivial.

---

## U-37 — Die Mailstore-Schnittstellen liegen im Fachmodell, mit eigenem Fehlervertrag

**Frage:** Architektur 9.1 zeigt `interface Mailstore` und `interface SieveStore`, sagt aber
nicht, wo sie liegen — und ein Port im Fachmodell dürfte laut `CLAUDE.md` („`Domain/` — kein SQL,
kein IMAP") keine Infrastrukturklasse nennen, auch nicht in einem `@throws`.

**Entscheidung:**

1. Die Ports liegen in **`src/Domain/Mailstore/`**, wie es das M2-Paketdokument in seiner
   Artefaktliste bereits vorsieht: `Mailstore.php`, `SieveStore.php`, dazu `MailboxEntry.php`
   für eine Zeile einer Auflistung.
2. Ihr Fehlervertrag ist die Schnittstelle **`MailstoreFailure`** (`errorCode()`,
   `isRetryable()`), die `Infrastructure\Mailstore\MailstoreError` implementiert. Anwendungsfälle
   fangen `MailstoreFailure`; das Fachmodell nennt keine Infrastrukturklasse.
3. Die Ports weichen an **zwei Stellen** von Architektur 9.1 ab, beide additiv:
   - `Mailstore::createDefaultFolders()` — Architektur 9.3 verlangt das Anlegen der
     Standardordner über SASL-Proxy-Auth, und ein Anwendungsfall muss das gegen die Attrappe
     üben können. Ohne die Methode am Port wäre genau der Vorgang, dessen Kern „ohne das
     Passwort des Nutzers" ist, der einzige, der nicht getestet werden kann.
   - `SieveStore::scriptNames()` — A6 muss die Altskripte aufzählen können, die webcyradm
     hinterlassen hat. Eine Schnittstelle, die nicht aufzählen kann, ist eine, deren Reste
     unsichtbar bleiben (E-18, U-22).
4. `listMailboxes()` liefert `MailboxEntry` und **nicht** `MailboxIdentity`. Eine Auflistung
   weiß nicht, wo der Local Part endet — diese Information steht nur in
   `accountuser.domain_name` (Architektur 3.3). Sie muss eine Mailbox **benennen** können, die
   sie nicht deuten kann; genau dafür gibt es A6.

**Warum:** Eine Schnittstelle mit `@throws \Mailadmin\Infrastructure\…` im Fachmodell wäre die
Art von Schichtbruch, die niemand mehr zurücknimmt. Die Kosten sind eine Datei mit zwei Methoden.

**Alternative:** Die Ports nach `src/Application/` legen. Verteidigbar, aber `Quota`, `Acl` und
`AclSet` liegen bereits in `src/Domain/Mailstore/`, und der Port ohne seine Wertobjekte
anderswohin zu legen trennt, was zusammengehört.

**Korrekturkosten:** Gering, solange nur M2 und M3 implementieren.

---

## U-38 — Was gegen einen echten Cyrus geprüft wurde, und was dabei herauskam

M1 und M2 sind gegen die Testumgebung (F5, Cyrus 3.10.2 auf Debian trixie) gefahren worden. Die
folgenden Punkte sind **gemessen, nicht angenommen**. Drei davon sind Voraussetzungen an den
Server und gehören in die Installationsanleitung, nicht nur hierher.

### Voraussetzungen an die Cyrus-Konfiguration

| # | Befund | Folge |
|---|---|---|
| a | **`allowstarttls` ist ab Werk `no`.** Cyrus prüft `allowstarttls && tls_enabled()`. Mit der Vorgabe ist `STARTTLS` auf 143 **und auf 4190** kein unbeworbenes, sondern ein *unbekanntes* Kommando — obwohl Zertifikat und Schlüssel konfiguriert sind und IMAPS auf 993 einwandfrei läuft. | timsieved hat in Cyrus' Standardkonfiguration **keinen** impliziten TLS-Port. Ohne diese Zeile ist ManageSieve (M3) nur im Klartext erreichbar, und Befund S-5 sagt, dass das keine Option ist. **Pflichteinstellung.** |
| b | **`allowusermoves` ist ab Werk `no`.** Damit scheitert *jedes* `RENAME user/a user/b` mit „Operation is not supported on mailbox" — nicht nur über Domaingrenzen hinweg. | Architektur 8.1 macht `renamemailbox` zur ersten Hälfte einer Domainumbenennung und eines Schemawechsels (E-14). Ohne die Zeile kann mailadmin die Datenbankhälfte tun und die Mailboxhälfte nie — der Fehler des Altsystems, per Konfiguration reproduziert. **Pflichteinstellung.** |
| c | `delete_mode` ist ab Werk `delayed`: `DELETE` verschiebt nach `DELETED/<name>/<hex Sekunden>`. Zwei Löschvorgänge derselben Mailbox **innerhalb einer Sekunde** kollidieren auf diesem Ziel, und der zweite wird mit — ausgerechnet — „Mailbox already exists" abgelehnt. | In Produktion unschädlich, in einer Testschleife nicht. F5 setzt deshalb `delete_mode: immediate`; der Adapter filtert den `DELETED/`-Namensraum unabhängig davon aus jeder Auflistung, und `MailboxOperationFailed::deletionRefused()` ist als **einziger** Fehler dieser Klasse wiederholbar. |

### Befunde, die den Adapter geprägt haben

| # | Befund | Umsetzung |
|---|---|---|
| d | **Cyrus 3.10 sendet zu `CREATE`, `DELETE`, `SETQUOTA` und `SETACL` überhaupt keinen Antwortcode.** Kein `[ALREADYEXISTS]`, kein `[NONEXISTENT]`. | Nur auf den Code zu schauen hätte auf genau dem Zielserver *nichts* eingeordnet; die englische Meldung zu lesen verbietet M2. Der Adapter **fragt stattdessen nach**: fehlt der Code, stellt eine zweite `LIST`-Abfrage fest, ob die Mailbox da ist. Ein Rundlauf mehr, nur im Fehlerfall, und keine Liste von Serverformulierungen, die gepflegt werden müsste. |
| e | **Eine per Proxy-Auth geöffnete Sitzung sieht den *persönlichen* Namensraum.** `CREATE user/anna.example.test/Sent` und `CREATE INBOX/Sent` werden beide mit `OK` beantwortet und legen beide die **falsche** Mailbox an (`…/user/…` bzw. `…/INBOX/Sent`). | Standardordner werden mit dem **relativen** Namen angelegt (`Sent`). Das ist der Fehlertyp, bei dem jedes Kommando gelingt und die Daten trotzdem falsch sind — deshalb steht er im Code und hier. |
| f | Ein Administrator hat **keine** impliziten Rechte auf der Mailbox eines Nutzers; `DELETE user/x` scheitert an „Permission denied". | Der Adapter setzt sich vor dem Löschen selbst die nötige ACL. Das Altsystem hat sich dafür als der Nutzer angemeldet. |
| g | `RENAME` bewegt die Ordner **mit**, auch über Domaingrenzen — sofern (b) gesetzt ist. | Ein einziges `RENAME`; kein Nachbauen von Hand. Damit ist M2s offener Punkt 6 beantwortet. |

### O-8 und O-9

**O-8 ist beantwortet, und die Annahme war richtig.** Gegen Cyrus 3.10 gemessen:

```text
dotted   CREATE Sent  (als Nutzer)  ->  user/probe.leer.test/Sent
fqun     CREATE Sent  (als Nutzer)  ->  user/probe/Sent@leer.test
```

Die `fqun`-Form ist exakt das, was `MailboxIdentity::imapFolder()` erzeugt: der Domainteil steht
am Ende des **gesamten** Namens, nicht hinter dem Benutzernamen. Ein eigener Integrationstest
(`CyrusMailstoreIntegrationTest::testTheFqunFolderNameIsTheOneCyrusStores`) hält das fest, mit
einer Fehlermeldung, die sagt, was zu ändern wäre, falls es je kippt.

**O-9 ist beantwortet, soweit es von außen geht.** Der Spool-Pfad ist reine Diagnoseausgabe und
über IMAP nicht sichtbar; nichts in der Anwendung liest oder schreibt den Spool. Prüfbar — und
geprüft — ist der Teil, um den es bei der Asymmetrie in Architektur 3.3 eigentlich geht: dass die
beiden Schemata **zwei verschiedene Mailboxen** ergeben und das Löschen der einen die andere
nicht mitnimmt. Kollidierten sie, wäre der Schemawechsel ein Datenverlustfehler. Die Darstellung
des Pfades bleibt ungeprüft und unkritisch.

---

## U-39 — Der Zuschnitt der Authentifizierung: Schlüssel, Fristen, Widerruf

**Frage:** S2 stellt sieben Punkte offen (Schlüsselformat und -rotation, Zeittoleranz, Schwellen
des Missbrauchsschutzes, Rollenänderung, Aufräumen der Sperrliste, deaktivierte Konten, Format
des Erneuerungs-Cookies). Sie hängen zusammen und werden deshalb gemeinsam entschieden.

**Entscheidung:**

| Punkt | Festlegung |
|---|---|
| Schlüsselformat | `MAILADMIN_JWT_SIGNING_KEY` ist der **Ed25519-Seed**, 32 Byte, base64 (`openssl rand -base64 32`). Nicht das Schlüsselpaar: dessen zweite Hälfte ist ableitbar, und zwei Werte könnten zueinander inkonsistent konfiguriert werden. |
| `kid` | Wird **abgeleitet** (erste 16 Hexzeichen des SHA-256 über den öffentlichen Schlüssel), nicht konfiguriert. Kein zweiter Wert, den man beim Rotieren zu ändern vergisst. |
| Rotation | `MAILADMIN_JWT_SIGNING_KEY_PREVIOUS` gilt **nur zur Prüfung**. Neu ausgestellt wird immer mit dem aktuellen Schlüssel; bestehende Token laufen ihre Stunde aus. Danach wird die Zeile entfernt. Ein Vorgängerschlüssel, der dem aktuellen gleicht, wird abgelehnt — das wäre eine Rotation, die aussieht, als hätte sie stattgefunden. |
| Zeittoleranz | **30 Sekunden** auf `exp` und `iat`. Genug für zwei leicht auseinandergelaufene Uhren, zu wenig, um eine Laufzeit nennenswert zu verlängern. |
| Missbrauchsschutz | Fenster 15 Minuten. Je Benutzername: ab dem 3. Fehlversuch wachsende Verzögerung, **bei 1 s gedeckelt**, ab dem 10. Sperre. Je Adresse: Sperre ab 50. Die Deckelung ist kein Detail — jede Verzögerung hält einen PHP-FPM-Prozess, und eine unbegrenzte Verzögerung wäre ein Weg, den Prozesspool zu verbrauchen. |
| Rollenänderung | **Kein `sub` → `jti`-Verzeichnis.** Die Middleware liest Rolle und Zuschnitt ohnehin pro Anfrage (Architektur 5.3); weicht die Rolle im Token von der in der Datenbank ab, ist das ein `401`. Damit wirkt eine Rollenänderung sofort, ohne zusätzliche Datenstruktur. |
| Deaktivierte Konten | Ebenso: `adminuser` bekommt **kein** Statusfeld. Die Zeile fehlt oder der Zuschnitt ist leer → `findActor()` liefert `null` → `401`. |
| Sperrliste | Zwei Zeilenarten in **einer** Tabelle: eine `jti`-Zeile (Abmeldung) und eine Zeile mit leerem `jti` plus Stichzeitpunkt (alle Token einer Person). Beide beantwortet **eine** Bereichsabfrage über den Primärschlüssel `(subject, jti)`. Der Stichzeitpunkt macht die zweite Art gefahrlos: ein *danach* ausgestelltes Token ist nicht betroffen, sonst wäre der Widerruf zugleich eine Sperre für die Neuanmeldung. |
| Aufräumen | `bin/prune-tokens.php`, stündlich per Cron. **Kein** gelegentliches Aufräumen im Anfragebetrieb: das macht Antwortzeiten unvorhersagbar, ist nicht testbar, und es hört genau dann auf, wenn der Verkehr aufhört. |
| Erneuerungs-Cookie | Name `mailadmin_refresh`, Pfad `<Basispfad>/api/v1/auth`, `HttpOnly`, `Secure`, `SameSite=Strict`, `Max-Age` aus der Familienfrist. `Secure` ist **nicht** vom Anfrageschema abhängig — mailadmin sendet HSTS bedingungslos; wer über HTTP kommt, soll das merken, statt still das schwächere Cookie zu bekommen. |

**Warum:** Jede dieser Festlegungen ersetzt eine Datenstruktur oder eine Konfigurationsoption
durch etwas, das die Anwendung ohnehin tut. Der auffälligste Fall ist die Rollenänderung: der
naheliegende Weg wäre ein Verzeichnis offener Token je Nutzer gewesen — zu pflegen auf dem
heißen Pfad, für einen Vorgang, der zweimal im Jahr vorkommt.

**Alternative:** HS256 statt EdDSA (spart wenig, kostet die Möglichkeit, Token prüfen zu lassen,
ohne sie ausstellen zu können). Ein Statusfeld in `adminuser` (verstößt gegen Regel 1 und wäre
für webcyradm unsichtbar). Sitzungen im Server statt Token (macht die API ohne Browser
unbrauchbar, E-10).

**Korrekturkosten:** Gering. Die Schwellen sind Konstanten in `RateLimiter`, die Fristen sind
Umgebungsvariablen mit Grenzen, und die Sperrlistenform ist eine additive Tabelle.

---

## U-40 — Die Lieferung des Erneuerungstokens sagt der Client, nicht der Server

**Frage:** Architektur 5.2 sagt, das Erneuerungstoken gehe an die SPA im Cookie und an
API-Clients im Antwortkörper. Woran erkennt der Server, welcher Fall vorliegt?

**Entscheidung:** Gar nicht — der Client sagt es. `refresh_delivery: cookie | body` im
Anmeldekörper, Vorgabe `cookie`. Bei `cookie` steht im Körper **kein** `refresh_token`, bei
`body` wird **kein** Cookie gesetzt. Beim Erneuern entscheidet der Weg, auf dem das alte Token
kam.

**Warum:** Die Alternative wäre, aus `User-Agent` oder `Accept` zu raten. Dann ließe sich ein
Browser durch einen ungewöhnlichen Header in die Körper-Variante reden — und genau die legt ein
Zwölf-Stunden-Token dorthin, wo ein Skript es lesen kann. Die sichere Variante ist die Vorgabe,
die unsichere muss ausgesprochen werden.

**Alternative:** Immer beides senden. Damit wäre die HttpOnly-Eigenschaft wertlos, weil der
Wert zusätzlich im lesbaren Körper stünde.

**Korrekturkosten:** Additiv; ein weiterer Enum-Wert bräche nichts.

**Nebenwirkung, die im Vertrag steht:** `POST /auth/refresh` verlangt **immer** einen
JSON-Körper, notfalls `{}`. Die Anfragevalidierung kann einen Körper ohne `Content-Type` nicht
gegen die Operation prüfen und lehnt ihn mit `422` ab. Eine Zeile im Client, dafür bleibt die
Eingangsgrenze bedingungslos (U-9).

---

## U-41 — Die Authentifizierung läuft in einer zweiten, routennahen Middleware-Kette

**Frage:** Wo läuft die Authentifizierung? Die Kette in `config/di/core.php` läuft **vor** dem
Routing.

**Entscheidung:** Der `Dispatcher` bekommt eine zweite, kurze Middleware-Kette, die **nach** dem
Routen und **vor** dem Handler läuft. Ihre Reihenfolge steht in `src/Http/RouteMiddleware.php`.
S2 hängt dort die Authentifizierung ein, S3 später die Autorisierung.

**Warum:** Ob eine Anfrage ein Token braucht, ist eine Eigenschaft der **getroffenen Operation**
— `x-permission` und `security: []` im Vertrag. Vor dem Router weiß das niemand, und die beiden
möglichen Vermutungen sind beide falsch: „überall ein Token" macht `POST /auth/token`
unerreichbar, „nirgends" schiebt die Durchsetzung in jeden Handler — dorthin, wo das Altsystem
sie hatte.

**Alternative:** Eine Positivliste öffentlicher Pfade in der äußeren Kette. Das wäre eine zweite
Wahrheit neben dem Vertrag, und Regel 3 hat genau dagegen etwas.

**Korrekturkosten:** Keine für andere Pakete. Die Reihenfolge steht in einer Klasse mit einem
Konstruktorparameter je Middleware.

---

## U-42 — Die beiden Passwortspalten werden verbreitert (Migration 0003)

**Frage:** `accountuser.password` und `adminuser.password` sind `VARCHAR(50)`. Ein
bcrypt-Hash braucht 60 Zeichen, Argon2id 97, sha512crypt 106. Ohne Änderung ist S1 nicht
benutzbar und S2 sperrt jeden Administrator aus, dessen Hash bei der Anmeldung angehoben wird.

**Entscheidung:** Migration `0003_widen_password_columns.sql` setzt beide auf `VARCHAR(255)` und
schreibt Zeichensatz, Kollation, `NOT NULL` und `DEFAULT ''` **explizit** mit. Die
Additivregel bekommt dafür eine **einzeln aufgezählte** Ausnahme: `AdditiveOnlyRule` akzeptiert
ein `MODIFY` auf einer Bestandstabelle nur, wenn die Spalte in
`LegacySchema::WIDENABLE_COLUMNS` steht, die Anweisung aus **einer** Klausel besteht und die
neue Breite die dort verzeichnete **echt übersteigt**.

**Warum die Kollation dabeisteht:** gemessen an MariaDB 11.8 — `MODIFY password VARCHAR(255)
NOT NULL DEFAULT ''` **ohne** Zeichensatzangabe verschiebt die Spalte still von `latin1_bin` auf
die Tabellenvorgabe `latin1_swedish_ci`. `MODIFY` ersetzt die vollständige Spaltendefinition,
und ein fehlender Zeichensatz fällt auf den Tabellenstandard zurück. Eine
schreibungsunabhängige Kollation auf einer Hash-Spalte will man nicht aus Versehen bekommen.

**Warum das erlaubt ist:** Regel 1 verbietet `DROP`, `RENAME` und **Verengung**. Ein längeres
`VARCHAR` ist keines davon, und weder Postfix noch `pam_mysql` liest die deklarierte Breite.

**Alternative:** Eine neue Spalte neben der alten. Das hätte `allusers` und `pam_mysql` von der
alten Spalte lesen lassen, während die Anwendung in die neue schreibt — zwei Wahrheiten für ein
Passwort.

**Korrekturkosten:** Keine Rücknahme nötig; eine breitere Spalte schadet nichts. Aufgepasst
werden muss **vor** dem Lauf, siehe O-16.

---

## U-43 — Die Berechtigungsliste, die Rollenabbildung und der Riegel im Build

**Frage:** S3 stellt sechs Punkte offen (Berechtigungsliste, Zwischenspeicherung des Zuschnitts,
Bedeutung der Wildcard-Zeile, Domainmaster ohne Domain, wer das Revisionsprotokoll liest,
Selbstbezug beim eigenen Passwort). Drei davon hängen an derselben Frage — *welche Menge gehört
zu welcher Rolle* — und werden hier gemeinsam entschieden.

**Entscheidung:**

Die verbindliche Liste ist die Aufzählung `Mailadmin\Domain\Access\Permission`, Form
`<ressource>.<verb>` wie in `openapi/README.md` (5). Sie ist nach Paketen geschnitten: `domain.*`
gehört A1, `account.*` A2, `address.*` A3, `admin.*` A4, `list.*` A5, `maintenance.*` A6,
`audit.*` S4, `session.*` S2. Ein Paket, das eine Berechtigung braucht, trägt sie in **seine**
Familie ein.

| Familie | Berechtigungen |
|---|---|
| `session` | `read`, `write` |
| `profile` | `password.write` |
| `domain` | `read`, `write`, `delete` |
| `account` | `read`, `write`, `delete`, `password.write` |
| `address` | `read`, `write`, `delete` — Aliase, Catchall, Weiterleitungen, Abwesenheit |
| `admin` | `read`, `write`, `delete` |
| `list` | `read`, `write`, `delete` |
| `audit` | `read` |
| `maintenance` | `read`, `write` |

Die Rollenabbildung steht in `RolePermissions`. Der Superuser hält **alles, per Definition** —
nicht als aufgezählte Liste, denn eine Liste hieße, dass ein neues Paket seine Berechtigung
hinzufügt, diese Datei vergisst und einen Superuser erzeugt, der sie nicht benutzen kann. Der
Domainmaster hält `session.*`, `profile.password.write`, `domain.read`, `account.*` und
`address.*` — und sonst nichts.

**Die Aufteilung ist nicht erfunden**, sie ist die von web-cyradm, gelesen aus
`validate.inc.php`: `newdomain`, `editdomain`, `deletedomain` und die gesamte
`adminuser`-Familie beginnen dort mit `if ($_SESSION['admintype'] != 0)` und lehnen ab; Konten,
Aliase, Adressen, Catchall, Weiterleitungen und Abwesenheit haben **keine** Rollenprüfung und
sind nur durch die Domainliste begrenzt; `changeadminpasswd` steht allen offen. Damit verliert
kein Administrator eine Fähigkeit, die er heute hat, und keiner bekommt eine dazu.

Zwei bewusste Abweichungen:

- **`domain.read` bekommt der Domainmaster.** „Domains durchsehen" war im Altsystem
  Superuser-Sache, aber die Kontenmaske las Kontingent und Kontenzahl der Domain ohnehin, um das
  Formular zu zeichnen. Das zur Berechtigung zu machen ist ehrlich gegenüber etwas, das schon
  galt. Sie ist gescoped: die Liste enthält seine Domains und keine anderen.
- **`list.*` ist Superuser-Sache.** Für Mailinglisten gibt es keine Altbehaviour, die zu wahren
  wäre — web-cyradm verwaltet `lists` überhaupt nicht, Salt schreibt die Tabelle. Wo es nichts zu
  wahren gibt, entscheidet deny-by-default. **A5 darf erweitern**; erweitern nimmt niemandem
  etwas, verengen später schon. Der Haken dafür ist gebaut und getestet
  (`ListPolicy::requireVisible()`), er wird nur nicht aufgerufen.

`audit.read` und `maintenance.*` bleiben aus demselben Grund beim Superuser: ein
Revisionsprotokoll nennt Administratoren über Domaingrenzen hinweg, und ein Abgleichslauf
vergleicht den ganzen Mailstore mit der ganzen Datenbank.

**Das eigene Passwort ist keine Administration.** `profile.password.write` halten beide Rollen,
und die Policy prüft zusätzlich, dass der genannte Name der eigene ist — byteweise, weil
`adminuser.username` `latin1_bin` ist und `Admin` dort nicht `admin` ist. Ein Superuser, der ein
**fremdes** Administratorpasswort setzt, braucht `admin.write`; die beiden dürfen nicht in einer
Berechtigung zusammenfallen, sonst reichte die Berechtigung, die jeder hält, an fremde Konten.

**Ein unbekannter Wert ist ein Buildfehler.** `RouteCompiler` übersetzt ein `x-permission`, das
kein Fall der Aufzählung ist, in einen Abbruch — wie schon der fehlende Handler. Ohne das würde
`domain.wrtie` sauber übersetzen und eine Operation erzeugen, die *jeden* ablehnt, auffindbar
nur durch Ausprobieren. `public` ist ein Fall der Aufzählung, damit es genau eine Liste zulässiger
Werte gibt und genau eine Abfrage; keine Rolle hält es, `PermissionSet` weist es zurück.

**`GET /auth/me` liefert die Menge mit** (Architektur 7.2). Zum Zeichnen, nicht zum Entscheiden:
der Server prüft bei jeder Anfrage erneut. Der Zweck ist, dass das Frontend die Abbildung nicht
nachbaut und dann auseinanderläuft.

**Zwei Punkte waren schon beantwortet**, S2 hat sie beim Bauen von `SqlAdminDirectory`
entschieden und S3 bestätigt sie nur: die Wildcard-Zeile `domainadmin.domain_name = '*'` gilt
**auch für einen Domainmaster** — eine Installation, die so eine Zeile hat, meint sie, und sie
als Domainnamen `*` zu lesen ergäbe einen Administrator mit genau einer nicht existierenden
Domain. Ein Domainmaster **ohne** zugeordnete Domain kann nicht handeln: `findActor()` liefert
`null`, die Anfrage wird `401`, der Grund steht im Log und nicht in der Antwort.

**Warum:** Jede Festlegung hier ersetzt eine Erfindung durch etwas Nachlesbares — die
Rollenaufteilung durch das, was das Altsystem durchsetzte, die Vollständigkeit des Superusers
durch eine Ableitung statt einer Liste, die Gültigkeit eines Wertes durch den Build statt durch
einen Testlauf gegen die laufende Anwendung.

**Alternative:** Rollen direkt prüfen (`if ($actor->isSuperuser())`) statt über Mengen. Spart die
beiden Klassen und ist genau die Form, aus der die 44-KB-`switch` des Altsystems gewachsen ist:
die Antwort auf „was darf ein Domainmaster?" wäre wieder eine Volltextsuche. Eine feinere
Rollenauflösung (Rollen in der Datenbank) verstößt gegen E-18 und gegen Regel 1.

**Korrekturkosten:** Gering und lokal. Eine Berechtigung umzuhängen ist eine Zeile in
`RolePermissions`; eine neue anzulegen sind zwei — der Fall und die Zuordnung. Was teuer wäre,
ist eine Berechtigung nachträglich zu **verengen**, weil sie dann jemandem weggenommen wird; die
Vorgabe ist deshalb überall die engere.

---

## U-44 — Wo `403` endet und `404` anfängt, und wer es entscheidet

**Frage:** CLAUDE.md verlangt, dass fremde Objekte `404` ergeben und nicht `403`. Wo wird das
durchgesetzt, ohne dass acht Pakete es je einzeln richtig machen müssen?

**Entscheidung:** Die Trennung wird an **einer** Stelle festgelegt und an **einer** Stelle in eine
Antwort übersetzt.

| Frage | Wer stellt sie | Antwort bei Nein |
|---|---|---|
| Trägt die Rolle die Berechtigung der Operation? | `AuthorizationMiddleware`, vor dem Handler | `403`, `request.forbidden` |
| Liegt **dieses Objekt** im Zuschnitt? | ein Policy-Objekt, im Anwendungsfall | `404`, `request.not_found` |

Die Policies (`DomainPolicy`, `AccountPolicy`, `AddressPolicy`, `AdminPolicy`, `ListPolicy`)
sind benannte Fassaden über zwei Primitive in `Domain\Policy\Guard`. Sie werfen `AccessDenied`
oder `ResourceNotVisible`; **die Middleware fängt beide auf dem Rückweg** und macht daraus die
Antwort. Ein Handler, der die Ausnahme aus seinem Anwendungsfall herauslässt, bekommt die
richtige Antwort, ohne eine Zeile dafür zu schreiben.

**Die Reihenfolge ist nicht vertauschbar: erst Berechtigung, dann Sichtbarkeit.** Eine Rolle, die
die Operation überhaupt nicht ausführen darf, erfährt aus dem `403` nichts über irgendein Objekt.
Andersherum geprüft käme für ein existierendes fremdes Objekt `404` und für ein nicht
existierendes `403` — dasselbe Leck, nur seitenverkehrt.

`ResourceNotVisible` erzeugt dieselbe Antwort wie ein echter Fehlschlag, nicht eine ähnliche: die
Middleware ruft `ProblemDetails::notFound()` auf, dieselbe Fabrik wie `NotFoundHandler`. Ein Test
vergleicht die beiden Antworten **byteweise**, denn genau eine Abweichung wäre das Signal, an dem
sich „gehört jemand anderem" von „gibt es nicht" unterscheiden ließe.

Ein `x-permission`, das die Middleware nicht kennt, ist **kein** `403`, sondern eine
`LogicException` und damit `500`: die Bereitstellung ist falsch, nicht die Anfrage. Erreichbar ist
das ohnehin nicht, weil der Build es abfängt (U-43) — es ist das zweite Schloss an derselben Tür,
und es fällt laut zu statt still auf.

**Warum:** Die Alternative, die sich anbietet, ist „jeder Handler fängt selbst". Damit wird die
Trennung *meistens* richtig: acht Pakete, eines schreibt `403`, weil es sich natürlicher liest,
und das Aufzählungsloch ist wieder offen. Eine Regel, die man an einer Stelle befolgt, ist
billiger als eine, die man überall befolgen muss.

**Alternative:** Die Prüfung in die Repositories legen, so dass eine fremde Zeile gar nicht erst
geladen wird. Das ist genau richtig für Listen (U-45) und falsch für Einzelzugriffe: der
Anwendungsfall bräuchte trotzdem eine Ausnahme, und die Ausnahme käme dann aus einer Schicht,
die nichts über Akteure wissen sollte.

**Korrekturkosten:** Keine für andere Pakete, solange sie die Policy aufrufen und die Ausnahme
laufen lassen. Ein Paket, das eine eigene Antwort braucht, fängt vorher — das steht offen.

---

## U-45 — Der Domain-Zuschnitt wird nicht zwischengespeichert, und das Filtern bleibt bei F3

**Frage:** S3/2 — den Zuschnitt über Anfragen hinweg zwischenspeichern? Und wie stellt man
sicher, dass es keinen Lesepfad gibt, der ungefiltert liest?

**Entscheidung:** **Kein Zwischenspeicher, und kein zweiter Leser.** Der Zuschnitt wird einmal je
Anfrage gelesen — von `AdminDirectory::findActor()`, das die Authentifizierung ohnehin aufruft
(Architektur 5.3). Ein `DomainScopeRepository`, wie das S3-Paketdokument es vorsah, wäre eine
zweite Stelle, die dasselbe liest, und damit eine zweite Stelle, die veralten kann.

**Das Einengen von Listen war bereits gebaut.** `Repository::restrictToDomains()` aus F3 erzeugt
das `IN`-Prädikat mit gebundenen Parametern und — wichtiger — bei leerer Menge `1 = 0` statt gar
keinem Prädikat. Das ist die Stelle, an der beide naheliegenden Umsetzungen **offen** scheitern
würden: ein leeres `IN ()` ist ein Syntaxfehler, und das Prädikat wegzulassen liefert jemandem,
der auf nichts Anspruch hat, jede Zeile der Tabelle. F3 hatte das richtig.

Was S3 hinzufügt, ist eine Zeile Fassade: `Repository::scopedTo($query, $actor, $column)`.

```php
// vorher — das `if` ist die ganze Angriffsfläche
if ($actor->requiresDomainScope()) {
    $query = $this->restrictToDomains($query, $actor->visibleDomains(), 'd.domain_name');
}

// nachher
$query = $this->scopedTo($query, $actor, 'd.domain_name');
```

**Warum das die Änderung wert ist:** Vergisst ein A-Paket das `if` samt Aufruf, funktioniert die
Liste eines Superusers weiterhin, die eines Domainmasters ebenfalls — sie enthält nur zusätzlich
die Zeilen aller anderen, und **nichts schlägt fehl**. Eine Regel, die man befolgen *muss*, ist
billiger als eine, die man befolgen *soll*. Die Methode nimmt außerdem den `ActorContext` statt
einer Liste von Namen, weil eine Liste die falsche Liste sein kann.

`restrictToDomains()` bleibt, für die Fälle, in denen die Menge nicht die des Akteurs ist — ein
Superuser, der die Konten *einer* Domain auflistet.

**Warum kein Zwischenspeicher:** Er macht genau das rückgängig, wofür der Zuschnitt aus dem Token
herausgehalten wurde (U-39): dass ein Entzug sofort wirkt. Die Ersparnis wäre eine indizierte
Abfrage auf einer Tabelle mit einer Zeile je Zuordnung.

**Alternative:** Das Filtern in die Anwendungsfälle legen und dort je Zeile die Policy fragen.
Das beantwortet für die eigene Liste eines Domainmasters `404` statt einer Liste, und es liest
die fremden Zeilen erst einmal. — Ein eigenes Zuschnitt-Werkzeug in S3 war der erste Entwurf und
ist verworfen worden, als klar wurde, dass F3 dieselbe Regel bereits durchsetzt: zwei Fassungen
der `1 = 0`-Regel wären zwei Stellen, an denen sie repariert werden muss.

**Korrekturkosten:** Keine. Wer später doch zwischenspeichern will, tut es hinter
`AdminDirectory`, ohne dass ein Aufrufer davon erfährt.

---

## U-46 — `domain.prefix` wird abgeleitet, einmal vergeben und nie wieder geändert

**Frage:** A1, Punkt 1. Die Spalte ist `varchar(50) NOT NULL UNIQUE`, das Altsystem missbrauchte
sie als Ersatzschlüssel, und entfernen können wir sie nicht (E-1). Was steht bei neuen Domains
drin, und was passiert bei einer Kollision?

**Entscheidung:** Der Wert wird **abgeleitet, nicht erfragt**. Erster Kandidat ist der
kleingeschriebene Domainname selbst, wenn er in 50 Zeichen passt. Ist er belegt oder zu lang,
folgen bis zu sieben weitere Kandidaten aus 41 Zeichen des Namens, einem Bindestrich und acht
Hexziffern eines SHA-256 über Name und Versuchsnummer. Die Liste ist endlich: wer sie
aufbraucht, bekommt `409 domain.prefix_taken`.

Der Prefix wird **bei der Anlage vergeben und danach nie geschrieben** — auch eine Umbenennung
lässt ihn stehen. `DomainRepository::update()` nennt die Spalte gar nicht erst.

**Warum:** Ein Pflichtfeld, dessen Wert niemand liest, füllen Menschen schlecht aus — die
Bestandsdaten belegen das, dort stehen willkürliche Abkürzungen ohne Bezug zu irgendetwas. Aus
dem Namen abgeleitet ist die Spalte wenigstens noch für einen Menschen lesbar, der eine Zeile in
`domain` vor sich hat, und das ist die einzige Verwendung, die ihr geblieben ist. Der Hash ist
eine Streufunktion, kein Sicherheitsmerkmal: ein Prefix ist kein Geheimnis, und nichts
authentifiziert sich damit.

Dass er bei einer Umbenennung stehen bleibt, ist die konservative Wahl: `accountuser.prefix`
trägt denselben Wert, und ihn mitzuziehen wäre eine weitere Tabelle, die eine ohnehin teure
Operation anfassen müsste — für eine Spalte, die niemand liest.

**Alternative:** Den Prefix im Anlage-Dialog erfragen, wie web-cyradm. Das erhält die
Bestandsbedienung und erzeugt genau die Werte, die heute unbrauchbar sind. Oder ihn immer aus
einem Hash bilden: kollisionsfrei, aber unlesbar.

**Korrekturkosten:** Gering und lokal. `Domain\Model\LegacyPrefix` ist die einzige Stelle; wer
das Feld doch erfragen will, ergänzt das Anfrageschema und reicht den Wert an
`DomainDraft::toDomain()` durch.

---

## U-47 — Was von den Altspalten getragen, gezeigt und nicht gedeutet wird

**Frage:** A1, Punkte 4, 6 und 7 — `freenames`, `freeaddress`, `transport` und `folders`.

**Entscheidung:**

| Spalte | Verhalten |
|---|---|
| `transport` | getragen, **schreibgeschützt** in der Darstellung, nie interpretiert. Neue Domains bekommen `cyrus`. |
| `freenames`, `freeaddress` | getragen, **änderbar**, **ohne Wirkung** in mailadmin. Als Kompatibilitätsfelder im Vertrag benannt. |
| `folders` | Format bleibt kommasepariert (E-1). Eine Änderung gilt **nur für neue Konten**; bestehende Postfächer werden nicht nachträglich mit Ordnern versehen. |

Alle vier stehen in der Darstellung. `transport` und der abgeleitete `legacy_prefix` als
`readOnly`, die beiden Flags als normale Felder.

**Warum:** Ein Wert, den niemand anzeigt, ist ein Wert, dessen Fehler niemand bemerkt — U-22 in
klein. Umgekehrt ist es unehrlich, ein Feld schreibbar zu machen und so zu tun, als hätte es eine
Wirkung: `freenames` und `freeaddress` steuerten im Altsystem, wie frei Konten- und Aliasnamen
gewählt werden durften, und wir können ihre genaue Semantik nicht aus dem Bestand rekonstruieren.
Sie stehenzulassen kostet nichts und hält die Tür für A2/A3 offen, sie später zu deuten;
stillschweigend zu entfernen wäre eine Änderung an einer Bestandstabelle über Bande.

Bei `folders` ist die Reihenfolge des Schadens ausschlaggebend: Ordner in fremden Postfächern
nachträglich anzulegen ist ein Eingriff in die Mailbox eines Nutzers und keine Nebenwirkung einer
Formulareingabe. Wer das will, bekommt es als ausdrückliche Massenoperation von A6 — als
Nebeneffekt eines `PATCH` bekommt es niemand.

**Alternative:** Die drei Kompatibilitätsfelder verstecken. Spart vier Zeilen im Schema und
nimmt der Oberfläche die Möglichkeit, einen ungewöhnlichen `transport` überhaupt zu zeigen.

**Korrekturkosten:** Gering. Die Felder zu deuten heißt, an genau einer Stelle in A2 bzw. A3 eine
Prüfung zu ergänzen; sie zu entfernen wäre eine brechende Vertragsänderung.

---

## U-48 — Kontingente: `maxaccounts` wird durchgesetzt, `domainquota` wird nicht summiert

**Frage:** A1, Punkte 2 und 3.

**Entscheidung:**

- **`maxaccounts`:** `0` heißt **unbegrenzt** — so meint es die Altspalte, und
  `AccountLimit::fromLegacyValue()` bildet das ab. Ein Kontingent von `0` ist über die API als
  „unbegrenzt" nicht ausdrückbar; dort ist `null` unbegrenzt und `0` heißt „keine Kontoanlage
  möglich". Durchgesetzt wird das Kontingent bei der **Kontoanlage** (A2), nicht beim Ändern der
  Domain.
- **Unterschreitung:** Das Kontingent unter die aktuelle Kontenzahl zu setzen ist **erlaubt**.
  Bestehende Konten bleiben unangetastet, neue lassen sich nicht anlegen, bis die Zahl wieder
  darunter liegt. Die Darstellung liefert `account_count` mit, damit die Oberfläche das zeigen
  kann.
- **`domainquota`:** wird getragen, angezeigt und geändert — aber **nicht bei jeder Operation
  durchgesetzt**. Die Summe aller Kontoquoten steht in Cyrus, nicht in der Datenbank, und sie zu
  bilden heißt eine IMAP-Runde je Konto.

**Warum:** Ein Kontingent, das beim Ändern der Domain durchgesetzt würde, hieße: eine Domain,
deren Kontenzahl aus irgendeinem Grund über dem Limit liegt, lässt sich nicht mehr bearbeiten —
auch nicht, um das Limit zu erhöhen. Das ist die falsche Richtung. Konten löschen kann man
immer.

Bei `domainquota` ist die Rechnung einfach: eine Domain mit 500 Konten kostet 500 `GETQUOTA`, und
das Altsystem hat genau diesen Fehler gemacht und wurde bei großen Domains unbenutzbar
(Architektur 7.2). Die Prüfung gehört an die eine Stelle, an der sie etwas verhindern kann — das
Anlegen oder Erhöhen einer Kontoquota (A2) — und dort gebündelt über **eine** Verbindung, nicht
je Zeile.

**Alternative:** Die Summe in einer eigenen Spalte mitführen. Das wäre schnell und wäre eine
zweite Wahrheit neben Cyrus, die bei jeder Änderung außerhalb von mailadmin veraltet — und
außerhalb von mailadmin wird geändert, dafür gibt es den Parallelbetrieb.

**Korrekturkosten:** Gering. `AccountLimit` und `Quota` tragen die Regeln bereits;
`Domain::permitsAnotherAccount()` steht und wird nur noch nicht aufgerufen.

---

## U-49 — Umbenennen wird bestätigt, Löschen wird verweigert, und `If-Match` ist Pflicht

**Frage:** A1, Punkte 5 und 8, und die Frage, die daraus folgt: reicht ein optionales `If-Match`
für Operationen, die Mail bewegen?

**Entscheidung:** Drei Riegel an den beiden schreibenden Operationen.

1. **Löschen einer Domain mit Konten wird verweigert** — `409 domain.not_empty`, mit der
   Kontenzahl im Log. Kein Kaskadieren. Das Altsystem löschte kaskadierend: ein Bestätigungsdialog
   entfernte die Domain, alle Konten und die Mail in ihren Postfächern.
2. **Eine Umbenennung braucht `confirm_rename: true`** im selben Rumpf — sonst
   `422 domain.rename_not_confirmed`. Sie wird geprüft, **bevor** irgendetwas nachgeschlagen
   wird, damit ein versehentlich getippter Name nicht verrät, ob er belegt ist.
3. **`If-Match` ist bei `PATCH` und `DELETE` Pflicht**, nicht optional (U-19 sagt „akzeptieren").
   Fehlt der Kopf: `428`. Passt er nicht: `412`.

Dazu die Bestätigung von Punkt 8: **ein nicht erreichbarer Mailstore verweigert die Umbenennung**
— `503 domain.mailstore_unavailable`, geprüft mit einer Sonde, bevor das erste Postfach bewegt
wird. Eine Domain **ohne Konten** braucht dafür keinen Mailserver und wird auch ohne umbenannt.

**Warum:** Die drei hängen zusammen. Was die Löschung sicher aussehen lässt, ist die Kontenzahl —
also muss die Zahl, die der Client gesehen hat, die Zahl sein, die entscheidet; genau das ist
`If-Match` über eine Darstellung, die `account_count` enthält. Und die Umbenennung entscheidet
sich am Vergleich zwischen gewünschtem und gespeichertem Namen: ein Client mit veralteter Sicht
würde entweder nichts tun oder den Namen von dem wegbenennen, den gerade jemand anders gesetzt
hat. Ein Nebenläufigkeitsschutz, den man nur benutzt, wenn man daran denkt, ist keiner.

`428` statt `422` für den fehlenden Kopf, weil die Anfrage nicht fehlerhaft ist: ihr fehlt eine
Vorbedingung, die der Client durch ein `GET` beschaffen kann. Das ist genau die Bedeutung von
`428`.

**Alternative:** Kaskadierend löschen mit ausdrücklicher Bestätigung, wie das Altsystem. Eine
Bestätigung, hinter der beliebig viel Mail liegt, ist keine Bestätigung. — Oder `If-Match`
optional lassen: billiger für Clients und wertlos an genau den beiden Operationen, an denen er
gebraucht wird.

**Korrekturkosten:** Gering für 1 und 2. Den `If-Match`-Zwang zu lockern ist eine Zeile in
`DomainPrecondition`; ihn nachträglich einzuführen wäre eine brechende Änderung für jeden Client,
der ohne ihn gebaut wurde — deshalb steht er von Anfang an.

---

## U-50 — Die Reparaturwarteschlange bekommt einen Port in A1; die Tabelle bleibt A6

> **Abgelöst durch [U-117](#u-117--die-reparaturwarteschlange-ist-eine-tabelle-auf-eigener-verbindung-das-log-bleibt-rückfall).**
> A6 hat die Tabelle `mailadmin_repair_queue` angelegt und den Port daran gebunden.
> `LoggingRepairQueue` ist kein Notnagel mehr, sondern der Rückfall für ein `INSERT`, das
> scheitert. Der Port und `RepairRequest` sind geblieben; `RepairRequest` trägt zusätzlich
> `parameters` (U-118).

**Frage:** Nicht im Paketdokument gestellt, aber blockierend. A1s Definition of Done verlangt:
„ein Abbruch hinterlässt einen Eintrag in der Reparaturwarteschlange". Die Tabelle
`mailadmin_repair_queue` gehört aber A6 und dem Nummernbereich 0040–0049 (U-6). A1 darf sie nicht
anlegen und braucht sie trotzdem.

**Entscheidung:** A1 deklariert den **Port** `Domain\Repair\RepairQueue` mit dem Wertobjekt
`RepairRequest` (was war beabsichtigt, welcher Schritt scheiterte, welcher Zustand ist erreicht,
warum) und liefert zwei Implementierungen: `InMemoryRepairQueue` für die Tests und
`LoggingRepairQueue` für die Verdrahtung. Letztere schreibt eine `critical`-Zeile mit dem
vollständigen Eintrag.

**Das ist ein Notnagel und ist als solcher gekennzeichnet** — in der Klasse, in `config/di/domains.php`
und hier. `CLAUDE.md` sagt ausdrücklich, dass so ein Zustand in die Tabelle gehört und nicht in
ein Log, und das stimmt: eine Logzeile kann man nicht auflisten, filtern oder abhaken. **A6
ersetzt eine Zeile** in `config/di/domains.php` und sonst nichts.

**Warum:** Die Alternative wäre gewesen, die Tabelle in A1s Bereich 0030–0039 anzulegen. Damit
hätte A1 A6 ein Artefakt weggenommen, und zwei Pakete hätten dasselbe Schema beschrieben — genau
der Konflikt, den U-6 vermeiden soll. Ein Port ist die Naht, die beide Seiten unabhängig hält:
A1 kann heute testen, dass ein nicht rücknehmbarer Schritt aufgezeichnet wird, ohne dass es eine
Tabelle gibt, und A6 kann die Tabelle bauen, ohne A1 anzufassen.

Dass die Zwischenlösung laut ist (`critical`) statt still, ist Absicht: solange sie in Betrieb
ist, soll ein Betreiber sie bemerken.

**Alternative:** Auf den Eintrag bis A6 verzichten und den Fehlschlag nur im Anwendungsfall
melden. Dann ginge genau die Information verloren, die eine Reparatur braucht — welches Postfach
unter welchem Namen liegt, während die Datenbank etwas anderes sagt.

**Korrekturkosten:** Eine Zeile, und sie steht in einer Datei, die A6 ohnehin anfasst.

---

## U-51 — Die vier Regeln, die eine Installation verwaltbar halten

**Frage:** A4, Punkte 1 und 2 — der letzte Superuser und die Selbstlöschung. Dazu ergibt sich
aus dem Fachmodell eine dritte Frage, die das Paketdokument nicht stellt: darf ein
Administrator ohne Domain entstehen?

**Entscheidung:** Vier Regeln, alle im Anwendungsfall und keine in einer Policy — sie zählen
Zeilen, und eine Policy, die die Datenbank läse, wäre eine Policy, die man ohne Datenbank nicht
prüfen kann.

| Regel | Antwort | Code |
|---|---|---|
| Der letzte Superuser wird gelöscht | `409` | `admin.last_superuser` |
| Der letzte Superuser wird herabgestuft | `409` | `admin.last_superuser` |
| Jemand löscht das eigene Konto | `409` | `admin.self_deletion` |
| Superuser mit Domains, oder Domainmaster ohne | `422` | `admin.role_scope_mismatch` |

Gezählt wird **in der Datenbank im Moment der Änderung** (`countSuperusers()`), nicht aus dem
`ActorContext` abgeleitet: der Akteur stuft womöglich jemand anderen herab, und die Zahl ändert
sich, ohne dass diese Sitzung davon erfährt.

**Sich selbst herabzustufen ist erlaubt**, solange man nicht der letzte Superuser ist. Das ist
eine echte Absicht — die Rolle übergeben — und keine Falle: Rolle und Zuschnitt werden pro
Anfrage gelesen (Architektur 5.3), die Wirkung ist also sofort und sichtbar, und im selben
Aufruf bekommt das Konto die Domains, die es als Domainmaster braucht. Eine eigene Regel dagegen
brächte nichts, was die Letzter-Superuser-Prüfung nicht schon leistet.

**Die vierte Regel ist die, die im Paketdokument fehlt.** Ein Domainmaster ohne Domain kann
überhaupt nicht handeln: `AdminDirectory::findActor()` liefert `null`, die Anmeldung lehnt ab,
und der Grund steht nur im Log. Diesen Zustand schreiben zu dürfen wäre ein Weg, jemanden ohne
sichtbare Ursache auszusperren. Umgekehrt sind einzelne Domains für einen Superuser kein
Feinschliff, sondern ein Widerspruch — er sieht ohnehin alle.

**Warum:** Jede der vier ist ein Zustand, aus dem die Oberfläche nicht mehr herausführt. Aus den
ersten beiden käme man nur über einen Datenbankeingriff heraus, aus der dritten nur über einen
zweiten Superuser, und die vierte erzeugt ein Konto, das sich nicht anmelden kann.

**Alternative:** Die Selbstlöschung erlauben und auf die Letzter-Superuser-Prüfung vertrauen.
Sie fängt den Totalverlust ab, aber nicht den versehentlichen Klick, der die eigene Sitzung
beendet — und es gibt immer einen zweiten Superuser, der es bewusst tun kann.

**Korrekturkosten:** Gering. Jede Regel ist eine `if`-Abfrage in genau einem Anwendungsfall.

---

## U-52 — Das eigene Passwort verlangt das alte und beendet alle Sitzungen

**Frage:** A4, Punkt 3 (strengere Passwortregeln für Administratoren?) und Punkt 6
(Token-Widerruf).

**Entscheidung:** Drei Festlegungen.

**Die Regeln sind schon strenger, und es kommen keine dazu.** U-21 verlangt für `adminuser`
zwölf Zeichen gegenüber zehn für `accountuser`, dazu Sperrliste und Prüfung gegen den eigenen
Namen. Punkt 3 ist damit beantwortet; eine zusätzliche Zeichenklassen-Pflicht wäre genau das,
was NIST SP 800-63B abgeschafft hat. Der Vertrag nennt die zwölf im Schema, damit ein Client es
vor dem Rundlauf sagen kann — durchgesetzt wird sie trotzdem in der Policy.

**`PUT /admins/me/password` verlangt das aktuelle Passwort.** Angemeldet zu sein reicht nicht.
Das Zugriffstoken liegt 60 Minuten lang im `localStorage` (E-15); wäre es allein genug, würde
ein gestohlenes Token nicht eine Stunde Zugriff bedeuten, sondern den dauerhaften Verlust des
Kontos. Falsche Eingabe ist `422` mit `admin.wrong_current_password` — nicht `401`, denn der
Aufrufer ist authentifiziert, es stimmt nur die Angabe nicht. Geprüft wird über
`PasswordHasher::verify()`, das alle Altformate liest: wer sich seit vor E-13 nicht angemeldet
hat, muss sein Passwort trotzdem ändern können.

**Nach einer Passwortänderung werden alle Erneuerungsfamilien dieses Kontos widerrufen**, die
des aufrufenden Clients eingeschlossen. Sitzungen, die mit dem alten Passwort geöffnet wurden,
sollen es nicht überleben, und „diese eine ausnehmen" hieße, dem Client zu glauben, welche das
ist. Das Zugriffstoken in der Hand läuft aus — S2s Sperrliste ist nur über `POST /auth/logout`
erreichbar, das den `jti` kennt —, und der Preis ist durch die 60 Minuten begrenzt, die ohnehin
nicht verhandelbar sind.

**Ein fremdes Passwort setzt ein Superuser über `PATCH /admins/{username}`** und braucht dafür
`admin.write`. Die beiden dürfen nicht in einer Berechtigung zusammenfallen, sonst reichte
`profile.password.write`, das jede Rolle hält, an fremde Konten (U-43). Eine Rollenänderung
löst **keinen** Widerruf aus: Rolle und Zuschnitt werden pro Anfrage gelesen, sie wirkt also
schon mit der nächsten Anfrage.

**Warum:** Der einzige Punkt, an dem hier etwas zu verlieren ist, ist das gestohlene Token —
und genau dort greift die Abfrage nach dem alten Passwort.

**Alternative:** Auf das aktuelle Passwort verzichten, weil der Aufrufer angemeldet ist. Spart
ein Feld und macht E-15s Restrisiko unbegrenzt statt einstündig.

**Korrekturkosten:** Trivial.

---

## U-53 — Was ein Administrator in der API ist: Wildcard, verwaiste Zuordnungen, Hash-Format

**Frage:** A4, Punkte 4 und 7 — die Bedeutung der `'*'`-Zeile beim **Schreiben**, und was mit
Zuordnungen zu Domains geschieht, die es nicht mehr gibt.

**Entscheidung:** Die Darstellung trägt sieben Felder, drei davon beschreiben den Bestand und
nicht eine Absicht:

- **`all_domains`** — es gibt eine `domainadmin`-Zeile mit `'*'`. Beim **Schreiben** ist die
  Regel eindeutig: ein Superuser bekommt genau diese eine Zeile, ein Domainmaster genau eine
  Zeile je Domain. Das ist, was web-cyradm schreibt, und eine Installation im Parallelbetrieb
  sieht in beiden Oberflächen dasselbe. Beim **Lesen** wird die Zeile gemeldet, wo immer sie
  steht — S3 hat entschieden, dass sie auch für einen Domainmaster gilt (U-43), und das Feld
  macht diesen Sonderfall sichtbar, statt ihn hinter `role` zu verstecken. `'*'` als Domainname
  anzunehmen ist ausgeschlossen: das Schema lehnt ihn ab, und der Anwendungsfall filtert ihn ein
  zweites Mal heraus, denn „Domainmaster für `*`" wäre ein Superuser ohne die Rolle.
- **`orphaned_domains`** — die Teilmenge von `domains`, zu der keine Zeile in `domain` gehört.
  **Angezeigt und markiert, nicht entfernt** (U-22): ein `LEFT JOIN` über die ganze Seite, nicht
  eine Abfrage je Zeile. Bereinigt wird nichts (E-18). **Neu vergeben** werden darf eine solche
  Domain aber nicht — `admin.unknown_domain`, `422`.
- **`password_format`** samt `password_legacy` — welches Verfahren der gespeicherte Hash hat.
  E-13 kennt keine Umstellungskampagne; wer noch auf DES-crypt sitzt, ist hier sichtbar, bis er
  sich das nächste Mal anmeldet. Der Hash selbst verlässt die Persistenz nie: `AdminAccount`
  hat kein Feld dafür, und damit kann er weder in eine Antwort noch in einen Revisionseintrag
  geraten.

`domains` ist sortiert und dedupliziert, damit zwei Anfragen mit derselben Menge in anderer
Reihenfolge dieselben Zeilen und denselben `ETag` ergeben. Werden `role` oder `domains`
geschickt, wird `domainadmin` vollständig neu geschrieben — eine Wildcard-Zeile, die an einem
Domainmaster hing, verschwindet dabei. Das ist die beabsichtigte Lesart: der Client hat das Feld
gesehen und eine Liste geschickt. Wird keines von beiden geschickt, wird nichts angefasst und
die Kuriosität bleibt stehen.

**Warum:** E-18 sagt, es findet keine Bereinigung statt. Damit das keine blinde Stelle wird,
muss der Zustand in der Oberfläche stehen — und zwar an der Zeile, um die es geht.

**Alternative:** Verwaiste Zuordnungen beim Lesen wegfiltern. Das ist bequemer und macht den
Bestand unsichtbar und damit dauerhaft.

**Korrekturkosten:** Gering, aber vertragsrelevant: die drei Felder sind `required` im Schema.

---

## U-54 — Löschen räumt drei weitere Tabellen ab, und `mailadmin_admin_setting` entsteht dafür jetzt

**Frage:** A4, Punkt 5 — was mit den `settings`-Zeilen eines gelöschten Administrators
geschieht.

**Entscheidung:** Ein `DELETE` auf `adminuser` nimmt in derselben Transaktion mit: die
`domainadmin`-Zuordnungen, die Alt-Zeile in `settings` und die Zeilen in
`mailadmin_admin_setting`. Das Altsystem löschte `settings` mit, und A4 übernimmt es — sonst
erbt der nächste Administrator, der zufällig denselben Namen bekommt, fremde Einstellungen.

Die Reihenfolge ist `domainadmin` → `settings` → `mailadmin_admin_setting` → `adminuser`. Auf
einer Speicher-Engine ohne Transaktionen (**O-11**, die Bestandstabellen sind MyISAM) bleibt
ein halb ausgeführtes Löschen damit als *Administrator ohne Domains* stehen — ein Konto, das
sich nicht anmelden kann — und nicht als Zuordnungen, die auf niemanden zeigen.

**`mailadmin_admin_setting` wird mit Migration 0050 angelegt, obwohl heute nichts daraus
liest.** Die Alt-Tabelle `settings` bleibt unangetastet (Regel 1) und hat für E-16 ohnehin die
falsche Form — „welche Sprache liest dieser Administrator" ist kein `style`. Der Grund, sie
**jetzt** anzulegen, ist nicht der Lesepfad, sondern der Löschpfad: eine Tabelle, die später von
W1 oder A6 nachgereicht wird, sammelt stillschweigend Zeilen von Administratoren, die es nicht
mehr gibt, und nichts schlägt dabei fehl. A4 besitzt sowohl das Löschen als auch den
Nummernbereich 0050–0059; hier zusammengelegt kann die Lücke gar nicht erst entstehen.

**Warum:** Das Altverhalten zu wahren ist der Vorgabewert, wo es keinen Grund gibt, davon
abzuweichen — und hier gibt es einen Grund *dafür*: verwaiste Einstellungen sind eine stille
Rechteübertragung an einen Namensnachfolger.

**Alternative:** Die `settings`-Zeile stehen lassen. Sie ist klein und harmlos, bis jemand einen
Namen wiederverwendet.

**Korrekturkosten:** Gering. Die Migration ist additiv, das Löschen sind vier Anweisungen.

---

## U-55 — `admin.not_found`, `If-Match` ohne Zwang, und Fehlercodes, die sich anhängen lassen

**Frage:** Drei kleinere Festlegungen, die A4 treffen musste und die andere Pakete betreffen
könnten.

**Entscheidung:**

**1. `admin.not_found` statt `request.not_found`.** A1 hat sich bewusst gegen `domain.not_found`
entschieden, weil eine nicht existierende und eine fremde Domain byteweise dieselbe Antwort
ergeben müssen (U-44). **Diese Ressource hat dieses Paar nicht.** Ein Administrator liegt in
keiner Domain — `adminuser` hat keine Domainspalte —, und `admin.read`, `admin.write` und
`admin.delete` sind Superuser-Sache; wer bis zur Abfrage kommt, sieht ohnehin jede Zeile. Es
gibt also keine zweite Bedeutung, mit der die `404` verwechselt werden könnte, und damit nichts,
was ein eigener Code verraten würde. Er bringt der Oberfläche ein übersetzbares „diesen
Administrator gibt es nicht mehr" statt „diese Seite gibt es nicht". **Die Begründung hängt
daran, dass `admin.*` Superuser-Sache bleibt** (E-18); würde die Ressource je gescoped, muss der
Code in derselben Änderung zurück auf `request.not_found`.

**2. `If-Match` wird beachtet, nicht verlangt.** Architektur 7.1 sagt, schreibende Methoden
*akzeptieren* `If-Match` und antworten bei Abweichung `412`. A4 tut genau das und nicht mehr:
kein `428`. Der `ETag` ist ein Hash der **Darstellung** (U-19) und liegt deshalb in
`Application\Admin\AdminEtag` neben `AdminAccount::representation()`, nicht in einem Responder —
zwei Stellen, die sich einig sein müssen, wären zwei Stellen, die sich uneinig werden können.
Geprüft wird im Anwendungsfall, nicht im Handler: eine Prüfung im Transport ist eine Prüfung,
die der nächste Aufrufer überspringen kann. Verglichen wird schwach (ohne `W/` und
Anführungszeichen); RFC 9110 verlangt für `If-Match` starken Vergleich, den ein schwacher
Validator nie erfüllen kann — buchstabengetreu befolgt würde jeder ausgelieferte `ETag` beim
Zurücksenden abgelehnt.

**3. Die Fehlercodenamen sind so gewählt, dass der Block anhängbar bleibt.** Der Vertragstest
liest das `ErrorCode`-Enum als **eine Liste je Präfix**, nicht je Kommentarblock. `admin.` gab
es schon: `admin.invalid_scope` steht im Block des Fachmodells (F4). Ein angehängter Block mit
`admin.already_exists` wäre unsortiert gewesen und hätte verlangt, F4s Block wieder aufzumachen
— mitten in der Parallelarbeit die eine Änderung, die garantiert kollidiert. Also heißt es
`admin.username_taken` statt `admin.already_exists` und `admin.unknown_domain` statt
`admin.invalid_domain_assignment`: jeder A4-Code sortiert hinter `invalid_scope`, und der Block
konnte angehängt werden. **Das ist eine Regel für jedes Paket, dessen Präfix schon vorkommt** —
A1 stand vor derselben Lage und hat es genauso gelöst.

**Warum:** Alle drei ersetzen eine Bequemlichkeit durch etwas, das die nächste Person nachlesen
kann, statt es aus einem fehlgeschlagenen Test zu rekonstruieren.

**Alternative:** Zu 1: `request.not_found` überall, der Einheitlichkeit wegen. Zu 2: `If-Match`
verlangen und `428` antworten — sicherer gegen Überschreiben, aber eine Entscheidung, die ein
einzelnes Paket nicht für die ganze API treffen sollte. Zu 3: das Enum insgesamt sortieren,
wenn alle Pakete geliefert haben.

**Korrekturkosten:** Zu 1 und 3 je eine Zeile plus die Stellen, die den Code nennen. Zu 2 gering,
solange kein Client sich darauf verlässt, dass `If-Match` weggelassen werden darf.

---

## U-56 — `lists.members` wird weit gelesen, eng geschrieben und unverändert byteweise erhalten

> **Ersetzt durch U-134.** Geschrieben wird seit dem Pipe-Transport ein Eintrag pro Zeile, nicht
> komma-separiert: `members` liest allein `maillist.py`, und das trennt an Zeilenumbrüchen. Weites
> Lesen und Bytetreue gelten weiter.

**Frage:** A5/1 — wie sind die Einträge in `lists.members` getrennt? Komma, Semikolon,
Zeilenumbruch? Das Paketdokument verlangt, das **am Bestand zu prüfen**, bevor irgendetwas
geschrieben wird; ein falsches Trennzeichen macht die 22 Bestandslisten unbrauchbar. Aus der
Implementierung heraus war das nicht prüfbar — die tatsächlichen Zeilen liegen auf dem
Produktivserver, und die Bestandsanalyse hat nur die Spaltennamen festgehalten.

**Entscheidung:** **Die Frage wird nicht beantwortet, sondern folgenlos gemacht.**

- **Lesen** trennt an Komma, Semikolon und Zeilenumbruch, in beliebiger Wiederholung, und
  entfernt umgebende Leerzeichen.
- **Zurückschreiben ohne Änderung liefert die Originalbytes.** `MemberList::withMembers()` gibt
  **dasselbe Objekt** zurück, wenn die zerlegte Mitgliederfolge unverändert ist, und
  `toStoredValue()` liefert dann den Originaltext — samt Trennzeichen, Abständen und einem
  `NULL`, das `NULL` bleibt.
- **Nur eine tatsächlich geänderte Mitgliederliste wird neu gerendert**, und zwar
  komma-separiert.

**Warum:** Die Bytetreue macht die offene Frage folgenlos. Wer einen Tag korrigiert, schreibt die
Mitgliederspalte identisch zurück, gleich welches Trennzeichen darin steht; die Frage nach dem
richtigen Trennzeichen stellt sich erst, wenn jemand die Mitglieder wirklich ändert — und dann
hat er die Liste ohnehin vor sich. Weit zu lesen ist dabei die vorsichtigere Hälfte: falsch
zerlegen macht aus einem Mitglied mehrere oder aus mehreren eines, und was falsch hereinkommt,
geht zerstört wieder hinaus.

Komma für das Rendern, weil Salt daraus `/etc/aliases-dir/aliases` erzeugt (E-4,
Bestandsanalyse 6) und Komma dort der Trenner der Empfängerliste ist. Die F5-Fixtures nehmen
dieselbe Annahme.

**Alternative:** Das Trennzeichen konfigurierbar machen. Das verlagert dieselbe unbeantwortete
Frage in eine Umgebungsvariable, die beim ersten Speichern falsch stehen kann. — Oder das
Produktivsystem lesend abfragen und das Ergebnis festschreiben: sinnvoll, aber dann ist es eine
Bestätigung dieser Entscheidung und keine andere.

**Korrekturkosten:** Trivial. `MemberList::SEPARATOR` und `MemberList::PARSE_PATTERN` sind je
eine Konstante; die Bytetreue bleibt davon unberührt.

---

## U-57 — Ein neues Mitglied muss eine Mailadresse sein; ein bestehendes wird markiert

**Frage:** A5/4 — Mitgliederadressen streng prüfen und ablehnen, oder nur warnen? Im Bestand
könnten ungültige Einträge stehen, die beim ersten Speichern verloren gehen.

**Entscheidung:** **Beides, nach Herkunft getrennt.** Ein Eintrag, der **hinzukommt**, muss eine
Mailadresse sein — sonst `422` mit `list.invalid_member`. Ein Eintrag, der **bereits gespeichert
ist**, wird nie geprüft, nie entfernt und nie korrigiert; er wird im Feld `unusable_members`
ausgewiesen, damit die Oberfläche ihn kenntlich machen kann (U-22). Dasselbe gilt für eine
Listenadresse, die keine ist: `address_usable` sagt es, verweigert aber nichts.

**Warum:** Die Strenge ist keine Validierungspedanterie, sondern die einzige Sicherheitsregel des
Pakets. Die Spalte wird in eine Aliasdatei gerendert, und dort bedeuten `|kommando` und
`/pfad/zur/datei` **Kommando** und **Datei**, nicht Empfänger. Ein Mitgliederfeld, das
beliebigen Text annimmt, gibt jedem, der eine Liste bearbeiten darf, einen Weg, den MTA ein
Kommando ausführen zu lassen.

Die Ausnahme für Bestandseinträge ist die Kehrseite derselben Überlegung: `lists` wird heute von
einem fremden Werkzeug geschrieben, und eine Liste, die sich nicht mehr speichern lässt, weil
irgendwer eine Pipe hineingeschrieben hat, ist eine Liste, die niemand mehr pflegen kann. Der
Vergleich läuft byteweise gegen die gespeicherte Menge, also kann die Ausnahme nicht dazu benutzt
werden, eine *neue* Pipe unterzubringen.

**Alternative:** Alles ablehnen, was keine Adresse ist, auch im Bestand. Dann ist die erste
Änderung an einer solchen Liste ein Datenverlust oder eine Sackgasse. — Oder gar nichts prüfen
und nur warnen: das ist die Variante mit dem Kommando in der Aliasdatei.

**Korrekturkosten:** Gering; die Prüfung ist eine Methode in `MemberList`.

---

## U-58 — Listen bleiben Superuser-Sache, und `tag` bleibt uninterpretiert

> **Im Punkt „Rolle“ geändert durch U-136.** Domainmaster verwalten die Listen ihrer Domains,
> seit die Adresse die Identität ist (U-135). Was hier über `tag` steht, gilt weiter.

**Frage:** A5/2 und A5/3 — wofür wird `tag` verwendet? Und dürfen Domainmaster Listen ihrer
eigenen Domain verwalten? S3 hatte beides als Annahme offen gelassen und A5 ausdrücklich erlaubt
zu erweitern.

**Entscheidung:** **Keine der beiden Erweiterungen.** `tag` ist ein freies Textfeld: gespeichert,
angezeigt, auf Wunsch geändert — und an keiner Stelle ausgewertet. `list.read`, `list.write` und
`list.delete` bleiben beim Superuser.

**Warum, für `tag`:** Was die Spalte bedeutet, wurde nirgends festgehalten und ist aus dem Schema
nicht ableitbar. Eine Bedeutung zu erfinden für ein Feld, das ein fremdes Werkzeug schreibt, ist
genau der Weg, auf dem zwei Werkzeuge über dasselbe Feld verschiedener Meinung werden. Anzeigen
und durchreichen nimmt niemandem etwas und lässt jede spätere Deutung offen.

**Warum, für die Rolle:** `lists` hat keine Domainspalte, und der Primärschlüssel ist `name` —
ein **globaler** Namensraum ohne Domainanteil. Ein Zuschnitt über `address` würde zwar regeln,
welche Zeilen jemand *sieht*, aber nicht, welchen Namen er belegt: der erste Domainmaster, der
eine Liste `sales` anlegt, nimmt den Namen allen anderen weg, und der Konflikt wäre eine `409`
ohne erkennbaren Grund. Solange nicht bekannt ist, wie die Namen in der Aliasdatei verwendet
werden (siehe `tag`), ist das kein Detail, das man erweitern und später wieder einfangen kann.

Die Erweiterung bleibt zweizeilig, so wie S3 sie beschrieben hat, und ist jetzt zusätzlich
vorbereitet: die Listenabfrage engt bereits über `Repository::scopedTo()` auf eine abgeleitete
Spalte `list_domain` ein (`SUBSTRING_INDEX(address, '@', -1)`), und ein Integrationstest fährt
diesen Pfad gegen ein echtes MariaDB. Wer erweitert, ergänzt `RolePermissions::domainMaster()`
und ruft `ListPolicy::requireVisible()` — kein Handler und keine Abfrage ändert sich.

**Alternative:** Domainmaster zulassen und den Namenskonflikt in Kauf nehmen; erweitern nimmt
niemandem etwas weg, verengen später schon. Das ist das Argument, das S3 für eine spätere
Erweiterung nennt — es trägt hier nur deshalb nicht, weil der belegte Name eben doch etwas
wegnimmt.

**Korrekturkosten:** Zwei Zeilen für die Rolle. Für `tag` beliebig, solange niemand die Spalte
zwischenzeitlich mit einer erfundenen Bedeutung befüllt hat.

---

## U-59 — Der Hinweis auf den Konfigurationslauf ist ein Antwortkopf

> **Eingeschränkt durch U-143.** Der Kopf erscheint nur noch bei
> `MAILADMIN_LIST_DELIVERY=alias`; im Transportmodus wirkt eine Änderung sofort.

**Frage:** A5/5 — wie deutlich wird darauf hingewiesen, dass eine Änderung erst nach dem nächsten
Konfigurationslauf wirkt? Die Definition of Done verlangt den Hinweis **nach jeder** Änderung.

**Entscheidung:** Jede zustandsändernde Antwort trägt den Kopf
`X-Mailadmin-Effect: pending-configuration-run`. Der Wert ist ein stabiles Schlüsselwort, kein
Satz; übersetzt wird im Frontend (E-16). Der Kopf ist im Vertrag als Komponente
`MailingListEffect` deklariert und an `201`, `200` und `204` gebunden.

**Warum:** „Nach jeder Änderung" schließt das Löschen ein, und das Löschen antwortet mit `204`
ohne Körper. Ein Feld im Antwortkörper hätte zwei von drei Schreibvorgängen erwischt und
ausgerechnet den ausgelassen, dessen Verzögerung am meisten überrascht: die Adresse stellt nach
dem `204` weiter zu. Ein Kopf gilt für alle drei, verbiegt keine Körperschemata und ist
maschinenlesbar.

Der Wert ist ein Schlüsselwort und keine Meldung, weil eine übersetzte Meldung nie
Vertragsbestandteil wird (`CLAUDE.md`, Mehrsprachigkeit).

**Alternative:** Ein Feld `effect` im Antwortkörper, `DELETE` dafür auf `200` umstellen. Das
bricht mit `openapi/README.md` (9), wonach Löschen `204` ohne Körper beantwortet. — Oder gar kein
maschinenlesbarer Hinweis und nur ein fester Text im Frontend: dann steht die Tatsache an einer
Stelle, die niemand mit dem Server abgleicht.

**Korrekturkosten:** Gering. Ein Kopf, eine Komponente, ein Konstantenpaar in
`MailingListResponder`.

---

## U-60 — Eine Liste wird über ihren Namen angesprochen; Umbenennen ist Löschen und Anlegen

> **Ersetzt durch U-135.** Die Produktions-DDL hat eine Spalte `id` als Primärschlüssel und keinen
> Unique-Index auf `name`. Eine Liste wird über ihre Adresse angesprochen, `name` ist änderbarer
> Anzeigetext.

**Frage:** Nicht gestellt, aber der Vertrag verlangt es: Architektur 7.2 nennt
`PATCH/DELETE /lists/{id}`, und das Paketdokument führt eine Spalte `id` auf. Die Tabelle hat
keine — die Bestandsanalyse (4.1) kennt genau vier Spalten, und der Primärschlüssel ist `name`.

**Entscheidung:** `{id}` ist der **URL-kodierte Listenname**; der Vertrag nennt den Parameter
`listName` mit `type: string`. `name` ist im `PATCH`-Körper nicht enthalten: umbenannt wird durch
Löschen und Neuanlegen.

**Warum:** Dieselbe Begründung wie U-18 für Konten. Eine numerische ID verlangte eine neue Spalte
an einer Bestandstabelle, die ein fremdes Werkzeug schreibt, oder eine Zuordnungstabelle, die
konsistent zu halten wäre, obwohl daneben jemand Zeilen anlegt. Der Name ist bereits eindeutig
und stabil.

Kein Umbenennen per `PATCH`, weil der Name zugleich die URL und der Schlüssel ist, den das
Konfigurationsmanagement liest: eine Umbenennung ändert beides und ist damit kein Feldwechsel,
sondern ein Identitätswechsel. Ihn als `PATCH` zu tarnen erzeugt eine Ressource, deren URL sich
unter dem Client ändert, ohne dass er es erfährt.

**Alternative:** Umbenennen erlauben und mit `Location` auf die neue URL zeigen. Möglich, aber
dann muss auch entschieden werden, was mit einer Aliasdatei geschieht, die den alten Namen noch
enthält — und das ist eine Frage an den Konfigurationslauf, nicht an diese Anwendung.

**Korrekturkosten:** Hoch, sobald Clients existieren — es ist die Gestalt jeder Listen-URL.

---

## U-61 — Was ein Eintrag festhält, wie lange, und was bei einem Stapelvorgang geschieht

**Frage:** S4, Punkte 1 bis 3 und 5: Aufbewahrungsdauer, was „vorher" bei einer Neuanlage und
„nachher" bei einer Löschung bedeutet, ein Eintrag je Vorgang oder je Objekt, und ob
fehlgeschlagene Operationen überhaupt protokolliert werden.

**Entscheidung:** Vier Festlegungen, die zusammengehören, weil sie dieselbe Tabelle beschreiben.

**Unbegrenzte Aufbewahrung, kein Aufräumlauf.** Es gibt kein `bin/prune-audit`, und es soll
keines geben. Ein Revisionsprotokoll, das sich nach Plan selbst löscht, beantwortet die Frage
„wer hat das im Frühjahr geändert" mit Schweigen — und das ist genau die Frage, für die es
angelegt wurde. Die Größenordnung trägt das: eine Installation dieser Art erzeugt Tausende
Zeilen im Jahr, nicht Millionen. Wer später doch löschen muss, tut es bewusst und von Hand;
`mailadmin_login_attempt` bleibt davon unberührt, das wird weiterhin von `bin/prune-tokens`
gestutzt, weil es ein Zähler ist und kein Protokoll.

**`NULL` statt `{}`.** Eine Neuanlage hat kein `state_before`, eine Löschung kein `state_after`.
Beide sind `NULL`, nicht das leere Objekt: „hat nicht existiert" und „war leer" sind
unterschiedliche Aussagen, und eine Auswertung, die sie nicht trennen kann, ist beim ersten
interessanten Fall unbrauchbar. Die benannten Konstruktoren von `AuditEntry` erzwingen das —
`creation()` nimmt kein `before` entgegen, `removal()` kein `after`.

**Ein Vorgangseintrag plus Einzeleinträge mit Verweis**, wie im Paketdokument vorgeschlagen. Die
Spalte heißt `parent_id`, der Aufruf `->partOf($operation)`. Eine Domainumbenennung schreibt
einen Eintrag für die Umbenennung und einen je Konto; ohne den Verweis läge dort ein Schwall von
vierzig zusammenhanglosen Änderungen in derselben Sekunde. Bewusst **kein** Fremdschlüssel: der
Elterneintrag wird zuerst geschrieben und mit den Kindern zusammen festgeschrieben, und eine
Fremdschlüsselprüfung wäre eine Sperre auf einer Tabelle, deren einzige Aufgabe das Annehmen von
`INSERT`s ist.

**Fehlschläge und Ablehnungen werden protokolliert**, über ein Ergebnisfeld mit drei Werten:
`succeeded`, `denied`, `failed`. Drei und nicht zwei, weil eine Ablehnung genau der Eintrag ist,
den ein Betreiber sucht, und weder ein Erfolg noch eine Störung ist.

**Warum:** Jeder der vier Punkte ist billiger jetzt als später. Eine Spalte, die von Anfang an
`NULL` zulässt, kostet nichts; eine, die nachträglich zwischen „leer" und „nicht vorhanden"
unterscheiden soll, kostet die gesamte Historie.

**Alternative:** Aufräumlauf nach zwölf Monaten, wie bei den Anmeldeversuchen. Verworfen, weil
die Anmeldeversuche ein Zähler mit einem Fenster sind und dies ein Protokoll ohne eines.

**Korrekturkosten:** Gering für die Aufbewahrung (ein Skript nachrüsten ist additiv), hoch für
die `NULL`-Frage, sobald Zeilen existieren.

---

## U-62 — Maskieren heißt Platzhalter für Passwörter und Verschwinden für Hashwerte

**Frage:** S4 verlangt „Geheimnisse werden maskiert, nicht gekürzt: Passwortfelder erscheinen als
Platzhalter, Hashes gar nicht." Was heißt das im Code, und woher kommt die Liste dessen, was als
Geheimnis gilt?

**Entscheidung:** `Application\Audit\Redactor` läuft **im Schreiber**, nicht beim Aufrufer. Es
gibt keine unmaskierte Form irgendwo — auch nicht für einen Betreiber mit einem SQL-Client.

| Schlüssel | Ergebnis | Grund |
|---|---|---|
| `password`, `secret`, `token`, `cookie`, … | Wert wird `[redacted]` | *Dass* ein Passwort gesetzt wurde, gehört ins Protokoll; der Wert nie |
| `password_hash`, `crypt`, … | **Schlüssel fällt ganz weg** | Ein Hash ist ein Zugangsmittel in langsamer Form. Selbst der Platzhalter würde behaupten, das Protokoll führe eines |
| `password_format`, `hash_format` | bleibt lesbar | E-13 und U-22 brauchen das *Format* eines Altverfahrens sichtbar — daran werden die DES-crypt-Konten gefunden |

**Das Vokabular ist dasselbe wie im Log.** `Redactor` fragt
`Log\SecretMaskingProcessor::isSecretKey()` — zwei Listen wären zwei Orte, an denen `passphrase`
nachgetragen werden müsste, und der zweite würde vergessen. Die Unterscheidung
Hash/Platzhalter kommt hier dazu, der Logger braucht sie nicht, weil er alles gleich behandelt.

**Zusätzlich eine Wertprüfung.** Schlüsselvergleich fängt die Felder, an die jemand denkt. Er
fängt nichts an `['stored' => '$2y$10$…']`, und ein Anwendungsfall, der eine Zeile aus
`accountuser` unverändert weiterreicht, ist genau der Weg dorthin. Werte, die *erkennbar* ein
gespeichertes Zugangsmittel sind, fallen deshalb unabhängig vom Schlüsselnamen weg: die
crypt(3)-Präfixe, die RFC-2307-Schemata und die Ausgabe von MySQLs `PASSWORD()`. Das kann nicht
vollständig sein — nichts kann das — und ist die zweite Verteidigungslinie hinter der Regel,
dass Aufrufer die API-Darstellung übergeben und nicht die Datenbankzeile.

**Warum:** Die Zusicherung aus der Definition of Done lautet „kein Eintrag enthält ein
Klartextpasswort oder einen Hash", nicht „kein Aufrufer übergibt eines". Eine Zusicherung, die
von der Aufmerksamkeit von acht Paketen abhängt, ist keine.

**Alternative:** Maskierung im Aufrufer, per Konvention. Das ist die Form, in der sie sieben Mal
richtig und einmal falsch gemacht wird — und der eine Fall ist der, der zählt.

**Korrekturkosten:** Keine für neue Einträge. Bereits geschriebene Zeilen sind nicht
nachträglich zu bereinigen, was ein weiterer Grund ist, es vor der ersten Zeile richtig zu
machen.

---

## U-63 — Wann geschrieben wird, und was ein fehlgeschlagener Schreibvorgang bedeutet

**Frage:** S4, Punkt 4: schreibt der Audit-Eintrag in derselben Transaktion? Vor dem Versuch,
nach dem Erfolg, oder beides?

**Entscheidung:** **Nach dem Ergebnis, und in der Transaktion, in der das Ergebnis steht.**

- **Erfolg:** innerhalb der Transaktion, die die Änderung vorgenommen hat. Dann werden Zeile und
  Änderung gemeinsam festgeschrieben, und eine zurückgerollte Operation hinterlässt keinen
  Eintrag, der behauptet, sie sei geschehen.
- **Ablehnung und Fehlschlag:** **nach** dem Zurückrollen. Es gibt nichts mehr, womit der Eintrag
  atomar sein könnte, und innerhalb der verlorenen Transaktion geschrieben würde er mit ihr
  verworfen — ausgerechnet der Eintrag, den ein Betreiber am ehesten sucht.
- **Nie um den Mailstore herum.** Eine Transaktion reicht nicht bis Cyrus. Der Eintrag hält fest,
  was die Datenbank am Ende sagt; ein Postfach, das nicht angelegt werden konnte, gehört in
  `mailadmin_repair_queue` (A6) und nicht in eine zweite Audit-Zeile.

**Ein fehlgeschlagener Schreibvorgang wird nicht geschluckt.** `SqlAuditWriter::record()` lässt
`PersistenceError` durch. An der vorgesehenen Stelle aufgerufen — in der Transaktion der
Operation — rollt das die Änderung mit zurück. Das ist die beabsichtigte Lesart von „jede
Änderung wird protokolliert": lässt sich der Eintrag nicht schreiben, bleibt die Änderung nicht
bestehen.

**Zwei Stellen fangen trotzdem selbst**, weil dort der Preis höher wäre als der Verlust einer
Zeile: `Application\Auth\SessionAudit` (Anmeldung, Abmeldung, erkannter Token-Diebstahl) und
`Http\Middleware\AuditMiddleware` (Ablehnungen). Die erste liegt auf dem einzigen Weg, über den
ein Administrator die Anwendung überhaupt erreicht — eine vollgelaufene Audit-Tabelle würde
sonst alle aussperren, auch den, der sie leeren soll. Die zweite würde aus einem `403` ein `500`
machen und damit jedem, der einen Schreibfehler auslösen kann, ein Mittel geben, Ablehnungen wie
Serverfehler aussehen zu lassen. Beide protokollieren den Fehlschlag im Anwendungslog.

**Warum:** „Vor dem Versuch schreiben" wäre die dritte Möglichkeit und die schlechteste: sie
erzeugt einen Eintrag für jeden Versuch und muss ihn hinterher berichtigen — ein Protokoll, das
sich selbst korrigiert, ist keines.

**Alternative:** Den Eintrag über eine zweite Verbindung schreiben, damit er von keiner
Transaktion abhängt. Damit überlebt der Eintrag einer zurückgerollten Operation und behauptet
eine Änderung, die nicht stattgefunden hat.

**Korrekturkosten:** Gering und aufrufseitig — es ist die Stelle, an der `record()` steht.

---

## U-64 — Die Schnittstelle für die A-Pakete: ein Aufruf, und der Anfragekontext kommt nicht als Parameter

**Frage:** Nicht gestellt, aber tragend: S4 ist Grundlage für A1 bis A6, M3 und S2. Wie sieht der
Aufruf aus, den acht Pakete schreiben, ohne dass er umgebaut werden muss?

**Entscheidung:** Ein Port mit **einer** Methode, ein Wertobjekt mit benannten Konstruktoren:

```php
public function __construct(private DomainRepository $domains, private AuditWriter $audit) {}

$this->audit->record(AuditEntry::creation($actor, 'domain.create', 'domain', $name, after: $view));
$this->audit->record(AuditEntry::change($actor,  'domain.update', 'domain', $name, before: $a, after: $b));
$this->audit->record(AuditEntry::removal($actor, 'domain.delete', 'domain', $name, before: $a));
$this->audit->record(AuditEntry::refusal($actor, 'domain.delete', 'domain', $name, reason: '…'));
$this->audit->record(AuditEntry::failure($actor, 'domain.rename', 'domain', $name, reason: '…'));
```

`$actor` ist der `ActorContext`, den jeder Anwendungsfall ohnehin als ersten Parameter hat.

**IP und Request-Id sind keine Parameter.** Sie sind Tatsachen über die Anfrage, nicht über die
Operation. Sie durch jede Signatur zu fädeln brächte HTTP in die Anwendungsschicht und würde
irgendwo vergessen. Stattdessen füllt `Http\Middleware\AuditMiddleware` einen anfragegebundenen
Halter (`AuditRequestContext`), aus dem der Schreiber liest — dieselbe Form, die
`Log\RequestIdProcessor` seit F1 hat, aus demselben Grund. Er wird im `finally` geleert, damit in
einem langlebigen Prozess keine Anfrage die Adresse der vorigen erbt.

**Die Aktion ist ein geprüfter String, keine Aufzählung.** Eine Aufzählung wäre die naheliegende
Wahl und hier die falsche: sie wäre eine geteilte Datei, die acht Pakete gleichzeitig ändern —
genau das, was U-3 bis U-6 in vier Entscheidungen abgeschafft haben. Erzwungen wird die *Form*
(`<objekt>.<verb>`, wie `x-permission` und die Fehlercodes), das Vokabular gehört dem Paket, dem
das Objekt gehört.

**Die Attrappe ist Teil der Schnittstelle.** `InMemoryAuditTrail` erfüllt Schreiber und Leser,
führt denselben `Redactor` aus und beantwortet `last()`, `withAction()` und `all()`. Damit kann
ein A-Paket seinen Anwendungsfall gegen die Attrappe testen **und** belegen, dass kein Geheimnis
im Protokoll landet. Sie ist bewusst nicht im Container verdrahtet (U-37): eine Konfiguration,
die sich per Umgebungsvariable auf ein Protokoll im Arbeitsspeicher umstellen ließe, wäre eine
Variable von einer Installation entfernt, die nichts aufzeichnet.

**Warum:** Der Wert dieses Pakets bemisst sich daran, wie schwer es ist, den Aufruf zu vergessen
oder falsch zu schreiben. Ein Parameter, ein Konstruktorargument, keine Konfiguration.

**Alternative:** Ein Ereignisverteiler und Abonnenten. Entkoppelt stärker und macht die Frage
„wird diese Operation protokolliert?" zu einer Volltextsuche — dieselbe Form, in der das
Altsystem seine Berechtigungen verwaltete.

**Korrekturkosten:** Gering, solange nur `record()` aufgerufen wird. Ein weiterer Parameter am
Wertobjekt ist additiv.

---

## U-65 — Der Leseendpunkt: Superuser, `desc` als Vorgabe, und eine Domainspalte für später

**Frage:** S4, Punkt 6: Sichtbarkeit für Domainmaster. Dazu die Fragen, die der Endpunkt selbst
aufwirft — Sortiervorgabe und Filterumfang.

**Entscheidung:** `GET /audit` trägt `x-permission: audit.read` und bleibt damit
**Superuser-Sache**; U-43 hat das bereits entschieden, und der Grund trägt: ein Eintrag nennt
Administratoren über Domaingrenzen hinweg, eine eingeengte Sicht wäre also überall dort eine
Sicht auf fremde Vorgänge, wo sich zwei Administratoren eine Domain teilen.

**Trotzdem wird `domain_name` mitgeschrieben, und die Abfrage ist gescoped.** Der Aufruf von
`Repository::scopedTo()` engt heute nichts ein, weil kein Domainmaster den Endpunkt erreicht.
Er steht da, weil die Domain eines Objekts **nur im Moment der Operation** bekannt ist: nach dem
Löschen des Objekts ist sie nicht mehr rekonstruierbar. Eine Spalte jetzt kostet nichts; sie
später zu brauchen und für alles Aufgezeichnete nicht zu haben, kostet die Historie. Ein Eintrag
ohne Domain — eine Anmeldung, ein Wartungslauf — liegt in keinem Zuschnitt, was dieselbe Antwort
ist, die `restrictToDomains()` mit `IN (…)` und `NULL` ohnehin gibt.

**Die Vorgabe für `order` ist `desc`, und nur hier.** Das interessante Ende eines
Revisionsprotokolls ist das jüngste; eine aufsteigende Vorgabe machte Seite eins auf Dauer zu den
ältesten Zeilen der Tabelle. Der gemeinsame Parameter aus `common.yaml` bleibt unangetastet —
`components/audit.yaml` deklariert einen eigenen mit derselben Schemareferenz und anderer
Vorgabe. Weil eine OpenAPI-Vorgabe Dokumentation ist und den Parser nie erreicht, setzt der
Handler sie selbst, und ein Vertragstest vergleicht beide Werte, damit sie nicht auseinanderlaufen.

**Sortiert wird zusätzlich immer nach `id`.** Zeitstempel haben Sekundenauflösung, und ein
Stapelvorgang schreibt Dutzende Zeilen innerhalb einer Sekunde; ohne den zweiten Schlüssel kann
dieselbe Zeile auf Seite eins und auf Seite zwei erscheinen, ohne dass sich etwas geändert hat.

**Gefiltert wird über zehn aufgezählte Felder**, darunter `request_id` und `part_of`. Die beiden
sind der Grund für den Abschnitt: `request_id` verbindet einen Eintrag mit dem Anwendungslog
derselben Anfrage — die Definition of Done —, `part_of` hält einen Stapelvorgang zusammen.
`action` vergleicht als Präfix, damit `domain.` „alles, was Domains betraf" beantwortet.

**Warum:** Jede dieser Festlegungen ist entweder billig und jetzt nötig (die Spalte) oder teuer
und später nicht mehr nachholbar (dieselbe Spalte). Die Vorgabe `desc` ist die einzige, die
Geschmack ist — und sie ist begründet.

**Alternative:** Dem Domainmaster eine eingeengte Sicht geben. Verworfen mit U-43; die
Erweiterung bleibt jederzeit möglich und nimmt niemandem etwas.

**Korrekturkosten:** Gering. `audit.read` in `RolePermissions` umzuhängen ist eine Zeile, und die
Einengung ist bereits gebaut und getestet.

---

## U-66 — ManageSieve bekommt einen eigenen Client und einen eigenen Pool; TLS ohne Schalter

**Frage:** M1 hat einen IMAP-Client mit Verbindungspool. ManageSieve spricht dieselbe Art von
Strom, aber eine andere Grammatik, läuft auf einem anderen Port und kennt keine Command-Tags.
Denselben Client erweitern, oder einen zweiten schreiben? Und woher kommen Host, CA, Zeitlimits
und Zugangsdaten?

**Entscheidung:**

1. **Ein eigener Client** unter `Infrastructure/Mailstore/Sieve/`, aber auf **demselben**
   `Transport`. Damit sind Zertifikatsprüfung, Zeitlimits und „niemals Klartext" einmal
   implementiert und nicht zweimal.
2. **Ein eigener Pool.** `ManageSieveConnectionPool` steht neben `ConnectionPool`. Ein
   gemeinsamer Pool müsste nach Endpunkt *und* Autorisierungsidentität schlüsseln und würde beim
   ersten falschen Schlüssel einem IMAP-Aufrufer eine ManageSieve-Verbindung geben.
3. **Nur der Port ist eigene Konfiguration** (`MAILADMIN_MANAGESIEVE_PORT`, Vorgabe 4190). Host,
   CA-Bündel, Zeitlimits und Administrator kommen aus `MailstoreConfiguration` — es ist derselbe
   Server. Ein zweiter Satz Variablen erlaubte, Sieve auf eine andere Maschine zu zeigen als
   IMAP, wo beide stillschweigend uneins wären, welches Konto existiert; und er erlaubte, für
   das eine eine CA zu nennen und für das andere nicht.
4. **`STARTTLS` ist nicht wählbar, sondern die einzige Möglichkeit.** RFC 5804 kennt keinen
   Port für implizites TLS und timsieved bietet keinen an. Der Pool handelt TLS aus, sobald die
   Verbindung steht; erst danach wird eine Anmeldeinformation geschrieben, und ob die Leitung
   verschlüsselt ist, wird **den Socket** gefragt, nicht die Konfiguration.

**Warum:** Der Unterschied zwischen den Protokollen liegt genau dort, wo Fehler teuer sind — bei
den Literalen. IMAP wartet auf ein `+`, ManageSieve nicht (`{n+}` ist nicht synchronisierend).
Einen Client für beides zu bauen hieße, diese Verzweigung in jedem Schreibvorgang zu tragen.

**Alternative:** `Connection` um einen Grammatik-Schalter erweitern. Verworfen: der Schalter
säße mitten im Schreiben von Kommandos, und ein falsch gesetzter Schalter erzeugt eine
Verbindung, die Daten sendet, die der Server als Kommandos liest.

**Korrekturkosten:** Gering. Der Pool ist eine Definition in `config/di/sieve.php`.

---

## U-67 — Die Sperre gegen fremde Skripte liegt über dem Port, nicht im Adapter

**Frage:** Architektur 9.4 verlangt, dass ein aktives Skript ohne mailadmin-Markierung **nicht
überschrieben** wird. `SieveScript::isManaged()` *erkennt* das. Wer *erzwingt* es — der Adapter,
der Anwendungsfall, oder etwas dazwischen?

**Entscheidung:** Ein eigenes Objekt über dem Port:
`Infrastructure\Mailstore\SieveScriptInstaller`. Es nimmt einen `SieveStore`, einen
`SieveRenderer` und optional die Serverfähigkeiten und erledigt vier Schritte in dieser
Reihenfolge: Erweiterungen prüfen, aktives Skript prüfen, vollständig rendern und ablegen,
danach aktivieren. Eine Übernahme ist ein **Parameter des Vorgangs**
(`$takeOverForeignScript`), kein Schalter der Installation.

**Warum nicht im Adapter:** Dann wäre die Attrappe nachsichtiger als der Server — und jeder
Anwendungsfall der Anwendung wird gegen die Attrappe getestet. Der Vertragstestsatz
(`SieveStoreContract`) fordert von beiden dasselbe; die Sperre gehört an die Stelle, an der
beide sie gleich bekommen.

**Warum nicht im Anwendungsfall:** Dann wäre sie eine Regel, die A3 sich merken muss. Eine
Regel, die man befolgen *muss*, ist billiger als eine, die man befolgen *soll* — dasselbe
Argument wie bei `Repository::scopedTo()` in U-45.

**Warum nicht im Fachmodell:** Das Objekt müsste einen Fehler mit dem Code
`sieve.script_foreign` werfen, und dieser Code ist eine Infrastrukturkonstante (U-7). Ein
Domänenobjekt, das sie nennt, wäre der Schichtbruch, den U-37 gerade vermieden hat.

**Die Erweiterungsprüfung sitzt aus demselben Grund dort.** Sie braucht die Regelmenge (die
weiß, was sie deklariert) *und* den Server (der weiß, was er kann); der Adapter sieht nur ein
fertig gerendertes Skript. Die dafür nötige Schnittstelle `SieveCapabilities` nimmt eine
`MailboxIdentity`, damit die Antwort von **derselben Sitzung** kommt, auf der geschrieben wird.

**Alternative:** Die Sperre als Dekorator um `SieveStore`. Gleichwertig sicher, aber dann hätte
`putScript()` je nach Verdrahtung zwei verschiedene Bedeutungen — und der Vertragstestsatz
könnte nicht mehr sagen, welche er prüft.

**Korrekturkosten:** Gering; A3 ist der einzige künftige Aufrufer.

---

## U-68 — `SieveStore::deactivate()` — die dritte additive Abweichung von Architektur 9.1

**Frage:** Architektur 9.1 zeigt `activeScript`, `putScript`, `activate`, `deleteScript`. Wie
wird eine Abwesenheitsnotiz wieder **abgeschaltet**?

**Entscheidung:** Der Port bekommt `deactivate(MailboxIdentity): void`. In ManageSieve ist das
`SETACTIVE ""`.

**Warum:** Ohne die Methode gibt es genau einen Weg, eine Regelmenge zurückzunehmen: ein leeres
Skript ablegen und aktiv lassen. Jeder ManageSieve-Server verweigert das Löschen des laufenden
Skripts, also bliebe es stehen — und ein leeres aktives Skript ist für den nächsten Leser nicht
davon zu unterscheiden, dass das Rendern fehlgeschlagen ist. `activate($id, '')` als
Sonderbedeutung des leeren Namens wäre die Alternative gewesen; ein Sonderfall in einem
Zeichenkettenparameter ist genau die Art Vertrag, die man beim Lesen des Aufrufs nicht sieht.

Ein Deaktivieren, wenn ohnehin nichts läuft, ist ein **Erfolg**: die Nachbedingung gilt, und ein
Reparaturlauf (A6) muss zu Ende kommen können, statt über die eigene frühere Arbeit zu
stolpern.

**Damit sind es drei additive Abweichungen von 9.1** — `createDefaultFolders()` und
`scriptNames()` aus U-37, dazu diese. Alle drei sind Ergänzungen; keine ändert eine bestehende
Signatur.

**Korrekturkosten:** Keine. Attrappe und Adapter haben sie, der Vertragstestsatz prüft sie.

---

## U-69 — Die sechs offenen Punkte aus M3, beantwortet

| # | Frage | Antwort |
|---|---|---|
| 1 | **Skriptname** | Der alte Name `sieve` bleibt (`SieveScript::LEGACY_NAME`). Nur so wird ein vorhandenes webcyradm-Skript überhaupt gefunden; unterschieden werden die beiden über die **Markierung** im Kopf, nicht über den Namen. Eine Migration alter Skripte findet nicht statt (E-18, U-22): sie werden erkannt, angezeigt und erst nach ausdrücklicher Übernahme ersetzt. |
| 2 | **Erweiterungen** | Vorausgesetzt wird nur, was die Regelmenge selbst deklariert — `vacation` für die Abwesenheit, `fileinto` für einen Filter, der einsortiert. Die Fähigkeiten werden beim Verbinden gelesen und **vor** dem Schreiben verglichen; fehlt eine, ist das `sieve.extension_missing` und nicht ein abgelehntes Skript. Gemessen: timsieved 3.10.2 kann beide (U-70 f). |
| 3 | **`:days`** | Teil der Regel, Vorgabe **7** statt der 1 des Altsystems, Obergrenze 60 (`VacationRule`). Eine 1 bedeutet, dass derselbe Absender **täglich** eine Abwesenheitsnotiz bekommt; das ist keine Voreinstellung, das ist ein Fehler mit Bestandsschutz. A3 darf den Wert anbieten. |
| 4 | **Zeichensatz** | Durchgehend Oktette, kein `iconv` an irgendeiner Stelle. Der Renderer schreibt UTF-8, der Client kündigt in `{n+}` eine **Byte**länge an, der Server liefert dieselbe Zahl zurück. Sonderzeichen im Betreff werden Sieve-typisch maskiert (`"` und `\`), der Text reist als mehrzeiliges Literal mit Punkt-Stopfung. Gegen den echten Server geprüft, byteweise. |
| 5 | **Mehrere Skripte** | Es bleibt bei **einem** verwalteten Skript. Andere Skripte werden aufgezählt (`scriptNames()`) und nicht angefasst — ein Nutzer, der sich in Roundcube ein zweites Skript angelegt hat, verliert es nicht. Aktiv sein kann in ManageSieve ohnehin nur eines. |
| 6 | **Zeitraum** | **Nicht** in M3. Sieve `vacation` kennt kein Start- und Enddatum; ein verwaltetes Datum bräuchte einen geplanten Lauf, den das System sonst nicht hat. Die Entscheidung gehört A3; das Modell tut nicht so, als hätte es einen. |

**Korrekturkosten:** Punkt 1 wäre teuer, sobald Skripte unter dem neuen Namen liegen; die
übrigen sind Parameter.

---

## U-70 — Was gegen den echten timsieved gemessen wurde

M3 ist gegen die Testumgebung gefahren worden (F5, **Cyrus timsieved 3.10.2** auf Debian). Die
folgenden Punkte sind **gemessen, nicht angenommen**.

| # | Befund | Folge |
|---|---|---|
| a | **Vor dem Handshake meldet timsieved `"SASL" ""`** — eine leere Mechanismenliste — und erst danach `"SASL" "PLAIN LOGIN"`. Die Fähigkeiten werden nach `STARTTLS` vollständig neu gesendet, wie RFC 5804 es verlangt. | Eine Anmeldung im Klartext ist nicht bloß verboten, sie ist **unmöglich**. Der Client verwirft die Liste von vor dem Handshake trotzdem: sie kam von wem auch immer auf der Leitung war. |
| b | **Proxy-Autorisierung gelingt für einen Nutzer, der eine Mailbox hat — auch ohne Zeile in `accountuser`.** Für einen Nutzer, der beides nicht hat, antwortet der Server `NO "Authentication Error"`. | Die Mailbox ist die Voraussetzung, nicht der Datenbankeintrag. Das passt zur Reihenfolge aus Architektur 8.1: **Mailstore zuerst, dann DB**. Ein Anwendungsfall, der es andersherum versuchte, bekäme beim Schreiben des Skripts eine Anmeldefehlermeldung und nicht den wahren Grund. |
| c | **`DELETESCRIPT` auf ein nicht vorhandenes Skript antwortet `NO (NONEXISTENT)`** — hier sendet Cyrus den Antwortcode, anders als bei den IMAP-Kommandos aus U-38 d. | Der Adapter liest den Code, wenn er da ist, und **fragt nach**, wenn nicht. Beide Wege sind abgedeckt; die Prüfung ohne Code steht als Einheitstest gegen einen geskripteten Server. |
| d | **Solange eine timsieved-Sitzung für einen Nutzer offen ist, bricht `DELETE user/x` über IMAP die *IMAP*-Verbindung ab.** Das Löschen wirkt trotzdem; der Client liest anschließend auf einem geschlossenen Socket und meldet „closed the connection during a response line". Reproduzierbar; ohne offene Sieve-Sitzung ist derselbe Löschvorgang unauffällig. | **Wer ein Konto löscht, beendet vorher dessen Sieve-Sitzung.** Für A2 und A6 heißt das: `ManageSieveConnectionPool::closeAll()` (oder das Ende der Anfrage) liegt **vor** dem Löschen der Mailbox. Sonst meldet ein erfolgreicher Löschvorgang einen Verbindungsfehler — und ein Aufrufer, der daraufhin wiederholt, löscht etwas, das schon weg ist. Der Integrationstest hält die Reihenfolge fest. |
| e | **`GETSCRIPT` liefert das Skript als Literal, gefolgt von CRLF vor dem `OK`.** Der Inhalt kommt byteweise unverändert zurück — Umlaute, Anführungszeichen, Zeilenumbrüche und eine mit `.` beginnende Zeile eingeschlossen. Es wird nichts normalisiert. | Die Definition of Done („ein Text mit Umlauten … überlebt den Weg unverändert") ist gegen den Server geprüft, nicht gegen die Attrappe. |
| f | Die Erweiterungsliste enthält unter anderem `vacation`, `vacation-seconds`, `fileinto`, `envelope`, `regex`, `imap4flags`, `date` und `variables`. | Was die Regelmenge heute deklariert, kann der Server. Die Prüfung bleibt trotzdem, weil sie eine *Server*eigenschaft prüft und nicht eine Annahme über Cyrus. |

**Nicht abgedeckt:** ein Server mit `allowstarttls: no` — dort ist der Weg ungeprüft, weil F5 die
Einstellung setzen muss, damit überhaupt etwas läuft (O-14 bleibt offen und ist eine
Voraussetzung an den Zielserver). Ebenso ungeprüft: ein Skript, das an einer Server**quote**
scheitert (`QUOTA/MAXSCRIPTS`) — F5 setzt keine, und eine künstliche Quote wäre eine Prüfung der
Testumgebung. Der Code dafür ist da und steht unter Einheitstest.

---

## U-71 — Das Frontend wird nach `public/` gebaut, mit `MAILADMIN_BASE_PATH` als einzigem Schalter

**Frage:** Nicht gestellt. Die Architektur (11.1, 11.3) sagt „aus `public/assets/` ausgeliefert"
und „Inhalts-Hash im Dateinamen", aber nicht, wie das Bauverzeichnis mit dem Front-Controller
zusammenlebt und wie das Frontend erfährt, dass die Anwendung in einem Unterverzeichnis liegt.

**Entscheidung:**

1. **Vite schreibt direkt nach `public/`** — `index.html` an die Wurzel, alles andere nach
   `public/assets/`. `emptyOutDir` bleibt **aus**, weil in `public/` auch `index.php` und die
   `.htaccess` aus U-25 Lage 3 liegen; ein `--emptyOutDir` würde beide löschen. Stattdessen
   räumt `frontend/scripts/clean-output.mjs` vor jedem Bau genau die erzeugten Pfade weg.
2. **Der Basispfad kommt zur Bauzeit aus `MAILADMIN_BASE_PATH`**, derselben Variablen, die das
   Backend liest. Aus ihr folgen Vites `base`, der `basename` des Routers und der API-Präfix
   (`import.meta.env.BASE_URL` + `api/v1`). Eine Installation stellt damit **einen** Wert ein,
   nicht drei.
3. **Beides ist nicht eingecheckt** (`/public/index.html`, `/public/assets/`), ebenso wenig die
   erzeugten Vertragstypen `frontend/src/api/schema.d.ts`.
4. **Keine Sourcemaps im ausgelieferten Bündel.** Sie sind dreimal so groß wie der Code und
   würden aus `public/` mit ausgeliefert. Der Quelltext ist ohnehin offen (U-23) — gespart wird
   Bandbreite, nicht Geheimnis. `npm run dev` hat volle Sourcemaps.

**Warum:** Ein zweites Ausgabeverzeichnis mit anschließendem Kopierschritt wäre der übliche Weg,
verlangt aber ein Deployment-Skript, das die Kopie nicht vergessen darf. `public/` ist bereits
das einzige öffentlich lesbare Verzeichnis und trägt schon die Cache-Regeln aus Architektur 11.3;
dort direkt hinein zu bauen heißt, dass „gebaut" und „ausgeliefert" dasselbe bedeuten.

Zu `schema.d.ts`: eingecheckt wäre es eine zweite Wahrheit neben dem Vertrag, und genau das
schließt Regel 3 aus `CLAUDE.md` aus. Der Preis ist, dass `npm run typecheck` ein vorheriges
`composer build` braucht; das steht in `docs/deployment/README.md`, und `npm run build` erzeugt
die Typen als `prebuild` selbst.

**Alternative:** `frontend/dist/` als Bauverzeichnis plus Kopierschritt. Sauberer getrennt, aber
ein Schritt mehr, den ein Deployment auslassen kann — und das Ergebnis wäre eine Oberfläche, die
sichtbar veraltet ist, ohne dass irgendetwas fehlschlägt.

**Korrekturkosten:** Gering. Betrifft `vite.config.ts`, ein Skript und zwei `.gitignore`-Zeilen.

---

## U-72 — W1 legt die Mehrsprachigkeitsnaht, setzt aber `react-i18next` nicht ein

**Frage:** W1 darf keine festverdrahteten Texte enthalten (E-16), soll aber W2 nicht
vorwegnehmen. Was genau baut W1 dann?

**Entscheidung:**

1. **Jeder sichtbare Text läuft über einen Schlüssel.** `frontend/src/i18n/de.ts` ist die
   einzige Datei im Frontend, in der ein deutscher Satz steht.
2. **Die Auflösung ist ein Dreizeiler mit der Signatur von `react-i18next`**: `useTranslation()`
   liefert `{ t, language }`, `t('schlüssel', { name: 'wert' })`, Platzhalter als `{{name}}`.
   W2 ersetzt `frontend/src/i18n/index.ts` durch die Einrichtung von `i18next` und **muss keine
   einzige Aufrufstelle anfassen**.
3. **Fehler werden über `code` übersetzt, nicht über `title`.** Schlüsselform `errors.<code>`.
   Ist der Code diesem Build unbekannt, wird `detail` beziehungsweise `title` aus der Antwort
   angezeigt — englisch, aber wahr.
4. **Ein Test hält den Katalog am Vertrag fest**: er liest `var/cache/openapi.bundled.json` und
   meldet jeden `ErrorCode` ohne Text **und** jeden Text ohne Code. Fehlt das Bündel,
   überspringt er sich mit Begründung, statt zu bestehen.
5. **Wer einen `ErrorCode` in den Vertrag einträgt, trägt im selben Schritt den deutschen Text
   in `frontend/src/i18n/de.ts` ein.** Das ist die Regel, die dieser Test durchsetzt.

**Warum:** `react-i18next` jetzt einzurichten hieße, W2s Entscheidungen — Ladeverfahren,
Namensräume, Spracherkennung, Persistenz in `mailadmin_admin_setting` — vorwegzunehmen und
später zurückzunehmen. Nur Schlüssel und Signatur festzulegen kostet fünfzig Zeilen und macht
den Wechsel zu einer Datei.

Der Textrückfall bei unbekanntem Code ist Absicht und steht so in Architektur 11.4: die
Oberfläche bleibt bei einem Backend benutzbar, das neuer ist als sie.

**Alternative:** `react-i18next` sofort. Spart W2 einen Schritt, verlegt aber dessen
Entscheidungen in ein Paket, das sie nicht treffen darf.

**Korrekturkosten:** Gering, und genau dafür ist die Naht da.

---

## U-73 — Gestaltung: eigenes Theme, MUI-`Table` statt `DataGrid`, keine Formularbibliothek

**Frage:** Drei offene Punkte aus dem W1-Paketdokument (3, 4, 5, 8), die zusammengehören, weil
sie alle die Frage „noch eine Abhängigkeit?" stellen.

**Entscheidung:**

| Punkt | Wahl |
|---|---|
| Theme | Ein eigenes `createTheme` mit fester Palette (gedecktes Blaugrau, `error` als einzige laute Farbe) |
| Tabellen | MUIs `Table` mit `TableSortLabel` und `TablePagination` — **kein `DataGrid`**, auch nicht in der MIT-Fassung |
| Formulare | Von Hand, gegen `problem+json`. Keine Formularbibliothek |
| Zugänglichkeit | Tastaturbedienbarkeit und benannte Bedienelemente als Mindestmaß; jede interaktive Fläche hat einen zugänglichen Namen, Fehler stehen in `role="alert"`, Ladezustände in `role="status"` |

**Warum:**

- **Theme:** Ein Verwaltungswerkzeug voller Löschknöpfe verträgt keine dekorative Akzentfarbe,
  die mit `error` um Aufmerksamkeit konkurriert. Die feste Palette kostet dreißig Zeilen und
  macht die Oberfläche gegenüber webcyradm wiedererkennbar.
- **Tabellen:** Sortierung, Filterung und Paginierung kommen laut U-20 vom Server. Damit bleibt
  vom `DataGrid` die Darstellung übrig — und die kann `Table` auch. Die kostenpflichtigen
  Fassungen kommen ohnehin nicht infrage; die MIT-Fassung wäre eine große Abhängigkeit für
  wenig, und der Weg von „MIT reicht" zu „für dieses eine Feature bräuchten wir Pro" ist kurz.
- **Formulare:** Die Validierungsregeln stehen bereits im OpenAPI-Schema und werden am
  Eingangsrand durchgesetzt (U-9). Eine Formularbibliothek würde sie im Client ein zweites Mal
  formulieren — mit der Aussicht, dass beide auseinanderlaufen. Was der Client prüft, sind
  Pflichtfelder; alles Weitere entscheidet der Server und antwortet mit `code` und
  `errors`-Feldpfaden, die direkt auf die Eingabefelder gelegt werden.

**Alternative:** MUI-Standardaussehen, `DataGrid`, `react-hook-form` + `zod`. Schneller zu
reichhaltigen Masken, drei Abhängigkeiten mehr und im Fall von `zod` eine zweite Beschreibung
derselben Regeln.

**Korrekturkosten:** Für Theme und Formulare gering. Für die Tabellenentscheidung mittel, aber
erst, wenn Listenansichten existieren — heute gibt es keine.

---

## U-74 — Bei `401`: eine gebündelte Erneuerung, ein Wiederholungsversuch, danach die Anmeldung

**Frage:** Die offenen Punkte 6 und 7 des Pakets: Wie werden parallele `401` behandelt, und was
passiert mit einem halb ausgefüllten Formular, wenn die Sitzung mittendrin abläuft?

**Entscheidung:**

1. **Eine Erneuerung, wie viele `401` auch eintreffen.** Der Client hält die laufende
   `POST /auth/refresh`-Zusage in einem Feld; jeder weitere Aufrufer wartet auf dieselbe.
2. **Genau ein Wiederholungsversuch.** Antwortet die wiederholte Anfrage erneut mit `401`, wird
   nicht ein zweites Mal erneuert.
3. **`/auth/token` und `/auth/refresh` lösen nie eine Erneuerung aus.** Ein falsches Passwort
   ist keine abgelaufene Sitzung.
4. **Der Verlust der Sitzung wird einmal gemeldet, nicht einmal je offener Anfrage.** Das Token
   wird gelöscht, und weil `RequireSession` am Token hängt, erscheint die Anmeldung beim
   nächsten Rendern — ohne imperativen Sprung mitten im Rendern.
5. **Eingaben bleiben stehen.** Beim Ablauf mitten im Formular verliert der Nutzer nur das
   Passwortfeld der Anmeldemaske; ein ausgefülltes Fachformular wird nicht verworfen und nicht
   in einen Zwischenspeicher gerettet.

**Warum die Bündelung nicht nur Ordnungsliebe ist:** Jedes Erneuerungstoken gilt **genau
einmal** (S2, Architektur 5.4). Zwei gleichzeitige Einlösungen desselben Tokens sind für den
Server nicht von einem **wiederverwendeten** Token zu unterscheiden — er widerruft dann die ganze
Familie und meldet `auth.refresh_reused`. Ohne Bündelung würde ein gewöhnlicher Tokenablauf bei
mehreren offenen Abfragen die Diebstahlerkennung auslösen und den Nutzer hinauswerfen. Die
Bündelung ist damit **Voraussetzung dafür, dass S2s Rotation im Alltag funktioniert**, nicht eine
Optimierung.

Zu Punkt 5: Eingaben in einen Zwischenspeicher zu retten und nach erneuter Anmeldung
wiederherzustellen hieße, Formularinhalte irgendwo abzulegen — und die enthalten in diesem
Werkzeug regelmäßig Passwörter. Nichts zu tun ist hier die sicherere und die ehrlichere Wahl.

**Alternative:** Vorausschauend erneuern, sobald das Token bald abläuft. Vermeidet den `401`
ganz, verlangt aber einen Zeitgeber und läuft bei einem schlafenden Laptop trotzdem in den
Reaktionsfall. Kann später zusätzlich kommen; die Bündelung bleibt in jedem Fall nötig.

**Korrekturkosten:** Gering, eine Klasse.

---

## U-75 — Die Oberfläche wird in jsdom geprüft, nicht in einem Browser

**Frage:** Das Paket nennt einen End-to-End-Testfall („anmelden, navigieren, abmelden; danach ist
kein Token mehr gespeichert"). Playwright oder Cypress dafür aufnehmen?

**Entscheidung:** **Nein.** Der Ablauf wird mit Vitest und Testing Library in jsdom gegen die
**vollständige Anwendung** geprüft: echte Providerkette, echte Routentabelle, echter API-Client,
echter Tokenspeicher. Ersetzt ist allein das Netz — durch eine `fetch`-Attrappe, die aufzeichnet,
was gefragt wurde.

**Warum:** Was der Testfall nachweisen soll, ist Anwendungsverhalten, nicht Browserverhalten:
dass das Token nach dem Abmelden verschwunden ist, dass parallele `401` eine Erneuerung erzeugen,
dass ein Feldfehler auf dem richtigen Feld landet. Das sieht jsdom vollständig. Ein Browsertreiber
brächte dafür eine große Abhängigkeit, einen Browserdownload in jeder Umgebung und einen
laufenden Server je Lauf — und wäre danach der langsamste und der wackeligste Teil der Suite.

**Was damit nachweislich nicht geprüft ist**, und deshalb hier steht statt in einer Fußnote:

- dass der Browser die Content-Security-Policy tatsächlich so anwendet, wie sie gemeint ist,
- dass das `HttpOnly`-Cookie mit `SameSite=Strict` und dem verengten Pfad wirklich nur an
  `/auth/*` geht,
- Layout, Fokusreihenfolge und Bildschirmleserverhalten.

Der erste Punkt ist teilweise abgedeckt: `frontend/scripts/check-build.mjs` bricht den Bau ab,
wenn die Shell ein Inline-Skript, einen fremden Ursprung oder eine Datei ohne Inhalts-Hash
enthält, und `bin/check-deployment.php` prüft an einer **laufenden** Installation nach, dass die
Header ankommen. Der zweite gehört zu S2 und ist dort gegen einen echten Server geprüft. Der
dritte bleibt offen und ist ein Fall für einen späteren Durchgang, nicht für ein Werkzeug, das
niemand pflegt.

**Alternative:** Playwright, sobald die fachlichen Ansichten stehen und es mehr zu klicken gibt
als eine Anmeldemaske. Dann ist die Abwägung eine andere; heute wäre es Werkzeug ohne Anlass
(E-12).

**Korrekturkosten:** Keine. Ein Browsertreiber lässt sich jederzeit danebenstellen; nichts an
der Anwendung müsste sich dafür ändern.

---

## U-76 — Wie A1 protokolliert, und was eine abgebrochene Umbenennung im Protokoll ist

**Frage:** A1s letzter offener Punkt der Definition of Done — „jede Änderung erzeugt einen
Audit-Eintrag". Für Anlegen, Ändern und Löschen schreibt U-63/U-64 den Aufruf vor. Offen war die
Umbenennung: Sie reicht über Cyrus und die Datenbank, kann auf halbem Weg stehen bleiben und wird
dann kompensiert — mit oder ohne Erfolg. Welches Ergebnis trägt ihr Eintrag, wann wird er
geschrieben, und was geschieht, wenn der Eintrag selbst nicht geschrieben werden kann?

**Entscheidung:**

| Vorgang | Einträge | Wann |
|---|---|---|
| Anlegen | `domain.create`, `after` = Darstellung wie bei `GET` | in der Transaktion des `INSERT` |
| Ändern | `domain.update`, `before` und `after` | in der Transaktion des `UPDATE` |
| Löschen | `domain.delete`, `before` | in der Transaktion des `DELETE` |
| Umbenennen, Erfolg | ein `domain.rename` für die Domain, dazu je Konto ein `domain.rename` mit Objekttyp `account`, über `part_of` am ersten | in der Transaktion des Datenbankschritts |
| Umbenennen, abgebrochen | **ein** `domain.rename` mit Ergebnis **`failed`** | **nach** der Kompensation |

Ein `PATCH`, der umbenennt **und** Attribute ändert, erzeugt beides: `domain.rename` aus
`RenameDomain` und `domain.update` aus `UpdateDomain` — je Anwendungsfall ein Aufruf (U-64).
Gelingt die Umbenennung und scheitert danach das Attribut-Update, steht genau die Umbenennung im
Protokoll, und genau das sagt dann auch die Datenbank.

**Eine abgebrochene Umbenennung ist `failed`, nicht ein Zwischenzustand.** Der Eintrag hält fest,
was die Datenbank am Ende sagt (U-63), und die sagt nach jedem Abbruch den alten Namen. Der Grund
nennt die Stufe (`mailstore: <code>` oder `database: write failed`) und ob die Postfächer
zurückgekommen sind. Ein Postfach, das nicht zurückbenannt werden konnte, steht in der
Reparaturwarteschlange; der Eintrag **verweist** darauf („see repair queue") und wiederholt es
nicht in einer zweiten Zeile — „nie um den Mailstore herum" (U-63). Kindeinträge je Konto gibt es
im Fehlerfall nicht: für kein Konto hat sich in der Datenbank etwas geändert.

**Der Erfolgseintrag liegt in der Transaktion, und sein Scheitern nimmt die Postfächer mit
zurück.** `DomainStore::atomically()` umschließt `rename()` und die Audit-Aufrufe; die
Repository-Methode tritt der äußeren Transaktion bei (die Verbindung zählt die Tiefe). Scheitert
der Audit-Schreibvorgang, rollt die Datenbankhälfte zurück und der Anwendungsfall läuft in
denselben Ausgleich wie bei jedem anderen Fehler des Datenbankschritts — die Postfächer werden
zurückbenannt. Das ist die Lesart von U-63 „lässt sich der Eintrag nicht schreiben, bleibt die
Änderung nicht bestehen", konsequent bis in Cyrus.

**Der Fehlschlagseintrag ist die dritte Stelle, die einen Schreibfehler selbst fängt** (neben
`SessionAudit` und `AuditMiddleware`, U-63). Zu diesem Zeitpunkt ist der Vorgang schon
gescheitert und nichts mehr zurückzurollen. Ließe man den Fehler durch, ersetzte eine defekte
Audit-Tabelle die Antwort `domain.rename_incomplete` — die einzige, die sagt, ob die Postfächer
zurückgekommen sind — durch ein nacktes `500`. Der Fehler wird im Anwendungslog auf `error`
festgehalten.

**Nicht protokolliert** werden Ablehnungen, bevor etwas geschah: `409` für einen belegten Namen,
eine nicht leere Domain oder ein nicht ableitbares Konto, `412`/`428` beim `If-Match`, `422` ohne
Bestätigung, `503` bei unerreichbarem Mailstore. Das entspricht A4, das seine `409` ebenso
behandelt; eine Vorbedingung, die der Client an der Ressource ablesen kann, ist keine Ablehnung
des Akteurs. Ablehnungen wegen fehlender Berechtigung (`403`) schreibt ohnehin die Middleware.

**Nebenbei korrigiert:** `domain.rename_incomplete` nach einem Datenbankfehler trug bisher die
Meldung der Datenbank in `detail`. Die steht jetzt nur noch im Log; der Client liest „The database
update failed." (CLAUDE.md: keine Ausnahmemeldungen an den Client).

**Warum:** Jede der Festlegungen folgt aus U-61 bis U-64; neu entschieden ist nur, wie eine
Operation über zwei Systeme auf diese Regeln abgebildet wird. Der Maßstab ist derselbe wie bei
der Reihenfolge (Architektur 8.1): das Protokoll darf nie eine Umbenennung behaupten, die die
Datenbank nicht zeigt, und es darf einen Abbruch nicht verschweigen.

**Alternative:** Ein viertes Ergebnis `partial` für einen Abbruch mit unvollständigem Ausgleich.
Verworfen: es verdoppelt, was die Reparaturwarteschlange bereits präzise festhält, und brächte
S4s Aufzählung um einen Wert, den kein anderer Vorgang braucht. Ebenso verworfen: den
Fehlschlagseintrag vor der Kompensation schreiben — dann wüsste er nicht, ob die Postfächer
zurückkamen.

**Korrekturkosten:** Gering. Alles liegt in `RenameDomain` und den drei übrigen
Anwendungsfällen; geschriebene Einträge bleiben lesbar, auch wenn sich die Form neuer ändert.

---

## U-77 — Der Kontoname ist immer frei gewählt und wird kleingeschrieben

**Frage:** A2, Punkt 1. Bei `freenames = NO` zählte das Altsystem hoch (`prefix` plus vierstellige
Nummer) und leitete die nächste Nummer aus dem letzten Eintrag ab. Übernehmen?

**Entscheidung:** Nein. Jede Anlage verlangt einen Local Part (`local_part`), unabhängig von
`freenames`; das Feld bleibt Kompatibilitätsfeld ohne Wirkung (U-47). Der Local Part wird vor der
Prüfung **kleingeschrieben** gespeichert. Der Cyrus-Benutzername entsteht daraus über
`MailboxIdentity::create()` mit dem wirksamen Namensschema der Domain (E-14) — nie anders.

**Warum:** Der Zähler war anfällig für Lücken und Wettläufe (zwei Anlagen lesen denselben letzten
Eintrag) und erzeugt Namen, die niemand als Adresse will. Cyrus faltet Benutzernamen bei der
Anmeldung in Kleinbuchstaben (`username_tolower`); zwei Zeilen, die sich nur in der Schreibweise
unterscheiden, wären ein Postfach mit zwei Passwörtern. Die Belegtprüfung vergleicht deshalb
ohne Rücksicht auf Groß- und Kleinschreibung.

**Alternative:** Den Zähler als Vorschlag im Formular anbieten. Möglich, ohne den Vertrag zu
ändern — es ist eine Frage der Oberfläche (W2), nicht der API.

**Korrekturkosten:** Gering. Ein optionaler Zählvorschlag ist ein Lesezugriff ohne
Vertragsänderung an `createAccount`.

---

## U-78 — Konten werden nicht deaktiviert; die Dienstschalter werden getragen, nicht durchgesetzt

> **Ersetzt durch U-127.** Der Projekteigner hat die Viewänderung an `allusers` freigegeben;
> Konten lassen sich seitdem sperren. Was hier über die Dienstschalter steht, gilt weiter: sie
> werden getragen, nicht durchgesetzt.

**Frage:** A2, Punkt 2. Deaktivieren statt Löschen — über ein additives Statusfeld oder über
`virtual.status`?

**Entscheidung:** **Keine Deaktivierung in A2. Eskaliert.** Die Spalten `imap`, `pop`, `sieve`
und `smtpauth` werden gelesen, angezeigt und über `PATCH /accounts/{account}` geändert — mit dem
ausdrücklichen Hinweis im Vertrag, dass sie **nichts sperren**.

**Warum:** Auf der Zielinstallation prüft keine Komponente einen Kontostatus:

- `saslauthd`/`pam_mysql` authentifiziert gegen den View `allusers`, der `accountuser` ohne
  jede Statusspalte liest, und der Account-Schritt der PAM-Kette ist `pam_permit`. Weder ein
  neues Feld noch die vier Dienstschalter erreichen die Anmeldung.
- `virtual.status = 0` nimmt dem Konto nur die Zustellung und den Eintrag in `validsenders` —
  die Anmeldung bleibt möglich, das Postfach läuft weiter voll.

Eine wirksame Sperre bräuchte entweder eine Änderung am View `allusers` (CLAUDE.md, Regel 1:
Eskalationsfall) oder ein Verstümmeln des gespeicherten Hashes (Eingriff ins Passwortverfahren
von `accountuser`: ebenfalls Eskalationsfall). Beides entscheidet dieses Paket nicht. Ein Schalter
in der Oberfläche, der „gesperrt" sagt und nichts sperrt, wäre schlimmer als keiner.

**Alternative:** (a) Additive Spalte `mailadmin_account_setting.disabled` plus ein angepasster
View `allusers`, der sie auswertet — sauber, aber eine Viewänderung, die Salt mitziehen muss.
(b) Hash-Präfix (`!`) wie bei `/etc/shadow` — wirksam ohne Viewänderung, aber ein Eingriff in
das, was `crypt(3)` liest. Beides ist eine Entscheidung für den Projekteigner.

**Korrekturkosten:** Mittel, und zwar außerhalb dieser Anwendung: View oder PAM-Konfiguration
liegen bei Salt. In mailadmin ist es ein Anwendungsfall und ein Feld im Vertrag.

---

## U-79 — Quota: `null` ist unbegrenzt, `0` wird abgelehnt

**Frage:** A2, Punkt 3 (aus M2): Was bedeutet eine Quota von `0`?

**Entscheidung:** In der API ist `null` **unbegrenzt**; das Minimum ist `1` KiB. `0` lehnt der
Vertrag ab (`422 request.validation_failed`). Bei der Anlage gilt: `quota_kib` fehlt → die
Kontovorgabe der Domain; `quota_kib: null` → unbegrenzt; eine Zahl → genau diese. Die Quota ist
eine eigene Ressource (`GET/PUT /accounts/{account}/quota`) und wird live aus Cyrus gelesen; die
Kontenliste trägt sie nicht (Architektur 7.2).

**Warum:** In Cyrus ist ein Limit von `0` ein echtes Nulllimit — jede Mail wird abgewiesen. In
der Altspalte `domain.quota` hieß `0` dagegen „unbegrenzt" (U-48). Eine Zahl mit zwei
gegenteiligen Bedeutungen gehört nicht in einen neuen Vertrag. Wer ein Postfach wirklich
schließen will, braucht eine Sperre (U-78, heute U-127), keine Quota.

**Alternative:** `0` als unbegrenzt deuten, wie die Domainspalte. Verworfen: Cyrus-Kundige
würden es umgekehrt lesen.

**Korrekturkosten:** Gering. Eine Schemaregel und ein Zweig in `AccountDraft`.

---

## U-80 — Standardordner bei jeder Anlage, abonniert über Proxy-Auth; ein Fehlschlag kippt die Anlage nicht

**Frage:** A2, Punkt 4. Standardordner bei jeder Anlage anlegen, und auch abonnieren?

**Entscheidung:** Ja, beides, aus `domain.folders`. Angelegt und abonniert wird **nach** dem
Datenbankschritt im Namen des Nutzers über SASL-Proxy-Auth (`authzid`), nie mit seinem Passwort
(CLAUDE.md). Scheitert der Schritt, bleibt das Konto bestehen: die Antwort ist `201` mit
`incomplete_steps: ["default_folders"]`, und die Reparaturwarteschlange bekommt einen Eintrag
`mailstore.default_folders`. Eine spätere Änderung von `domain.folders` wirkt nur auf neue Konten
(U-47).

**Warum:** Ordner sind Komfort; das Konto funktioniert ohne sie, und jeder Mailclient legt `Sent`
bei Bedarf selbst an. Das Konto deswegen wieder abzubauen, hieße eine funktionierende Anlage zu
verwerfen — und erneut zwei Systeme anzufassen.

**Alternative:** Ordner vor dem Datenbankschritt anlegen und einen Fehlschlag kompensieren.
Verworfen: macht den häufigsten Nebenschauplatz zum Abbruchgrund.

**Korrekturkosten:** Gering. Die Stelle ist ein Schritt in `CreateAccount`.

---

## U-81 — Kontingente: `maxaccounts` doppelt geprüft unter einer Domainsperre, `domainquota` beim Anlegen und Erhöhen

**Frage:** A2, Punkt 5. Wo werden `maxaccounts` und `domainquota` geprüft, und was geschieht bei
gleichzeitigen Anlagen?

**Entscheidung:**

- **`maxaccounts`** (U-48) und die Belegtprüfung laufen **zweimal**: vor dem Mailstore, damit die
  gewöhnliche Ablehnung kein System anfasst, und noch einmal im Datenbankschritt unter einer
  Sperre je Domain (`GET_LOCK('mailadmin.account.<sha1>')`, zehn Sekunden). Wer zwischen beiden
  Prüfungen den letzten Platz oder den Namen genommen hat, bekommt dieselbe Ablehnung
  (`409 account.limit_reached` / `account.name_taken`), und das schon angelegte Postfach wird
  wieder entfernt.
- **`domainquota`** wird beim **Anlegen** und beim **Erhöhen** einer Kontoquota geprüft: die
  Limits aller übrigen Konten der Domain aus Cyrus (über eine Verbindung), plus das neue, gegen
  das Kontingent. Ein unbegrenztes Konto zählt mit `0` (und erzeugt eine Logzeile), ein Konto ohne
  Postfach ebenso. Eine unbegrenzte Quota ist in einer Domain mit Kontingent nicht zulässig.
  **Senken** ist immer erlaubt.
- Der Wettlauf zweier gleichzeitiger **Erhöhungen** in derselben Domain wird hingenommen.

**Warum:** Eine Datenbanktransaktion schützt den Zähler nicht — die Altabellen sind MyISAM
(O-11), und selbst unter InnoDB sähe `REPEATABLE READ` die fremde Zeile nicht. `GET_LOCK` wirkt
unabhängig von der Engine. Die Sperre umschließt nur den kurzen Datenbankschritt, nicht die
Mailstore-Runden. Die Quota-Erhöhung ist selten, wird von Administratoren ausgelöst und lässt
sich in Cyrus jederzeit korrigieren; eine Sperre über mehrere IMAP-Runden wäre teurer als der
Schaden.

**Alternative:** `SELECT … FOR UPDATE` auf der Domainzeile. Wirkungslos unter MyISAM.

**Korrekturkosten:** Gering. Die Sperre steckt in `AccountStore::withDomainLock()`.

---

## U-82 — Kein Umbenennen und kein Schemawechsel eines Kontos in A2

**Frage:** A2, Punkt 6. Ein Konto umbenennen?

**Entscheidung:** Nein, nicht in A2. Das Konto ist über seinen Cyrus-Benutzernamen adressiert
(U-18); `PATCH /accounts/{account}` ändert nur die Dienstschalter.

**Warum:** Technisch ist es der Schemawechsel (CLAUDE.md, Tabelle der Reihenfolgen):
`renamemailbox`, dann die Datenbank, und der Nutzer muss seine Zugangsdaten ändern — nie
automatisch auslösen. Das ist eine eigene, bestätigte Operation wie die Domainumbenennung (U-49),
nicht ein Feld in einem `PATCH`, und das Altsystem bot sie nie an.

**Alternative:** `POST /accounts/{account}/rename` mit Bestätigung. Bleibt möglich, additiv.

**Korrekturkosten:** Mittel, aber ohne Bruch: ein neuer Endpunkt, ein neuer Anwendungsfall nach
dem Muster von `RenameDomain`.

---

## U-83 — Löschen verlangt `If-Match`, räumt `virtual` mit ab und bietet kein Rückholen

**Frage:** A2, Punkt 7. Vor dem Löschen warnen, Größe zeigen, verzögertes Löschen anbieten?

**Entscheidung:**

- `DELETE /accounts/{account}` verlangt `If-Match` (`428` ohne, `412` bei veraltetem Stand) —
  wie bei Domains (U-49). Die Größe liest der Client vorher aus `GET …/quota`; die Warnung ist
  Sache der Oberfläche.
- Reihenfolge: **Datenbank zuerst** (`virtual`-Zeilen des Kontos, wie webcyradm: Kontoadresse,
  Aliase mit diesem Ziel-Benutzernamen, eine Weiterleitungszeile unter dem Benutzernamen; dann
  `accountuser`), danach das Postfach. Der Audit-Eintrag nennt die Zahl der entfernten
  `virtual`-Zeilen.
- Scheitert das Entfernen des Postfachs, ist die Antwort trotzdem `204`, und die
  Reparaturwarteschlange bekommt `mailstore.delete_mailbox` — nach dem Datenbankschritt fließt
  keine Mail mehr, das Postfach ist nur noch Speicher.
- **Kein Rückholen.** Ob Cyrus verzögert löscht, ist Serverkonfiguration (`delete_mode`); die
  Anwendung verspricht nichts, was sie nicht prüfen kann.

**Warum:** Die Reihenfolge ist die aus Architektur 8.1. Eine Ablehnung nach dem Datenbankschritt
hätte den Client glauben lassen, das Konto bestehe noch.

**Alternative:** Ein Papierkorb-Zustand mit Frist. Braucht die Sperre aus U-78 (heute U-127).

**Korrekturkosten:** Gering.

---

## U-84 — `accountuser.prefix` wird aus `domain.prefix` übernommen

**Frage:** A2, Punkt 8.

**Entscheidung:** Bei der Anlage wird `domain.prefix` unverändert in `accountuser.prefix`
geschrieben, so wie webcyradm es tat. Die Anwendung liest das Feld nur zur Anzeige
(`legacy_prefix`) und deutet es nie.

**Warum:** Kompatibilität mit dem Parallelbetrieb; keine Komponente außer webcyradm liest es.

**Alternative:** Leer lassen. Würde webcyradm-Listen verfälschen, die nach Präfix filtern.

**Korrekturkosten:** Keine nennenswerten.

---

## U-85 — Reparaturwarteschlange nur für das, was übrig bleibt; Benutzernamen höchstens 50 Zeichen

**Frage:** Die Definition of Done verlangt: „Ein Fehler nach der Mailbox-Anlage hinterlässt keinen
Datenbankeintrag und einen Eintrag in der Reparaturwarteschlange." Immer einen Eintrag? Und:
welche Länge darf ein neuer Cyrus-Benutzername haben?

**Entscheidung:**

- **Ein Eintrag entsteht nur, wenn die Kompensation scheitert.** Gelingt das Entfernen des
  Postfachs, gibt es nichts zu reparieren; der Vorgang steht als `failed` im Audit-Protokoll
  (U-76) und im Log. Scheitert es, bleibt ein verwaistes Postfach, und genau das kommt in die
  Warteschlange (`mailstore.delete_mailbox`). Die DoD-Zeile wird so gelesen: kein
  Datenbankeintrag, und was übrig bleibt, steht in der Warteschlange.
- **Liegengebliebene Zeilen zuerst.** Weil die Altabellen nicht zurückrollen (O-11), löscht der
  Ausgleich nach einem Fehler im Datenbankschritt zuerst etwaige Zeilen dieses Kontos und erst
  dann das Postfach. Scheitert das Löschen der Zeilen, bleibt das Postfach stehen
  (`database.remove_rows` in der Warteschlange) — ein Postfach unter einer Zeile ist harmlos, eine
  Zeile ohne Postfach erzeugt Bounces.
- **Höchstens 50 Zeichen** für den Cyrus-Benutzernamen eines neuen Kontos
  (`422 account.username_too_long`), weil `virtual.username` `VARCHAR(50)` ist und bleibt
  (CLAUDE.md, Regel 1). Bestandskonten mit längeren Namen werden angezeigt.
- **Quota-Änderung und Protokoll:** Cyrus kennt keine Transaktion. Der Audit-Eintrag wird nach
  dem `SETQUOTA` geschrieben; scheitert er, wird das alte Limit zurückgesetzt, und erst wenn auch
  das scheitert, entsteht `mailstore.restore_quota`.

**Warum:** Eine Warteschlange, die auch erledigte Vorgänge enthält, wird nicht gelesen. A6 soll
dort Arbeit finden, keine Chronik.

**Alternative:** Jeden Abbruch eintragen und als „erledigt" markieren. Verdoppelt das
Audit-Protokoll.

**Korrekturkosten:** Gering; die Einträge entstehen an drei benannten Stellen.

---

## U-86 — A2 nach Review: Audit ohne Rollback, `CREATE` ohne Antwort, erstes Limit, Punktnamen

**Frage:** Vier Befunde aus dem Review von A2. (a) Die Altabellen sind MyISAM (O-11) — was gilt,
wenn der Audit-Eintrag nach dem `DELETE`/`UPDATE` scheitert? (b) Was, wenn `CREATE` ohne
eindeutige Antwort endet (Zeitüberschreitung, Verbindungsabbruch)? (c) Ist das erste Limit auf
einem unbegrenzten Konto eine Erhöhung? (d) Darf ein Domainmaster im Punktschema einen
Benutzernamen anlegen, der in den Namensraum einer Subdomain fällt?

**Entscheidung:**

- **(a) Löschen:** Scheitert der Datenbankschritt, fragt der Anwendungsfall nach, ob die Zeilen
  noch da sind. Sind sie da (InnoDB hat zurückgerollt, oder das Löschen selbst scheiterte vor
  `accountuser`), geht der Fehler an den Client, und das Postfach bleibt unberührt — eine
  Wiederholung bringt es zu Ende. Sind sie weg, **steht das Löschen**: das Postfach wird wie
  sonst entfernt, die Antwort ist `204` (U-83: eine Ablehnung ließe den Client glauben, das Konto
  bestehe noch), und der fehlende Audit-Eintrag wird als `audit.record` in die
  Reparaturwarteschlange geschrieben. Ein Datenbankfehler bei der Nachfrage gilt als „nicht
  sicher weg"; dann wird kein Postfach gelöscht.
- **(a) Dienstschalter:** Scheitert der Audit-Eintrag, werden die alten Schalter zurückgeschrieben,
  bevor der Fehler an den Client geht — dieselbe Regel wie für die Quota (U-85): keine Änderung
  ohne ihren Eintrag.
- **(a) Passwort: bewusst offen gelassen.** Unter MyISAM bleibt der neue Hash bei einem
  gescheiterten Audit-Eintrag stehen, der Client sieht einen Fehler; eine Wiederholung setzt
  dasselbe Passwort erneut und wird protokolliert. Zurücksetzen hieße, den alten Hash zu lesen —
  der Store gibt aber keine Hashes heraus (A2, Artefakte), und diese Linie ist mehr wert als das
  Schließen der Lücke. A1 und A4 haben sie für ihre Einzeltabellenänderungen ebenso; sie
  verschwindet mit der Umstellung auf InnoDB (O-11, Weg 1).
- **(b)** Nur `NO`/`BAD`, ein bereits vorhandenes Postfach und Fehler vor jeder Verbindung
  (Anmeldung, TLS, Konfiguration, Namensraum) gelten als eindeutig. Bei allem anderen
  (`mailstore.timeout`, `mailstore.unavailable`, `mailstore.protocol_error`) prüft der
  Anwendungsfall mit `mailboxExists()`, ob das Postfach jetzt da ist. Ist es da, oder scheitert
  auch die Probe, entsteht ein Eintrag `mailstore.create_mailbox_uncertain`. Das Postfach wird
  **nicht** entfernt: ob es von dieser Anfrage stammt oder schon vorher bestand, ist nicht zu
  unterscheiden, und ein fremdes Postfach mit Mail auf Verdacht zu löschen ist der schlimmere
  Fehler. Die Antwort bleibt `503 account.mailstore_unavailable`.
- **(c) Ja.** Maßstab ist die Wirkung auf die Summe der Domain, nicht der Zahlenwert: ein Konto
  ohne Limit zählt dort mit `0` (U-81), ein erstes Limit erhöht die Summe um seinen Betrag und
  wird geprüft. Frei ist nur das Senken eines **bestehenden** Limits.
- **(d) Nein.** Im Punktschema erhalten `info.kunde@example.de` und `info@kunde.example.de`
  denselben Cyrus-Benutzernamen `info.kunde.example.de` (Architektur 3.3). Enthält der Local Part
  Punkte und ist einer seiner Endabschnitte zusammen mit der Domain ein bestehender Domainname
  (`kunde.example.de`), lehnt die Anlage mit `409 account.name_taken` ab — dasselbe, was das
  Konto der Subdomain bei umgekehrter Reihenfolge träfe. Im `fqun`-Schema gibt es die
  Mehrdeutigkeit nicht; dort wird nichts geprüft. Kein neuer Fehlercode, keine Vertragsänderung.

**Warum:** (a) und (b) folgen der Regel aus CLAUDE.md, „so scheitern, dass niemand Mail verliert",
und U-85: was übrig bleibt, gehört in die Warteschlange — auch ein fehlender Audit-Eintrag und ein
Postfach, dessen Herkunft niemand kennt. (c) schließt eine Lücke, durch die gerade die
Altkonten ohne Limit die Kontingentprüfung umgingen. (d) verhindert, dass ein Domainmaster
Anmeldenamen im Namensraum einer Subdomain belegt, die ein anderer Domainmaster verwaltet.

**Alternative:** (a) Den Audit-Eintrag vorab als Absicht schreiben und das Ergebnis nachtragen —
verworfen, weil ein Absichtseintrag eine Änderung behaupten kann, die nie stattfand (der Maßstab
aus U-76). (b) Das Postfach nach einem unklaren `CREATE` immer entfernen — verworfen, siehe oben.
(d) Die Mehrdeutigkeit hinnehmen und für delegierte Subdomains `fqun` empfehlen — bleibt als
Betriebshinweis richtig, schützt aber nicht vor dem Fall.

**Korrekturkosten:** Gering. Alles steckt in `DeleteAccount`, `UpdateAccount`,
`CreateAccount::createMailbox()`/`requireUnambiguous()` und `SetAccountQuota::raises()`.

---

## U-87 — Keine Übersetzungsbibliothek: die W1-Naht wird ausgebaut, nicht ersetzt

**Frage:** W2, Punkte 1 und 3. U-72 hat `react-i18next` offengelassen und die Signatur so
gewählt, dass W2 es einsetzen könnte. Einsetzen oder die eigene Naht ausbauen? Und braucht es
Pluralformen und Interpolation?

**Entscheidung:** **Keine Bibliothek.** `frontend/src/i18n/index.ts` bleibt die eigene Auflösung
und lernt, was gebraucht wird:

1. **Interpolation** wie bisher, `{{name}}`. Zahlen werden beim Einsetzen mit
   `Intl.NumberFormat` der Sprache formatiert (`2.048 Domains`); wer eine Zahl unformatiert will,
   übergibt eine Zeichenkette.
2. **Pluralformen ja**, über `Intl.PluralRules`: `t('overview.scopeCount', { count })` wählt
   `overview.scopeCount#one` oder `#other` nach den CLDR-Regeln der Sprache; fehlt eine
   Kategorie, gilt `#other`.
3. **Formate** über `Intl` in `createFormatters()`: Datum mit Uhrzeit und Zonenkürzel, Datum,
   Zahl, Größen in binären Stufen (`B`, `KiB`, `MiB`, `GiB`, `TiB`; Einheitenmuster aus der
   Sprachdatei) und `kibibytes()` für die KiB-Werte von Cyrus und `domain.quota`.
4. Die Aufrufsignatur `useTranslation()` → `{ t, language }` bleibt; keine Aufrufstelle musste
   sich ändern. `TranslationKey` ist so getypt, dass Pluralformen nur über ihren Grundschlüssel
   aufrufbar sind.

**Warum:** Was `react-i18next` darüber hinaus bietet — Laden zur Laufzeit, Namensräume,
Erkennungs-Plugins, Kontextformen —, braucht ein Werkzeug mit einer ausgelieferten Sprache und
einer Handvoll Ansichten nicht. Die Plattform liefert die schwierigen Teile (`Intl.PluralRules`
kennt die CLDR-Regeln aller Sprachen), der Rest sind vierzig Zeilen. Eine neue npm-Abhängigkeit
wäre außerdem ein Eskalationsfall (E-12, Detailkonzept Abschnitt 5) — die schlanke Lösung
vermeidet ihn, ohne etwas zu verlieren.

**Alternative:** `i18next` + `react-i18next`. Bringt Pluralformen und Interpolation fertig mit,
dazu etwa 40 KB und einen Satz Konfiguration, der für eine Sprache leer bliebe. Bleibt möglich:
die Schlüssel sind flach, die Signatur ist kompatibel, nur das Pluraltrennzeichen (`#` statt `_`,
U-88) müsste umgeschrieben werden.

**Korrekturkosten:** Gering. Eine Datei; die Sprachdateien lassen sich mechanisch umschreiben.

---

## U-88 — Schlüsselkonvention: `<bereich>.<ansicht>.<element>`, geprüft statt beschrieben

**Frage:** W2, Punkt 2. Die Konvention muss stehen, bevor die ersten Fachansichten entstehen.

**Entscheidung:**

- **Form `<bereich>.<ansicht>.<element>[.<detail>]`**, jedes Segment `lowerCamelCase`, flach in
  der Sprachdatei: `account.list.column.quota`, `domain.detail.action.rename`.
- **Bereiche sind festgelegt** in `frontend/src/i18n/keyConvention.ts`:
  - Ressourcenbereiche im Singular, wie die Berechtigungsfamilien: `domain`, `account`,
    `address`, `admin`, `list`, `audit`, `maintenance`. Sie nennen **immer** die Ansicht
    (mindestens drei Segmente) — `account.title` wäre mehrdeutig, sobald Liste und Detail
    existieren.
  - Rahmenbereiche: `app`, `nav`, `signIn`, `signOut`, `overview`, `preferences`, `state`,
    `role`, `format`, `action`. Sie dürfen eine Ebene flacher sein (`signIn.title`), weil sie
    selbst die Ansicht sind. `action` ist für Knöpfe, die überall gleich heißen
    (`action.save`, `action.cancel`).
  - Ein neuer Bereich wird dort eingetragen; ein unbekannter bricht die Prüfung.
- **Pluralformen** als `<schlüssel>#<kategorie>` mit den CLDR-Kategorien (`zero`, `one`, `two`,
  `few`, `many`, `other`). `#other` ist Pflicht, ein Grundschlüssel daneben verboten.
- **Fehlertexte** folgen dem Vertrag, nicht dieser Form: `errors.<code>` mit dem Code aus U-7
  (`snake_case`), dazu `errors.client.*` und `errors.field.<Validator-Schlüsselwort>`.
- **Schlüssel stehen wörtlich im Code**, auch in Zuordnungstabellen (`ROLE_KEYS`), nie
  zusammengesetzt. Nur so findet die Prüfung auf ungenutzte Schlüssel (U-89) ihre Verwendung;
  `errors.*` ist ausgenommen, weil diese Schlüssel per Konstruktion aus `code` entstehen.

**Warum:** Eine beschriebene Konvention hält bis zur ersten Ansicht unter Zeitdruck. Als Prüfung
im Testlauf kostet sie nichts und gilt für jedes spätere Paket gleich. Das `#` statt des
i18next-üblichen `_` hat einen Grund: Fehlercodes enthalten Unterstriche, und ein Code wie
`…_many` wäre von einer Pluralform nicht zu unterscheiden.

Die W1-Schlüssel (`signIn.title`, `overview.greeting`, `state.error.title`) passen bereits und
bleiben, wie sie sind.

**Alternative:** Keine feste Bereichsliste, nur die Form. Billiger, aber dann entstehen
`accounts.…` neben `account.…` — genau die Umbenennung, die später teuer wird.

**Korrekturkosten:** Gering, solange es wenige Ansichten gibt; danach wächst jede Umbenennung mit
der Zahl der Aufrufstellen. Deshalb jetzt.

---

## U-89 — Fehlertexte in eigener Datei; fehlende und überzählige Schlüssel brechen den Testlauf

**Frage:** W2, Punkte 4 und 5. Wo liegen die Fehlercode-Texte, und ist ein fehlender Schlüssel
ein Abbruch oder eine Warnung?

**Entscheidung:**

1. **Eigene Datei** `frontend/src/i18n/de.errors.ts` für alle `errors.*`-Texte; `de.ts` enthält
   die Oberflächentexte und fügt beide zu `de` zusammen. Wer einen `ErrorCode` in den Vertrag
   einträgt, trägt den Text im selben Schritt dort ein (U-72, 5).
2. **Harter Abbruch, keine Warnung.** `frontend/src/i18n/catalogue.test.ts` läuft in `npm test`
   und schlägt fehl bei
   - einem Code aus `ErrorCode` im gebündelten Vertrag ohne deutschen Text,
   - einem Text unter `errors.*` ohne Code im Vertrag (ausgenommen `client.*`, `field.*`),
   - einem Wert aus `InterfaceLocale` ohne Sprachdatei und einer Sprachdatei ohne Wert dort,
   - einem Schlüssel, den eine weitere Sprachdatei mehr oder weniger hat als die deutsche,
   - einem Oberflächenschlüssel, den der Anwendungscode nirgends wörtlich nennt,
   - einem Verstoß gegen die Schlüsselkonvention (U-88), einer Pluralgruppe ohne `#other`, einem
     leeren Text oder einem unbenannten Platzhalter.
3. **Die Prüfungen sind reine Funktionen** in `catalogueCheck.ts` und werden in
   `catalogueCheck.test.ts` je an einem absichtlich beschädigten Katalog vorgeführt — darunter
   der Testfall des Pakets, „ein absichtlich entfernter Schlüssel wird gemeldet".
4. ~~**Fehlt das Vertragsbündel** (`var/cache/openapi.bundled.json`), werden die beiden
   Vertragsprüfungen übersprungen und als übersprungen gemeldet.~~ **Ersetzt durch U-93:** ohne
   Bündel schlagen sie fehl. Alle übrigen laufen immer.
5. **Unbekannte Codes zur Laufzeit** bleiben, wie U-72 sie geregelt hat: `detail`, sonst `title`,
   sonst `errors.client.unknown` — nie eine leere Zeile.
6. Auf der Serverseite hält `tests/Contract/OwnPreferencesEndpointTest.php` die PHP-Aufzählung
   `InterfaceLocale` und das Vertragsenum gleich. Damit ist die Kette Server ↔ Vertrag ↔
   Sprachdateien an beiden Enden geschlossen.

**Warum:** Eine Warnung im Build wird einmal gelesen und dann nie wieder. Der Abbruch kostet den,
der einen Fehlercode einträgt, eine Zeile Deutsch — und verhindert, dass ein Nutzer `request.foo`
oder englischen Rückfalltext liest, ohne dass es jemand merkt. Die Prüfung auf ungenutzte
Schlüssel hat beim ersten Lauf zwei Reste aus W1 gefunden (`app.skipToContent`,
`signIn.expired`); sie sind entfernt.

Die eigene Datei für Fehlertexte macht den Abgleich mit dem Backend zu einem Blick in eine Datei,
deren Blöcke dieselbe Reihenfolge und dieselben Paketkommentare haben wie `ErrorCode`.

**Alternative:** Ein eigenes Skript statt eines Tests. Dasselbe Ergebnis, aber ein zweiter
Einstiegspunkt, den CI und Entwickler kennen müssten; `npm test` läuft ohnehin.

**Korrekturkosten:** Keine nennenswerten.

---

## U-90 — Sprachwahl: Browserliste als Vorgabe, Überschreibung je Verwalter in `mailadmin_admin_setting`

**Frage:** W2 verlangt „Sprachwahl aus `Accept-Language`, überschreibbar je Administrator; Ablage
in `mailadmin_admin_setting`". Wie genau, und über welchen Endpunkt?

**Entscheidung:**

1. **Reihenfolge:** gewählte Sprache des angemeldeten Verwalters → erste angebotene Sprache aus
   `navigator.languages` → Deutsch. Ausgehandelt wird **im Client**: `navigator.languages` ist
   genau die Liste, die der Browser als `Accept-Language` sendet; sie im Client auszuwerten wirkt
   schon auf der Anmeldeseite und spart eine Anfrage. Erst der volle Tag (`de-AT`), dann die
   Hauptsprache.
2. **Vertrag:** `GET` und `PUT /admins/me/preferences` mit dem Schema `AdminPreferences`
   (`{ "locale": "de" | null }`), eigene Dateien `openapi/paths/preferences.yaml` und
   `openapi/components/preferences.yaml`, Tag `admins` (benutzt, nicht deklariert — die Handler
   liegen deshalb unter `Http\Handler\Admins`). `null` heißt „wie im Browser". Kein
   `/admins/{username}/preferences`: die Sprache eines anderen geht niemanden etwas an.
3. **Berechtigungen** `profile.preferences.read` und `profile.preferences.write`, **beide
   Rollen**, deshalb ohne `403` im Vertrag. Familie `profile.*` wie `profile.password.write`
   (U-43): das eigene Konto, nicht die Verwaltung anderer.
4. **Ablage** in `mailadmin_admin_setting`, Zeile `name = 'ui.locale'` — genau das Beispiel aus
   dem Kommentar von Migration 0050. **Keine neue Migration**, die Tabelle hat die nötige Form.
   „Wie im Browser" ist das **Fehlen** der Zeile, wie bei `mailadmin_domain_setting` (A1); eine
   `NULL`-Zeile oder ein Wert, den dieser Build nicht anbietet (nach einem Rückbau), wird als
   „nichts gewählt" gelesen statt die Antwort scheitern zu lassen. Das Löschen eines Verwalters
   räumt die Zeilen schon seit A4 mit ab.
5. **`PUT` ersetzt das Ganze**, ein fehlendes Feld ist `422`, ein nicht angebotener Wert ebenfalls
   (Schemaprüfung am Eingang). **Kein `If-Match`**: der einzige Schreiber ist der Verwalter selbst.
6. **Audit:** `admin.preferences.change`, Objekt `admin`/Benutzername, `before` und `after`, in
   der Transaktion der Änderung (U-63). Ein `PUT`, der den gespeicherten Stand wiederholt, ändert
   nichts und schreibt nichts — weder `updated_at` noch einen Eintrag.
7. **Oberfläche:** eine Karte „Sprache und Zeit" auf der Übersicht, sichtbar mit
   `profile.preferences.write`: Auswahl „Wie im Browser eingestellt (…)" oder eine der
   ausgelieferten Sprachen. Die Wahl wird nicht im Browser abgelegt; nach der Abmeldung gilt
   wieder die Browserliste.

**Warum:** Die API bleibt sprachneutral (E-16) — gespeichert wird eine Vorliebe, übersetzt wird
nichts, und keine Serverantwort hängt von ihr ab. Eine serverseitige Aushandlung über
`Accept-Language` hätte vor der Anmeldung nicht geholfen und eine zweite Stelle geschaffen, die
die Liste der Sprachen kennen muss.

**Alternative:** Die Sprache als Feld in `GET /auth/me`. Spart eine Anfrage nach dem Laden, mischt
aber S2s Sitzungsauskunft mit einer Einstellung und verlangt einen Schreibweg anderswo.

**Korrekturkosten:** Gering. Zwei Operationen, ein Port, eine Zeile je Verwalter.

---

## U-91 — Englisch kommt später; die Struktur steht

**Frage:** W2, Punkt 6. Englisch von Anfang an mitpflegen?

**Entscheidung:** **Nein, ausgeliefert wird nur Deutsch.** Die Struktur ist so gebaut, dass
Englisch eine Datei und zwei Einträge ist:

1. `frontend/src/i18n/en.ts` vom Typ `Catalogue` — der Typ erzwingt dieselben Schlüssel wie
   `de`, die Prüfung aus U-89 meldet zusätzlich jede Abweichung,
2. ein Eintrag in `catalogues` (`frontend/src/i18n/locales.ts`),
3. der Wert `en` in `InterfaceLocale` (`openapi/components/preferences.yaml`) und als Fall in
   `Domain\Model\InterfaceLocale`. Vertragstest und Frontendtest verlangen beides gemeinsam.

Die Sprachnamen stehen je Sprache in ihrer eigenen Datei (`preferences.localeName.<code>`).

**Warum:** Eine zweite Sprache, die niemand liest, verrottet trotz Prüfung inhaltlich: die
Schlüssel stimmen, die Sätze passen nicht mehr. Solange es keine fachlichen Ansichten gibt,
wäre fast jeder englische Satz später neu zu schreiben. Vor der Veröffentlichung (E-5) ist das
ein überschaubarer Durchgang über eine Datei.

**Alternative:** Englisch sofort. Hält die Struktur ehrlich, kostet aber jede künftige Ansicht
eine zweite Formulierung, solange niemand sie liest.

**Korrekturkosten:** Gering, siehe die drei Schritte oben.

---

## U-92 — Zeiten erscheinen in der Zeitzone des Browsers, immer mit Zonenkürzel

**Frage:** W2, Punkt 7. Die API liefert UTC (U-14, `Z`-Suffix). Anzeige in der lokalen Zeit des
Browsers oder in einer konfigurierten Zone?

**Entscheidung:** **Zeitzone des Browsers**, ohne Einstellung. `dateTime()` zeigt Datum, Uhrzeit
**und Zonenkürzel** (`17.10.2026, 14:05 MESZ`); die Karte „Sprache und Zeit" nennt die Zone
dieses Browsers ausdrücklich. Gespeichert wird weiter in UTC; nichts im Client rechnet mit
Ortszeit.

**Warum:** Die Verwalter dieser Installation arbeiten in einer Zone, und der Browser kennt sie
bereits. Eine konfigurierte Zone wäre eine weitere Einstellung, die genau dann falsch ist, wenn
jemand unterwegs ist. Das Zonenkürzel löst den einen echten Fall: zwei Verwalter in
verschiedenen Zonen, die über denselben Protokolleintrag sprechen, sehen, dass sie verschiedene
Uhren lesen.

**Alternative:** Eine Zone je Verwalter in `mailadmin_admin_setting` (`ui.time_zone`). Die
Tabelle und `AdminPreferences` tragen das ohne Umbau, wenn es je gebraucht wird.

**Korrekturkosten:** Gering. `createFormatters()` nimmt die Zone bereits als Parameter.

---

## U-93 — Ohne Vertragsbündel schlägt die Katalogprüfung fehl, statt zu überspringen

**Frage:** Review W2. U-89, 4 ließ die beiden Vertragsprüfungen aus `catalogue.test.ts`
überspringen, wenn `var/cache/openapi.bundled.json` fehlt. `npm test` hat keinen Vorlauf, der das
Bündel baut; auf einem frischen Checkout oder einem CI-Läufer endet der Lauf also grün, ohne dass
ein einziger Fehlercode gegen die Texte geprüft wurde. Genau das sollte U-89 verhindern.

**Entscheidung:**

1. **Fehlt das Bündel, schlagen beide Vertragsprüfungen fehl**, mit dem Hinweis, erst
   `php bin/openapi-bundle.php` auszuführen. Dasselbe gilt, wenn das Bündel die Aufzählung
   (`ErrorCode`, `InterfaceLocale`) nicht enthält — vorher führte auch das still zum
   Überspringen.
2. Gelesen wird über die reine Funktion `contractEnum()` in `catalogueCheck.ts`; ihr Verhalten
   ohne Bündel und ohne Aufzählung ist in `catalogueCheck.test.ts` vorgeführt.
3. **Kein `pretest`-Skript.** Das Bündel entsteht in PHP, und PHP läuft nicht zwingend auf dem
   Rechner, auf dem `npm test` läuft. Die Reihenfolge „erst PHP-Build, dann Frontend" gilt ohnehin
   schon: `npm run typecheck` und `npm run build` brauchen das Bündel für `api:types`.

**Warum:** Ein übersprungener Test ist im Ergebnis eines CI-Laufs nicht von einem bestandenen zu
unterscheiden. Die Datenbanktests der PHP-Suite überspringen, weil ihnen eine laufende Umgebung
fehlt; dem Frontend fehlt nur ein Bauschritt, den jeder Weg zur Auslieferung ohnehin geht.

**Alternative:** Nur unter `CI` fehlschlagen, lokal überspringen. Das hätte die Lücke für jeden
gelassen, der lokal ohne PHP-Build prüft und dann eincheckt.

**Korrekturkosten:** Keine nennenswerten.

---

## U-94 — Größen wählen die Einheit nach dem gerundeten Wert; reine Kalenderdaten in UTC

**Frage:** Review W2, zwei Randfälle in `createFormatters()`. `bytes()` wählte die Einheit vor dem
Runden: 1 048 575 B erschien als `1.024 KiB` statt `1 MiB`. Und `date('2026-10-17')` las ein
reines Datum als Mitternacht UTC und zeigte es westlich von UTC als Vortag.

**Entscheidung:**

1. **Einheit nach dem angezeigten Wert:** `bytes()` vergleicht den auf eine Nachkommastelle
   gerundeten Wert mit 1024 — dieselbe Rundung wie die Anzeige. `1023,9 KiB` bleibt, was
   `1024,0` würde, rückt in die nächste Stufe. Oberhalb von TiB bleibt es bei `1.024 TiB`.
2. **Reine Kalenderdaten** (`^\d{4}-\d{2}-\d{2}$`, OpenAPI `format: date`) formatiert `date()` in
   UTC: sie bezeichnen einen Tag, keinen Zeitpunkt. Zeitpunkte mit Uhrzeit bleiben, wie U-92 es
   festlegt, in der Zone des Browsers. Der Vertrag hat derzeit kein solches Feld; die Regel steht,
   bevor das erste kommt.

**Warum:** Eine Quote, die als `1.024 KiB` erscheint, liest sich wie „tausendvierundzwanzig" und
ist doch fast ein MiB. Ein Datum, das je nach Standort einen Tag früher erscheint, ist schlicht
falsch.

**Alternative:** Für Daten: `date()` nur Zeitpunkte annehmen lassen und das dokumentieren. Das
verschiebt den Fehler zur ersten Ansicht, die ein `format: date`-Feld zeigt.

**Korrekturkosten:** Keine nennenswerten.

---

## U-95 — Eine Domain mit Adressen wird nicht gelöscht; ihre Zuordnungen gehen mit

**Frage:** Review von A1/A2. `DomainRepository::delete()` entfernte nur die Zeile in `domain` und
die in `mailadmin_domain_setting`. Was ist mit den übrigen Zeilen, die die Domain nennen?
`domainadmin` überlebte, und eine später unter demselben Namen angelegte Domain gehörte damit
stillschweigend wieder ihrem alten Domainmaster. Die Zeilen in `virtual` (Adressen `…@domain`,
Catchall `@domain`, Weiterleitungen) überlebten ebenso und lebten mit dem Namen wieder auf.

**Entscheidung:**

- **Zeilen in `virtual` verweigern das Löschen** wie Konten (U-49): `409
  domain.still_has_addresses`, die Anzahl im Log, kein Audit-Eintrag (wie `domain.not_empty`,
  U-76). Gezählt wird jede Zeile, deren `alias` auf `@<domain>` endet — über `RIGHT()`, ohne
  `LIKE`-Platzhalter, ohne Unterscheidung der Schreibweise, dieselbe Regel wie
  `DomainRepository::renameAliases()`. Entfernt werden sie nicht hier, sondern über A3. Konten
  werden zuerst geprüft; eine Domain mit Konten antwortet weiter `domain.not_empty`.
- **Die `domainadmin`-Zeilen der Domain werden im selben Schritt gelöscht**, und zwar **vor** der
  Zeile in `domain`: unter MyISAM (O-11) bleibt bei einem Abbruch dazwischen höchstens eine Domain
  ohne Zuordnung stehen — die ein Superuser neu vergibt —, nie eine Zuordnung ohne Domain. Die
  Wildcard-Zeile `*` nennt keine Domain und bleibt unberührt.
- **Protokoll:** je entfernter Zuordnung ein Kindeintrag `domain.delete`, Objekttyp `admin`,
  Objekt-Id der Verwaltername, `before` = `{"domains": ["<domain>"]}`, über `part_of` am
  Löscheintrag der Domain — das Muster der Umbenennung für ihre Konten (U-76).
- **Bezug zu A4 (U-53):** Das Löschen erzeugt keine verwaisten Zuordnungen mehr. Bestehende
  verwaiste Zuordnungen bleiben, wie U-53 es will, angezeigt und markiert; bereinigt wird nichts
  (E-18). Ein Domainmaster, dessen letzte Domain gelöscht wird, hat danach keine Zuordnung mehr,
  kann sich nicht anmelden (`AdminDirectory::findActor()` antwortet `null`, wie für jeden
  Domainmaster ohne Zeile) und erscheint in A4 mit leerer `domains`-Liste — ein Superuser vergibt
  neu oder löscht ihn. Hingenommen wird der Wettlauf zwischen dem Löschen einer Domain und einer
  gleichzeitigen Zuordnung derselben Domain in A4: beides ist Superuser-Sache, und A4 prüft die
  Existenz ohne Sperre.

**Warum:** Eine Zuordnung, die ihre Domain überlebt, ist eine Berechtigung, die niemand mehr
vergeben hat. Eine Adresse, die ihre Domain überlebt, ist Zustellung, die niemand mehr sieht —
und die mit dem Namen zurückkehrt. Beides nach dem Muster von U-49: was Mail trägt, wird bewusst
und einzeln entfernt; was nur Berechtigung ist, geht mit.

**Alternative:** Die `virtual`-Zeilen kaskadierend mitlöschen — verworfen wie das Kaskadieren der
Konten in U-49: hinter einer Bestätigung läge beliebig viel Zustellung. Die Zuordnungen stehen
lassen und erst beim Neuanlegen prüfen — verworfen, weil dann die Anlage entscheiden müsste, wem
eine Domain gehört, die es noch nicht gibt.

**Korrekturkosten:** Gering. `DeleteDomain::requireNothingLeft()` und `DomainRepository::delete()`.

---

## U-96 — Punktschema in Gegenrichtung: Domainname, der einen Benutzernamen mehrdeutig macht

**Frage:** U-86 (d) lehnt ein Konto ab, dessen Punktname in den Namensraum einer bestehenden
Subdomain fällt. Die Gegenrichtung war offen: `example.test` hat das Konto `info.kunde` (Cyrus
`info.kunde.example.test`), danach wird `kunde.example.test` angelegt — dessen Konto `info` hätte
denselben Benutzernamen. Dasselbe beim Umbenennen, und dort zusätzlich für die Konten, die die
Umbenennung bewegt.

**Entscheidung:** `409 domain.namespace_conflict`, neuer Code, im Vertrag und mit deutschem Text.

- **Anlegen** (`CreateDomain`) und **Umbenennen** (`RenameDomain`, auch über `PATCH`) lehnen einen
  Namen `N` ab, wenn ein Konto einer **Elterndomain** von `N` einen gespeicherten Benutzernamen ohne
  `@` hat, der auf `.N` endet (ohne Unterscheidung der Schreibweise). Ob das Konto im Punktschema
  ist, entscheidet sein gespeicherter Name (CLAUDE.md: erkannt, nie abgeleitet) — damit ist auch
  ein geerbtes Schema abgedeckt. Das Schema der **neuen** Domain spielt keine Rolle: es lässt sich
  später ändern, die vorhandenen Benutzernamen nicht.
- Konten einer **Subdomain** von `N` blockieren nicht: Legt man `example.test` an, während
  `kunde.example.test` das Konto `info` hat, schützt U-86 den Namen — `info.kunde@example.test`
  wird dann abgelehnt.
- **Umbenennen** prüft zusätzlich jedes bewegte Konto: fällt sein neuer Punktname in den
  Namensraum einer bestehenden Subdomain (Regel aus U-86), oder ist der neue Benutzername schon
  vergeben (auch durch eine verwaiste Zeile), wird abgelehnt. Die Konten der umbenannten Domain
  selbst sind ausgenommen — sie ziehen mit. Eine reine Änderung der Schreibweise prüft nichts.
- Die Prüfung läuft vor dem ersten `renamemailbox` und noch einmal unter der Sperre aus U-97.
  Scheitert sie dort, sind die Postfächer schon bewegt: sie werden zurückbenannt (der Ausgleich
  aus U-76), ein `failed`-Eintrag entsteht, und die Antwort ist die Ablehnung selbst. Kommen die
  Postfächer nicht vollständig zurück, bleibt es bei `domain.rename_incomplete` mit
  Reparatureintrag.

**Warum:** Ein mehrdeutiger Benutzername ist eine Anmeldung, die zwei Domainmastern zugleich
gehört. U-86 hat die eine Richtung geschlossen; ohne die andere hängt die Sicherheit davon ab, in
welcher Reihenfolge angelegt wurde.

**Alternative:** Nur prüfen, wenn das wirksame Schema der neuen Domain `dotted` ist — verworfen,
weil das Schema (oder die globale Vorgabe, von der es erbt) sich ändern kann, nachdem die Domain
angelegt ist. Bestehende Mehrdeutigkeiten suchen und melden — gehört zu A6s Abgleich, nicht
hierher.

**Korrekturkosten:** Gering. `DomainStore::namespaceClaimant()`/`accountUsernameTaken()`,
`Domain\Model\DottedNamespace`, `CreateDomain::requireFree()`,
`RenameDomain::requireUnambiguous()`.

---

## U-97 — Eine Namensraumsperre für Kontoanlage und Domainanlage, -umbenennung und -löschung

**Frage:** Die Prüfungen aus U-86 und U-96 lesen jeweils die andere Tabelle. `requireUnambiguous`
lief in `CreateAccount` nur vor dem Mailstore, nicht unter der Domainsperre aus U-81, und das
Anlegen einer Domain nahm gar keine Sperre. Zwei gleichzeitige Anfragen konnten so beide Prüfungen
bestehen und die Mehrdeutigkeit doch erzeugen.

**Entscheidung:**

- **Eine** benannte Sperre, `GET_LOCK('mailadmin.namespace')`, über `DomainStore::withNamespaceLock()`.
  Sie umschließt den **Datenbankschritt** der Kontoanlage sowie Anlegen, Umbenennen und Löschen
  einer Domain — jeweils nur den kurzen Datenbankteil, nie Mailstore-Runden (dieselbe Abwägung
  wie U-81).
- Unter der Sperre wird wiederholt, was vorher als Höflichkeitsprüfung lief: in `CreateAccount`
  Existenz der Domain, Punktname (U-86), Belegung und Kontingent (U-81); in `CreateDomain`
  Belegung und Namensraum (U-96); in `RenameDomain` Ziel frei und Namensraum (U-96); in
  `DeleteDomain` Konten und Adressen (U-95). Die Kontoanlage sieht damit auch eine Domain, die
  inzwischen gelöscht wurde — das Postfach wird wieder entfernt, die Antwort ist `404`.
- **Reihenfolge der Sperren:** Namensraumsperre außen, Domainsperre (U-81) innen — an jeder
  Stelle gleich, damit sie sich nicht gegenseitig blockieren. `GET_LOCK` ist auf derselben
  Verbindung wiedereintrittsfähig.
- **Zeitlimit und Fehlschlag wie U-81:** zehn Sekunden, danach `PersistenceError`. Beide Sperren
  laufen über dieselbe Hilfsklasse `NamedLock`, damit sie gleich scheitern. Bei der Kontoanlage
  ist das Postfach dann schon angelegt und wird wie bei jedem Fehler des Datenbankschritts wieder
  entfernt (`409 account.setup_incomplete`); bei Domainanlage, -umbenennung und -löschung ist es
  ein `500` ohne Änderung, bei der Umbenennung nach Rückbenennung der Postfächer
  `409 domain.rename_incomplete`.

**Warum:** Prüfen-dann-Schreiben ist nur unter einer Sperre sicher, die **beide** Seiten nehmen.
Eine Transaktion hilft nicht (MyISAM, O-11; und selbst InnoDB sähe die fremde Zeile unter
`REPEATABLE READ` nicht). Die Sperre gilt nicht gegen webcyradm, das nichts von ihr weiß — gegen
das schützt beim Domainnamen der Primärschlüssel, beim Namensraum nichts; das ist der
Parallelbetrieb, den E-18 hinnimmt.

**Alternative:** Eine Sperre je betroffenem Namensraum (Domain und alle Elterndomains). Feiner, aber
die Menge der zu sperrenden Namen hängt von Daten ab, die man erst unter der Sperre lesen dürfte.
Die globale Sperre serialisiert Kontoanlagen über alle Domains — für Anfragen von Administratoren,
deren Datenbankschritt Millisekunden dauert, ohne spürbare Folge.

**Korrekturkosten:** Gering. `DomainStore::withNamespaceLock()`, `NamedLock`.

---

## U-98 — Richtlinien-Ablehnungen sind keine `DomainError` mehr

**Frage:** Nebenbefund aus A2: `AccessDenied` und `ResourceNotVisible` erbten von `DomainError`.
Jeder Handler, der `DomainError` fängt, um einen vom Modell abgelehnten Wert als `422` zu melden,
fing damit auch die `403`/`404` der Richtlinien ab — aus einem verdeckten fremden Objekt wurde ein
`422`, der das Feld nennt. A2 hatte die Ablehnungen in drei Handlern ausdrücklich weitergeworfen;
`CreateDomainHandler` und `UpdateDomainHandler` taten es nicht.

**Entscheidung:** Strukturell behoben. Beide erben jetzt von einer eigenen abstrakten Klasse
`Domain\Policy\PolicyRefusal` (`RuntimeException`, mit `code()` und `context()` wie
`DomainError`). Kein `catch (DomainError)` erreicht sie mehr; die ausdrücklichen Weiterwürfe in
A2 sind entfernt. `tests/Unit/Http/HandlerCatchGuardTest.php` liest die `catch`-Klauseln aller
Handler, löst die Namen auf und schlägt fehl, wenn ein gefangener Typ eine der beiden Ablehnungen
einschließt (`Exception`, `Throwable`, `RuntimeException`, `PolicyRefusal` oder sie selbst). Die
einzige Ausnahme ist benannt: `ListAuditEntriesHandler::toColumnValue()` fängt `\Exception` nur um
`new DateTimeImmutable()`.

**Warum:** Eine Regel, die jeder Handler einhalten muss, wird irgendwann von einem vergessen —
genau das war in A1 schon geschehen. Erreichbar war es dort nicht, weil `domain.write` dem
Superuser vorbehalten ist und die Middleware vorher ablehnt; beim nächsten Paket mit Domainmaster-
Zugriff wäre es das.

**Alternative:** Die Weiterwürfe in jedem Handler nachziehen und per Test erzwingen — wirkt nur auf
die Handler, die es heute gibt.

**Korrekturkosten:** Gering.

---

## U-99 — Ein neuer Domainname erbt keine Zuordnungen und Adressen einer früheren Domain

**Frage:** Review der Sicherheitsnachbesserung. U-95 verhindert, dass das Löschen über mailadmin
neue Waisen erzeugt. Bestehende verwaiste `domainadmin`-Zeilen bleiben aber stehen (U-53), und
webcyradm kann im Parallelbetrieb (E-18) neue erzeugen — ebenso `virtual`-Zeilen `…@<name>` und
den Catchall `@<name>`. `CreateDomain` prüfte nur `domain` und den Namensraum, `RenameDomain` unter
der Sperre nur Ziel und Namensraum. Eine Domain, die unter dem Namen neu entsteht, gehörte damit
sofort dem alten Domainmaster, und ihre Mail an unbekannte Adressen ging über den alten Catchall an
ein fremdes Postfach.

**Entscheidung:** `409 domain.leftover_state`, neuer Code, im Vertrag und mit deutschem Text.

- **Anlegen** und **Umbenennen** lehnen einen Namen ab, solange `domainadmin`-Zeilen ihn nennen
  oder `virtual`-Zeilen auf `@<name>` enden (dieselbe Zählung wie U-95, ohne Unterscheidung der
  Schreibweise). Die Meldung nennt beide Zahlen. Kein Audit-Eintrag, wie bei den anderen `409` der
  Anlage; eine Umbenennung, die erst unter der Sperre scheitert, schreibt wie jede Ablehnung dort
  ihren `failed`-Eintrag nach der Rückbenennung.
- Geprüft wird vor dem ersten `renamemailbox` bzw. vor der Sperre und **noch einmal unter der
  Namensraumsperre** (U-97). Gegen webcyradm schützt die Sperre nicht — das nimmt E-18 hin.
- Eine reine Änderung der Schreibweise prüft nichts: die gefundenen Zeilen sind die eigenen.
- **Bereinigt wird nichts.** Verwaiste Zuordnungen bleiben in A4 sichtbar (U-53) und werden dort
  bewusst entfernt; die `virtual`-Zeilen gehören A3 (U-95). Danach ist der Name frei.
- Umgesetzt in `Application\Domain\DomainLeftovers`, gemeinsam für beide Anwendungsfälle;
  `DomainStore::assignmentCount()` kommt hinzu.

**Warum:** Eine Zuordnung, die niemand vergeben hat, ist eine Berechtigung; ein Catchall, den
niemand sieht, ist Zustellung an Dritte. Beides beim Anlegen stillschweigend zu übernehmen ist
derselbe Fehler, den U-95 beim Löschen schließt — nur von der anderen Seite.

**Alternative:** Die verwaisten Zuordnungen beim Anlegen löschen und protokollieren, wie
`DeleteDomain` es tut — verworfen, weil U-53 und E-18 eine Bestandsbereinigung ausschließen und
das Anlegen dann entschiede, wem eine alte Zuordnung gehört (dieselbe Begründung wie in U-95). Nur
warnen — verworfen: die Warnung käme, wenn die Berechtigung schon wirkt. Wer eine Domain neu
anlegen will, deren Name Altlasten trägt, braucht derzeit A4 für die Zuordnungen und — bis A3
steht — einen Weg außerhalb von mailadmin für die Adressen; das ist hingenommen.

**Korrekturkosten:** Gering. `DomainLeftovers`, `CreateDomain::requireFree()`,
`RenameDomain::requireNoLeftovers()`.

---

## U-100 — Die Umbenennung prüft unter der Sperre, ob ihr Plan noch stimmt

**Frage:** Review der Sicherheitsnachbesserung. Der Plan (Konten und ihre neuen Cyrus-Namen)
entsteht vor dem Bewegen der Postfächer. Die Kontoanlage hält die Namensraumsperre nur für ihren
kurzen Datenbankschritt (U-97) und kann deshalb ein Konto in der Domain festschreiben, während die
Umbenennung noch Postfächer bewegt. Die Transaktion der Umbenennung setzte danach
`domain_name` des neuen Kontos um, seinen Benutzernamen nicht — genau das gestrandete Konto des
Altsystems. Ebenso: eine **leere** Domain hat keinen Mailstore-Schritt, ein gleichzeitiges Löschen
konnte vor der Sperre durchgehen, und die Umbenennung meldete danach Erfolg für ein `UPDATE`, das
nichts traf, samt Audit-Eintrag.

**Entscheidung:**

- Unter der Namensraumsperre liest `RenameDomain` die gespeicherten Benutzernamen der Domain neu
  und vergleicht sie byteweise, ohne Reihenfolge, mit dem Plan. Weichen sie ab, ist das eine
  Ablehnung unter der Sperre: Postfächer zurück, `failed`-Eintrag, Antwort `409
  domain.rename_incomplete` mit der Meldung, dass sich die Konten geändert haben und nichts
  geändert wurde. Ein neuer Code wäre zu viel — für den Client ist es dasselbe: nicht umbenannt,
  noch einmal versuchen.
- Ebenfalls unter der Sperre: Gibt es die Domain nicht mehr, ist die Antwort `404`
  (`request.not_found`), nicht ein Erfolg.
- Die Sperre wird **nicht** auf die ganze Mailstore-Phase ausgedehnt: sie würde jede Kontoanlage
  aller Domains für die Dauer einer Umbenennung anhalten, und U-97 hält sie bewusst kurz.

**Warum:** Die Prüfung kostet eine Abfrage und schließt den Fall vollständig: was nach ihr
geschrieben werden will, wartet auf die Sperre und sieht danach eine Domain, die es nicht mehr gibt
(`404` der Kontoanlage, U-97).

**Alternative:** Kontoanlage in einer Domain verweigern, deren Umbenennung läuft — bräuchte einen
gemeinsamen Zustand „Umbenennung läuft", der bei einem Absturz hängen bliebe.

**Korrekturkosten:** Gering. `RenameDomain::requirePlanCoversEveryAccount()`.

---

## U-101 — Weiterleitungen im Punktschema ziehen bei der Umbenennung mit

**Frage:** Review der Sicherheitsnachbesserung. Eine Weiterleitung ist in `virtual` die Zeile
`alias = <Cyrus-Benutzername>`, `dest = <externe Adresse>`, `username = ''` (Architektur 3.3).
`DomainRepository::rename()` schrieb `virtual.username`, Aliase auf `@<domain>` und `dest` um —
ein Punktname wie `clara.example.test` endet nicht auf `@example.test`, die Weiterleitung blieb also
unter dem alten Namen stehen. Sie hörte still auf zu wirken und galt wieder, sobald jemand den
alten Namen bekam: durch eine spätere Umbenennung einer anderen Domain auf den Namen, deren Konto
dann fremde Mail mit an die externe Adresse geschickt hätte. `requireUnambiguous()` sah solche
Zeilen nicht.

**Entscheidung:**

- `rename()` schreibt je bewegtem Konto auch dessen Weiterleitungszeilen um:
  `alias` byteweise gleich dem alten Benutzernamen und `username = ''` — dieselbe Abgrenzung wie
  `AccountRepository::delete()`. Im `fqun`-Schema endet der Schlüssel ohnehin auf `@<domain>`;
  dort ändert sich nichts.
- `RenameDomain::requireUnambiguous()` behandelt eine `virtual`-Zeile, deren `alias` dem neuen
  Benutzernamen eines bewegten Kontos gleicht (ohne Unterscheidung der Schreibweise), als belegt —
  dieselbe Regel wie `AccountRepository::isTaken()` bei der Anlage. Antwort `409
  domain.namespace_conflict`, vor dem ersten `renamemailbox` und noch einmal unter der Sperre.
  Neu ist `DomainStore::aliasTaken()`.
- **Nicht** geändert: `DeleteDomain` zählt weiter nur `…@<domain>`. Eine leere Domain hat keine
  Konten, deren Weiterleitungen noch an ihr hängen könnten — `AccountRepository::delete()` entfernt
  sie mit dem Konto. Altzeilen, deren Konto längst fort ist, lassen sich einer Domain nicht
  zuordnen (ein Punktname ist nicht zerlegbar); gegen ihr Wiederaufleben schützen die Belegtprüfung
  der Anlage und die neue Prüfung der Umbenennung.

**Warum:** Eine Weiterleitung, die beim Umbenennen zurückbleibt, ist zugleich ein Zustellverlust
für den einen Kunden und eine Zustellung an Dritte für den nächsten.

**Alternative:** Die zurückgebliebenen Zeilen nach dem Umbenennen löschen — verworfen, die
Weiterleitung soll ja weiter wirken.

**Korrekturkosten:** Gering. `DomainRepository::rename()`, `RenameDomain::requireUnambiguous()`.

---

## U-102 — Die Abwesenheitsnotiz steht als Modellzeile im Skript; fremde Skripte nur mit Übernahme

**Frage:** A3 soll die Abwesenheitsnotiz aus dem Modell erzeugen, nie durch Patchen (M3). Woraus
wird das Modell beim nächsten Ändern gelesen? Eine eigene Tabelle wäre eine zweite Wahrheit
neben dem, was in Cyrus tatsächlich aktiv ist; das Skript zu parsen wäre genau das Patchen, das
M3 ausschließt. Und was gilt, wenn das aktive Skript nicht von uns stammt?

**Entscheidung:**

- **Das Modell steht im Skript, als Daten.** Die zweite Zeile jedes von mailadmin erzeugten
  Skripts lautet `# mailadmin-model: <base64url(JSON)>` — direkt unter der Markierung, sonst
  nirgends gesucht. `SieveRuleSetCodec` kodiert den ganzen `SieveRuleSet` (Version `1`:
  Abwesenheit, Umleitungen, Filter, `keep`). Gelesen wird ausschließlich diese Zeile, nie das
  Sieve darunter; geändert wird, indem der Regelsatz aus der Zeile genommen, die Abwesenheit
  ersetzt und das Skript **vollständig neu gerendert** wird.
- **Vier Zustände** (`VacationState`): `none` (kein aktives Skript oder unseres ohne Notiz),
  `active`, `foreign` (ohne unsere Markierung) und `unreadable` (unsere Markierung, aber die
  Modellzeile fehlt oder lässt sich nicht dekodieren — etwa ein Skript aus M3 vor dieser
  Entscheidung). `foreign` und `unreadable` zeigen das Skript schreibgeschützt in `script`.
- **Ein fremdes Skript wird nie ohne ausdrückliche Übernahme ersetzt**: `409
  vacation.foreign_script`; `take_over: true` (Body bei `PUT`, Query bei `DELETE`) übernimmt.
  Übernehmen heißt **deaktivieren, nicht löschen**: unser Skript `sieve` wird aktiv, das fremde
  bleibt unter seinem Namen gespeichert. Ein fremdes Skript, das selbst `sieve` heißt, ersetzt
  M3s Installer beim Übernehmen unter demselben Namen — das ist M3s bestehende Regel (M3, Punkt 1).
- **Ein unlesbares Skript ist unseres** und wird ohne Übernahme ersetzt; was es enthielt, zeigt
  das vorherige `GET`.
- **Protokoll ohne Text:** `vacation.set`/`vacation.delete` tragen Zustand, Betreff, Intervall,
  Adressen und `message_length`, nie die Nachricht selbst.
- **Audit ohne Rollback:** Das Skript liegt in Cyrus, keine Transaktion umfasst es. Reihenfolge
  wie U-85 bei der Quota: ändern, protokollieren; scheitert das Protokoll, wird das vorherige
  Skript zurückgelegt und aktiviert (bzw. unseres entfernt, wenn vorher keines aktiv war).
  Scheitert auch das: Reparatureintrag `sieve.restore`.
- Schreibvorgänge an Sieve laufen **nicht** unter der Namensraumsperre — sie berühren die
  Datenbank nicht; zwei gleichzeitige Änderungen schützt `If-Match`.

**Warum:** Die Modellzeile hält Modell und Wirkung in einem Objekt zusammen: was Cyrus ausführt
und was mailadmin beim nächsten Mal liest, können nicht auseinanderlaufen. Deaktivieren statt
Löschen, weil ein übernommenes Skript etwas sein kann, das der Nutzer in Roundcube gepflegt hat —
es soll wiederherstellbar bleiben.

**Alternative:** Eine Tabelle `mailadmin_sieve_rule` — verworfen, zwei Wahrheiten. Das Skript
parsen — verworfen (M3). Fremde Skripte beim Übernehmen löschen — verworfen, nicht umkehrbar.

**Korrekturkosten:** Gering. `SieveRuleSetCodec`, eine Zeile in `SieveRenderer`. Die Version im
JSON erlaubt ein späteres Format ohne Bruch; ein unbekanntes Format ist `unreadable`, nicht
verloren.

---

## U-103 — Ein `virtual`-Eintrag wird über einen Fingerabdruck adressiert; drei Regeln für „gehört zur Domain"

**Frage:** `virtual` hat keinen Schlüssel. Wie adressiert `DELETE /domains/{domain}/addresses/{entry}`
eine einzelne Zeile — auch eine, die in kein Muster passt? Und welche Zeilen zeigt die Adressliste
einer Domain, wenn eine Weiterleitung im Punktschema gar kein `@` trägt?

**Entscheidung:**

- **Fingerabdruck:** `id` = die ersten 32 Hex-Zeichen von SHA-256 über `alias`, `dest`,
  `username`, `status`, getrennt durch NUL. Eine geänderte Zeile hat eine andere `id` und ist
  `404` — das ist zugleich die Nebenläufigkeitsprüfung des Einzellöschens. Gleiche Duplikate haben
  dieselbe `id`; gelöscht wird genau eine.
- **Gehört zur Domain**, wenn (1) `alias` auf `@<domain>` endet (Adressen, Aliase, Catchall,
  `fqun`-Weiterleitungen — ohne Unterscheidung der Schreibweise, über `RIGHT()` wie U-95), oder
  (2) `alias` kein `@` hat, `username` leer ist und `alias` byteweise der Benutzername eines
  Kontos der Domain ist (Weiterleitungen im Punktschema), oder (3) `alias` kein `@` hat und
  `username` ein Konto der Domain nennt. **Regeln (2) und (3) sind durch U-126 ersetzt:** ein
  Schlüssel ohne `@` gehört der Domain des Kontos, das er nennt.
- **Unbekannte Zeilen werden angezeigt** (Typ `unknown`, `unknown_reason`), einzeln löschbar, nie
  beim Speichern eines Nachbarn verändert. Ein `dest` von `NULL` wird als leer gelesen
  (`no_destination`); beim Zurücksetzen schreibt mailadmin dafür `''` — Postfix behandelt beides
  gleich, eine Zeile ohne Ziel liefert nichts.
- **Kontoadressen** (`mailbox_address`) werden angezeigt, aber hier nicht gelöscht (`409
  address.not_editable`): sie gehen mit dem Konto (A2, U-83).
- Die Liste sortiert und blättert **in PHP** (`sort` ∈ {`alias`, `type`}, Filter `type` und
  `alias`), weil der Typ erst nach dem Klassifizieren feststeht. Die Allowlist steht im Vertrag.

**Warum:** Ein Fingerabdruck braucht keine Schemaänderung (Regel 1) und macht „die Zeile, die
der Client gesehen hat" adressierbar. Die drei Regeln sind dieselben, mit denen A2 beim Löschen
eines Kontos dessen Zeilen findet; eine Liste, die weniger zeigte, ließe `domain.still_has_addresses`
unerklärlich.

**Alternative:** Eine Ersatzspalte `id` in `virtual` — verworfen, Änderung an einer
Bestandstabelle. Positionsbasierte Adressierung — verworfen, MyISAM garantiert keine Reihenfolge.

**Korrekturkosten:** Gering. `EntryKey`, `VirtualRepository::rowsOfDomain()`.

---

## U-104 — Ziele: Komma ohne Leerzeichen, höchstens 50, Kontonamen in gespeicherter Schreibweise

**Frage:** A3, Punkt 1. `dest` ist eine kommagetrennte Liste. Welche Schreibweise, welche
Grenzen, und wie wird ein Kontoname von einer Adresse unterschieden?

**Entscheidung:**

- Geschrieben wird **kommagetrennt ohne Leerzeichen** — die Schreibweise jeder Bestandszeile;
  Postfix akzeptiert sie (`virtual_alias_maps` liefert die Liste, Postfix trennt an Komma und
  Leerraum). Ein Wert mit Komma oder Leerraum wird abgelehnt.
- **Höchstens 50 Ziele** je Eintrag (Vertrag `maxItems`).
- Ein Ziel, das ein **Kontoname** ist, wird ohne Unterscheidung der Schreibweise erkannt und in
  der Schreibweise aus `accountuser` geschrieben. Ein Wert ohne `@`, der kein Konto ist, wird
  abgelehnt (`address.invalid_destination`): Postfix ergänzte ihn um `myorigin` und stellte an
  eine fremde Adresse zu.
- **Doppelte Ziele und Schleifen** (das Ziel ist die Adresse selbst bzw. bei einer Weiterleitung
  der eigene Kontoname) werden abgelehnt.
- Ziele in einer anderen lokalen Domain werden **nicht** gegen den Zuschnitt geprüft — Annahme von
  U-44 bestätigt (A3, Punkt 6). `AddressPolicy::requireDestinationVisible()` bleibt ungenutzt.

**Warum:** Eine Schreibweise für alle Zeilen hält die Integrationstests gegen die Postfix-Abfrage
aussagekräftig. Die Prüfung des Ziels gegen den Zuschnitt bräche bestehende
abteilungsübergreifende Weiterleitungen; die Adresse selbst ist geprüft, und das ist der Fall,
der zählt.

**Alternative:** Zielprüfung gegen den Zuschnitt — verworfen (U-44). Leerzeichen nach dem Komma
— verworfen, nichts im Bestand schreibt so.

**Korrekturkosten:** Gering. `DestinationRules`.

---

## U-105 — Zeilen einer Adresse werden als eine neu geschrieben: erst einfügen, dann entfernen

**Frage:** Bestandsdaten verteilen einen Alias oft über mehrere Zeilen. Wie ändert A3 so etwas
auf MyISAM (O-11), wo Postfix jede Anweisung sofort sieht und ein `ROLLBACK` nichts zurücknimmt?

**Entscheidung:**

- Ein Alias, Catchall oder eine Weiterleitung wird als **eine Zeile** neu geschrieben; die
  Bestandszeilen werden entfernt. **Erst einfügen, dann entfernen**: dazwischen antworten alte
  und neue Zeile, die Mail geht an die Vereinigung — schlimmstenfalls eine Kopie zu viel, nie
  eine zu wenig (Architektur 8.1). Unverändertes schreibt nichts und protokolliert nichts.
- **Vorher wird geprüft**, ob die gelesenen Zeilen noch da sind (Anzahl je Zeile). Fehlt eine,
  `409 address.not_editable` und nichts wird geschrieben — eine Zeile, die jemand anders
  inzwischen entfernt hat, darf mailadmin nicht wiederbeleben.
- **Das Protokoll gehört zur Änderung** (wie U-85 für die Quota): scheitert der Audit-Eintrag,
  wird für jede beteiligte Zeile die vorherige Anzahl wiederhergestellt; auf InnoDB findet das
  nichts mehr zu tun. Scheitert auch das, Reparatureintrag `virtual.restore`.
- **Gemeinsam bearbeitbar** ist eine Adresse nur, wenn alle ihre Zeilen Aliase mit demselben
  `username` sind (Catchall entsprechend; Weiterleitungen: alle Zeilen Weiterleitungen). Sonst
  `editable: false`, `409 address.not_editable`, und die Zeilen werden einzeln über U-103
  bearbeitet.
- Alle `virtual`-Schreibvorgänge laufen unter der Namensraumsperre (U-97), damit eine
  gleichzeitige Domainumbenennung oder -anlage nicht dazwischenkommt.

**Warum:** Die Reihenfolge ist die einzige, bei der ein Abbruch an jeder Stelle keine Mail
verliert. Die Vorabprüfung schließt den Fall aus, dass das Zurücksetzen eine fremd gelöschte
Zeile wieder einfügt.

**Alternative:** `UPDATE` in place — verworfen, mehrere Zeilen und kein Schlüssel. Erst löschen,
dann einfügen — verworfen, dazwischen `550` für eine lokale Domain.

**Korrekturkosten:** Gering. `RowWriter`.

---

## U-106 — Wer als Alias senden darf: das eine Zielkonto, sonst niemand

**Frage:** `virtual.username` entscheidet über `validsenders`, wer als Adresse senden darf. Was
schreibt A3 hinein, wenn ein Alias an mehrere Ziele geht? Leer geht nicht ohne Weiteres: eine
Zeile mit leerem `username` und einem `alias` ohne `@` wäre eine Weiterleitung, und bei
Adressen mit `@` ist leer Webcyradms Form für „Weiterleitung nach außen".

**Entscheidung:**

- **Ohne Angabe** bekommt ein neuer Alias das Konto als Absender, wenn er an **genau ein Konto
  und sonst nichts** geht (Webcyradms „weitere Adresse"). Sonst einen **Platzhalter**: den
  Domainnamen in Kleinbuchstaben — kein Konto, also kein Eintrag in `validsenders`. Ist der
  Domainname länger als 50 Zeichen (Spaltenbreite) oder selbst ein Kontoname, `409
  address.unstorable`.
- **Ausdrücklich** (`sender_account`) muss es ein Konto unter den Zielen sein; `null` heißt
  niemand (Platzhalter).
- **Beim Ändern** bleibt der gespeicherte Absender, solange das Konto weiter Ziel ist; fällt es
  aus den Zielen, verliert es das Recht (Platzhalter).
- **Catchall:** ohne Angabe kein Absender (`username = ''`), ausdrücklich wie oben.

**Warum:** Senderecht ohne Zustellung wäre eine stille Berechtigung. Der Platzhalter verhält sich
in `validsenders` wie „niemand" und bleibt klassifizierbar. Den View ändern ist ein
Eskalationsfall; diese Lösung kommt ohne aus.

**Alternative:** Leerer `username` — verworfen, kollidiert mit der Typerkennung. Alle Zielkonten
als Absender — unmöglich, die Spalte trägt einen Namen.

**Korrekturkosten:** Gering. `SenderRules`. Ein späterer Wunsch nach mehreren Absendern bräuchte
mehrere Zeilen je Alias — U-105 zeigt sie bereits einzeln.

---

## U-107 — Weiterleitungen nur in `virtual`; „Kopie behalten" ist der eigene Kontoname im Ziel

**Frage:** A3, Punkt 2. Schalter oder Zielliste? Und Sieve-`redirect` oder `virtual`?

**Entscheidung:**

- Weiterleitungen leben **nur in `virtual`** (`alias` = gespeicherter Benutzername, `username`
  leer), wie im Bestand. Nie als Sieve-`redirect`.
- Die Darstellung trennt **Ziele außer dem Postfach** (`destinations`) und **`keep_copy`**.
  Gespeichert wird `keep_copy` als der eigene Benutzername am Ende der Zielliste — die
  Bestandsform.
- Im **`fqun`-Schema** hat die Adresszeile denselben Schlüssel wie die Weiterleitung, Postfix
  stellt also immer auch ins Postfach zu. `keep_copy: false` ist dort `409 forward.copy_required`
  (`copy_required: true` in der Darstellung), statt etwas zu versprechen, das nicht gilt.
- Ohne Kopie antwortet keine Abwesenheitsnotiz — die Mail erreicht das Postfach nicht. Der
  Vertrag sagt das; die Oberfläche soll es zeigen.

**Warum:** Postfix liest `virtual`, Sieve wirkt erst nach der Zustellung — eine Weiterleitung
über Sieve wäre für jedes Konto ohne Sieve-Recht wirkungslos und unsichtbar für alles, was
`virtual` liest (A6, Salt).

**Alternative:** Reine Zielliste ohne Schalter — verworfen, der Kontoname in der Liste ist für
Nutzer nicht verständlich.

**Korrekturkosten:** Gering. `SetForwards`, `ForwardState`.

---

## U-108 — Catchall: eine deaktivierte Adresse fällt durch; ein Ziel in der eigenen Domain muss existieren

**Frage:** A3, Punkt 4. Postfix fragt erst die volle Adresse, dann `@domain`. Was gilt bei
deaktivierten Zeilen, und wie werden Schleifen verhindert?

**Entscheidung:**

- `status` (A3, Punkt 3) ist der Schalter `enabled`; Postfix liest nur `status = 1`. Eine
  Adresse, deren Zeilen alle deaktiviert sind, ist für Postfix **nicht vorhanden** — die Mail
  geht an den Catchall. Das ist dokumentiert (`Catchall` im Vertrag), nicht verhindert.
- Ein Catchall-Ziel **in der eigenen Domain** muss eine vorhandene Adresse sein; sonst fiele es
  selbst auf den Catchall zurück (`address.invalid_destination`).
- `DELETE` auf einen nicht vorhandenen Catchall ist erfolgreich und protokolliert nichts.

**Warum:** Die Postfix-Regel ist gegeben; die Oberfläche muss sie erklären, nicht umgehen.

**Alternative:** Deaktivieren einer Adresse bei vorhandenem Catchall sperren — verworfen, das
Deaktivieren ist gerade der Weg, Mail auf den Catchall umzulenken.

**Korrekturkosten:** Gering. `SetCatchall::requireNoLoop()`, seit U-112 `LoopGuard`.

---

## U-109 — `postmaster` und `abuse` brauchen eine Bestätigung zum Entfernen und Deaktivieren

**Frage:** A3, Punkt 5. Durchsetzen oder warnen?

**Entscheidung:** Entfernen oder Deaktivieren eines **aktiven** Eintrags mit dem Local Part
`postmaster` oder `abuse` (RFC 2142) verlangt `confirm_reserved: true`, sonst `409
address.reserved`. Die übrigen reservierten Namen (`ReservedLocalPart::isReserved()`) werden nur
mit `reserved: true` markiert. Weiterleiten bleibt frei — eine Weiterleitung von `postmaster` an
eine zuständige Person ist der Normalfall.

**Warum:** Ein Fehlklick soll keine Pflichtadresse kosten; ein bewusster Entscheid soll möglich
bleiben, denn manche Domain leitet diese Adressen gezielt anders.

**Alternative:** Hart verbieten — verworfen, der Bestand enthält Domains ohne diese Adressen.

**Korrekturkosten:** Gering.

---

## U-110 — Ein Benutzername, den ein Platzhalter oder eine Domain trägt, ist belegt

**Frage:** Review zu A3. Ein Alias ohne Senderecht trägt als Platzhalter den Domainnamen in
`virtual.username` (U-106). Im Punktschema heißt das Konto `mail` in `example.test` aber
`mail.example.test` — genau so heißt eine Domain `mail.example.test`. Was passiert, wenn beides
zusammenkommt?

**Entscheidung:**

- `AccountStore::isTaken()` zählt einen Benutzernamen als belegt, sobald **irgendeine**
  `virtual`-Zeile ihn in `username` nennt. Ein neues Konto mit diesem Namen bekäme sonst über
  `validsenders` das Senderecht fremder Aliase geschenkt.
- `CreateAccount` lehnt im Punktschema zusätzlich einen Benutzernamen ab, der wie eine
  vorhandene Domain heißt (`DottedNamespace::domainNamedLike()`), denn jeder spätere Alias ohne
  Absender dieser Domain trüge ihn als Platzhalter. Beides ergibt `409 account.name_taken`.
- `RenameDomain` lehnt einen Zielnamen ab, den eine `virtual`-Zeile als `username` nennt
  (`DomainStore::senderNameTaken()`) oder den im Punktschema ein Konto als Benutzernamen trägt —
  `409 domain.namespace_conflict`, wie U-96.
- `AccountRepository::delete()` bleibt unverändert: Es entfernt weiter alle Zeilen, deren
  `username` den Kontonamen nennt (U-83).

**Warum:** Der View `validsenders` verbindet `virtual.username` mit `accountuser`; welche Zeile
ein Platzhalter und welche ein echtes Senderecht ist, steht in der Tabelle nicht. Die einzige
Stelle, an der sich die Verwechslung verhindern lässt, ist das Entstehen des Namens.

**Alternative:** Das Löschen auf Zeilen beschränken, deren `alias` in der Kontodomain liegt —
verworfen. Eine Zeile, die das Konto in `username` nennt, ist nach Bestandssemantik dessen
Senderecht; sie beim Löschen stehen zu lassen, änderte die Bedeutung einer Bestandstabelle
(Eskalationsfall). Mit der neuen Prüfung entsteht die Verwechslung durch mailadmin nicht mehr;
bestehende Kollisionen im Bestand werden nicht bereinigt.

**Korrekturkosten:** Gering. Eine zusätzliche Abfrage je Anlage und Umbenennung.

---

## U-111 — Alias-Zeilen mit unterschiedlichem `status` sind nicht gemeinsam bearbeitbar

**Frage:** Review zu A3. Ein Alias kann aus mehreren Zeilen bestehen (U-105). `PATCH` mit nur
neuen Zielen schrieb alle Zeilen mit dem Status der ersten neu — eine deaktivierte Zeile wurde
dabei stillschweigend aktiv oder umgekehrt.

**Entscheidung:** `AliasState::isEditable()` verlangt zusätzlich zu einem gemeinsamen Absender
einen gemeinsamen `status`. Sonst `409 address.not_editable`; die Zeilen bleiben über die
Adressliste einzeln löschbar.

**Warum:** Wie bei Mischzeilen mit verschiedenen Absendern: lieber nicht bearbeiten als
unbemerkt die Zustellung ändern.

**Alternative:** Den Status je Zeile erhalten — verworfen, `PATCH` schreibt die Adresse als eine
Zeile neu (U-105), und eine Zuordnung alter zu neuen Zielen gibt es nicht.

**Korrekturkosten:** Gering.

---

## U-112 — Schleifen werden wie Postfix aufgelöst; der Catchall behält ein funktionierendes Ziel

**Frage:** Review zu A3. Die Schleifenprüfung (U-104, U-108) erkannte nur eine Adresse, die auf
sich selbst zeigt. `a → b`, `b → a`, eine Weiterleitung zwischen zwei Konten ohne Kopie oder ein
Ziel, das über den Catchall zurückläuft, gingen durch. Und ein Catchall-Ziel in der eigenen
Domain wurde nur beim Setzen des Catchalls geprüft — wer das Ziel danach löschte oder
deaktivierte, ließ die unbekannte Mail der ganzen Domain ins Leere laufen.

**Entscheidung:**

- `LoopGuard` bildet die Auflösung von `virtual_alias_maps` nach (Postfix `cleanup_map1n`): nur
  aktive Zeilen; ohne Treffer der Catchall `@domain`; das erste Ergebnis ersetzt die Adresse,
  weitere werden angehängt; eine Adresse, die sich selbst ergibt, wird nicht weiter aufgelöst.
  Mehr als 1000 Schritte auf einer Position oder mehr als 1000 Adressen sind die Schleife — das
  sind Postfix' Vorgaben (`virtual_alias_recursion_limit`, `virtual_alias_expansion_limit`), bei
  denen die Mail zurückgestellt wird. Die geplante Änderung wird über den Bestand gelegt.
- Geprüft wird beim Anlegen und Ändern eines aktiven Alias, beim Setzen einer aktiven
  Weiterleitung und beim Setzen des Catchalls. Ablehnung: `422 address.invalid_destination` mit
  dem Ziel, das in die Schleife führt.
- Ein Catchall-Ziel in der eigenen Domain muss eine **aktive** Adresse oder ein Kontoname sein;
  eine deaktivierte zählt nicht mehr (Präzisierung von U-108).
- Löschen oder Deaktivieren einer Zeile, auf die der aktive Catchall angewiesen ist —
  `DeleteAlias`, `UpdateAlias`, `DeleteDomainAddress` —, ergibt `409 address.needed_by_catchall`.
  Das gilt nur, wenn der Catchall vorher funktionierte: ein schon defekter Bestandscatchall
  blockiert nicht jede andere Änderung der Domain.
- `SetCatchall::requireNoLoop()` aus U-108 ist darin aufgegangen.

**Warum:** Eine Schleife stellt Postfix nicht zu, sondern stellt zurück und gibt nach Tagen
auf; ein Catchall auf eine nicht vorhandene Adresse lässt jede unbekannte Mail der Domain
abprallen. Beides ist Mailverlust, und beides ist nur an der Tabelle als Ganzes zu erkennen.

**Alternative:** Nur direkte Paare (`a → b → a`) prüfen — verworfen, die Kette über den
Catchall und über Weiterleitungen ist gerade der Fall, der im Bestand vorkommt. Postfix'
Grenzwerte aus der Konfiguration lesen — verworfen, mailadmin kennt die Postfix-Konfiguration
nicht; die Vorgaben sind die engste sichere Annahme, weil eine echte Schleife jede Grenze reißt.

**Korrekturkosten:** Gering. Eine Abfrage je verschiedener Adresse in der Kette.

---

## U-113 — Unter dem Namen `sieve` liegt kein fremdes Skript, das wir überschreiben

**Frage:** Review zu A3. U-102 schützt nur das **aktive** fremde Skript. Liegt ein inaktives
fremdes Skript unter dem Namen, den mailadmin schreibt, ersetzte `PUTSCRIPT` es ohne Rückfrage,
und das Löschen entfernte es.

**Entscheidung:**

- `SieveStore` erhält `script(MailboxIdentity, string $name): ?SieveScript` (ManageSieve
  `GETSCRIPT`), lesend für jedes gespeicherte Skript.
- `SieveScriptInstaller` lehnt Schreiben und Entfernen ab, wenn unter dem Namen `sieve` ein
  Skript ohne unsere Markierung liegt — `sieve.script_foreign`, im Anwendungsfall
  `409 vacation.foreign_script`. Mit Übernahme (`take_over`) wird es ersetzt.
- `VacationScripts` merkt sich das vorher unter dem Namen gespeicherte Skript. Scheitert das
  Protokoll nach einer Übernahme, wird es zurückgelegt; ein eigenes Skript, das vorher nicht da
  war, wird entfernt.

**Warum:** Der Schutz fremder Skripte (M3, U-102) darf nicht davon abhängen, ob das Skript
gerade aktiv ist.

**Alternative:** Unter einem anderen Namen schreiben, wenn `sieve` belegt ist — verworfen, ein
zweiter Name machte jede spätere Erkennung des eigenen Skripts unsicher.

**Korrekturkosten:** Gering. Eine Methode mehr am Port, in beiden Umsetzungen.

---

## U-114 — Kein Steuerzeichen im Betreff der Abwesenheitsnotiz

**Frage:** Review zu A3. Der Betreff landet als Sieve-String in `vacation :subject` und von dort
in einen Mailheader. Ein Zeilenumbruch darin erlaubte zusätzliche Header.

**Entscheidung:** `VacationRule` lehnt Steuerzeichen (`U+0000`–`U+001F`, `U+007F`) im Betreff
ab; der Vertrag beschreibt das mit einem `pattern`. Ablehnung: `422`, `sieve.invalid_rule`. Der
Nachrichtentext bleibt mehrzeilig.

**Warum:** Ob Cyrus Zeilenumbrüche im Betreff selbst entfernt, ist nicht geprüft; die
Ablehnung hängt nicht davon ab.

**Alternative:** Steuerzeichen entfernen — verworfen, eine stillschweigend geänderte Eingabe ist
schwerer zu erklären als eine Ablehnung.

**Korrekturkosten:** Gering.

---

## U-115 — Der Abgleich läuft synchron und speichert nichts

**Frage:** A6, Punkt 1. Der Abgleich listet alle Postfächer und vergleicht sie mit allen
Datenbankzeilen. Synchron im Anfragebetrieb oder als angestoßener Lauf mit gespeichertem
Ergebnis?

**Entscheidung:** Synchron. `GET /maintenance/reconciliation` liest `accountuser`, `domain`,
`virtual` und `lists` mit je einem `SELECT` und Cyrus mit **einem** `LIST "" "user/%"` (U-116)
und antwortet mit dem Bericht. Nichts wird gespeichert, nichts wird geändert — weder in Cyrus
noch in einer Tabelle (E-18). Der Bericht trägt `checked_at`, die Zahl der geprüften Zeilen und
Postfächer, eine Zusammenfassung je Kategorie und die Befunde.

**Warum:** Der Aufwand ist zwei Listen und ein Abgleich im Speicher; beides wächst linear mit
der Zahl der Konten. Für eine Installation der Größe, für die mailadmin gebaut ist, sind das
Sekundenbruchteile. Ein gespeichertes Ergebnis bräuchte eine Tabelle, einen Auslöser und eine
Antwort auf die Frage, wie alt ein Befund sein darf — und ein veralteter Befund ist bei einem
Werkzeug, nach dem jemand handelt, schlimmer als ein langsamer.

**Alternative:** Ein Lauf per CLI oder Cron, Ergebnis in einer Tabelle. Bleibt möglich: der
Anwendungsfall `Reconcile` ist von HTTP unabhängig.

**Korrekturkosten:** Gering. Ein Aufrufer mehr, eine Tabelle mehr; der Abgleich selbst bleibt.

---

## U-116 — Ein `LIST`-Muster deckt beide Namensräume; gelöschte Postfächer fallen heraus

**Frage:** A6, Punkte 2 und 3. Wie werden der flache und der virtdomains-Namensraum abgefragt,
und wie bleiben verzögert gelöschte Postfächer aus der Liste?

**Entscheidung:** Ein `LIST "" "user/%"` mit dem Administratorzugang. Gegen F5 gemessen: die
Antwort enthält die Wurzeln **beider** Schemata, `user/anna.example.test` und
`user/anna@example.test`, denn Cyrus führt das Postfach in beiden Fällen unter
`user/<gespeicherter Benutzername>` (U-38). Gelöschte Postfächer liegen unter `DELETED/…` und
passen nicht auf das Muster; der Adapter filtert sie zusätzlich (M2). Gezählt werden nur
Benutzerwurzeln, keine Unterordner.

Zugeordnet wird **nur über den gespeicherten Namen**: zu jeder Zeile in `accountuser` gehört
genau `user/<username>`. Eine Mailbox wird nie zerlegt, um ihre Domain zu erraten.

**Warum:** Zwei getrennte Abfragen hätten nichts gefunden, was die eine nicht findet, und eine
Zerlegung des Postfachnamens ist genau die Mehrdeutigkeit, die `CLAUDE.md` verbietet.

**Alternative:** Je Domain ein Muster `user/%.<domain>` und `user/%@<domain>` — verworfen: ein
Postfach, dessen Domain es nicht mehr gibt, fiele durch jedes Muster und wäre unsichtbar.

**Korrekturkosten:** Gering. F5 läuft mit `delete_mode: immediate`; ein Zielserver mit
`delayed` wird vom Muster ebenso abgedeckt, der Integrationstest prüft das gelöschte Postfach.

---

## U-117 — Die Reparaturwarteschlange ist eine Tabelle auf eigener Verbindung; das Log bleibt Rückfall

**Frage:** Nicht im Paketdokument gestellt. U-50 hat A1 bis A6 einen Port mit einer Logzeile als
Notnagel gegeben. Wie ersetzt A6 ihn, ohne dass ein Eintrag verloren geht?

**Entscheidung:**

- Migration `0040_repair_queue.sql` legt `mailadmin_repair_queue` an — **rein additiv**, InnoDB,
  utf8mb4. Spalten: was beabsichtigt war, welcher Schritt scheiterte, was erreicht ist, warum,
  die `parameters` als JSON-Objekt aus Strings (U-118), `request_id`, `status`
  (`open`/`resolved`), Wiederholungsversuche mit letztem Fehlercode, Abschluss mit Person,
  Art (`retried`/`dismissed`) und Notiz. Zeilen werden nie gelöscht.
- `RepairQueueRepository` setzt beide Ports um: `Domain\Repair\RepairQueue` (anhängen, für A1–A3)
  und `Application\Maintenance\RepairLedger` (lesen, Versuch zählen, abschließen, für A6).
- **Die Warteschlange schreibt über eine eigene Datenbankverbindung** aus derselben Quelle. Ein
  Eintrag entsteht oft mitten in einem Fehlerpfad, dessen Transaktion gleich zurückgerollt wird;
  auf der geteilten Verbindung verschwände er mit. Die zweite Verbindung öffnet erst, wenn sie
  gebraucht wird.
- **`LoggingRepairQueue` bleibt als Rückfall.** Scheitert das `INSERT` (Tabelle fehlt, Datenbank
  weg), schreibt das Repository den vollständigen Eintrag samt Parametern als `critical`-Zeile.
  Der Port wirft nie.
- Der Abschluss ist ein bedingtes `UPDATE … WHERE status = 'open'`: zwei gleichzeitige
  Abschlüsse ergeben einen Erfolg und ein `409 repair.already_resolved`.

U-50 ist damit **abgelöst**; `config/di/domains.php` bindet den Port nicht mehr,
`config/di/maintenance.php` bindet ihn an das Repository.

**Warum:** `CLAUDE.md` verlangt die Tabelle, nicht das Log. Die eigene Verbindung ist die einzige
Form, in der „was hängen bleibt, gehört in die Warteschlange" auch dann stimmt, wenn der
Fehlerpfad eine Transaktion abbricht.

**Alternative:** Den Eintrag nach dem Rollback schreiben — verworfen, das müsste jede
Aufrufstelle richtig machen; die Verbindung macht es an einer Stelle richtig.

**Korrekturkosten:** Gering. Eine Verbindung mehr je Anfrage, die einen Eintrag schreibt.

---

## U-118 — Wiederholbar ist, was seinen Zustand selbst prüfen kann

**Frage:** A6, Punkt 5. Welche Vorgänge sind sicher wiederholbar, und woher weiß ein Eintrag,
ob er es ist?

**Entscheidung:** `RepairRequest` trägt seit A6 ein optionales Feld `parameters` (Strings,
gespeicherte Namen, nie zerlegt). `RepairCatalogue` legt fest, welche Schritte die Anwendung
selbst abschließen kann und welche Parameter sie dafür braucht. Ein Eintrag ist wiederholbar,
wenn sein Schritt im Katalog steht **und** alle Parameter da sind; die API zeigt das als
`retryable`.

| Schritt | wiederholbar | Vorbedingung, sonst `409 repair.state_changed` |
|---|---|---|
| `mailstore.default_folders` | ja | Zeile und Postfach existieren; dann Ordner anlegen und abonnieren |
| `mailstore.delete_mailbox` | ja | unter Namensraum- und Domainsperre (U-97): kein Konto beansprucht den Namen wieder |
| `mailstore.restore_quota` | ja | Zeile existiert, das Limit in Cyrus ist noch das geänderte (`current_kib`) |
| `mailstore.rename_back` | ja | unter der Namensraumsperre: Zeile unter dem alten Namen, keine unter dem neuen, Postfach nur unter dem neuen |
| `mailstore.create_mailbox_uncertain` | nein | ob das Postfach uns gehört, weiß nur ein Mensch |
| `database.remove_rows` | nein | Zeilen löschen ist zustellungsrelevant |
| `audit.record` | nein | ein Protokolleintrag lässt sich nicht nachträglich wahrheitsgemäß schreiben |
| `virtual.restore` | nein | Adresszeilen; der alte Zustand steht im Eintrag, nicht maschinenlesbar |
| `sieve.restore` | nein | ein Skript, ggf. ein fremdes (U-102) |

Ein Eintrag ohne Parameter — etwa aus der Zeit des Logs — ist nie wiederholbar. Was nicht
wiederholbar ist, schließt ein Superuser mit `POST …/resolve` als `dismissed` ab, mit Notiz.
Ein gescheiterter Versuch lässt den Eintrag offen, zählt `attempts` hoch und hält den
Fehlercode in `last_error`; beides wird als `repair.retry` protokolliert. Ist der Mailstore
nicht erreichbar, antwortet die Wiederholung `503`, sonst `409 repair.retry_failed`.

**Warum:** „Anlegen ja, Löschen mit Vorsicht" heißt praktisch: jeder Schritt prüft vorher, ob
die Welt noch die ist, die der Eintrag beschreibt. Das Löschen eines Postfachs, dessen Namen
inzwischen ein neues Konto trägt, wäre Datenverlust; deshalb läuft die Prüfung unter derselben
Sperre wie die Kontoanlage.

**Alternative:** Wiederholbarkeit beim Schreiben des Eintrags festlegen — verworfen, sie hängt
am Zustand zum Zeitpunkt der Wiederholung, nicht des Fehlers.

**Korrekturkosten:** Gering. Ein Schritt mehr im Katalog ist eine Methode in `RetryRepair`.

---

## U-119 — Acht Kategorien; was sich nicht zuordnen lässt, wird gezeigt, nicht geraten

**Frage:** A6, Punkt 4. Der Vorschlag nennt vier Kategorien; vollständig machen.

**Entscheidung:**

| Kategorie | Bedeutung |
|---|---|
| `account_without_mailbox` | Zeile in `accountuser`, kein `user/<username>` in Cyrus |
| `mailbox_without_account` | Benutzerwurzel in Cyrus, die keine Zeile beansprucht |
| `shadow_mailbox` | das Postfach des **anderen** Schemas neben einem Konto (`reason` nennt das Schema) |
| `unresolvable_account` | Benutzername passt nicht zu `domain_name` (U-22); sein Postfach gilt als beansprucht |
| `ambiguous_username` | Punktname, den eine bestehende Subdomain ebenfalls erreicht (U-96) |
| `virtual_owner_missing` | `virtual.username` nennt weder ein Konto noch einen Domainplatzhalter (U-106) |
| `virtual_target_missing` | ein Ziel ohne `@`, das kein Konto ist (`no_account`), oder eine Adresse in einer lokal zugestellten Domain, die nichts beantwortet (`no_address`) |
| `virtual_unclassified` | eine Zeile, die in keines der vier Muster passt (`reason` aus F4) |

- **Punktnamen in `virtual.dest` werden so gemeldet, wie sie dastehen.** A1 nimmt an, dass ein
  Ziel ohne `@` ein gespeicherter Benutzername ist; gibt es ihn nicht, wird er nicht in Local
  Part und Domain zerlegt, um ein „gemeintes" Konto zu finden.
- Die Domain eines Befunds ist die der Alias-Adresse oder der Zeile, nie eine aus einem
  Postfach- oder Zielnamen gelesene. Ein verwaistes Postfach hat keine Domain.
- „Beantwortet" heißt: Kontoadresse oder Benutzername, Alias-Schlüssel in `virtual`,
  Listenadresse aus `lists` (E-9) oder ein Catchall der Domain. Ziele in Domains, deren
  `transport` nicht `cyrus` ist, und fremde Domains werden nicht bewertet.
- Die Cyrus-Adminzeile (leere `domain_name`) ist kein Befund, ihr Postfach auch nicht.

**Warum:** Der Abgleich soll das zeigen, was das Altsystem übersah, und zwar so, dass ein
Mensch danach handeln kann. Eine geratene Zuordnung sähe aus wie ein Befund und wäre keiner.

**Alternative:** Nur die vier vorgeschlagenen Kategorien — verworfen: drei der übrigen vier
(`unresolvable_account`, `ambiguous_username`, `virtual_unclassified`) beschreiben Zustände, die
U-22, U-96 und F4 ausdrücklich „anzeigen, nicht übergehen" verlangen.

**Korrekturkosten:** Gering. Eine Kategorie ist ein Enum-Fall im Code und im Vertrag.

---

## U-120 — Kein Sieve-Abgleich

**Frage:** Die Aufgabe nennt Sieve „wo relevant". Gehört ein Abgleich der Sieve-Skripte dazu?

**Entscheidung:** Nein. Der Abgleich vergleicht nur, was es auf beiden Seiten gibt. Für Sieve
hält die Datenbank nichts, was ein Skript spiegelt: die Abwesenheitsnotiz wird aus dem Skript
gelesen (U-102, U-107), nicht aus einer Tabelle. Ein Durchlauf bräuchte je Konto eine
ManageSieve-Sitzung über Proxy-Auth und fände keine Abweichung, sondern nur Zustand. Die
Erreichbarkeit von ManageSieve meldet die Zustandsprüfung (U-121).

**Warum:** Ein Abgleich ohne Gegenseite ist ein Inventar, und das gibt es je Konto schon unter
`/accounts/{account}/vacation`.

**Alternative:** Je Konto prüfen, ob ein fremdes aktives Skript liegt — möglich, aber kein
Abgleich und mit einer Sitzung je Konto teuer. Bei Bedarf als eigener Bericht nachrüstbar.

**Korrekturkosten:** Gering.

---

## U-121 — Zustandsprüfung: echte Anmeldung an IMAP, TLS an ManageSieve; `/health` bleibt knapp

**Frage:** A6, Punkte 6 und 7. Nur Erreichbarkeit oder echte Anmeldung? Darf `/health` ohne
Anmeldung erreichbar sein?

**Entscheidung:**

- Drei Prüfungen, getrennt gemeldet: **Datenbank** `SELECT 1`; **IMAP** Anmeldung als
  Administrator und `NOOP`; **ManageSieve** Verbindung, `STARTTLS` mit Zertifikatsprüfung und
  der Nachweis, dass danach `PLAIN` angeboten wird — aber **keine Anmeldung**. Gegen F5
  gemessen: timsieved lehnt die eigene Sitzung des Administrators ab („Authentication
  Error"), nur Proxy-Auth geht, und eine Prüfung im Namen eines Kontos hinge an diesem Konto.
  Die Zugangsdaten beweist die IMAP-Prüfung gegen dasselbe SASL-Backend.
- Jede Prüfung wirft nie; ein Fehler wird `error` mit dem stabilen Code der Ausnahme, eine
  fremde Ausnahme `request.service_unavailable`. Meldungstexte verlassen den Prüfer nicht. Der
  Client wird in der Prüfung gebaut, damit eine fehlende Einstellung ein `error` für diese eine
  Komponente ist und keinen Containerfehler auslöst.
- **`GET /health` bleibt öffentlich** (U-9) und antwortet nur `status` und je Komponente
  `component`/`status`: `200` wenn alles geht, sonst `503` mit `degraded`. Das Ergebnis wird
  **zehn Sekunden** in `health.json` im Cache-Verzeichnis gehalten (atomar geschrieben, jeder
  Lesefehler heißt „nicht gecacht"), damit eine Überwachung im Sekundentakt Cyrus nicht mit
  Anmeldungen belastet. Keine Fehlercodes, keine Zeiten, keine Hostnamen.
- **`GET /maintenance/health`** (Superuser, `maintenance.read`) prüft frisch und liefert
  zusätzlich `code`, `duration_ms` und `checked_at`; es antwortet immer `200`.

**Warum:** Eine echte Anmeldung ist die einzige Prüfung, die eine falsche Konfiguration oder
abgelaufene Zugangsdaten findet; der Cache macht sie bezahlbar. Die öffentliche Antwort verrät
nur, was eine Überwachung braucht, um Alarm zu schlagen.

**Alternative:** `/health` nur angemeldet — verworfen, ein Überwachungssystem müsste dann ein
Token halten und erneuern. ManageSieve mit Proxy-Auth für ein festes Konto — verworfen, die
Prüfung hinge an einem Konto, das jemand löschen kann.

**Korrekturkosten:** Gering. Die Tiefe einer Prüfung ist eine Closure in
`config/di/maintenance.php`.

---

## U-122 — Fehlercodes mit Präfix `repair`

**Frage:** U-7 zählt die erlaubten Präfixe auf; `repair` ist nicht darunter.

**Entscheidung:** `repair` kommt hinzu, mit vier Codes: `repair.already_resolved`,
`repair.not_retryable`, `repair.state_changed` (alle `409`) und `repair.retry_failed` (`409`,
oder `503` wenn der Mailstore nicht erreichbar ist). Ein unbekannter Eintrag ist
`request.not_found`. `ContractConventionsTest` und `openapi/README.md` sind ergänzt, die
deutschen Texte stehen in `de.errors.ts`.

**Warum:** Die Zustände gehören der Warteschlange, nicht einem Konto oder dem Mailstore — ein
`mailstore.*`-Code für „der Eintrag ist schon erledigt" wäre irreführend.

**Alternative:** `request.conflict` für alles — verworfen, die Oberfläche muss „nicht
wiederholbar, bitte von Hand" von „Zustand hat sich geändert" unterscheiden können.

**Korrekturkosten:** Gering.

---

## U-123 — `/health` prüft nie zweimal gleichzeitig und schlägt ohne Cache geschlossen fehl

**Frage:** Nachbesserung zu U-121 aus dem Review. Das Zehn-Sekunden-Fenster begrenzt nur
Anfragen, die nacheinander kommen. Jede Anfrage, die die Datei abgelaufen findet, während eine
langsame Prüfrunde noch läuft — Cyrus hängt bis in die Zeitgrenzen —, startete eine eigene
Runde, also eine eigene Administratoranmeldung. Wie bleibt der öffentliche Endpunkt bei
gleichzeitigen Anfragen billig?

**Entscheidung:**

- **Eine Runde zur Zeit.** `FileHealthReportCache` nimmt ein nicht blockierendes `flock()` auf
  `health.lock` neben `health.json`. Nur wer es bekommt, prüft; liest danach unter der Sperre
  noch einmal, falls die vorige Runde gerade geschrieben hat. Alle anderen antworten mit dem
  Bericht aus der Datei, auch wenn er älter als zehn Sekunden ist.
- **Ohne jeden Bericht** — Kaltstart — wartet eine Anfrage höchstens 15 Sekunden auf die
  laufende Runde und liest dann deren Ergebnis; sie prüft nie selbst.
- **Ohne Sperrdatei schlägt die Antwort geschlossen fehl:** jede Komponente `error` mit
  `request.service_unavailable`, also `503`, keine Prüfung, eine Warnung im Log. Der Start
  verweigert ein fehlendes oder schreibgeschütztes Cache-Verzeichnis ohnehin (U-13); dieser
  Pfad ist ein ungewöhnlicher Fehler, kein Konfigurationsfehler.
- **Frisch heißt `0 <= Alter < 10 s`.** Ein Bericht aus der Zukunft (Uhr zurückgestellt,
  geteiltes Verzeichnis mit verstellter Uhr) gilt als abgelaufen, sonst hielte er ein altes
  `ok` am Leben. Eine Temporärdatei, deren `rename` scheitert, wird entfernt.

`components/health.yaml` sagt, dass die Antwort während einer Runde älter sein kann.

**Warum:** Der Endpunkt braucht kein Token. Die Zusage aus U-121 — kein Strom von
Administratoranmeldungen — muss auch für gleichzeitige Anfragen gelten, und gerade dann, wenn
eine Abhängigkeit ohnehin schwächelt.

**Alternative:** Bei gesperrter Runde blockierend warten — verworfen, jede wartende Anfrage
hielte einen PHP-Worker für die Dauer der Runde. Bei fehlendem Cache nur die Datenbank prüfen —
verworfen, IMAP und ManageSieve stünden dann ungeprüft auf `skipped`, und `skipped` ist nicht
`degraded` (U-121): ein toter Cyrus sähe gesund aus.

**Korrekturkosten:** Gering. Wartezeit und Fenster sind Konstruktorparameter.

---

## U-124 — Abgleich: Zielerweiterung, abgeschaltete Zeilen, keine Folgebefunde

**Frage:** Nachbesserung zu U-119 aus dem Review. Drei Stellen meldeten etwas, das kein
eigenständiges Problem ist, oder übersahen eines.

**Entscheidung:**

- **`recipient_delimiter`.** Ein Ziel `anna+news@example.test` gilt als beantwortet, wenn
  `anna@example.test` beantwortet ist — Postfix fällt mit `recipient_delimiter = +` genau darauf
  zurück. Geprüft wird zuerst die Adresse wie geschrieben, dann ohne Erweiterung; gemeldet wird
  sie immer wie geschrieben. Das ist die ausdrückliche Nachfrage nach dem Basisteil, die F4
  Punkt 6 einem Anwendungsfall erlaubt; das Modell schneidet weiterhin nichts von selbst ab.
- **Abgeschaltete Zeilen beantworten nichts.** Eine Zeile mit `status = 0` liefert Postfix nicht
  aus; sie zählt weder als Alias-Schlüssel noch als Catchall. Ein Ziel, das nur sie beantwortet,
  wird als `virtual_target_missing` gemeldet.
- **Ein Konto, das schon als `unresolvable_account` gemeldet ist, erzeugt keine Folgebefunde.**
  Sein gespeicherter Name gilt als beantwortet; ein Ziel im `fqun`-Schema, das darauf zeigt,
  ist dasselbe Problem. Wie bei seinem Postfach (U-119) wird es einmal gemeldet.

Nicht zutreffend war die Annahme, die Adresszeile eines solchen Kontos fiele als
`virtual_unclassified` durch: der Klassifizierer liest sie ohne Domain des Kontos als Alias
(F4), und ihr `username` steht in der Kontenliste.

**Warum:** Ein Befund, der kein Problem ist, lehrt die Leser, Befunde zu übergehen; einer, der
fehlt, versteckt eine Adresse ohne Zustellung.

**Alternative:** Die Erweiterung konfigurierbar machen — verworfen, der Trenner ist im Modell
festgelegt (`LocalPart::RECIPIENT_DELIMITER`); weicht ein Server ab, ist es eine Stelle.

**Korrekturkosten:** Gering.

---

## U-125 — Wiederholen und Abschließen eines Eintrags halten dessen Sperre; Wiederholung ist idempotent

**Frage:** Nachbesserung zu U-117/U-118 aus dem Review. `RetryRepair` las den Eintrag, handelte
in Cyrus und schloss ihn erst danach — ohne das Ergebnis des bedingten `UPDATE` zu prüfen. Ein
Superuser konnte den Eintrag dazwischen als `dismissed` abschließen („Postfach behalten"), und
die Wiederholung löschte es trotzdem und protokollierte Erfolg. Und: Ging der Schritt durch, aber
das Abschließen nicht, sah die nächste Wiederholung bei Quota und Rückbenennung den neuen Zustand
und lehnte für immer mit `repair.state_changed` ab.

**Entscheidung:**

- `RepairLedger::withEntryLock()` — ein `GET_LOCK` `mailadmin.repair.<id>` auf der eigenen
  Verbindung der Warteschlange, wie die Sperren aus U-81 und U-97. **Wiederholen und
  Abschließen nehmen sie beide** und lesen den Eintrag darunter neu. Zwei Wiederholungen oder
  Wiederholung und Abschluss handeln nie beide an einem Eintrag; wer zu spät kommt, bekommt
  `409 repair.already_resolved`. Ist die Sperre zehn Sekunden nicht frei, ist das ein
  Datenbankfehler wie bei U-81.
- Meldet `resolve()` dennoch „nicht mehr offen" — unter der Sperre nur durch eine Änderung von
  Hand möglich —, gibt es keinen Erfolgseintrag im Protokoll, eine Warnung im Log und `409`.
- `recordAttempt()` zählt nur an offenen Einträgen (`AND status = 'open'`).
- **Idempotent:** Steht das Limit in Cyrus schon auf `limit_kib`, schließt `restore_quota` nur
  ab. Steht die Datenbank unter dem alten Namen allein und liegt das Postfach nur unter dem
  alten Namen, schließt `rename_back` nur ab. `delete_mailbox` und `default_folders` waren es
  schon.

Kein Zeilensperren mit `SELECT … FOR UPDATE`: das hielte auf der Warteschlangenverbindung eine
Transaktion für die Dauer einer Cyrus-Operation offen, und ein Eintrag, den eine andere Operation
währenddessen schreibt, wartete auf deren Ende. Kein neuer Status „in Arbeit": er bliebe nach
einem Absturz mitten in der Wiederholung stehen, eine Sperre geht mit der Verbindung.

**Warum:** Ein abgeschlossener Eintrag ist eine Entscheidung eines Menschen; eine Wiederholung
darf sie nicht überholen. Und ein Eintrag, dessen Arbeit getan ist, muss sich schließen lassen,
ohne dass jemand von Hand `dismissed` wählt und `attempts` einen Fehlschlag zeigt, den es nicht
gab.

**Korrekturkosten:** Gering. Eine Methode am Port, eine Sperre je Aufruf.

---

## U-126 — Ein Schlüssel ohne `@` gehört der Domain des Kontos, das er nennt

**Frage:** Nachbesserung zu U-103 aus dem Review. Regel (3) — `alias` ohne `@`, `username` nennt
ein Konto der Domain — ordnet eine Zeile nach ihrem **Absender** zu. Ein `alias` ohne `@` ist
aber ein **Nachschlageschlüssel**: im Punktschema ist `bob.other.test` der Schlüssel, unter dem
Postfix die Mail des Kontos `bob.other.test` in `other.test` weiterreicht. Eine Altzeile
`bob.other.test → …` mit `username = anna.example.test` erschien in der Adressliste von
`example.test`, und deren Domainmaster konnte sie über `DELETE /domains/example.test/addresses/{entry}`
löschen — und damit die Zustellung einer fremden Domain ändern.

**Entscheidung:**

- **Der Schlüssel entscheidet, nicht der Absender.** Eine Zeile mit `alias` ohne `@` gehört zur
  Domain,
  - (2) deren Konto der Schlüssel nennt — **ohne Rücksicht auf Groß- und Kleinschreibung**, wie
    Postfix nachschlägt, und **gleich, was `username` trägt**. Das ersetzt die alte Regel (2)
    (byteweise, nur mit leerem `username`) und schließt sie ein;
  - (3) deren Konto `username` nennt, **wenn der Schlüssel überhaupt kein Konto nennt**. Nennt er
    ein Konto einer anderen Domain, gehört die Zeile dorthin (Regel 2) und wird dort angezeigt,
    hier nicht.
- **Angezeigt wird weiterhin alles** (CLAUDE.md): jede Zeile, die früher unter (3) erschien,
  erscheint jetzt unter der Domain ihres Schlüssels, eine Schreibvariante des eigenen Schlüssels
  zusätzlich unter der eigenen Domain. Weggefallen ist keine.
- **Die Einzellöschung prüft den Schlüssel noch einmal**, unabhängig von der Liste: Nennt er
  Konten, muss **jede** dieser Domains im Zuschnitt des Akteurs liegen, sonst `404` wie für jedes
  fremde Objekt — auch bei zwei Konten in zwei Domains, die sich nur in der Schreibweise
  unterscheiden: der Schlüssel leitet für beide. Nennt er **kein** Konto, gehört er keiner Domain; dann löscht
  nur ein Superuser, sonst `409 address.not_editable`. Kein `404`, weil die Zeile in der Liste
  der Domain sichtbar ist — sie ist dem Akteur nicht fremd, sie gehört nur niemandem. Kein neuer
  Fehlercode.
- **A2s Kontolöschung bleibt byteweise** (`forwardRowsOf()`): eine Schreibvariante des
  Schlüssels überlebt das Konto, steht aber jetzt in der Liste der Domain und ist dort
  einzeln löschbar.
- **A6s Abgleich ist nicht betroffen:** er liest alle Zeilen (`virtualRows()`), ordnet nur über den
  Teil nach dem `@` einer Domain zu und ist dem Superuser vorbehalten. `addressCount()` für
  `domain.still_has_addresses` zählt nur Regel (1).

**Warum:** Wohin Mail für einen Schlüssel geht, ist Sache dessen, dem der Schlüssel gehört. Der
Absender einer Zeile gibt einem Konto ein Senderecht, aber keine Verfügung über den Schlüssel.
Ein Schlüssel ohne Konto kann trotzdem Teil einer Kette sein, die eine fremde Domain über `dest`
aufbaut; ob das so ist, sieht nur, wer alle Domains sieht.

**Alternative:** Regel (3) streichen — verworfen, die Zeilen verschwänden aus jeder Liste.
Zeilen nur unter (3) ganz für Domainmaster sperren, auch wenn der Schlüssel ein eigenes Konto
nennt — verworfen, die eigene Weiterleitung mit einem Absender wäre dann nicht mehr löschbar.

**Korrekturkosten:** Gering. `VirtualRepository::rowsOfDomain()`, `InMemoryVirtualStore`,
`AddressReader::domainsOfKey()`, `DeleteDomainAddress`.

---

## U-127 — Konten werden über `allusers` gesperrt; die Statustabelle ist `mailadmin_account_status`

**Frage:** Ersetzt die Eskalation aus U-78 und schließt O-17. Der Projekteigner hat freigegeben,
den View `allusers` um einen Statusfilter aus einer neuen `mailadmin_`-Tabelle zu erweitern —
und nur das: keine Änderung an Bestandstabellen, keine an `validsenders`.

**Entscheidung:**

- **Neue Tabelle `mailadmin_account_status`** (Migration 0060, Bereich 0060–0069 für A2, U-6).
  Eine Zeile je Konto, das je gesperrt wurde: `username` (Schlüssel, `latin1`/`latin1_bin` wie
  `accountuser.username`, damit der View Bytes vergleicht), `disabled`, `disabled_at`,
  `disabled_by`, `updated_at`, `updated_by`. Keine Zeile heißt „aktiv". Beim Entsperren bleibt
  die Zeile mit `disabled = 0` stehen, damit die letzte Änderung sichtbar bleibt. `accountuser`
  bleibt unberührt.
- **Der View** bekommt in **beiden** Zweigen der `UNION` denselben Zusatz:
  `LEFT JOIN mailadmin_account_status ON … = accountuser.username WHERE … username IS NULL OR
  … disabled = 0`. Für jedes nicht gesperrte Konto liefert er Zeile für Zeile und Spalte für
  Spalte dasselbe wie vorher, einschließlich des `REPLACE`-Fehlers im Adresszweig.
  `tests/Integration/TestEnvironment/AccountStatusViewTest.php` vergleicht den neuen View mit der
  alten Definition, wörtlich, auf den Fixtures: ohne Statuszeile identisch, mit einer gesperrten
  Zeile genau ohne die Zeilen dieses Kontos.
- **Reihenfolge beim Ausrollen ist Pflicht:** erst die Migration, dann der View. Der neue View
  auf einer Datenbank ohne die Tabelle ist ungültig — `pam_mysql` bekommt einen Fehler, und
  **niemand** kann sich mehr anmelden. Der alte View neben der neuen Tabelle ist dagegen
  harmlos; er wertet sie nur nicht aus. Die Vorlage für Salt steht in
  `docs/deployment/allusers.sql.example`, mit Rückweg.
- **API:** `PATCH /accounts/{account}` nimmt `enabled` (neben oder statt `services`). Das Konto
  trägt `enabled`, `disabled_at` und `disabled_by`. Berechtigung wie bei den Dienstschaltern:
  `account.write`, also auch Domainmaster für die eigene Domain; ein fremdes Konto ergibt `404`.
  Audit: `account.disable` bzw. `account.enable`, getrennt von `account.update`.
- **Keine Sperre, die nicht sperrt.** Nach dem Schreiben prüft der Anwendungsfall in derselben
  Transaktion, ob `allusers` das Konto noch liefert. Tut er es, läuft dort der alte View: die
  Änderung wird zurückgenommen und mit `409 account.status_not_enforced` abgelehnt. Die
  Oberfläche kann so nie „gesperrt" anzeigen, solange Salt den View nicht ausgerollt hat.
- **`bin/db-check.php`** meldet, ob die Tabelle da ist, ob beide `username`-Spalten dieselbe
  Kollation haben und ob der ausgerollte View die Tabelle liest — aus der Definition, wenn der
  Datenbanknutzer `SHOW VIEW` hat, sonst am Verhalten eines gesperrten Kontos. Ein View, der die
  Tabelle liest, die es nicht gibt, ist ein kritischer Befund.
- **Was die Sperre bewirkt, gegen F5 geprüft** (`AccountLifecycleTest`, beide Namensschemata):
  IMAP-Anmeldung unter Benutzername und Adresse und SMTP-AUTH unter beiden schlagen fehl, nach
  dem Entsperren funktionieren sie wieder. POP und ManageSieve laufen über dieselbe Kette
  (`saslauthd` → `pam_mysql` → `allusers`). Eine Anmeldung an `allusers` vorbei gibt es nicht;
  die einzige Ausnahme ist die Proxy-Anmeldung des Cyrus-Administrators (`authzid`), mit der
  mailadmin selbst Ordner und Sieve verwaltet — sie prüft nur den Administrator und soll das auch.
- **Was die Sperre nicht bewirkt:**
  - **Die Zustellung läuft weiter.** Postfix liest `virtual`, nicht `allusers`; das Postfach
    füllt sich weiter, ein Catchall oder Alias auf das Konto wirkt weiter. Wer auch die Zustellung
    stoppen will, nimmt die Adresse (A3) oder löscht das Konto.
  - Schon offene IMAP-Sitzungen bleiben offen, bis der Client sie schließt.
  - Läuft `saslauthd` mit Credential-Cache (`-c`), gilt ein zwischengespeichertes Passwort bis
    zum Ablauf des Caches weiter. Auf dem Zielserver zu prüfen (O-21).
  - Die Dienstschalter `imap`/`pop`/`sieve`/`smtpauth` bleiben, was sie nach U-78 sind:
    getragen, nicht durchgesetzt.
- **Lebenszyklus der Zeile:** Das Löschen eines Kontos entfernt seine Statuszeile, die Anlage
  entfernt eine liegengebliebene (ein Konto, das webcyradm gelöscht hat, darf seinem Nachfolger
  gleichen Namens keine Sperre vererben), die Domainumbenennung zieht sie mit dem Benutzernamen
  um.

**Warum:** Eine Sperre muss an der Stelle greifen, an der angemeldet wird, und das ist allein
`allusers`. Eine eigene Tabelle statt einer Spalte hält `accountuser` unverändert (CLAUDE.md,
Regel 1); `LEFT JOIN` mit `IS NULL` macht „keine Zeile" zu „aktiv", sodass die Migration ohne
Datenübernahme auskommt. Die Prüfung nach dem Schreiben verhindert, dass Anwendung und
Mailserver über den Zustand eines Kontos verschiedener Meinung sind.

**Alternative:** Ein Hashpräfix (`!`) in `accountuser.password` — verworfen, Eingriff in das, was
`crypt(3)` liest, und nicht freigegeben. Eine Spalte in `accountuser` — verworfen, Bestandstabelle.
`validsenders` ebenfalls filtern — nicht freigegeben, und ohne Anmeldung gibt es ohnehin keinen
authentifizierten Absender.

**Korrekturkosten:** In mailadmin gering. Der View liegt bei Salt; ein Rückweg ist der alte View,
die Tabelle kann bleiben.

---

## U-128 — Der Catchall geht mit dem Konto

**Frage:** O-19. `DeleteAccount` entfernt die `virtual`-Zeilen eines Kontos, ohne den Catchall
der Domain anzusehen. Zeigt `@domain` auf das Konto, läuft danach jede unbekannte Mail der Domain
auf ein Postfach, das es nicht mehr gibt. Schlimmer: Ein Bestandscatchall, dessen `username` das
Konto nennt (webcyradm trug dort das Konto des ersten Ziels ein), fiel dem `WHERE username = …`
der Löschung ganz zum Opfer — samt Zielen, die woanders hin zeigten.

**Entscheidung:** Der Catchall wird im **selben Datenbankschritt** wie das Konto bereinigt, nicht
abgelehnt.

- **Ein Ziel ist tot**, wenn es — ohne Rücksicht auf Groß- und Kleinschreibung, wie Postfix
  nachschlägt — (1) der gespeicherte Benutzername des Kontos ist, oder (2) eine Adresse, deren
  Zeilen alle mit dem Konto gehen (die Kontoadresse, ein Alias mit dem Konto als Absender, ein
  Weiterleitungsschlüssel), und für die danach keine andere aktive Zeile und kein anderes Konto
  mehr antwortet. Alles andere bleibt, auch Ziele, die vorher schon nicht funktionierten.
- **Bleiben Ziele übrig**, wird nur das tote entfernt, der Catchall bleibt (Komma ohne
  Leerzeichen, U-104). **Bleibt keines**, wird die Zeile gelöscht.
- **Nennt `username` des Catchalls das Konto**, wird es geleert: das Senderecht endet mit dem
  Konto. Bliebe es stehen, hielte die Zeile den Namen belegt (U-110) und gäbe einem späteren
  Konto gleichen Namens ein Senderecht mit. Die Ziele bleiben.
- Aktive und abgeschaltete Catchall-Zeilen gleichermaßen: Eine abgeschaltete, die noch auf das
  Konto zeigte, liefe beim Wiedereinschalten ins Leere.
- **Reihenfolge im Schritt:** erst die Ersatzzeilen einfügen, dann die alten entfernen (wie A3,
  U-105), dann das Konto, dann das Audit. Alles unter der Namensraumsperre, die auch A3s
  Catchall-Schreibvorgänge nehmen (U-97); vorher wird gezählt, ob die gelesenen Zeilen noch da
  sind — sonst `409 request.conflict`, nichts geändert. Scheitert der Schritt und das Konto steht
  noch, werden die Catchall-Zeilen zurückgesetzt wie in A3 (`RowWriter::restore()`), ein
  misslungenes Zurücksetzen landet in der Reparaturwarteschlange.
- **Audit:** `account.delete` wie bisher, dazu ein Kindeintrag (`partOf`) `catchall.set` oder
  `catchall.delete` mit dem Catchall vorher, nachher und der Liste `removed_destinations`.
- **Nur der Catchall der eigenen Domain.** Ein Catchall einer anderen Domain, der auf das Konto
  zeigt, bleibt unverändert — ihn zu ändern hieße, in eine Domain einzugreifen, die der Akteur
  vielleicht nicht verwaltet (O-20).

**Warum:** Ablehnen hätte das Löschen eines Kontos davon abhängig gemacht, dass vorher jemand den
Catchall umstellt — für ein Ziel, das ohnehin tot ist, sobald das Konto geht. Den Catchall
stillschweigend auf ein Ziel ohne Postfach zeigen zu lassen, ist dagegen Mailverlust.
`LoopGuard::requireCatchallKeepsWorking()` beantwortet nur „darf das weg?"; hier steht fest,
dass das Konto geht, und gefragt ist, was vom Catchall bleibt. Die Bausteine aus A3 —
`CatchallState`, `AliasRecord::ofCatchall()`, `AddressReader`, `RowWriter` — sind dieselben.

**Alternative:** Das Löschen mit `409 address.needed_by_catchall` ablehnen — verworfen, s. o.
Den ganzen Catchall entfernen, auch wenn andere Ziele bleiben — verworfen, nimmt der Domain ohne
Not die Zustellung unbekannter Adressen an funktionierende Ziele.

**Korrekturkosten:** Gering. `CatchallCleanup`, `CatchallCleanupPlan`, `DeleteAccount`.

---

## U-129 — Nachbesserungen zu U-127 und U-128 aus dem Review

**Frage:** Vier Befunde zur Kontosperre und zur Catchall-Bereinigung.

**Entscheidung:**

- **Domainumbenennung: der Status steht vor der Kontozeile unter dem neuen Namen.** `rename()`
  schrieb erst `accountuser` (MyISAM, keine Rücknahme) und zog danach die Statuszeile (InnoDB)
  um. Scheiterte danach irgendeine Anweisung der Transaktion, stand das Konto unter dem neuen
  Namen ohne Statuszeile — gesperrt war es dann nicht mehr; zwischen den beiden Anweisungen gab
  es außerdem einen kurzen Moment ohne Sperre. Jetzt:
  - `RenameDomain` ruft unter der Namensraumsperre, **vor** der Transaktion,
    `DomainStore::carryAccountStatus()` auf. Das gibt jedem neuen Benutzernamen die Statuszeile
    des alten und wird für sich festgeschrieben. Hat der alte Name keine Zeile, wird eine unter
    dem neuen Namen liegengebliebene gelöscht — sie stammt von einem außerhalb von mailadmin
    gelöschten Konto und hätte sonst über das umziehende Konto entschieden (bisher sperrte sie
    es, oder die Umbenennung scheiterte am doppelten Schlüssel).
  - `rename()` wiederholt das als ersten Schritt in der Transaktion (für sich allein aufgerufen
    bleibt es vollständig) und **löscht** die Zeile unter dem alten Namen erst nach dem Umzug der
    Kontozeile, statt sie umzubenennen.
  - Scheitert die Transaktion, stehen danach beide Namen gesperrt — gleich, unter welchem Namen
    MyISAM die Kontozeile hinterlassen hat. Eine Zeile unter dem nicht erreichten neuen Namen
    ist harmlos; `bin/db-check.php` zählt sie unter „status rows without account", die Anlage
    eines Kontos dieses Namens räumt sie weg.
- **`bin/db-check.php` prüft jeden Zweig der `UNION`.** Bisher genügte, dass die Tabelle
  irgendwo in der Definition stand, und das Verhalten wurde nur unter dem gespeicherten
  Benutzernamen geprüft, den allein der zweite Zweig liefert. Ein View, der nur einen Zweig
  filtert, galt als „honoured", obwohl sich das gesperrte Konto unter der anderen Schreibweise
  weiter anmelden kann. Jetzt (`AccountStatusEnforcement`): jeder Zweig muss die Tabelle nennen,
  sonst eine Warnung „only N of M UNION branches"; und das Verhalten wird unter dem gespeicherten
  Namen **und** unter der Adressform geprüft, die der View daraus bildet (samt
  `REPLACE`-Eigenheit). Die Testdatenbank `mail_test` bekommt dafür `CREATE VIEW` und `SHOW
  VIEW`; `mail` nicht.
- **Kollation: verworfen.** Der Befund nahm an, eine andere `latin1`-Kollation bei
  `accountuser.username` (etwa die Vorgabe `latin1_swedish_ci`) führe im Join zu „Illegal mix of
  collations". Gegen MariaDB 11.4 gemessen: Bei gleichem Zeichensatz gewinnt die
  `_bin`-Kollation, der Join vergleicht Bytes und wirft keinen Fehler — weder im View noch in
  `AccountRepository`. Ein Integrationstest hält das fest. `bin/db-check.php` meldet diesen Fall
  nur noch als Hinweis, einen anderen Zeichensatz weiter als Warnung. Die Migration 0060 bleibt
  unverändert (Prüfsumme).
- **Kontolöschung lässt Zeilen fremder Domains stehen.** `AccountRepository::delete()` löschte
  jede `virtual`-Zeile mit dem Konto als `username` — auch den Catchall oder einen Alias einer
  anderen Domain, die das Konto nach U-106 als Absender nennen dürfen, samt deren übrigen
  Zielen. Der Domainmaster von `a.test` konnte so den Catchall von `b.test` entfernen. Jetzt
  entfernt die Löschung eine solche Zeile nur, wenn sie zur Domain des Kontos gehört, nach der
  Regel aus U-126: eine Adresse nach ihrem Teil hinter dem `@` (ohne Rücksicht auf
  Groß- und Kleinschreibung), ein Schlüssel ohne `@`, solange er kein Konto einer anderen Domain
  nennt. Die Zeile einer fremden Domain bleibt **unverändert**, auch ihr `username`:
  - Ein Senderecht gibt sie nicht mehr — `validsenders` findet das Konto nicht.
  - Sie hält den Namen belegt (U-110); ein neues Konto dieses Namens wird abgelehnt, bis die
    andere Domain die Zeile ändert. Das ist die sichere Seite.
  - `AccountStore::delete()` bekommt dafür die Domain des Kontos. `CatchallCleanup` spiegelt
    die Regel: Eine fremde Adresse mit dem Konto als Absender geht nicht mit dem Konto und ist
    deshalb kein totes Ziel des eigenen Catchalls.

**Warum:** Eine Sperre, die eine gescheiterte Umbenennung aufhebt, und eine Prüfung, die einen
halb gefilterten View für vollständig hält, verfehlen beide den Zweck von U-127. Und das Löschen
eines Kontos darf nur ändern, was der Akteur verwaltet — dasselbe Argument, das O-20 für fremde
Catchalls offen hält.

**Alternative:** Bei der Umbenennung im Fehlerfall den Status zurückschreiben — verworfen, die
Kompensation kann selbst scheitern; der Status vorab unter beiden Namen braucht keine. Fremde
Zeilen beim Löschen umschreiben (`username` leeren) — verworfen, wäre ein Eingriff in eine fremde
Domain ohne deren Audit, genau die Frage von O-20. Löschen mit `409` ablehnen — verworfen, der
Domainmaster könnte die Ursache in der fremden Domain nicht beheben.

**Korrekturkosten:** Gering. `DomainStore`, `DomainRepository`, `RenameDomain`,
`AccountStatusEnforcement`, `bin/db-check.php`, `AccountRepository::delete()`, `CatchallCleanup`.

---

## U-130 — Fundament der Fachansichten: Bereichsdateien, Seitenverzeichnisse, geteilte Bausteine

**Frage:** Die Oberflächen von A1–A6 und S4 sollen von drei Arbeitssträngen gleichzeitig im
selben Arbeitsverzeichnis gebaut werden (`domains-admins-lists`, `accounts-addresses`,
`operations`). Ohne Vorbereitung hätten alle drei dieselben Dateien angefasst: `de.ts`,
`routes.tsx`, die Navigation in `AppLayout.tsx` — und jeder hätte seine eigene Tabelle, seinen
eigenen Bestätigungsdialog und seinen eigenen Umgang mit `ETag` erfunden.

**Entscheidung:**

1. **Sprachdatei je Ressourcenbereich.** Die Texte der Fachbereiche stehen in
   `frontend/src/i18n/de/<bereich>.ts` (`domain`, `admin`, `list`, `account`, `address`,
   `maintenance`, `audit` — genau die Ressourcenbereiche aus U-88). `de.ts` behält den Rahmen
   und mischt die Bereichsdateien ein; `TranslationKey` folgt weiter aus `de`. Die
   Katalogprüfung (U-89) lernt eine Regel dazu, `areaFileReport()`: jeder Ressourcenbereich hat
   seine Datei, jede Datei enthält nur Schlüssel mit ihrem Präfix, der Rahmen keinen
   Bereichsschlüssel. Damit hat jeder Schlüssel genau eine Datei, die ihn tragen darf — ein
   doppelter Schlüssel, der beim Zusammenfügen still den anderen überschriebe, ist
   ausgeschlossen. Die Verzeichnisse `i18n/<sprache>/` zählen bei der Suche nach ungenutzten
   Schlüsseln nicht als Anwendungscode. `de.errors.ts` bleibt eine Datei; neue Fehlercodes
   sind Vertragsänderungen und gehören in keinen Ansichtsstrang.
2. **Ein Seitenverzeichnis je Ansicht, mit eigener Routenliste.** `pages/domains`, `admins`,
   `lists`, `accounts`, `addresses`, `repairs`, `reconciliation`, `health`, `audit` tragen je
   eine Platzhalterseite mit übersetzter Überschrift und eine `routes.tsx`;
   `app/routes.tsx` mischt die Listen ein und wird von keinem Strang mehr angefasst. Adressen:
   `/domains`, `/admins`, `/lists`, `/accounts`, `/addresses`, `/maintenance/repairs`,
   `/maintenance/reconciliation`, `/maintenance/health`, `/audit`.
3. **Navigation als Daten** in `app/navigation.tsx`, vollständig für alle neun Ansichten, in
   zwei Gruppen („Verwaltung", „Betrieb"), je Eintrag mit der Berechtigung der lesenden
   Operation. Ein Domainmaster sieht Übersicht, Domains, Konten und Adressen. Jede Route steckt
   zusätzlich in `RequirePermission`, das ohne Berechtigung einen Hinweis statt der Seite
   zeigt — Höflichkeit wie das Ausblenden, keine Durchsetzung.
4. **Geteilte Bausteine unter `components/`**, je mit Test: `DataTable` (MUI-`Table`,
   Kopfzeilen sortierbar nur mit Sortierfeld, Paginierung mit deutschen Beschriftungen) und
   `useTableState` (Sortierung, Richtung, Seite, Seitengröße und Filter **in der URL**; die
   Sortierfelder sind gegen das `sort`-Enum der Operation typisiert, `SortFieldOf<'listDomains'>`,
   und ein Wert aus der URL, der nicht auf der Liste steht, wird verworfen statt
   weitergereicht), `FilterField` (verzögert, Enter sofort), `ConfirmDialog` (mit
   `typedConfirmation`: Bestätigen erst nach exakter Eingabe des Namens), `form.tsx`
   (Pflichtfeldprüfung mit dem Text von `errors.field.required`, Feldfehler aus
   `Problem.errors` auf das Feld), `ProblemAlert` (Text über `code`, Vorgangsnummer, „Neu laden"
   bei `412`), `PageHeader` (Pfad, Überschrift, Dokumenttitel), `Notifications` (nur
   Erfolgsmeldungen) und `ForbiddenState`.
5. **`ETag` im API-Client.** `getVersioned()`/`requestVersioned()` liefern `{ data, etag }`,
   `ifMatch(etag)` baut den Kopf und wirft ohne Tag — der Fehler liegt dann in der Ansicht, die
   das Objekt ohne Version geladen hat, nicht beim Server, der mit `428` antworten würde. Dazu
   `put`, `patch`, `delete` als typisierte Kurzformen.
6. **Neue Rahmenbereiche** `table` und `confirm` in `keyConvention.ts`, für die Texte der
   Bausteine. Allgemeine Schaltflächen (`action.*`) gibt es nur, soweit die Bausteine sie
   selbst benutzen; was eine Ansicht darüber hinaus braucht, steht in ihrem Bereich.
7. **Regeln für die Stränge** in `frontend/src/pages/README.md` (englisch, wie der Code):
   Zuständigkeit je Verzeichnis und Sprachdatei, Routen, Schlüssel, Berechtigungen, Abfrage-
   schlüssel mit dem Bereich als erstem Segment, Tests über die ganze Anwendung in jsdom
   (U-75), Commits nur mit eigenem Pfad.

**Warum:** Parallele Arbeit im selben Baum scheitert nicht an schwierigen Stellen, sondern an
den Sammeldateien, die jeder um eine Zeile ergänzt. Die werden hier einmal vollständig
geschrieben und danach geschlossen; jede Erweiterung findet in einer Datei statt, die genau
einem Strang gehört. Die Bausteine legen das Verhalten fest, das in allen Ansichten gleich
sein muss und das ein Strang unter Zeitdruck am ehesten weglässt: die Sortier-Allowlist
(Regel zwei aus `CLAUDE.md`, von der Clientseite), `If-Match` auf jedem Schreibzugriff
(U-19), die eingetippte Bestätigung vor dem Löschen von Post und die Vorgangsnummer in jeder
Fehlermeldung.

Tabellenzustand in der URL statt im Komponentenzustand, weil eine eingegrenzte Liste ein
Neuladen, einen geteilten Link und den Rückweg aus einer Detailansicht überstehen soll.

**Alternative:** Eine Sprachdatei je Strang statt je Bereich (drei statt sieben Dateien) —
verworfen, weil die Zuordnung Schlüssel → Datei dann nicht aus dem Schlüssel folgt und die
Prüfung sie nicht erzwingen könnte. Die Navigation je Strang beisteuern lassen — verworfen,
die Liste ist bekannt und kurz, und ihre Reihenfolge ist eine Entscheidung über das Ganze.

**Korrekturkosten:** Gering. Die Bereichsdateien lassen sich mechanisch zusammenführen; die
Bausteine sind vor ihrer ersten Verwendung noch frei änderbar.

---

## U-131 — Betriebsansichten: Abgleich auf Anforderung, Namenseingabe vor dem Postfach-Löschen, Protokollzeiten in UTC in der URL

**Frage:** Die Oberflächen von A6 (Warteschlange, Abgleich, Systemzustand) und S4
(Revisionsprotokoll) werfen Fragen auf, die der Vertrag offenlässt: Wann läuft ein Abgleich,
der jedes Postfach auflistet? Welche Wiederholung braucht mehr als einen Klick? Wie werden
englische Betreiberangaben gezeigt, ohne sie zur Meldung zu machen? Und wie wird ein
Zeitraumfilter eingegeben, wenn der Vertrag RFC 3339 mit Zone verlangt (U-65) und die
Oberfläche in der Zone des Browsers anzeigt (U-92)?

**Entscheidung:**

1. **Der Abgleich läuft auf Knopfdruck, nicht beim Öffnen der Seite, und ohne automatische
   Wiederholung.** Er liest vier Tabellen ganz und listet jedes Postfach in Cyrus (U-115,
   U-116); beim Aufruf der Seite und nach einem `503` dreimal abzugleichen, wäre die teuerste
   Nebenwirkung des Werkzeugs. Das letzte Ergebnis bleibt im Abfrage-Cache, „Erneut
   abgleichen" holt ein frisches.
2. **Befunde in den acht Kategorien, in der Reihenfolge des Vertrags**; die Zusammenfassung
   nennt alle acht, auch mit `0`, zusammen mit `checked` — „keine Befunde" über null und über
   viertausend Postfächer sind verschiedene Aussagen. `reason` wird als der Schlüssel gezeigt,
   der er ist, nicht übersetzt: der Vertrag nennt ihn eine Präzisierung, keine Meldung, und
   die Menge seiner Werte ist nicht aufgezählt.
3. **Eine Wiederholung des Schritts `mailstore.delete_mailbox` verlangt die Eingabe des
   Postfachnamens** (`subject`), wie das Löschen eines Kontos; jede andere Wiederholung wird
   mit einem Klick bestätigt. Abschließen fragt nach einer optionalen Notiz und sendet immer
   einen JSON-Körper (`{}` ohne Notiz, A6). Wiederholen wird nur bei `retryable`, Abschließen
   nur bei offenen Einträgen angeboten, beides nur mit `maintenance.write`.
4. **Betreiberangaben bleiben wörtlich und sind als solche gekennzeichnet.** `intended`,
   `reached`, `reason` eines Reparatureintrags und `reason` eines Protokolleintrags stehen in
   einem eigenen, beschrifteten Abschnitt in Festbreitenschrift, nie als Text der Oberfläche
   (E-16). Was ein Fehlercode des Vertrags ist — `last_error`, `code` einer Zustandsprüfung —,
   geht über `errors.<code>` im Katalog; ein unbekannter Code erscheint wörtlich.
5. **Zeitraumfilter des Protokolls: lokale Eingabe, UTC in URL und Anfrage.** `since` und
   `until` werden als `datetime-local` in der Zone des Browsers eingegeben und als
   `…Z` gespeichert und gesendet, sodass ein geteilter Link für jemanden in einer anderen
   Zone denselben Zeitpunkt meint. `until` schließt die gewählte Minute ein (`:59`). Ein
   ungültiger Wert in der URL — auch ein `part_of`, das keine Eintragsnummer ist — wird
   weggelassen statt gesendet. Über der Liste steht die Zone, in der die Zeiten erscheinen.
6. **Kein Einzelendpunkt, also ein Dialog.** Ein Protokolleintrag öffnet sich über der Liste;
   seine Verweise („Alle Einträge dieser Anfrage", „Verlauf dieses Objekts", „Alle Einträge
   dieses Vorgangs") **ersetzen** die Filter, statt sie zu ergänzen. Die Warteschlange
   verweist mit `/audit?request_id=…` hinein — derselbe Parametername wie im Vertrag.

**Warum:** Jede dieser Festlegungen schützt vor einem Fehler, der in einer Betriebsansicht
teuer ist: ein Abgleich als Nebenwirkung eines Seitenaufrufs, ein versehentlich gelöschtes
Postfach, ein englischer Betreibertext, der wie eine Meldung aussieht, und ein Zeitfilter,
der für zwei Verwalter in verschiedenen Zonen verschiedene Stunden meint.

**Alternative:** Den Abgleich beim Öffnen starten — verworfen aus dem Grund unter 1.
`since`/`until` in Ortszeit mit Offset in die URL schreiben — verworfen, weil der Link dann
die Zone des Absenders trägt und beim Empfänger umgerechnet werden müsste, um dasselbe zu
zeigen.

**Korrekturkosten:** Gering; alles liegt in `frontend/src/pages/{repairs,reconciliation,health,audit}/`.

---

## U-132 — Zusammenführung der Fachansichten: was aus den Strängen in die geteilten Bausteine wandert

**Frage:** Die drei Stränge aus U-130 haben ihre Ansichten fertig und dabei gemeldet, was ihnen in
den geteilten Dateien fehlte und was sie deshalb örtlich gebaut oder umgangen haben. Was davon
wird geteilt, was bleibt im Bereich?

**Entscheidung:**

1. **Optionaler Anfragekörper ist typisiert.** `RequestBodyOf` in `api/client.ts` erkennt neben
   `requestBody:` auch `requestBody?:` und macht `body` dann optional, aber typisiert. Damit
   entfällt die Typumgehung beim Abschließen eines Reparatureintrags (`repairActions.tsx`).
2. **`X-Mailadmin-Effect` erreicht die Ansicht.** `requestVersioned()`/`getVersioned()` liefern
   `{ data, etag, effect }` (`Exchange`), `hasPendingConfigurationRun()` prüft auf den einen
   Wert des Vertrags. Die Mailinglisten schreiben darüber und richten die Erfolgsmeldung nach dem
   Kopf (U-59): mit Kopf nennt sie den Konfigurationslauf, ohne Kopf nicht. Der Hinweis auf den
   Seiten und in den Bestätigungen bleibt fest, weil er eine Eigenschaft des Systems beschreibt,
   nicht eine Antwort.
3. **Eigenes Passwort im Kontomenü.** `/admins/me/password` braucht `profile.password.write`,
   der Navigationseintrag „Administratoren" aber `admin.read` — ein Domainmaster erreichte die
   Seite nur über die eingetippte Adresse. Das Kontomenü der Kopfleiste trägt den Eintrag jetzt
   für jede Rolle mit `profile.password.write`; der neue Rahmenschlüssel ist `nav.ownPassword`.
   Die Navigationsliste selbst bleibt unverändert.
4. **Gehoben nach `components/` und `i18n/`:** `DefinitionList`/`DefinitionItem`/`Monospace`
   (vorher doppelt in Reparatur- und Protokollansicht, dazu ein dritter Nachbau auf der
   Kontoseite), `errorAtOrBelow` nach `form.tsx` (vorher in `pages/domains/`, von Listen und
   Administratoren quer importiert) und `useErrorCodeText()` nach `i18n/problemText.ts` (vorher
   in `pages/health/`, von der Reparaturansicht quer importiert).
5. **Bewusst nicht gehoben:**
   - Die beiden Quota-Eingaben. `QuotaField` (A1) kennt „leer = keine Grenze" und KiB/MiB/GiB,
     `QuotaInput` (A2) kennt drei Modi einschließlich „Vorgabe der Domain". Das ist dieselbe
     Größe, aber nicht dasselbe Bedienelement; sie zu vereinheitlichen ist eine
     Gestaltungsentscheidung, keine Aufräumarbeit.
   - Die Domainauswahl. `DomainPicker` (A2/A3) wählt eine Domain für eine Liste,
     `DomainAssignmentField` (A4) ordnet mehrere einem Administrator zu.
   - Ein `disabled` für `ConfirmDialog`. Die Umbenennung prüft den neuen Namen beim Bestätigen
     und zeigt den Grund am Feld — wie jedes Formular hier (Pflichtfelder beim Absenden,
     U-130). Ein bloß gesperrter Knopf sagte nicht, warum.
   - Zeilenaktionen in `DataTable`. Verweise und Löschknöpfe in Zellen funktionieren und sind
     getestet; eine eigene Schnittstelle dafür lohnt erst mit einem dritten Muster.
   - Die drei Testhilfen der Stränge (`pages/domains/testkit.ts`, `pages/accounts/testKit.ts`,
     `pages/repairs/operationsTestKit.ts`). Ihre Sitzungen tragen bewusst verschiedene
     Berechtigungsausschnitte, mit denen die Tests Sichtbarkeit nachweisen; eine gemeinsame
     Fassung hieße, jeden Test neu zu prüfen.
6. **Querverweise per Abfrageparameter** sind festgeschrieben: `/accounts?domain=`,
   `/addresses?domain=` (von der Domainseite), `/audit?request_id=` (von der Warteschlange).

**Warum:** Gehoben wird, was mindestens zwei Bereiche schon gleich brauchen oder quer
importieren — dort ist die Doppelung bereits Wirklichkeit. Was nur ähnlich aussieht, aber
verschieden bedient wird, bleibt im Bereich, bis jemand die Bedienung vereinheitlichen will.

**Alternative:** Alle gemeldeten Kandidaten heben — verworfen aus dem Grund unter 5. Den
Effektkopf weiter ignorieren, weil er nur einen Wert kennt — verworfen: Die Meldung „wirkt erst
nach dem Konfigurationslauf" wäre sonst eine Behauptung der Oberfläche statt eine Auskunft des
Servers, und ein späterer zweiter Wert bliebe unbemerkt.

**Korrekturkosten:** Gering; die gehobenen Bausteine sind klein und getestet, die nicht gehobenen
liegen weiter in ihren Bereichen.

---

## U-133 — Nachbesserungen an den Fachansichten aus dem Review

**Frage:** Das Review der Fachansichten (U-130 bis U-132) meldete neun Befunde. Welche sind echt,
und wie werden sie behoben, ohne neue Abhängigkeiten oder eine Lockerung der Regeln?

**Entscheidung:** Alle neun sind echt und behoben, jeder mit einem Test, der vorher scheiterte; die
beiden Befunde zum Umbenennen und Löschen einer Domain stehen gemeinsam unter 6.

1. **Weiterleitung: gesendet wird, was der Schalter zeigt.** Wo das Namensschema die Kopie
   erzwingt (`copy_required`), zeigte der Schalter sie an, gesendet wurde aber der gespeicherte
   Wert. Eine Altzeile ohne Kopie ging so als `keep_copy: false` zurück und wurde mit
   `forward.copy_required` abgelehnt. `ForwardsCard` sendet jetzt den angezeigten Wert.
2. **Quota: kein Runden beim Vorbelegen.** `quotaChoiceOf` rundete Grenzen, die keine ganzen MiB
   sind, auf zwei Nachkommastellen; ein Speichern ohne Änderung schrieb dann eine andere Grenze
   (1000001 KiB wurden 999997 KiB). Die MiB-Angabe bleibt ungerundet — KiB durch 1024 ist ein
   endlicher Binärbruch, `String()` liefert also eine Dezimalzahl, die `quotaKibOf` exakt
   zurückrechnet. Eine eigene KiB-Einheit wäre die Alternative gewesen; sie hätte die Eingabe für
   den seltenen Fall unübersichtlicher gemacht.
3. **Reparaturwarteschlange: eine Ablehnung lädt neu.** Wiederholen und Abschließen luden Liste
   und Eintrag nur nach Erfolg neu. Nach `409` (anderswo erledigt, Zustand verändert) zeigte die
   Seite weiter den alten Stand, und jeder weitere Versuch scheiterte gleich. Jetzt wird nach
   jeder Ablehnung neu geladen; der Dialog behält seine Fehlermeldung.
4. **Musterfilter.** `useTableState` kennt neben `'text'` und Aufzählungen einen `RegExp` für
   Freitextfilter, die der Vertrag mit `pattern` einschränkt (`FILTER_PATTERN`: `action` und
   `object_type` im Protokoll, `operation` und `step` in der Warteschlange). Ein Wert, der nicht
   passt, bleibt im Feld stehen, wird aber nicht gesendet und am Feld begründet
   (`table.filter.invalidDotted`, `table.filter.invalidPlain`). Stilles Kleinschreiben wurde
   verworfen: Es änderte die Eingabe, ohne es zu sagen.
5. **Seite hinter dem Ende.** Antwortet die Liste mit leerer Seite, aber `total > 0` (gelöschte
   letzte Zeile, gespeicherter Verweis auf `?page=9`), springt `DataTable` auf die letzte Seite,
   statt „keine Einträge" zu zeigen — nicht während noch geladen wird.
6. **Umbenennen und Löschen einer Domain** gehen über `forgetDomainName()`
   (`pages/domains/domainCache.ts`): Der Eintrag unter dem alten Namen wird **entfernt, bevor**
   invalidiert wird — sonst lädt die noch eingehängte Seite den alten Namen neu und erhält `404`.
   Danach werden `domains` (einschließlich der Domainauswahl der Administratorformulare),
   `accounts`, `addresses` und `admins` als veraltet markiert, ohne darauf zu warten.
7. **Quota-Auswahl benannt.** Die Radiogruppe trägt `aria-labelledby` auf ihre Beschriftung.
8. **Domainzuordnung übernimmt getippten Text beim Verlassen** (`autoSelect`), nicht nur mit der
   Eingabetaste; doppelte Einträge werden zusammengefasst.

**Warum:** Jeder Befund ließ die Oberfläche etwas anderes tun, als sie anzeigte — eine Kopie, die
nicht gesendet wird, eine Grenze, die sich beim Speichern verschiebt, ein Eintrag, der als offen
erscheint, eine Domain, die nach dem Löschen noch angeboten wird. Das ist dieselbe Fehlerklasse,
die CLAUDE.md für das Altsystem beschreibt.

**Alternative:** Für 2 den `PUT` überspringen, wenn sich die Auswahl nicht geändert hat — verworfen
als alleinige Lösung, weil eine echte Änderung der Einheit dann weiter gerundet hätte.

**Korrekturkosten:** Gering; alle Änderungen liegen in der Oberfläche und sind getestet.

---

## U-134 — `lists.members` wird zeilenweise geschrieben; gelesen wird weiter tolerant

> Ersetzt U-56 im Punkt „geschrieben wird komma-separiert". Bytetreue und weites Lesen bleiben.

**Frage:** U-56 hat das Trennzeichen offen gelassen und Komma gerendert, unter der Annahme, Salt
schreibe `members` in die Aliasdatei. Was gilt, nachdem bekannt ist, wer die Spalte wirklich liest?

**Entscheidung:**

- **Geschrieben wird ein Eintrag pro Zeile**, getrennt durch `\n` (`MemberList::SEPARATOR`).
  Ein Eintrag ist `user@domain` oder `Name <user@domain>`.
- **Gelesen wird an `\r\n`, `\n`, `,`, `;` und `<br>` in jeder Schreibweise**, nachdem
  HTML-Entities der Altanwendung dekodiert sind (erst dekodieren, dann trennen).
- **Unverändert bleibt U-56s Kern:** Eine Liste, deren Mitglieder sich nicht geändert haben,
  wird byteweise zurückgeschrieben, samt `NULL`. Erst eine geänderte Liste wird vollständig
  kanonisch geschrieben; dekodierte Altbestandseinträge stehen danach als Klartext in der Spalte.
- **Neu abgelehnt** wird ein hinzukommender Eintrag, der nicht als er selbst zurückgelesen würde:
  `,`, `;` oder `<br>` im Anzeigenamen (beim Lesen ein Trenner) und Entities (würden dekodiert).
  Bestandseinträge bleiben unangetastet (U-22, U-57).
- `MemberList::deliverableAddresses()` liefert die Adressen, an die `maillist.py` zustellen wird:
  brauchbare Einträge, ohne Dubletten (Vergleich ohne Groß-/Kleinschreibung, der erste gewinnt).
  Golden-Dateien unter `tests/Fixtures/MemberFormat/` sind der gemeinsame Vertrag zwischen
  `MemberList` und `normalize_recipients()` in `maillist/`; beide Testsuiten lesen sie.

**Warum:** Die Aliasdatei enthält nur die Pipe-Zeile auf das Listenskript, nie die Mitglieder.
`members` liest allein `maillist.py`, und das trennt an Zeilenumbrüchen. Eine in mailadmin
geänderte Liste wäre mit Kommas ein einziger ungültiger Empfänger `a@x,b@y` geworden. Der lesende
Blick in die Produktion (Bestandsanalyse 4) bestätigt das Format: Die Einträge sind durch
Zeilenumbrüche und `<br />` getrennt, eine Liste durch Kommas, keine trägt Entities.

**Alternative:** Weiter mit Komma schreiben und nur `maillist.py` an Kommas trennen lassen. Das
Skript tut das inzwischen auch, als Schutz für Daten, die die frühere Fassung geschrieben haben
könnte. Als Schreibformat wäre Komma aber das schlechtere: Anzeigenamen mit Komma ließen sich nicht
mehr eindeutig lesen.

**Korrekturkosten:** Gering. Eine Konstante und die Golden-Dateien.

---

## U-135 — Eine Liste wird über ihre Adresse identifiziert; der Name ist Anzeigetext

> Ersetzt U-60.

**Frage:** U-60 nahm an, `lists` habe vier Spalten mit `name` als Primärschlüssel. Die
Produktions-DDL (lesend erhoben, `SHOW CREATE TABLE`) sagt etwas anderes. Worüber wird eine Liste
dann angesprochen, und was bedeutet `name`?

**Entscheidung:**

- **Produktions-DDL.** `lists` hat `id` (`int unsigned`, `AUTO_INCREMENT`, Primärschlüssel),
  `name`, `tag`, `address` (je `varchar(255) NOT NULL`) und `members` (`text NOT NULL`), MyISAM,
  `latin1`. **Es gibt keinen Unique-Index** auf `name` oder `address`. Das Basisschema der
  Testumgebung (`docker/db/init/00-baseline-schema.sql`) übernimmt genau diese Definition.
- **Identität ist die Adresse:** `GET/PATCH/DELETE /lists/{address}`, URL-kodiert. Die Adresse
  wird beim Anlegen kleingeschrieben und ist danach **unveränderlich**; der `PATCH`-Körper kennt
  kein `address` (`additionalProperties: false`, also `422`). Wer die Adresse ändern will, löscht
  die Liste und legt sie neu an.
- **Die Eindeutigkeit sichert die Anwendung**, nicht die Datenbank: Beim Anlegen darf die Adresse
  weder in `lists.address` noch in `virtual.alias` noch als Kontoname stehen, und keine Liste darf
  denselben Listenschlüssel `REPLACE(address,'@','.')` haben. Damit ist auch die Mehrdeutigkeit
  `a.b@c` gegenüber `a@b.c` abgefangen. Jeder Treffer ist `409 list.address_taken`;
  `list.already_exists` entfällt. `list.not_found` gibt es weiterhin nicht (U-44).
- **Altbestand mit doppelter Adresse:** `find` liefert die Zeile mit der kleinsten `id`, `remove`
  löscht alle Zeilen der Adresse.
- **`name` ist freier Anzeigetext**, darf doppelt vorkommen und ist per `PATCH` änderbar. Die
  strenge Tür `MailingListName::fromString()` verlangt nur, was die Spalte speichern kann: nicht
  leer, höchstens 255 Zeichen, keine Steuerzeichen, nur `latin1` (U-137). Leerzeichen,
  Doppelpunkte und Umlaute sind erlaubt.
- **Die tolerante Tür `MailingListName::fromStoredValue()`** nimmt jeden gespeicherten Wert an,
  ohne zu trimmen oder abzulehnen, und markiert ihn nur über `isConventional()`. Eine ungewöhnliche
  Zeile verschwindet nicht und lässt keine Liste scheitern (U-22); unverändert gespeichert behält
  sie ihre Bytes.

**Warum:** Die Adresse ist das, was Postfix und `maillist.py` kennen, und das Einzige, das in
Produktion tatsächlich eindeutig ist. `name` wertet niemand aus: Salt schreibt die Aliasdatei aus
der Adresse (`REPLACE(address,'@','.')`), nicht aus `lists.name`, und mit dem Pipe-Transport
entfällt auch das. Die Adresse als Pfad ist zudem unabhängig davon, welchen Primärschlüssel die
Tabelle trägt. Unveränderlich ist sie, weil an ihr der `virtual`-Eintrag und der Listenschlüssel
hängen; eine Adressänderung wäre ein Identitätswechsel über zwei Tabellen, keine Feldänderung.

**Alternative:** `id` als Pfad. Sie existiert zwar, ist aber für niemanden außerhalb der Tabelle
bedeutsam, und ein Client müsste zu jeder Adresse erst die `id` suchen. — Ein Unique-Index auf
`address`: eine Änderung an einer Bestandstabelle (Regel 1), und er würde am doppelten Altbestand
scheitern.

**Korrekturkosten:** Hoch, sobald Clients existieren — es ist die Gestalt jeder Listen-URL.

---

## U-136 — Domainmaster verwalten die Listen ihrer Domains

> Ändert U-58 im Punkt „Rolle". Was U-58 über `tag` sagt, gilt weiter.

**Frage:** U-58 ließ Listen beim Superuser, weil `name` ein globaler Namensraum ohne Domainanteil
schien. Die Altanwendung ließ Domain-Admins die Listen ihrer Domain pflegen. Trägt das Argument
noch?

**Entscheidung:** **Domainmaster erhalten `list.read`, `list.write` und `list.delete`.**
`ListPolicy` prüft die Domain der Listenadresse:

- **Lesen, Ändern, Löschen:** Eine Liste einer fremden Domain ergibt `404`, nicht unterscheidbar
  von einer, die es nicht gibt (U-44).
- **Anlegen** für eine Domain außerhalb des eigenen Zuschnitts ergibt `403`
  (`ListPolicy::requireCreateIn()`), weil die Adresse vom Client kommt und es nichts zu verbergen
  gibt. Eine Domain, die nicht in `domain` steht, ergibt `422 list.unknown_domain`.
- Die Listenabfrage engt über `list_domain` ein, wie U-58 es vorbereitet hatte.

**Warum:** Mit U-135 ist die Identität die Adresse, und die trägt ihre Domain. Den Namen, den U-58
als globalen Namensraum fürchtete, belegt niemand mehr; ein Domainmaster kann einem anderen nichts
wegnehmen außer einer Adresse in dessen Domain, und die sieht er nicht. Ohne die Erweiterung
fehlte eine Funktion der Altanwendung, die abgelöst werden soll.

Eine Ausnahme bleibt bewusst: Die Prüfung auf gleichen Listenschlüssel kann einem Domainmaster
verraten, dass in einer anderen Domain eine Liste existiert (`a.b@own.test` gegen
`a@b.own.test`). Die Ablehnung ist trotzdem nötig, weil `maillist.py` sonst zwei Listen unter
einem Schlüssel fände.

**Alternative:** Weiter nur Superuser. Dann bliebe die Altanwendung für Domain-Admins in Betrieb,
samt ihren Sicherheitsmängeln.

**Korrekturkosten:** Gering. Drei Rechte in `RolePermissions::domainMaster()`.

---

## U-137 — Eingaberegeln einer Liste: kein `+` in der Adresse, nur `latin1` in Name, Tag und Mitgliedern

**Frage:** Welche Werte kann die Zustellkette nicht tragen, und wo werden sie abgelehnt?

**Entscheidung:**

1. **Kein `+` im Local Part der Listenadresse:** `422 list.address_has_extension` am Feld
   `address`. Das Trennzeichen steht als Konstante mit Begründung im Code, nicht in der
   Konfiguration.
2. **Nur druckbares `latin1`** in Name, Tag und neuen Mitgliedern, also U+0000–U+007F und
   U+00A0–U+00FF ohne die C1-Steuerzeichen: `list.name_outside_latin1`,
   `list.tag_outside_latin1`, `list.member_outside_latin1`. Steuerzeichen lehnen Name und Tag
   zusätzlich ab. Die Regel steht an genau einer Stelle, `Latin1Text`; Längen zählen dort Zeichen,
   nicht Bytes, so wie `varchar(255)` in `latin1`.
3. Beides gilt nur für **neue oder geänderte** Werte. Gespeicherte werden gelesen, wie sie sind
   (U-22).
4. **Nachtrag aus dem Abschlussreview: ein neues Mitglied muss zustellbar sein.** Ein
   hinzukommender Eintrag muss eine Adresse sein, an die `maillist.py` genau so zustellt, wie sie
   dasteht: die Adresse besteht `_is_plain_address()` (ASCII ohne Leerraum und ohne
   `<>"(),;:\[]`, Domain mit Punkt, ohne führenden, abschließenden oder doppelten Punkt), und ein
   Anzeigename vor `<adresse>` ist entweder ein Anführungszeichen-String ohne inneres `"` und `\`
   oder enthält keines von `()<>[]:;@\"`. Sonst `422 list.member_not_deliverable` an
   `body.members.<n>`, wie die `latin1`-Regel nur mit der Position, nie mit der Adresse.
   Gespeicherte Einträge bleiben, wie sie sind: Eine Änderung, die eine solche Zeile unverändert
   lässt, geht durch; der Eintrag erscheint in `unusable_members`.
   `MemberList::deliverableAddresses()` und `unusable_members` folgen derselben Regel.
5. **`maillist.py` liest keine Gruppensyntax mehr.** `getaddresses()` las `user:x@example.org`
   als Gruppe `user` mit dem Mitglied `x@example.org` und stellte an eine Adresse zu, die niemand
   eingetragen hatte. Das Skript trennt eine Zeile jetzt selbst an `,` und `;` außerhalb von
   Anführungszeichen und spitzen Klammern und überspringt einen Eintrag mit `:` außerhalb davon,
   mit derselben Warnung wie jeden anderen unbrauchbaren Eintrag.
6. **Kein `&` in neuen Mitgliedern, und das Skript dekodiert nur vollständige Entities.**
   `html.unescape()` las auch `&#64` und `&lt` ohne Semikolon, `html_entity_decode()` in PHP nicht:
   Derselbe Text hätte in mailadmin eine Adresse gezeigt und im Skript an eine andere zugestellt.
   Ein neuer Eintrag mit `&` ist deshalb `list.member_not_deliverable`; gespeicherte bleiben
   geduldet. `maillist.py` dekodiert nur noch `&name;`, `&#nn;` und `&#xhh;` mit Semikolon, benannte
   Entities nur bei exaktem Treffer in der HTML5-Tabelle. Golden-Fall `entity-without-semicolon`.
7. **Steuerzeichen.** `str.splitlines()` trennte Zeilen auch an `\x0b`, `\x0c`, `\x1c` bis `\x1e`
   und `\x85`, mailadmin nur an CR und LF. Aus `"evil@attacker.test\x0b" <victim@example.test>`
   zeigte mailadmin das Opfer, das Skript stellte an `evil@attacker.test` zu. Jetzt:
   - `maillist.py` trennt Zeilen nur an `\r\n`, `\r` und `\n`.
   - Ein Eintrag mit einem C0-Steuerzeichen außer Tab oder mit DEL ist auf beiden Seiten nicht
     zustellbar. Geprüft wird nach genau dem Trimmen, das PHPs `trim()` vornimmt, damit beide Seiten
     dieselben Ränder abschneiden. Gespeicherte Einträge werden gemeldet und bleiben erhalten.
   - Ein neuer Eintrag darf gar kein Steuerzeichen enthalten, auch keinen Tab
     (`list.member_not_deliverable`).
   - Numerische Entities dekodiert das Skript nur, wo `html_entity_decode(…, ENT_HTML5)` es auch
     tut: nicht C0 außer Tab, LF und FF, nicht CR, DEL, C1, Surrogate und Nichtzeichen.
     `html.unescape()` machte aus `&#128;` ein Euro-Zeichen und ließ `&#11;` verschwinden.
   - Golden-Fälle `control-vertical-tab`, `control-form-feed`, `control-file-separator` und
     `control-nul`.
   - Kein `pattern` im Vertrag: `PATCH` schickt die vollständige Mitgliederliste samt
     gespeicherter Einträge zurück, und ein Schema-Muster würde eine Liste mit einem geduldeten
     Alteintrag unbearbeitbar machen. Außerdem melden nur die Fachregeln den Fehler an der Position
     des Eintrags.

**Warum, für die Zustellbarkeit:** Vorher prüfte mailadmin neue Mitglieder mit der toleranten
Adressprüfung, das Skript aber mit der strengen. `müller@example.de`, `user@localhost` oder
`a"b@example.de` wurden gespeichert, angezeigt und gezählt, erhielten aber nie Mail; das Skript
schrieb nur eine Warnung ins Log. Die Regel ist bewusst so eng, dass beide Seiten für jeden
angenommenen Eintrag übereinstimmen, ohne `getaddresses()` in PHP nachzubauen. Bei gespeichertem
Text, den niemand geprüft hat, ist mailadmin die strengere Seite: Ein `Jan (Vertrieb) <j@…>`
stellt das Skript zu, mailadmin meldet ihn als unbrauchbar — nie umgekehrt. Die Golden-Dateien in
`tests/Fixtures/MemberFormat` decken Nicht-ASCII, Domain ohne Punkt, Endpunkt, Gruppensyntax,
Anführungszeichen, Kommentare, Backslash und Domainliteral ab und laufen in beiden Testsuiten.

**Warum, für `+`:** Der Transport übergibt `${user}`, den Local Part ohne Adresserweiterung. Bei
`recipient_delimiter = +` (Postfix-Standard, auch in Produktion) würde ein `+` im Listenschlüssel
abgeschnitten, und die Liste wäre nicht auffindbar. `${mailbox}` hilft nicht: Wegen
`propagate_unmatched_extensions` käme `team+x@…` dann als `schluessel+x` an. Mit `${user}` erreicht
Mail an `team+x@domain` die Liste `team` — die Erweiterung funktioniert also für die Absender, nur
die Liste selbst darf keine tragen. Das Umstellungskommando (U-142) überspringt Altlisten mit `+`.

**Warum, für `latin1`:** `lists` ist eine `latin1`-Tabelle, die Verbindung spricht `utf8mb4`.
MariaDB wandelt bei jedem Schreiben um. Ein Umlaut kommt heil an; ein Zeichen, das `latin1` nicht
fasst, lässt einen strikten Server die Anweisung verweigern (Fehler 1366, nach außen ein
undurchsichtiges `500`), und ein nachsichtiger speichert `?`. Beides ist nicht, was im Formular
stand. MariaDBs `latin1` ist eigentlich `cp1252` und nähme `€`, aber ob `maillist.py` und andere
Leser das genauso sehen, ist nicht belegt; die engere Grenze ist die sichere.

**Alternative:** Für `latin1`: die Tabelle auf `utf8mb4` umstellen — keine additive Migration und
verboten (Regel 1). Oder Zeichen als Entities speichern, wie die Altanwendung: Genau das hat
`maillist.py` heute zu dekodieren. — Für `+`: Adressen mit `+` über einen eigenen Schlüssel
zulassen. Das hätte die Datenbankabfrage von `maillist.py` geändert und Alt- und Neuweg
während der Umstellung getrennt.

**Korrekturkosten:** Gering. `Latin1Text` und eine Konstante in `CreateMailingList`; für die
Zustellbarkeit zwei private Prüfungen in `MemberList` und `_split_entries()` in `maillist.py`.

---

## U-138 — Anlegen und Löschen über `lists` und `virtual`, unter der Namensraumsperre

**Frage:** Zu einer Liste gehören eine Zeile in `lists` und eine in `virtual`. Beide Tabellen
sind MyISAM, eine Transaktion über beide gibt es nicht. In welcher Reihenfolge wird geschrieben,
unter welcher Sperre, und was bleibt bei einem Fehler zurück?

**Entscheidung:**

- **Der `virtual`-Eintrag einer Liste:** `alias = <Adresse>`,
  `dest = REPLACE(address,'@','.') + '@' + <Pseudodomain>`, `username = ''`, `status = 1`. Die
  Pseudodomain kommt aus `MAILADMIN_LIST_TRANSPORT_DOMAIN` (Standard `maillist.invalid`, RFC 2606).
  `username` bleibt leer, damit `sender_canonical_maps` (Abfrage über `username`) nicht auf
  Listeneinträge anspringt.
- **Anlegen: `lists` zuerst, dann `virtual`.** Scheitert `virtual`, werden dessen Zeilen
  zurückgesetzt (`RowWriter::restore()`) und die `lists`-Zeile wieder entfernt. Scheitert auch
  das, entsteht ein Reparatureintrag `list.create`/`lists.remove`, und der ursprüngliche Fehler
  geht an den Client.
- **Löschen: `virtual` zuerst, dann `lists`.** Entfernt werden die `virtual`-Zeilen der Adresse,
  die als Listeneintrag erkannt werden (U-139), in Transport- wie Altform; Zeilen anderen Typs unter
  derselben Adresse bleiben und werden protokolliert. Scheitert danach `lists`, gilt das Löschen
  trotzdem als erfolgt (`204`): Es entsteht ein Reparatureintrag `list.delete`/`lists.remove` und
  eine `error`-Logzeile, so wie `DeleteAccount` ein nicht entferntes Postfach meldet.
- **Ändern** schreibt nur `lists` (`name`, `tag`, `members`); `virtual` bleibt unberührt.
  Scheitert das Audit, wird die Zeile auf den gelesenen Stand zurückgesetzt (U-105), sonst
  `lists.restore` in die Warteschlange (manuell).
- **Sperre:** Anlegen, Ändern, Löschen und die Wiederholung aus der Warteschlange laufen unter
  `DomainStore::withNamespaceLock()`, derselben Sperre, die alle Adress-Anwendungsfälle nehmen
  (U-97). Die Spec nannte eine Sperre pro Listenadresse.
- **Wiederholung:** `RepairCatalogue` kennt den Schritt `lists.remove`. Die Wiederholung
  entfernt die `lists`-Zeile der Adresse, wenn unter der Sperre keine `virtual`-Zeile mehr die
  Adresse trägt; sonst lehnt sie ab.
- **Audit:** Anlegen und Löschen schreiben einen Haupteintrag `list.create`/`list.delete` und
  den `virtual`-Eintrag als Kindeintrag (`partOf`), wie bei Domains. Ändern schreibt
  `list.update`.

**Warum:** Die Reihenfolge folgt „so scheitern, dass niemand Mail verliert" (CLAUDE.md). Eine
`lists`-Zeile ohne `virtual` empfängt nichts und ist harmlos; ein `virtual`-Eintrag ohne
`lists`-Zeile ließe `maillist.py` mit `67` bouncen. Deshalb kommt `lists` beim Anlegen zuerst
und geht beim Löschen zuletzt.

Die Namensraumsperre ist strenger als eine Sperre pro Adresse und schließt genau das Rennen aus,
das eine Adresssperre offen ließe: Ein Alias und eine Liste können nicht gleichzeitig dieselbe
Adresse belegen, weil die Adressverwaltung dieselbe Sperre nimmt. Dass auch Ändern sie nimmt, hält
ein gleichzeitiges Löschen davon ab, zwischen Lesen und Zurücksetzen zu fallen.

**Alternative:** Eine Sperre pro Listenadresse, wie in der Spec. Weniger Wartezeit, aber das
Rennen mit der Aliasanlage bliebe offen. — `virtual` beim Anlegen zuerst: Dann gäbe es ein
Zeitfenster, in dem Mail ankommt und mangels Liste bounct.

**Korrekturkosten:** Gering. Die Reihenfolge steht in `CreateMailingList` und
`DeleteMailingList`, der Retry in `RepairCatalogue`.

---

## U-139 — Listeneinträge in `virtual` sind ein eigener Typ, streng erkannt; Transportziele gehören nur ihnen

**Frage:** `virtual` trägt bisher Kontoadresse, Alias, Catchall und Weiterleitung, nur an der
Feldbelegung unterscheidbar. Wie erkennt die Adressverwaltung einen Listeneintrag, und wie
verhindert sie, dass ein gewöhnlicher Alias die Pseudodomain als Ziel nennt?

**Entscheidung:**

- **`VirtualEntryType::MailingList` (`mailing_list`).** Eine Zeile ist ein Listeneintrag, wenn
  (1) sie genau die Form hat, die A5 schreibt — leeres `username`, genau ein Ziel, und dieses Ziel
  ist `<Listenschlüssel des alias>@<Pseudodomain>` — und `lists` eine Zeile mit dieser Adresse
  enthält (seit U-146), oder (2) sie die Altform
  `dest = username = REPLACE(alias,'@','.')` hat **und** `lists` eine Zeile mit dieser Adresse
  enthält. Die Regel steht vor „leeres `username` → Weiterleitung".
- **Streng:** Eine Zeile, die die Pseudodomain nennt, aber nicht genau Form (1) hat, ist **kein**
  Listeneintrag, sondern `unknown` — angezeigt, nicht geraten.
- Die Prüfung hängt an `MailingListRecognition`, einer reinen Funktion. Die Aufrufer laden die
  Listenadressen der betroffenen Domains mit einer Abfrage pro Domain, nicht pro Zeile.
- **In der Adressverwaltung** erscheinen Listeneinträge nur lesend (`editable: false`) mit Verweis
  auf die Liste; Löschen dort ergibt `409 address.not_editable`. Ändern und Löschen laufen nur über
  `/lists`.
- **Transportziele sind reserviert.** `DestinationRules` lehnt für Aliase, Weiterleitungen und
  Catchall jedes neue Ziel auf der Pseudodomain ab, dazu Routing-Schreibweisen, mit denen sich das
  umgehen ließe: `%` oder `!` im Local Part und eine Quellroute mit führendem `@`
  (`address.invalid_destination`). Domains werden dabei normalisiert verglichen: kleingeschrieben,
  getrimmt, ohne abschließenden Punkt. Gespeicherte Zeilen mit solchen Zielen werden gelesen und
  behalten (U-22).
- **Die Pseudodomain ist keine Domain.** Anlegen oder Umbenennen einer Domain auf die
  Pseudodomain (gleich in welcher Schreibweise) ergibt `422 domain.invalid_name`.

**Warum:** Regel (2) braucht `lists`, weil die Altform Feld für Feld auch die Adresszeile eines
Kontos im Punktschema ist; ohne die Gegenprobe würde ein Konto als Liste erkannt. Die Strenge in
Regel (1) verhindert, dass eine fremde Zeile mit einem Transportziel als Liste gilt und dann über
`/lists` gelöscht oder in der Adressverwaltung nicht mehr entfernt werden kann.

Die Sperre der Transportziele ist ein Zugriffsschutz: Postfix prüft nach der `virtual`-Auflösung
nichts mehr. Ein Alias auf `<schluessel>@<Pseudodomain>` speiste jede Liste, auch die einer
fremden Domain, an `ListPolicy` vorbei. Eine verwaltete Pseudodomain wiederum würde Postfix lokal
zustellen; keine Liste erreichte mehr den Transport.

**Alternative:** Listeneinträge weiter als Alias bzw. Weiterleitung führen. Dann ließe die
Adressverwaltung sie ändern und löschen, ohne dass `lists` mitgeht.

**Korrekturkosten:** Gering. `MailingListRecognition`, `VirtualEntryClassifier`,
`DestinationRules`.

---

## U-140 — Eine Domain mit Listen wird nicht umbenannt

**Frage:** `DomainRepository::rename` schreibt `virtual.alias` um, lässt aber `lists.address`,
den Listenschlüssel in `dest` und den Alt-`username` stehen. Mitziehen oder ablehnen?

**Entscheidung:** **Ablehnen.** Hat die Domain mindestens eine Zeile in `lists`, antwortet die
Umbenennung mit `409 domain.rename_blocked_by_mailing_list`, vor jedem Mailstore- oder
Datenbankschritt. Wer umbenennen will, löscht die Listen vorher und legt sie danach neu an.

**Warum:** Ohne die Sperre passen danach `lists.address` und `virtual.alias` nicht mehr zusammen.
Im Transportmodus läuft die Zustellung zwar weiter, aber `/lists` findet den `virtual`-Eintrag
beim Löschen nicht mehr. In der Altform würde der Eintrag zum gewöhnlichen Alias und über die
Aliasverwaltung änderbar. Mitziehen hieße, `lists`, `dest` und im Altmodus die Aliasdatei gemeinsam
umzustellen — nicht atomar, und im Altmodus ohne Salt-Lauf nicht vollständig. Das Vorbild ist
`domain.rename_blocked_by_account`.

**Alternative:** Mitziehen im Transportmodus und nur im Altmodus ablehnen. Möglich, sobald der
Altweg entfallen ist; bis dahin wären es zwei Verhalten für denselben Aufruf.

**Korrekturkosten:** Mittel. Das Mitziehen wäre ein eigener Ablauf über zwei Tabellen mit
Kompensation.

---

## U-141 — Das Listenskript liegt in diesem Repository, mit eigenem Installer und eigenem Release

**Frage:** `maillist.py` lag in einem eigenen Altrepository, mit Zugangsdaten im Quelltext und
ohne Tests. mailadmin und das Skript teilen sich das Format von `members` und den Listenschlüssel.
Wo lebt das Skript?

**Entscheidung:**

- **`maillist/` in diesem Repository**, übernommen **ohne** die Historie des Altrepositorys, weil
  diese ein Datenbankpasswort enthält. Produktionshosts und -adressen stehen nicht mehr im Code,
  sondern in einer Konfigurationsdatei (`maillist.ini`, Pfad über `MAILLIST_CONFIG` mit festem
  Standard). `click` und PyYAML sind durch `argparse` und `configparser` ersetzt, damit das Skript
  mit dem System-Python des Mailservers läuft; Abhängigkeit bleibt nur `mysql.connector`.
- **Eigener Installer** (`maillist/install`): Releases nebeneinander mit atomarem
  `current`-Symlink, Prüfung als Listenbenutzer mit `maillist.py --check` vor dem Umschalten,
  Rückweg über `--rollback`, eine Konfigurationsdatei, die einmal angelegt und nie überschrieben
  wird. Er ist gegen Symlink-, Hardlink- und Shared-Directory-Angriffe gehärtet.
- **Eigenes Release-Archiv:** `release.yml` baut neben dem mailadmin-Archiv
  `maillist-<tag>.tar.gz` und `install-maillist` und führt beide in `SHA256SUMS`. `maillist/`
  gehört nicht ins mailadmin-Archiv und ist im Webroot gesperrt. Der CI-Lauf prüft das Skript mit
  `pytest` und `ruff`.
- Installation, Postfix-Konfiguration und Umstellung beschreibt
  `docs/deployment/maillist.md`.

**Warum:** Das Format von `members` ist ein Vertrag zwischen zwei Programmen. Liegen beide in
einem Repository, prüfen dieselben Golden-Dateien beide Seiten (U-134), und eine Änderung an einer
Seite bricht den Test der anderen. Das Skript läuft auf dem Mailserver und nicht auf dem Webserver;
deshalb ein eigenes Archiv mit eigenem Installer statt eines Teils der mailadmin-Installation.

**Alternative:** Das Altrepository weiterführen. Dann bliebe das Passwort in dessen Historie, und
der Formatvertrag hätte zwei Kopien.

**Korrekturkosten:** Gering. Das Verzeichnis lässt sich samt Workflow-Schritten wieder herauslösen.

---

## U-142 — Das Listenskript antwortet mit `sysexits`-Codes und verliert bei Teilfehlern keine Mail

**Frage:** Postfix wertet beim `pipe(8)`-Transport den Exit-Code aus. Das Altskript endete bei
Datenbankfehlern mit `1` — Postfix bounct, und eine Mail geht verloren, die ein späterer Versuch
zugestellt hätte. Wie bildet das Skript seine Fälle ab?

**Entscheidung:**

| Fall | Code | Wirkung in Postfix |
|---|---|---|
| zugestellt, oder bewusst verworfen (Schleife, Bounce) | `0` (`EX_OK`) | erledigt |
| kein Listenschlüssel (kein Argument, keine Rückfallebene) | `64` (`EX_USAGE`) | Bounce |
| Liste nicht gefunden, oder Liste ohne brauchbaren Empfänger | `67` (`EX_NOUSER`) | Bounce |
| unerwarteter Fehler | `70` (`EX_SOFTWARE`) | Bounce, Fehlerbericht an die Admins |
| Datenbank, SMTP-Relay oder Konfiguration vorübergehend nicht nutzbar; Schlüssel trifft mehrere Listen | `75` (`EX_TEMPFAIL`) | erneuter Versuch |

- **Teilfehler beim Versand:** `75` nur, solange **kein** Empfänger angenommen wurde — dann kann
  Postfix wiederholen, ohne doppelt zuzustellen. Sobald einer angenommen ist, gilt die Mail als
  zugestellt (`0`), und die abgelehnten Empfänger gehen als Bericht an die Admins. Auch wenn alle
  Empfänger dauerhaft abgelehnt werden, gibt es `0` plus Bericht; ein Bounce würde dem Absender
  den Zustand der Mitgliederliste verraten.
- **Schleifenschutz:** Jede Liste hängt ihre Adresse als `X-Loop`-Kopf an, ohne frühere zu
  ersetzen, und verwirft eine Mail, die ihre Adresse schon trägt oder `MAX_LOOP_HOPS = 10` solcher
  Köpfe hat. So endet `A → B → A` beim zweiten Durchlauf durch A, gleich wie viele Listen
  dazwischen liegen. Zusätzlich gilt der bisherige Marker (`X-maillist`, konfigurierbar als
  `loop_marker`) zusammen mit der eigenen `List-Id`.
- Im Pipe-Modus schreibt das Skript nichts auf stdout oder stderr, weil Postfix beides in Bounces
  kopiert. Empfängeradressen erscheinen nur auf `DEBUG`.
- Den Listenschlüssel liest das Skript aus dem Argument. Die Rückfallebene `Delivered-To` gilt nur
  während der Umstellung, verlangt genau einen solchen Kopf und lässt sich mit
  `delivered_to_fallback = no` abschalten.

**Warum:** „So scheitern, dass niemand Mail verliert" gilt auch für das Skript. Ein
vorübergehender Fehler ist eine Mail in der Warteschlange, kein Bounce. Doppelte Zustellung ist
der umgekehrte Fehler und genauso zu vermeiden; deshalb wiederholt Postfix nur, wenn noch niemand
die Mail bekommen hat. Der Schleifenschutz über `X-Loop` funktioniert unter `flags=Rq`, wo
`X-Original-To` fehlt, und lässt verschachtelte Listen durch.

**Alternative:** Bei jedem Teilfehler `75`. Postfix würde wiederholen und den bereits bedienten
Empfängern noch einmal zustellen.

**Korrekturkosten:** Gering. Die Abbildung steht an wenigen `return`-Stellen in `maillist.py`,
mit Tests je Fall.

---

## U-143 — Umstellung der Altlisten, Hinweis auf den Konfigurationslauf nur im Altmodus, Einstellungen in der Betriebsübersicht

> Ändert U-59: Der Kopf `X-Mailadmin-Effect: pending-configuration-run` erscheint nur noch im
> Altmodus.

**Frage:** Bestehende Listen zeigen in `virtual` auf den Aliasweg. Wie kommen sie auf den
Transport, wie erfährt der Betrieb den Stand, und was wird aus dem Hinweis auf den
Konfigurationslauf?

**Entscheidung:**

- **`MAILADMIN_LIST_DELIVERY=alias|transport`** (Standard `alias`) sagt, ob der Aliasweg noch
  aktiv ist. Nur bei `alias` tragen schreibende Antworten den Kopf aus U-59, und
  (Nachtrag aus dem Abschlussreview) nur die auf **Anlegen und Löschen**. Geprüft am Code: Auch im
  Altmodus schreibt `CreateMailingList` die Transportform `dest = <schluessel>@<Pseudodomain>`,
  `username = ''`; eine neue Liste stellt also sofort zu, sobald Schritt 2 der Umstellung (Transport
  im Salt-State) erledigt ist, und nur ihre Zeile in der Aliasdatei folgt mit dem nächsten
  Konfigurationslauf. Beim Löschen verschwindet der `virtual`-Eintrag sofort, Mail an die Adresse
  wird nicht mehr angenommen; nur die Zeile in der Aliasdatei bleibt bis zum nächsten Lauf. Eine
  Änderung von Name, Tag oder Mitgliedern wartet auf nichts — `maillist.py` liest sie bei jeder
  Zustellung aus `lists`, die Aliasdatei enthält sie nicht —, und ihre Antwort trägt den Kopf nicht
  mehr. Die Erfolgsmeldungen sagen das je Vorgang so; die frühere Fassung („stellt nach dem nächsten
  Konfigurationslauf zu“, „stellt bis dahin weiter zu“, „wirkt nach dem nächsten Lauf“) war falsch. Die Oberfläche zeigt
  den Hinweis nur noch, wenn die Antwort den Kopf trägt; der ständige Hinweis auf jeder Listenseite,
  im Anlegedialog und in der Löschbestätigung ist entfallen. Im Transportmodus wirkt eine Änderung
  sofort.
- **Umstellungskommando `bin/migrate-list-delivery.php [--dry-run] [--revert]`** (im installierten
  System `mailadmin migrate-list-delivery`, im Checkout `composer lists:migrate-delivery`). Vorwärts
  setzt es für jede Liste die Altform `dest = username = <schluessel>` auf
  `dest = <schluessel>@<Pseudodomain>`, `username = ''`; `--revert` macht das Umgekehrte. Je Zeile
  ein `UPDATE` unter der Namensraumsperre. Ausgabe je Liste (`migrated`, `reverted`, `unchanged`,
  `no virtual entry`, `skipped: …`, `failed: …`) und Summen. Listen mit `+` (U-137) und Adressen
  mit Einträgen anderen Typs werden übersprungen, fehlende `virtual`-Einträge nicht angelegt.
- **Wiederaufnahme:** Stehen nach einem Abbruch Alt- und Transportform einer Liste nebeneinander,
  stellt der nächste Lauf die übrigen Zeilen um, statt die Liste zu überspringen. Ein zweiter Lauf
  ändert nichts.
- **Verwaltete Pseudodomain:** Steht die Pseudodomain als Zeile in `domain`, bricht der
  Vorwärtslauf vor der ersten Änderung mit `1` ab, auch im Trockenlauf. `--revert` läuft trotzdem.
- **Systemakteur:** Das Revisionsprotokoll braucht einen Akteur. `ActorContext::system($label)`
  hat die Rechte eines Superusers ohne Rolle und erscheint als `<system:migrate-list-delivery>`;
  diesen Namen kann kein angelegter Administrator tragen. Jede geänderte Zeile bekommt
  `list.delivery_migrate` bzw. `list.delivery_revert`.
- **Betriebsübersicht:** `GET /maintenance/health` liefert `list_delivery` mit `mode`,
  `transport_domain` und `transport_domain_is_managed` (Schreibweise `snake_case` wie der übrige
  Vertrag). `transport_domain_is_managed` ist `true` bei einer verwalteten Pseudodomain — die
  Oberfläche warnt dann — und `null`, wenn die Datenbank nicht antwortet. Der Block ist eine
  Einstellung, keine Prüfung, und ändert `status` nie.

**Warum:** Die Umstellung muss Liste für Liste rücknehmbar sein, und zwischen den Schritten müssen
alle Listen weiter zustellen. Ein einzelnes `UPDATE` je Zeile lässt Postfix nie einen halben oder
fehlenden Eintrag sehen. Der Hinweis auf den Konfigurationslauf war eine Eigenschaft des Aliaswegs;
mit dem Transport stimmt er nicht mehr, und ein Hinweis, der nicht stimmt, wird überlesen, wenn er
einmal wieder gilt. Die Einstellung gehört in die Betriebsübersicht, weil eine falsche
Pseudodomain sonst erst an ausbleibenden Listenmails auffällt.

**Alternative:** Eine Migration statt eines Kommandos. Migrationen sind additiv und laufen ohne
Trockenlauf; diese Umstellung ändert Bestandszeilen und muss sich zurücknehmen lassen.

**Korrekturkosten:** Gering. Das Kommando wird nach der Umstellung nicht mehr gebraucht.

---

## U-144 — Die Testumgebung stellt Listen über einen Pipe-Transport mit Stub zu

**Frage:** Wie wird Ende zu Ende geprüft, dass eine in mailadmin angelegte Liste ihre Mail mit dem
richtigen Listenschlüssel beim Skript abliefert, ohne `maillist.py` samt SMTP-Relay in die
Testumgebung zu holen?

**Entscheidung:** Postfix in `docker/mail/` bekommt den Transport `maillist` mit einem Stub, der
sein Argument und die Mail protokolliert, dazu `transport_maps`,
`maillist_destination_recipient_limit = 1` und die Sperre der direkten Adressierung
(`check_recipient_access hash:/etc/postfix/maillist_block`). Der Integrationstest legt eine Liste
an, schickt eine Mail und erwartet beim Stub `key=<schluessel>`; nach dem Löschen kommt nichts mehr
an. Ein direkter Versand an `<schluessel>@maillist.invalid` wird bei `RCPT TO` abgelehnt.

Die Sperre steht in **`smtpd_recipient_restrictions`**, nicht in `smtpd_relay_restrictions`: Der
submission-Dienst in `master.cf` überschreibt `smtpd_relay_restrictions` per `-o`, die Prüfung
wäre dort wirkungslos gewesen. `docs/deployment/maillist.md` schreibt das für die Produktion vor,
samt dem Hinweis, jede `-o smtpd_recipient_restrictions=`-Überschreibung in `master.cf` zu prüfen.

**Warum:** Was mailadmin verantwortet, endet beim Aufruf des Transports mit dem richtigen
Schlüssel. Das Skript selbst ist mit eigenen Tests abgedeckt (U-142); der Stub macht den Test
unabhängig von dessen Konfiguration und Datenbankzugang.

**Alternative:** Das echte Skript im Container. Mehr Abdeckung, aber dann bräuchte die
Testumgebung ein zweites Relay und eine Skriptkonfiguration, nur um zu zeigen, was die
Skripttests schon zeigen.

**Korrekturkosten:** Gering.

---

## U-145 — Der Listenschlüssel ist ein Name im Namensraum der Konten

**Frage:** Abschlussreview: Der Domainmaster von `example.com` legt die Liste
`info.shop@example.com` an. Ihr Schlüssel `info.shop.example.com` ist der Cyrus-Benutzername des
Kontos `info@shop.example.com` im Punktschema, das einem anderen Mandanten gehören kann. Im
Altmodus schreibt Salt `info.shop.example.com: |maillist.py` in die Aliasdatei, und local(8) liest
Aliase vor dem `mailbox_transport` — die Mail des Kontos ginge an die Liste des Angreifers.
Verhindern U-86 und U-96 das schon?

**Antwort auf die Vorfrage: nein.** U-86 und U-96 prüfen Konten und Domains gegeneinander, Listen
kamen darin nicht vor. Ein Anwendungstest mit genau diesem Fall lief vor der Änderung durch.

**Entscheidung:** Der Listenschlüssel `REPLACE(address,'@','.')` wird wie ein Benutzername im
Punktschema behandelt, in beide Richtungen und ohne Unterscheidung der Schreibweise.

- **Liste anlegen** lehnt mit `409 list.address_taken` ab (kein neuer Code: für den Aufrufer ist
  die Adresse belegt), wenn der Schlüssel der gespeicherte Benutzername eines Kontos ist, als
  `virtual.username` oder `virtual.alias` vorkommt, der Name einer Domain ist, oder ein
  Endabschnitt des Local Parts zusammen mit der Domain eine bestehende Domain ergibt — dieselbe
  Regel wie für Konten (U-86, `DottedNamespace`).
- **Konto anlegen:** `AccountStore::isTaken()` zählt den Schlüssel jeder Liste mit;
  `account.name_taken`.
- **Domain anlegen oder umbenennen:** `DomainStore::namespaceClaimant()` findet neben Konten auch
  Listen einer Elterndomain, deren Schlüssel auf `.<name>` endet; `domain.namespace_conflict`
  (U-96). Eine Liste in der neuen Domain selbst blockiert die Domain nicht — ihr Schlüssel kann
  nur mit einem Konto derselben Domain kollidieren, und das lehnt die Kontoanlage ab.
- **`migrate-list-delivery --revert`** überspringt eine Liste, deren Schlüssel ein Kontoname ist
  (`skipped: list key is the user name of an account`). Die Altform schreibt den Schlüssel in
  `virtual.username` und `dest`; `validsenders` gäbe dem Konto die Listenadresse.

Alle Abfragen sind vorbereitet, verglichen wird in Kleinschreibung wie in den bestehenden
Prüfungen.

**Warum:** Ein Name, der zwei Mandanten zugleich gehört, ist dieselbe Lücke wie in U-86 und U-96,
nur mit einer Liste auf einer Seite. Solange die Aliasdatei existiert (Altmodus und Umstellung bis
Schritt 5), entscheidet sie vor dem Postfach.

**Alternative:** Nur im Altmodus prüfen — verworfen, weil der Modus zurückgestellt werden kann und
die Aliasdatei bis Schritt 5 der Umstellung bleibt. Bestehende Kollisionen suchen und melden —
gehört zum Abgleich (A6), nicht in die Anlage.

**Korrekturkosten:** Gering. `CreateMailingList::requireFree()`/`reachesAnotherNamespace()`,
`AddressStore::isSenderName()`, `AccountRepository::isTaken()`,
`DomainRepository::namespaceClaimant()`, `MigrateListDelivery`.

---

## U-146 — Ein Transporteintrag ohne Liste ist `unknown`

**Frage:** Abschlussreview: Eine `virtual`-Zeile in genau der Transportform, zu der `lists` keine
Zeile hat, galt nach U-139 als Listeneintrag. Der Abgleich übersprang sie, die Adressverwaltung
verweigerte das Löschen (`managed_by_mailing_list`), und `DELETE /lists/{address}` fand keine
Liste. Die Zeile war unsichtbar und nicht zu entfernen, obwohl das Listenskript für ihre Adresse
nichts findet und die Mail zurückweist (`EX_NOUSER`). Entstehen kann sie nur außerhalb dieser
Anwendung, etwa durch ein händisches Löschen in `lists` oder eine abgebrochene Wiederherstellung;
Anlegen und Löschen über `/lists` halten beide Tabellen zusammen (U-138).

**Entscheidung:** Regel (1) aus U-139 verlangt zusätzlich die Zeile in `lists`. Fehlt sie, ist der
Eintrag `unknown` mit dem neuen Grund `list_missing`.

- **Abgleich:** Der Eintrag erscheint als `virtual_unclassified` mit Grund `list_missing`; keine
  neue Fundkategorie.
- **Ausweg:** Die Adressverwaltung zeigt ihn als unbekannte Zeile mit Erklärung, und wer die
  Adressen der Domain verwaltet, löscht ihn dort wie jede andere unbekannte Zeile — mit Audit,
  unter der Namensraumsperre.
- `MailingListRecognition::domainsToLookUp()` nennt jetzt auch die Domains von Zeilen mit einem
  Ziel auf der Pseudodomain, damit die Adressverwaltung deren Listenadressen lädt — weiterhin eine
  Abfrage pro Domain.

**Warum diese Variante:** Sie ist die am wenigsten eingreifende. Es gibt keinen neuen Endpunkt,
keine neue Fundkategorie und keine neue Reparaturaktion; Melden und Entfernen laufen über Wege,
die es für unbekannte Zeilen schon gibt, samt Berechtigung, Audit und Sperre. Die Anzeige wird
dabei ehrlicher: Vorher stand die Zeile als Liste in der Adressverwaltung und verwies auf eine
Listenseite, die `404` lieferte.

**Alternativen:** `DELETE /lists/{address}` entfernt die verwaiste Zeile, wenn `lists` keine hat —
verworfen, weil der Endpunkt dann etwas löscht, das er beim Lesen nicht zeigt, und weil der
Abgleich trotzdem eine eigene Meldung bräuchte. Eine Reparaturaktion im Abgleich — verworfen,
weil der Abgleich bisher nur meldet und dafür eine neue schreibende Operation samt Vertrag
entstünde.

**Korrekturkosten:** Gering. `VirtualEntryClassifier`, `MailingListRecognition`,
`UnknownReason::ListMissing`, `AddressUnknownReason` im Vertrag, ein Textschlüssel.

---

## U-147 — Das Listenskript ist in Module geteilt, flach neben `maillist.py`

**Frage:** `maillist.py` war auf knapp 900 Zeilen gewachsen: Konfiguration, Lesen von `members`,
Kopfzeilen, SMTP-Zustellung und Einstieg in einer Datei. Der Teil, der mit `MemberList` übereinstimmen
muss (U-134, U-137), war darin nicht abgegrenzt. Wie wird das Skript aufgeteilt, ohne dass Installation
und Aufruf durch Postfix unsicherer werden?

**Entscheidung:** Fünf Module nebeneinander in `maillist/`, ohne Paketverzeichnis. Der Code ist
unverändert verschoben; neu ist nur, wie `maillist.py` seine Module findet und was es tut, wenn das
misslingt.

| Modul | Inhalt |
|---|---|
| `maillist.py` | Einstieg: Argumente, Datenbankabfrage der Liste, `deliver`, `--check`, `main` |
| `maillist_config.py` | Einstellungen, `load_settings`, Logging, Pfadkonstanten und die `sysexits`-Codes |
| `maillist_members.py` | Lesen von `members` — das Gegenstück zu `MemberList`, geprüft mit denselben Golden-Dateien |
| `maillist_headers.py` | Schleifen- und Bounce-Erkennung, Listenköpfe, `sanitize_text` |
| `maillist_delivery.py` | SMTP-Zustellung und die Berichte an die Admins |

- **Namen mit Präfix:** `config` oder `headers` sind allgemein genug, um mit einem Modul gleichen
  Namens anderswo auf `sys.path` zusammenzustoßen. `maillist_` macht die Namen eindeutig; keiner ist
  ein Modul der Standardbibliothek.
- **Abhängigkeiten nur in eine Richtung:** `maillist_config` importiert keines der anderen Module,
  `maillist_headers` nur `maillist_config`, `maillist_delivery` `maillist_config` und
  `maillist_headers`, `maillist` alle. Die Exit-Codes stehen deshalb in `maillist_config` und nicht im
  Einstieg: `maillist_delivery` gibt sie auch zurück, und ein Import aus `maillist.py` lüde das als
  Skript gestartete Programm ein zweites Mal.
- **Importpfad:** `maillist.py` setzt sein eigenes, aufgelöstes Verzeichnis an die erste Stelle von
  `sys.path`, bevor es irgendetwas außer `os` und `sys` importiert. Python setzt das Verzeichnis des
  Skripts nur ohne `-I` selbst dorthin; die `master.cf`-Zeile und `--check` des Installers starten mit
  `-I`, und `-I` lässt es weg.
- **Fast immer ein Release:** Weil der `current`-Symlink aufgelöst wird, kommen die Module fast immer
  aus demselben Release-Verzeichnis wie `maillist.py`. Es bleibt ein kurzes Fenster: Schaltet ein
  Update `current` um, nachdem Python `maillist.py` geöffnet hat und bevor das Skript den Pfad
  auflöst, kommen die Module aus dem neuen Release.
- **Unbrauchbare Installation:** Scheitert ein Import — ein fehlendes Modul, eine fehlende
  `mysql.connector`, oder eine Mischung aus zwei Releases, die nicht zusammenpasst —, schreibt das
  Skript nur die feste Zeile `maillist: installation unusable` nach stderr und endet mit `75`
  (`EX_TEMPFAIL`). Postfix behält die Mail und versucht es erneut, statt dem Absender einen Traceback
  zurückzuschicken. Die `75` steht dort als Zahl, weil gerade `maillist_config` das Modul sein kann,
  das fehlt.
- **Postfix startet mit `-I`:** Die `master.cf`-Zeile lautet
  `<python> -I <prefix>/current/maillist.py -- ${user}`. So wirken weder Umgebungsvariablen noch ein
  Benutzer-`site-packages` des Listenbenutzers auf das Skript, und Postfix startet es genauso wie die
  Prüfung des Installers.
- **Installer und Release:** Der Installer führt die fünf Dateien als `CODE_FILES`. Jede wird
  kopiert, mit einem bestehenden Release verglichen und bekommt Mode `0644`; die Kennung eines
  `dev`-Releases ist ein Hash über alle fünf. Eine Quelle oder ein Archiv ohne eines der Module wird
  abgelehnt. Abhängigkeitsprüfung und `--check` laufen zusätzlich mit `-B`, damit eine Prüfung als
  Root kein `__pycache__` im Release-Verzeichnis hinterlässt. `bin/package-release` packt alle Module
  in `maillist-<tag>.tar.gz`, damit deckt die Prüfsumme in `SHA256SUMS` sie mit ab.

**Warum flach:** Ein Paketverzeichnis bräuchte einen Starter, der das Paket importiert, oder
`python3 -m`, und damit eine andere Form der `argv`-Zeile. Flache Module lassen das Skript eine
einzelne Datei sein, die Postfix aufruft, und der Installer behandelt sie wie die übrigen
Release-Dateien.

**Warum der Pfad im Skript gesetzt wird statt `--check` ohne `-I` zu starten:** `-I` gehört zur
Härtung (Umgebung und Arbeitsverzeichnis des Aufrufers wirken nicht). Setzt das Skript seinen Pfad
selbst, bleibt diese Härtung, beim Installer wie in `master.cf`, und das Skript läuft unabhängig davon,
mit welchen Schaltern der Interpreter gestartet wird — auch eine ältere `master.cf`-Zeile ohne `-I`
funktioniert weiter.

**Alternative:** `--check` ohne `-I`, aber mit `cwd="/"` und leerer Umgebung. Das gibt die Härtung
auf und ließe ein Skript, das nur ohne `-I` läuft, unentdeckt.

**Korrekturkosten:** Gering. Die Module lassen sich wieder zu einer Datei zusammenführen; Installer
und Paketierung lesen die Liste aus je einer Konstante.

---

## Verbleibende offene Punkte

Diese sind **nicht** entschieden, weil sie ohne Betriebswissen nicht sinnvoll zu entscheiden
sind. Sie blockieren die Implementierung nicht.

| # | Punkt | Wer entscheidet |
|---|---|---|
| O-1 | Lizenzwahl bestätigen (U-23) — AGPL hat geschäftliche Folgen | Projekteigner |
| O-2 | Property-basierte Vertragstests (Schemathesis) nachrüsten oder verzichten (U-16) | Projekteigner |
| O-3 | Wurzel-`README.md` — Kurzfassung für die Repository-Startseite | Projekteigner |
| O-4 | Ob `MAILADMIN_AUTO_MIGRATE` in der ausgelieferten `.env.example` überhaupt erscheint | Betrieb |
| O-5 | Ob Redoc in der Zielinstallation eingeschaltet wird (U-9) | Betrieb |
| O-6 | Läuft die Installation hinter einem Reverse Proxy? Dann ist die Auswertung von `X-Forwarded-For` nachzurüsten, sonst zählt der Missbrauchsschutz alle Anfragen auf eine IP (U-27) | Betrieb |
| O-7 | Steht der vHost auf `AllowOverride All`? Sonst ist der `.htaccess`-Schutz wirkungslos (U-25). Mit `bin/check-deployment.php` prüfbar | Betrieb |
| ~~O-8~~ | ~~Ordnername im `fqun`-Schema~~ — **erledigt.** Gegen Cyrus 3.10 gemessen, die Annahme war richtig. Siehe U-38 | — |
| ~~O-9~~ | ~~Spool-Pfad-Darstellung~~ — **erledigt, soweit von außen prüfbar.** Siehe U-38 | — |
| **O-14** | **`allowstarttls: yes` auf dem Zielserver.** Cyrus hat es ab Werk aus; ohne die Einstellung ist ManageSieve nur im Klartext erreichbar (U-38 a). Vor der Inbetriebnahme von M3 zu setzen | **Betrieb** |
| **O-15** | **`allowusermoves: yes` auf dem Zielserver.** Ab Werk aus; ohne die Einstellung kann kein Konto und keine Domain umbenannt werden, und die Anwendung könnte die Datenbankhälfte ohne die Mailboxhälfte ausführen (U-38 b). Vor A1 zu klären | **Betrieb** |
| O-10 | Ob eine Wortliste häufiger Passwörter (U-21) mitgeliefert wird und in welchem Umfang — sie vergrößert das Repository | Projekteigner |
| **O-11** | **Speicher-Engine der Bestandstabellen — siehe unten. Sollte vor Inbetriebnahme entschieden sein.** | **Betrieb** |
| O-12 | Bleibt es bei „kein Redoc" (U-30)? Das CSP-Argument ist durch U-35 entfallen; die Entscheidung ruht nur noch auf der Repository-Größe | Projekteigner |
| **O-16** | **Sind `accountuser.password` und `adminuser.password` auf der Zielinstallation noch `latin1`?** Migration 0003 schreibt Zeichensatz und Kollation explizit mit (U-42). Wurde die Datenbank zwischenzeitlich auf utf8mb4 umgestellt, würde der Lauf die Werte umkodieren — für jedes Hashformat harmlos, nicht aber für ein **Klartextpasswort** mit einem Zeichen jenseits von ASCII, und Klartext ist eines der Bestandsformate. `php bin/db-check.php` meldet die tatsächliche Definition beider Spalten und warnt, bevor etwas läuft | **Betrieb** |
| ~~O-17~~ | ~~Kontosperre (U-78)~~ — **erledigt durch U-127.** ~~Eine Sperre, die die Anmeldung wirklich verhindert, braucht eine Änderung am View `allusers` oder ein gekennzeichnetes Hashformat in `accountuser` — beides Eskalationsfälle. Bis dahin bietet A2 keine Deaktivierung, und die Dienstschalter `imap`/`pop`/`sieve`/`smtpauth` werden getragen, aber von keiner Komponente durchgesetzt~~ | — |
| **O-18** | **`sender_canonical_maps` und das Senderecht von Aliasen (A3).** Die Bestandsabfrage wählt mit `username LIKE '%s' … LIMIT 1` ohne Ordnung **irgendeine** aktive Zeile, deren `username` den Absender nennt, und schreibt den Absender auf deren `alias` um. Im `fqun`-Schema ist der Absender zugleich der Benutzername: Jeder Alias, der dem Konto Senderecht gibt (U-106, wie Webcyradms „weitere Adresse“), kann so zur Absenderadresse des Kontos werden — auf MyISAM je nachdem, welche Lücke eine neue Zeile füllt. `_` und `%` im Benutzernamen wirken als Platzhalter. A3 schreibt `username` genau wie der Bestand und ändert an der Postfix-Abfrage nichts; ob sie auf die Kontoadresse eingeschränkt oder sortiert wird, ist eine Betriebsentscheidung (Salt) | **Betrieb** |
| ~~O-19~~ | ~~A2s `DeleteAccount` und der Catchall (U-112)~~ — **erledigt durch U-128.** ~~Das Löschen eines Kontos entfernt dessen Adresszeilen, ohne zu prüfen, ob der Catchall der Domain auf eine davon zeigt. Danach fällt die unbekannte Mail der Domain auf eine Adresse ohne Postfach. Nachrüstbar mit `LoopGuard::requireCatchallKeepsWorking()` in A2; ob das Löschen dann abgelehnt oder der Catchall mit entfernt wird, ist eine fachliche Wahl~~ | — |
| O-20 | **Catchalls anderer Domains beim Löschen eines Kontos (U-128).** Zeigt der Catchall einer *anderen* Domain auf das gelöschte Konto, bleibt er unverändert und zeigt danach auf eine Adresse ohne Postfach. Ihn mitzuändern hieße, in eine Domain einzugreifen, die der Akteur womöglich nicht verwaltet. Ob in diesem Fall abgelehnt, nur für Superuser mitgeändert oder hingenommen wird, ist eine fachliche Wahl. Seit U-129 gilt dasselbe für eine fremde Zeile, die das Konto als Absender nennt: Sie bleibt samt `username` stehen und hält den Namen belegt | Projekteigner |
| **O-21** | **Kontosperre ausrollen (U-127).** Salt muss den View `allusers` nach `docs/deployment/allusers.sql.example` anpassen, **nach** Migration 0060; vorher lehnt mailadmin jede Sperre mit `account.status_not_enforced` ab. Zu prüfen ist dabei, ob `saslauthd` mit Credential-Cache (`-c`) läuft — dann wirkt eine Sperre erst nach Ablauf des Caches. Der Datenbankbenutzer von mailadmin braucht `SELECT` auf `allusers` (mit `SELECT` auf die ganze Datenbank gegeben) für die Prüfung vor jeder Sperre, `SHOW VIEW` nur für die Anzeige in `bin/db-check.php` | **Betrieb** |
| ~~O-13~~ | ~~Die statisch ausgelieferte `index.html` trägt keinen CSP-Header~~ — **erledigt durch W1.** `public/.htaccess` und beide vHost-Beispiele setzen die Header aus Architektur 5.7 mit `always`; `tests/Unit/Deployment/ShellSecurityHeadersTest.php` hält die vier Fassungen deckungsgleich, und `bin/check-deployment.php` weist an einer laufenden Installation nach, dass sie ankommen | — |

### O-11 im Einzelnen: MyISAM bricht eine Architekturannahme

Bei der Umsetzung von F3 aufgefallen und wichtig genug für eine eigene Darstellung.

`webcyradm/scripts/create_mysql.sql` legt die Bestandstabellen als **MyISAM** an. MyISAM kennt
keine Transaktionen — ein `BEGIN`/`COMMIT` darüber tut nichts, und ein `ROLLBACK` nimmt nichts
zurück. **Stillschweigend**: es gibt keine Fehlermeldung.

Architektur 8.1 baut aber genau darauf auf:

```text
Konto anlegen:  2. DB-Transaktion: INSERT accountuser + INSERT virtual, commit
Konto löschen:  1. DB-Transaktion: DELETE virtual + DELETE accountuser, commit
```

Ist die Engine im Zielsystem noch MyISAM, ist dieser Schritt **nicht atomar**. Scheitert der
zweite `INSERT`, bleibt der erste stehen: ein Konto ohne Adresse oder eine Adresse ohne Konto.
A2 und A3 können die vorgeschriebene Reihenfolge dann nicht einhalten, weil ihr Fundament fehlt.

**Die Anwendung darf das nicht selbst beheben.** `ALTER TABLE … ENGINE=InnoDB` ist keine additive
Migration und verstößt gegen E-1 — und die Tabellen werden von Postfix und `pam_mysql` gelesen,
eine Konvertierung unter Last ist nichts, was ein Werkzeug nebenbei tut.

Deshalb: `bin/db-check.php` **meldet** Engine, Zeichensatz und Kollation der Bestandstabellen mit
Begründung. Drei Wege stehen offen:

1. **Konvertieren** (`ALTER TABLE … ENGINE=InnoDB`) im Wartungsfenster, außerhalb von mailadmin.
   Der sauberste Weg — danach gilt Architektur 8.1 wie geschrieben.
2. **So lassen** und in Kauf nehmen, dass ein halb ausgeführter Datenbankschritt möglich ist. Die
   Reparaturwarteschlange (A6) macht das Ergebnis sichtbar, verhindert es aber nicht.
3. **Prüfen, ob die Annahme überhaupt zutrifft** — die Produktivdatenbank kann längst konvertiert
   sein. `bin/db-check.php` beantwortet das in einem Lauf, lesend.

Weg 3 zuerst; erst danach ist die Frage überhaupt eine.
