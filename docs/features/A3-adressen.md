<!-- markdownlint-disable MD013 MD024 MD060 -->

# A3 — Adressen, Aliase, Catchall, Weiterleitungen

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | A2, M3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 3.4, 8.1, 9.4 |
| **Status** | **umgesetzt** — Backend und Oberfläche vollständig; Zustellung über F5-Postfix nicht prüfbar, ersetzt durch die exakten Postfix-Abfragen (siehe Testfälle) |

---

## Ziel

Alles, was in der Tabelle `virtual` lebt: weitere Adressen eines Kontos, Aliase, Catchall je
Domain, Weiterleitungen nach innen und außen — sowie Abwesenheitsnotizen über Sieve.

## Abgrenzung

- Keine Konten — das ist **A2**. Die Adresszeile eines Kontos (`mailbox_address`) wird angezeigt,
  aber nicht hier gelöscht; sie geht mit dem Konto (U-83).
- Kein Sieve-Protokoll — das ist **M3**. A3 nutzt `SieveStore` und `SieveScriptInstaller`.
- Keine Filterregeln außer der Abwesenheit. Der Regelsatz trägt sie mit (U-102), A3 bietet sie
  nicht an.
- Kein Zeitraum für die Abwesenheit (Punkt 7).

## Abhängigkeiten und geteilte Dateien

Nutzt F4 (`VirtualEntryClassifier`, `VirtualRow`, `DestinationList`, `ReservedLocalPart`,
`MailboxIdentity`), M3 (`SieveRenderer`, `SieveScriptInstaller`, `SieveStore`), S3
(`AddressPolicy`), S4 (`AuditWriter`), A1 (`DomainStore::withNamespaceLock()`, Muster für
Responder und ETag) und A2 (`AccountStore` für `/accounts/{account}/…`).

Angefasste geteilte Dateien:

| Datei | Was |
|---|---|
| `openapi/components/common.yaml` | eigener A3-Block im `ErrorCode`-Enum (zehn Codes) hinter `list.*` |
| `openapi/paths/domains.yaml` | `deleteDomain` verweist bei `domain.still_has_addresses` auf die Adressliste |
| `frontend/src/i18n/de.errors.ts` | deutsche Texte für die zehn Codes; `still_has_addresses` nennt den Weg zur Lösung |
| `src/Domain/Sieve/SieveRenderer.php`, `SieveScript.php` | Modellzeile unter der Markierung, `SieveScript::ruleSet()` (U-102) |
| `src/Domain/Sieve/SieveRuleSetCodec.php` | neu: Regelsatz ↔ base64url-JSON |
| `src/Domain/Policy/AddressPolicy.php` | `requireDeleteIn()` für das Löschen einer einzelnen Zeile ohne Adresse |
| `src/Application/Domain/DomainOperationFailed.php`, `DomainResponder.php` | `still_has_addresses` nennt `GET /domains/{domain}/addresses` |
| `config/di/` | eigene Datei `addresses.php` |
| `src/Application/Account/AccountStore.php`, `CreateAccount.php`, `Infrastructure/Persistence/AccountRepository.php`, `InMemoryAccountStore.php` | ein Benutzername, den eine `virtual`-Zeile nennt oder eine Domain trägt, ist belegt (U-110) |
| `src/Application/Domain/DomainStore.php`, `RenameDomain.php`, `Infrastructure/Persistence/DomainRepository.php`, `InMemoryDomainStore.php` | `senderNameTaken()`; die Umbenennung meidet Platzhalter- und Kontonamen (U-110) |
| `src/Domain/Model/DottedNamespace.php` | `domainNamedLike()` (U-110) |
| `src/Domain/Mailstore/SieveStore.php`, `Infrastructure/Mailstore/Cyrus/CyrusSieveStore.php`, `InMemorySieveStore.php` | `script()` liest ein gespeichertes Skript per Namen (U-113) |
| `src/Infrastructure/Mailstore/SieveScriptInstaller.php`, `SieveOperationFailed.php` | fremdes inaktives Skript unter `sieve` nur mit Übernahme (U-113) |
| `src/Domain/Sieve/VacationRule.php`, `src/Domain/Exception/InvalidSieveRule.php` | kein Steuerzeichen im Betreff (U-114) |

