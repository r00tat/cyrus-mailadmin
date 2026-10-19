<!-- markdownlint-disable MD013 MD024 MD060 -->

# F3 — Persistenz und Migrator

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 10 |
| **Status** | fertig |

---

## Ziel

Datenbankzugriff über PDO mit Prepared Statements, eine Repository-Basis und ein eigener
Migrator, der die `mailadmin_`-Tabellen anlegt, ohne den webcyradm-Bestand zu verändern.

## Abgrenzung

- Keine fachlichen Repositories — die gehören zu den A-Paketen.
- Keine Änderung an Bestandstabellen oder Views. **Eskalationsfall.**

## Abhängigkeiten und geteilte Dateien

`migrations/` wird von mehreren Paketen erweitert (S2 Token-Tabellen, S4 Audit, A6
Reparaturwarteschlange, W2 Spracheinstellung). Die Nummerierung muss kollisionsfrei bleiben.

## Vorgaben

- `PDO::ATTR_ERRMODE = EXCEPTION`, `ATTR_EMULATE_PREPARES = false`.
- Migrationen rein additiv. Kein `DROP`, kein `RENAME`, keine Typverengung (E-1).
- Neue Tabellen mit Präfix `mailadmin_`.
- Ausführung über `bin/migrate` unter Datenbanksperre; die Web-Anwendung prüft nur die Version
  und verweigert bei Rückstand den Dienst.
- Kein ORM (Architektur 3.2).

---

## Was entstanden ist

### Verbindung

| Datei | Zweck |
|---|---|
| `src/Infrastructure/Persistence/DatabaseConfiguration.php` | DSN, Benutzer, Passwort, Zeitüberschreitung. Das Passwort verlässt das Objekt nur über `password()`; für Meldungen gibt es ausschließlich `redactedDsn()`. |
| `src/Infrastructure/Persistence/ConnectionFactory.php` | Öffnet PDO mit den vier Festlegungen aus Architektur 10.1 und setzt `SET time_zone = '+00:00'` (U-14). |
| `src/Infrastructure/Persistence/ConnectionSource.php`, `OpenConnection.php` | Naht, damit die Repository-Basis ohne MySQL-Server geprüft werden kann. |
| `src/Infrastructure/Persistence/Connection.php` | Die einzige Tür zur Datenbank: `select`, `selectOne`, `selectValue`, `execute`, `transactional`. Kein `query()`, kein `exec()` — jeder Aufruf bereitet vor. |

Die Verbindung wird **erst beim ersten Zugriff geöffnet**. Eine Anfrage, die keine Tabelle
berührt, öffnet keinen Socket.

### Repository-Basis

| Datei | Zweck |
|---|---|
| `Repository.php` | Basis für A1–A6: `fetchPage`, `fetchAll`, `restrictToDomains`, `transactional`. `sortable()` ist **abstrakt**. |
| `ListQuery.php` | Der feste Teil einer Abfrage: `SELECT`, `FROM`, feste Prädikate, `GROUP BY`. |
| `Page.php` | Ergebnisseite mit `meta()` in genau der Form, die der Vertrag verlangt. |
| `Criteria/ListCriteria.php` | `page`, `per_page`, `sort`, `order`, Filter. Vorgabe 50, Höchstwert 200 (U-20). |
| `Criteria/SortAllowlist.php`, `OrderBy.php`, `ColumnName.php` | Die Sortier-Allowlist. `OrderBy` hat einen **privaten Konstruktor** und genau eine Fabrik, die eine Allowlist verlangt. |
| `Criteria/FilterAllowlist.php`, `FilterField.php`, `FilterComparison.php`, `Filters.php` | Dasselbe für `WHERE`. |
| `Criteria/CriteriaError.php` | Stabiler Code `request.validation_failed`. |

### Migrator

