<!-- markdownlint-disable MD013 MD024 MD060 -->

# F5 — Testumgebung mit Docker

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 12 |
| **Status** | **fertig** — hochgefahren, Kette nachgewiesen |

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

## Benutzung

```bash
bin/test-env up      # baut, startet, lädt Fixtures, legt die Mailboxen an — ein Befehl
bin/test-env test    # PHPUnit im App-Container
bin/test-env reset   # Container und Volumes weg; danach wieder Ausgangsschema
bin/test-env status  # was läuft
bin/test-env logs    # allen Diensten zusehen
```

Die Reihenfolge in `up` ist nicht beliebig: die Datenbank kommt zuerst und **die Fixtures noch
vor dem Warten auf den Mail-Container**. Cyrus authentifiziert seinen eigenen Administrator über
`saslauthd` gegen `accountuser`, sein Healthcheck kann also nicht bestehen, bevor die Zeilen da
sind — wer auf ein gesundes `mail` wartet, bevor er sät, wartet ewig.

## Zu klärende Punkte

Alle sechs sind entschieden.

1. ~~**Woher kommt Cyrus?**~~ **Selbst gebaut auf Debian trixie.** Es gibt kein gepflegtes
   offizielles Abbild, und ein Community-Abbild bände die Umgebung an eine Version und einen
   Betreuer, die man nicht sieht. Trixie führt genau die Versionen des Produktivsystems
   (Cyrus 3.10.2, Postfix 3.10.x), also sagt „läuft hier" etwas über Produktion aus.
2. ~~**`pam_mysql`.**~~ **Es ist paketiert:** `libpam-mysql` 0.8.2 liegt in trixie/main. Der
   Punkt mit dem höchsten Risiko in diesem Paket kostete eine `apt`-Zeile; nichts wird übersetzt.
3. ~~**Zertifikate.**~~ Selbstsigniert mit eigener CA (`docker/certs/generate.sh`), die im
   App-Container im Trust Store liegt. Die Prüfung ist damit **echt** und wird nirgends
   abgeschaltet. Die CA wird einmal erzeugt und über Rebuilds hinweg behalten.
4. ~~**Startdauer und Bereitschaft.**~~ Über Compose-Healthchecks und `up --wait`; der
   Mail-Container hat ein eigenes Skript, das IMAP, SMTP und ManageSieve wirklich anspricht.
   Grenze 300 s; bei Überschreitung zeigt `bin/test-env` die letzten Logzeilen jedes Dienstes,
   statt still zu scheitern.
5. ~~**Zurücksetzen.**~~ Beides, je nach Bedarf: `seed` lädt die Fixtures neu (schnell,
   idempotent — `00-reset.sql` leert vorher), `reset` entfernt die Volumes und stellt das
   Ausgangsschema wieder her (langsam, vollständig). Die Init-Skripte laufen nur beim Anlegen
   des Volumes, deshalb ist `reset` der einzige Weg zurück zum Altschema.
6. ~~**Umfang der Fixtures.**~~ Abgedeckt: beide Namensschemata, je ein Konto pro
   Alt-Hash-Format (bcrypt, md5crypt, SHA1-hex, DES-crypt, sha512crypt, MySQL-`PASSWORD()`,
   Klartext), eine Domain ohne Konten (`leer.test`, die auch die Mailstore-Integrationstests
   benutzen), Catchall, Weiterleitung und ein verwaister Eintrag.

## Definition of Done

Alle fünf Zusagen sind erfüllt und laufen als Test.

| Zusage | Nachweis |
|---|---|
| IMAP-Anmeldung mit bcrypt gelingt, mit falschem Passwort nicht | `AuthenticationChainTest::testTheWholeChainWithABcryptAccount`, `…AWrongPasswordIsRefused` |
| Dasselbe für SMTP-AUTH über STARTTLS | `SmtpAuthenticationTest` |
| Ein DES-crypt-Konto meldet sich ebenfalls an | `AuthenticationChainTest`, Datensatz `descrypt` — dazu md5crypt, SHA1-hex und sha512crypt |
| Zertifikatsprüfung aktiv, kein Test schaltet sie ab | `CertificateVerificationTest`: mit Test-CA gelingt, ohne sie scheitert es |
| Der Migrator läuft gegen das Ausgangsschema durch | `MigratorIntegrationTest` — läuft seit **U-36** wirklich, statt sich zu überspringen |

Nicht gefordert, aber miterledigt: `AuthenticationChainTest` weist auch nach, dass
MySQL-`PASSWORD()` und Klartext **nicht** authentifizieren, weil keine PAM-Zeile sie abdeckt.

## Testfälle

Dieses Paket **ist** die Testinfrastruktur. Eigene Prüfungen, alle vorhanden:

- `AuthenticationChainTest` — die Kette einmal vollständig, je Hash-Format.
- `AutocreateDisabledTest` — eine Anmeldung mit falschem Namensschema scheitert und erzeugt
  **keine** Mailbox. Genau die 33 Schattenpostfächer, die im Altsystem unbemerkt entstanden.
- `BaselineSchemaTest` — das Schema ist wirklich das webcyradm-Altschema, MyISAM und latin1
  inbegriffen, und die beiden Views liefern, was Postfix und `pam_mysql` erwarten.
