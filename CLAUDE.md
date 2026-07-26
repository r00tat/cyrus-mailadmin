# CLAUDE.md — mailadmin

Projektspezifische Anweisungen. Ergänzt die globale Konfiguration, überschreibt sie nicht.

## Worum es geht

mailadmin ersetzt web-cyradm als Weboberfläche für einen Mailserver aus Postfix, Cyrus IMAP und
MySQL. PHP-8-Backend mit REST-API, React-SPA als Frontend.

Vollständiger Überblick: [docs/README.md](docs/README.md).
Architektur: [docs/03-architektur.md](docs/03-architektur.md).
Entscheidungsprotokoll (E-1 … E-18): `docs/internal/02-entscheidungen.md`.
Arbeitspakete: [docs/04-detailkonzept.md](docs/04-detailkonzept.md) und [docs/features/](docs/features/).

**Wer ein Arbeitspaket bearbeitet**, liest zuerst dieses Dokument, dann die im Paket genannten Architekturabschnitte, dann das Paketdokument. Was in
[04-detailkonzept.md, Abschnitt 5](docs/04-detailkonzept.md) als Eskalationsfall steht, wird nicht im Paket entschieden.

---

## Die drei Regeln, die alles andere überstimmen

### 1. Die Datenbank gehört uns nicht allein

Postfix liest `domain`, `virtual` und den View `validsenders` zur Laufzeit direkt.
`saslauthd`/`pam_mysql` authentifiziert gegen den View `allusers`. Salt erzeugt die Views und
liest `lists`. Ein Schemabruch legt nicht die Oberfläche lahm, sondern **Zustellung und
Authentifizierung**.

- Bestandstabellen bleiben semantisch unverändert: `domain`, `accountuser`, `virtual`,
  `adminuser`, `domainadmin`, `settings`, `lists`.
- Die Views `allusers` und `validsenders` müssen jederzeit gültige Ergebnisse liefern.
- Migrationen sind **rein additiv**: neue Tabellen, oder neue Spalten mit Default. Kein `DROP`,
  kein `RENAME`, keine Typverengung an Bestandsspalten.
- Neue Tabellen tragen das Präfix `mailadmin_`.

### 2. Kein SQL aus Strings

Ausnahmslos Prepared Statements. `PDO::ATTR_EMULATE_PREPARES = false`.

Das gilt auch für Sortier- und Filterparameter: erlaubte Feldnamen kommen aus einer Allowlist,
die im OpenAPI-Schema steht. Das Altsystem interpolierte `ORDER BY` aus der Session direkt ins
SQL — das ist der Fehler, den dieses Projekt nicht wiederholt.

### 3. Der Vertrag zuerst

`openapi/openapi.yaml` ist die einzige Wahrheit für die API. Änderungen beginnen dort, nie im
Handler. Aus dem Schema entstehen Routentabelle, Request-Validierung, Frontend-Typen,
Dokumentation und Vertragstests.

- Jede Operation braucht eine `operationId` und eine `x-permission`-Deklaration.
- Fehlt zu einer `operationId` ein Handler oder umgekehrt: Buildfehler, nicht stillschweigend
  durchlassen.

---

## Was nie ins Repository darf

- **`.env` und Zugangsdaten.** Konfiguration kommt aus der Umgebung. `.env.example` mit
  neutralen Platzhaltern ist erwünscht.
- **Produktivdetails.** Keine Hostnamen, Domainlisten, IP-Adressen oder Serverpfade des
  konkreten Servers in Code, Kommentaren oder Dokumenten außerhalb von `docs/internal/`.
- **`docs/internal/`** ist per `.gitignore` ausgenommen und bleibt es. Dort liegen
  Bestandsaufnahme, Entscheidungsprotokoll und Domain-Inventar mit realen Kundendaten.

Vor dem ersten Veröffentlichen: Historie auf versehentlich eingecheckte Produktivdetails prüfen.

---

## Fachliche Fallstricke

### Der Cyrus-Benutzername wird erzeugt, nie zerlegt

