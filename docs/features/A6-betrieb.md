<!-- markdownlint-disable MD013 MD024 MD060 -->

# A6 — Abgleich, Reparaturwarteschlange, Zustandsprüfung

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | A2, M2, F3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 8.2 |
| **Status** | **umgesetzt** — Backend und Oberfläche (Warteschlange, Abgleich, Systemzustand; U-131) |

---

## Ziel

Sichtbar machen, was zwischen Datenbank und Mailstore auseinanderläuft, offene
Reparaturvorgänge anzeigen und wiederholbar machen, und die Erreichbarkeit der Abhängigkeiten
melden.

## Abgrenzung

- **Es wird nichts automatisch bereinigt** (E-18). Der Abgleich zeigt an; das Handeln bleibt
  beim Menschen.
- Kein Abgleich gegen die Salt-generierte Konfiguration (E-9).

## Abhängigkeiten und geteilte Dateien

Nutzt M2 zum Auflisten der Mailboxen und die Repositories aus A1 und A2.

## Vorgaben

- Der Abgleich prüft **beide Cyrus-Namensräume** — den flachen und den virtdomains-Baum. Das
  Altsystem sah nur einen und übersah dadurch Schatten-Postfächer (Architektur 8.2).
- Die Reparaturwarteschlange nimmt auf, was bei zusammengesetzten Operationen hängen bleibt.

## Artefakte

| Artefakt | Datei |
|---|---|
| Vertrag | `openapi/paths/maintenance.yaml`, `openapi/components/maintenance.yaml`; Fehlercodes `repair.*` in `components/common.yaml` (U-122); `components/health.yaml` beschreibt die drei Prüfungen |
| Endpunkte | `GET /maintenance/reconciliation`, `GET /maintenance/repairs`, `GET /maintenance/repairs/{repair}`, `POST /maintenance/repairs/{repair}/retry`, `POST /maintenance/repairs/{repair}/resolve`, `GET /maintenance/health` (alle Superuser, `maintenance.read`/`maintenance.write`); `GET /health` öffentlich |
| Migration | `migrations/0040_repair_queue.sql` — `mailadmin_repair_queue`, rein additiv (U-117) |
| Abgleich | `src/Application/Maintenance/Reconcile.php`, `ReconciliationReport`, `Finding`, `FindingCategory`; Port `MaintenanceInventory` mit `Infrastructure\Persistence\SqlMaintenanceInventory` und `Infrastructure\Repair\InMemoryMaintenanceInventory` |
| Warteschlange | `ListRepairs`, `GetRepair`, `RetryRepair`, `ResolveRepair`, `RepairCatalogue`, `RepairEntry`, `RepairOperationFailed`, `RepairErrorCode`; Port `RepairLedger` mit Eintragssperre `withEntryLock()` (U-125); `Infrastructure\Persistence\RepairQueueRepository` (beide Ports), `Infrastructure\Repair\InMemoryRepairLedger` |
| Zustandsprüfung | `HealthMonitor`, `HealthProbes`, `HealthProbe`, `ProbeResult`, `HealthReport`, `GetHealthDetails`; `Infrastructure\Health\{TimedProbe,FileHealthReportCache,UncachedHealthReports}` (eine Prüfrunde zur Zeit über `health.lock`, U-123); `ManageSieveConnectionPool::probe()` |
| Handler | `src/Http/Handler/Maintenance/` (sechs Handler, `MaintenanceResponder`); `Health\GetHealthHandler` neu geschrieben |
| Verdrahtung | `config/di/maintenance.php`; die Bindung an `LoggingRepairQueue` in `config/di/domains.php` ist entfernt |
| Protokoll | `repair.retry` (Erfolg als Änderung, Fehlschlag als `failed`), `repair.resolve`; der Abgleich und die Zustandsprüfung ändern nichts und protokollieren nichts |
| Oberfläche | `frontend/src/pages/repairs/` (Liste `/maintenance/repairs`, Einzelansicht `/maintenance/repairs/{id}`, `repairActions.tsx` mit Wiederholen und Abschließen), `pages/reconciliation/` (`/maintenance/reconciliation`), `pages/health/` (`/maintenance/health`); Fehlercodes als Feld über `useErrorCodeText()` aus `i18n/problemText.ts` (U-132); Texte in `frontend/src/i18n/de/maintenance.ts`, die vier `repair.*`-Codes in `de.errors.ts` (U-131) |

### Wer Einträge schreibt

Jede Stelle, die heute `RepairRequest` erzeugt. Die vier wiederholbaren Schritte tragen seit A6
`parameters` (U-118); die übrigen bleiben Handarbeit.

