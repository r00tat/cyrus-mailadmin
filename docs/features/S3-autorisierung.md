<!-- markdownlint-disable MD013 MD024 MD060 -->

# S3 — Autorisierung

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F2, F3, S2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 6 · E-18 |
| **Status** | offen |

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

## Vorgaben

- **Rollenmodell unverändert:** Superuser und Domainmaster (E-18). Rollen werden trotzdem auf
  Berechtigungsmengen abgebildet, nicht direkt geprüft.
- Jeder Anwendungsfall bekommt einen `ActorContext` als ersten Parameter — nicht optional.
- Ressourcenprüfungen in Policy-Objekten.
- **Fremde Objekte ergeben `404`, nicht `403`** — die Existenz wird nicht bestätigt.
- Der Domain-Zuschnitt wird pro Anfrage aus `domainadmin` gelesen, nicht aus dem Token.

## Artefakte

- `Application/ActorContext.php`
- `Domain/Policy/` — `DomainPolicy`, `AccountPolicy`, `AdminPolicy`, `ListPolicy`
- `Http/Middleware/Authorization.php` — wertet `x-permission` aus
- `Infrastructure/Persistence/DomainScopeRepository.php`
- Die verbindliche Liste der Berechtigungen, dokumentiert im Schema

## Zu klärende Punkte

1. **Die Berechtigungsliste festlegen.** Vorschlag als Ausgangspunkt: `domain:read`,
   `domain:write`, `domain:delete`, `account:read`, `account:write`, `account:delete`,
   `account:password`, `alias:read`, `alias:write`, `admin:read`, `admin:write`,
   `list:read`, `list:write`, `audit:read`, `maintenance:read`. Vollständig und ohne
   Überschneidung machen.
2. **Zwischenspeicherung des Domain-Zuschnitts.** Pro Anfrage einmal lesen genügt. Über
   Anfragen hinweg zwischenspeichern? Dann greift ein Entzug verzögert — das war gerade der
   Grund, ihn nicht ins Token zu legen.
3. **Bedeutung von `domainadmin.domain_name = '*'`.** Bedeutet das „alle Domains" auch für
   einen Domainmaster, oder nur für Superuser? Im Bestand existieren solche Einträge.
4. **Domainmaster ohne zugeordnete Domain.** Leere Liste oder Fehler bei der Anmeldung?
5. **Wer darf das Revisionsprotokoll lesen?** Superuser alles; Domainmaster nur Einträge zu
   ihren Domains — oder gar nicht?
6. **Selbstbezug.** Darf ein Administrator sein eigenes Passwort ändern, ohne
   `admin:write` zu haben? Vorschlag: ja, über einen eigenen Endpunkt mit eigener
   Berechtigung.

## Definition of Done

- Eine Operation ohne `x-permission` erreicht keinen Handler (Buildfehler aus F2 plus
  Laufzeitprüfung).
- Ein Domainmaster erhält bei Zugriff auf eine fremde Domain `404`, nicht `403`.
- Jede Listenabfrage ist domain-gescoped; es gibt keinen Pfad, der ungefiltert liest.
- Ein Anwendungsfall lässt sich nicht ohne `ActorContext` aufrufen.

## Testfälle

- **Unit:** Policies je Rolle und Zuschnitt, positiv und negativ.
- **Integration:** Domainmaster versucht Zugriff auf fremde Domain, fremdes Konto, fremden
  Alias — jeweils `404`.
- **Integration:** Superuser sieht alles.
- **Integration:** Aufruf ohne Token ergibt `401`, mit gültigem Token ohne Berechtigung `403`.
