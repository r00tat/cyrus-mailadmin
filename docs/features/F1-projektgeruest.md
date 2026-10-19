<!-- markdownlint-disable MD013 MD024 MD060 -->

# F1 — Projektgerüst und Konfiguration

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | — |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 3 und 3.1 |
| **Status** | fertig |

---

## Ziel

Ein lauffähiges PHP-Projekt: Front-Controller, PSR-15-Middleware-Kette, Container,
Konfiguration aus der Umgebung, strukturiertes Logging, einheitliche Fehlerbehandlung.
Am Ende antwortet ein Aufruf mit einer wohlgeformten JSON-Antwort.

## Abgrenzung

- Kein Routing aus dem OpenAPI-Schema — das ist **F2**.
- Keine Datenbankverbindung — das ist **F3**.
- Kein Frontend-Build — das ist **W1**.

## Abhängigkeiten und geteilte Dateien

Keine Vorgänger. `public/index.php`, die Middleware-Kette und der Container werden von fast
allen Paketen berührt; Änderungen daran gehören abgestimmt.

## Vorgaben

- Verzeichnisstruktur nach Architektur, Abschnitt 3. Nur `public/` liegt im Web-Root.
- Abhängigkeitsliste nach Abschnitt 3.1. Keine weiteren Pakete ohne Begründung (E-12).
- Konfiguration ausschließlich aus Umgebungsvariablen. `.env` bleibt ungetrackt,
  `.env.example` mit neutralen Platzhaltern gehört ins Repository.
- Keine Ausnahmemeldung erreicht den Client. Unbehandelte Fehler werden geloggt und als
  generisches `problem+json` mit `500` beantwortet.

## Artefakte

- `composer.json` mit der festgelegten Abhängigkeitsliste
- `public/index.php` — Front-Controller
- `src/Http/MiddlewareDispatcher.php` — PSR-15-Kette
- `src/Http/Middleware/` — RequestId, ErrorHandler, SecurityHeaders, JsonBody
- `config/` — Container-Definitionen, Konfigurationsobjekt aus der Umgebung
- `.env.example`, `.gitignore`-Ergänzungen
- `bin/` — Einstiegspunkte für CLI-Kommandos

### Was Folgepakete daran anknüpfen

| Ort | Erweiterungspunkt |
|---|---|
| `config/di/<paket>.php` | Eine Datei je Paket, gibt ein Array zurück. Der Container liest `config/di/*.php` per `glob`, mischt alphabetisch und **bricht bei einem doppelten Schlüssel ab** (U-5). Nur eintragen, was Autowiring nicht auflöst. |
| `config/di/core.php`, Block `MiddlewareDispatcher` | Die einzige Zeile, die F2 ändern soll: `NotFoundHandler` als innersten Handler durch den aus dem Vertrag kompilierten Dispatcher ersetzen, Schema-Validierung zwischen `JsonBody` und Router einsetzen. Die Reihenfolge der vier Middlewares ist dort begründet. |
| `composer.json`, Skript `build` | Ein Array. F2 hängt Bündelung und Routenkompilierung an; `build:cache` bleibt der erste Schritt. Läuft auch als `post-install-cmd`/`post-update-cmd` (U-8). |
| `bin/bootstrap.php` | `$container = require __DIR__ . '/bootstrap.php';` — mehr braucht ein CLI-Kommando nicht. Setzt UTC und prüft die Konfiguration. Dateien in `bin/` tragen `.php`, damit PHPStan und PHP-CS-Fixer sie erfassen. |
| `Mailadmin\Runtime\CacheDirectory` | `file('name')` für einen Artefaktpfad; `ensureUsable()` prüft (U-13). |
| `Mailadmin\Config\Config` | Typisierte Zugriffe (`string`, `int`, `bool`, `enum`, jeweils mit `…OrDefault`). Neue Variablen als Konstante ergänzen und in `.env.example` dokumentieren. |
| `Mailadmin\Http\Problem\ProblemDetails` | Jede Fehlerantwort. `ErrorCode` enthält bisher nur die Querschnittscodes des Rahmens — F2 nimmt sie in das `ErrorCode`-`enum` des Vertrags auf (U-7). |
| `Mailadmin\Log\SecretMaskingProcessor` | Maskiert Geheimfelder in jedem Log-Eintrag. Neue Geheimfeldnamen ergänzen; Formatangaben (`password_format` u. ä.) stehen in einer engen Ausnahmeliste, weil U-22 sie sichtbar verlangt. |
| `tests/Support/` | `CallTrace`, `RecordingMiddleware`, `AttributeMiddleware`, `CallableHandler` für Middleware-Tests. |

## Zu klärende Punkte

Alle fünf Punkte sind in [05-umsetzungsentscheidungen.md](../05-umsetzungsentscheidungen.md)
entschieden. Die Antworten stehen jeweils unter der Frage.

1. **Mindest-PHP-Version.** 8.1 (breiteste Kompatibilität) oder 8.3 (readonly classes,
   typed constants)? Die Zielumgebung hat 8.3.

   > **Beantwortet (U-1): PHP 8.3.** `composer.json` verlangt `"php": ">=8.3"`. Das Fachmodell
   > besteht überwiegend aus unveränderlichen Value Objects; `readonly class` und typisierte
   > Klassenkonstanten ersparen dort Boilerplate, den man sonst prüfen müsste. Beides wird im
   > Gerüst bereits verwendet (`Paths`, `ProblemDetails`, `Config`-Konstanten).

