<!-- markdownlint-disable MD013 MD024 MD060 -->

# A5 — Mailinglisten

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | F2, F3, S3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 7.2 · E-4 |
| **Status** | **umgesetzt** — U-56 bis U-60; Backend und Oberfläche |

---

## Ziel

Die Tabelle `lists` über die Oberfläche pflegbar machen: Listen anlegen, Mitglieder ändern,
Listen löschen.

## Abgrenzung

- **Die Anwendung erzeugt keine Aliasdatei.** Das übernimmt das Konfigurationsmanagement, das
  die Tabelle ausliest (E-4). Die Anwendung schreibt ausschließlich die Datenbank.
- Keine Listensoftware, keine Zustellung, keine Archive.

## Abhängigkeiten und geteilte Dateien

Eigenständig. Berührt nur `lists`.

Geteilte Dateien, die A5 angefasst hat — jeweils nur der eigene Block:

- `openapi/components/common.yaml` — fünf `list.*`-Codes im `ErrorCode`-Enum,
- `src/Domain/Exception/DomainErrorCode.php` — vier `LIST_INVALID_*`-Konstanten.

`Permission` und `RolePermissions` blieben unverändert: S3 hatte `list.read`, `list.write` und
`list.delete` bereits angelegt und dem Superuser zugeordnet, und A5 hat den Zuschnitt nicht
erweitert (U-58).

## Vorgaben

- `lists` ist eine Bestandstabelle: `name`, `tag`, `address`, `members`. Struktur bleibt
  unverändert (E-1). **Keine Migration** — der reservierte Bereich 0060–0069 blieb ungenutzt.
- Änderungen wirken erst nach dem nächsten Konfigurationslauf — die Oberfläche sagt das.

> **Korrektur am Paketdokument:** Die ursprüngliche Fassung führte eine Spalte `id` auf. Die
> Tabelle hat keine; die Bestandsanalyse (4.1) kennt genau vier Spalten, und der Primärschlüssel
> ist `name`. Daraus folgt U-60.

## Artefakte

| Was | Wo |
|---|---|
| Vertrag | `openapi/paths/lists.yaml`, `openapi/components/list.yaml` |
| Fachmodell | `src/Domain/MailingList/` — `MailingList`, `MailingListName`, `MemberList` |
| Fehler | `src/Domain/Exception/InvalidMailingList.php` |
| Anwendungsfälle | `src/Application/MailingList/` — fünf, dazu `MailingListView` (Darstellung + ETag), `MailingListGuard`, `MailingListChanges`, `MailingListFailure` |
| Persistenz | `src/Infrastructure/Persistence/MailingListRepository.php` (Schnittstelle), `SqlMailingListRepository`, `InMemoryMailingListRepository` |
| Handler | `src/Http/Handler/MailingLists/` — fünf, dazu `MailingListResponder`, `MailingListPath`, `MailingListBody` |
| Container | `config/di/mailinglists.php` |
| Oberfläche | `frontend/src/pages/lists/` — Liste mit Anlegedialog (`/lists`), Detail mit Ändern und Löschen (`/lists/:listName`); Texte in `frontend/src/i18n/de/list.ts`; jsdom-Tests in `lists.test.tsx` |

**Abweichung von der Artefaktliste:** Das Paketdokument nannte
`Infrastructure/Persistence/ListRepository.php`. Der Name wurde zu `MailingListRepository`
geändert, weil `ListQuery`, `ListCriteria` und `Repository::list()` in derselben Namensschicht
liegen und ein `ListRepository` dort wie „die Repository-Basis für Listenabfragen" liest.

