<!-- markdownlint-disable MD013 MD024 MD060 -->

# F5 — Testumgebung mit Docker

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 12 |
| **Status** | offen |

---

## Ziel

Ein Compose-Aufbau, in dem die vollständige Authentifizierungskette lokal durchläuft:
`password_hash()` → `accountuser` → View `allusers` → `pam_mysql` → `crypt(3)` → IMAP und
SMTP-AUTH. Grundlage für alle Integrationstests.

## Abgrenzung

- Keine Produktionsbereitstellung.
- Keine Nachbildung des Salt-Konfigurationsmanagements — nur die Dienste, die die Anwendung
  sieht.

## Abhängigkeiten und geteilte Dateien

Wird von M1, M2, M3, A2, A6 und F3 für Integrationstests genutzt. Die Fixtures teilen sich alle
Pakete.

## Vorgaben

- **Testdaten ausschließlich synthetisch.** Keine echten Domains, Konten oder Hashes (E-5,
  Eskalationsfall).
- Das Datenbankschema kommt im **webcyradm-Ausgangszustand**, damit der Migrator gegen ein
  Altschema getestet wird.
- `autocreate_quota: -1`, damit ein Login mit falschem Namensschema scheitert statt still ein
  Schatten-Postfach anzulegen.
- Die `pam_mysql`-Konfiguration bildet beide Bestandszeilen ab (`crypt=1` und `crypt=4`).

## Artefakte

- `docker/compose.yaml`
- `docker/mail/` — Abbild mit Postfix, Cyrus IMAP, `timsieved`, `saslauthd`, `pam_mysql`
- `docker/db/init/` — Schema im Ausgangszustand, Views `allusers` und `validsenders`, Fixtures
- `docker/certs/` — selbstsignierte Zertifikate plus CA für die Zertifikatsprüfung
- `bin/test-env` — Hochfahren, Warten auf Bereitschaft, Zurücksetzen
- Kurzanleitung in `docs/features/F5-testumgebung.md` oder einer eigenen `TESTING.md`

## Zu klärende Punkte

1. **Woher kommt Cyrus?** Es gibt kein gepflegtes offizielles Abbild. Selbst bauen auf
   Debian-Basis (nah an der Produktion, aber Bauzeit) oder ein Community-Abbild (schneller,
   aber Version und Pflege unklar)?
2. **`pam_mysql` ist in aktuellen Distributionen nicht mehr paketiert.** Woher kommt es —
   selbst übersetzen im Abbild? Das ist der Punkt mit dem höchsten Risiko in diesem Paket und
   sollte zuerst geprüft werden.
3. **Zertifikate.** Selbstsigniert mit eigener CA, die in den Trust Store des
   Anwendungscontainers gelegt wird — damit die Zertifikatsprüfung im Test *echt* geprüft wird
   und nicht abgeschaltet werden muss.
4. **Startdauer und Bereitschaftsprüfungen.** Cyrus braucht eine initialisierte
   `mailboxes.db`. Wie signalisiert der Container Bereitschaft, und wie lange darf ein
   Testlauf auf ihn warten?
5. **Zurücksetzen zwischen Testläufen.** Container neu starten (langsam, sauber) oder Daten
   per Skript zurücksetzen (schnell, fehleranfällig)?
6. **Umfang der Fixtures.** Welche Fälle müssen abgedeckt sein? Mindestens: beide
   Namensschemata, ein Konto je Alt-Hash-Format, eine Domain ohne Konten, ein Catchall, eine
   Weiterleitung, ein verwaister Eintrag für den Abgleich.

## Definition of Done

- `bin/test-env up` liefert eine Umgebung, in der eine IMAP-Anmeldung mit einem
  bcrypt-gehashten Konto gelingt und mit falschem Passwort scheitert.
- Dasselbe für SMTP-AUTH über STARTTLS.
- Ein Konto mit DES-crypt-Hash meldet sich ebenfalls erfolgreich an — der Rückwärtspfad ist
  Teil der Zusage.
- Die Zertifikatsprüfung ist aktiv; kein Test schaltet TLS-Verifikation ab.
- Der Migrator läuft gegen das Ausgangsschema durch.

## Testfälle

Dieses Paket **ist** die Testinfrastruktur. Eigene Prüfungen:

- Ein Rauchtest, der die Kette einmal vollständig durchläuft.
- Ein Test, der belegt, dass `autocreate` aus ist: Anmeldung mit falschem Namensschema
  scheitert und erzeugt **keine** Mailbox.
