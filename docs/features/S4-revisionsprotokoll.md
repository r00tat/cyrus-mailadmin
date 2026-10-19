<!-- markdownlint-disable MD013 MD024 MD060 -->

# S4 — Revisionsprotokoll

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F3, S3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 13 |
| **Status** | **umgesetzt** — Tabelle, Schreiber, Maskierung, Leseendpunkt, die Anbindung von S2 und die Oberfläche (U-131) stehen |

---

## Ziel

Jede zustandsändernde Operation wird nachvollziehbar: wer, wann, von wo, was vorher, was
nachher. Schließt die Lücke, dass im Altsystem Änderungen nicht zuordenbar waren.

## Abgrenzung

- Kein Anwendungslog — das ist F1.
- Die webcyradm-Tabelle `log` bleibt unangetastet; sie gehört `pam_mysql`.

## Abhängigkeiten und geteilte Dateien

Jedes A-Paket schreibt Einträge. Das Format wird hier festgelegt und ist danach verbindlich.

---

## Die Schnittstelle für die anderen Pakete

**Das ist der Abschnitt, den A1 bis A6 und M3 brauchen.** Alles andere hier ist Begründung.

### Ein Konstruktorargument, ein Aufruf

```php
final readonly class CreateDomain
{
    public function __construct(
        private DomainRepository $domains,
        private AuditWriter $audit,      // Mailadmin\Application\Audit\AuditWriter
    ) {
    }

    public function handle(ActorContext $actor, …): Domain
    {
        return $this->domains->transactional(function () use ($actor, …): Domain {
            $domain = …;

            $this->audit->record(AuditEntry::creation(
                $actor,                  // der ActorContext des Anwendungsfalls
                'domain.create',         // <objekt>.<verb>, wie x-permission
                'domain',                // Objekttyp
                $name->toString(),       // Objektbezeichner
                after: $representation,  // die API-Darstellung, nicht die DB-Zeile
                domain: $name,           // DomainName oder String, optional
            ));

            return $domain;
        });
    }
}
```

Der Container liefert `AuditWriter` (siehe `config/di/audit.php`); Autowiring erledigt den Rest.

### Die fünf Konstruktoren

| Aufruf | Ergebnis | `before` | `after` |
|---|---|---|---|
| `AuditEntry::creation($actor, …, after: [...])` | `succeeded` | `null` | gesetzt |
| `AuditEntry::change($actor, …, before: [...], after: [...])` | `succeeded` | gesetzt | gesetzt |
| `AuditEntry::removal($actor, …, before: [...])` | `succeeded` | gesetzt | `null` |
| `AuditEntry::refusal($actor, …, reason: '…')` | `denied` | `null` | `null` |
| `AuditEntry::failure($actor, …, reason: '…', before: [...])` | `failed` | optional | `null` |

`$actor` ist ein `ActorContext` oder — wo es keinen gibt — ein `AuditActor::named('…')` bzw.
`AuditActor::system()`.

### Stapelvorgänge

`record()` liefert eine `AuditEntryId`. Ein Vorgang, der viele Objekte berührt, schreibt einen
Eintrag für den Vorgang und hängt die Einzeleinträge daran:

```php
$operation = $this->audit->record(AuditEntry::change($actor, 'domain.rename', 'domain', $old, …));

foreach ($accounts as $account) {
    $this->audit->record(
        AuditEntry::change($actor, 'account.rename', 'account', $account->id())->partOf($operation),
    );
}
```

### Wann aufrufen (U-63)

| Fall | Wo | Warum |
|---|---|---|
| Erfolg | **innerhalb** der Transaktion der Änderung | beides oder nichts |
| Ablehnung, Fehlschlag | **nach** dem Zurückrollen | sonst wird der Eintrag mitverworfen |
| um den Mailstore herum | gar nicht | eine Transaktion reicht nicht bis Cyrus; hängengebliebenes gehört in `mailadmin_repair_queue` (A6) |

Ein fehlgeschlagener Schreibvorgang wird **nicht geschluckt** — die Änderung fällt mit ihm.

### Was **nicht** aufgerufen werden muss

- **Eine `403`-Ablehnung.** `Http\Middleware\AuditMiddleware` schreibt sie aus der Antwort; ein
  Aufruf im Anwendungsfall erzeugte einen zweiten Eintrag für denselben Vorgang.
- **Maskierung.** `Redactor` läuft im Schreiber. Wer eine Darstellung mit `password` übergibt,
  bekommt einen Platzhalter in die Tabelle, nicht das Passwort — ob er daran gedacht hat oder
  nicht.
- **IP und Request-Id.** Kommen aus `AuditRequestContext`, den die Middleware füllt.
- **Lesezugriffe.** Werden nicht protokolliert.

### Testen

```php
$audit = new InMemoryAuditTrail();          // Mailadmin\Infrastructure\Persistence
$useCase = new CreateDomain($repository, $audit);

$useCase->handle($actor, …);

self::assertSame('domain.create', $audit->last()?->action);
self::assertArrayNotHasKey('password_hash', (array) $audit->last()?->after);
```