**Oberfläche.** Liste, Anlegen (Dialog, weil jeder Name — auch `new` — eine Liste sein kann),
Ändern und Löschen. Das Mitgliederfeld nimmt eine Adresse pro Zeile; gesendet wird `members` nur,
wenn sich die Zeilen gegenüber dem Geladenen geändert haben, damit unveränderte Bestandslisten
byteweise erhalten bleiben (U-56). Unbrauchbare Adressen und Mitglieder sind markiert, nicht
entfernt (U-22). Der Hinweis auf den Konfigurationslauf (U-59) steht auf jeder Seite, im
Anlegedialog und in der Löschbestätigung, weil er eine Eigenschaft des Systems ist. Die
Erfolgsmeldung nach dem Schreiben folgt dagegen der Antwort: Trägt sie
`X-Mailadmin-Effect: pending-configuration-run`, nennt die Meldung den Konfigurationslauf, sonst
nicht (U-132).

## Endpunkte

| Methode und Pfad | `operationId` | `x-permission` |
|---|---|---|
| `GET /lists` | `listMailingLists` | `list.read` |
| `POST /lists` | `createMailingList` | `list.write` |
| `GET /lists/{listName}` | `getMailingList` | `list.read` |
| `PATCH /lists/{listName}` | `updateMailingList` | `list.write` |
| `DELETE /lists/{listName}` | `deleteMailingList` | `list.delete` |

Alle fünf deklarieren `403`, weil keine der drei Berechtigungen von jeder Rolle gehalten wird —
ein Vertragstest erzwingt das.

## Zu klärende Punkte

Alle fünf sind entschieden. Was davon **Annahme** bleibt, ist unten ausdrücklich markiert.

1. **Format des Feldes `members`** → **U-56**, und die Frage ist bewusst *nicht* beantwortet,
   sondern folgenlos gemacht: gelesen wird an Komma, Semikolon und Zeilenumbruch, geschrieben
   wird komma-separiert, und **eine unverändert gespeicherte Liste behält ihre Originalbytes**,
   samt `NULL`. Ein Integrationstest fährt das gegen ein echtes MariaDB, für acht
   Spaltenformen.
   **Annahme, nicht geprüft:** dass Komma das Trennzeichen ist, das
   `maillist.sls` beim Rendern der Aliasdatei erwartet. Das ist erst relevant, wenn jemand
   Mitglieder tatsächlich ändert; ein lesender Blick in eine Produktivzeile bestätigt oder
   widerlegt es in einer Minute.
2. **Bedeutung von `tag`** → **U-58**: nicht ermittelbar, also **freies Textfeld** — gespeichert,
   angezeigt, änderbar, an keiner Stelle ausgewertet. Die Oberfläche bietet es als Beschriftung
   an und nicht als Auswahl.
   **Annahme, nicht geprüft:** dass das schreibende Fremdwerkzeug (`maillist.py`) keine Semantik
   voraussetzt, die durch eine freie Eingabe verletzt werden könnte.
3. **Wer darf Listen verwalten?** → **U-58**: **weiterhin Superuser allein.** A5 durfte
   erweitern und hat es nicht getan, mit einem Grund, den S3 nicht kennen konnte: der
   Primärschlüssel ist `name` und damit ein **globaler Namensraum ohne Domainanteil**. Ein
   Zuschnitt über `address` regelt, wer welche Zeile sieht, aber nicht, wer welchen Namen
   belegt.
   Der Weg zur Erweiterung ist derselbe wie bei S3 beschrieben und jetzt zusätzlich
   vorbereitet: die Listenabfrage engt bereits über `Repository::scopedTo()` auf eine
   abgeleitete Spalte `list_domain` ein, und ein Integrationstest fährt diesen Pfad gegen ein
   echtes MariaDB.
4. **Validierung der Mitgliederadressen** → **U-57**: ein **hinzukommender** Eintrag muss eine
   Mailadresse sein, ein **bereits gespeicherter** wird nie geprüft und nie entfernt, sondern in
   `unusable_members` ausgewiesen. Die Strenge ist eine Sicherheitsregel: die Spalte wird in
   eine Aliasdatei gerendert, wo `|kommando` ein Kommando ist.
