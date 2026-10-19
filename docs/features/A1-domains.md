<!-- markdownlint-disable MD013 MD024 MD060 -->

# A1 — Domains

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | F2, F3, F4, S3, M2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 7.2 und 8.1 |
| **Status** | **umgesetzt** — Backend und Oberfläche |

---

## Ziel

Domains anlegen, ändern, löschen und auflisten, einschließlich Kontingenten, Standardordnern
und der Umbenennung als bestätigte Stapeloperation.

## Abgrenzung

- Keine Konten — das ist **A2**.
- Kein Schreiben von DKIM, `loginrealms` oder DNS. Die Anwendung weist nur darauf hin (E-4,
  E-9).

## Abhängigkeiten und geteilte Dateien

Erstes fachliches Paket. Legt die Muster fest, denen A2 bis A6 folgen: Repository-Aufbau,
Handler-Zuschnitt, Policy-Anbindung, Audit-Aufruf, Schema-Abschnitt.

Angefasste geteilte Dateien:

| Datei | Was |
|---|---|
| `openapi/components/common.yaml` | eigener `domain.*`-Block im `ErrorCode`-Enum, hinter A4s |
| `tests/Integration/Kernel/ContainerBootTest.php` | benutzte `/api/v1/domains` als „nicht geroutet"; jetzt `/api/v1/no-such-resource` |
| `migrations/` | `0030_domain_setting.sql` |
| `config/di/` | eigene Datei `domains.php` |

`Permission` und `RolePermissions` **nicht** angefasst — S3 hatte `domain.read`, `domain.write`
und `domain.delete` bereits deklariert und zugeordnet. `Domain\Exception\DomainErrorCode`
ebenfalls nicht: A1s Codes entstehen im Anwendungsfall, nicht im Fachmodell, und stehen deshalb
in `Application\Domain\DomainErrorCode`.

## Vorgaben

- Umbenennung ändert **nicht nur die Datenbank**. Sie erfordert `renamemailbox` über alle
  Konten der Domain — genau das unterließ das Altsystem und machte Konten unerreichbar.
- Reihenfolge und Kompensation nach Architektur 8.1.
- Im Anlage-Dialog erscheint ein Hinweis auf die externen Schritte: Pillar-Eintrag,
  Konfigurationslauf, DNS-Einträge (MX, SPF, DKIM, DMARC).

## Artefakte

| Artefakt | Datei |
|---|---|
| Vertrag | `openapi/paths/domains.yaml`, `openapi/components/domain.yaml` |
| Endpunkte | `GET/POST /domains`, `GET/PATCH/DELETE /domains/{domain}` |
| Anwendungsfälle | `src/Application/Domain/{List,Get,Create,Update,Delete,Rename}Domain.php` |
| Ein-/Ausgabe | `DomainDraft`, `DomainChange`, `DomainAttributes`, `DomainSummary`, `DomainView`, `DomainPrecondition`, `DomainDefaults`, `DomainOperationFailed`, `DomainErrorCode`, `RenameStep`, `DomainLeftovers` (U-99) |
| Port + Umsetzung | `Application\Domain\DomainStore`, `Infrastructure\Persistence\DomainRepository`, `InMemoryDomainStore` |
| Reparaturweg | `Domain\Repair\{RepairQueue,RepairRequest}`, `Infrastructure\Repair\{Logging,InMemory}RepairQueue` |
| Fachmodell | `Domain\Model\LegacyPrefix`, `Domain\Model\DomainRewrite`, `Domain\Model\DottedNamespace` (U-96) |
| Sperre | `Infrastructure\Persistence\NamedLock`, `DomainStore::withNamespaceLock()` — die Namensraumsperre aus U-97 |
| Richtlinien-Ablehnung | `Domain\Policy\PolicyRefusal` — `AccessDenied` und `ResourceNotVisible` sind keine `DomainError` mehr (U-98) |
| Handler | `src/Http/Handler/Domains/` (fünf Handler, `DomainResponder`, `DomainPath`) |
| Schema | `migrations/0030_domain_setting.sql` |
| Verdrahtung | `config/di/domains.php` |
| Protokoll | S4s `AuditWriter` in `Create`, `Update`, `Delete` und `RenameDomain`; `DomainStore::atomically()` für die gemeinsame Transaktion (U-76) |
| Oberfläche | `frontend/src/pages/domains/` — Liste (`/domains`), Anlegen mit Checkliste der nächsten Schritte (`/domains/new`), Detail mit Umbenennen und Löschen (`/domains/:domain`), Bearbeiten (`/domains/:domain/edit`); Texte in `frontend/src/i18n/de/domain.ts`; jsdom-Tests in `domains.test.tsx` |

