<!-- markdownlint-disable MD013 MD024 MD060 -->

# Listenskript installieren und betreiben

`maillist.py` stellt Mailinglisten-Mails zu. Postfix ruft es über einen `pipe(8)`-Transport auf. Das
Skript läuft auf dem System, auf dem Postfix läuft, nicht auf dem Webserver von mailadmin.
Hintergrund und Umstellungsplan: Spezifikation `docs/superpowers/specs/2026-10-22-mailinglisten-pipe-transport-design.md`. Das Skript selbst:
[maillist/README.md](../../maillist/README.md).

## 1. Voraussetzungen

- Python 3.11 oder neuer und `python3-mysql.connector` (Debian) auf dem System, auf dem Postfix läuft.
- Ein Datenbankbenutzer, der nur `SELECT` auf die Tabelle `lists` hat. Mehr braucht das Skript nicht.
- Läuft Postfix in einem Container: Das Installationsverzeichnis, die Konfigurationsdatei und das
  Verzeichnis der Logdatei müssen im Container sichtbar sein (Bind-Mount oder Volume), und der
  Listenbenutzer muss dort existieren. Der Installer läuft am besten im Container selbst.

## 2. Installation

Aus dem Release:

```bash
dir=$(mktemp -d)
curl -fsSL -o "$dir/install-maillist" https://github.com/r00tat/cyrus-mailadmin/releases/latest/download/install-maillist
sudo python3 -I "$dir/install-maillist" --release latest
```

Lade den Installer in ein frisches Verzeichnis und starte ihn mit `python3 -I`. Ohne `-I` steht das
Verzeichnis des Skripts vorne in `sys.path`; ein dort abgelegtes Modul (etwa in einem gemeinsam
genutzten Download-Verzeichnis) würde als Root importiert. Fehlt `-I`, gibt der Installer eine Warnung
aus, arbeitet aber weiter. Seine eigenen Python-Aufrufe (Abhängigkeitsprüfung, `--check`) startet er
immer mit `-I` und `-B` und im Verzeichnis `/`; `-B` verhindert, dass die Prüfung als Root Bytecode
ins Release-Verzeichnis schreibt. `maillist.py` findet seine Module (`maillist_config.py`,
`maillist_delivery.py`, `maillist_headers.py`, `maillist_members.py`) trotzdem: Es importiert sie aus
dem Release-Verzeichnis, in dem es selbst liegt (U-147).

Der erste Lauf legt den Listenbenutzer (`maillist`, ein Systembenutzer ohne Login) und die
Konfigurationsdatei aus der Vorlage an und endet mit Exit-Code 2. Trage die Zugangsdaten und die
Pflichtschlüssel ein und rufe denselben Befehl erneut auf. Dann legt der Installer die Logdatei an,
installiert das Release, prüft es als Listenbenutzer mit `maillist.py --check` und schaltet erst danach
um. Alternativ aus dem Checkout: `sudo python3 -I maillist/install`.

Der Installer lädt das Archiv nur von GitHub über https und prüft es gegen `SHA256SUMS`. Die
Prüfsummen kommen aus demselben Release wie das Archiv; sie schützen gegen beschädigte Downloads, nicht
gegen ein manipuliertes Release. Vertrauensanker ist GitHub, also das Repository und dessen Zugänge.

### Layout

```text
/opt/mailadmin-maillist/
  .mailadmin-maillist     Markierung: dieses Verzeichnis gehört dem Installer
  releases/<id>/          je Release ein Verzeichnis: maillist.py, maillist_config.py,
                          maillist_delivery.py, maillist_headers.py, maillist_members.py,
                          maillist.ini.example, VERSION, README.md
  current  -> releases/<id>
  previous -> releases/<id>
```

Die Konfiguration liegt in `/etc/mailadmin-maillist/maillist.ini` (Mode `0640`, `root:maillist`), die
Logdatei bekommt `0640` und gehört dem Listenbenutzer. Der Installer überschreibt eine bestehende
Konfiguration nie; er korrigiert nur ihre Rechte.

### Optionen

