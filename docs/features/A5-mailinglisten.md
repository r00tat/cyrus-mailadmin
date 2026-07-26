<!-- markdownlint-disable MD013 MD024 MD060 -->

# A5 — Mailinglisten

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | F2, F3, S3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 7.2 · E-4 |
| **Status** | offen |

---

## Ziel

Die Tabelle `lists` über die Oberfläche pflegbar machen: Listen anlegen, Mitglieder ändern,
Listen löschen.

## Abgrenzung

- **Die Anwendung erzeugt keine Aliasdatei.** Das übernimmt das Konfigurationsmanagement, das
  die Tabelle ausliest (E-4). Die Anwendung schreibt ausschließlich die Datenbank.
- Keine Listensoftware, keine Zustellung, keine Archive.

## Abhängigkeiten und geteilte Dateien

Eigenständig. Berührt nur `lists`.

## Vorgaben

- `lists` ist eine Bestandstabelle: `id`, `name`, `tag`, `address`, `members`. Struktur bleibt
  unverändert (E-1).
- Änderungen wirken erst nach dem nächsten Konfigurationslauf — die Oberfläche sagt das.

## Artefakte

- Endpunkte `GET/POST /lists`, `GET/PATCH/DELETE /lists/{id}`
- `Application/MailingList/` — Anwendungsfälle
- `Infrastructure/Persistence/ListRepository.php`
- Oberfläche: Liste, Bearbeiten mit Mitgliederfeld

## Zu klärende Punkte

1. **Format des Feldes `members`.** Es ist ein `text`. Wie sind Einträge getrennt — Komma,
   Zeilenumbruch, Semikolon? **Am Bestand prüfen**, bevor irgendetwas geschrieben wird; ein
   falsches Trennzeichen macht bestehende Listen unbrauchbar.
2. **Bedeutung von `tag`.** Aus dem Schema nicht ersichtlich. Klären, wofür das Feld verwendet
   wird, bevor die Oberfläche es anbietet.
3. **Wer darf Listen verwalten?** Superuser allein, oder auch Domainmaster für Listen in ihrer
   Domain? Die Tabelle hat keine Domainspalte — der Zuschnitt müsste aus `address` abgeleitet
   werden.
4. **Validierung der Mitgliederadressen.** Streng prüfen und ablehnen, oder nur warnen? Im
   Bestand könnten ungültige Einträge stehen, die beim ersten Speichern sonst verloren gehen.
5. **Hinweis auf den Konfigurationslauf.** Wie deutlich? Ohne ihn wirkt eine neue Liste nicht,
   und der Nutzer sucht den Fehler an der falschen Stelle.

## Definition of Done

- Listen lassen sich anlegen, ändern und löschen, ohne dass bestehende Einträge beschädigt
  werden.
- Das Format von `members` entspricht exakt dem, was das Konfigurationsmanagement erwartet.
- Der Hinweis auf den nötigen Konfigurationslauf erscheint nach jeder Änderung.
- Änderungen sind protokolliert.

## Testfälle

- **Unit:** Zerlegen und Zusammensetzen des Mitgliederfelds, verlustfrei.
- **Integration:** eine Bestandsliste lesen, unverändert speichern, Vergleich Byte für Byte.
- **Integration:** Berechtigungsprüfung je nach Antwort auf Punkt 3.