| Datei | Zweck |
|---|---|
| `Migration/SqlStatements.php` | Zerlegt eine `.sql`-Datei in Statements; Zeichenketten, Bezeichner und Kommentare werden erkannt, Kommentare entfernt. |
| `Migration/Migration.php`, `MigrationLoader.php` | `NNNN_beschreibung.sql`, nach Nummer geordnet; doppelte Nummer ist ein Fehler. |
| `Migration/AdditiveOnlyRule.php` | **Die Regel als Prüfung.** Geschlossene Menge erlaubter Statements; alles andere wird abgelehnt. |
| `Migration/SchemaVersionTable.php`, `AppliedMigration.php`, `SchemaState.php` | Lesen und Schreiben von `mailadmin_schema_version`, Vergleich Bestand ↔ Build. |
| `Migration/MigrationLock.php` | `GET_LOCK`, auf den Datenbanknamen bezogen. |
| `Migration/Migrator.php` | Der Ablauf: Sperre nehmen, Zustand neu lesen, Regel prüfen, anwenden, protokollieren. |
| `Migration/MigrationError.php` | Stabile Codes und Meldungen, die sagen, was zu tun ist. |
| `migrations/0001_schema_version.sql` | `mailadmin_schema_version` |
| `migrations/0002_setting.sql` | `mailadmin_setting` |

### Prüfung im laufenden Betrieb

| Datei | Zweck |
|---|---|
| `Guard/SchemaGuard.php` | Vergleicht Bestand mit dem Build. Migriert nur, wenn `MAILADMIN_AUTO_MIGRATE` gesetzt ist. |
| `Guard/SchemaOutdated.php` | `migration.schema_outdated` |
| `Guard/SchemaVersionMiddleware.php` | `503` mit `Retry-After: 30`. `/health` bleibt erreichbar. |

### Befehle

| Befehl | Zweck |
|---|---|
| `php bin/migrate.php` | Wendet alles Ausstehende an, unter der Sperre. |
| `php bin/migrate.php status` | Zeigt den Zustand, ändert nichts. Beendet sich immer mit 0. |
| `php bin/migrate.php --dry-run` | Listet, was liefe, prüft die Regeln, **beendet sich mit 1**, wenn etwas aussteht — für eine Deployment-Prüfung. |
| `php bin/db-check.php` | Liest Zeichensatz, Kollation und **Speicher-Engine** der Bestandstabellen und meldet den Migrationsstand. |

Exit-Codes von `bin/migrate.php`: `0` erledigt, `1` Konfigurations- oder Datenbankfehler,
`2` gesperrt (nichts geändert), `3` Migration von der Regel abgelehnt, `4` Migration gescheitert.

---

## Antworten auf die zu klärenden Punkte

### 1. Zeichensatz und Kollation der neuen Tabellen

**Entschieden:** `utf8mb4` / `utf8mb4_unicode_ci` / `InnoDB` für jede `mailadmin_`-Tabelle,
ausdrücklich in der DDL, nicht aus der Serververgabe.

Die im Punkt befürchtete Verknüpfungsfalle wird nicht durch eine passende Kollation gelöst,
sondern **durch den Verzicht auf die Verknüpfung**: keine Abfrage verbindet eine
`mailadmin_`-Tabelle per `JOIN` mit einer Bestandstabelle. Bei 114 Domains und 306 Konten sind
zwei Abfragen und eine Zuordnung in PHP billiger als eine Abhängigkeit von einer Kollation, die
Salt und webcyradm gemeinsam gehört. **Das ist eine Konvention für A1–A6.**

Der Punkt verlangt außerdem, den Ist-Zustand am Zielsystem zu prüfen. Ein Hinweis in einem
Dokument ist keine Prüfung, deshalb gibt es `bin/db-check.php`. Es meldet zusätzlich die
**Speicher-Engine** — siehe „Was dabei aufgefallen ist".

### 2. Format der Migrationen

**Entschieden:** reines SQL, wie der Punkt vorschlägt. Rein additive Änderungen brauchen keine
Logik, und eine SQL-Datei ist im Codereview vollständig lesbar. PHP-Migrationen würden Daten
umformen können — genau das, was E-1 nicht will.

Preis: der Migrator braucht einen Statement-Zerleger (`SqlStatements`), weil `explode(';', …)`
an einem `COMMENT 'a; b'` scheitert. Rund 120 Zeilen, mit Tests.

### 3. Sperrmechanismus

**Entschieden:** `GET_LOCK()`, wie vorgeschlagen. Der Name ist auf den Datenbanknamen bezogen
(`mailadmin_migration:<db>`), weil `GET_LOCK` serverweit gilt und zwei Installationen auf einer
MariaDB einander sonst grundlos blockieren.

