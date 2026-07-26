<!-- markdownlint-disable MD013 MD024 MD060 -->

# S1 — Passwortverfahren

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 5.5 und 14 · E-3, E-13 |
| **Status** | offen |

---

## Ziel

Ein `PasswordHasher`, der alle im Bestand vorkommenden Hash-Formate prüft, neue Passwörter im
Zielverfahren schreibt und erkennt, wann ein Hash erneuert werden muss.

## Abgrenzung

- Kein Anmeldeablauf, keine Token — das ist **S2**.
- Keine Massenrücksetzung; sie fällt nach E-18 aus dem ersten Release.

## Abhängigkeiten und geteilte Dateien

Wird von S2 (Anmeldung), A2 (Kontopasswort) und A4 (Adminpasswort) genutzt.

## Vorgaben

| Tabelle | Zielverfahren | Grund |
|---|---|---|
| `adminuser` | Argon2id | kein externer Konsument |
| `accountuser` | bcrypt (`$2y$`) | muss `crypt(3)`-lesbar bleiben, `pam_mysql` prüft mit |

- **Niemals ein Verfahren für `accountuser` wählen, das `crypt(3)` nicht versteht.**
  Eskalationsfall.
- Alle Vergleiche über `hash_equals()`.
- Unbekannter Benutzername durchläuft eine Dummy-Verifikation gegen Zeitanalyse.
- Bestandshashes bleiben gültig; es gibt keine Zwangsumstellung (E-13).

## Artefakte

- `Infrastructure/Security/PasswordHasher.php` mit `verify()`, `hash()`, `needsRehash()`
- Erkennung und Prüfung für: Argon2, bcrypt, DES-crypt, md5crypt, sha256crypt, sha512crypt,
  SHA1-hex, MySQL-`PASSWORD()`, Klartext
- `Infrastructure/Security/PasswordGenerator.php`
- `Domain/Model/PasswordPolicy.php`

## Zu klärende Punkte

1. **Bcrypt-Kostenfaktor.** Standard ist 10. Höher ist sicherer, kostet aber Zeit bei jeder
   IMAP-Anmeldung — `pam_mysql` prüft bei **jedem** Mailabruf. Bei Clients, die im
   Minutentakt pollen, ist das spürbar. Vorschlag: 10 oder 11, gemessen auf der Zielhardware.
2. **Argon2id-Parameter** für `adminuser`: Speicher, Iterationen, Parallelität. Hier gibt es
   keine Poll-Last, also darf es teurer sein.
3. **Passwortregeln.** Mindestlänge, Zeichenklassen, Sperrliste? Das Altsystem prüfte nur
   Zeichenklassen. Vorschlag: Mindestlänge statt Zeichenklassenzwang.
4. **Format erzeugter Passwörter.** Lesbar am Telefon durchsagbar (keine `l`/`1`/`O`/`0`) oder
   maximale Entropie? Betrifft die Verteilung an Nutzer.
5. **MySQL-`PASSWORD()` nachbilden.** Das alte Format ist `*` + Großbuchstaben-Hex von
   `SHA1(SHA1(pw))`. Bestätigen, dass es im Bestand überhaupt vorkommt — sonst weglassen.
6. **Klartext-Passwörter im Bestand.** Kommen sie vor? Wenn ja: beim ersten erfolgreichen
   Prüfen sofort umschreiben, unabhängig von `needsRehash()`.

## Definition of Done

- Jedes gelistete Format wird korrekt geprüft, mit positivem und negativem Fall.
- `needsRehash()` meldet für jedes Altformat `true`, für das aktuelle Zielformat `false`.
- Ein von `hash()` für `accountuser` erzeugter Hash wird von `crypt(3)` verifiziert — Test in
  der Umgebung aus F5.
- Kein Zweig gibt einen Hash oder ein Klartextpasswort in ein Log.

## Testfälle

- **Unit:** je Format ein richtiges und ein falsches Passwort.
- **Unit:** DES-crypt — belegen, dass nur die ersten acht Zeichen zählen (dokumentiert das
  Altrisiko als Test).
- **Unit:** `needsRehash()` je Format.
- **Integration (F5):** bcrypt-Hash aus PHP, Anmeldung über IMAP und SMTP-AUTH.
