<!-- markdownlint-disable MD013 MD024 MD060 -->

# F2 — API-Vertrag und Dispatcher

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 4 und 7 |
| **Status** | fertig |

---

## Ziel

`openapi/openapi.yaml` ist die Quelle für Routing und Validierung. Ein Build-Schritt erzeugt
die Routentabelle aus den `operationId`-Werten; eingehende Anfragen werden gegen das Schema
geprüft; Fehler kommen einheitlich als `problem+json` zurück.

## Abgrenzung

- Keine fachlichen Endpunkte — die kommen mit den A-Paketen.
- Keine Durchsetzung von `x-permission` — das ist **S3**. F2 stellt nur sicher, dass die
  Deklaration vorhanden ist und der Build sonst scheitert.
- Keine TypeScript-Generierung — das ist **W1**.

## Abhängigkeiten und geteilte Dateien

`openapi/openapi.yaml` wird von **jedem** A-Paket erweitert. F2 legt Aufbau, Namenskonventionen
und wiederverwendbare Komponenten fest, an die sich alle halten.

## Vorgaben

- Design-first. Das Schema wird von Hand geschrieben, nicht aus Annotationen erzeugt (E-11).
- Fehlt zu einer `operationId` ein Handler oder umgekehrt: Buildfehler.
- Jede Operation trägt `operationId` und `x-permission`.
- Fehlerform einheitlich als wiederverwendbare Komponente, inklusive stabilem `code`-Feld
  (E-16).
- Sortier- und Filterwerte werden im Schema aufgezählt, nie frei übernommen.

## Artefakte

Umgesetzt, mit Abweichungen von der ursprünglichen Aufzählung (jeweils begründet):

| Artefakt | Datei |
|---|---|
| Wurzel des Vertrags | `openapi/openapi.yaml` |
| Gemeinsame Komponenten | `openapi/components/common.yaml` — `Problem`, `ErrorCode`, `PaginationMeta`, `SortOrder`, `Page`/`PerPage`/`SortOrder`/`IfMatch`, `ETag`-Header, 13 Fehlerantworten, `securitySchemes` |
| `/health` | `openapi/paths/health.yaml`, `openapi/components/health.yaml` |
| Konventionen für die Folgepakete | `openapi/README.md` |
| Bündler | `bin/openapi-bundle.php` → `var/cache/openapi.bundled.{yaml,json}` |
| Routenkompilierung | `bin/compile-routes.php` → `var/cache/routes.php` |
| Vertragspipeline | `src/Http/Routing/` — `SpecificationBundler`, `ContractLinter`, `RouteCompiler`, `RouteTable`, `Route`, `ContractArtefacts`, `ContractBuild`, `BasePath`, `DocumentationEndpoint` |
| Dispatcher | `src/Http/Dispatcher.php` |
| Validierung | `src/Http/Middleware/OpenApiValidationMiddleware.php` |
| Handler-Schnittstelle | `src/Http/Handler/ApiHandler.php` |
| Health-Handler | `src/Http/Handler/Health/GetHealthHandler.php` |
| Verdrahtung | `config/di/http.php`, Erweiterungspunkt in `config/di/core.php` |

Abweichungen:

- **`bin/openapi-bundle.php` kam hinzu**, weil der Vertrag nach U-3 verteilt liegt. Ohne
  Bündelschritt gäbe es kein Dokument, gegen das Validator, Kompilierung und Tests arbeiten
  könnten.
- **`src/Http/ProblemResponseFactory.php` ist nicht Teil von F2** — F1 hat sie bereits unter
  `src/Http/Problem/ProblemResponseFactory.php` angelegt. F2 hat nur `ErrorCode` um die Codes
  ergänzt, die es selbst erzeugt.
- **Die Dateien heißen `bin/openapi-bundle.php` und `bin/compile-routes.php`** statt ohne
  Endung, damit PHPStan und PHP-CS-Fixer sie mit ihren Standardmustern erfassen (so schon in F1
  entschieden).

## Zu klärende Punkte — beantwortet