**Verhalten bei Zeitüberschreitung:** nach 10 Sekunden (`MAILADMIN_MIGRATION_LOCK_TIMEOUT`)
Abbruch mit Exit-Code 2 und **ohne jede Änderung**. Länger zu warten hilft nicht: entweder ist
der andere Lauf gleich fertig — dann findet der nächste Aufruf nichts mehr vor — oder er hängt,
und dann darf ein zweiter sich nicht dazustellen.

### 4. Verhalten bei Versionsrückstand

**Entschieden:** `503` mit `Retry-After: 30` und `migration.schema_outdated`.

`503`, nicht `500`: der Dienst ist nicht kaputt, er ist nicht fertig ausgerollt. Es ist dieselbe
Antwort, die F2 für ein veraltetes Build gibt (`migration.build_stale`) — dieselbe Art Fehler
sollte nicht am Statuscode unterschieden werden müssen.

**`/health` antwortet weiter.** Der Endpunkt, dessen Aufgabe es ist zu sagen, was nicht stimmt,
darf nicht der sein, der bei einem Problem schweigt — sonst meldet eine Deployment-Prüfung
„Verbindung abgelehnt" statt „Migrator laufen lassen". Die Middleware lässt Pfade durch, die auf
`/health` enden; welche Details der Health-Endpunkt über den Schemastand ausgibt, gehört A6.

### 5. Rückwärtsmigration

**Entschieden: nein**, wie der Punkt vorschlägt. Vorwärtsgerichtet; der Rückweg ist der Dump.
Eine `down`-Migration auf einem Schema, das drei fremde Systeme lesen, müsste selbst additiv
sein, um ungefährlich zu sein — dann ist sie keine Rücknahme mehr.

### 6. `MAILADMIN_AUTO_MIGRATE`

**Bestätigt: standardmäßig aus.**

Ist er an, ruft `SchemaGuard` denselben `Migrator` mit derselben `GET_LOCK`-Sperre auf, die die
Kommandozeile nimmt. Zwei gleichzeitig eintreffende Anfragen erzeugen damit eine Migration und
eine kurze Wartezeit, nicht zwei halbe. Kann die Sperre nicht genommen werden, wird die Anfrage
abgewiesen, als wäre der Schalter aus: ein anderer Prozess arbeitet, und diese Anfrage darf nicht
gegen ein halb migriertes Schema laufen. Nach der Migration wird der Zustand **erneut gelesen**,
nicht angenommen.

Ob die Variable überhaupt in der ausgelieferten `.env.example` erscheint, bleibt O-4.

---

## Weitere Festlegungen (nicht im Paketdokument gefragt)

