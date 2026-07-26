<!-- markdownlint-disable MD013 MD024 MD060 -->

# F3 — Persistenz und Migrator

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 10 |
| **Status** | offen |

---

## Ziel

Datenbankzugriff über PDO mit Prepared Statements, eine Repository-Basis und ein eigener
Migrator, der die `mailadmin_`-Tabellen anlegt, ohne den webcyradm-Bestand zu verändern.

## Abgrenzung

- Keine fachlichen Repositories — die gehören zu den A-Paketen.
- Keine Änderung an Bestandstabellen oder Views. **Eskalationsfall.**

## Abhängigkeiten und geteilte Dateien

`migrations/` wird von mehreren Paketen erweitert (S2 Token-Tabellen, S4 Audit, A6
Reparaturwarteschlange, W2 Spracheinstellung). Die Nummerierung muss kollisionsfrei bleiben.

## Vorgaben

- `PDO::ATTR_ERRMODE = EXCEPTION`, `ATTR_EMULATE_PREPARES = false`.
- Migrationen rein additiv. Kein `DROP`, kein `RENAME`, keine Typverengung (E-1).
- Neue Tabellen mit Präfix `mailadmin_`.
- Ausführung über `bin/migrate` unter Datenbanksperre; die Web-Anwendung prüft nur die Version
  und verweigert bei Rückstand den Dienst.
- Kein ORM (Architektur 3.2).

## Artefakte

- `src/Infrastructure/Persistence/ConnectionFactory.php`
- `src/Infrastructure/Persistence/Repository.php` — gemeinsame Basis
- `src/Infrastructure/Persistence/Migrator.php`
- `bin/migrate`, `bin/migrate-status`
- Migration 001: `mailadmin_schema_version`
- Middleware, die bei Versionsrückstand mit einer klaren Meldung abweist

## Zu klärende Punkte

1. **Zeichensatz und Kollation der neuen Tabellen.** Diese müssen zum Bestand passen, sonst
   scheitern Verknüpfungen zwischen `mailadmin_*` und webcyradm-Tabellen an unterschiedlichen
   Kollationen. **Vor der ersten Migration am Zielsystem prüfen**, welchen Zeichensatz
   `accountuser` und `domain` tatsächlich haben.
2. **Format der Migrationen.** Reine SQL-Dateien (einfach lesbar, keine Logik) oder
   PHP-Klassen (können Daten umformen)? Für rein additive Änderungen genügt SQL.
3. **Sperrmechanismus.** MySQLs `GET_LOCK()` ist naheliegend und braucht keine Tabelle.
   Verhalten bei Zeitüberschreitung festlegen.
4. **Verhalten bei Versionsrückstand.** `503` mit `Retry-After`, oder `500`? Und gilt das auch
   für `/health`, oder muss der gerade dann antworten?
5. **Rückwärtsmigration.** Gibt es sie? Vorschlag: nein — vorwärtsgerichtet, und für den
   Rückweg der Datenbank-Dump. Das entspricht E-1.
6. **`MAILADMIN_AUTO_MIGRATE`.** Standardmäßig aus. Bestätigen, und festlegen, wie die Sperre
   im Web-Kontext gehalten wird.

## Definition of Done

- `bin/migrate` legt `mailadmin_schema_version` an und ist mehrfach ausführbar, ohne Schaden
  anzurichten.
- Zwei gleichzeitige Migrationsläufe führen nicht zu doppelter Ausführung.
- Die Anwendung verweigert bei Versionsrückstand den Dienst mit einer verständlichen Meldung.
- Kein Statement im Code setzt SQL aus Eingaben zusammen.

## Testfälle

- **Unit:** Migrator — Erkennung eines unmigrierten Bestands, Idempotenz, Reihenfolge.
- **Integration:** Migration gegen ein Schema im **webcyradm-Ausgangszustand**, nicht gegen
  eine leere Datenbank (siehe F5).
- **Integration:** gleichzeitige Läufe unter Sperre.