| Option | Wirkung |
|---|---|
| `--release TAG\|latest` | Release von GitHub installieren (`--repo OWNER/NAME` für ein anderes Repository) |
| `--source DIR` | Quelle ist ein Verzeichnis mit `maillist.py` und seinen Modulen (Standard: neben dem Installer) |
| `--prefix`, `--config` | Installations- und Konfigurationspfad |
| `--user NAME`, `--no-user` | Listenbenutzer, oder ganz ohne eigenen Benutzer (ohne Root) |
| `--python PATH` | Interpreter, mit dem Postfix das Skript startet (absoluter Pfad) |
| `--keep-releases N` | So viele Releases behalten (Standard 3; `current` und `previous` bleiben immer) |
| `--no-verify` | Überspringt die Prüfung. Das gilt auch für die Abhängigkeitsprüfung auf `mysql.connector` |
| `--dry-run` | Gibt jeden Schritt aus, ändert nichts |
| `--rollback`, `--status` | Auf `previous` zurück, oder den Stand zeigen |

Pfade für `--prefix`, `--config` und `--python` müssen absolut sein und dürfen weder Leerzeichen noch
`$` enthalten, weil sie in der `master.cf`-Zeile stehen und Postfix `$` dort ersetzt.

### Exit-Codes des Installers

| Code | Bedeutung |
|---|---|
| `0` | fertig |
| `1` | Fehler; die Meldung steht auf stderr, `current` ist unverändert |
| `2` | die Konfigurationsdatei wurde neu angelegt und muss noch ausgefüllt werden |

### Verzeichnisse müssen dem Root gehören

Der Installer läuft als Root und ändert Dateien in Verzeichnissen, die er nicht selbst angelegt hat,
nicht. Er verweigert die Arbeit, wenn das Verzeichnis der Logdatei, der Konfiguration, das Prefix oder
`releases/` nicht root gehört oder für Gruppe oder andere schreibbar ist. Ein Beispiel ist `/var/log`
unter Ubuntu (`0775 root:syslog`). Lege deshalb ein eigenes Verzeichnis an (etwa `/var/log/mail/` mit
`root:root 0755`) und trage es unter `[log] file` ein. Symlinks und Dateien mit mehreren Hardlinks
als Ziel von Konfiguration oder Logdatei lehnt er ebenfalls ab.

## 3. Postfix

Der Installer gibt die `master.cf`-Zeile am Ende aus. Sie sieht so aus:

```text
maillist  unix  -  n  n  -  -  pipe
  flags=Rq user=maillist argv=/usr/bin/python3 -I /opt/mailadmin-maillist/current/maillist.py -- ${user}
```

Postfix startet das Skript mit `python3 -I`, genau wie der Installer seine Prüfung: Umgebungsvariablen
wie `PYTHONPATH` und ein Benutzer-`site-packages` des Listenbenutzers wirken dann nicht. Seine Module
findet das Skript trotzdem, weil es sein eigenes Release-Verzeichnis selbst in den Suchpfad setzt. Ist
die Installation unvollständig, endet es mit `75` und der Zeile `maillist: installation unusable`;
Postfix stellt die Mail zurück und versucht es erneut. Eine ältere Zeile ohne `-I` funktioniert weiter,
ist aber weniger abgeschottet.

Weicht `--config` vom Standard ab, setzt der Installer die Konfiguration über `env` in die Zeile, weil
`pipe(8)` keine Umgebung weitergibt:

```text
  flags=Rq user=maillist argv=/usr/bin/env MAILLIST_CONFIG=/pfad/maillist.ini /usr/bin/python3 -I /opt/mailadmin-maillist/current/maillist.py -- ${user}
```

`main.cf`:

```text
maillist_destination_recipient_limit = 1
```

So erhält jeder Skriptaufruf genau einen Listenschlüssel.

Die `transport`-Datei bekommt die Pseudodomain, danach `postmap`:

```text
maillist.invalid    maillist:
```

```bash
postmap hash:/etc/postfix/transport
```

Den Typ `hash:` immer ausdrücklich angeben. Ein `postmap` ohne Typ folgt `default_database_type`, das
auch `lmdb` sein kann; die erzeugte Datei passt dann nicht mehr zu `hash:` in `main.cf`.

In `main.cf` muss `transport_maps = hash:/etc/postfix/transport` auf diese Datei zeigen. Die Pseudodomain darf weder in `mydestination` noch in
`virtual_alias_domains` stehen. `MAILADMIN_LIST_TRANSPORT_DOMAIN` in mailadmin muss dieselbe Domain
tragen.

### Adresserweiterungen

