<!-- markdownlint-disable MD013 MD024 MD060 -->

# Deployment

mailadmin lässt sich auf zwei Arten ausliefern. Beide funktionieren mit demselben Code; sie
unterscheiden sich nur darin, wer den Schutz der nicht-öffentlichen Verzeichnisse übernimmt.

Grundlage: [U-25](../05-umsetzungsentscheidungen.md) und E-8.

**Installation Schritt für Schritt** — mit dem Installer `bin/install` oder von Hand:
[installation.md](installation.md). Dieses Dokument erklärt die Varianten und die Voraussetzungen
dahinter.

| Datei | Zweck |
|---|---|
| [installation.md](installation.md) | Installation, Aktualisierung, Rollback, Fehlersuche |
| [apache-vhost.conf.example](apache-vhost.conf.example) | Apache-vHost, Variante A |
| [nginx.conf.example](nginx.conf.example) | nginx, Variante A |
| [php-fpm-pool.conf.example](php-fpm-pool.conf.example) | eigener PHP-FPM-Pool mit der Konfiguration |
| [allusers.sql.example](allusers.sql.example) | View `allusers` mit Kontosperre |
| [maillist.md](maillist.md) | Listenskript `maillist.py`: Installation, Postfix-Transport, Betrieb |

---

## Variante A — `public/` als DocumentRoot (empfohlen)

```apache
<VirtualHost *:443>
    ServerName mail.example.test
    DocumentRoot /srv/mailadmin/public
    …
</VirtualHost>
```

Alles außerhalb von `public/` ist damit **strukturell** nicht erreichbar. Es ist keine
`.htaccess` beteiligt, es gibt nichts, was durch eine Serverkonfiguration ausfallen könnte.

Verlangt Zugriff auf die vHost-Konfiguration. Vollständiges Beispiel:
[`apache-vhost.conf.example`](apache-vhost.conf.example).

---

## Variante B — das ganze Verzeichnis im Web-Root

Für Installationen ohne Zugriff auf die vHost-Konfiguration — etwa ein Shared-Hosting-Paket,
in dem man nur Dateien in ein Verzeichnis legt.

```text
/var/www/html/mailadmin/     ← das vollständige Projektverzeichnis
```

Erreichbar unter `https://host/mailadmin/`. Der Schutz liegt dann bei den
`.htaccess`-Dateien, die das Repository mitbringt (U-25):

| Lage | Datei | Wirkt |
|---|---|---|
| 1 | `.htaccess` im Wurzelverzeichnis | leitet alles nach `public/` um, sperrt Punktdateien und die bekannten Projektdateien |
| 2 | `.htaccess` je Verzeichnis | `Require all denied` in `src/`, `config/`, `bin/`, `migrations/`, `openapi/`, `tests/`, `var/`, `docs/` |
| 3 | `public/.htaccess` | erteilt Zugriff, verteilt auf Front-Controller und SPA |

### Zwingende Voraussetzung

```apache
<Directory /var/www/html/mailadmin>
    AllowOverride All
</Directory>
```

Mindestens `AllowOverride FileInfo Limit AuthConfig Options=Indexes`.

> **Steht dort `AllowOverride None`, werden alle `.htaccess`-Dateien ignoriert und das
> gesamte Projektverzeichnis ist über das Web lesbar.** Apache meldet das nicht — die
> Anwendung funktioniert weiter, sie ist nur offen.

### Setze `MAILADMIN_BASE_PATH`

```bash
MAILADMIN_BASE_PATH=/mailadmin
```

Sonst erzeugt die Anwendung Pfade relativ zum Server-Wurzelverzeichnis.

### Danach prüfen — nicht annehmen

```bash
php bin/check-deployment.php https://host/mailadmin
```

