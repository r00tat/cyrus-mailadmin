<!-- markdownlint-disable MD013 MD024 MD060 -->

# A2 — Konten

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | A1, S1, M2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 7.2, 8.1 · E-13, E-14 |
| **Status** | **umgesetzt** — Backend und Oberfläche vollständig; Deaktivierung über `allusers` (U-127), wirksam erst nach dem Ausrollen des Views (O-21) |

---

## Ziel

Mailkonten anlegen, ändern, löschen und auflisten, Passwörter setzen, Quota lesen und setzen,
Namensschema je Konto berücksichtigen.

## Abgrenzung

- Keine Aliase und Weiterleitungen — das ist **A3**. Beim Löschen räumt A2 die
  `virtual`-Zeilen ab, die am Konto hängen (U-83), und bereinigt den Catchall der eigenen
  Domain, soweit er auf das Konto zeigt (U-128).
- Keine Massenrücksetzung von Passwörtern; sie fällt nach E-18 aus dem ersten Release.
- Kein Umbenennen und kein Schemawechsel eines Kontos (U-82).
- Die Dienstschalter `imap`/`pop`/`sieve`/`smtpauth` sperren nichts (U-78). Gesperrt wird über
  `enabled` (U-127).

## Abhängigkeiten und geteilte Dateien

Kernstück der Fachlichkeit. Nutzt S1 (`PasswordPolicy`, `PasswordHasher`), M2 (`Mailstore`),
F4 (`MailboxIdentity`, `AccountServices`, `PasswordFormat`), S3 (`AccountPolicy`), S4
(`AuditWriter`) und die Muster aus A1 (Repository, Responder, Reparaturwarteschlange,
`DomainView::etag()`).

Angefasste geteilte Dateien:

| Datei | Was |
|---|---|
| `openapi/components/common.yaml` | eigener `account.*`-Block im `ErrorCode`-Enum, hinter A1s |
| `frontend/src/i18n/de.ts` | deutsche Texte für die acht neuen Codes (der Katalogtest verlangt sie) |
| `tests/Support/Mailstore/UnreliableMailstore.php` | `failNext($operation, $times, $after)` — Fehler für beliebige Mailstore-Schritte; `loseAnswerNext($operation, $tookEffect)` — Zeitüberschreitung ohne eindeutige Antwort (U-86) |
| `config/di/` | eigene Datei `accounts.php` |

`Permission` und `RolePermissions` **nicht** angefasst — S3 hatte `account.read`,
`account.write`, `account.delete` und `account.password.write` bereits deklariert und beiden
Rollen zugeordnet. Deshalb deklariert keine Kontooperation ein `403`: die Grenze eines
Domainmasters ist die Domain, und die zeigt sich als `404`. Keine Migration — A2 kommt ohne
neue Tabelle aus.

## Vorgaben

- **Anlegen: Mailstore zuerst, dann Datenbank.** Löschen umgekehrt (Architektur 8.1).
- Neue Passwörter als bcrypt; Bestandshashes bleiben gültig (E-13).
- Namensschema für neue Konten aus der Domain- bzw. globalen Einstellung; bei bestehenden
  Konten aus dem gespeicherten Benutzernamen erkannt (E-14).
- Quota kommt aus Cyrus, nicht aus der Datenbank — eigene Ressource, nicht in der Liste
  mitgeliefert (Architektur 7.2).
- Die Kontenliste zeigt das Hash-Format und lässt danach filtern (E-13).

## Artefakte

