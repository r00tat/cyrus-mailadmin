<!-- markdownlint-disable MD013 MD024 MD060 -->

# A5 — Mailinglisten

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | F2, F3, S3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 7.2 · E-4 · [Listenskript betreiben](../deployment/maillist.md) |
| **Status** | **umgesetzt** — U-56 bis U-60, überarbeitet für die Zustellung per Pipe-Transport durch U-134 bis U-144; Backend, Oberfläche, Listenskript `maillist/` |

---

## Ziel

Mailinglisten über die Oberfläche vollständig pflegbar machen: Listen anlegen, Name, Tag und
Mitglieder ändern, Listen löschen — `lists` und den zugehörigen `virtual`-Eintrag zusammen, für
Superuser und für Domainmaster auf ihren Domains. Eine Änderung wirkt sofort, ohne Salt-Lauf.
Danach lässt sich die Altanwendung maillist-web abschalten.

## Wie eine Listenmail zugestellt wird

```text
liste@domain ──virtual──▶ <schluessel>@<Pseudodomain> ──transport_maps──▶ maillist (pipe) ──▶ maillist.py <schluessel>
```

- Der **Listenschlüssel** ist `REPLACE(address, '@', '.')`. Über ihn sucht `maillist.py` die Liste
  in `lists`.
- Die **Pseudodomain** (`MAILADMIN_LIST_TRANSPORT_DOMAIN`, Standard `maillist.invalid`) wird nie
  öffentlich aufgelöst. Postfix leitet sie an den `pipe(8)`-Transport `maillist` weiter, der dem
  Skript den Local Part (`${user}`) als Argument übergibt.
- **`maillist.py`** schreibt die Mail um (Betreff-Tag, Listenköpfe, Schleifen- und
  Bounce-Erkennung) und verschickt sie an die Mitglieder. Es liegt unter `maillist/` in diesem
  Repository (U-141). Installation, Postfix-Konfiguration und Umstellung:
  [docs/deployment/maillist.md](../deployment/maillist.md).

Bis zur Umstellung (`MAILADMIN_LIST_DELIVERY=alias`) gibt es daneben den Altweg: `virtual` zeigt auf
`dest = username = <schluessel>`, und eine von Salt erzeugte Aliasdatei leitet den Schlüssel an das
Skript. Das Wartungskommando `bin/migrate-list-delivery.php` stellt die Altlisten um (U-143).

## Abgrenzung

- **Die Anwendung erzeugt keine Aliasdatei** (E-4). Sie schreibt ausschließlich die Datenbank:
  `lists` und `virtual`. Mit dem Pipe-Transport braucht es für Listen keine Aliasdatei mehr.
- Keine Listensoftware mit An- und Abmeldung, Moderation oder Archiv.
- Keine Normalisierung von `members` in eine eigene Tabelle. `maillist.py` liest das Textfeld.

## Abhängigkeiten und geteilte Dateien

Berührt `lists` und `virtual`, dazu die Klassifizierung der Adressverwaltung (A3) und die
Domainumbenennung (A1).

Geteilte Dateien, die A5 angefasst hat — jeweils nur der eigene Block:

- `openapi/components/common.yaml` — die `list.*`-Codes im `ErrorCode`-Enum, dazu
  `domain.rename_blocked_by_mailing_list`,
- `openapi/components/address.yaml` — der Typ `mailing_list`,
- `openapi/components/maintenance.yaml` — `ListDeliverySettings` in den Health-Details,
- `src/Domain/Exception/DomainErrorCode.php` und `src/Application/MailingList/MailingListErrorCode.php`,
- `src/Domain/Access/RolePermissions.php` — `list.read`, `list.write` und `list.delete` für
  Domainmaster (U-136),
- `src/Domain/Model/Virtual/` — `VirtualEntryType::MailingList`, `MailingListRecognition` (U-139),
- `src/Application/Address/DestinationRules.php` — keine Ziele auf der Pseudodomain (U-139),
- `src/Application/Domain/{CreateDomain,RenameDomain}.php` — Pseudodomain als Domainname
  abgelehnt, Umbenennung mit Listen gesperrt (U-139, U-140),
- `src/Application/Maintenance/RepairCatalogue.php` — Schritt `lists.remove` (U-138),
- `src/Domain/Access/ActorContext.php` — `ActorContext::system()` für das Umstellungskommando
  (U-143).

