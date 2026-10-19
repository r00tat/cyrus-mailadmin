<!-- markdownlint-disable MD013 MD024 MD060 -->

# Übergabe — Stand der Implementierung

Dieses Dokument beschreibt, **was fertig ist, was halbfertig ist und wie man weitermacht.**

> **Stand:** 2026-08-15
>
> Der Stand bis einschließlich M2 liegt als Commit `b3531f3` auf dem Branch
> `feature/backend-implementation`, S2 als `6addca1` darüber. S3 liegt darüber als
> Arbeitskopie.

---

## 1. Zustand in einem Satz

Die Stufen 1 bis 3 sind erreicht: die API startet und validiert gegen den Vertrag, das Fachmodell
steht, Persistenz und Migrator laufen gegen ein echtes Altschema, das Passwortverfahren ist
fertig, **der Mailstore spricht mit einem echten Cyrus**, **man kann sich anmelden** — Token,
Rotation mit Diebstahlerkennung, Widerruf und Missbrauchsschutz — und **es ist entschieden, wer
was darf**: Berechtigungen, Rollenabbildung, Policies und die Trennung von `403` und `404` stehen
und werden erzwungen. Offen sind das Revisionsprotokoll, ManageSieve, die Fachlichkeit und die
Oberfläche.

---

## 2. Prüfbarer Ausgangszustand

Lokal ist **kein PHP installiert**. Alles läuft in Docker.

