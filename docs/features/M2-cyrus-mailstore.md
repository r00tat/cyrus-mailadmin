<!-- markdownlint-disable MD013 MD024 MD060 -->

# M2 — Cyrus-Mailstore-Adapter

| | |
|---|---|
| **Stufe** | 3 — Mailstore |
| **Hängt ab von** | M1, F4 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 9.1 und 9.3 |
| **Status** | offen |

---

## Ziel

Die Schnittstelle `Mailstore` in einer Cyrus-Implementierung plus einer In-Memory-Attrappe, mit
der sich alle Anwendungsfälle ohne laufenden Server testen lassen.

## Abgrenzung

- Kein Sieve — das ist **M3**.
- Keine fachlichen Regeln, wann eine Mailbox angelegt wird — das ist **A2**.

## Abhängigkeiten und geteilte Dateien

Nutzt M1 und `MailboxIdentity` aus F4. Wird von A1, A2 und A6 genutzt.

## Vorgaben

- Schnittstelle nach Architektur 9.1.
- Die Attrappe ist gleichwertig zu testen — sie ist die Grundlage aller Anwendungsfalltests.
- Standardordner werden über SASL-Proxy-Authentifizierung angelegt, **nie** durch Anmeldung mit
  dem Passwort des Nutzers (Architektur 9.3).

## Artefakte

- `Infrastructure/Mailstore/CyrusMailstore.php`
- `Infrastructure/Mailstore/InMemoryMailstore.php`
- `Domain/Mailstore/Mailstore.php` — die Schnittstelle
- `Domain/Mailstore/Quota.php`, `AclSet.php`
- Vertragstestsatz, den beide Implementierungen bestehen müssen

## Zu klärende Punkte

1. **Quota-Semantik.** Cyrus rechnet `STORAGE` in Kilobyte. Was bedeutet `0` — kein Kontingent
   oder Kontingent null? Und wie wird „nicht gesetzt" von „auf null gesetzt" unterschieden?
   Das Altsystem lieferte hier `NOT-SET` als Zeichenkette.
2. **ACL-Satz.** Der Altclient verwendete je nach Serverfähigkeit `lrswipcda` oder
   `lrswipkxtecda`. Welchen Satz setzen wir für den Administrator, welchen für den Eigentümer?
3. **Namensraum-Trennzeichen.** Bei `unixhierarchysep: yes` lautet der Pfad `user/name`. Ist
   das fest vorausgesetzt oder wird es erkannt?
4. **Verhalten bei bereits vorhandener Mailbox.** Fehler, oder als Erfolg werten? Für den
   Wiederholungsfall aus der Reparaturwarteschlange (A6) ist Idempotenz nützlich.
5. **Verzögertes Löschen.** Cyrus verschiebt gelöschte Mailboxen in einen `DELETED`-Namensraum
   und räumt sie später ab. Muss der Abgleich in A6 diese ausblenden? Und gilt eine Mailbox
   nach `DELETE` sofort als weg?
6. **Umbenennen über Domaingrenzen** — beim Wechsel des Namensschemas (E-14) wechselt eine
   Mailbox zwischen den Namensräumen. Beherrscht `RENAME` das in einem Schritt?
7. **Standardordner:** feste Liste, oder aus `domain.folders` wie im Altsystem? Und werden sie
   abonniert oder nur angelegt?

## Definition of Done

- Beide Implementierungen bestehen denselben Vertragstestsatz.
- Anlegen, ACL setzen, Quota setzen, lesen, umbenennen und löschen funktionieren gegen F5.
- Standardordner werden ohne Kenntnis des Nutzerpassworts angelegt.
- Ein Fehler des Servers führt zu einer typisierten Ausnahme mit verwertbarer Meldung.

## Testfälle

- **Vertrag:** derselbe Satz gegen `InMemoryMailstore` und `CyrusMailstore`.
- **Integration (F5):** vollständiger Lebenszyklus einer Mailbox.
- **Integration:** Quota setzen und wieder lesen, einschließlich „nicht gesetzt".
- **Integration:** Anlegen einer bereits vorhandenen Mailbox verhält sich wie festgelegt.