## Vorgaben

- `lists` ist eine Bestandstabelle. Struktur bleibt unverändert (E-1). **Keine Migration** — der
  reservierte Bereich 0060–0069 blieb für A5 ungenutzt.
- **Produktions-DDL von `lists`** (U-135): `id` (`int unsigned`, `AUTO_INCREMENT`,
  Primärschlüssel), `name`, `tag`, `address` (je `varchar(255) NOT NULL`), `members`
  (`text NOT NULL`); MyISAM, `latin1`. Kein Unique-Index auf `name` oder `address`. Das Basisschema
  der Testumgebung übernimmt sie.
- **Datenvertrag:**

  | Tabelle | Spalte | Wert |
  |---|---|---|
  | `lists` | `address` | vollständige Listenadresse, kleingeschrieben, ohne `+` im Local Part |
  | `lists` | `name`, `tag` | freier Text, nur `latin1`. `tag` erscheint als `[tag]` im Betreff |
  | `lists` | `members` | ein Eintrag pro Zeile (`\n`), `user@domain` oder `Name <user@domain>` |
  | `virtual` | `alias` | Listenadresse |
  | `virtual` | `dest` | `<schluessel>@<Pseudodomain>` |
  | `virtual` | `username` | leer |
  | `virtual` | `status` | `1` |

- **Anlegen: `lists` zuerst, dann `virtual`. Löschen: `virtual` zuerst, dann `lists`.** Beides
  unter der Namensraumsperre, mit Kompensation und Reparaturwarteschlange (U-138).
- Änderungen wirken im Transportmodus sofort. Nur im Altmodus (`MAILADMIN_LIST_DELIVERY=alias`)
  wirken sie erst nach dem nächsten Konfigurationslauf, und nur dann sagt die Antwort das (U-143).

> **Überholt:** Die ursprüngliche Vorgabe nannte die Spalten `name`, `tag`, `address`, `members`
> mit `name` als Primärschlüssel, und Änderungen wirkten immer erst nach dem Konfigurationslauf.
> Ersetzt durch U-135 und U-143.
>
> **Korrektur am Paketdokument (ursprüngliche Fassung):** Die ursprüngliche Fassung führte eine
> Spalte `id` auf. Die Tabelle hat keine; die Bestandsanalyse (4.1) kennt genau vier Spalten, und
> der Primärschlüssel ist `name`. Daraus folgt U-60. — **Selbst überholt:** Die Produktions-DDL
> hat `id` als Primärschlüssel; die Bestandsanalyse kannte nur die Spaltennamen. Siehe U-135.

## Artefakte

| Was | Wo |
|---|---|
| Vertrag | `openapi/paths/lists.yaml`, `openapi/components/list.yaml` |
| Fachmodell | `src/Domain/MailingList/` — `MailingList`, `MailingListName`, `MemberList`, `Latin1Text` |
| Fehler | `src/Domain/Exception/InvalidMailingList.php` |
| Anwendungsfälle | `src/Application/MailingList/` — fünf, dazu `MailingListView` (Darstellung + ETag), `MailingListGuard`, `MailingListChanges`, `MailingListFailure`; Zustellung: `ListDelivery`, `ListDeliveryMode`, `TransportDomainIsManaged`; Umstellung: `MigrateListDelivery` und Ergebnistypen |
| Klassifizierung | `src/Domain/Model/Virtual/MailingListRecognition.php`, `VirtualEntryType::MailingList` |
| Persistenz | `src/Infrastructure/Persistence/MailingListRepository.php` (Schnittstelle), `SqlMailingListRepository`, `InMemoryMailingListRepository` |
| Handler | `src/Http/Handler/MailingLists/` — fünf, dazu `MailingListResponder`, `MailingListPath`, `MailingListBody` |
| Wartungskommando | `bin/migrate-list-delivery.php` (`composer lists:migrate-delivery`) |
| Container | `config/di/mailinglists.php` |
| Oberfläche | `frontend/src/pages/lists/` — Liste mit Anlegedialog (`/lists`), Detail mit Ändern und Löschen (`/lists/:address`); Texte in `frontend/src/i18n/de/list.ts`; jsdom-Tests in `lists.test.tsx`. Listeneinträge in der Adressliste unter `frontend/src/pages/addresses/`, Einstellungen in `frontend/src/pages/health/HealthPage.tsx` |
| Listenskript | `maillist/` — `maillist.py`, Installer `maillist/install`, Beispielkonfiguration, Tests; Release-Archiv aus `.github/workflows/release.yml` |
| Formatvertrag | `tests/Fixtures/MemberFormat/` — Golden-Dateien für `members`, gelesen von `MemberList` und `maillist.py` |
| Testumgebung | `docker/mail/` — Transport `maillist` mit Stub, Sperre der Pseudodomain |