| Artefakt | Datei |
|---|---|
| Vertrag | `openapi/paths/accounts.yaml`, `openapi/components/account.yaml` |
| Endpunkte | `GET/POST /domains/{domain}/accounts`, `GET/PATCH/DELETE /accounts/{account}`, `PUT /accounts/{account}/password`, `GET/PUT /accounts/{account}/quota` |
| Anwendungsfälle | `src/Application/Account/{List,Get,Create,Update,Delete}Account(s).php`, `SetAccountPassword`, `GetAccountQuota`, `SetAccountQuota` |
| Ein-/Ausgabe | `AccountDraft`, `AccountRecord`, `AccountCreated`, `AccountView`, `AccountLocator`, `DomainAllowance`, `AccountOperationFailed`, `AccountErrorCode` |
| Port + Umsetzung | `Application\Account\AccountStore`, `Infrastructure\Persistence\AccountRepository`, `InMemoryAccountStore` |
| Handler | `src/Http/Handler/Accounts/` (acht Handler, `AccountResponder`, `AccountPath`) |
| Verdrahtung | `config/di/accounts.php` |
| Sperren | `Infrastructure\Persistence\NamedLock` für die Domainsperre (U-81) und, über `DomainStore::withNamespaceLock()`, die Namensraumsperre (U-97); Punktnamenregel in `Domain\Model\DottedNamespace` |
| Protokoll | S4s `AuditWriter`: `account.create`, `account.update`, `account.password.set` (nur das Hashformat vorher/nachher), `account.quota.set`, `account.delete` (mit Zahl der entfernten `virtual`-Zeilen); eine abgebrochene Anlage als `failed` nach der Kompensation (wie U-76) |
| Oberfläche | `frontend/src/pages/accounts/`: Liste je Domain (`/accounts?domain=…`, Spalte und Filter Hashformat, Sortierung über die Allowlist des Vertrags), Anlage (`/accounts/new`, Hinweis auf das Namensschema mit Vorschau des Benutzernamens, Vorgabe-Quota der Domain), Detailseite (`/accounts/{account}`: Stammdaten mit Hinweis auf Altformate, Sperren mit Rückfrage, Dienstschalter, Passwort setzen, Quota als eigene Ressource live aus Cyrus mit `Intl`-Byteformat, Löschen mit eingetipptem Namen und dem Hinweis auf Weiterleitung und Catchall-Ziel). Weiterleitung und Abwesenheit kommen aus A3 auf dieselbe Seite. Texte in `frontend/src/i18n/de/account.ts`; Tests `*.test.tsx` daneben |

`{account}` ist der URL-kodierte gespeicherte Cyrus-Benutzername (U-18); `AccountPath`
dekodiert ihn und reicht ihn unzerlegt weiter. Das Hashformat wird in SQL erkannt
(`AccountRepository::PASSWORD_FORMAT`, Regel für Regel wie `PasswordFormatDetector`), sodass
kein Lesezugriff je die Spalte `password` auswählt.

## Zu klärende Punkte

Alle acht sind beantwortet. Was über A2 hinausreicht, steht als U-Entscheidung; der Rest ist
hier als **Annahme** markiert.

1. **Kontenname bei `freenames = NO`.** → **U-77.** Immer frei gewählt, kein Zähler. Der Local Part
   wird kleingeschrieben; die Belegtprüfung ignoriert die Schreibweise.
2. **Deaktivieren statt Löschen.** → **U-127** (ersetzt U-78, schließt O-17). Siehe
   „Deaktivierung" unten. Die Dienstschalter werden weiter getragen und änderbar gehalten,
   sperren aber nichts — das steht so im Vertrag.
3. **Was bedeutet Quota `0`?** → **U-79.** `null` ist unbegrenzt, Minimum `1` KiB, `0` lehnt der
   Vertrag ab. Ohne Angabe bei der Anlage gilt die Kontovorgabe der Domain.
4. **Standardordner.** → **U-80.** Bei jeder Anlage angelegt und über Proxy-Auth abonniert, nach
   dem Datenbankschritt. Ein Fehlschlag ergibt `201` mit `incomplete_steps` und einen
   Reparatureintrag, nicht den Abbau des Kontos.
5. **Kontingentprüfung.** → **U-81.** `maxaccounts` und Namensbelegung vor dem Mailstore und noch
   einmal unter `GET_LOCK` je Domain; `domainquota` beim Anlegen und beim Erhöhen gegen die Summe
   der Cyrus-Limits. Senken eines bestehenden Limits ist frei; ein erstes Limit auf einem
   unbegrenzten Konto wird geprüft (U-86). Der Wettlauf zweier Erhöhungen wird hingenommen.
6. **Umbenennen eines Kontos.** → **U-82.** Nicht in A2. Es wäre eine eigene bestätigte
   Operation wie die Domainumbenennung.
