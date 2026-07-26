<!-- markdownlint-disable MD013 MD024 MD060 -->

# F4 — Domänenmodell

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 3.3 und 3.4 |
| **Status** | offen |

---

## Ziel

Die Fachbegriffe als Value Objects mit ihren Regeln — insbesondere `MailboxIdentity`, das die
drei Schreibweisen eines Kontos an genau einer Stelle beherrscht, und die vier Typen, die sich
die Tabelle `virtual` teilen.

## Abgrenzung

- Kein Datenbankzugriff, kein IMAP. Reine Fachlogik, vollständig ohne Infrastruktur testbar.
- Keine Anwendungsfälle — die gehören zu den A-Paketen.

## Abhängigkeiten und geteilte Dateien

Grundlage für M1, M2, M3 und alle A-Pakete. Änderungen hier wirken überall.

## Vorgaben

- **Der Cyrus-Benutzername wird erzeugt, nie zerlegt.** Einzige erlaubte Auswertung: enthält
  der gespeicherte Name ein `@` (Architektur 3.3).
- `MailboxIdentity` immer aus dem Tripel (Local Part, Domain, Namensschema).
- Namensschema eines bestehenden Kontos wird erkannt, nie aus der Konfiguration abgeleitet
  (E-14).
- Die vier `virtual`-Typen sind explizite Klassen, kein Feldraten im Anwendungscode
  (Architektur 3.4).

## Artefakte

- `Domain/Model/DomainName.php`, `LocalPart.php`, `EmailAddress.php`
- `Domain/Model/NamingScheme.php` — Enum `Dotted` / `Fqun`, mit Erkennung aus einem
  gespeicherten Benutzernamen
- `Domain/Model/MailboxIdentity.php` — Ableitung von Adresse, Cyrus-Benutzername, Spool-Name
- `Domain/Model/Quota.php` — inklusive Einheit
- `Domain/Model/Virtual/` — `MailboxAddress`, `Alias`, `CatchAll`, `Forward`, `UnknownEntry`
- `Domain/Model/AdminRole.php`

## Zu klärende Punkte

1. **Erlaubte Zeichen im Local Part.** Das Altsystem hatte keine Validierung und ließ Adressen
   wie `f*o*o+b*a*r@…` zu. Welche Zeichen erlauben wir? Vorschlag: konservativ — Buchstaben,
   Ziffern, `.`, `-`, `_`, `+`; kein führender oder abschließender Punkt.
2. **Internationalisierte Domainnamen.** Das Altsystem hatte ein Werkzeug zur
   Punycode-Umwandlung. Speichern wir Domains immer in Punycode und zeigen Unicode an, oder
   umgekehrt? Im Bestand existieren `xn--`-Domains — hier ist Einheitlichkeit wichtig.
3. **Groß- und Kleinschreibung.** Domains und Local Parts durchgängig kleinschreiben? Cyrus hat
   `lmtp_downcase_rcpt: yes`. Was passiert mit vorhandenen Einträgen in Großschreibung?
4. **Reservierte Adressen.** Das Altsystem kannte eine Liste (`root`, `postmaster`, …).
   Übernehmen, konfigurierbar machen oder streichen?
5. **Quota-Einheit.** Cyrus rechnet `STORAGE` in **Kilobyte**. Die Oberfläche zeigt vermutlich
   MB oder GB. Wo findet die Umrechnung statt, und was bedeutet der Wert `0`?
6. **Der `+`-Trenner.** Postfix ist mit `recipient_delimiter = +` konfiguriert. Muss das Modell
   Adressteile davor abschneiden, wenn es Zuordnungen sucht?

## Definition of Done

- `MailboxIdentity` erzeugt in beiden Namensschemata korrekte Formen und lässt sich nicht aus
  einem einzelnen String konstruieren.
- Ein Local Part mit Punkten führt in keinem Schema zu einer falschen Zerlegung.
- Jeder `virtual`-Datensatz wird genau einem der fünf Typen zugeordnet, einschließlich
  `UnknownEntry`.
- Keine Klasse im Paket kennt PDO oder IMAP.

## Testfälle

- **Unit:** Local Part mit Punkten in beiden Schemata, hin und zurück.
- **Unit:** Schemaerkennung aus gespeicherten Benutzernamen, mit und ohne `@`.
- **Unit:** Typerkennung für alle vier `virtual`-Muster plus einen unbekannten Fall.
- **Unit:** Grenzfälle bei Validierung — leerer Local Part, führender Punkt, überlange Namen.