**Abweichung von der Artefaktliste:** Das Paketdokument nannte
`Infrastructure/Persistence/ListRepository.php`. Der Name wurde zu `MailingListRepository`
geändert, weil `ListQuery`, `ListCriteria` und `Repository::list()` in derselben Namensschicht
liegen und ein `ListRepository` dort wie „die Repository-Basis für Listenabfragen" liest.

**Oberfläche.** Liste, Anlegen (Dialog), Ändern und Löschen. Die Detailseite liegt unter der
URL-kodierten Adresse; die Adresse ist dort nur lesbar, ein Hinweistext erklärt, dass man zum
Ändern der Adresse die Liste löscht und neu anlegt. Editierbar sind Name, Tag und Mitglieder. Das
Mitgliederfeld nimmt eine Adresse pro Zeile; gesendet wird `members` nur, wenn sich die Zeilen
gegenüber dem Geladenen geändert haben, damit unveränderte Bestandslisten byteweise erhalten
bleiben (U-134). Ebenso werden Name und Tag einer Altliste so gesendet, wie sie gespeichert sind,
solange niemand sie ändert. Unbrauchbare Adressen und Mitglieder sind markiert, nicht entfernt
(U-22). Den Menüpunkt sieht jeder mit `list.read`, also auch Domainmaster. In der Adressliste einer
Domain erscheinen Listeneinträge mit eigenem Typ, einem Verweis auf die Liste und ohne Aktionen.

Der Hinweis auf den Konfigurationslauf folgt **nur** der Antwort: Trägt sie
`X-Mailadmin-Effect: pending-configuration-run` (nur im Altmodus), nennt die Erfolgsmeldung den
Konfigurationslauf, sonst nicht (U-132, U-143). Der frühere ständige Hinweis auf jeder Listenseite,
im Anlegedialog und in der Löschbestätigung ist entfallen.

## Endpunkte

| Methode und Pfad | `operationId` | `x-permission` |
|---|---|---|
| `GET /lists` | `listMailingLists` | `list.read` |
| `POST /lists` | `createMailingList` | `list.write` |
| `GET /lists/{address}` | `getMailingList` | `list.read` |
| `PATCH /lists/{address}` | `updateMailingList` | `list.write` |
| `DELETE /lists/{address}` | `deleteMailingList` | `list.delete` |

`{address}` ist die URL-kodierte Listenadresse (U-135). Der `PATCH`-Körper kennt `name`, `tag` und
`members`, aber kein `address`.

Alle fünf deklarieren `403`: beim Anlegen für eine Domain außerhalb des eigenen Zuschnitts, und weil
keine der drei Berechtigungen von jeder Rolle gehalten wird — ein Vertragstest erzwingt das. Eine
Liste einer fremden Domain ist beim Lesen, Ändern und Löschen `404` (U-44, U-136).

Fehlercodes: `list.address_taken` (`409`), `list.address_has_extension`, `list.unknown_domain`,
`list.invalid_address`, `list.invalid_name`, `list.invalid_tag`, `list.invalid_member`,
`list.name_outside_latin1`, `list.tag_outside_latin1`, `list.member_outside_latin1`,
`list.member_not_deliverable` (je `422`).
Die Domainumbenennung kennt dazu `domain.rename_blocked_by_mailing_list` (`409`, U-140).

> **Überholt:** Die Pfade lauteten `/lists/{listName}`, der Konfliktcode war
> `list.already_exists`. Ersetzt durch U-135.

## Zu klärende Punkte

Alle fünf sind entschieden. Die ursprünglichen Antworten stehen weiter da, wo sie überholt sind,
mit Verweis auf die neue Entscheidung.