## Zu klärende Punkte

Alle acht sind beantwortet, dazu sieben aus den Reviews. Was über A1 hinausreicht, steht als U-Entscheidung; der Rest ist
hier als **Annahme** markiert.

1. **Das Feld `prefix`.** → **U-46.** Abgeleitet aus dem Domainnamen, bei der Anlage einmal
   vergeben, nie wieder geändert — auch nicht bei einer Umbenennung. Bei Kollision bis zu acht
   Kandidaten mit Hash-Diskriminator, danach `409 domain.prefix_taken`.
2. **`maxaccounts`.** → **U-48.** `0` ist unbegrenzt (Altsemantik), durchgesetzt wird bei der
   Kontoanlage (A2). Unterschreiten durch nachträgliche Änderung ist erlaubt: bestehende Konten
   bleiben, neue kommen nicht dazu.
3. **`domainquota`.** → **U-48.** Getragen und änderbar, **nicht** bei jeder Operation aus Cyrus
   summiert. Die Prüfung gehört an die Kontoquota (A2), gebündelt über eine Verbindung.
4. **`freenames` und `freeaddress`.** → **U-47.** Kompatibilitätsfelder: getragen, änderbar, ohne
   Wirkung. Die genaue Altsemantik ist aus dem Bestand nicht rekonstruierbar; A2/A3 dürfen sie
   später deuten.
5. **Löschen einer Domain mit Konten.** → **U-49.** Verweigert, `409 domain.not_empty`. Kein
   Kaskadieren.
6. **`transport`.** → **U-47.** Übernommen, schreibgeschützt angezeigt, nie interpretiert. Neue
   Domains bekommen `cyrus`.
7. **Standardordner (`folders`).** → **U-47.** Format bleibt kommasepariert. Eine Änderung gilt
   **nur für neue Konten**; bestehende Postfächer werden nicht nachträglich bestückt.
8. **Umbenennung bei nicht erreichbarem Mailstore.** → **U-49, bestätigt.** Verweigert, mit einer
   Sonde vor dem ersten `renamemailbox`, `503 domain.mailstore_unavailable`. Eine Domain **ohne
   Konten** wird auch ohne erreichbaren Mailstore umbenannt — es gibt nichts zu bewegen.

### Aus dem Review nachgezogen

9. **Löschen einer Domain mit Adressen und Zuordnungen.** → **U-95.** Zeilen in `virtual`, die auf
   `@<domain>` enden, verweigern das Löschen: `409 domain.still_has_addresses`. Die
   `domainadmin`-Zeilen der Domain gehen im selben Schritt mit und stehen als Kindeinträge im
   Protokoll. Eine neu angelegte Domain gleichen Namens erbt keinen alten Domainmaster mehr.
10. **Domainname, der einen Punktnamen mehrdeutig macht.** → **U-96.** Anlegen und Umbenennen
    lehnen einen Namen ab, wenn ein Konto einer Elterndomain auf `.<name>` endet; die Umbenennung
    prüft zusätzlich die Konten, die sie bewegt. `409 domain.namespace_conflict`.
11. **Wettlauf zwischen Konto- und Domainanlage.** → **U-97.** Eine gemeinsame Sperre
    `mailadmin.namespace` um die Datenbankschritte; die Prüfungen laufen unter ihr noch einmal.
12. **Richtlinien-Ablehnungen in `catch (DomainError)`.** → **U-98.** Strukturell behoben, ein
    Wächtertest liest alle Handler.
13. **Altlasten unter einem neuen Domainnamen.** → **U-99.** Anlegen und Umbenennen lehnen einen
    Namen ab, solange `domainadmin`-Zeilen ihn nennen oder `virtual`-Zeilen auf `@<name>` enden:
    `409 domain.leftover_state`, vor und unter der Namensraumsperre. Bereinigt wird nichts.
14. **Konto, das während der Umbenennung entsteht; Domain, die währenddessen verschwindet.** →
    **U-100.** Unter der Sperre wird der Plan mit den Konten verglichen; weicht er ab, gehen die
    Postfächer zurück und die Antwort ist `409 domain.rename_incomplete`. Eine inzwischen
    gelöschte Domain ergibt `404` statt eines Erfolgs.
