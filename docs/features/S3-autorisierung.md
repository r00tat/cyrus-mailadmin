<!-- markdownlint-disable MD013 MD024 MD060 -->

# S3 — Autorisierung

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F2, F3, S2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 6 · E-18 |
| **Status** | **fertig** (2026-08-15) |

---

## Ziel

Deny-by-default: keine Operation wird ausgeführt, ohne dass die im Schema deklarierte
Berechtigung geprüft wurde. Domainmaster sehen ausschließlich ihre Domains.

## Abgrenzung

- Keine Rollenverwaltung — das ist **A4**.
- Kein Revisionsprotokoll — das ist **S4**.

## Abhängigkeiten und geteilte Dateien

Baut auf der `x-permission`-Deklaration aus F2 auf. Jedes A-Paket definiert seine
Berechtigungen und Policies nach dem hier festgelegten Muster.

**Was S3 an fremden Dateien angefasst hat**, jeweils mit Begründung:

| Datei | Änderung | Warum |
|---|---|---|
| `src/Http/RouteMiddleware.php` (S2) | zweiter Konstruktorparameter | Der Platz war dafür vorgesehen und dokumentiert (U-41) |
| `src/Http/Routing/RouteCompiler.php` (F2) | prüft `x-permission` gegen die Aufzählung | U-43: F2 prüfte nur die Anwesenheit und verwies für den Rest auf S3 |
| `src/Http/Problem/ErrorCode.php` (F2) | `FORBIDDEN` | Die Middleware soll den Code nicht als Literal schreiben |
| `src/Domain/Exception/DomainErrorCode.php` (F4) | `REQUEST_FORBIDDEN`, `REQUEST_NOT_FOUND` | dieselbe Regel, für die Fachschicht |
| `openapi/components/auth.yaml`, `src/Http/Handler/Auth/GetCurrentSessionHandler.php` (S2) | `permissions` in `CurrentSession` | Architektur 7.2 verlangt sie; ohne S3 gab es nichts zu liefern |
| `openapi/README.md` (F2) | Abschnitt 5 erweitert | Die Regel für neue Berechtigungen gehört dorthin, wo Pakete sie suchen |
| `tests/Unit/Http/Routing/RouteCompilerTest.php`, `tests/Integration/Http/Fixtures/contract/**` (F2) | erfundene Berechtigungen durch echte ersetzt | Der Compiler lehnt `widget.read` jetzt ab — richtigerweise |
| `tests/Contract/AuthEndpointTest.php` (S2) | prüft `permissions` mit | derselbe Vertrag |
| `src/Infrastructure/Persistence/Repository.php` (F3) | `scopedTo()` neben `restrictToDomains()` | U-45: entfernt das `if`, das ein A-Paket vergessen kann |

## Vorgaben

- **Rollenmodell unverändert:** Superuser und Domainmaster (E-18). Rollen werden trotzdem auf
  Berechtigungsmengen abgebildet, nicht direkt geprüft.
- Jeder Anwendungsfall bekommt einen `ActorContext` als ersten Parameter — nicht optional.
- Ressourcenprüfungen in Policy-Objekten.
- **Fremde Objekte ergeben `404`, nicht `403`** — die Existenz wird nicht bestätigt.
- Der Domain-Zuschnitt wird pro Anfrage aus `domainadmin` gelesen, nicht aus dem Token.

## Artefakte

| Vorgesehen | Geworden |
|---|---|
| `Application/ActorContext.php` — lag bereits als `Domain/Access/ActorContext.php` vor | unverändert übernommen, wie das Paketdokument es verlangte |
| Die verbindliche Liste der Berechtigungen | `Domain/Access/Permission.php` (Aufzählung), dazu `PermissionSet` und `RolePermissions` |
| `Domain/Policy/` — `DomainPolicy`, `AccountPolicy`, `AdminPolicy`, `ListPolicy` | alle vier, dazu `AddressPolicy` (A3 braucht eine), `Guard` als gemeinsamer Kern und die beiden Ausnahmen `AccessDenied`, `ResourceNotVisible` |
| `Http/Middleware/Authorization.php` | `AuthorizationMiddleware`, eingehängt in die Kette aus U-41 |
| `Infrastructure/Persistence/DomainScopeRepository.php` | **nicht gebaut** — siehe unten |