7. **Löschen eines Kontos mit vorhandener Mail.** → **U-83.** `If-Match` ist Pflicht; die Größe
   liest der Client aus der Quota-Ressource; kein Rückholen. Ein Postfach, das nach dem
   Datenbankschritt nicht entfernt werden kann, ergibt trotzdem `204` und einen
   Reparatureintrag.
8. **`prefix` in `accountuser`.** → **U-84.** Aus `domain.prefix` übernommen, nur angezeigt.

Nach dem Review hinzugekommen: **U-86** — Audit-Fehler auf MyISAM bei Löschen, Dienstschaltern
und Passwort; `CREATE` ohne eindeutige Antwort; erstes Limit als Erhöhung; Punktnamen im
Namensraum einer Subdomain.

Im zweiten Review nachgezogen: **U-96** — die Gegenrichtung von U-86 (d): eine neue oder
umbenannte Domain, die einen bestehenden Punktnamen einer Elterndomain mehrdeutig machen würde,
lehnt A1 ab. **U-97** — der Datenbankschritt der Kontoanlage läuft unter der Namensraumsperre
`mailadmin.namespace` (außen) und der Domainsperre aus U-81 (innen); unter ihnen werden Existenz
der Domain, Punktname, Belegung und Kontingent noch einmal geprüft. Eine Domain, die zwischen
Mailstore und Datenbankschritt gelöscht wurde, ergibt `404` und das Postfach wird wieder
entfernt. **U-98** — `AccessDenied` und `ResourceNotVisible` sind keine `DomainError` mehr; die
ausdrücklichen Weiterwürfe in `CreateAccountHandler`, `SetAccountPasswordHandler` und
`SetAccountQuotaHandler` sind entfernt.

Im dritten Review geprüft: Die Belegtprüfung `isTaken()` vergleicht `accountuser.username` ohne
Unterscheidung der Schreibweise, obwohl die Spalte die Kollation `latin1_bin` trägt — `LOWER()`
wirkt auf ein `VARCHAR` mit binärer Kollation. Der Befund wurde verworfen, ein Integrationstest
hält das Verhalten fest. Eine Domainumbenennung, die während einer Kontoanlage läuft, strandet
das neue Konto nicht mehr (U-100, A1).

Nach Freigabe durch den Projekteigner nachgezogen: **U-127** — Kontosperre über den View
`allusers` (unten). **U-128** — der Catchall geht mit dem Konto (unten).

### Deaktivierung (U-127)

- `PATCH /accounts/{account}` mit `{"enabled": false}` sperrt, `{"enabled": true}` entsperrt;
  `services` kann in derselben Anfrage stehen. Berechtigung `account.write` (Domainmaster für die
  eigene Domain, fremdes Konto `404`). Audit: `account.disable` / `account.enable`.
- Gespeichert in `mailadmin_account_status` (Migration 0060). `accountuser` bleibt unverändert.
- Wirksam über den View `allusers`, der gesperrte Konten weglässt. Damit schlagen IMAP, POP,
  ManageSieve und SMTP-AUTH fehl — unter Benutzername und Adresse. Läuft der alte View, lehnt
  mailadmin die Sperre mit `409 account.status_not_enforced` ab und ändert nichts.
- **Die Zustellung läuft weiter.** Postfix liest `virtual`; Mail an ein gesperrtes Konto landet
  weiter im Postfach. Offene Sitzungen bleiben bis zum Abmelden offen; ein Credential-Cache von
  `saslauthd` verzögert die Sperre (O-21).
- Ausrollen: erst `php bin/migrate.php`, dann der View nach
  `docs/deployment/allusers.sql.example`; `php bin/db-check.php` meldet, ob der ausgerollte View
  die Sperre auswertet. Umgekehrt ausgerollt bricht der View **jede** Anmeldung.

### Löschen und der Catchall (U-128)