2. **Wie werden Umgebungsvariablen gelesen?** `getenv()` direkt genügt und spart eine
   Abhängigkeit; `.env`-Dateien im Entwicklungsbetrieb bräuchten `vlucas/phpdotenv`. Wenn nur
   die Entwicklung sie braucht: als `require-dev` aufnehmen?

   > **Beantwortet (U-11): eigenes `Config`-Objekt über `$_ENV`/`getenv()`, `phpdotenv` als
   > `require-dev`.** `Mailadmin\Config\Config` ist die einzige Stelle, die die Prozessumgebung
   > liest. `Mailadmin\Config\DotenvLoader` lädt eine `.env` nur, wenn alle drei Bedingungen
   > gelten: `MAILADMIN_ENV` ist nicht `prod`, das Paket ist installiert, die Datei existiert.
   > In Produktion ist keine `.env` beteiligt, und die Abhängigkeit liegt nicht im
   > Produktionsbaum.
   >
   > `MAILADMIN_ENV` ist die **Pflichtvariable**: fehlt sie oder ist sie unbekannt, startet die
   > Anwendung nicht. Ein leerer Wert gilt als „nicht gesetzt" — Deployment-Werkzeuge exportieren
   > regelmäßig leere Variablen.

3. **Log-Ziel.** `stderr` (containerfreundlich) oder Datei? Bei Auslieferung als Verzeichnis
   im Web-Root (E-8) ist `stderr` nicht selbstverständlich sichtbar.

   > **Beantwortet (U-12): `stderr` als Vorgabe, umschaltbar auf Datei.** Monolog mit
   > `JsonFormatter`, eine Zeile je Eintrag. `MAILADMIN_LOG_TARGET` nimmt einen absoluten
   > Dateipfad an; dann schreibt ein `RotatingFileHandler` (14 Tage) dorthin. Ist das Ziel nicht
   > beschreibbar, fällt die Anwendung auf `stderr` zurück und meldet das einmalig — ein Logziel
   > ist kein Grund, den Dienst zu verweigern (anders als der Cache, siehe Punkt 4).
   >
   > Die Prüfung geschieht vorab, nicht erst beim ersten Schreiben: Monolog öffnet den Stream
   > verzögert, sonst würde der Fehler ausgerechnet in dem Moment auftreten, in dem schon etwas
   > anderes schiefgeht.

4. **Schreibbares `var/`-Verzeichnis.** Die kompilierte Routentabelle und die geparste
   Spezifikation brauchen einen Cache. Wo liegt der, wem gehört er, und was passiert, wenn er
   nicht beschreibbar ist — Fehler oder stiller Verzicht auf den Cache?

   > **Beantwortet (U-13): `<projekt>/var/cache`, überschreibbar über `MAILADMIN_CACHE_DIR`,
   > und ein fehlender oder nicht beschreibbarer Cache ist ein Startfehler.** Kein stiller
   > Verzicht: das würde ein Konfigurationsproblem in ein Leistungsproblem verschieben, das erst
   > unter Last auffällt.
   >
   > Die Artefakte sind **Build-Ergebnisse** (`composer build`) und werden vom Deployment
   > mitgeliefert. Außerhalb der Produktion legt der Start das Verzeichnis bei Bedarf an — ein
   > frischer Checkout soll kein `mkdir` von Hand brauchen. In Produktion bedeutet ein fehlendes
   > Verzeichnis, dass das Deployment unvollständig ist, und der Start scheitert mit einer
   > Meldung, die den Pfad und `MAILADMIN_CACHE_DIR` nennt.
   >
   > `var/` selbst ist eingecheckt (nur `var/.gitignore`), damit es in einem frischen Checkout
   > existiert; sein Inhalt nicht.

5. **Zeitzone und Zeitquelle.** UTC intern, Anzeige lokal? Betrifft Revisionsprotokoll und
   Token-Gültigkeit.

   > **Beantwortet (U-14): UTC überall, Formatierung erst im Frontend.**
   > `date_default_timezone_set('UTC')` steht im Front-Controller und in
   > `Mailadmin\Kernel\Bootstrap::readEnvironment()`, das sowohl `config/bootstrap.php` als auch
   > jedes Kommando unter `bin/` durchläuft — eine Migration oder ein Reparaturlauf schreibt
   > ebenfalls Zeitstempel. Ein Integrationstest prüft, dass die Zeitzone nach dem Start `UTC`
   > ist.
   >
   > Was daran hängt und in späteren Paketen umzusetzen ist: `SET time_zone = '+00:00'` auf der
   > Verbindung (F3), `DATETIME` statt `TIMESTAMP` für neue Spalten, ISO-8601 mit `Z` in der API,
   > Ortszeit ausschließlich über `Intl` im Frontend.

## Definition of Done

- `composer install` läuft ohne Fehler auf der Zielversion.
- Ein Aufruf gegen den Front-Controller liefert eine JSON-Antwort mit `X-Request-Id`.
- Ein absichtlich ausgelöster Fehler erzeugt einen Log-Eintrag und eine generische
  `problem+json`-Antwort ohne Ausnahmedetails.
- Alle Sicherheits-Header aus Architektur 5.7 sind gesetzt.
- Fehlt eine Pflicht-Umgebungsvariable, startet die Anwendung mit einer klaren Meldung nicht.

## Testfälle

- **Unit:** Konfigurationsobjekt — fehlende Variable, Standardwerte, Typumwandlung.
- **Unit:** Middleware-Kette — Reihenfolge, Kurzschluss, Fehlerdurchreichung.
- **Integration:** Fehlerbehandler wandelt eine Ausnahme in `problem+json` ohne Klartext.