Die Attrappe führt denselben `Redactor` aus wie der SQL-Schreiber und erfüllt zusätzlich
`AuditReader`, so dass Schreiben und Zurücklesen in einem Test möglich sind.

---

## Vorgaben

- Tabelle `mailadmin_audit`: Akteur, Zeitpunkt, IP, Request-Id, Aktion, Objekttyp,
  Objektbezeichner, Vorher- und Nachher-Zustand als JSON.
- **Geheimnisse werden maskiert**, nicht gekürzt: Passwortfelder erscheinen als Platzhalter,
  Hashes gar nicht.
- Lesezugriffe werden nicht protokolliert — Anmeldungen und Anmeldeversuche schon.

## Artefakte

| Datei | Inhalt |
|---|---|
| `migrations/0020_audit.sql` | `mailadmin_audit` mit Indizes auf Akteur, Zeitpunkt, Objekt, Domain, Vorgang und Request-Id |
| `src/Application/Audit/AuditWriter.php` | der Port, eine Methode |
| `src/Application/Audit/AuditEntry.php` | was aufgezeichnet wird, mit fünf benannten Konstruktoren |
| `src/Application/Audit/AuditAction.php`, `AuditActor.php`, `AuditEntryId.php`, `AuditOutcome.php` | die Wertobjekte darin |
| `src/Application/Audit/Redactor.php` | die Maskierung (U-62) |
| `src/Application/Audit/AuditRequestContext.php` | IP und Request-Id, anfragegebunden (U-64) |
| `src/Application/Audit/AuditReader.php`, `AuditRecord.php`, `ListAuditTrail.php` | der Lesepfad |
| `src/Infrastructure/Persistence/SqlAuditWriter.php` | ein vorbereitetes `INSERT` |
| `src/Infrastructure/Persistence/AuditRepository.php` | der Leser, mit Sortier- und Filter-Allowlist, gescoped |
| `src/Infrastructure/Persistence/InMemoryAuditTrail.php` | die Attrappe für alle anderen Pakete |
| `src/Http/Middleware/AuditMiddleware.php` | bindet den Kontext, zeichnet Ablehnungen auf |
| `src/Http/Handler/Audit/ListAuditEntriesHandler.php` | `GET /audit` |
| `openapi/paths/audit.yaml`, `openapi/components/audit.yaml` | der Vertrag |
| `config/di/audit.php` | die Verdrahtung |
| `src/Application/Auth/SessionAudit.php` | die Einträge der Sitzungsendpunkte (S2) |
| `frontend/src/pages/audit/` | die Oberfläche (`/audit`): Liste mit allen zehn Filtern aus U-65, Eintrag im Dialog (`AuditEntryDialog.tsx`), Zeitumrechnung (`auditTime.ts`); Texte in `frontend/src/i18n/de/audit.ts` (U-131) |

### Angefasste fremde Dateien

- `src/Http/RouteMiddleware.php` — ein Konstruktorargument, an der Stelle, die das Klassendokument
  dafür vorsieht. Die Reihenfolge ist Authentifizierung → **Audit** → Autorisierung und nicht
  wählbar: die Middleware braucht das Akteur-Attribut, das es nur *innerhalb* der
  Authentifizierung gibt, und sie muss die `403` sehen, die es nur *außerhalb* der Autorisierung
  gibt.
- `src/Application/Auth/{SignIn,SignOut,RefreshSession}.php` — je ein Konstruktorargument und ein
  Aufruf. Die Aufrufstellen sind die, die [06-uebergabe.md](../06-uebergabe.md), Abschnitt 4,
  benennt.
- Die zugehörigen Tests, `tests/Integration/Http/AuthorizationChainTest.php` (Kette plus drei
  neue Fälle) und die beiden Migrationslisten in
  `tests/Unit/.../MigrationLoaderTest.php` und `tests/Integration/.../MigratorIntegrationTest.php`.

`openapi/components/common.yaml` wurde **nicht** angefasst: der Endpunkt kommt mit den
Querschnittscodes aus, und U-7 kennt kein Präfix `audit`.

---

## Zu klärende Punkte

Alle sechs sind entschieden; die Nummern verweisen auf
[05-umsetzungsentscheidungen.md](../05-umsetzungsentscheidungen.md).

1. **Aufbewahrungsdauer** — unbegrenzt, kein Aufräumlauf (**U-61**).
2. **„Vorher" bei Neuanlage, „nachher" bei Löschung** — jeweils `NULL`, nicht `{}` (**U-61**).
3. **Größenbegrenzung / Stapelvorgänge** — ein Vorgangseintrag plus Einzeleinträge über
   `parent_id` (**U-61**).