15. **Weiterleitungen im Punktschema.** → **U-101.** Die Umbenennung schreibt die
    Weiterleitungszeilen (`alias` = Benutzername, `username` leer) der bewegten Konten mit um und
    lehnt ab, wenn der neue Benutzername eines Kontos schon Schlüssel einer Weiterleitung ist.

Ebenfalls geprüft und verworfen: Der Befund, `AccountRepository::isTaken()` vergleiche
`accountuser.username` wegen einer binären Spalte ohne Wirkung von `LOWER()`. Die Spalte ist ein
`VARCHAR` mit der Kollation `latin1_bin`, kein Binärtyp; `LOWER()` wirkt, und der Vergleich zweier
kleingeschriebener Werte unterscheidet keine Schreibweise. Ein Integrationstest gegen die echte
Spalte hält das fest; die irreführenden Kommentare in `DomainRepository` sprechen jetzt von
binärer Kollation.

### Nachbesserungen an der Oberfläche (U-133)

- **Umbenennen und Löschen** gehen über `forgetDomainName()` in `frontend/src/pages/domains/domainCache.ts`:
  Der Detaileintrag unter dem alten Namen wird entfernt, bevor `domains`, `accounts`, `addresses`
  und `admins` als veraltet markiert werden. Die Seite lädt den alten Namen also nicht mehr neu
  (kein `404` nach dem Umbenennen), und die Domainauswahl der Administratorformulare bietet eine
  gelöschte Domain nicht mehr an.

### Paket-lokale Annahmen

- **Annahme: der Hinweis auf die externen Schritte sind stabile Schlüssel, keine Sätze.**
  `next_steps` in der `201`-Antwort trägt `salt_pillar`, `salt_apply`, optional
  `cyrus_loginrealm`, dann `dns_mx`, `dns_spf`, `dns_dkim`, `dns_dmarc`. Die API bleibt
  sprachneutral (E-16); die Übersetzung liegt beim Frontend. `cyrus_loginrealm` erscheint nur,
  wenn das wirksame Namensschema `fqun` ist — geprüft wird nichts, das kann die Anwendung nicht
  (E-9).
- **Annahme: `naming_scheme` ist über die API änderbar und gilt nur für neue Konten.** Die Spalte
  lebt in `mailadmin_domain_setting` (Migration 0030), `inherited` ist ein eigener Wert neben
  `dotted` und `fqun`. Bestehende Konten wechseln dadurch **nicht** das Schema — das wäre
  `renamemailbox` je Konto plus neue Zugangsdaten für den Nutzer (E-14) und gehört A2.
- **Annahme: `virtual.dest` wird nur dort umgeschrieben, wo es eindeutig ist.** Eine Adresse in
  der alten Domain (`@` macht sie eindeutig) und ein Kontoname, den der Aufrufer in
  `accountuser` nachgeschlagen hat. Ein `dotted`-Kontoname, den niemand nachgeschlagen hat,
  bleibt stehen: `info.sub.example.test` kann `info` in `sub.example.test` oder `info.sub` in
  `example.test` sein, und die Zeichenkette sagt es nicht (Architektur 3.3). Was stehen bleibt,
  meldet A6s Abgleich; was falsch umgeschrieben würde, stellt Mail zu.
- **Annahme: eine unbrauchbare Zeile in `domain.folders` wird beim Lesen übersprungen, nicht
  abgelehnt.** Ein Ordnername mit `@` bringt sonst `GET /domains` als `500` zu Fall, weil eine
  Domain aus dem Jahr 2009 etwas Ungültiges enthält. U-22 verlangt Anzeigen statt Kippen.
- **Annahme: `GET /domains/{domain}` liest den Pfadwert tolerant.** Eine Bestandsdomain darf
  einen Namen tragen, den diese Anwendung nicht ausstellen würde. Neue Namen gehen durch die
  strenge Tür.

## Protokoll (U-76)

Jede Änderung schreibt über S4s `AuditWriter` genau so, wie A4 es tut: im Erfolgsfall **in der
Transaktion** der Änderung (U-63), mit der Darstellung, die `GET /domains/{domain}` zeigt
(U-61), maskiert im Schreiber (U-62), ein Aufruf je Anwendungsfall (U-64). Die bisherigen
Platzhalter-Logzeilen an diesen Stellen sind ersetzt.

