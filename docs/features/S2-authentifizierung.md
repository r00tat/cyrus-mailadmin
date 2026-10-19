<!-- markdownlint-disable MD013 MD024 MD060 -->

# S2 — Authentifizierung und Token

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F2, F3, S1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 5 · E-10, E-15 |
| **Status** | **fertig** (2026-08-15) |

---

## Ziel

Anmeldung gegen `adminuser`, Ausstellung eines JWT-Zugriffstokens mit 60 Minuten Laufzeit und
eines rotierenden Erneuerungstokens, Widerruf, Missbrauchsschutz und die Sicherheits-Header.

## Abgrenzung

- Keine Berechtigungsprüfung — das ist **S3**. S2 stellt nur fest, *wer* jemand ist.
- Keine Endnutzer-Anmeldung. Es gibt keinen Selfservice (E-7).

## Abhängigkeiten und geteilte Dateien

Nutzt `PasswordHasher` aus S1. Legt Migrationen an, die mit F3 abzustimmen sind. Die
Sicherheits-Header-Middleware stammt aus F1 und wird hier vervollständigt.

**Was S2 an fremden Dateien angefasst hat**, jeweils mit Begründung:

| Datei | Änderung | Warum |
|---|---|---|
| `src/Http/Dispatcher.php` (F2) | zweite, routennahe Middleware-Kette | U-41: ob eine Anfrage ein Token braucht, weiß erst der Router |
| `config/di/http.php` (F2) | reicht `RouteMiddleware` durch | dieselbe Entscheidung |
| `src/Kernel/Bootstrap.php` (F1) | `container(..., $overrides)` | damit Vertragstests eine Attrappe vor einen Port setzen können; in Produktion leer und nicht konfigurierbar |
| `src/Domain/Password/Password.php` (S1) | `alreadyInUse()` | E-13 hebt einen Altformat-Hash bei der Anmeldung; die heutige Policy darf darüber nicht entscheiden |
| `src/Infrastructure/Persistence/LegacySchema.php`, `Migration/AdditiveOnlyRule.php` (F3) | Ausnahme für Spaltenverbreiterung | U-42; ohne sie passt kein Argon2id-Hash in `adminuser` |
| `bin/db-check.php` (F3) | meldet die Definition beider Passwortspalten | O-16 |
| `migrations/0003_widen_password_columns.sql` | neu, in F3s Nummernbereich | die Migration war laut Fixture-Kommentar „F3/S1 geschuldet" und fehlte |

## Vorgaben

- Zugriffstoken: JWT, EdDSA, 60 Minuten. Ansprüche minimal: `iss`, `aud`, `sub`, `role`,
  `jti`, `iat`, `exp`.
- **Der Domain-Zuschnitt gehört nicht ins Token** — er wird pro Anfrage gelesen (Architektur
  5.3).
- Erneuerungstoken: undurchsichtig, 256 Bit, 12 Stunden absolut, Rotation bei jeder Nutzung,
  Wiederverwendung widerruft die gesamte Familie.
- Im Browser: Zugriffstoken im `localStorage`, Erneuerungstoken im HttpOnly-Cookie mit
  `SameSite=Strict`. **Das Erneuerungstoken darf nicht in den `localStorage`** (E-15,
  Eskalationsfall).
- Widerruf über `mailadmin_token_denylist`, geprüft bei jeder Anfrage.

## Artefakte

Alle vorhanden.

| Vorgesehen | Geworden |
|---|---|
| `POST /auth/token`, `/auth/refresh`, `/auth/logout`, `GET /auth/me` | `openapi/paths/auth.yaml`, `openapi/components/auth.yaml`, vier Handler unter `src/Http/Handler/Auth/` |
| `Infrastructure/Security/TokenIssuer.php`, `TokenVerifier.php` | dazu `SigningKey`, `SigningKeys`, `AccessToken`, `TokenClaims`, `TokenRejected`, `AuthenticationConfiguration` |
| `RefreshTokenStore.php` | Schnittstelle plus `SqlRefreshTokenStore` und `InMemoryRefreshTokenStore`; dazu `RefreshToken`, `RefreshTokenRecord` |
| `RateLimiter.php` | dazu `RateLimitDecision`, `LoginAttemptLog` (+ zwei Implementierungen), `Runtime\Sleeper` |
| `Http/Middleware/Authentication.php` | `AuthenticationMiddleware`, eingehängt über `Http\RouteMiddleware` (U-41) |
| Migrationen `mailadmin_refresh_token`, `_token_denylist`, `_login_attempt` | `0010`, `0011`, `0012` — plus `0003`, siehe oben |
| `bin/prune-tokens` | `bin/prune-tokens.php` |

Nicht vorgesehen und trotzdem entstanden: `Domain/Access/AdminDirectory` mit
`AdminCredentials` (Port für `adminuser`/`domainadmin`, damit die Anwendungsfälle ohne
Datenbank testbar sind), `Runtime\Clock` und `Runtime\Sleeper` (dasselbe für Zeit und Warten),
`Http\ParsedBody`, `Http\ClientAddress`, `Http\RefreshCookie`.

## Zu klärende Punkte

Alle sieben beantwortet; die Festlegungen stehen vollständig in **U-39**, die Formate in
**U-40** bis **U-42**. In Kurzform:

1. **Schlüsselverwaltung.** Seed statt Schlüsselpaar, `kid` abgeleitet statt konfiguriert,
   Rotation über einen reinen Prüfschlüssel. Ein Vorgängerschlüssel gleich dem aktuellen wird
   abgelehnt.