4. **Transaktion** — nach dem Ergebnis, in dessen Transaktion; Ablehnungen danach (**U-63**).
5. **Fehlgeschlagene Operationen** — ja, über ein Ergebnisfeld mit drei Werten (**U-61**).
6. **Sichtbarkeit für Domainmaster** — nein, `audit.read` bleibt beim Superuser (**U-43**). Die
   Domain wird trotzdem mitgeschrieben und die Abfrage ist gescoped, damit eine Erweiterung
   später möglich bleibt (**U-65**).

### Nachbesserungen an der Oberfläche (U-133)

- **Musterfilter:** `action` und `object_type` werden gegen das `pattern` des Vertrags geprüft.
  Ein Wert wie `Account.Create` bleibt im Feld stehen, wird aber nicht gesendet und am Feld
  begründet; ein Neuladen der Adresse ergibt keine `422` mehr.

### Annahmen, die dieses Paket getroffen hat

- **Ein erfolgreicher `POST /auth/refresh` wird nicht protokolliert**, ein *wiederverwendetes*
  Erneuerungstoken schon. Begründung in `SessionAudit`: eine Erneuerung setzt eine Sitzung fort,
  die bei der Anmeldung bereits festgehalten wurde, und ein Eintrag je Administrator und Stunde
  würde die Einträge zudecken, auf die es ankommt. Wer das anders will, ändert eine Zeile in
  `RefreshSession`.
- **Eine abgelehnte *Lese*anfrage wird protokolliert**, obwohl Lesezugriffe es nicht werden.
  Aufgezeichnet wird der Versuch, nicht die Daten. Ablehnungen sind selten, und eine Häufung ist
  genau das, was gefunden werden soll.
- **Ein `404` für ein fremdes Objekt wird nicht protokolliert.** U-44 macht es ununterscheidbar
  von einem Objekt, das es nie gab; ein Eintrag, der etwas anderes sagt, öffnete im Protokoll das
  Leck, das die Antwort schließt.

---

## Definition of Done

| Forderung | Wo belegt |
|---|---|
| Jede schreibende Operation erzeugt genau einen Haupteintrag | Schnittstelle oben; für S2 in `SessionAuditTest`, für Ablehnungen in `AuthorizationChainTest::testAPermittedRequestLeavesNoRefusalBehind` |
| Kein Eintrag enthält ein Klartextpasswort oder einen Hash — durch Test belegt | `RedactorTest` (17 Fälle), `AuditTrailIntegrationTest::testTheStoredRowContainsNeitherThePasswordNorTheHash` gegen die **gespeicherte Zeile** |
| Der Leseendpunkt ist gescoped und paginiert | `AuditRepository::list()` über `scopedTo()`; `ListAuditTrailTest`, `AuditTrailIntegrationTest::testADomainMasterSeesOnlyTheirOwnDomains` |
| Ein Eintrag lässt sich über die Request-Id mit dem Anwendungslog verbinden | Spalte `request_id`, Filter `?request_id=`, `AuditEndpointTest::testTheRefusalIsRecordedWithTheRequestIdThatProducedIt` |
| Oberfläche: Liste, Filter, Zeiten mit Zone, Eintrag mit Vorher/Nachher | `frontend/src/pages/audit/AuditListPage.test.tsx` (jsdom, U-75): Standardanfrage `recorded_at`/`desc`, Zeit in der Zone des Browsers samt Kürzel und Hinweis, Filter aus der URL, ungültige `part_of`/`until`/`outcome` weggelassen, Ergebnis-, Text- und Zeitfilter (lokal eingegeben, UTC gesendet), Zurücksetzen, Eintrag mit maskiertem Vorher/Nachher, Eingrenzen nach Anfrage und Objekt ersetzt die Filter, Ablehnung ohne Vorher/Nachher, leere Liste, `422` über den Katalog, nicht für Domainmaster. Der Verweis aus der Reparaturwarteschlange (`?request_id=`) in `RepairDetailPage.test.tsx` |

## Testfälle

- **Unit:** Maskierung — ein Objekt mit Passwortfeld enthält danach keinen Klartext mehr.
  → `tests/Unit/Application/Audit/RedactorTest.php`, dazu `AuditEntryTest.php` für die Form der
  Einträge.
- **Integration:** Konto anlegen, ändern, löschen erzeugt drei Einträge mit korrektem
  Vorher/Nachher. → Die drei Anwendungsfälle gehören A2; die Form ist in
  `tests/Application/Audit/ListAuditTrailTest.php` und
  `tests/Integration/Persistence/AuditTrailIntegrationTest.php` gegen Attrappe **und** echte
  Datenbank belegt, einschließlich `creation`/`change`/`removal` mit `NULL`-Rändern.
- **Integration:** abgelehnter Zugriff erzeugt einen Eintrag mit Ergebnis „verweigert".
  → `tests/Integration/Http/AuthorizationChainTest.php::testARefusedAttemptIsRecordedInTheAuditTrail`
  und `tests/Contract/AuditEndpointTest.php`.