Zeigt der Catchall `@<domain>` auf das gelöschte Konto — über den Benutzernamen oder eine
Adresse, die mit dem Konto geht —, wird er im selben Datenbankschritt bereinigt: bleiben andere
Ziele, fällt nur das tote heraus; bleibt keines, wird die Zeile gelöscht. Ein `username`, der
das Konto nennt, wird geleert. Im Audit steht `catchall.set` bzw. `catchall.delete` als
Kindeintrag von `account.delete`. Catchalls anderer Domains bleiben unberührt (O-20) — seit
**U-129** auch dann, wenn ihr `username` das Konto als Absender nennt: Das Löschen entfernt nur
noch Zeilen der eigenen Domain (Domain nach U-126), keinen Catchall und keinen Alias einer
fremden Domain samt deren übrigen Zielen.

### Nachbesserungen aus dem Review (U-129)

- **Domainumbenennung und Sperre:** Vor der Transaktion bekommt jeder neue Benutzername die
  Statuszeile des alten (`DomainStore::carryAccountStatus()`), eine liegengebliebene Zeile unter
  dem neuen Namen fällt weg. Die alte Zeile entfernt erst `rename()`, nach dem Umzug der
  Kontozeile. Scheitert die Transaktion, nachdem MyISAM die Kontozeile schon umbenannt hat, bleibt
  das Konto unter beiden Namen gesperrt.
- **`bin/db-check.php`** prüft jeden Zweig der `UNION` einzeln und das Verhalten unter beiden
  Schreibweisen (`AccountStatusEnforcement`).
- **Kontolöschung:** Zeilen fremder Domains, die das Konto als Absender nennen, bleiben stehen
  (s. o.).
- Verworfen: eine andere `latin1`-Kollation bei `accountuser.username` bricht den Join nicht —
  MariaDB löst die Mischung mit `latin1_bin` zugunsten von `latin1_bin` auf; ein
  Integrationstest hält das fest.

### Nachbesserungen an der Oberfläche (U-133)

- **Quota:** `quotaChoiceOf` rundet nicht mehr. Eine Grenze, die keine ganzen MiB ist, steht im
  Dialog als ungerundete MiB-Zahl und wird beim Speichern exakt zurückgeschrieben.
- Die Radiogruppe der Quota-Eingabe ist über `aria-labelledby` mit ihrer Beschriftung benannt.

### Paket-lokale Annahmen

- **Annahme: die Zeile wird vor der Policy gelesen.** Bei `/accounts/{account}` steht die Domain
  nicht im Pfad, und der Benutzername darf nicht zerlegt werden (Architektur 3.3) — die Domain
  kommt aus `accountuser.domain_name`. Ein fremdes Konto ergibt danach dieselbe
  `ResourceNotVisible` wie eine fremde Domain, also ein `404`, das sich von „gibt es nicht"
  byteweise nicht unterscheidet.
- **Annahme: ein Konto, dessen Benutzername nicht zu seiner Domain passt, wird angezeigt, aber
  nicht geändert.** Es erscheint mit `resolvable: false` (U-22). Ändern, Passwort, Quota und
  Löschen antworten `409 account.identity_mismatch` — aber erst, nachdem feststeht, dass der
  Akteur die Domain sehen darf; sonst verriete das `409` die Existenz.
- **Annahme: Zeilen mit einer unbrauchbaren `domain_name`** (etwa die Cyrus-Adminzeile einer
  echten Installation) gibt `find()` nicht zurück — sie sind kein Konto.
- **Annahme: `password_format_current` heißt „bcrypt"**, also das Zielformat von
  `PasswordSubject::AccountUser`; `password_format_weak` übernimmt F4s Einstufung.
- **Annahme: die Reparaturwarteschlange bekommt nur, was übrig bleibt (U-85).** Die DoD-Zeile
  „hinterlässt keinen Datenbankeintrag und einen Eintrag in der Reparaturwarteschlange" wird so
  gelesen: ein Eintrag entsteht, wenn die Kompensation scheitert und ein verwaistes Postfach
  bleibt. Gelingt sie, steht der Abbruch als `failed` im Protokoll.

## Was bei einem Fehler geschieht

