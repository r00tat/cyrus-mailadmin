<!-- markdownlint-disable MD013 MD024 MD060 -->

# Der API-Vertrag

`openapi/` ist die **einzige Wahrheit** für Routen, Validierung, Frontend-Typen, Dokumentation
und Tests (`CLAUDE.md`, Regel 3). Eine Änderung beginnt hier, nie im Handler.

Dieses Dokument ist die Anleitung für die acht Pakete, die den Vertrag noch erweitern
(S2, S4, A1–A6). Wer sich daran hält, kann parallel zu allen anderen arbeiten, ohne je dieselbe
Datei anzufassen.

---

## 1. Dateiablage

Der Vertrag ist **ein logisches Dokument in mehreren Dateien** (U-3):

```text
openapi/
  openapi.yaml              Wurzel: openapi, info, servers, security. Gehört F2.
  paths/<gruppe>.yaml       Endpunkte einer Gruppe + die Tags, die sie einführt
  components/<paket>.yaml   Schemata, Parameter, Header, Antworten des Pakets
```

| Paket | eigene Dateien |
|---|---|
| F2 | `paths/health.yaml`, `components/health.yaml`, `components/common.yaml` |
| S2 | `paths/auth.yaml`, `components/auth.yaml` |
| S4 | `paths/audit.yaml`, `components/audit.yaml` |
| A1 | `paths/domains.yaml`, `components/domain.yaml` |
| A2 | `paths/accounts.yaml`, `components/account.yaml` |
| A3 | `paths/addresses.yaml`, `components/address.yaml` |
| A4 | `paths/admins.yaml`, `components/admin.yaml` |
| A5 | `paths/lists.yaml`, `components/list.yaml` |
| A6 | `paths/maintenance.yaml`, `components/maintenance.yaml` |
| W2 | `paths/preferences.yaml`, `components/preferences.yaml` (Tag `admins`, benutzt) |

**Ein Paket ändert `openapi.yaml` nicht** und ändert `components/common.yaml` nur, um seinen
Block im `ErrorCode`-Enum zu ergänzen. Alles andere gehört in die eigenen Dateien.

Eine Teildatei darf ausschließlich `paths`, `components` und `tags` auf oberster Ebene tragen.
Jeder andere Schlüssel bricht den Build.

`bin/openapi-bundle.php` fügt alles zu `var/cache/openapi.bundled.yaml` zusammen. **Jeder
Konsument liest das Bündel, nie die Quellen.** Zwei Dateien, die denselben Pfad, denselben
Komponentennamen oder denselben Tag deklarieren, sind ein Fehler mit Nennung beider Dateien —
kein stilles Überschreiben.

---

## 2. `operationId`

- Pflicht, eindeutig im ganzen Vertrag, `lowerCamelCase` (nur Buchstaben und Ziffern).
- Verb + Ressource: `listDomains`, `getDomain`, `createDomain`, `updateDomain`, `deleteDomain`,
  `setAccountPassword`.
- Die `operationId` **benennt die Handler-Klasse** (siehe 4) und in W1 die Methode des
  generierten Clients. Sie umzubenennen ist eine brechende Änderung.

## 3. `tags`

- Pflicht, mindestens einer. **Der erste Tag entscheidet den Namensraum des Handlers.**
- Kleinbuchstaben, Ziffern, Bindestriche: `domains`, `accounts`, `mailing-lists`.
- Die Datei, die einen Tag **einführt**, deklariert ihn auf oberster Ebene mit `description`.
  Jede weitere Datei benutzt ihn nur. Eine zweite Deklaration bricht den Build.

## 4. Handler-Zuordnung (U-4)

Es gibt keine Registry. Die Zuordnung ist Konvention und wird zur Bauzeit in **beide** Richtungen
geprüft:

```text
Mailadmin\Http\Handler\{UpperCamel(erster tag)}\{UpperCamel(operationId)}Handler

listDomains        + tags: [domains]        → Handler\Domains\ListDomainsHandler
setAccountPassword + tags: [accounts]       → Handler\Accounts\SetAccountPasswordHandler
```

Der Build bricht ab, wenn zu einer Operation die Klasse fehlt, wenn eine Handler-Klasse zu keiner
Operation gehört, oder wenn die Klasse `Mailadmin\Http\Handler\ApiHandler` nicht implementiert.

Gescannt wird nur die **erste Ebene** unter `src/Http/Handler/`, also genau ein Verzeichnis je
Tag. Auf der obersten Ebene liegt nur die Schnittstelle.

## 5. `x-permission`

Pflicht auf jeder Operation, auch auf öffentlichen. Ohne sie bricht der Build ab — eine
vergessene Deklaration würde sonst „erreichbar" bedeuten.