| Paket | Stelle | Operation / Schritt | Parameter | wiederholbar |
|---|---|---|---|---|
| A2 | `CreateAccount.php`, `CREATE` ohne eindeutige Antwort | `account.create` / `mailstore.create_mailbox_uncertain` | — | nein |
| A2 | `CreateAccount.php`, liegengebliebene Zeilen | `account.create` / `database.remove_rows` | — | nein |
| A2 | `CreateAccount.php`, Standardordner | `account.create` / `mailstore.default_folders` | `account`, `domain`, `folders` | ja |
| A2 | `CreateAccount.php`, Kompensation entfernt das Postfach nicht | `account.create` / `mailstore.delete_mailbox` | `account`, `domain` | ja |
| A2 | `DeleteAccount.php`, Postfach nach den Zeilen | `account.delete` / `mailstore.delete_mailbox` | `account`, `domain` | ja |
| A2 | `DeleteAccount.php`, Audit nach dem Löschen (MyISAM) | `account.delete` / `audit.record` | — | nein |
| A2 | `SetAccountQuota.php`, Rücksetzen scheitert | `account.quota.set` / `mailstore.restore_quota` | `account`, `domain`, `limit_kib`, `current_kib` | ja |
| A1 | `RenameDomain.php`, Rückbenennung scheitert | `domain.rename` / `mailstore.rename_back` | `account`, `domain`, `target_account`, `target_domain` | ja |
| A3 | `RowWriter.php` | `alias.create`, `alias.update`, `alias.delete`, `catchall.set`, `catchall.delete`, `forward.set`, `forward.delete`, `address.delete` / `virtual.restore` | — | nein |
| A3 | `VacationScripts.php` | `vacation.set`, `vacation.delete` / `sieve.restore` | — | nein |

Die Sicherheitsnacharbeit (Namensraumsperre, Reste einer Domain) hat keine neue Stelle
hinzugefügt; sie lehnt ab, bevor etwas hängen bleiben kann. A4 und A5 schreiben keine Einträge.

## Zu klärende Punkte

Alle sieben sind beantwortet.

1. **Laufzeit des Abgleichs.** → **U-115.** Synchron im Anfragebetrieb, nichts gespeichert. Der
   Anwendungsfall hängt nicht an HTTP und kann später auch per CLI laufen.
2. **Wie werden beide Namensräume abgefragt?** → **U-116.** Ein `LIST "" "user/%"` liefert
   gegen F5 gemessen die Wurzeln beider Schemata; zugeordnet wird nur über den gespeicherten
   Namen, nie durch Zerlegen des Postfachnamens.
3. **Verzögert gelöschte Mailboxen.** → **U-116.** Sie liegen unter `DELETED/…`, passen nicht
   auf das Muster und werden vom Adapter zusätzlich gefiltert. Der F5-Test löscht ein Postfach
   und prüft, dass es nicht erscheint.
4. **Kategorien der Abweichung.** → **U-119.** Acht: `account_without_mailbox`,
   `mailbox_without_account`, `shadow_mailbox`, `unresolvable_account`, `ambiguous_username`,
   `virtual_owner_missing`, `virtual_target_missing`, `virtual_unclassified`. Ein Punktname in
   `virtual.dest`, den es nicht als Konto gibt, wird **so gemeldet, wie er dasteht** — A1s
   Annahme über `dest` wird nicht durch Raten ergänzt.
5. **Wiederholung aus der Warteschlange.** → **U-118.** Wiederholbar sind Standardordner,
   Postfach entfernen, Quota zurücksetzen und Rückbenennen — jeweils nur mit vollständigen
   Parametern und nach einer Prüfung, ob der Zustand noch der beschriebene ist
   (`409 repair.state_changed`). Löschen und Rückbenennen prüfen unter den Sperren aus U-97.
   Alles andere schließt ein Superuser als `dismissed` mit Notiz ab. Wiederholen und Abschließen
   halten die Sperre des Eintrags; ein Schritt, dessen Ziel schon erreicht ist, schließt nur ab
   (U-125).
6. **Tiefe der Zustandsprüfung.** → **U-121.** Datenbank mit `SELECT 1`, IMAP mit echter
   Administratoranmeldung und `NOOP`, ManageSieve mit `STARTTLS` und Zertifikatsprüfung ohne
   Anmeldung — timsieved lehnt die eigene Sitzung des Administrators ab (gemessen).
7. **Darf `/health` ohne Anmeldung erreichbar sein?** → **U-121.** Ja, knapp: nur Gesamtstatus
   und Status je Komponente, `200` oder `503`, zehn Sekunden gecacht. Codes, Dauer und Zeitpunkt
   gibt es frisch unter `/maintenance/health` für Superuser. Gleichzeitige Anfragen teilen eine
   Prüfrunde; ohne Cache schlägt die Antwort geschlossen fehl (U-123).