| Schritt scheitert | Ergebnis |
|---|---|
| Prüfungen vor dem Mailstore (Name, Kontingent, Quota, Passwort, Länge) | Ablehnung, kein System angefasst, kein Protokoll |
| `CREATE` des Postfachs | `409 account.mailbox_exists` (fremdes Postfach bleibt unberührt) oder `503 account.mailstore_unavailable` |
| `CREATE` ohne eindeutige Antwort (Zeitüberschreitung, Abbruch) | `503 account.mailstore_unavailable`; ist das Postfach danach da oder die Probe nicht möglich, Reparatureintrag `mailstore.create_mailbox_uncertain` — das Postfach bleibt unberührt (U-86) |
| ACL oder Quota | Postfach wird entfernt, `409 account.setup_incomplete`, `failed` im Protokoll |
| Datenbankschritt (auch Audit) | erst etwa liegengebliebene Zeilen löschen (MyISAM, O-11), dann das Postfach; `409 account.setup_incomplete` |
| Zweitprüfung unter der Sperre | Postfach wird entfernt, `409 account.limit_reached` / `account.name_taken` (auch für einen Punktnamen, der inzwischen in eine Subdomain fällt), `404` für eine inzwischen gelöschte Domain (U-97) |
| Namensraumsperre nicht frei (zehn Sekunden) | wie ein Fehler des Datenbankschritts: Postfach wird entfernt, `409 account.setup_incomplete` (U-97) |
| Entfernen des Postfachs beim Ausgleich | Reparatureintrag `mailstore.delete_mailbox` |
| Entfernen liegengebliebener Zeilen | Postfach bleibt stehen, Reparatureintrag `database.remove_rows` |
| Standardordner | Konto bleibt, `201` mit `incomplete_steps`, Reparatureintrag `mailstore.default_folders` |
| Postfach nach dem Löschen der Zeilen | `204`, Reparatureintrag `mailstore.delete_mailbox` |
| Audit beim Löschen, Zeilen trotzdem weg (MyISAM) | Postfach wird entfernt, `204`, Reparatureintrag `audit.record` (U-86) |
| Löschen, Zeilen noch da | Fehler an den Client, Postfach unberührt; ein schon umgeschriebener Catchall wird zurückgesetzt, scheitert das, Reparatureintrag `virtual.restore` (U-128) |
| Catchall-Zeilen zwischen Lesen und Schreiben fremd geändert | `409 request.conflict`, nichts geändert (U-128) |
| Sperre, die der ausgerollte View nicht auswertet | Sperre wird zurückgenommen, `409 account.status_not_enforced` (U-127) |
| Audit nach Sperren/Entsperren | alter Status und alte Schalter werden zurückgeschrieben, Fehler an den Client (U-127) |
| Audit nach Dienstschaltern | alte Schalter werden zurückgeschrieben, Fehler an den Client (U-86) |
| Audit nach Passwort (MyISAM) | neuer Hash bleibt stehen, Fehler an den Client — bewusst offen (U-86) |
| Audit nach `SETQUOTA` | altes Limit wird zurückgesetzt; scheitert auch das, `mailstore.restore_quota` |

## Definition of Done

- [x] Anlegen erzeugt Mailbox, ACL, Quota und die Datenbankeinträge in der festgelegten
      Reihenfolge.
- [x] Ein Fehler nach der Mailbox-Anlage hinterlässt keinen Datenbankeintrag; was übrig bleibt,
      steht in der Reparaturwarteschlange (Lesart: U-85).
- [x] Löschen entfernt zuerst die Zustellung, dann die Mailbox.
- [x] Ein neu gesetztes Passwort funktioniert für IMAP und SMTP-AUTH (Test gegen F5).
- [x] Die Liste zeigt das Hash-Format und ist danach filterbar.
- [x] Jede Änderung erzeugt einen Audit-Eintrag in der Transaktion der Änderung (Lesart für
      MyISAM-Tabellen und Cyrus: U-85, U-86 — bis auf das Passwort steht keine Änderung ohne
      Eintrag oder Reparatureintrag).
- [x] Ein gesperrtes Konto kann sich weder über IMAP noch über SMTP-AUTH anmelden, ein
      entsperrtes wieder; Mail an ein gesperrtes Konto nimmt Postfix weiter an (Test gegen F5,
      U-127).
- [x] Löschen lässt keinen Catchall der eigenen Domain auf das Konto zeigen (U-128).
- [x] Oberfläche: Liste, Anlage, Detail, Sperren, Passwort, Quota und Löschen, mit jsdom-Tests
      für Erfolgsweg, Fehleranzeige, rechteabhängige Sichtbarkeit und bestätigte Zerstörung.