1. **Format des Feldes `members`** → **U-134** (ersetzt U-56). Ein Eintrag pro Zeile; gelesen
   wird an Zeilenumbruch, Komma, Semikolon und `<br>`, nach dem Dekodieren von HTML-Entities. Eine
   unverändert gespeicherte Liste behält ihre Originalbytes, samt `NULL`. Golden-Dateien prüfen
   das Format gegen `MemberList` und gegen `normalize_recipients()` in `maillist.py`.
   Neue Einträge mit `,`, `;` oder `<br>` im Anzeigenamen, mit Entities oder mit Zeichen außerhalb
   von `latin1` werden abgelehnt (U-137), ebenso Adressen, an die `maillist.py` nicht genau so
   zustellt, wie sie dastehen (`list.member_not_deliverable`, U-137 Punkt 4).
   **Ersetzt durch U-134:** ~~geschrieben wird komma-separiert. **Annahme, nicht geprüft:** dass
   Komma das Trennzeichen ist, das `maillist.sls` beim Rendern der Aliasdatei erwartet.~~ Die
   Aliasdatei enthielt nie die Mitglieder, nur die Pipe-Zeile auf das Skript, und das trennt an
   Zeilenumbrüchen.
2. **Bedeutung von `tag`** → **U-58**, unverändert: freies Textfeld. `maillist.py` setzt es als
   `[tag]` vor den Betreff; eine weitere Semantik gibt es nicht. Neu gilt nur die
   `latin1`-Regel (U-137).
3. **Wer darf Listen verwalten?** → **U-136** (ändert U-58): **Superuser und Domainmaster**, diese
   auf ihren Domains. Fremde Listen ergeben `404`, Anlegen in einer fremden Domain `403`.
   **Ersetzt durch U-136:** ~~weiterhin Superuser allein, weil der Primärschlüssel `name` ein
   globaler Namensraum ohne Domainanteil ist.~~ Die Identität ist die Adresse (U-135), und die
   trägt ihre Domain.
4. **Validierung der Mitgliederadressen** → **U-57**: ein **hinzukommender** Eintrag muss eine
   Mailadresse sein, ein **bereits gespeicherter** wird nie geprüft und nie entfernt, sondern in
   `unusable_members` ausgewiesen. Die Strenge bleibt richtig, die Begründung hat sich verschoben:
   `members` wird nicht in eine Aliasdatei gerendert, sondern von `maillist.py` gelesen; was dort
   kein Empfänger ist, wird übersprungen.
5. **Hinweis auf den Konfigurationslauf** → **U-59**, eingeschränkt durch **U-143**: der Kopf
   `X-Mailadmin-Effect: pending-configuration-run` erscheint nur noch bei
   `MAILADMIN_LIST_DELIVERY=alias`. Im Transportmodus wirkt jede Änderung sofort.

Neu hinzugekommen:

6. **Identität und Name** → **U-135** (ersetzt U-60): Identität ist die Adresse, unveränderlich
   nach dem Anlegen. `name` ist freier, änderbarer Anzeigetext und darf doppelt vorkommen;
   gespeicherte Werte liest `MailingListName::fromStoredValue()` ohne Ablehnung.
7. **Zwei Tabellen ohne Transaktion** → **U-138**: Reihenfolge, Namensraumsperre, Kompensation,
   Reparatureinträge `list.create`/`list.delete` mit Schritt `lists.remove`.
8. **Listeneinträge in `virtual`** → **U-139**: eigener Typ, strenge Erkennung, Transportziele nur
   für Listen, Pseudodomain nie als Domain.
9. **Domainumbenennung** → **U-140**: gesperrt, solange die Domain Listen hat.
10. **Adresserweiterung und Zeichensatz** → **U-137**: kein `+` in der Listenadresse, nur `latin1`.
11. **Umstellung der Altlisten und Betriebsübersicht** → **U-143**.

## Definition of Done