`Permission` und `RolePermissions` **nicht** angefasst: `address.read`, `address.write` und
`address.delete` gehören beiden Rollen. Kein Endpunkt deklariert ein `403`; die Grenze eines
Domainmasters ist die Domain, und die zeigt sich als `404`. Keine Migration.

## Vorgaben

- Die vier Typen aus Architektur 3.4 sind explizit; kein Feldraten im Anwendungscode.
- Ein `virtual`-Datensatz, der in kein Muster passt, wird als unbekannt **angezeigt**, nicht
  verschluckt.
- Abwesenheitsnotizen werden aus dem Modell erzeugt, nie durch Patchen (M3).

## Artefakte

| Artefakt | Datei |
|---|---|
| Vertrag | `openapi/paths/addresses.yaml`, `openapi/components/address.yaml` |
| Endpunkte | `GET /domains/{domain}/addresses`, `DELETE /domains/{domain}/addresses/{entry}`, `POST /domains/{domain}/aliases`, `GET/PATCH/DELETE /aliases/{alias}`, `GET/PUT/DELETE /domains/{domain}/catchall`, `GET/PUT/DELETE /accounts/{account}/forwards`, `GET/PUT/DELETE /accounts/{account}/vacation` |
| Anwendungsfälle | `src/Application/Address/`: `ListDomainAddresses`, `DeleteDomainAddress`, `{Create,Get,Update,Delete}Alias`, `{Get,Set,Delete}Catchall`, `{Get,Set,Delete}Forwards`, `{Get,Set,Delete}Vacation` |
| Regeln und Zustände | `DestinationRules` (U-104), `SenderRules` (U-106), `RowWriter` (U-105), `VacationScripts` (U-102), `EntryKey` (U-103), `LoopGuard` (U-112), `AliasState` (U-111), `CatchallState`, `ForwardState`, `VacationState`, `AddressView` |
| Port + Umsetzung | `Application\Address\AddressStore`, `Infrastructure\Persistence\VirtualRepository`, `InMemoryVirtualStore` |
| Handler | `src/Http/Handler/Addresses/` (fünfzehn Handler, `AddressResponder`, `AddressPath`) |
| Verdrahtung | `config/di/addresses.php` |
| Protokoll | `alias.create`, `alias.update`, `alias.delete`, `address.delete`, `catchall.set`, `catchall.delete`, `forward.set`, `forward.delete`, `vacation.set`, `vacation.delete` (ohne Nachrichtentext, nur `message_length`) |
| Oberfläche | `frontend/src/pages/addresses/`: Adressliste je Domain (`/addresses?domain=…`, alle vier Arten und unbekannte Zeilen mit Grund, Filter nach Art, Einzellöschung jeder Zeile außer der Kontoadresse), Catchall-Karte über der Liste, Alias anlegen (Dialog) und bearbeiten (`/addresses/aliases/{alias}`), Weiterleitung mit den `keep_copy`-Regeln und Abwesenheitsnotiz mit Rückfrage vor dem Übernehmen eines fremden Skripts auf der Kontoseite. Reservierte Adressen (U-109): Löschen und Abschalten erst nach ausdrücklicher Rückfrage, dann mit `confirm_reserved`. Texte in `frontend/src/i18n/de/address.ts`; Tests `*.test.tsx` daneben |

`{alias}` ist die URL-kodierte Adresse (`info%40example.test`), `{account}` der URL-kodierte
gespeicherte Benutzername (U-18), `{entry}` der Fingerabdruck einer Zeile (U-103).

## Zu klärende Punkte

Alle sieben sind beantwortet. Was über A3 hinausreicht, steht als U-Entscheidung.

1. **Mehrere Ziele in `dest`.** → **U-104.** Kommagetrennt ohne Leerzeichen, höchstens 50,
   keine Duplikate, keine Schleife — auch nicht über mehrere Zeilen, Weiterleitungen oder den
   Catchall, aufgelöst wie Postfix (U-112). Kontonamen ohne Unterscheidung der Schreibweise erkannt und in
   gespeicherter Schreibweise geschrieben; ein Name ohne `@`, der kein Konto ist, wird abgelehnt.
   Dass Postfix die Schreibweise liest, belegt der Integrationstest gegen die Abfrage aus
   `mysql-virtual.cf`.
