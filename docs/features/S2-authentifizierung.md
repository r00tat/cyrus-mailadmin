<!-- markdownlint-disable MD013 MD024 MD060 -->

# S2 — Authentifizierung und Token

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F2, F3, S1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 5 · E-10, E-15 |
| **Status** | offen |

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

- Endpunkte `POST /auth/token`, `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me`
- `Infrastructure/Security/TokenIssuer.php`, `TokenVerifier.php`
- `Infrastructure/Security/RefreshTokenStore.php` — Hash, Familie, Verbrauch
- `Infrastructure/Security/RateLimiter.php`
- `Http/Middleware/Authentication.php`
- Migrationen: `mailadmin_refresh_token`, `mailadmin_token_denylist`,
  `mailadmin_login_attempt`
- `bin/prune-tokens` — Aufräumen abgelaufener Einträge

## Zu klärende Punkte

1. **Schlüsselverwaltung.** Der Ed25519-Schlüssel kommt aus der Umgebung — in welchem Format?
   Und wie wird er rotiert, ohne alle Sitzungen zu beenden? Vorschlag: `kid` im Token-Kopf und
   zwei gleichzeitig gültige Schlüssel während einer Übergangszeit.
2. **Zeittoleranz.** Wie viele Sekunden Abweichung werden bei `exp` und `iat` akzeptiert?
3. **Schwellen des Missbrauchsschutzes.** Ab wie vielen Fehlversuchen wird verzögert, ab wann
   gesperrt, wie lange? Und wird pro IP, pro Benutzername oder pro Kombination gezählt?
4. **Rollenänderung.** Ändert ein Superuser die Rolle eines Administrators, ist dessen Token
   bis zu 60 Minuten veraltet. Werden die Token dieses Nutzers dann widerrufen? Das erfordert
   eine Zuordnung `sub` → offene `jti`, die es sonst nicht bräuchte.
5. **Aufräumen der Sperrliste.** Cron, oder gelegentlich beim Anfragebetrieb? Ein Verzeichnis
   abgelaufener `jti` wächst sonst unbegrenzt.
6. **Verhalten bei deaktiviertem Konto.** `adminuser` hat kein Statusfeld. Ein gelöschter
   Administrator kann sein Token bis zu 60 Minuten weiterverwenden — wird beim Löschen
   widerrufen?
7. **Format des Erneuerungs-Cookies** und sein Pfad. Eng auf den Erneuerungsendpunkt begrenzen.

## Definition of Done

- Anmeldung mit richtigem Passwort liefert Token-Paar; mit falschem `401` als `problem+json`.
- Ein abgelaufenes Zugriffstoken wird abgelehnt; die Erneuerung liefert ein neues.
- Ein zweimal verwendetes Erneuerungstoken widerruft die Familie und erzwingt eine Neuanmeldung.
- Nach `logout` ist das Zugriffstoken sofort ungültig, nicht erst nach Ablauf.
- Ein Altformat-Hash wird bei erfolgreicher Anmeldung auf Argon2id gehoben.
- Alle Header aus Architektur 5.7 sind gesetzt, die CSP ohne `unsafe-inline` und `unsafe-eval`.
- Kein Token und kein Hash erscheint in einem Log.

## Testfälle

- **Unit:** Token erzeugen und prüfen, abgelaufen, falsche Signatur, falscher `aud`.
- **Unit:** Rotation und Wiederverwendungserkennung des Erneuerungstokens.
- **Integration:** vollständiger Ablauf Anmeldung → Zugriff → Ablauf → Erneuerung → Abmeldung.
- **Integration:** Missbrauchsschutz greift nach der festgelegten Zahl Fehlversuche.
- **Vertrag:** alle vier Endpunkte gegen das Schema.
