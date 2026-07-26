<!-- markdownlint-disable MD013 MD024 MD060 -->

# F1 — Projektgerüst und Konfiguration

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | — |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 3 und 3.1 |
| **Status** | offen |

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

## Zu klärende Punkte

1. **Mindest-PHP-Version.** 8.1 (breiteste Kompatibilität) oder 8.3 (readonly classes,
   typed constants)? Die Zielumgebung hat 8.3.
2. **Wie werden Umgebungsvariablen gelesen?** `getenv()` direkt genügt und spart eine
   Abhängigkeit; `.env`-Dateien im Entwicklungsbetrieb bräuchten `vlucas/phpdotenv`. Wenn nur
   die Entwicklung sie braucht: als `require-dev` aufnehmen?
3. **Log-Ziel.** `stderr` (containerfreundlich) oder Datei? Bei Auslieferung als Verzeichnis
   im Web-Root (E-8) ist `stderr` nicht selbstverständlich sichtbar.
4. **Schreibbares `var/`-Verzeichnis.** Die kompilierte Routentabelle und die geparste
   Spezifikation brauchen einen Cache. Wo liegt der, wem gehört er, und was passiert, wenn er
   nicht beschreibbar ist — Fehler oder stiller Verzicht auf den Cache?
5. **Zeitzone und Zeitquelle.** UTC intern, Anzeige lokal? Betrifft Revisionsprotokoll und
   Token-Gültigkeit.

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