2. **Weiterleitung mit lokaler Kopie.** → **U-107.** Schalter `keep_copy`, gespeichert als der
   eigene Benutzername am Ende der Zielliste. Im `fqun`-Schema ist eine Kopie unvermeidbar:
   `keep_copy: false` ergibt `409 forward.copy_required`. Weiterleitungen nur in `virtual`, nie als
   Sieve-`redirect`.
3. **`status`-Feld.** → **U-108.** Ja: `enabled` je Alias, Catchall und Weiterleitung. Eine
   Adresse, deren Zeilen alle deaktiviert sind, fällt auf den Catchall durch. Zeilen mit
   verschiedenem Status sind nicht gemeinsam bearbeitbar (U-111).
4. **Catchall-Konflikte.** → **U-108.** Die konkrete Adresse gewinnt, solange sie aktiv ist; der
   Vertrag beschreibt das, die Oberfläche soll es erklären. Ein Catchall-Ziel in der eigenen
   Domain muss eine aktive Adresse oder ein Konto sein und bleiben: Löschen oder Deaktivieren
   des Ziels ergibt `409 address.needed_by_catchall` (U-112).
5. **Reservierte Adressen.** → **U-109.** `postmaster` und `abuse` brauchen `confirm_reserved` zum
   Entfernen und Deaktivieren (`409 address.reserved`); Weiterleiten ist frei. Die übrigen
   reservierten Namen werden nur markiert.
6. **Adressen fremder Domains als Alias.** → **U-104**, Annahme von U-44 bestätigt: das Ziel wird
   **nicht** gegen den Zuschnitt geprüft, die Adresse sehr wohl. `requireDestinationVisible()`
   bleibt ungenutzt.
7. **Abwesenheit ohne Zeitraum.** → Kein Zeitraum. Sieve `vacation` kennt keinen, und ein Datum
   bräuchte einen geplanten Lauf, der die Notiz entfernt — den gibt es nicht (M3, Punkt 6), und
   ein Feld, das nichts bewirkt, verspräche etwas Falsches. Nachrüstbar als A6-Aufgabe.

Darüber hinaus festgelegt: **U-102** (Modellzeile, Übernahme fremder Skripte), **U-103**
(Fingerabdruck, Domainzugehörigkeit), **U-105** (Schreibreihenfolge auf MyISAM), **U-106**
(Senderecht). Aus dem Review: **U-110** (Platzhalter- und Domainnamen als Benutzernamen
belegt), **U-111** (Status bei Mischzeilen), **U-112** (Schleifen und Catchall-Ziel),
**U-113** (fremdes inaktives Skript unter `sieve`), **U-114** (Steuerzeichen im Betreff),
**U-126** (ein Schlüssel ohne `@` gehört der Domain des Kontos, das er nennt). Neu
offen: **O-18** (`sender_canonical_maps`). **O-19** (A2s Kontolöschung und der Catchall) ist
durch **U-128** geschlossen: `DeleteAccount` bereinigt den Catchall der eigenen Domain im selben
Datenbankschritt — totes Ziel heraus, ohne verbleibendes Ziel die Zeile gelöscht, Audit als
Kindeintrag `catchall.set`/`catchall.delete`. Es nutzt dafür `CatchallState`,
`AliasRecord::ofCatchall()`, `AddressReader` und `RowWriter` aus A3 (`CatchallCleanup`).
Catchalls anderer Domains bleiben unberührt (**O-20**) — nach **U-129** auch ein Catchall oder
Alias einer anderen Domain, dessen `username` das gelöschte Konto als Absender nennt.

### Nachbesserungen an der Oberfläche (U-133)

- **Weiterleitung:** Wo das Namensschema die Kopie erzwingt (`copy_required`), sendet die Karte
  `keep_copy: true` — den Wert, den der gesperrte Schalter zeigt —, auch wenn die gespeicherte
  Altzeile keine Kopie hat. Vorher wurde sie mit `forward.copy_required` abgelehnt.