Ein Konto hat mehrere Schreibweisen, und `local.domain.tld` ist **nicht** eindeutig zerlegbar —
der Local Part darf Punkte enthalten. Wo der Local Part endet, steht nur in
`accountuser.domain_name`.

- Immer über das Value Object `MailboxIdentity` arbeiten, konstruiert aus
  (Local Part, Domain, Schema).
- Die einzige erlaubte Zerlegung: „enthält der gespeicherte Name ein `@`" — das entscheidet
  über das Namensschema und ist eindeutig, weil ein `@` nur Domaintrenner sein kann.
- Das Schema eines bestehenden Kontos wird **erkannt**, nie aus der Konfiguration abgeleitet.
  Die Konfiguration bestimmt nur, was neue Konten bekommen.

### `virtual` trägt vier verschiedene Dinge

Kontoadresse, Alias, Catchall (`alias = '@domain'`) und Weiterleitung (leeres `username`) —
unterschieden nur durch Feldbelegung. Im Code als vier explizite Typen modellieren. Ein Eintrag,
der in kein Muster passt, wird **angezeigt**, nicht stillschweigend übergangen.

### Reihenfolge bei Operationen über beide Systeme

MySQL-Transaktionen umfassen Cyrus nicht. Die Regel lautet: so scheitern, dass niemand Mail
verliert.

| Operation | Reihenfolge | Warum |
|---|---|---|
| Konto anlegen | **Mailstore zuerst**, dann DB | Ein verwaistes Postfach ist harmlos. Ein DB-Eintrag ohne Postfach führt zu Bounces. |
| Konto löschen | **DB zuerst**, dann Mailstore | Nach dem DB-Löschen fließt keine Mail mehr. |
| Domain umbenennen | `renamemailbox` über alle Konten, dann DB | Der Domainname steckt im Cyrus-Benutzernamen. Nur die DB zu ändern macht Konten unerreichbar — genau der Fehler des Altsystems. |
| Namensschema wechseln | wie Domain umbenennen | Zusätzlich muss der Nutzer seine Zugangsdaten ändern; nie automatisch auslösen. |

Was trotzdem hängen bleibt, gehört in `mailadmin_repair_queue`, nicht in ein Log.

### Passwörter

| Tabelle | Verfahren | Grund |
|---|---|---|
| `adminuser` | Argon2id | kein externer Konsument |
| `accountuser` | bcrypt (`$2y$`) | muss `crypt(3)`-lesbar bleiben, `pam_mysql` prüft mit |

- **Niemals ein Verfahren für `accountuser` wählen, das `crypt(3)` nicht versteht.** Argon2id
  bricht dort die IMAP- und SMTP-Anmeldung. Verifiziert funktionieren bcrypt, sha256crypt,
  sha512crypt und scrypt; `password_hash()` erzeugt davon nur bcrypt.
- Rehash über `password_needs_rehash()`, nicht bedingungslos.
- `PasswordHasher.verify()` muss alle Altformate lesen: DES-crypt, md5crypt, SHA1-hex,
  MySQL-`PASSWORD()`, Klartext. Diese Konten bleiben gültig — es gibt keine Zwangsumstellung.
- Vergleiche über `hash_equals()`. Unbekannte Benutzernamen durchlaufen eine
  Dummy-Verifikation, damit die Antwortzeit nichts verrät.

### Sieve erzeugen, nicht patchen

Skripte vollständig aus dem strukturierten Modell rendern. Nie lesen-und-per-Regex-ändern —
das war die Fehlerquelle im Altsystem. Ein aktives Skript ohne unsere Markierung wird nicht
überschrieben.

### Token und Frontend-Sicherheit

Das Zugriffstoken liegt im `localStorage` (E-15) — eine bewusste Abwägung zugunsten der
Handhabung. Daran hängen Regeln, die nicht verhandelbar sind:

- **Das Erneuerungstoken bleibt im HttpOnly-Cookie.** Niemals ebenfalls in den `localStorage`
  legen — das verlängert ein gestohlenes Token von 60 Minuten auf 12 Stunden.