| # | Festlegung | Grund |
|---|---|---|
| a | **Keine Migration ist in eine Transaktion gefasst.** | MySQL/MariaDB committen vor und nach jedem DDL-Statement implizit. `BEGIN … CREATE TABLE … ROLLBACK` nimmt die Tabelle nicht zurück, es sieht nur so aus. Stattdessen: Idempotenz erzwingen. |
| b | **`IF NOT EXISTS` ist Pflicht** bei `CREATE TABLE`, `CREATE INDEX` und jedem `ALTER TABLE … ADD`; `INSERT` muss `IGNORE` oder `ON DUPLICATE KEY UPDATE` tragen. | Folgt aus (a): eine bei Statement 3 von 5 gescheiterte Migration wird nicht protokolliert und beim nächsten Lauf von vorn wiederholt. **Annahme: MariaDB.** `ADD COLUMN IF NOT EXISTS` ist MariaDB-Syntax; MySQL 8 kennt sie nicht. |
| c | **Die Prüfung arbeitet nach Erlaubnisliste, nicht nach Verbotsliste.** | Eine Verbotsliste müsste `CREATE OR REPLACE VIEW`, `CONVERT TO CHARACTER SET`, `DELIMITER` und jede künftige Dialekterweiterung vorhersehen. Eine Erlaubnisliste muss nichts vorhersehen. |
| d | Eine bereits angewandte, danach geänderte Migration wird **gemeldet, nie erneut ausgeführt**. | Erneut auszuführen ist genau das, was die additive Regel verbietet. `bin/migrate.php status` zeigt die Abweichung; was zu tun ist, entscheidet ein Mensch. |
| e | Die Versionstabelle führt **eine Zeile je Migration**, keinen Höchststand. | U-6 lässt Lücken zwischen den Bereichen. Eine spät gemergte 0005 muss noch laufen, auch wenn 0010 schon lief. |
| f | `ListCriteria` liegt in `Infrastructure/Persistence/Criteria/`, nicht in `Application/`. | Dateihoheit: F3 besitzt `Infrastructure/Persistence/**`. Fachlich wäre `Application/` vertretbar; ein Verschieben ist eine Namensraumänderung, sonst nichts. |
| g | Jede Sortier-Allowlist hat einen **Tiebreaker**, der an jede Sortierung angehängt wird. | Ohne ihn ist die Reihenfolge gleicher Werte dem Server überlassen: eine Zeile kann auf Seite 1 und noch einmal auf Seite 2 erscheinen, ohne dass sich etwas geändert hat. |
| h | Allowlist-Werte müssen `spalte` oder `alias.spalte` sein — **auch die von uns geschriebenen**. | Damit die Behauptung „das zusammengesetzte Fragment enthält nur Bezeichner" nicht vom Codereview abhängt. Wer nach einem Ausdruck sortieren will, wählt ihn unter einem Alias aus. |
| i | Ein leerer Domain-Geltungsbereich ergibt `1 = 0`, nicht „kein Prädikat". | Ein Administrator ohne Domains muss nichts sehen; „kein Prädikat" zeigte ihm alles. |
| j | `sql_mode` wird **nicht** gesetzt. | Die Sitzung teilt sich den Server mit Postfix und `pam_mysql`. `STRICT_ALL_TABLES` würde Schreibvorgänge in Bestandstabellen verändern — eine Nebenwirkung ohne Auftrag. |

---

## Was dabei aufgefallen ist