### Paket-lokale Annahmen

- **Annahme: Alle `virtual`-Schreibvorgänge laufen unter der Namensraumsperre (U-97)**, die
  Sieve-Schreibvorgänge nicht. Seit U-128 nimmt auch A2s `DeleteAccount` die Sperre für seinen
  Datenbankschritt. Ein Alias, der kurz vor dem Löschen seines Zielkontos angelegt wird, kann
  dessen Namen trotzdem behalten — er ist keine Catchall-Zeile und wird nicht bereinigt. Das ist
  eine Zeile, die Postfix nirgendwohin zustellt (Bounce, kein Verlust), und A6s Abgleich meldet
  sie.
- **Annahme: Eine Adresse ohne Alias-Zeilen ist kein Alias.** `GET /aliases/{alias}` auf eine
  Kontoadresse ist `404`; die Kontoadresse steht in der Adressliste.
- **Annahme: Die Adressliste zeigt auch Zeilen einer gelöschten Domain**, solange `@<domain>`
  passt — genau das braucht der Weg aus `domain.still_has_addresses` und `domain.leftover_state`
  (U-99). Nur ohne jede Zeile und ohne Domain antwortet sie `404`.
- **Annahme: Die Abwesenheit antwortet ohne Angabe für die eigene Adresse des Kontos**, höchstens
  für 20 Adressen; Intervall wie M3 (Vorgabe 7, 1 bis 60 Tage), Text höchstens 4000 Zeichen.
- **Annahme: `DELETE` ohne Gegenstand ist erfolgreich** (Catchall, Weiterleitung, Abwesenheit)
  und protokolliert nichts.

## Was bei einem Fehler geschieht

| Schritt scheitert | Ergebnis |
|---|---|
| Prüfungen (Ziele, Absender, Belegung, Reserviert, `If-Match`) | Ablehnung, nichts geschrieben, kein Protokoll |
| Gelesene Zeilen fehlen inzwischen | `409 address.not_editable`, nichts geschrieben (U-105) |
| Schleife nach der Änderung | `422 address.invalid_destination` mit dem Ziel, das hineinführt; nichts geschrieben (U-112) |
| Catchall verlöre sein Ziel | `409 address.needed_by_catchall`, nichts geschrieben (U-112) |
| Einfügen oder Entfernen einer Zeile | vorherige Zeilenzahl wiederhergestellt, Fehler an den Client |
| Audit nach dem Schreiben (MyISAM) | vorherige Zeilen wiederhergestellt; scheitert das, Reparatureintrag `virtual.restore` |
| Sieve-Server lehnt ab oder ist fort | `503 vacation.mailstore_unavailable`, `422 vacation.rejected`, `409 vacation.not_supported`; nichts geändert |
| Audit nach dem Skriptwechsel | vorheriges Skript zurückgelegt und aktiviert bzw. unseres entfernt; scheitert das, Reparatureintrag `sieve.restore` |
| Aktives Skript ist fremd, oder unter `sieve` liegt ein fremdes inaktives | `409 vacation.foreign_script`, Skript unberührt (U-102, U-113) |
| Audit nach Übernahme eines fremden inaktiven Skripts | das fremde Skript wird unter `sieve` zurückgelegt (U-113) |

## Definition of Done

- [x] Alle vier Typen lassen sich anlegen, ändern, löschen und auflisten — Aliase, Catchall und
      Weiterleitungen über ihre Ressourcen, die Kontoadresse über A2; jede Zeile ist einzeln über
      die Adressliste löschbar.
- [x] Ein unbekannter Bestandseintrag wird angezeigt und nicht beim Speichern zerstört (Typ
      `unknown`, eigene Löschung; Mischzeilen sind nicht gemeinsam bearbeitbar).
- [x] Eine Abwesenheitsnotiz erscheint als gültiges Sieve-Skript und wird wieder entfernt —
      gegen den echten timsieved, einschließlich der Ablehnung eines fremden Skripts.
- [x] Alle Änderungen sind domain-gescoped und protokolliert (fremd = fehlend, byteweise; kein
      Schreiben ohne Protokoll oder Reparatureintrag). Eine Zeile ohne `@` gehört der Domain
      ihres Schlüssels, nicht der ihres Absenders (U-126).