| Anwendungsfall | Aktion | Inhalt |
|---|---|---|
| `CreateDomain` | `domain.create` | `after` |
| `UpdateDomain` | `domain.update` | `before`, `after` |
| `DeleteDomain` | `domain.delete` | `before`; dazu je entfernter Zuordnung ein Kindeintrag (Objekttyp `admin`, `before` = `{"domains": ["<domain>"]}`) über `part_of` (U-95) |
| `RenameDomain`, Erfolg | `domain.rename` | Domaineintrag mit `before`/`after`, dazu je Konto ein Kindeintrag (Objekttyp `account`, alter und neuer Cyrus-Benutzername) über `part_of` |
| `RenameDomain`, Abbruch | `domain.rename`, Ergebnis `failed` | nach der Kompensation; Grund nennt Stufe und ob die Postfächer zurückkamen, verweist sonst auf die Reparaturwarteschlange |

Scheitert der Audit-Schreibvorgang einer Umbenennung, rollt die Datenbankhälfte zurück und die
Postfächer werden zurückbenannt — derselbe Weg wie jeder andere Fehler des Datenbankschritts. Nur
der `failed`-Eintrag fängt seinen eigenen Schreibfehler, damit eine defekte Audit-Tabelle
`domain.rename_incomplete` nicht zu einem `500` macht. Ablehnungen vor jeder Änderung (`409`,
`412`, `428`, `422`, `503`) werden wie in A4 nicht protokolliert, nur geloggt.

Nebenbei: Nach einem Datenbankfehler trug `domain.rename_incomplete` die Meldung der Datenbank im
`detail`. Sie steht jetzt nur noch im Log.

## Definition of Done

- [x] Anlegen, Ändern, Löschen und Auflisten funktionieren, domain-gescoped.
- [x] Eine Umbenennung benennt Datenbank **und** alle Postfächer um; ein Abbruch hinterlässt einen
      Eintrag in der Reparaturwarteschlange und einen verständlichen Zustand.
- [x] Der Hinweis auf die externen Schritte erscheint beim Anlegen.
- [x] Jede Änderung erzeugt einen Audit-Eintrag — über S4s `AuditWriter`, in der Transaktion der
      Änderung; eine abgebrochene Umbenennung als `failed` nach der Kompensation (U-76).
- [x] Das Löschen lässt keinen Zustand zurück: Adressen verweigern es, Zuordnungen gehen mit (U-95).
- [x] Keine Domain macht einen bestehenden Punktnamen mehrdeutig, weder beim Anlegen noch beim
      Umbenennen; die Prüfungen laufen unter einer gemeinsamen Sperre mit der Kontoanlage
      (U-96, U-97).
- [x] Kein Handler fängt eine Richtlinien-Ablehnung ab (U-98).
- [x] Ein neuer oder umbenannter Domainname übernimmt keine Zuordnungen und Adressen einer
      früheren Domain (U-99).
- [x] Eine Umbenennung strandet kein Konto, das während des Postfachschritts entsteht, und meldet
      keinen Erfolg für eine inzwischen gelöschte Domain (U-100).
- [x] Weiterleitungen im Punktschema ziehen mit; eine verwaiste Weiterleitung unter dem neuen
      Benutzernamen verweigert die Umbenennung (U-101).
- [x] Oberfläche: Liste, Detail, Anlegen, Bearbeiten und Löschen mit `If-Match`. Nur die geänderten
      Felder gehen an den Server; ein `412` bietet das Neuladen an. Löschen und Umbenennen
      verlangen die Eingabe des Domainnamens. Die Umbenennung zählt ihre Folgen auf (Postfächer,
      Anmeldenamen, Salt/DNS) und zeigt `domain.rename_incomplete` als nicht übersehbaren Hinweis
      mit Vorgangsnummer und Weg zur Reparaturwarteschlange. Nach dem Anlegen erscheinen die
      `next_steps` als übersetzte Checkliste; die Häkchen werden nicht gespeichert. Ein
      Domainmaster sieht Liste und Detail ohne Aktionen.

## Testfälle