Fordert die Pfade an, die nicht lesbar sein dürfen, und meldet jeden, der mit `200` antwortet.
Zusätzlich prüft der Lauf, ob die **ausgelieferte `index.html` die Sicherheits-Header trägt**
(O-13) — die setzt `public/.htaccess`, und ohne `AllowOverride` setzt sie niemand. Exit-Code `0`,
wenn nichts offenliegt und die Header ankommen. **Dieser Lauf gehört zur Inbetriebnahme**, nicht
zur Fehlersuche.

---

## Das Frontend bauen

Die Oberfläche wird **vor** dem Deployment gebaut, nicht auf dem Zielserver. Ergebnis sind
`public/index.html` und `public/assets/` — beides ist bewusst nicht eingecheckt, weil die
Dateinamen einen Inhalts-Hash tragen.

```bash
composer install --no-dev        # erzeugt var/cache/openapi.bundled.yaml
cd frontend && npm ci && npm run build
```

Die Reihenfolge ist keine Nebensache: `npm run build` erzeugt die TypeScript-Typen aus dem
**gebündelten** Vertrag. Ohne `composer build` gibt es keinen Bündel und damit keinen Client.

Liegt die Anwendung in einem Unterverzeichnis des Web-Roots, gehört derselbe Wert wie in
`MAILADMIN_BASE_PATH` auch in den Bau:

```bash
MAILADMIN_BASE_PATH=/mailadmin npm run build
```

Die GitHub-Releases bauen mit dem Platzhalter `__MAILADMIN_BASE__`, und `bin/install --release`
ersetzt ihn beim Entpacken durch den Basispfad der Installation. So passt ein Archiv für beide
Varianten. Ein `<base>`-Element wäre einfacher, aber die CSP verbietet es mit `base-uri 'none'`.

`npm run build` schlägt fehl, wenn das Ergebnis ein Inline-Skript, eine Referenz auf einen
fremden Ursprung oder eine Datei ohne Inhalts-Hash enthält. Das ist Absicht: die
Content-Security-Policy trägt das Restrisiko aus E-15, und ein Bündler, der sie bricht, wird
geändert — nicht die Policy.

---

## nginx

nginx wertet `.htaccess` nicht aus. Variante B ist dort ohne Serverkonfiguration **nicht**
absicherbar — die `location`-Regeln aus [`nginx.conf.example`](nginx.conf.example) sind Pflicht,
nicht Empfehlung.

---

## Was in beiden Varianten gilt

- **Keine Geheimnisse in Dateien.** Die Konfiguration kommt aus der Umgebung (U-11); in
  Produktion ist keine `.env` beteiligt. Das begrenzt den Schaden eines ausgefallenen
  Verzeichnisschutzes auf offengelegten Quelltext — bei einer quelloffenen Anwendung kein
  Geheimnisverlust.
- **`var/` muss beschreibbar sein** (U-13), und zwar für den Benutzer des PHP-FPM-Pools. Die
  Anwendung startet sonst nicht.
- **Die Bauartefakte werden mitgeliefert**, nicht zur Laufzeit erzeugt: `composer build` läuft
  vor dem Deployment.
- **Ein eigener Datenbankbenutzer** für die Anwendung, nicht der von Postfix und `pam_mysql`
  (Architektur 10.1).

---

## Voraussetzungen an den Cyrus-Server

Zwei Einstellungen in `imapd.conf` sind **Pflicht** und stehen bei Cyrus ab Werk auf `no`. Beide
sind bei der Umsetzung von M1 und M2 gegen einen echten Cyrus 3.10.2 aufgefallen; Begründung und
Messung stehen in [U-38](../05-umsetzungsentscheidungen.md).

```apache
allowstarttls:   yes
allowusermoves:  yes
```

**`allowstarttls`** — Cyrus prüft `allowstarttls && tls_enabled()`. Steht es auf `no`, ist
`STARTTLS` nicht bloß unbeworben, sondern ein *unbekanntes Kommando* — auch dann, wenn Zertifikat
und Schlüssel korrekt konfiguriert sind und IMAPS auf 993 einwandfrei läuft. Da `timsieved` in
Cyrus' Standardkonfiguration keinen impliziten TLS-Port hat, wäre ManageSieve sonst nur im
Klartext erreichbar. Offener Punkt **O-14**.