Der Transport übergibt `${user}`, den Local Part ohne Adresserweiterung. Mit `recipient_delimiter = +`
(dem üblichen Wert von Postfix) und `propagate_unmatched_extensions`, das `virtual` enthält (der
Standard), erreicht Mail an `team+x@domain` die Liste als `<schluessel>`. Das funktioniert also.
Eine Listenadresse darf deshalb selbst kein `+` enthalten; mailadmin lehnt solche Adressen ab. Wer einen
anderen `recipient_delimiter` benutzt, darf auch dieses Zeichen in Listenadressen nicht verwenden.
`${mailbox}` wird bewusst nicht benutzt, weil die weitergereichte Erweiterung sonst Teil des
Schlüssels würde.

### Direkte Adressierung der Pseudodomain sperren

Ein authentifizierter Absender dürfte sonst `schluessel@maillist.invalid` direkt adressieren und die
Liste am Zugriffsschutz von mailadmin vorbei erreichen. Dagegen steht am Anfang von
`smtpd_recipient_restrictions` (nicht in `smtpd_relay_restrictions`: der submission-Dienst in
`master.cf` überschreibt diese per `-o`, die Prüfung würde dort übergangen):

```text
check_recipient_access hash:/etc/postfix/maillist_block
```

Der Eintrag muss an erster Stelle stehen, damit kein vorheriges `permit_*` (etwa
`permit_sasl_authenticated` oder `permit_mynetworks`) ihn überspringt.

Dazu die Datei

```text
maillist.invalid    REJECT
```

und danach `postmap hash:/etc/postfix/maillist_block`. Die Prüfung gilt für die RCPT-TO-Adresse vor
der `virtual`-Auflösung. Über `virtual` umgeschriebene Listenmails laufen deshalb weiter durch.

Umwege über eine lokale Domain erfasst die Sperre ebenfalls: `schluessel%maillist.invalid@domain`
(`allow_percent_hack`), `"schluessel@maillist.invalid"@domain` (`resolve_dequoted_address`) und
`maillist.invalid!schluessel@domain` (`swap_bangpath`). Postfix schreibt diese Formen vor der Prüfung auf
`schluessel@maillist.invalid` um, und `check_recipient_access` sucht nach der umgeschriebenen Adresse.
Die Testumgebung prüft das mit den Postfix-Standardwerten der drei Parameter
(`MailingListDeliveryTest`). Eine Änderung an ihnen ist deshalb nicht nötig.

**`master.cf` prüfen.** Jeder `smtpd`-Dienst in `master.cf` (`smtp`, `submission`, `smtps`), der
`-o smtpd_recipient_restrictions=…` setzt, ersetzt den Wert aus `main.cf` vollständig. Trage die
Prüfung dort ebenfalls an erster Stelle ein, sonst ist die Pseudodomain über diesen Dienst direkt
erreichbar. In der Testumgebung von mailadmin setzt der submission-Dienst nur
`smtpd_relay_restrictions` per `-o`; deshalb steht die Prüfung dort in `smtpd_recipient_restrictions`.

## 4. Aktualisieren und zurückrollen

Aktualisieren: denselben Befehl erneut ausführen. Das neue Release liegt neben dem alten, wird als
Listenbenutzer geprüft und erst danach per atomarem Symlink-Tausch aktiv. Eine laufende Zustellung
sieht immer ein vollständiges Release. Zurück: `sudo python3 -I "$dir/install-maillist" --rollback`. Den Stand
zeigt `--status`.

## 5. Datenbankbenutzer

Lege einen eigenen Benutzer an, der nur lesen darf:

```sql
CREATE USER 'maillist'@'localhost' IDENTIFIED BY '...';
GRANT SELECT ON mail.lists TO 'maillist'@'localhost';
```

Name, Host und Datenbank sind Beispiele; sie stehen in `[database]` der Konfiguration.

## 6. Umstellung aus Sicht des Skripts

Die Schritte gehören zur Umstellung in der Spezifikation. Hier stehen die, die das Skript betreffen.

1. **Skript ausrollen** (Schritt 1). Installieren wie oben. Ohne Argument verhält es sich wie bisher,
   mit `delivered_to_fallback = yes` im Abschnitt `[list]` (der Standard). Das Skript liest seine Zugangsdaten aus der
   Konfigurationsdatei; das früher im Quelltext stehende Passwort ist zu rotieren.
2. **Transport einrichten** (Schritt 2). `master.cf`, `transport`, Recipient-Limit und die Sperre aus
   Abschnitt 3. Die alte Aliasdatei bleibt, beide Wege sind aktiv.
