<!-- markdownlint-disable MD013 -->

# mailadmin

Weboberfläche zur Verwaltung eines Mailservers aus **Postfix**, **Cyrus IMAP** und
**MariaDB/MySQL**: Domains, Konten, Aliase, Catchall, Weiterleitungen, Abwesenheitsnotizen,
Quotas, Mailinglisten und Administratoren.

mailadmin ist der Nachfolger von [web-cyradm](https://github.com/web-cyradm/web-cyradm) und
arbeitet **auf dessen Datenbankschema weiter**. Bestehende Konten, Postfächer und Zugangsdaten
bleiben gültig, und web-cyradm kann während der Umstellung parallel weiterlaufen.

- **Backend:** PHP 8.3, REST-API nach OpenAPI-Vertrag, ohne Full-Stack-Framework
- **Frontend:** React-SPA mit Material UI, statisch ausgeliefert, strenge Content-Security-Policy
- **Sicherheit:** ausschließlich Prepared Statements, kurzlebige Ed25519-JWT,
  Erneuerungstoken im HttpOnly-Cookie, TLS mit Zertifikatsprüfung zu Cyrus, Revisionsprotokoll

Hintergrund, Architektur und Entscheidungen: [docs/README.md](docs/README.md).

---

## Schnellstart: Installation auf einem Server

Voraussetzungen: Debian oder Ubuntu mit **PHP 8.3+** (FPM, `pdo_mysql`, `sodium`), **Node 20+**
und Composer zum Bauen, Apache oder nginx, eine MariaDB mit dem web-cyradm-Schema und ein
Cyrus-IMAP-Server. Details: [Installationsanleitung](docs/deployment/installation.md).

```bash
# 1. Installer holen
git clone https://github.com/r00tat/cyrus-mailadmin.git /usr/local/src/cyrus-mailadmin
cd /usr/local/src/cyrus-mailadmin

# 2. Erster Lauf: legt /etc/mailadmin/mailadmin.env mit frischem Signaturschlüssel an und hält an
sudo bin/install

# 3. Konfiguration ausfüllen (Datenbank, Cyrus), dann installieren
sudo editor /etc/mailadmin/mailadmin.env
sudo bin/install --server-name mail.example.org \
     --apache --tls-cert /etc/ssl/certs/mail.example.org.pem \
              --tls-key  /etc/ssl/private/mail.example.org.key
```

Der Installer holt den Code, baut Backend und Frontend und führt die Datenbankmigrationen aus.
Außerdem richtet er einen eigenen PHP-FPM-Pool, den Cronjob und optional den Apache-vHost ein und
prüft am Ende, dass keine internen Pfade über das Web erreichbar sind. Jede Version landet in
einem eigenen Verzeichnis; `sudo bin/install --rollback` schaltet auf die vorige zurück.
`--dry-run` zeigt vorher, was passieren würde.

Vor dem ersten Lauf müssen der eigene Datenbankbenutzer und zwei Cyrus-Einstellungen
vorhanden sein — siehe [Installationsanleitung, Schritte 3 und 4](docs/deployment/installation.md#3-datenbankbenutzer-anlegen).
Angemeldet wird mit einem bestehenden web-cyradm-Administrator.

**Aktualisieren:** im Installer-Verzeichnis `git pull`, dann `sudo bin/install` mit denselben
Optionen.

---

## Schnellstart: Entwicklung

Die lokale Testumgebung bringt alles mit, was die Anwendung braucht: MariaDB mit dem
web-cyradm-Schema im Ausgangszustand, Postfix, Cyrus IMAP mit ManageSieve, `saslauthd` mit
`pam_mysql` und Apache vor PHP-FPM. Voraussetzungen auf dem Rechner: Docker und Node 20+. PHP
muss lokal nicht installiert sein.

```bash
bin/test-env up                     # Images bauen, starten, warten bis alles antwortet
bin/test-env seed                   # synthetische Testdaten laden (wiederholbar)

# PHP-Abhängigkeiten und Build-Artefakte (var/cache)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 install

# Datenbank migrieren
docker compose -f docker/compose.yaml exec -T app php bin/migrate.php

# Frontend bauen
cd frontend && npm ci && npm run build
```

Danach läuft die Oberfläche unter <http://127.0.0.1:18080/>. Anmeldung mit
`superadmin` / `superadmin-secret-2026` (Superuser) oder `dmaster` / `dmaster-secret-2026`
(Domainmaster für `example.test`). Alle Zugangsdaten der Testumgebung sind synthetisch und
stehen absichtlich im Repository.

Für die Arbeit am Frontend startet `npm run dev` (im Verzeichnis `frontend/`) einen Vite-Server
mit Hot Reload unter <http://localhost:5173/>. Er leitet `/api` an die Testumgebung weiter;
`MAILADMIN_DEV_API` setzt ein anderes Ziel.

> Ist in deiner npm-Konfiguration `ignore-scripts=true` gesetzt, überspringt `npm run build` die
> Schritte `prebuild` und `postbuild`. Dann nacheinander ausführen:
> `node scripts/clean-output.mjs && npm run api:types && npm run build && node scripts/check-build.mjs`

### Testumgebung

| Befehl | Wirkung |
|---|---|
| `bin/test-env up` / `down` | starten / anhalten, Daten bleiben |
| `bin/test-env seed` | Testdaten neu laden |
| `bin/test-env reset` | Volumes löschen — zurück zum Altschema ohne `mailadmin_`-Tabellen |
| `bin/test-env test` | PHPUnit im App-Container |
| `bin/test-env shell` | Shell im App-Container |
| `bin/test-env status` | was läuft und was antwortet |

Ports (nur auf 127.0.0.1): Apache 18080, MariaDB 13306, IMAPS 10993, IMAP 10143,
Submission 10587, SMTP 10025, ManageSieve 14190.

### Prüfungen

PHP läuft im App-Container (`docker compose -f docker/compose.yaml exec -T app …`):

| Prüfung | Befehl |
|---|---|
| Tests (Unit, Anwendung, Vertrag, Integration) | `bin/test-env test` |
| Codestil | `php vendor/bin/php-cs-fixer fix --dry-run --diff` |
| Statische Analyse | `php vendor/bin/phpstan analyse --memory-limit=1G` und `php vendor/bin/phpstan analyse -c phpstan-domain.neon.dist` |
| Frontend-Typen, Lint, Tests | `cd frontend && npm run typecheck && npm run lint && npm test` |
| Installer | `shellcheck bin/install bin/test-env` |

Die Integrationstests melden sich als *übersprungen*, nicht als bestanden, wenn die
Testumgebung nicht läuft.

### Aufbau

```text
openapi/        der API-Vertrag — einzige Quelle für Routen, Validierung und Frontend-Typen
src/            Backend: Http (Handler je operationId), Application (Anwendungsfälle),
                Domain (Fachmodell), Infrastructure (Persistenz, Cyrus, Sicherheit)
migrations/     rein additive SQL-Migrationen, Tabellen mit Präfix mailadmin_
frontend/       React-SPA; der Build schreibt nach public/
public/         einziges öffentliches Verzeichnis: index.php, index.html, assets/
bin/            Kommandozeilenwerkzeuge, Installer und Testumgebung
docker/         die lokale Testumgebung
docs/           Architektur, Umsetzungsentscheidungen, Arbeitspakete, Deployment
```

### Regeln, die nicht verhandelbar sind

1. **Die Datenbank gehört uns nicht allein.** Postfix und `saslauthd` lesen dieselben Tabellen.
   Migrationen sind rein additiv; Bestandstabellen bleiben semantisch unverändert.
2. **Kein SQL aus Strings.** Prepared Statements ohne Ausnahme, Sortierfelder aus Allowlists.
3. **Der Vertrag zuerst.** API-Änderungen beginnen in `openapi/`, nie im Handler.

Alle Konventionen — Passwortverfahren, Reihenfolge bei Operationen über Datenbank und Cyrus,
Frontend-Sicherheit — stehen in [CLAUDE.md](CLAUDE.md).

---

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [docs/deployment/installation.md](docs/deployment/installation.md) | Installation Schritt für Schritt, Installer, Aktualisierung, Fehlersuche |
| [docs/deployment/README.md](docs/deployment/README.md) | Auslieferungsvarianten, Cyrus-Voraussetzungen, Kontosperre |
| [docs/README.md](docs/README.md) | Projektüberblick und Motivation |
| [docs/03-architektur.md](docs/03-architektur.md) | Architektur im Detail |
| [docs/05-umsetzungsentscheidungen.md](docs/05-umsetzungsentscheidungen.md) | Entscheidungen während der Umsetzung |
| [openapi/README.md](openapi/README.md) | Konventionen des API-Vertrags |
| [.env.example](.env.example) | alle Konfigurationsvariablen mit Erklärung |

---

## Lizenz

[GNU Affero General Public License v3.0 oder später](LICENSE).