**Warum kein `DomainScopeRepository`:** Es hätte gelesen, was `AdminDirectory::findActor()`
ohnehin je Anfrage liest. Zwei Leser derselben Tabelle sind zwei Stellen, die auseinanderlaufen
können.

**Und warum auch kein eigenes Zuschnitt-Werkzeug:** Der erste Entwurf hatte eins
(`DomainScopeCondition`) und ist verworfen worden — F3 setzt dieselbe Regel bereits durch, samt
der wichtigen Hälfte, dass eine leere Menge `1 = 0` ergibt und nicht *gar kein* Prädikat. Zwei
Fassungen dieser Regel wären zwei Stellen, an denen sie repariert werden muss. Geblieben ist eine
Zeile Fassade: `Repository::scopedTo($query, $actor, $column)` (U-45).

Nicht vorgesehen und trotzdem entstanden: `tests/Unit/Domain/LayeringTest.php`, das prüft, dass
kein Fachmodell-Modul aus einer äußeren Schicht importiert. Aufgeschrieben, weil `Domain/Policy/`
die Namensraumfamilie ist, die am ehesten danach greifen würde: eine Policy will einen
Statuscode, und Statuscodes wohnen in `Http`.

## Zu klärende Punkte

Alle sechs beantwortet; die Festlegungen stehen in **U-43** bis **U-45**. In Kurzform:

1. **Berechtigungsliste.** Neun Familien, eine je Paket, Form `<ressource>.<verb>`. Ein Wert
   außerhalb der Aufzählung ist ein **Buildfehler**, kein `403`.
2. **Zwischenspeicherung des Zuschnitts.** Keine. Einmal je Anfrage, dort wo die
   Authentifizierung ihn ohnehin liest — sonst wäre die Begründung aus U-39 hinfällig.
3. **`domainadmin.domain_name = '*'`.** Gilt **auch für einen Domainmaster**. Bereits von S2
   in `SqlAdminDirectory` so entschieden; S3 bestätigt es und prüft es in den Policies mit.
4. **Domainmaster ohne Domain.** Kann nicht handeln: `findActor()` liefert `null` → `401`, der
   Grund ins Log. Ebenfalls schon von S2 entschieden.
5. **Wer liest das Revisionsprotokoll.** Superuser. Ein Protokoll nennt Administratoren über
   Domaingrenzen hinweg; ein Filter darauf wäre ein Filter auf Freitext.
6. **Selbstbezug.** Ja, über `profile.password.write`, die **beide** Rollen halten, und einen
   eigenen Endpunkt (`PUT /admins/me/password`, gebaut von A4). Getrennt von `admin.write`,
   damit die Berechtigung, die jeder hält, nicht an fremde Konten reicht.

**Angenommen, nicht entschieden** — jeweils im Zielpaket markiert:

- **A5:** `list.*` ist vorerst Superuser-Sache. Der Haken zum Einengen auf die Domain der
  Listenadresse ist gebaut und getestet (`ListPolicy::requireVisible()`), wird aber nicht
  aufgerufen. A5 darf erweitern.
- **A3:** Ob ein Alias mit Ziel in einer **fremden lokalen** Domain verboten ist, entscheidet A3
  (dessen Punkt 6). `AddressPolicy::requireDestinationVisible()` steht bereit und wird nicht
  von den übrigen Methoden aufgerufen, weil die Vorgabe sonst jede bestehende
  abteilungsübergreifende Weiterleitung bräche.

## Definition of Done