| Stufe | Datei | Was |
|---|---|---|
| Unit | `tests/Unit/Domain/Model/LegacyPrefixTest.php` | Prefix-Ableitung, Kollisionskandidaten, Spaltenbreite |
| Unit | `tests/Unit/Domain/Model/DomainRewriteTest.php` | was eine Umbenennung in `virtual` anfasst — und was nicht |
| Unit | `tests/Unit/Application/Domain/DomainViewTest.php` | Darstellung, `inherited`, ETag, `If-Match` |
| Unit | `tests/Unit/Application/Domain/DomainAttributesTest.php` | abwesend / `null` / Wert; Bestätigungspflicht |
| Anwendungsfall | `tests/Application/Domain/RenameDomainTest.php` | **Erfolg, Fehler in der Mitte, Kompensation, Reparaturwarteschlange, DB-Fehler nach dem Mailstore**; Protokoll: Vorgangseintrag mit Kindern je Konto, `failed` nach Mailstore- und DB-Abbruch, Verweis auf die Reparaturwarteschlange, gescheiterter Audit-Schreibvorgang benennt die Postfächer zurück, defekte Audit-Tabelle verdeckt den Abbruch nicht |
| Anwendungsfall | `tests/Application/Domain/{Create,Update,Delete,List}DomainTest.php` | Kontingente, Konflikte, `If-Match`, Zuschnitt; Audit-Eintrag mit `before`/`after`, keiner bei Ablehnungen; Umbenennen plus Attribute ergibt zwei Vorgänge |
| Vertrag | `tests/Contract/DomainEndpointTest.php` | alle fünf Endpunkte durch die echte Kette; `domain.still_has_addresses` und `domain.namespace_conflict`; `403` für den Domainmaster, `404` byteweise identisch für fremd und fehlend; Audit-Eintrag mit Request-Id über die echte Verdrahtung |
| Integration | `tests/Integration/Persistence/DomainRepositoryIntegrationTest.php` | echtes MariaDB mit Altschema: Rundlauf, `enum('YES','NO')`, Zuschnitt, **Umbenennung über fünf Tabellen**, Umbenennung innerhalb einer äußeren Transaktion |
| Unit | `tests/Unit/Domain/Model/DottedNamespaceTest.php` | welche Subdomains ein Punktname erreicht, Elterndomains eines Namens (U-96) |
| Unit | `tests/Unit/Http/HandlerCatchGuardTest.php` | kein Handler fängt `AccessDenied`/`ResourceNotVisible`, auch nicht über einen Obertyp (U-98) |
| Anwendungsfall | `tests/Application/Domain/DeleteDomainTest.php` | Adressen, Catchall und Weiterleitungen verweigern; fremde Domains nicht; Zuordnungen entfernt und als Kindeinträge protokolliert; neu angelegte Domain ohne alte Zuordnung; Konto oder Adresse, die vor der Sperre hinzukommt (U-95, U-97) |
| Anwendungsfall | `tests/Application/Domain/{Create,Rename}DomainTest.php` | Namensraumkonflikt mit Elterndomain, Schreibweise, tiefere Subdomain, `fqun` und Subdomain-Konten blockieren nicht; bewegtes Konto im Namensraum einer Subdomain; Wettlauf unter der Sperre, bei der Umbenennung mit Rückbenennung der Postfächer (U-96, U-97) |
| Integration | `tests/Integration/Persistence/DomainRepositoryIntegrationTest.php` | Löschen mit Zuordnungen, Adresszählung ohne `LIKE`-Platzhalter, `namespaceClaimant`, `accountUsernameTaken`, Wiedereintritt der Namensraumsperre |
| Anwendungsfall | `tests/Application/Domain/CreateDomainTest.php` | verwaiste Zuordnung, Catchall, Adresse vor der Sperre verweigern; Altlasten eines anderen Namens nicht (U-99) |
| Anwendungsfall | `tests/Application/Domain/RenameDomainTest.php` | Konto, das während des Postfachschritts entsteht, Domain, die vor der Sperre gelöscht wird (U-100); verwaiste Weiterleitung unter dem neuen Namen, eigene Weiterleitung zieht mit (U-101); Zuordnung und Catchall am Ziel, Schreibweise allein (U-99) |
| Integration | `tests/Integration/Persistence/DomainRepositoryIntegrationTest.php` | Weiterleitungszeilen ziehen byteweise mit, andere Schreibweise und Kontozeile nicht; `aliasTaken`, `assignmentCount` (U-99, U-101) |
| Integration | `tests/Integration/Persistence/AccountRepositoryIntegrationTest.php` | Belegtprüfung über `accountuser` ohne Adresszeile, gemischte Schreibweise gegen `latin1_bin` |
| Hilfsmittel | `tests/Support/RefusingAuditWriter.php` | ein Audit-Schreiber, der wie eine volle Tabelle scheitert |

Offen: **Integration gegen einen echten Cyrus** — eine Umbenennung über mehrere Postfächer, danach
sind alle erreichbar. Die Bausteine stehen (F5, `CyrusMailstore`), der Test ist noch nicht
geschrieben; die Attrappe deckt die Reihenfolge und die Kompensation ab, nicht das Verhalten von
`renamemailbox` selbst.