- **Die Content-Security-Policy nicht aufweichen.** Kein `unsafe-inline`, kein `unsafe-eval`.
  Wenn der Bundler Inline-Skripte erzeugt, wird der Bundler geändert, nicht die Policy.
- **Keine Fremdinhalte im Frontend**, kein `dangerouslySetInnerHTML` ohne Bereinigung.
- Die Token-Laufzeit von 60 Minuten wird nicht erhöht, um Anmeldungen zu sparen.

### Cyrus-Zugriff

- TLS ist Pflicht, mit Zertifikatsprüfung.
- Eindeutige Command-Tags, korrekte Literale, Timeouts auf Verbindung und Lesevorgang.
- Verbindungen innerhalb einer Anfrage wiederverwenden — keine Verbindung pro Listenzeile.
- Ordner im Namen eines Nutzers über SASL-Proxy-Auth anlegen (`authzid`), **nie** durch
  Anmeldung mit dem Passwort des Nutzers.
- `ext-imap` nicht verwenden: ab PHP 8.4 nicht mehr im Core und für Adminkommandos ungeeignet.

---

## Konventionen

- **Sprache:** Code, Bezeichner und Commit-Messages auf Englisch. Dokumentation auf Deutsch.
- **Mehrsprachigkeit ist Pflicht, nicht Kür** (E-16). Keine festverdrahteten Oberflächentexte —
  alles über Textschlüssel. Die API bleibt sprachneutral: jeder Fehler trägt ein stabiles
  `code`-Feld, das Frontend übersetzt. Niemals eine übersetzte Meldung zum Vertragsbestandteil
  machen. Ausgeliefert wird vorerst nur Deutsch.
- **Autorisierung:** Jeder Anwendungsfall bekommt einen `ActorContext` als ersten Parameter —
  nicht optional. Ressourcenprüfungen in Policy-Objekten. Fremde Objekte ergeben `404`, nicht
  `403`.
- **Fehler:** `application/problem+json`. Keine Ausnahmemeldungen an den Client durchreichen.
- **Logging:** strukturiert, mit Request-Id. Passwörter, Hashes und Token werden nie geloggt,
  auch nicht gekürzt.
- **Audit:** Jede zustandsändernde Operation schreibt nach `mailadmin_audit`.
- **Keine neuen Abhängigkeiten ohne Anlass.** Der schlanke Stack ist eine bewusste Entscheidung
  (E-12). Wer ein Paket hinzufügt, begründet es.

---

## Arbeiten am Produktivsystem

Für Analyse und Verifikation kann ein Zugriff auf den Produktivserver nötig sein.

- **Lesend:** in Ordnung, wenn die Aufgabe es erfordert.
- **Schreibend:** nur nach ausdrücklicher Freigabe für den konkreten Vorgang. Danach
  vollständig aufräumen und das Aufräumen verifizieren.
- Geheimnisse, die dabei sichtbar werden, gehören nicht in Dokumente oder Commits — nur der
  Hinweis, dass sie rotiert werden sollten.

---

## Tests

- Anwendungsfälle gegen eine In-Memory-Attrappe des Mailstores. Deshalb existiert die
  Schnittstelle.
- Vertragstests gegen das OpenAPI-Schema.
- Integrationstests gegen einen echten Cyrus, sobald eine Testumgebung steht.
- Der Migrator wird gegen einen Dump des Altschemas getestet, nicht gegen ein leeres Schema.

---

## Stand

Alle strukturellen Entscheidungen sind getroffen (E-1 bis E-18). Bewusst offen gelassen, jeweils
mit Begründung im Entscheidungsprotokoll: Bestandshashes bleiben in Altverfahren, das
Zugriffstoken liegt im `localStorage`, die Drift zwischen Datenbank und Salt-Konfiguration bleibt
unsichtbar, und eine Bestandsbereinigung findet nicht statt.

Noch festzulegen: Lizenz, Wurzel-README, Umfang des ersten Release. Siehe
[docs/03-architektur.md, Abschnitt 15](docs/03-architektur.md).