| Kriterium | Nachweis |
|---|---|
| Eine Operation ohne `x-permission` erreicht keinen Handler | `RouteCompilerTest::testAnOperationWithoutXPermissionFailsTheBuild` (F2, unverändert), dazu neu `…AnXPermissionTheCodeDoesNotKnowFailsTheBuild` — auch ein *falsches* erreicht keinen |
| Ein Domainmaster erhält bei Zugriff auf eine fremde Domain `404`, nicht `403` | `PolicyTest::testAForeignDomainIsNotFoundRatherThanForbidden`, `…testEveryAccountOperationInAForeignDomainIsNotFound` (sechs Operationen), `AuthorizationMiddlewareTest::testTheHiddenObjectAnswersExactlyWhatAnAbsentOneAnswers` — byteweiser Vergleich mit der echten `404` |
| Jede Listenabfrage ist domain-gescoped; es gibt keinen Pfad, der ungefiltert liest | `Repository::scopedTo()`; `RepositoryTest::testReadingTheScopeOffTheActorNarrowsTheSameWay`, `…testASuperuserSeesEverythingWithoutTheCallerWritingAnIf`, dazu F3s `…testAnEmptyDomainScopeShowsNothing` |
| Ein Anwendungsfall lässt sich nicht ohne `ActorContext` aufrufen | Jede Policy-Methode nimmt ihn als ersten Parameter, nicht optional; `LayeringTest` hält die Policies im Fachmodell, wo kein Request hinreicht |
| Aufruf ohne Token `401`, mit gültigem Token ohne Berechtigung `403` | `AuthorizationChainTest` — echte Kette, echtes Ed25519-Token, echter Vertragsvalidator |
| Superuser sieht alles | `AuthorizationMiddlewareTest::testASuperuserPassesEveryPermissionThereIs` (über **alle** Berechtigungen, nicht über eine Auswahl), `RolePermissionsTest::testASuperuserHoldsEveryPermissionThereIs` |

## Testfälle

| Stufe | Datei | Zahl |
|---|---|---|
| Unit | `PermissionTest` (13), `RolePermissionsTest` (26), `PolicyTest` (27), `AuthorizationMiddlewareTest` (15), `LayeringTest` (76 — je eine Datei des Fachmodells) | 157 |
| Vertrag | `PermissionContractTest` — Vertrag und Aufzählung müssen dieselbe API beschreiben | 7 |
| Integration | `AuthorizationChainTest` — 401/403/200 über die echte Kette | 8 |

Dazu zwei Fälle im `RouteCompilerTest` (F2), zwei im `RepositoryTest` (F3) und eine erweiterte
Zusicherung im `AuthEndpointTest` (S2). Insgesamt **176** neue Tests: 1330 → 1506.

Der Vertragstest, der am ehesten in Zukunft anschlägt: **eine Operation, deren Berechtigung
nicht jede Rolle hält, muss eine `403`-Antwort deklarieren.** Ohne ihn würde die
Antwortvalidierung (U-9) die erste echte Ablehnung eines A-Pakets als Vertragsverletzung melden,
und die naheliegende Reparatur wäre, das `403` nicht mehr zu senden.

## Was S3 nicht getan hat

- **Keine Endpunkte.** Es gibt weiterhin nur `/auth/*` und `/health`. Der einzige Ort, an dem
  sich die beiden Rollen heute unterscheiden lassen, ist der Attrappenvertrag der
  Integrationstests — die vier `/auth/*`-Operationen halten beide Rollen.
- **Keine Objektprüfung in der Middleware.** Sie kennt die Operation, nicht das Objekt. Das
  Einengen ist Sache der Policies und von `Repository::scopedTo()`; die Hälfte davon in die
  Middleware zu legen ergäbe eine Prüfung, die vollständig aussieht und es nicht ist.
- **Keine Rollen in der Datenbank.** `adminuser.type` bleibt die Quelle, `domainadmin` der
  Zuschnitt. Beides unverändert (Regel 1).