- Form `<ressource>.<verb>`: `domain.read`, `domain.write`, `account.password.write`.
- **Der Wert muss ein Fall von `Mailadmin\Domain\Access\Permission` sein.** Das ist seit S3 die
  verbindliche Liste; `RouteCompiler` übersetzt einen unbekannten Wert in einen Buildfehler.
  Wer eine neue Berechtigung braucht, trägt sie dort in der Familie seines Pakets ein
  (`domain.*` gehört A1, `account.*` A2, `address.*` A3, `admin.*` A4, `list.*` A5,
  `maintenance.*` A6) und ordnet sie in `RolePermissions` einer Rolle zu — sonst hat sie
  niemand.
- Öffentlich ist `x-permission: public`, **zusammen mit** `security: []`. Das eine ohne das
  andere ist ein Testfehler.
- Durchgesetzt wird sie in **S3** von `AuthorizationMiddleware`, die den Wert aus dem
  Request-Attribut `Dispatcher::ATTRIBUTE_PERMISSION` liest.
- **Eine Operation, deren Berechtigung nicht jede Rolle hält, muss eine `403`-Antwort
  deklarieren.** Ein Vertragstest erzwingt das: sonst würde die Antwortvalidierung (U-9) die
  einzige Ablehnung beanstanden, die die Operation tatsächlich erzeugen kann.

## 6. Fehlercodes (U-7)

- Form `<ressource>.<zustand>`, beides `snake_case`: `domain.not_found`,
  `account.quota_exceeded`.
- Erlaubte Präfixe: `auth`, `domain`, `account`, `address`, `alias`, `catchall`, `forward`,
  `vacation`, `admin`, `list`, `quota`, `mailstore`, `sieve`, `migration`, `repair`,
  `request`. Querschnittscodes ohne Ressource tragen `request`. `repair` kam mit A6 hinzu
  (U-122).
- **Jeder Code steht im `enum` von `ErrorCode`** in `components/common.yaml`. Das Enum ist
  erschöpfend: ein Code, der nicht darin steht, ist ein Vertragsbruch und fällt im Test auf. Es
  ist zugleich die vollständige Liste der Übersetzungsschlüssel, die das Frontend abdecken muss
  (E-16).
- Ein Paket hängt seinen **eigenen Block** an, alphabetisch innerhalb des Blocks, mit einer
  Kommentarzeile davor. So kollidieren zwei Pakete, die gleichzeitig anhängen, nicht.
- Codes, die die Middleware selbst erzeugt, stehen zusätzlich als Konstante in
  `src/Http/Problem/ErrorCode.php`. Ein Test hält beide Listen deckungsgleich.

## 7. Wiederverwendbare Komponenten

Aus `components/common.yaml`, per internem Zeiger (`$ref: '#/components/schemas/Problem'`):

| Komponente | wofür |
|---|---|
| `schemas/Problem` | jede Fehlerantwort, RFC 9457 mit `code` |
| `schemas/ErrorCode` | die erschöpfende Codeliste |
| `schemas/PaginationMeta` | `meta` jeder Sammelantwort (U-20) |
| `schemas/SortOrder` | `asc` / `desc` |
| `parameters/Page`, `parameters/PerPage` | Paginierung: Vorgabe 50, Höchstwert 200 (U-20) |
| `parameters/SortOrder` | Richtung; das Feld ist ressourcenspezifisch |
| `parameters/IfMatch` | Nebenläufigkeitsschutz (U-19) |
| `headers/ETag`, `headers/XRequestId`, `headers/Location`, `headers/Allow` | Antwortköpfe |
| `responses/*` | `BadRequest`, `Unauthorized`, `Forbidden`, `NotFound`, `MethodNotAllowed`, `Conflict`, `UnsupportedMediaType`, `PreconditionFailed`, `PreconditionRequired`, `UnprocessableEntity`, `TooManyRequests`, `InternalServerError`, `ServiceUnavailable` |

Referenzen sind **intern** (`#/components/…`). Dateirelative Referenzen
(`../components/domain.yaml#/components/schemas/Domain`) funktionieren auch und werden beim
Bündeln umgeschrieben — interne sind kürzer und können nicht auf eine Datei zeigen, die es nicht
gibt. Ein Zeiger, der ins Leere geht, bricht den Build.

Eine eigene Komponente bekommt einen Namen, der sie einem Paket zuordnet (`Domain`,
`DomainListItem`, `AccountPasswordRequest`). `Item`, `Request` oder `Response` allein sind zu
gefährlich nah an dem, was ein anderes Paket auch nennen würde.

## 8. Regeln für Sammelantworten, Sortierung und Filter

- Paginierung über `parameters/Page` und `parameters/PerPage`. Die Antwort trägt
  `{items: [...], meta: {page, per_page, total}}`.