3. **Listeneinträge umstellen** (Schritt 4). Das erledigt mailadmin, siehe Abschnitt 7.
4. **Aliasweg abschalten** (Schritt 5). Sobald alle Listen über den Transport laufen und
   `MAILADMIN_LIST_DELIVERY=transport` gesetzt ist, sind zwei Dinge zu tun, und beide sind nötig:
   - **Salt schreibt keine Listeneinträge mehr in die Aliasdatei**, und die vorhandenen Einträge
     verschwinden daraus (danach `newaliases`). Solange die Aliasdatei einen Listenschlüssel kennt,
     erreicht Mail an `<schluessel>@<beliebige lokale Domain>` die Liste über `local(8)` und den Alias.
     Dieser Weg führt nicht über die Pseudodomain, die Sperre aus Abschnitt 3 greift dort nicht, und der
     Zugriffsschutz von mailadmin wird umgangen. Das gilt während der ganzen Umstellung, solange die
     Einträge in der Aliasdatei stehen.
   - **`delivered_to_fallback = no`** im Abschnitt `[list]` der Konfiguration. Ein Aufruf ohne Argument
     endet dann mit `64`. Diese Einstellung allein reicht nicht: Postfix nimmt die Adressen weiter an
     und ruft das Skript über die Aliasdatei auf. Es lehnt den Aufruf zwar ab, aber der Schutz hängt dann
     an der Form der Aliaszeile. Trägt sie je einen Listenschlüssel als Argument, ist der Weg wieder
     offen. Erst das Entfernen der Einträge macht die Adressen für Postfix unbekannt.

Prüfe nach jedem Schritt mit `python3 -I /opt/mailadmin-maillist/current/maillist.py --check` (als
Listenbenutzer, mit gesetztem `MAILLIST_CONFIG`) und mit einer Testmail an eine Liste.

## 7. Umstellung der Listeneinträge in mailadmin (Schritt 4)

Bis hierher zeigen die `virtual`-Einträge der Altlisten auf den Aliasweg:
`dest = username = <schluessel>`, wobei `<schluessel>` die Listenadresse mit `.` statt `@` ist. Das
Wartungskommando von mailadmin stellt sie auf den Transport um: `dest = <schluessel>@<Pseudodomain>`,
`username` leer. Es läuft auf dem Webserver von mailadmin, mit dessen Konfiguration.

Voraussetzungen:

- Schritt 2 ist erledigt. Der Transport nimmt Mail an die Pseudodomain an und reicht sie an das Skript
  weiter. Teste das vorher mit einer neu angelegten Liste, denn die bekommt schon den Transport-Eintrag.
- `MAILADMIN_LIST_TRANSPORT_DOMAIN` trägt dieselbe Pseudodomain wie die `transport`-Datei.

Ablauf:

```bash
# 1. Trockenlauf: zeigt pro Liste, was passieren würde, und schreibt nichts
mailadmin migrate-list-delivery --dry-run

# 2. Umstellen
mailadmin migrate-list-delivery

# 3. Kontrolle: ein zweiter Lauf meldet jede umgestellte Liste als „unchanged“
mailadmin migrate-list-delivery --dry-run
```

Ohne den Wrapper aus `bin/install` lautet der Aufruf `php bin/migrate-list-delivery.php [--dry-run]
[--revert]` im Installationsverzeichnis, mit geladener Umgebung. Im Checkout gibt es dafür auch
`composer lists:migrate-delivery`.

Das Kommando gibt eine Zeile pro Liste aus und am Ende die Summen:

