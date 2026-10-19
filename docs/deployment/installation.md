<!-- markdownlint-disable MD013 MD024 -->

# Installation

Diese Anleitung führt von einem Server mit laufendem Postfix, Cyrus und web-cyradm-Datenbank zu
einer laufenden mailadmin-Installation. Es gibt zwei Wege:

- **Mit dem Installer** (`bin/install`): Er führt die Schritte 5 bis 11 selbst aus und erledigt
  auch Aktualisierung und Rollback. Die Schritte 1 bis 4 bleiben Handarbeit, weil sie
  Datenbank-Administratorrechte und die Cyrus-Konfiguration betreffen.
- **Von Hand**: alle Schritte einzeln, etwa für nginx, eine andere Distribution oder ein
  Verzeichnis unterhalb eines bestehenden Web-Roots.

Beide Wege ergeben dasselbe Ergebnis. Was der Installer tut, steht in den Schritten unten. Er
kann nichts, was nicht auch hier beschrieben ist.

Hintergrund zu den Auslieferungsvarianten und zur Kontosperre: [README.md](README.md) in diesem
Verzeichnis.

---

## Überblick

| Schritt | Was | Installer |
|---|---|---|
| 1 | Pakete: PHP 8.3+ mit FPM, Composer, Node 20+, git | mit `--install-packages` |
| 2 | Server-Anforderungen prüfen | ja |
| 3 | Datenbankbenutzer anlegen | **nein** — braucht DB-Administratorrechte |
| 4 | Cyrus vorbereiten | **nein** — Konfiguration des Mailservers |
| 5 | Konfigurationsdatei | legt sie an, du füllst sie aus |
| 6 | Code holen und bauen | ja |
| 7 | Datenbank migrieren und prüfen | ja |
| 8 | PHP-FPM-Pool | ja |
| 9 | Webserver | mit `--apache`; nginx von Hand |
| 10 | Cronjob und Kommandozeile | ja |
| 11 | Auslieferung prüfen | mit `--server-name` |
| 12 | Anmelden | — |
| 13 | Kontosperre aktivieren (optional) | **nein** — der View gehört Salt |

---

## 1. Pakete

| Komponente | Anforderung | Debian / Ubuntu |
|---|---|---|
| PHP | 8.3 oder neuer, CLI und FPM | `php8.3-cli php8.3-fpm` |
| PHP-Erweiterungen | `pdo_mysql`, `sodium`, `openssl`, `json`, `iconv`, `filter`; `mbstring` empfohlen | `php8.3-mysql php8.3-mbstring` (der Rest steckt in `php8.3-common`) |
| Composer | 2.x | `composer` |
| Node.js | 20 oder neuer, **nur zum Bauen** | `nodejs npm` |
| git | | `git unzip` |
| Webserver | Apache 2.4 mit `mod_rewrite`, `mod_headers`, `mod_proxy_fcgi`, `mod_ssl` — oder nginx | `apache2` |
| Datenbank | MariaDB mit dem web-cyradm-Schema | — |