- **Sortier- und Filterwerte sind im Schema aufgezählt** — ein `enum` je Ressource für `sort`,
  Richtung über `parameters/SortOrder`. Das ist Regel zwei aus `CLAUDE.md` in Vertragsform: das
  Altsystem interpolierte `ORDER BY` aus der Session direkt ins SQL. Ein freier String an dieser
  Stelle ist ein Codereview-Stopp.
- Ein Bestandsdatensatz, der nicht ins Fachmodell passt, bekommt einen eigenen Zustandswert im
  Enum (`unknown`) und wird angezeigt, nicht weggefiltert (U-22).

## 9. Statuscodes und Antworten

- `201` mit `Location` beim Anlegen, `204` ohne Körper beim Löschen, `409` bei fachlichen
  Konflikten, `422` bei Schema- oder Regelverstößen, `412` bei `If-Match`-Konflikt, `428` wenn
  `If-Match` gefordert, aber nicht gesendet wurde.
- Jede Operation zählt **genau die Status auf, die sie erzeugen kann**, und immer `500`.
- **Kein `default`-Response.** Ein nicht deklarierter Status fällt sonst der Antwortvalidierung
  (U-9) nicht auf, sondern wird stillschweigend akzeptiert.
- `403` heißt „diese Operation ist tabu". Ein Objekt, das der Akteur nicht sehen darf, ergibt
  `404`, nicht `403` (`CLAUDE.md`).

## 10. Sicherheit

`security: [bearerAuth]` gilt global. Eine Operation, die ohne Token erreichbar sein soll,
schreibt `security: []` — und trägt dann `x-permission: public`. Das Erneuerungstoken kommt nie
über diesen Weg: es bleibt im HttpOnly-Cookie und wird nur von `POST /auth/refresh` gelesen
(E-15).

---

## 11. Ablauf

```bash
composer build          # bündelt, prüft gegen OpenAPI 3.0, kompiliert die Routen
composer test           # unit, application, contract, integration
```

`composer build` läuft zusätzlich als `post-install-cmd` und `post-update-cmd`, damit ein
Deployment ihn nicht vergessen kann. Die kompilierte Routentabelle trägt den SHA-256 des Bündels;
weicht er zur Laufzeit ab, wird in der Entwicklung neu gebaut und sonst der Dienst mit `503` und
`migration.build_stale` verweigert (U-8).

Erzeugte Dateien unter `var/cache/` — nicht von Hand bearbeiten, nicht eingecheckt:

| Datei | wofür |
|---|---|
| `openapi.bundled.yaml` | der zusammengefügte Vertrag; Grundlage der Prüfsumme, Eingabe für `openapi-typescript` (W1) |
| `openapi.bundled.json` | dasselbe als JSON: Laufzeitvalidierung und `/api/docs/openapi.json` |
| `routes.php` | die kompilierte Routentabelle mit beiden Prüfsummen |

## 12. Dokumentation ansehen

`GET /api/docs` leitet auf `GET /api/docs/openapi.json` weiter; beides ist außerhalb der
Produktion an und in Produktion nur mit `MAILADMIN_API_DOCS=1` (U-9).

**Redoc wird nicht mitgeliefert.** Ursprünglich war der Grund die CSP — Redoc rendert über
styled-components und hätte `style-src 'unsafe-inline'` gebraucht. **Dieses Argument ist mit
U-35 entfallen**, seit Material UI dieselbe Aufweichung ohnehin verlangt. Es bleibt der zweite
Grund: ein Megabyte gebündeltes JavaScript im Repository für einen Betrachter, den man auch auf
`/api/docs/openapi.json` richten kann. Ob das trägt, entscheidet der Projekteigner unter
**O-12**. Wer heute eine gerenderte Ansicht will, richtet einen Betrachter seiner Wahl auf
`/api/docs/openapi.json` — der läuft dann in seinem eigenen Ursprung mit seiner eigenen Policy.

---

## 13. Checkliste vor dem Commit

- [ ] Neue Endpunkte nur in `paths/<gruppe>.yaml`, neue Schemata nur in `components/<paket>.yaml`
- [ ] Jede Operation: `operationId`, `tags`, `x-permission`, `500`, keine `default`-Antwort
- [ ] Handler-Klasse existiert und heißt nach der Konvention
- [ ] Neue Fehlercodes im `ErrorCode`-Enum, im eigenen Block, alphabetisch
- [ ] Sortier- und Filterwerte als `enum`, nicht als freier String
- [ ] `Problem`, `PaginationMeta`, `IfMatch` wiederverwendet statt nachgebaut
- [ ] `composer build && composer test && composer lint && composer analyse` sind grün