| Kriterium | Stand |
|---|---|
| Listen lassen sich anlegen, ändern und löschen, ohne dass bestehende Einträge beschädigt werden | **erfüllt** — Unit-, Anwendungs-, Vertrags- und Integrationstest; Anlegen und Löschen schreiben `lists` und `virtual` |
| Das Format von `members` entspricht exakt dem, was der Leser erwartet | **erfüllt** — Golden-Dateien, geprüft gegen `MemberList` und `maillist.py`; unverändert gespeicherte Listen sind byteweise identisch |
| Eine in mailadmin angelegte Liste empfängt Mail | **erfüllt** — Ende-zu-Ende-Test in der Testumgebung: Postfix übergibt dem Transport den richtigen Listenschlüssel; nach dem Löschen kommt nichts mehr an |
| Der Hinweis auf den nötigen Konfigurationslauf erscheint, wenn einer nötig ist | **erfüllt** — `X-Mailadmin-Effect` auf `201`, `200` und `204`, nur im Altmodus; Vertragstests für beide Modi |
| Oberfläche: Liste, Anlegen, Ändern, Löschen mit `If-Match` | **erfüllt** — nur geänderte Felder, `412` mit Neuladen; jsdom-Tests in `frontend/src/pages/lists/lists.test.tsx` |
| Domainmaster verwalten die Listen ihrer Domains | **erfüllt** — Anwendungs- und Vertragstests: eigene Domain `200`/`204`, fremde `404`, Anlegen fremd `403` |
| Änderungen sind protokolliert | **erfüllt** — `mailadmin_audit`: `list.create`, `list.update`, `list.delete`, beim Anlegen und Löschen mit dem `virtual`-Eintrag als Kindeintrag; das Umstellungskommando schreibt `list.delivery_migrate`/`list.delivery_revert` |

## Testfälle

| Was | Wo |
|---|---|
| **Unit:** Zerlegen und Zusammensetzen des Mitgliederfelds, verlustfrei; Zeilenformat, Entities, `<br>` | `tests/Unit/Domain/MailingList/MemberListTest.php`, `MemberFormatGoldenTest.php` |
| **Unit:** Namen, Tags, Adressen; strenge und tolerante Tür; `latin1` | `MailingListNameTest`, `MailingListTest` |
| **Unit:** Klassifizierung von Listeneinträgen in `virtual`, Transport- und Altform | `tests/Unit/Domain/Model/Virtual/` |
| **Anwendung:** Konflikt (Adresse, Alias, Listenschlüssel), Reihenfolge, Kompensation, Reparatureinträge, Domainmaster, `If-Match`, Sortier-Allowlist | `tests/Application/MailingList/` |
| **Anwendung:** Umstellungskommando — vorwärts, idempotent, Trockenlauf, `--revert`, Wiederaufnahme, verwaltete Pseudodomain, Audit | `tests/Application/MailingList/MigrateListDeliveryTest.php` |
| **Vertrag:** jede Operation als Superuser, als Domainmaster (eigene und fremde Domain) und ohne Token; Kopf je Modus; `list_delivery` in den Health-Details | `tests/Contract/MailingListEndpointTest.php`, `tests/Contract/MaintenanceEndpointTest.php` |
| **Integration:** Produktions-DDL, Anlegen und Löschen über beide Tabellen, Bytetreue | `tests/Integration/Persistence/MailingListRepositoryIntegrationTest.php` |
| **Ende zu Ende:** Mail an eine angelegte Liste erreicht den Transport mit dem richtigen Schlüssel; direkte Adressierung der Pseudodomain wird abgelehnt | `tests/Integration/TestEnvironment/MailingListDeliveryTest.php` |
| **Listenskript:** Exit-Codes je Fehlerfall, Argument vor `Delivered-To`, Trennzeichen, Schleifenschutz | `maillist/tests/` |
| **Oberfläche** | `frontend/src/pages/lists/lists.test.tsx`, Adressliste, `HealthPage.test.tsx` |

## Was bewusst nicht getan wurde

- **Keine Migration.** `lists` bleibt, wie es ist. Der für A5 vorgesehene Nummernbereich
  0060–0069 ist unbenutzt und bleibt frei.
- **Keine Adressänderung per `PATCH`.** Die Adresse ist die Identität (U-135); geändert wird durch
  Löschen und Anlegen. ~~Kein Umbenennen per `PATCH`. Der Name ist die Identität (U-60).~~ Der
  Name ist seit U-135 änderbar.
- **Kein `list.not_found`.** Eine fehlende Liste antwortet mit `request.not_found`, genau wie
  eine, die jemand nicht sehen darf. Ein eigener Code wäre der Unterscheider, den die
  403/404-Regel gerade beseitigt (U-44).
- **Keine Bereinigung des Bestands.** Ungewöhnliche Einträge werden markiert, nicht korrigiert
  (U-22). Das Umstellungskommando legt fehlende `virtual`-Einträge nicht an.
- **Keine Mitnahme der Listen bei der Domainumbenennung** (U-140).