**PHP 8.3 auf Debian 12:** Debian 12 bringt PHP 8.2 mit. PHP 8.3 kommt entweder aus dem Paketarchiv
von [deb.sury.org](https://deb.sury.org/) oder aus einer neueren Distribution. Ubuntu 24.04
bringt PHP 8.3 mit, Debian 13 PHP 8.4.

**Node 20 auf älteren Distributionen:** Debian 12 und Ubuntu 24.04 liefern Node 18. Node 20 kommt
aus [NodeSource](https://github.com/nodesource/distributions) oder einer Node-Versionsverwaltung.
Node wird nur für den Bau gebraucht. Wer es nicht auf dem Mailserver haben will, baut auf einem
anderen Rechner (siehe [Bauen auf einem anderen Rechner](#bauen-auf-einem-anderen-rechner)).

`sudo bin/install --install-packages` installiert die Pakete über `apt-get`. Ob die
Distribution die nötigen Versionen hat, prüft der Installer danach. Ist eine Version zu alt,
bricht er mit einer Meldung ab.

---

## 2. Server-Anforderungen

- **HTTPS ist Pflicht.** Das Erneuerungstoken wird ausschließlich mit `Secure` gesetzt, und
  mailadmin sendet HSTS. Über reines HTTP schlägt die Anmeldung fehl.
- **Ein eigener vHost** mit `public/` als DocumentRoot ist der empfohlene Weg (Variante A). Ein
  Unterverzeichnis eines bestehenden Web-Roots geht auch (Variante B), siehe
  [README.md](README.md#variante-b--das-ganze-verzeichnis-im-web-root).
- mailadmin braucht eine Verbindung zur Datenbank und zu Cyrus über IMAPS (993) und ManageSieve
  (4190). Beide Verbindungen laufen mit TLS und Zertifikatsprüfung.

---

## 3. Datenbankbenutzer anlegen

mailadmin bekommt **einen eigenen Datenbankbenutzer**, nicht den, mit dem Postfix und
`pam_mysql` lesen. So lassen sich die Rechte trennen, und ein Fehler in der Oberfläche kann
nicht die Zustellung lahmlegen.

Als Datenbankadministrator, `<db>` durch den Namen der web-cyradm-Datenbank ersetzt:

```sql
CREATE USER 'mailadmin'@'localhost' IDENTIFIED BY '<ein langes Zufallspasswort>';

-- Bestandstabellen lesen und schreiben, eigene mailadmin_-Tabellen anlegen.
-- Bewusst kein DROP: Migrationen sind rein additiv, die Datenbank erzwingt das mit.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON `<db>`.* TO 'mailadmin'@'localhost';

-- Optional: lässt bin/db-check.php die Definition des Views allusers lesen (Kontosperre).
GRANT SHOW VIEW ON `<db>`.`allusers` TO 'mailadmin'@'localhost';
```

Ein Passwort ohne `'`, `"`, `\`, `$` und Backtick erspart Ärger mit dem Format der
Konfigurationsdatei. Erzeugen lässt es sich zum Beispiel mit `openssl rand -hex 24`.

Läuft die Datenbank auf einem anderen Rechner, steht statt `localhost` dessen Sicht auf den
Webserver im Benutzernamen.

---

## 4. Cyrus vorbereiten

In `imapd.conf` (danach Cyrus neu starten):

```text
allowstarttls:    yes
allowusermoves:   yes
unixhierarchysep: yes
admins:           cyrus <weitere>
```

| Einstellung | Warum |
|---|---|
| `allowstarttls: yes` | ohne sie kennt Cyrus `STARTTLS` nicht — ManageSieve (Abwesenheitsnotizen) wäre nur im Klartext erreichbar, und mailadmin verweigert das |
| `allowusermoves: yes` | ohne sie scheitert jedes Umbenennen einer Domain oder eines Kontos im Mailstore |
| `unixhierarchysep: yes` | mit `.` als Trenner wären Postfachnamen mehrdeutig; mailadmin verweigert dann den Dienst |
| `admins` | der Administrator, mit dem mailadmin Postfächer anlegt, löscht und Quotas setzt |

Der Administrator muss außerdem **für Nutzer proxen dürfen** (SASL-`authzid`). Darüber legt
mailadmin die Standardordner im Namen des Nutzers an, ohne dessen Passwort zu kennen. Bei Cyrus
genügt dafür der Eintrag in `admins`. Läuft die Anmeldung über `saslauthd`, kommt es auf die
Konfiguration dort an. Ist das Zertifikat von Cyrus nicht von einer öffentlichen CA signiert,
trägst du die eigene CA später als `MAILADMIN_IMAP_CA_FILE` ein.

Begründung und Messungen: [U-38](../05-umsetzungsentscheidungen.md).

---

## Mit dem Installer

Der Installer ist ein Bash-Skript im Repository. Es läuft als root und führt alles, was
Composer und npm ausführen, als unprivilegierter Benutzer `mailadmin` aus.

```bash
git clone https://github.com/r00tat/cyrus-mailadmin.git /usr/local/src/cyrus-mailadmin
cd /usr/local/src/cyrus-mailadmin
sudo bin/install --dry-run          # zeigt jeden Schritt, ändert nichts
sudo bin/install                    # erster Lauf: legt die Konfiguration an und hält an
```

Der erste Lauf legt `/etc/mailadmin/mailadmin.env` an. Den Signaturschlüssel für die Tokens
erzeugt er dabei frisch. Danach hält er mit Exit-Code `2` an. Fülle jeden Wert `CHANGEME` aus
(Schritt 5 erklärt die Felder) und starte erneut:

```bash
sudo bin/install --server-name mail.example.org \
     --apache --tls-cert /etc/ssl/certs/mail.example.org.pem \
              --tls-key  /etc/ssl/private/mail.example.org.key
```

Ohne `--apache` richtet der Installer keinen Webserver ein. Er nennt am Ende DocumentRoot und
FPM-Socket für deine eigene Konfiguration.

### Optionen

| Option | Standard | Bedeutung |
|---|---|---|
| `--repo URL` | `origin` des Checkouts, in dem das Skript liegt | Quelle des Codes |
| `--ref REF` | Standardzweig | Zweig, Tag oder Commit |
| `--prefix DIR` | `/srv/mailadmin` | Installationsverzeichnis |
| `--env-file FILE` | `/etc/mailadmin/mailadmin.env` | Konfiguration |
| `--base-path PATH` | leer | URL-Präfix bei Variante B, z. B. `/mailadmin` |
| `--server-name HOST` | — | öffentlicher Hostname; schaltet die Auslieferungsprüfung ein |
| `--apache` | aus | vHost schreiben und aktivieren (braucht `--tls-cert`, `--tls-key`) |
| `--user NAME` | `mailadmin` | Systembenutzer des FPM-Pools |
| `--web-group NAME` | `www-data` | Gruppe des Webservers (Zugriff auf den FPM-Socket) |
| `--php-version X.Y` | Version der PHP-CLI | welcher php-fpm |
| `--fpm-socket PATH` | `/run/php/mailadmin.sock` | Socket des Pools |
| `--keep-releases N` | 3 | aufbewahrte Versionen (mindestens 2) |
| `--install-packages` | aus | Pakete per `apt-get` installieren |
| `--skip-migrate` | aus | Migrationen nicht ausführen |
| `--no-system` | aus | nur holen, bauen, migrieren — als aktueller Benutzer, ohne Systemänderungen |
| `--rollback` | — | auf die vorige Version zurückschalten |
| `--dry-run` | — | nur anzeigen |

Beim ersten Lauf füllen `--db-dsn`, `--db-user`, `--imap-host` und `--imap-admin-user` die
Konfigurationsdatei vor.

### Was er anlegt

```text
/srv/mailadmin/releases/<zeit>-<commit>/   eine Version je Lauf, root gehört, nur var/ dem Pool
/srv/mailadmin/current                     Symlink auf die aktive Version
/etc/mailadmin/mailadmin.env               Konfiguration (0640 root:mailadmin)
/etc/php/8.3/fpm/pool.d/mailadmin.conf     FPM-Pool, aus der Konfiguration erzeugt (0640)
/etc/apache2/sites-available/mailadmin.conf   mit --apache
/usr/local/sbin/mailadmin                  Kommandozeile mit Konfiguration
/etc/cron.d/mailadmin                      stündliches Aufräumen der Tokens
```

Dateien, die er nicht selbst geschrieben hat, überschreibt der Installer nicht. Er erkennt
seine eigenen an einer Kennzeile. Liegt an einer dieser Stellen bereits eine von Hand
geschriebene Datei, bricht er ab.

### Aktualisieren und zurückrollen

```bash
cd /usr/local/src/cyrus-mailadmin && git pull
sudo bin/install --server-name mail.example.org     # dieselben Optionen wie bei der Installation
sudo bin/install --rollback                         # falls nötig
```

Eine neue Version wird vollständig gebaut und migriert, **während die alte weiterläuft**.
Migrationen sind rein additiv, deshalb arbeitet die alte Version auf dem neuen Schema weiter.
Erst danach schaltet der Symlink um. Scheitert ein Schritt vorher, bleibt die aktive Version
unverändert, und die halb gebaute wird entfernt.

Der Rollback schaltet nur den Code zurück. Migrationen nimmt er nicht zurück, und das ist auch
nicht nötig, aus demselben Grund.

### Nach einer Änderung der Konfiguration

Der FPM-Pool wird aus `/etc/mailadmin/mailadmin.env` erzeugt. Nach einer Änderung führst du
`sudo bin/install` erneut aus. Das baut zwar eine neue Version, ist aber der sichere Weg: Der
Pool wird nur übernommen, wenn `php-fpm -t` ihn akzeptiert.

---

## Von Hand

### 5. Konfigurationsdatei

Die Konfiguration kommt **ausschließlich aus Umgebungsvariablen** (U-11). In Produktion gibt es
keine `.env` im Projektverzeichnis. Alle Variablen mit Erklärung stehen in
[`.env.example`](../../.env.example). Die nötigen:

| Variable | Wert |
|---|---|
| `MAILADMIN_ENV` | `prod` — Pflicht, ohne sie startet die Anwendung nicht |
| `MAILADMIN_DB_DSN` | `mysql:host=localhost;port=3306;dbname=<db>;charset=utf8mb4` |
| `MAILADMIN_DB_USER`, `MAILADMIN_DB_PASSWORD` | der Benutzer aus Schritt 3 |
| `MAILADMIN_JWT_SIGNING_KEY` | Ausgabe von `openssl rand -base64 32` — einmal erzeugen, nie ins Repository |
| `MAILADMIN_IMAP_HOST` | Hostname von Cyrus, **wie er im Zertifikat steht** |
| `MAILADMIN_IMAP_ADMIN_USER`, `MAILADMIN_IMAP_ADMIN_PASSWORD` | der Administrator aus Schritt 4 |
| `MAILADMIN_IMAP_CA_FILE` | nur bei eigener CA |
| `MAILADMIN_NAMING_SCHEME` | `dotted` (`local.domain.tld`) oder `fqun` (`local@domain.tld`) — gilt nur für **neue** Konten; bestehende behalten ihr Schema |
| `MAILADMIN_BASE_PATH` | nur bei Variante B, z. B. `/mailadmin` |

Lege sie als Datei an, die nur root und der Pool-Benutzer lesen dürfen. Sie dient als Quelle für
den FPM-Pool und für die Kommandozeilenwerkzeuge:

```bash
sudo install -d -m 0755 /etc/mailadmin
sudo install -m 0640 -g mailadmin /dev/null /etc/mailadmin/mailadmin.env
sudoedit /etc/mailadmin/mailadmin.env      # KEY='value' je Zeile
```

### 6. Code holen und bauen

```bash
sudo useradd --system --create-home --home-dir /var/lib/mailadmin --shell /usr/sbin/nologin mailadmin

git clone https://github.com/r00tat/cyrus-mailadmin.git /srv/mailadmin
cd /srv/mailadmin
composer install --no-dev --optimize-autoloader     # erzeugt auch var/cache/
cd frontend && npm ci && npm run build && rm -rf node_modules
```

Die Reihenfolge zählt: `npm run build` erzeugt die TypeScript-Typen aus dem Vertrag, den
`composer install` in `var/cache/` gebündelt hat. Bei Variante B gehört der Basispfad auch in den
Bau: `MAILADMIN_BASE_PATH=/mailadmin npm run build`.

`npm run build` schlägt fehl, wenn das Ergebnis ein Inline-Skript oder einen Verweis auf einen
fremden Ursprung enthält. Das ist Absicht, siehe [README.md](README.md#das-frontend-bauen).

Danach:

```bash
sudo chown -R root:root /srv/mailadmin
sudo chown -R mailadmin:mailadmin /srv/mailadmin/var
```

Der Code gehört root und ist für den Webprozess nicht schreibbar. Nur `var/` ist beschreibbar.
Ohne diese Rechte startet die Anwendung nicht.

#### Bauen auf einem anderen Rechner

Ein Rechner mit PHP 8.3, Composer und Node 20 baut genauso. Danach überträgst du das ganze
Verzeichnis, einschließlich `vendor/`, `var/cache/`, `public/index.html` und `public/assets/`,
aber ohne `frontend/node_modules/`. Alternativ baut `bin/install --no-system --prefix <dir>` dort
in ein eigenes Verzeichnis.

### 7. Datenbank migrieren und prüfen

```bash
cd /srv/mailadmin
set -a; . /etc/mailadmin/mailadmin.env; set +a
php bin/migrate.php --dry-run      # zeigt, was laufen würde; Exit 1 heißt "etwas steht aus"
php bin/migrate.php                # führt es aus
php bin/db-check.php               # Zeichensätze, Speicher-Engines, Migrationsstand
```

Die Migrationen legen **nur** neue Tabellen mit dem Präfix `mailadmin_` an und verbreitern die
Passwortspalten. Bestehende Daten ändern sie nicht. web-cyradm, Postfix und `saslauthd` laufen
unverändert weiter.

`db-check` endet mit `2`, wenn es Warnungen gibt. Auf einem web-cyradm-Schema ist das der
Normalfall: die Tabellen sind MyISAM. Ein Fehler ist nur `1`.

Solange Migrationen ausstehen, beantwortet die Weboberfläche jede Anfrage mit `503`. `/health`
antwortet trotzdem.

### 8. PHP-FPM-Pool

Ein eigener Pool, damit mailadmin unter einem eigenen Benutzer läuft und seine Konfiguration
nicht mit anderen PHP-Anwendungen teilt. Vorlage:
[`php-fpm-pool.conf.example`](php-fpm-pool.conf.example) nach
`/etc/php/8.3/fpm/pool.d/mailadmin.conf`, mit den Werten aus Schritt 5 als `env[...]`.

```bash
sudo chmod 0640 /etc/php/8.3/fpm/pool.d/mailadmin.conf     # enthält Passwörter
sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm
```

php-fpm lehnt `env[X] = ""` ab. Leere Werte lässt du deshalb weg.

### 9. Webserver

**Apache:** [`apache-vhost.conf.example`](apache-vhost.conf.example) anpassen (Hostname,
Zertifikat, Pfad, Socket), dann:

```bash
sudo a2enmod rewrite headers proxy_fcgi setenvif ssl
sudo a2ensite mailadmin
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Zwei Stellen im Beispiel sind wichtig:

- **`CGIPassAuth On`** (oder die Regel in `public/.htaccess`). Ohne sie verliert Apache vor
  PHP-FPM den `Authorization`-Header, und jede Anfrage nach der Anmeldung endet mit `401`.
- **Die Sicherheits-Header.** Mit `AllowOverride None` liest Apache `public/.htaccess` nicht,
  und die Oberfläche käme ohne Content-Security-Policy an.

**nginx:** [`nginx.conf.example`](nginx.conf.example). nginx liest keine `.htaccess`. Die
`location`-Blöcke und Header dort sind deshalb Pflicht.

### 10. Cronjob und Kommandozeile

`bin/prune-tokens.php` entfernt abgelaufene Erneuerungstoken, Sperrlisteneinträge und
Anmeldeversuche. Läuft es nicht, geht nichts kaputt. Die Tabellen wachsen nur unnötig.

```text
# /etc/cron.d/mailadmin
17 * * * * mailadmin sh -c 'set -a; . /etc/mailadmin/mailadmin.env; exec php /srv/mailadmin/bin/prune-tokens.php' >/dev/null
```

Der Installer legt dafür den Wrapper `/usr/local/sbin/mailadmin` an. Er lädt die Konfiguration
und ruft das Werkzeug auf, also `mailadmin migrate status`, `mailadmin db-check`,
`mailadmin prune-tokens --dry-run` oder `mailadmin check-deployment https://host`.

### 11. Auslieferung prüfen

```bash
php bin/check-deployment.php https://mail.example.org
```

Fordert die Pfade an, die nicht lesbar sein dürfen (`src/`, `config/`, `.env`, `vendor/` …),
und prüft, dass die ausgelieferte Oberfläche die Sicherheits-Header trägt. Exit-Code `0` heißt:
nichts liegt offen. **Dieser Lauf gehört zur Inbetriebnahme**, gerade bei Variante B.

Danach:

```bash
curl -s https://mail.example.org/api/v1/health
# {"status":"ok","checks":[{"component":"database","status":"ok"},{"component":"imap","status":"ok"},…]}
```

### 12. Anmelden

Die Anmeldung läuft mit einem **bestehenden web-cyradm-Administrator** aus der Tabelle
`adminuser`. Alte Passwort-Hashes (DES-crypt, MD5-crypt usw.) werden gelesen und beim
erfolgreichen Anmelden auf Argon2id umgestellt. Das Passwort bleibt dasselbe, und web-cyradm
kann den neuen Hash nicht mehr prüfen. Wer beide Oberflächen parallel nutzt, meldet sich mit
einem Administrator an, den web-cyradm nicht mehr braucht.

Mailkonten (`accountuser`) bekommen beim Setzen eines Passworts bcrypt. Das kann `pam_mysql`
über `crypt(3)` lesen.

### 13. Kontosperre aktivieren (optional)

Konten zu sperren erfordert eine Anpassung des Views `allusers`, den Salt verwaltet. **Die
Reihenfolge ist Pflicht:** Migration, `db-check`, dann der View. Umgekehrt ausgerollt kann sich
niemand mehr an IMAP oder SMTP anmelden. Anleitung:
[README.md, Kontosperre](README.md#kontosperre-migration-zuerst-dann-der-view-allusers).

Solange der View nicht angepasst ist, lehnt mailadmin jede Sperre mit einer Meldung ab. Alles
andere funktioniert.

---

## Fehlersuche

| Symptom | Ursache |
|---|---|
| Anmeldung klappt, danach `401` bei jeder Anfrage | Apache reicht den `Authorization`-Header nicht an PHP-FPM weiter — `CGIPassAuth On` im vHost oder `AllowOverride FileInfo` für `public/.htaccess` |
| Anmeldung schlägt sofort fehl, im Browser kein Cookie | Aufruf über HTTP statt HTTPS |
| `503` mit `migration.schema_outdated` | Migrationen ausstehend: `mailadmin migrate` |
| Startfehler „MAILADMIN_ENV“ | die Umgebungsvariablen erreichen PHP nicht — im Pool `env[...]` setzen, `clear_env` beachten |
| Startfehler zum Cache-Verzeichnis | `var/` nicht beschreibbar für den Pool-Benutzer, oder `composer install` lief nicht |
| `/health` meldet `imap` als Fehler | Hostname passt nicht zum Zertifikat, eigene CA fehlt in `MAILADMIN_IMAP_CA_FILE`, oder Port 993 gesperrt |
| Abwesenheitsnotizen lassen sich nicht speichern | `allowstarttls: yes` fehlt in `imapd.conf` |
| Umbenennen einer Domain scheitert im Mailstore | `allowusermoves: yes` fehlt |
| leere Seite, in der Browserkonsole CSP-Fehler | Frontend mit falschem `MAILADMIN_BASE_PATH` gebaut, oder ein Proxy schreibt die Header um |

Das Log geht standardmäßig nach `stderr`, also ins Fehlerlog von php-fpm.
`MAILADMIN_LOG_TARGET=/var/log/mailadmin/app.log` schreibt in eine eigene Datei. Das
Verzeichnis muss dann dem Pool-Benutzer gehören.