| Ergebnis | Bedeutung |
|---|---|
| `migrated` | Der Alteintrag zeigt jetzt auf den Transport. |
| `reverted` | Der Transport-Eintrag hat wieder die Altform (nur mit `--revert`). |
| `unchanged` | Der Eintrag hat schon die gewünschte Form. |
| `no virtual entry` | Die Liste steht in `lists`, hat aber keinen `virtual`-Eintrag. Das Kommando legt keinen an; ohne Eintrag erreicht die Liste keine Mail. Soll die Liste Mail bekommen, lösche sie in mailadmin und lege sie neu an. **Vorsicht:** Das Löschen entfernt auch die Mitglieder. Sichere sie vorher (Detailseite der Liste oder `SELECT members FROM lists WHERE address = …`) und trage sie beim Neuanlegen wieder ein. |
| `skipped: address contains '+'` | Die Listenadresse enthält das Trennzeichen für Adresserweiterungen. Über den Transport käme sie nicht an (Abschnitt 3), deshalb bleibt sie auf dem Aliasweg. Lege die Liste vor Schritt 5 unter einer Adresse ohne `+` neu an und lösche die alte. Lege dabei zuerst die neue Liste mit denselben Mitgliedern an (aus der Detailseite der alten Liste übernehmen), erst danach löschst du die alte. Sonst gehen die Mitglieder verloren. |
| `skipped: virtual entry is not a list entry` | Unter der Listenadresse steht ein `virtual`-Eintrag, der weder Alt- noch Transportform hat, etwa ein gewöhnlicher Alias. Er bleibt unverändert. Kläre den Fall von Hand. |
| `skipped: virtual entries of another type present` | Neben dem Listeneintrag steht unter derselben Adresse ein Eintrag anderen Typs. Das Kommando ändert dann keinen davon. Kläre den Fall von Hand. |
| `skipped: list key longer than …` | Nur bei `--revert`: Der Schlüssel passt nicht in `virtual.username` (50 Zeichen). Die Altform würde abgeschnitten. |
| `failed: …` | Der Eintrag konnte nicht geschrieben werden. Das Kommando endet mit `1`, die Ursache steht im Log von mailadmin. Ein erneuter Lauf setzt dort fort. |

Eigenschaften:

- **Idempotent.** Ein zweiter Lauf ändert nichts. Nach einem Abbruch kann das Kommando einfach erneut
  laufen. Stehen danach Alt- und Transportform einer Liste nebeneinander (etwa bei doppelten
  Alteinträgen), stellt der nächste Lauf die übrigen Einträge um.
- **Schutz vor einer verwalteten Pseudodomain.** Ist die Pseudodomain zugleich als Domain in mailadmin
  angelegt, stellt Postfix Mail an sie lokal zu, und keine Liste erreicht mehr den Transport. Die
  Umstellung bricht dann vor der ersten Änderung mit Exit-Code `1` und einer Meldung ab. Das gilt auch für
  den Trockenlauf. `--revert` läuft trotzdem.
- **Nur `virtual`.** `lists` bleibt unverändert. Erkannt wird ein Listeneintrag nach denselben Regeln wie
  in der Adressverwaltung: Die Altform zählt nur, wenn es zur Adresse eine Zeile in `lists` gibt. Die
  gleich aussehende Adresszeile eines Kontos im Punkt-Schema bleibt deshalb unberührt.
- **Eine Zeile nach der anderen**, jede mit einem einzigen `UPDATE` unter der Namensraumsperre, die auch
  die Adressverwaltung nimmt. Postfix sieht einen Eintrag nie halb geändert oder fehlend.
- **Revisionsprotokoll.** Jede geänderte Zeile bekommt einen Eintrag `list.delivery_migrate` bzw.
  `list.delivery_revert` mit dem Zustand vorher und nachher. Als Akteur steht dort
  `<system:migrate-list-delivery>` ohne Rolle. Kein in mailadmin angelegter Administrator kann so heißen.

Exit-Codes: `0` alles erledigt (auch mit `skipped` und `no virtual entry`), `1` Konfigurations- oder
Datenbankfehler, eine verwaltete Pseudodomain (nur vorwärts) oder mindestens ein `failed`, `64` falscher
Aufruf.

### Zurück

```bash
mailadmin migrate-list-delivery --revert --dry-run
mailadmin migrate-list-delivery --revert
```

`--revert` stellt jeden Transport-Eintrag einer Liste wieder auf die Altform
`dest = username = <schluessel>`. Das betrifft auch Listen, die mailadmin seit Schritt 3 neu angelegt
hat. Damit sie danach zugestellt werden, muss der Aliasweg noch aktiv sein, also Schritt 5 darf noch
nicht erfolgt oder muss zurückgenommen sein. Der nächste Lauf von Salt nimmt sie dann in die Aliasdatei
auf.

## 8. Logdatei rotieren

Das Skript schreibt mit einem `WatchedFileHandler` und folgt einer rotierten Datei. Rotiere mit
logrotate, entweder mit `copytruncate` oder mit `create 0640 maillist maillist`:

```text
/var/log/mail/maillist.log {
    weekly
    rotate 8
    compress
    missingok
    create 0640 maillist maillist
}
```

Ist die Logdatei nicht beschreibbar, schreibt das Skript ins Syslog.