- [x] `domain.still_has_addresses` ist lösbar: Fehlertext und Vertrag nennen die Adressliste,
      deren Einzellöschung jede Zeile erreicht.
- [x] Oberfläche: Adressliste, Catchall, Alias, Weiterleitung und Abwesenheit, mit jsdom-Tests
      für Erfolgsweg, Fehleranzeige, rechteabhängige Sichtbarkeit und bestätigte Zerstörung.

## Testfälle

| Stufe | Datei | Was |
|---|---|---|
| Unit | `tests/Unit/Domain/Sieve/SieveRuleSetCodecTest.php` | Rundweg des ganzen Regelsatzes, base64url in einer Zeile, Modellzeile direkt unter der Markierung, fremdes Skript ohne Regelsatz trotz Modellzeile, Skript mit Markierung ohne Modellzeile ist unlesbar, zehn Fälle unbrauchbarer Kodierung |
| Anwendungsfall | `tests/Application/Address/AliasUseCaseTest.php` | Anlegen mit abgeleitetem, Platzhalter- und ausdrücklichem Absender; belegt (auch deaktiviert); unbrauchbare Ziele (Schleife, Duplikat, Name ohne Konto, Komma, Leerraum, mehr als 50); Platzhalter als Kontoname; fremde und fehlende Domain; **Audit-Fehler setzt zurück**, **Zurücksetzen scheitert → `virtual.restore`**; Lesen über mehrere Zeilen; Ändern schreibt erst ein, dann aus; Absender verliert das Recht; unveränderter Alias schreibt nichts; veraltetes ETag; Mischzeilen nicht bearbeitbar; `postmaster`/`abuse` mit Bestätigung; inzwischen entfernte Zeile wird nicht wiederbelebt; **Zeilen mit verschiedenem Status nicht bearbeitbar** (U-111); **Schleife über einen zweiten Alias, beim Ändern geschlossen, über den Catchall** abgelehnt, Kette bis zum Konto erlaubt; **Löschen/Deaktivieren des Catchall-Ziels** abgelehnt, deaktivierter Catchall hängt von nichts ab (U-112) |
| Anwendungsfall | `tests/Application/Address/DomainAddressesUseCaseTest.php` | Liste mit allen Typen und unbekannten Zeilen, Filter, Sortierung, Blättern, unbekannte Sortierung; fremde/fehlende Domain; Zeilen einer gelöschten Domain; Einzellöschung per Fingerabdruck, auch unbekannter Zeilen; Kontoadresse nicht löschbar; Schlüssel fremder Domain; Fingerabdruck über alle vier Felder; Catchall ohne/mit Absender, Schleifenprüfung, Schreibweise des Bestands, fremde Domain, idempotentes Löschen, Zurücksetzen bei Audit-Fehler; Catchall auf eine deaktivierte Adresse der eigenen Domain abgelehnt; Einzellöschung des Catchall-Ziels abgelehnt (U-112); **Schlüssel ohne `@` eines fremden Kontos erscheint nur in dessen Domain und ist für den Domainmaster des Absenders `404`, Schlüssel ohne Konto sichtbar, aber nur vom Superuser löschbar, Schlüssel zweier Domains braucht beide im Zuschnitt** (U-126) |
| Anwendungsfall | `tests/Application/Address/AccountAddressesUseCaseTest.php` | Weiterleitung mit und ohne Kopie, **`fqun` verlangt Kopie**, Schleife auf das eigene Konto, Umschreiben mehrerer Zeilen mit ETag, idempotentes Löschen, Zurücksetzen bei Audit-Fehler, fremdes/fehlendes Konto; Abwesenheit: kein Skript, Rendern und Zurücklesen, Protokoll ohne Text, **fremdes Skript nur mit Übernahme** (bleibt gespeichert), Löschen eines fremden Skripts nur deaktivierend, unlesbares Skript ersetzt, Zurücknehmen bei Audit-Fehler (neu und geändert), veraltetes ETag, unbrauchbare Adressen, fremder Akteur; **Weiterleitung zwischen zwei Konten ohne Kopie ist Schleife**, mit Kopie nicht (U-112); **fremdes inaktives Skript unter `sieve` nur mit Übernahme**, nach Audit-Fehler zurückgelegt (U-113) |
| Unit | `tests/Unit/Domain/Sieve/VacationRuleTest.php` | sechs Steuerzeichenfälle im Betreff abgelehnt (CRLF mit Header, LF, CR, NUL, Tab, DEL), Umlaute und Anführungszeichen angenommen (U-114) |
| Unit | `tests/Unit/Infrastructure/Mailstore/SieveScriptInstallerTest.php` | fremdes inaktives Skript unter `sieve` weder ersetzt noch gelöscht (U-113) |
| Anwendungsfall | `tests/Application/Account/CreateAccountTest.php`, `tests/Application/Domain/RenameDomainTest.php` | Benutzername als Platzhalter einer `virtual`-Zeile belegt; Punktname gleich einem Domainnamen belegt; Umbenennung auf einen Platzhalter- oder Kontonamen abgelehnt (U-110) |
| Vertrag (Port) | `tests/Support/Mailstore/SieveStoreContract.php` | gespeichertes Skript per Namen lesbar, aktiv oder nicht — gegen die Attrappe und gegen Cyrus (U-113) |
| Integration | `tests/Integration/Persistence/AccountRepositoryIntegrationTest.php`, `DomainRepositoryIntegrationTest.php` | Platzhaltername belegt den Benutzernamen; Platzhalter in jeder Schreibweise gefunden (U-110) |
| Vertrag | `tests/Contract/AddressEndpointTest.php` | alle fünfzehn Operationen durch die echte Kette, Antworten gegen das Schema; `401` für jede; `404` byteweise identisch für fremd und fehlend an allen vierzehn objektbezogenen Operationen ohne jeden Schreibzugriff; `412`, `422` (Sortierung, Ziel, leerer `PATCH`, Textlänge), `409` (belegt, reserviert, Kontoadresse, `copy_required`, `foreign_script`); `Location` mit `%40`; Sortier-Allowlist und `ErrorCode`-Parität; kein `403` im Vertrag |
| Integration | `tests/Integration/Persistence/VirtualRepositoryIntegrationTest.php` | MariaDB mit Altschema (MyISAM, latin1): drei Regeln der Domainzugehörigkeit, **Schlüssel ohne `@` nach dem Konto, das er nennt, in jeder Schreibweise** (U-126), Schreibweise bei Alias und Kontonamen, exaktes Entfernen einer von zwei gleichen Zeilen, `dest = NULL`, latin1-Zeichen; **die Postfix-Abfrage aus `mysql-virtual.cf.tmpl` und der View `validsenders` aus `10-views.sql`**, aus den Dateien gelesen: Alias mit Absender, Platzhalter ohne Senderecht, deaktivierter Alias fällt auf den Catchall durch, Catchall ohne Absender, Weiterleitung über die Kontozeile |
| Integration (F5) | `tests/Integration/Mailstore/VacationIntegrationTest.php` | gegen timsieved in `leer.test`: Notiz setzen, über die Modellzeile zurücklesen, entfernen; **fremdes Skript abgelehnt**, Übernahme deaktiviert es nur; Löschen eines fremden Skripts nur mit Übernahme |

**Warum keine Zustellung über F5-Postfix.** Der Testfall „Alias anlegen, Mail-Zustellung über
Postfix prüfen" ist in F5 nicht durchführbar: Ein Punktname im Ziel (`anna.example.test`) hat
keine Domain, Postfix ergänzt `myorigin`, das nicht in `mydestination` steht, und die Mail geht an
den Relay statt an Cyrus; der direkte Weg über den LMTP-Socket scheitert im Container an den
Dateirechten. Beides ist Eigenschaft der Testumgebung, nicht des Produkts. Ersatz ist der
Integrationstest oben: Er führt **genau die Abfrage** aus, mit der Postfix `virtual_alias_maps`
und `smtpd_sender_login_maps` beantwortet, gegen die Zeilen, die `VirtualRepository` schreibt.
Die Zustellung selbst ist damit dieselbe wie bei jeder Bestandszeile gleicher Form.