### Ohne Testumgebung (Host)

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'php vendor/bin/phpunit'
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'php vendor/bin/php-cs-fixer fix --dry-run --show-progress=none'
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress -c phpstan.neon.dist'
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress -c phpstan-domain.neon.dist'
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer validate --strict'
```

### Mit Testumgebung (der aussagekräftige Lauf)

```bash
bin/test-env up      # baut, startet, lädt Fixtures, legt die Mailboxen an
bin/test-env test    # PHPUnit im App-Container
```

| Prüfung | Ergebnis |
|---|---|
| PHPUnit **im Container** | **1506 Tests, 4449 Zusicherungen, 3 übersprungen, keine Fehler** |
| PHPUnit **auf dem Host** | 1506 Tests, 4184 Zusicherungen, 130 übersprungen, keine Fehler |
| PHP-CS-Fixer | **sauber** (0 von 374 Dateien) |
| PHPStan Stufe 8 (gesamtes Projekt) | **sauber** |
| PHPStan Stufe max (`src/Domain`) | **sauber** |
| `composer validate --strict` | **sauber** |
| `bin/openapi-bundle.php` / `bin/compile-routes.php` | laufen durch, 5 Routen, Prüfsumme `1ecbab8fc5b3` |

Der Unterschied zwischen 3 und 130 übersprungenen Tests ist der ganze Sinn der Testumgebung:
Datenbank- und Mailstore-Tests überspringen sich absichtlich, wenn keine Umgebung erreichbar ist
— **niemals melden sie sich als bestanden**. Die 127 zusätzlich übersprungenen Tests auf dem Host
sind genau die, die einen Server brauchen.

Die **drei**, die auch im Container übersprungen bleiben, sind dieselben drei wie zuvor: sie
prüfen das Verhalten bei einem nicht beschreibbaren Verzeichnis, und im Container läuft PHP als
`root`, der Verzeichnisrechte ignoriert. Sie melden das mit genau dieser Begründung.

> **Die Mailstore-Integrationstests laufen nur *im* Container**, nicht vom Host aus. Die
> Zertifikatsprüfung ist überall an und lässt sich nicht abschalten (Architektur 9.2); das
> Testzertifikat lautet auf `mail.example.test`, und vom Host wird `127.0.0.1` gewählt. Sie
> überspringen sich dort mit genau dieser Begründung.

---

## 3. Fertige Pakete

| ID | Titel | Wichtigstes Ergebnis |
|---|---|---|
| **F1** | Projektgerüst | Front-Controller, PSR-15-Kette, Konfiguration aus der Umgebung, strukturiertes Logging mit Geheimnismaskierung, einheitliche Fehlerbehandlung |
| **F2** | API-Vertrag und Dispatcher | OpenAPI 3.0.3 verteilt und gebündelt, Routentabelle aus `operationId` kompiliert, Anfragevalidierung, Vertragstests. **`openapi/README.md` enthält die Konventionen für alle Folgepakete** |
| **F3** | Persistenz und Migrator | PDO ohne Emulation, Migrator unter Datenbanksperre mit erzwungener Additivitätsprüfung, Repository-Basis mit nicht umgehbarer Sortier-Allowlist |
| **F4** | Domänenmodell | 49 Klassen. `MailboxIdentity` mit privatem Konstruktor, die vier `virtual`-Typen plus `UnknownVirtualEntry`, Quota, ACL, Sieve-Modell mit Renderer |
| **F5** | Testumgebung | **Läuft.** MariaDB mit Altschema, Postfix + Cyrus 3.10.2 + timsieved + `saslauthd`/`pam_mysql`, Apache, eigene CA. `bin/test-env` fährt sie in einem Befehl hoch |
| **S1** | Passwortverfahren | Argon2id/bcrypt je Tabelle ohne wählbaren Algorithmus, alle Altformate lesbar, Dummy-Verifikation, Regelwerk nach U-21 |
| **M1** | IMAP-Client | TLS mit Prüfung ohne Abschalter, eindeutige Tags, Literale mit Continuation, Timeouts, Verbindungspool je Anfrage, SASL-Proxy-Auth, redigierter Mitschnitt |
| **M2** | Cyrus-Mailstore | Ports im Fachmodell, `CyrusMailstore`, In-Memory-Attrappen für Mailstore und Sieve, **ein Vertragstestsatz, den beide bestehen — je Namensschema** |
| **S2** | Authentifizierung und Token | EdDSA-Token mit abgeleitetem `kid` und Rotation ohne Abmelden, Erneuerungstoken mit **Wiederverwendungserkennung**, Sperrliste für sofortigen Widerruf, Missbrauchsschutz je Name **und** Adresse. Rolle und Domain-Zuschnitt werden **pro Anfrage** gelesen — damit wirken Rollenänderung und gelöschtes Konto sofort, ohne Statusfeld und ohne `sub`→`jti`-Verzeichnis |
| **S3** | Autorisierung | Deny-by-default gegen eine **Aufzählung** von Berechtigungen statt gegen Rollen; ein `x-permission`, das die Aufzählung nicht kennt, ist ein **Buildfehler**. Rollenabbildung aus web-cyradms tatsächlichem Verhalten gelesen, nicht erfunden. `403` für die Operation, `404` für ein fremdes Objekt — die Middleware fängt beide Policy-Ausnahmen, damit acht Pakete die Trennung nicht achtmal treffen müssen |
| — | Webroot-Schutz (U-25) | `.htaccess` in drei Lagen, `bin/check-deployment.php`, `docs/deployment/` |

### Was F5 nachweislich kann

Die vollständige Authentifizierungskette läuft, und zwar für jedes Hash-Format, das
`crypt(3)`/`pam_mysql` lesen können sollen:

```text
password_hash() → accountuser → View allusers → pam_mysql → crypt(3) → IMAP / SMTP-AUTH
```

`AuthenticationChainTest` fährt sie mit bcrypt, md5crypt, SHA1-hex, DES-crypt und sha512crypt
durch — und weist nach, dass MySQL-`PASSWORD()` und Klartext **nicht** authentifizieren, weil
keine PAM-Zeile sie abdeckt. Dazu SMTP-AUTH über STARTTLS, die Zertifikatsprüfung, und der
Nachweis, dass `autocreate` aus ist (eine Anmeldung mit falschem Namensschema legt **kein**
Schattenpostfach an). Damit ist F5s Definition of Done erfüllt.

### Was dabei über den Zielserver gelernt wurde

Drei Befunde sind **Voraussetzungen an die Produktivkonfiguration**, keine Testdetails. Sie
stehen ausführlich in [U-38](05-umsetzungsentscheidungen.md) und in
[docs/deployment/README.md](deployment/README.md):

- **`allowstarttls: yes`** — ab Werk `no`. Ohne die Zeile ist `STARTTLS` auf 143 *und* auf 4190
  kein unbeworbenes, sondern ein **unbekanntes** Kommando, obwohl IMAPS auf 993 einwandfrei
  läuft. ManageSieve hätte dann keinen verschlüsselten Weg (**O-14**).
- **`allowusermoves: yes`** — ab Werk `no`. Ohne die Zeile scheitert *jedes* Umbenennen eines
  Kontos, und mailadmin könnte die Datenbankhälfte einer Domainumbenennung ausführen und die
  Mailboxhälfte nie: der Fehler des Altsystems, per Konfiguration reproduziert (**O-15**).
- **Cyrus 3.10 sendet zu `CREATE`, `DELETE`, `SETQUOTA` und `SETACL` keinen Antwortcode.** Der
  Adapter fragt deshalb im Fehlerfall nach, statt die englische Meldung zu lesen.

**O-8 und O-9 sind damit beantwortet** — O-8 bestätigt die Annahme, siehe U-38.

---

## 4. Halbfertige Pakete — hier wieder aufsetzen

### M3 — Sieve · `offen, aber vorbereitet`

Vorhanden: das Fachmodell (`src/Domain/Sieve/`, mit Renderer), der Port
`Domain\Mailstore\SieveStore`, die Attrappe `InMemorySieveStore` samt Tests, die Fehlerklasse
`SieveOperationFailed` und ein `Transport`, der `STARTTLS` beherrscht.

**Fehlt:** der ManageSieve-Client selbst und `CyrusSieveStore`. Der Server ist bereit — F5
beantwortet auf 4190 mit `"STARTTLS"`, seit `allowstarttls` gesetzt ist.

### S4 — Revisionsprotokoll · `offen`

**Es liegt nichts auf der Platte.** Die Aufrufstellen sind aber schon sichtbar: überall dort, wo
S2 heute eine Log-Zeile schreibt (`SignIn`, `SignOut`, `RefreshSession`), gehört zusätzlich ein
Eintrag nach `mailadmin_audit`. Nummernbereich `0020–0029` ist reserviert.

---

## 5. Offene Pakete

| Stufe | Pakete | Blockiert durch |
|---|---|---|
| 2 | S4 | — sofort bearbeitbar, S2 steht |
| 3 | M3 | — sofort bearbeitbar, M1 und F5 stehen |
| 4 | A1, A4, A5 | — **nicht mehr blockiert**, S3 steht |
| 4 | A2 | A1 (M2 steht) |
| 4 | A3, A6 | A2 |
| 5 | W1, W2 | — S2 steht; W1 kann gegen `/auth/*` bauen |

---

## 6. Was beim Wiederaufsetzen zuerst zu tun ist

1. **A1 beauftragen.** Stufe 4 ist entsperrt und A1 entsperrt A2, das den Rest entsperrt. Was
   ein A-Paket über S3 wissen muss, steht in drei Sätzen: eine Berechtigung in die eigene Familie
   von `Domain\Access\Permission` eintragen, sie in `RolePermissions` einer Rolle zuordnen, und
   im Anwendungsfall die Policy aufrufen und die Ausnahme laufen lassen. Eine Operation, deren
   Berechtigung nicht jede Rolle hält, **muss eine `403`-Antwort deklarieren** — ein Vertragstest
   erzwingt es.
2. **S4 beauftragen.** Klein und unabhängig; die Aufrufstellen sind die heutigen Log-Zeilen in
   `src/Application/Auth/`.
3. **M3 bauen.** ManageSieve über den vorhandenen `Transport`; Port, Attrappe und Fachmodell
   liegen bereit, der Server auch. Sinnvoll parallel, andere Dateien.
4. **`SchemaVersionMiddleware` in die Kette hängen.** F3 hat sie gebaut, sie ist noch nicht
   verdrahtet. Position in `config/di/core.php` zwischen `ErrorHandlerMiddleware` und
   `JsonBodyMiddleware`. **Der ursprüngliche Grund für den Aufschub ist entfallen** — F5 stellt
   jetzt eine Testdatenbank. Was bleibt: ohne gesetztes `MAILADMIN_DB_DSN` beantwortet sie jede
   Anfrage außer `/health` mit `503`, und die Unit- und Vertragstests laufen ohne Datenbank.
   Beim Einhängen also prüfen, ob die betroffenen Tests eine Datenbank bekommen oder die
   Middleware bei fehlender Konfiguration durchlässt.
5. **Danach A4/A5 (parallel zu A1), dann A2, dann A3/A6, dann W1/W2.**

**W1 muss O-13 schließen:** die statisch ausgelieferte `index.html` trägt heute keinen
CSP-Header, weil nur der Front-Controller die Policy setzt, also nur `/api/*`. Ohne das ist die
Abwägung in U-35 wirkungslos.

---

## 7. Wie gearbeitet wurde

**Ein Paket, ein Bearbeiter**, mit strikter Dateihoheit. Vier Konfliktpunkte wurden vorab
aufgelöst, damit überhaupt parallel gearbeitet werden konnte — sie stehen als U-3 bis U-6 in
[05-umsetzungsentscheidungen.md](05-umsetzungsentscheidungen.md):

- der Vertrag liegt auf mehrere Dateien verteilt und wird gebündelt,
- Handler werden über eine Konvention statt über eine Registry zugeordnet,
- Container-Definitionen liegen paketweise in `config/di/`,
- Migrationen haben feste Nummernbereiche je Paket.

**Alle Entscheidungen, die ohne Rückfrage getroffen wurden, stehen in
[05-umsetzungsentscheidungen.md](05-umsetzungsentscheidungen.md)** als U-1 bis U-45, jeweils mit
Begründung, Alternative und Korrekturkosten. Sie sind ausdrücklich zur Korrektur gedacht.

Paket-lokale Annahmen stehen im jeweiligen Dokument unter `docs/features/` im Abschnitt
*Zu klärende Punkte*, als Annahme markiert.

---

## 8. Was auf eine Entscheidung wartet

Nichts davon blockiert die Weiterarbeit — mit einer Ausnahme, die vor der Inbetriebnahme geklärt
sein muss.

| # | Punkt | Wer |
|---|---|---|
| **O-15** | **`allowusermoves: yes` auf dem Zielserver.** Ohne die Einstellung kann keine Domain und kein Konto umbenannt werden. Vor A1 zu klären | **Betrieb** |
| **O-14** | **`allowstarttls: yes` auf dem Zielserver.** Ohne die Einstellung ist ManageSieve nur im Klartext erreichbar. Vor M3s Inbetriebnahme | **Betrieb** |
| **O-11** | **Speicher-Engine der Bestandstabellen.** MyISAM kennt keine Transaktionen — Architektur 8.1 setzt sie voraus. `bin/db-check.php` beantwortet lesend, ob die Annahme zutrifft | **Betrieb** |
| **O-1** | **Lizenz AGPL-3.0-or-later bestätigen** (U-23). Nach dem ersten Release nur mit Zustimmung aller Mitwirkenden änderbar | Projekteigner |
| **O-13** | Die statisch ausgelieferte `index.html` trägt keinen CSP-Header. **W1 muss das schließen**, sonst ist U-35 wirkungslos | W1 |
| O-2 | Property-basierte Vertragstests nachrüsten oder verzichten | Projekteigner |
| O-3 | Wurzel-`README.md` | Projekteigner |
| O-6 | Reverse Proxy? Dann `X-Forwarded-For` nachrüsten, sonst zählt der Missbrauchsschutz alles auf eine IP | Betrieb |
| O-7 | `AllowOverride All` im vHost? Sonst ist der `.htaccess`-Schutz wirkungslos | Betrieb |
| O-10 | Umfang der Passwort-Sperrliste | Projekteigner |
| O-12 | Bleibt es bei „kein Redoc"? Das CSP-Argument ist mit U-35 entfallen | Projekteigner |
| **O-16** | **Sind `accountuser.password` und `adminuser.password` auf der Zielinstallation noch `latin1`?** Migration 0003 verbreitert beide und schreibt Zeichensatz und Kollation explizit mit (U-42). Auf einer zwischenzeitlich nach utf8mb4 konvertierten Datenbank würde der Lauf **Klartextpasswörter mit Nicht-ASCII-Zeichen umkodieren**. `bin/db-check.php` meldet den Ist-Zustand, bevor etwas läuft | **Betrieb** |

Erledigt seit der letzten Übergabe: **O-8** und **O-9** (gegen einen echten Cyrus beantwortet,
U-38). Neu seit S2: **O-16**, aus U-42. S3 hat keinen neuen offenen Punkt erzeugt — die zwei
Fragen, die es nicht selbst entscheiden durfte, sind als **Annahmen** in `A3` (Punkt 6) und `A5`
(Punkt 3) markiert und gehören dem jeweiligen Paket, nicht dem Betrieb.

Die vollständige Liste mit Begründungen steht am Ende von
[05-umsetzungsentscheidungen.md](05-umsetzungsentscheidungen.md).