**Die Bestandstabellen sind MyISAM.** `webcyradm/scripts/create_mysql.sql` legt jede von ihnen
mit `TYPE=MyISAM` an. Eine Transaktion über eine MyISAM-Tabelle committet nichts und nimmt nichts
zurück — **stillschweigend**. Architektur 8.1 baut darauf, dass der Datenbankschritt atomar ist
(„DB-Transaktion: `INSERT accountuser` + `INSERT virtual`, commit"). Ist die Engine im
Zielsystem noch MyISAM, gilt diese Annahme nicht.

Die Anwendung kann das nicht beheben: die Engine zu wechseln wäre ein `ALTER TABLE … ENGINE`,
also genau der Eingriff, den E-1 verbietet. `bin/db-check.php` meldet es deshalb und benennt die
Folge. **Die Entscheidung gehört dem Betrieb** und sollte vor der Inbetriebnahme fallen — sie ist
Voraussetzung dafür, dass A2 und A3 die Reihenfolgen aus 8.1 überhaupt einhalten können.

---

## Definition of Done

| Forderung | Stand |
|---|---|
| `bin/migrate` legt `mailadmin_schema_version` an und ist mehrfach ausführbar | erfüllt — `MigratorIntegrationTest::testRunningItTwiceChangesNothing` und `…SurvivesBeingRunAgainstItsOwnResult` |
| Zwei gleichzeitige Läufe führen nicht zu doppelter Ausführung | erfüllt — `…testASecondRunCannotStartWhileTheFirstHoldsTheLock` |
| Die Anwendung verweigert bei Versionsrückstand den Dienst | Middleware fertig und geprüft; **noch nicht in der Kette** — siehe unten |
| Kein Statement im Code setzt SQL aus Eingaben zusammen | erfüllt — `Connection` bietet keinen Weg dazu, `OrderBy`/`Filters` haben private Konstruktoren |

**Offen und nicht in F3s Hoheit:** `SchemaVersionMiddleware` hängt noch nicht in der
Middleware-Kette. Die steht in `config/di/core.php`, das F1/F2 gehört; nötig ist dort ein
zusätzliches Argument zwischen `ErrorHandlerMiddleware` und `JsonBodyMiddleware`. Ebenso fehlt
`migration.schema_outdated` im `ErrorCode`-`enum` in `openapi/components/common.yaml`. Beides ist
an F2 gemeldet.

## Testfälle

| Fall | Wo |
|---|---|
| **Unit:** Migrator — unmigrierter Bestand, Reihenfolge, Lücken, späte Migration, geänderte Datei | `tests/Unit/Infrastructure/Persistence/Migration/SchemaStateTest.php`, `MigrationLoaderTest.php` |
| **Unit:** additive Regel — 30 abgelehnte und 9 akzeptierte Statements, dazu die ausgelieferten Migrationen gegen ihre eigene Regel | `…/Migration/AdditiveOnlyRuleTest.php` |
| **Unit:** Statement-Zerleger | `…/Migration/SqlStatementsTest.php` |
| **Unit:** Sortier- und Filter-Allowlist, sechs Injektionsversuche | `…/Criteria/SortAllowlistTest.php`, `FilterAllowlistTest.php` |
| **Unit:** Repository-Basis über SQLite — Paginierung, stabile Reihenfolge, Geltungsbereich, Transaktion | `…/RepositoryTest.php` mit `Fixtures/WidgetRepository.php` |
| **Unit:** Verbindung — Bindung, Transaktionsschachtelung, Fehlerübersetzung | `…/ConnectionTest.php` |
| **Unit:** Guard und Middleware | `…/Guard/SchemaGuardTest.php`, `SchemaVersionMiddlewareTest.php` |
| **Integration:** Migration gegen ein Schema im **webcyradm-Ausgangszustand** | `tests/Integration/Persistence/MigratorIntegrationTest.php` — die Bestandstabellen werden aus `webcyradm/scripts/create_mysql.sql` nachgebaut (MyISAM, latin1) und mit synthetischen Zeilen gefüllt |
| **Integration:** gleichzeitige Läufe unter Sperre | ebenda |
| **Integration:** Verbindungseinstellungen gegen einen echten Server | `tests/Integration/Persistence/ConnectionIntegrationTest.php` |

Die Integrationstests brauchen `MAILADMIN_TEST_DB_DSN` (dazu `MAILADMIN_TEST_DB_USER`,
`MAILADMIN_TEST_DB_PASSWORD`). **Ohne diese Variablen melden sie sich als übersprungen, nie als
bestanden.** Zusätzlich verweigern sie die Arbeit, wenn die Zieldatenbank mehr als fünf Zeilen in
`accountuser`, `domain` oder `adminuser` hat — sie legen Tabellen an und löschen sie wieder, und
das darf nie eine echte Installation treffen.

---

## Konventionen für A1–A6

### Ein Repository anlegen

```php
final class DomainRepository extends Repository
{
    // Pflicht. Ohne Allowlist kein Listing.
    protected function sortable(): SortAllowlist
    {
        return new SortAllowlist(
            ['name' => 'd.domain_name', 'max_accounts' => 'd.maxaccounts'],
            default: 'name',
            tiebreaker: 'name',   // eindeutig — sonst wackelt die Paginierung
        );
    }

    protected function filterable(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('q', 'd.domain_name', FilterComparison::Contains),
        );
    }

    /** @return Page<Domain> */
    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        $query = new ListQuery(
            select: 'd.domain_name, d.prefix, d.maxaccounts, d.quota, d.domainquota, d.folders',
            from: '`domain` d',
        );

        if ($actor->requiresDomainScope()) {
            $query = $this->restrictToDomains($query, $actor->visibleDomains(), 'd.domain_name');
        }

        return $this->fetchPage($query, $criteria, $this->toDomain(...));
    }

    /** @param array<string, mixed> $row */
    private function toDomain(array $row): Domain { /* Altschema -> Fachmodell */ }
}
```

Die Schlüssel der `SortAllowlist` **sind** das `sort`-`enum` der Ressource im Vertrag. Ein
Vertragstest im eigenen Paket sollte `$allowlist->keys()` gegen das `enum` stellen; laufen die
beiden auseinander, ist das ein `request.validation_failed` zur Laufzeit statt eines Buildfehlers.

### Paginierung

- `ListCriteria::fromQuery($request->getQueryParams())` im Handler, oder ein `new ListCriteria(…)`
  in einem Anwendungsfall.
- `fetchPage()` liefert eine `Page`; `$page->meta()` hat genau die Form von `PaginationMeta`.
- Vorgabe 50, Höchstwert 200 (U-20), erzwungen im Konstruktor **und** im Schema.
- `fetchPage` führt zwei Abfragen aus: Zählung und Zeilen. Ist die Zählung 0, entfällt die zweite.

### Sortieren und Filtern

- Ein Feldname, der nicht in der Allowlist steht, ist ein **Fehler** — kein stilles Ignorieren
  und kein Rückfall auf die Vorgabe.
- Allowlist-Werte sind `spalte` oder `alias.spalte`. Für einen Ausdruck: im `SELECT` unter Alias
  auswählen und den Alias in die Allowlist schreiben.
- Feste Prädikate (Geltungsbereich, Statusflag) gehören in die `ListQuery`, nicht in die Filter —
  sie kommen nicht aus der Anfrage.
- Eigene Bindungsnamen dürfen nicht heißen wie die der Basis (`ma_f*`, `ma_limit`, `ma_offset`);
  `fetchPage` bricht bei einer Kollision ab, statt einen Wert zu überschreiben.

### Transaktionen

```php
// Konto anlegen — Mailstore zuerst (Architektur 8.1)
$this->mailstore->createMailbox($identity);

try {
    $this->connection->transactional(function () use ($account): void {
        $this->accounts->insert($account);
        $this->addresses->insertPrimary($account);
    });
} catch (Throwable $error) {
    $this->mailstore->deleteMailbox($identity);   // Kompensation, Scheitern ist harmlos
    throw $error;
}
```

- `transactional()` ist der einzige Weg. `beginTransaction()` auf dem PDO-Handle umgeht die
  Tiefenzählung und wird von `Connection` bemerkt.
- Der Aufruf ist **wiedereintrittsfähig**: zwei Repositories in einem Anwendungsfall ergeben eine
  Transaktion, der äußerste Aufruf entscheidet.
- Wirft ein innerer Block und wird die Ausnahme **gefangen**, ist die Transaktion trotzdem
  rollback-only. Der äußere Aufruf rollt zurück und meldet `migration.transaction_misuse`, statt
  die Hälfte zu committen.
- **Kein Mailstore-Aufruf innerhalb der Transaktion.** Die Reihenfolge darum herum ist der Schutz,
  nicht die Transaktion.
- **Vorbehalt:** über eine MyISAM-Tabelle tut eine Transaktion nichts. Siehe „Was dabei
  aufgefallen ist".

### Verknüpfungen zum Bestand

Keine `JOIN`s zwischen `mailadmin_`-Tabellen und Bestandstabellen. Zwei Abfragen, Zuordnung in
PHP. Grund: unterschiedliche Kollationen, und der Bestand gehört uns nicht.

### Fehler

`PersistenceError::errorCode()` trägt einen stabilen Code. Zum Client geht der Code, nie die
Meldung. Werte werden nie Teil einer Fehlermeldung — U-28 kann nicht in eine Zeichenkette
hineinsehen, und eine davon könnte ein Passwort-Hash sein.

---

## Umgebungsvariablen

| Variable | Vorgabe | Bedeutung |
|---|---|---|
| `MAILADMIN_DB_DSN` | — (Pflicht) | PDO-DSN, `mysql:`. `charset=utf8mb4` wird ergänzt oder korrigiert. |
| `MAILADMIN_DB_USER` | — (Pflicht) | Eigener Benutzer, **nicht** der von Postfix/`pam_mysql` (Architektur 10.1). |
| `MAILADMIN_DB_PASSWORD` | `''` | |
| `MAILADMIN_DB_TIMEOUT` | `5` | Sekunden bis zum Verbindungsabbruch. |
| `MAILADMIN_AUTO_MIGRATE` | `0` | Migriert beim ersten Zugriff unter Sperre. |
| `MAILADMIN_MIGRATION_LOCK_TIMEOUT` | `10` | Sekunden Wartezeit auf `GET_LOCK`. |
| `MAILADMIN_TEST_DB_DSN` / `_USER` / `_PASSWORD` | — | Nur für die Integrationstests. Ohne sie überspringen sie sich. |