**`allowusermoves`** — ohne diese Einstellung scheitert *jedes* `RENAME user/a user/b` mit
„Operation is not supported on mailbox". Damit könnte mailadmin eine Domain- oder
Kontoumbenennung in der Datenbank ausführen und im Mailstore nie — genau der Fehler des
Altsystems (Architektur 8.1). Offener Punkt **O-15**.

Weiter braucht der von mailadmin verwendete Cyrus-Administrator:

- einen Eintrag in `admins`,
- das Recht, für Nutzer zu proxen (SASL `authzid`, Architektur 9.3) — sonst können keine
  Standardordner angelegt werden,
- `unixhierarchysep: yes`. Der Adapter prüft den Trenner beim ersten Kommando und **verweigert
  den Dienst**, wenn er nicht `/` ist: mit `.` als Trenner wäre
  `user.greta.mustermann.example.at` mehrdeutig, und keine Sorgfalt weiter unten kann das
  reparieren.

---

## Kontosperre: Migration zuerst, dann der View `allusers`

Seit U-127 lässt sich ein Konto sperren. Die Sperre steht in `mailadmin_account_status`
(Migration 0060) und wirkt nur, wenn der View `allusers` sie auswertet. Den View legt Salt an,
nicht mailadmin. Die Vorlage ist [`allusers.sql.example`](allusers.sql.example).

**Die Reihenfolge ist Pflicht:**

1. `php bin/migrate.php` — legt die Tabelle an. Der alte View läuft daneben unverändert weiter.
2. `php bin/db-check.php` — muss `mailadmin_account_status present` melden. Bei der Kollation
   steht im Normalfall `latin1_bin` auf beiden Seiten; eine andere `latin1`-Kollation bei
   `accountuser` ist unschädlich (der Join vergleicht trotzdem Bytes, U-129), ein anderer
   Zeichensatz wird als Warnung gemeldet.
3. Den View über Salt anpassen: in **jedem** Zweig der `UNION` den `LEFT JOIN` und das `WHERE`
   aus der Vorlage ergänzen. Die Vorlage nicht blind übernehmen, sondern auf die ausgerollte
   Definition anwenden — alles andere am View bleibt, wie es ist.
4. `php bin/db-check.php` — muss `allusers honours the status table in every UNION branch`
   melden. Liest nur ein Teil der Zweige die Tabelle, meldet es `only N of M UNION branches` als
   Warnung: das gesperrte Konto meldet sich dann unter der anderen Schreibweise weiter an (U-129).
   Ohne `SHOW VIEW` für den Datenbankbenutzer von mailadmin prüft es das Verhalten an einem
   gesperrten Konto — unter dem gespeicherten Benutzernamen **und** unter der Adressform, die der
   View daraus bildet.

**Umgekehrt ausgerollt** — der neue View auf einer Datenbank ohne die Tabelle — ist `allusers`
ungültig: `pam_mysql` bekommt einen Fehler, und **niemand** kann sich mehr an IMAP, POP,
ManageSieve oder SMTP-AUTH anmelden. Rückweg ist in jedem Fall der alte View; die Tabelle kann
stehen bleiben.

Solange der alte View läuft, lehnt mailadmin jede Sperre mit `409 account.status_not_enforced`
ab. Eine Sperre stoppt die Anmeldung, **nicht die Zustellung**: Postfix liest `virtual`, Mail an
ein gesperrtes Konto landet weiter im Postfach. Läuft `saslauthd` mit Credential-Cache (`-c`),
greift eine Sperre erst nach dessen Ablauf. Offener Punkt **O-21**.

Der Datenbankbenutzer von mailadmin braucht `SELECT` auf `allusers` — mailadmin fragt den View
vor jeder Sperre, ob er das Konto noch anbietet. `GRANT SHOW VIEW ON <db>.allusers` ist optional
und lässt `bin/db-check.php` die Definition lesen statt nur das Verhalten.