Dazu, nicht im Paket gefragt: **U-117** (Tabelle auf eigener Verbindung, Log als Rückfall; löst
U-50 ab), **U-120** (kein Sieve-Abgleich), **U-122** (Präfix `repair` für Fehlercodes).
Aus dem Review: **U-123** (eine Prüfrunde zur Zeit), **U-124** (Abgleich: `+`-Erweiterung,
abgeschaltete Zeilen, keine Folgebefunde), **U-125** (Eintragssperre, idempotente Wiederholung).

### Nachbesserungen an der Oberfläche (U-133)

- **Ablehnungen laden neu:** Lehnt der Server Wiederholen oder Abschließen ab (etwa `409
  repair.already_resolved`), werden Liste und Eintrag neu geladen; der Dialog behält die Meldung.
- **Musterfilter:** `operation` und `step` werden gegen das `pattern` des Vertrags geprüft. Ein
  Wert, der nicht passt, wird nicht gesendet und am Feld begründet, statt eine `422` zu erzeugen.

### Paket-lokale Annahmen

- **Annahme: der Abgleich bewertet nur lokal zugestellte Ziele.** Ein Ziel mit `@` wird nur
  gemeldet, wenn seine Domain `transport = cyrus` hat und weder Konto, Alias, Liste noch
  Catchall es beantworten — jeweils nur Zeilen mit `status = 1`, und eine `+`-Erweiterung fällt
  auf die Basisadresse zurück (U-124). Was Postfix weiterreicht, kann mailadmin nicht beurteilen.
- **Annahme: ein Domainname als `virtual.username` ist kein fehlender Besitzer.** Das ist der
  Platzhalter für Aliase ohne Senderecht (U-106).
- **Annahme: die Standardsortierung der Warteschlange ist `id`, absteigend.** `recorded_at` hat
  Sekundenauflösung und der Tiebreaker ist immer aufsteigend; `id` gibt die Reihenfolge des
  Eintragens exakt wieder.
- **Annahme: ein `POST …/resolve` trägt immer einen JSON-Körper**, `{}` ohne Notiz — wie
  `refreshToken`, weil die Anfragevalidierung einen Körper ohne Typ nicht prüfen kann.

## Definition of Done

- [x] Der Abgleich findet ein absichtlich erzeugtes Konto ohne Mailbox und eine Mailbox ohne Konto
      (F5).
- [x] Er findet ein Schatten-Postfach im jeweils anderen Namensraum (F5).
- [x] Verzögert gelöschte Mailboxen erscheinen nicht als Abweichung (F5, gelöschtes Postfach).
- [x] Ein fehlgeschlagener Anlagevorgang landet in der Warteschlange und lässt sich wiederholen
      (Anwendungsfall: Ordnerfehler bei der Anlage → Eintrag → Wiederholung legt die Ordner an).
- [x] `/health` meldet Datenbank, IMAP und ManageSieve getrennt.
- [x] Reviewbefunde: eine Prüfrunde zur Zeit (U-123), Abgleich ohne Fehl- und Folgebefunde
      (U-124), Eintragssperre und idempotente Wiederholung (U-125).
- [x] Oberfläche (U-131): Warteschlange mit Status-, Vorgangs- und Schrittfilter, Wiederholen nur bei
      `retryable`, Abschließen mit optionaler Notiz, Wiederholen eines Postfach-Löschens erst nach
      Eingabe des Postfachnamens; Abgleich auf Anforderung, gruppiert nach den acht Kategorien,
      Zusammenfassung mit allen acht; Systemzustand je Komponente mit übersetztem Code und Dauer.
      Schreibende Bedienelemente nur mit `maintenance.write`; Fehler über den Katalog.

## Testfälle