1. **OpenAPI 3.1 oder 3.0?** → **U-2**: 3.0.3. `RouteCompiler` weist ein Dokument mit einer
   anderen Hauptversion ausdrücklich zurück, damit die Festlegung nicht versehentlich
   unterlaufen wird.
2. **Namensschema der Fehlercodes.** → **U-7**: `<ressource>.<zustand>`, `snake_case`,
   erschöpfend im `ErrorCode`-Enum. Drei Vertragstests halten es: Form, erlaubte Präfixe,
   Deckungsgleichheit mit den Konstanten in `src/Http/Problem/ErrorCode.php`.
3. **Wann läuft der Build?** → **U-8**: `composer build`, zusätzlich als `post-install-cmd` und
   `post-update-cmd`. Die kompilierte Tabelle trägt den SHA-256 des Bündels; weicht er ab, wird
   in der Entwicklung neu gebaut und sonst mit `503` und `migration.build_stale` abgelehnt.
4. **Antwortvalidierung in Produktion?** → **U-9**: bestätigt, nur Entwicklung und Test.
   Anfragevalidierung bleibt überall an.
5. **Dokumentation ausliefern?** → **U-9**: außerhalb der Produktion an, in Produktion nur mit
   `MAILADMIN_API_DOCS=1`. **Ohne Redoc** — siehe „Entscheidungen innerhalb des Pakets".
6. **Versionierung.** → **U-10**: `/api/v1` als `servers`-Eintrag; ein späteres v2 läuft
   parallel. Die Routentabelle trägt das Präfix, der Handler-Namensraum nicht. Ein Vertragstest
   verhindert, dass ein Pfad die Version wiederholt.

## Entscheidungen innerhalb des Pakets

- **Kein Redoc unter `/api/docs`.** Redoc rendert über styled-components und schreibt dabei
  `<style>`-Elemente in die Seite; das verlangte ursprünglich `style-src 'unsafe-inline'`, was
  die CSP nicht hergab. **Dieser Grund ist mit U-35 entfallen** — seit der Wahl von Material UI
  erlaubt die Policy Inline-Stile. Die Entscheidung bleibt vorerst bestehen, jetzt allein wegen
  der Abhängigkeitsgröße; neu bewertet unter O-12. Stattdessen: `GET /api/docs` leitet auf
  `GET /api/docs/openapi.json` weiter, das den gebündelten Vertrag ausliefert. Jeder Betrachter
  kann darauf gerichtet werden und läuft dann in seinem eigenen Ursprung.
- **Antwortvalidierung protokolliert, sie ändert die Antwort nicht.** Eine vertragswidrige
  Antwort ist unser Fehler; sie in Entwicklung zu einem `500` zu machen, ließe eine
  Dokumentationslücke wie einen Ausfall aussehen. Erzwungen wird sie in den Vertragstests.
- **Der Dispatcher weigert sich, einen Handler ohne Validierungsmarke aufzurufen.** Wären sich
  Validator und Router über den Mount-Präfix uneinig, liefe sonst eine ungeprüfte Anfrage in
  einen Handler. Der Fehler ist damit laut statt still.
- **`MAILADMIN_ENV=test` darf neu bauen, `prod` nicht.** U-8 nennt nur `dev`; ein Testlauf muss
  aber aus einem frischen Checkout heraus funktionieren. Der Verweigerungspfad bleibt getestet.
- **`x-permission` wird nur auf Anwesenheit geprüft** und in das Request-Attribut
  `Dispatcher::ATTRIBUTE_PERMISSION` gelegt. Durchsetzung ist S3.

## Definition of Done

