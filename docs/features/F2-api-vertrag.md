<!-- markdownlint-disable MD013 MD024 MD060 -->

# F2 — API-Vertrag und Dispatcher

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 4 und 7 |
| **Status** | offen |

---

## Ziel

`openapi/openapi.yaml` ist die Quelle für Routing und Validierung. Ein Build-Schritt erzeugt
die Routentabelle aus den `operationId`-Werten; eingehende Anfragen werden gegen das Schema
geprüft; Fehler kommen einheitlich als `problem+json` zurück.

## Abgrenzung

- Keine fachlichen Endpunkte — die kommen mit den A-Paketen.
- Keine Durchsetzung von `x-permission` — das ist **S3**. F2 stellt nur sicher, dass die
  Deklaration vorhanden ist und der Build sonst scheitert.
- Keine TypeScript-Generierung — das ist **W1**.

## Abhängigkeiten und geteilte Dateien

`openapi/openapi.yaml` wird von **jedem** A-Paket erweitert. F2 legt Aufbau, Namenskonventionen
und wiederverwendbare Komponenten fest, an die sich alle halten.

## Vorgaben

- Design-first. Das Schema wird von Hand geschrieben, nicht aus Annotationen erzeugt (E-11).
- Fehlt zu einer `operationId` ein Handler oder umgekehrt: Buildfehler.
- Jede Operation trägt `operationId` und `x-permission`.
- Fehlerform einheitlich als wiederverwendbare Komponente, inklusive stabilem `code`-Feld
  (E-16).
- Sortier- und Filterwerte werden im Schema aufgezählt, nie frei übernommen.

## Artefakte

- `openapi/openapi.yaml` — Grundgerüst mit `info`, `servers`, `securitySchemes`, den
  gemeinsamen Komponenten (`Problem`, `Pagination`, `ETag`-Parameter) und `/health`
- `bin/compile-routes` — erzeugt die Routentabelle nach `var/cache/`
- `src/Http/Dispatcher.php` — Zuordnung `operationId` → Handler
- `src/Http/Middleware/OpenApiValidation.php`
- `src/Http/ProblemResponseFactory.php`
- Handler-Schnittstelle, an die sich alle Handler halten

## Zu klärende Punkte

1. **OpenAPI 3.1 oder 3.0?** `league/openapi-psr7-validator` unterstützt 3.0 vollständig,
   3.1 nur eingeschränkt. Entweder auf 3.0 gehen oder einen anderen Validator wählen — das
   sollte **vor** dem ersten Schema-Entwurf entschieden sein, ein späterer Wechsel ist teuer.
2. **Namensschema der Fehlercodes.** Vorschlag `<ressource>.<zustand>`, etwa
   `account.quota_exceeded`, `domain.not_found`. Festlegen und dokumentieren, sonst wächst
   Wildwuchs.
3. **Wann läuft der Build?** Composer-Skript, Makefile, CI — und wie wird verhindert, dass
   eine veraltete Routentabelle in Produktion landet? Vorschlag: Prüfsumme des Schemas in der
   kompilierten Datei, Abgleich beim Start.
4. **Antwortvalidierung in Produktion:** aus (Leistung) oder an (Fehlerfrüherkennung)? Der
   Entwurf sagt „nur Entwicklung und Test" — bestätigen.
5. **Dokumentation ausliefern?** Redoc unter `/api/docs` — in Produktion an oder aus? Sie
   verrät die vollständige Angriffsfläche.
6. **Versionierung.** `/api/v1` im Pfad ist gesetzt. Was passiert bei v2 — parallel betreiben
   oder harter Schnitt?

## Definition of Done

- Ein Schema mit `/health` erzeugt eine funktionierende Route.
- Eine Operation ohne Handler bricht den Build mit klarer Meldung ab; ein Handler ohne
  Operation ebenso.
- Eine Operation ohne `x-permission` bricht den Build ab.
- Eine schemawidrige Anfrage wird mit `422` und Feldzuordnung abgelehnt, bevor ein Handler
  läuft.
- Das Schema validiert gegen die OpenAPI-Spezifikation (Linter im Build).

## Testfälle

- **Unit:** Routenkompilierung — fehlender Handler, fehlende `x-permission`, Duplikate.
- **Vertrag:** `/health` gegen das Schema.
- **Integration:** ungültiger Anfragekörper ergibt `422` mit `errors`-Abbildung; unbekannter
  Pfad ergibt `404` als `problem+json`.