## Testfälle

| Stufe | Datei | Was |
|---|---|---|
| Anwendungsfall | `tests/Application/Account/CreateAccountTest.php` | Reihenfolge Mailbox → ACL → Quota → Zeilen → Ordner; bcrypt; beide Schemata; **DB-Fehler mit Kompensation**, **gescheiterte Kompensation → Reparatureintrag**, ACL-/Quota-Fehler, liegengebliebene Zeilen (Attrappe ohne Rollback wie MyISAM), Ordnerfehler → `incomplete_steps`; **`CREATE` ohne Antwort mit und ohne Postfach** (U-86); belegter Name, Alias, **Punktname im Namensraum einer Subdomain** (U-86), **Subdomain, die vor der Sperre entsteht**, **Zeilen unter der Namensraumsperre**, **Domain, die vor der Sperre gelöscht wird** (U-97), Kontingent, **gleichzeitige Anlage unter der Sperre**, Namenslänge, `domainquota`, schwaches Passwort ohne Postfach, fremde Domain |
| Anwendungsfall | `tests/Application/Account/ManageAccountTest.php` | Lesen, Liste mit Formatfilter, nicht auflösbare Zeile, Dienstschalter mit `If-Match`, Passwort als bcrypt mit Protokoll ohne Hash, Quota lesen/senken/erhöhen, **erstes Limit auf unbegrenztem Konto** (U-86), Rücksetzen bei Audit-Fehler (Quota und Dienstschalter), Löschen mit `If-Match` und Reparatureintrag, **Audit-Fehler nach dem Löschen der Zeilen** und **Löschen, das die Zeilen stehen ließ** (U-86), fremdes Konto; **Sperren und Entsperren** mit Audit, Domainmaster, fremdes Konto, alter View → `account.status_not_enforced`, Rücksetzen bei Audit-Fehler (U-127); **Catchall beim Löschen**: Zeile entfernt, nur totes Ziel entfernt, Benutzername als Ziel, `username` geleert, abgeschaltete Zeile, fremder Catchall unberührt, Adresse eines anderen Kontos bleibt, Rücksetzen, Kindeintrag im Audit (U-128) |
| Vertrag | `tests/Contract/AccountEndpointTest.php` | alle acht Endpunkte durch die echte Kette; `404` byteweise identisch für fremd und fehlend an jedem Konto-Endpunkt; `401`; `%40` im Pfad; Sortier-Allowlist und `ErrorCode`-Parität |
| Integration | `tests/Integration/Persistence/AccountRepositoryIntegrationTest.php` | MariaDB mit Altschema plus 0003/0030/0060: **Formaterkennung SQL gegen PHP**, Anlage beider Zeilen, Belegtprüfung (auch gemischte Schreibweise ohne Adresszeile), Löschen samt `virtual`, Liste, `GET_LOCK`; Statuszeile lesen, schreiben, mit dem Konto löschen, bei der Anlage entfernen (U-127); Catchall umschreiben und entfernen im Löschschritt (U-128) |
| Integration (F5) | `tests/Integration/TestEnvironment/AccountStatusViewTest.php` | neuer View `allusers` gegen die alte Definition auf den Fixtures: ohne Statuszeile identisch, mit gesperrtem Konto genau ohne dessen Zeilen; Migration und Init-Skript, View und Salt-Vorlage deckungsgleich (U-127) |
| Integration (F5) | `tests/Integration/TestEnvironment/AccountLifecycleTest.php` | in `leer.test`, **beide Namensschemata**: Anlegen, Quota aus Cyrus, IMAP- und SMTP-AUTH-Anmeldung, Passwort setzen, altes Passwort abgewiesen, **sperren: keine Anmeldung unter Name und Adresse, Mail wird angenommen; entsperren: Anmeldung wieder möglich** (U-127), Löschen samt Statuszeile, keine Anmeldung mehr |

Ein eigener Integrationsfall „Domainmaster kann in fremder Domain kein Konto anlegen" ist durch
den Vertragstest abgedeckt, der die echte Autorisierungskette durchläuft.