| Forderung | erfüllt durch |
|---|---|
| Ein Schema mit `/health` erzeugt eine funktionierende Route. | `HealthEndpointTest::testHealthAnswersOkAndMatchesTheContract` |
| Eine Operation ohne Handler bricht den Build mit klarer Meldung ab. | `RouteCompilerTest::testAnOperationWithoutAHandlerFailsTheBuildAndNamesTheExpectedClass` — die Meldung nennt Operation, erwartete Klasse und erwartete Datei |
| Ein Handler ohne Operation ebenso. | `RouteCompilerTest::testAHandlerWithoutAnOperationFailsTheBuild` |
| Eine Operation ohne `x-permission` bricht den Build ab. | `RouteCompilerTest::testAnOperationWithoutXPermissionFailsTheBuild` (plus: leerer Wert zählt als fehlend) |
| Eine schemawidrige Anfrage ergibt `422` mit Feldzuordnung, bevor ein Handler läuft. | `ContractDispatchTest` — vier Fälle: `required`, `maximum`, `type`, `enum` |
| Das Schema validiert gegen die OpenAPI-Spezifikation. | `ContractLinter` im Build (cebe-Objektmodell **und** offizielles OpenAPI-3.0-JSON-Schema), `ContractConventionsTest::testTheBundledContractValidatesAgainstOpenApiThreeZero` |

## Testfälle

**Unit** (`tests/Unit/Http/`)

- `Routing/RouteCompilerTest` — 14 Fälle: Konvention, Präfix aus `servers`, fehlender Handler,
  verwaister Handler, falsche Schnittstelle, fehlende und leere `x-permission`, doppelte
  `operationId`, fehlende `tags`, `operationId` in falscher Schreibweise, OpenAPI 3.1,
  gefährlicher Pfadparameter, Pfadparameter im Routenpfad, Runde durch `var_export`.
- `Routing/SpecificationBundlerTest` — 16 Fälle: Zusammenführung, Kollisionen bei Pfad,
  Komponente und Tag, unerlaubter Schlüssel in einer Teildatei, Umschreibung dateirelativer
  Referenzen, ins Leere zeigende Referenz, Determinismus, Quellfingerabdruck, YAML-Rundlauf
  für leere Listen und Statuscodes.
- `Routing/BasePathTest` — der Mount-Präfix wird abgeschnitten und nichts sonst.
- `DispatcherTest` — Attribute für den Handler, `404`, `405` mit `Allow`, `503` bei veraltetem
  Build, Verweigerung ohne Validierungsmarke, Mount-Präfix, Dokumentationsendpunkt an und aus.

**Vertrag** (`tests/Contract/`)

- `ContractConventionsTest` — 19 Regeln, die für **alle** Folgepakete gelten: Gültigkeit gegen
  OpenAPI 3.0, `operationId`/`tags`/`x-permission` überall, Eindeutigkeit, deklarierte Tags,
  `500` überall, kein `default`, jede Fehlerantwort als `Problem`, Form und Präfixe der
  Fehlercodes, Deckung mit `ErrorCode`, Paginierungsgrenzen, `ETag`/`If-Match`, Bearer-Schema,
  „öffentlich" nur mit `security: []` **und** `x-permission: public`, Vertrag und Routentabelle
  deckungsgleich.
- `HealthEndpointTest` — `/health` durch den echten Container, Antwort gegen das Schema
  validiert; kein Token nötig; die Antwort verrät nichts über die Installation; `/api/docs`.

**Integration** (`tests/Integration/Http/ContractDispatchTest`)

- Die ganze Kette über einen Vertrag, der aus Quelldateien gebaut wird: gültige Anfrage, `422`
  mit `errors`-Abbildung in vier Varianten, **kein eingesandter Wert kommt zurück**, `401` ohne
  Token, `404` als `problem+json`, `400` bei kaputtem JSON, stabiler Prüfsummenlauf, `503` bei
  veraltetem Artefakt, Neubau in der Entwicklung.

## Was andere Pakete von hier übernehmen

- Konventionen: `openapi/README.md`.
- Handler: `Mailadmin\Http\Handler\ApiHandler` implementieren, in
  `src/Http/Handler/{Tag}/{OperationId}Handler.php`.
- Request-Attribute: `Dispatcher::ATTRIBUTE_OPERATION_ID`, `ATTRIBUTE_PERMISSION` (für S3),
  `ATTRIBUTE_PATH_PARAMETERS` sowie jeder Pfadparameter unter seinem eigenen Namen.
- Neue Umgebungsvariable: `MAILADMIN_API_DOCS` (U-9). Gehört in `.env.example` und in die
  Installationsanleitung — beides außerhalb der Hoheit dieses Pakets.
