<!-- markdownlint-disable MD013 MD024 MD060 -->

# M1 — IMAP-Client

| | |
|---|---|
| **Stufe** | 3 — Mailstore |
| **Hängt ab von** | F1, F4 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 9.2 |
| **Status** | offen |

---

## Ziel

Ein kleiner, getesteter IMAP-Client für Adminkommandos: TLS mit Zertifikatsprüfung, eindeutige
Command-Tags, korrekte Literale, Zeitüberschreitungen, Wiederverwendung der Verbindung.

## Abgrenzung

- Keine Mailstore-Fachlogik — das ist **M2**.
- Kein ManageSieve — das ist **M3**.
- Kein Postfachzugriff für Endnutzer. Nur Administration.

## Abhängigkeiten und geteilte Dateien

Wird von M2 und M3 genutzt. Integrationstests laufen gegen F5.

## Vorgaben

- **`ext-imap` wird nicht verwendet** — ab PHP 8.4 nicht mehr im Kern und für Adminkommandos
  ungeeignet.
- TLS ist Pflicht, mit Zertifikatsprüfung. Kein Schalter zum Abschalten, auch nicht für Tests
  — die Testumgebung bringt eine eigene CA mit.
- Eindeutige Command-Tags statt eines konstanten Präfixes.
- Zeitüberschreitungen auf Verbindungsaufbau und Lesevorgang.
- Fehler des Servers werden zu typisierten Ausnahmen, nicht zu Rückgabewerten.

## Artefakte

- `Infrastructure/Mailstore/Imap/Connection.php`
- `Infrastructure/Mailstore/Imap/CommandTag.php`
- `Infrastructure/Mailstore/Imap/ResponseParser.php`
- `Infrastructure/Mailstore/Imap/Literal.php` — Quoting und Literale
- Ausnahmehierarchie: Verbindungsfehler, Anmeldefehler, Kommandofehler, Zeitüberschreitung
- `Infrastructure/Mailstore/Imap/ConnectionPool.php` — Wiederverwendung je Anfrage

## Zu klärende Punkte

1. **IMAPS oder STARTTLS?** Beides ist verfügbar. Vorschlag: IMAPS auf 993 als Standard,
   STARTTLS konfigurierbar.
2. **Mindest-Cyrus-Version.** 3.x ist gesetzt, aber Antwortformate unterscheiden sich zwischen
   Unterversionen — insbesondere bei `GETQUOTA` und `GETACL`. Welche Versionen werden zugesagt?
3. **Zeichensatz für Ordnernamen.** Cyrus erwartet modifiziertes UTF-7 für Mailboxnamen mit
   Sonderzeichen. Wird das unterstützt, oder beschränken wir Ordnernamen auf ASCII?
4. **Verbindungsabbruch mitten in einem Stapel.** Bei einer Domainumbenennung über viele Konten:
   Wiederverbinden und fortsetzen, oder abbrechen und melden? Betrifft A1.
5. **Proxy-Authentifizierung.** Für das Anlegen von Ordnern im Namen eines Nutzers (Architektur
   9.3) wird SASL PLAIN mit `authzid` gebraucht. Beherrscht der Client das, oder wird es erst
   in M2 gebraucht?
6. **Protokollierung.** Ein IMAP-Mitschnitt hilft bei der Fehlersuche enorm — aber er enthält
   Passwörter. Nur in Entwicklung, mit Maskierung der Anmeldezeile?

## Definition of Done

- Verbindung zu einem Server mit selbstsigniertem Zertifikat gelingt **nur**, wenn dessen CA
  bekannt ist.
- Ein Mailboxname mit Leerzeichen und Sonderzeichen wird korrekt übertragen.
- Eine hängende Verbindung bricht nach der eingestellten Zeit mit einer Ausnahme ab, nicht mit
  einer Endlosschleife — genau dieser Fehler steckte im Altclient.
- Mehrere Kommandos laufen über eine Verbindung.
- Kein Passwort erscheint in einem Log.

## Testfälle

- **Unit:** Quoting und Literale, Antwort-Zerlegung, Tag-Zuordnung.
- **Integration (F5):** Anmeldung, einfaches Kommando, Abmeldung.
- **Integration:** Zertifikatsprüfung schlägt bei unbekannter CA fehl.
- **Integration:** Zeitüberschreitung greift.