2. **Zeittoleranz.** 30 Sekunden auf `exp` und `iat`.
3. **Schwellen.** Fenster 15 Minuten; je Name ab dem 3. Fehlversuch Verzögerung (gedeckelt bei
   1 s), ab dem 10. Sperre; je Adresse ab 50. Beides, nicht eines von beidem.
4. **Rollenänderung.** Kein `sub` → `jti`-Verzeichnis. Die Rolle im Token wird gegen die
   Datenbank verglichen, Abweichung ist `401`.
5. **Aufräumen.** `bin/prune-tokens.php` per Cron, nicht im Anfragebetrieb.
6. **Deaktivierte Konten.** Kein Statusfeld. Fehlende Zeile oder leerer Zuschnitt ⇒ `401`,
   sofort.
7. **Erneuerungs-Cookie.** `mailadmin_refresh`, Pfad `<Basispfad>/api/v1/auth`, `HttpOnly`,
   `Secure`, `SameSite=Strict`, `Max-Age` aus der Familienfrist.

**Angenommen, nicht entschieden:** dass die Zielinstallation ihre Passwortspalten noch als
`latin1` führt. Migration 0003 schreibt Zeichensatz und Kollation mit; `bin/db-check.php` meldet
den Ist-Zustand. Offener Punkt **O-16**.

## Definition of Done

| Kriterium | Nachweis |
|---|---|
| Anmeldung mit richtigem Passwort liefert Token-Paar; mit falschem `401` als `problem+json` | `AuthEndpointTest::testSigningInAnswersWithASessionAndMatchesTheContract`, `…AWrongPasswordIsAProblemDocumentInTheContractsShape` |
| Ein abgelaufenes Zugriffstoken wird abgelehnt; die Erneuerung liefert ein neues | `AuthenticationMiddlewareTest::testAnExpiredTokenIsRefused`, `RefreshSessionTest::testAValidTokenIsExchangedForANewPair` |
| Ein zweimal verwendetes Erneuerungstoken widerruft die Familie und erzwingt eine Neuanmeldung | `RefreshSessionTest::testAReusedTokenRevokesTheWholeFamily`, `AuthEndpointTest::testASpentRefreshTokenIsReportedAsAReuse` |
| Nach `logout` ist das Zugriffstoken sofort ungültig, nicht erst nach Ablauf | `SignOutTest::testTheAccessTokenIsDeadAtOnce`, `AuthEndpointTest::testTheWholeRoundTripWorksOverTheWire` |
| Ein Altformat-Hash wird bei erfolgreicher Anmeldung auf Argon2id gehoben | `SignInTest::testALegacyHashIsLiftedOnASuccessfulSignIn` (vier Altformate) |
| Alle Header aus Architektur 5.7 sind gesetzt. Die CSP trägt `script-src 'self'` ohne `unsafe-inline` und nirgends `unsafe-eval`; `'unsafe-inline'` steht ausschließlich in `style-src` (U-35, Material UI). Genau diese Aufteilung ist es, die S2 schützt: das Zugriffstoken im `localStorage` ist nur über Skriptausführung erreichbar. | `SecurityHeadersMiddlewareTest` (aus U-35, unverändert gültig) |
| Kein Token und kein Hash erscheint in einem Log | `SigningKeyTest::testARefusalNamesNoPartOfTheKey`, `…testTheSecretHalfIsNotInADebugDump`, `TokenRoundTripTest::testTheTokenStringIsNotInADebugDump`; U-28 maskiert die Kontextfelder |

## Testfälle

| Stufe | Datei | Zahl |
|---|---|---|
| Unit | `SigningKeyTest`, `TokenRoundTripTest`, `RateLimiterTest`, `AuthenticationMiddlewareTest`, `RefreshCookieTest`, `InMemoryTokenDenylistTest` | 67 |
| Anwendungsfall | `SignInTest`, `RefreshSessionTest`, `SignOutTest` | 36 |
| Vertrag | `AuthEndpointTest` — alle vier Endpunkte gegen das Schema, inklusive vollständigem Rundlauf | 10 |
| Integration | `AuthenticationStoresIntegrationTest` — die vier SQL-Implementierungen gegen echtes MariaDB | 17 |

Was der Integrationstest gefunden hat und keine Attrappe hätte finden können: mit
`PDO::ATTR_EMULATE_PREPARES = false` darf ein benannter Platzhalter **nicht zweimal** im selben
Statement stehen. `SqlRefreshTokenStore::consume()` hatte `:at` zweimal; der emulierte Treiber
akzeptiert das, der echte antwortet mit „Invalid parameter number".

## Was S2 nicht getan hat

- **Keine Autorisierung.** `x-permission` wird vom Dispatcher ins Request-Attribut gelegt und
  von der Authentifizierung nur auf `public` geprüft. Wer was darf, entscheidet S3 in der
  Kette hinter der Authentifizierung — der Platz dafür ist da.
- **Kein Revisionsprotokoll.** Anmeldung, Abmeldung und der Wiederverwendungsalarm gehen ins
  strukturierte Log, nicht nach `mailadmin_audit`. Das ist S4; die Aufrufstellen sind die
  jetzigen Log-Zeilen in `SignIn`, `SignOut` und `RefreshSession`.
- **Keine Auswertung von `X-Forwarded-For`.** Der Missbrauchsschutz zählt `REMOTE_ADDR`. Hinter
  einem Reverse Proxy zählt er damit alle auf eine Adresse (O-6) — ein zu grober Schlüssel ist
  besser als ein vom Angreifer setzbarer.