| Stufe | Datei | Was |
|---|---|---|
| Anwendungsfall | `tests/Application/Maintenance/ReconcileTest.php` | je Kategorie ein Fall; sauberer Zustand ohne Befund und ohne Mailstore-Operation; gelöschtes Postfach; Schatten statt Waise; Adminzeile; **Punktname in `dest` wörtlich gemeldet**, Domain aus dem Alias; Liste, Catchall, Relay-Domain und fremde Domain ohne Befund; Darstellung mit Zusammenfassung; **kein Folgebefund für ein Ziel, das auf ein nicht zuordenbares Konto zeigt**; `+`-Erweiterung beantwortet durch die Basisadresse, fehlende Basisadresse wie geschrieben gemeldet; abgeschaltete Zeile und abgeschalteter Catchall beantworten nichts (U-124) |
| Anwendungsfall | `tests/Application/Maintenance/RetryRepairTest.php` | **DoD: Ordnerfehler bei `CreateAccount` → Eintrag mit Parametern → Wiederholung legt Ordner an, Eintrag `retried`, Protokoll**; erledigt, unbekannt, erneuter Fehlschlag zählt den Versuch, `503` bei unerreichbarem Mailstore; sieben nicht wiederholbare Fälle ohne Spur; Löschen (auch aus `DeleteAccount`), Löschen verweigert, wenn ein neues Konto den Namen trägt; Quota (auch aus `SetAccountQuota`, unbegrenzt, inzwischen geändert); Rückbenennen, wiederholte Umbenennung, beide Postfächer; Abschließen mit Notiz, Liste und Einzelabruf; **Abschluss zwischen Lesen und Handeln: kein Löschen, kein Erfolgsprotokoll** (`StaleFirstReadLedger`), beide Anwendungsfälle nehmen die Eintragssperre, `resolve()` ohne Wirkung ergibt `409` ohne Erfolgsprotokoll, kein Versuch an geschlossenem Eintrag; Quota schon zurückgesetzt und Postfach schon zurückbenannt schließen nur ab (U-125) |
| Anwendungsfall | `tests/Application/Domain/RenameDomainTest.php` | Parameter des Eintrags `mailstore.rename_back` |
| Unit | `tests/Unit/Infrastructure/Health/HealthCheckTest.php` | Prüfer werfen nie, Codes aus Mailstore- und Datenbankfehlern, fremde Ausnahme ohne Meldungstext, `degraded`, `skipped`, Cachefenster, kaputte Cachedatei; **fehlendes Verzeichnis: keine Prüfung, alles `error`**; Bericht aus der Zukunft nicht frisch; **bei gehaltener Sperre der alte Bericht ohne Prüfung**, ohne Bericht Warten und geschlossenes Fehlschlagen; keine Temporärdatei nach gescheitertem `rename` (U-123) |
| Vertrag | `tests/Contract/MaintenanceEndpointTest.php` | alle sechs Operationen durch die echte Kette und gegen den Vertrag; `403` für Domainmaster und `401` ohne Token an jeder; Filter, Sortier-Allowlist und Standardrichtung gleich Vertrag; jede `RepairErrorCode` im `ErrorCode`-Enum; jede Kategorie im Vertrag; unbekanntes Feld im Abschlusskörper |
| Vertrag | `tests/Contract/HealthEndpointTest.php` | drei Komponenten, `no-store`; ausgefallene Komponente → `503` gegen den Vertrag, nur `status` und `checks`, kein Code, kein Hostname |
| Integration | `tests/Integration/Persistence/RepairQueueIntegrationTest.php` | MariaDB mit 0040: Rundreise samt JSON und `request_id`, `NULL` ohne Parameter, Versuche, **einmaliger Abschluss**, Sortierung/Filter/Seiten, **Eintrag überlebt den Rollback der geteilten Verbindung**, Rückfall ins Log ohne Tabelle, Kürzen überlanger Werte; kein Versuch an geschlossenem Eintrag, Eintragssperre für eine zweite Verbindung sichtbar und danach frei (U-125); `SqlMaintenanceInventory` gegen das Altschema plus `lists` mit einem Abgleich |
| Oberfläche (jsdom) | `frontend/src/pages/repairs/RepairListPage.test.tsx`, `RepairDetailPage.test.tsx` | Standardanfrage `id`/`desc`, Filter aus der URL, unbekanntes Sortierfeld verworfen, Sortierwechsel; leere Liste; `500` und `404` über den Katalog mit Vorgangsnummer; Wiederholen nur bei `retryable`, keine Schreibknöpfe ohne `maintenance.write`, kein Prüfverweis ohne `audit.read`, Domainmaster sieht die Ansicht nicht; Wiederholen nach Bestätigung, **Postfach-Löschen erst nach eingetipptem Namen**, `409 repair.state_changed` im Dialog; Abschließen mit Notiz und mit `{}`, Feldfehler auf der Notiz; Zeilenklick in die Einzelansicht; `last_error` übersetzt |
| Oberfläche (jsdom) | `frontend/src/pages/reconciliation/ReconciliationPage.test.tsx`, `pages/health/HealthPage.test.tsx` | Abgleich erst auf Knopfdruck, Gruppen in Vertragsreihenfolge, Zusammenfassung mit allen acht Kategorien, sauberer Lauf, `503` ohne automatische Wiederholung; Zustand je Komponente, bekannter Code übersetzt, unbekannter wörtlich, Zeit mit Zone, erneute Prüfung; beide nicht für Domainmaster |
| Integration (F5) | `tests/Integration/TestEnvironment/ReconciliationTest.php` | in `leer.test`: Zeile ohne Postfach, Postfach ohne Zeile, Schatten im `fqun`-Schema, **Punktziel ohne Konto**, gelöschtes Postfach nicht gemeldet, nichts verändert; echte Prüfer grün; **IMAP-Port zu → `error`, Rest `ok`, kein Absturz** |