5. **Hinweis auf den Konfigurationslauf** → **U-59**: jeder zustandsändernde Antwortkopf trägt
   `X-Mailadmin-Effect: pending-configuration-run` — auch das `204` beim Löschen, das keinen
   Körper hat und dessen Verzögerung am meisten überrascht.

## Definition of Done

| Kriterium | Stand |
|---|---|
| Listen lassen sich anlegen, ändern und löschen, ohne dass bestehende Einträge beschädigt werden | **erfüllt** — Unit-, Anwendungs-, Vertrags- und Integrationstest |
| Das Format von `members` entspricht exakt dem, was das Konfigurationsmanagement erwartet | **erfüllt, soweit von hier prüfbar** — unverändert gespeicherte Listen sind byteweise identisch; für geänderte gilt die Annahme aus Punkt 1 |
| Der Hinweis auf den nötigen Konfigurationslauf erscheint nach jeder Änderung | **erfüllt** — `X-Mailadmin-Effect` auf `201`, `200` und `204`; die Oberfläche zeigt ihn auf jeder Listenseite und richtet die Erfolgsmeldung nach dem Kopf (U-132) |
| Oberfläche: Liste, Anlegen, Ändern, Löschen mit `If-Match` | **erfüllt** — nur geänderte Felder, `412` mit Neuladen, Löschbestätigung nennt die weiter zustellende Adresse; jsdom-Tests in `frontend/src/pages/lists/lists.test.tsx` |
| Änderungen sind protokolliert | **erfüllt, vorläufig** — strukturierte Log-Zeilen in den drei schreibenden Anwendungsfällen. **Der Eintrag nach `mailadmin_audit` gehört an genau diese Stellen** und wird von S4 nachgezogen, so wie in `docs/06-uebergabe.md` (4) für die Aufrufstellen in `Application/Auth/` beschrieben |

## Testfälle

| Was | Wo |
|---|---|
| **Unit:** Zerlegen und Zusammensetzen des Mitgliederfelds, verlustfrei | `tests/Unit/Domain/MailingList/MemberListTest.php` — neun Spaltenformen, jede zweimal: einmal gelesen, einmal unverändert zurückgeschrieben |
| **Unit:** Namen, Tags, Adressen; strenge und tolerante Tür | `MailingListNameTest`, `MailingListTest` |
| **Integration:** eine Bestandsliste lesen, unverändert speichern, Vergleich Byte für Byte | `tests/Integration/Persistence/MailingListRepositoryIntegrationTest.php` — gegen ein echtes MariaDB, mit der `MyISAM`/`latin1`-Definition der Bestandstabelle, acht Spaltenformen, `NULL` eingeschlossen |
| **Integration:** Berechtigungsprüfung je nach Antwort auf Punkt 3 | `tests/Contract/MailingListEndpointTest.php` — jede der fünf Operationen als Domainmaster (`403` aus der Middleware, nicht aus A5) und ohne Token (`401`) |
| **Anwendung:** Konflikt, `If-Match`, Mitgliederregel, Sortier-Allowlist | `tests/Application/MailingList/MailingListUseCaseTest.php` |

## Was bewusst nicht getan wurde

- **Keine Migration.** `lists` bleibt, wie es ist. Der für A5 vorgesehene Nummernbereich
  0060–0069 ist unbenutzt und bleibt frei.
- **Kein Umbenennen per `PATCH`.** Der Name ist die Identität (U-60); umbenannt wird durch
  Löschen und Anlegen.
- **Kein `list.not_found`.** Eine fehlende Liste antwortet mit `request.not_found`, genau wie
  eine, die jemand nicht sehen darf. Ein eigener Code wäre der Unterscheider, den die
  403/404-Regel gerade beseitigt (U-44).
- **Keine Bereinigung des Bestands.** Ungewöhnliche Einträge werden markiert, nicht korrigiert
  (U-22).
