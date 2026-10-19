<!-- markdownlint-disable MD013 MD024 MD060 -->

# M2 — Cyrus-Mailstore-Adapter

| | |
|---|---|
| **Stufe** | 3 — Mailstore |
| **Hängt ab von** | M1, F4 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 9.1 und 9.3 |
| **Status** | **fertig** — Vertragstestsatz gegen Attrappe **und** echten Cyrus grün |

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

Alle sieben sind entschieden. Was gegen einen echten Cyrus 3.10.2 **gemessen** wurde, statt
angenommen, steht in [U-38](../05-umsetzungsentscheidungen.md).

1. ~~**Quota-Semantik.**~~ Entschieden: kein Quota-Root heißt `Quota::unlimited()` — „kein
   Kontingent konfiguriert", eine andere Tatsache als ein Kontingent von null, und das
   Wertobjekt hält beides auseinander (U-32 d). Ein unbegrenztes Kontingent wird als
   `SETQUOTA … ()` gesendet und entfernt den Quota-Root; `SETQUOTA … (STORAGE 0)` wäre ein
   Kontingent von null Byte und sperrte das Konto aus seiner eigenen Mail aus. Das Altsystem
   hat beides zu `NOT-SET` verschmolzen.
2. ~~**ACL-Satz.**~~ Entschieden: **`lrswipkxtecda`** (`Acl::fullControl()`) für Administrator
   wie Eigentümer, weil Cyrus 3.x die aufgeteilten Rechte kennt. `legacyFullControl()`
   (`lrswipcda`) bleibt zum **Lesen** von Bestandswerten und wird nie gesetzt.
3. ~~**Namensraum-Trennzeichen.**~~ Entschieden: **erkannt, nicht vorausgesetzt**. Der Adapter
   fragt `NAMESPACE` beim ersten Kommando und **verweigert den Dienst**, wenn der Trenner nicht
   `/` ist. Mit `.` wäre `user.greta.mustermann.example.at` mehrdeutig, und keine Sorgfalt
   weiter unten kann das reparieren.
4. ~~**Bereits vorhandene Mailbox.**~~ Entschieden: **Fehler, nicht Erfolg.** Eine vorhandene
   Mailbox zu übernehmen hieße, einem neuen Konto zu geben, was der vorige Eigentümer darin
   gelassen hat — genau die Waise, die U-22 *zeigen* und nicht aufsaugen will. Wer Idempotenz
   braucht (A6), fragt vorher `mailboxExists()` und sagt es damit. Das **Löschen** ist dagegen
   idempotent: die Nachbedingung gilt so oder so.
5. ~~**Verzögertes Löschen.**~~ Entschieden: der Adapter filtert `DELETED/…` aus jeder
   Auflistung, A6 sieht diese Mailboxen also nicht. Nach `DELETE` gilt eine Mailbox sofort als
   weg, weil der Name sofort frei ist. Nebenbefund mit Folgen: zwei Löschvorgänge derselben
   Mailbox innerhalb **einer Sekunde** kollidieren auf dem `DELETED`-Ziel und der zweite wird
   abgelehnt — `MailboxOperationFailed::deletionRefused()` ist deshalb als einziger Fehler
   dieser Klasse wiederholbar.
6. ~~**Umbenennen über Domaingrenzen.**~~ **Beantwortet: ja, in einem Schritt, Ordner
   inbegriffen** — aber nur mit `allowusermoves: yes`, das Cyrus ab Werk **aus** hat. Ohne die
   Einstellung scheitert jedes Umbenennen eines Kontos. Als Betriebsvoraussetzung **O-15**
   aufgenommen; der Adapter meldet den Fall mit einer Meldung, die die Einstellung nennt, statt
   ihn als „dieses Konto lässt sich nicht umbenennen" auszugeben.
7. ~~**Standardordner.**~~ Entschieden: die Liste kommt vom Aufrufer (`DefaultFolders`, das
   `domain.folders` lesen kann), und sie werden **angelegt und abonniert**. Ein nicht
   abonnierter Ordner ist in den meisten Clients unsichtbar, also täte das Anlegen allein
   nichts Sichtbares. Das Altsystem hat ebenfalls abonniert.

## Definition of Done

| Zusage | Nachweis |
|---|---|
| Beide Implementierungen bestehen denselben Vertragstestsatz | `tests/Support/Mailstore/MailstoreContract.php`, gefahren von `InMemoryMailstoreTest` (Unit) **und** `CyrusMailstoreIntegrationTest` (gegen F5). Jeder Fall läuft unter **beiden** Namensschemata |
| Anlegen, ACL, Quota, Lesen, Umbenennen, Löschen gegen F5 | dieselbe Integrationsklasse, 48 Fälle grün |
| Standardordner ohne Kenntnis des Nutzerpassworts | `testFoldersAreCreatedForAUserWhosePasswordNobodyKnows` — die Fixture-Passwörter sind gehasht, es *gäbe* keines |
| Serverfehler wird typisierte Ausnahme mit verwertbarer Meldung | `MailboxOperationFailed`, `CyrusMailstoreTest` (Draht) und die Vertragsfälle (Verhalten) |

## Testfälle

- **Vertrag:** derselbe Satz gegen `InMemoryMailstore` und `CyrusMailstore`, je Namensschema.
- **Draht (Unit, mit Skript-Transport):** die Entscheidungen, die im Verhaltenstest unsichtbar
  sind, weil richtige und falsche Variante beide `OK` ergeben — `SETQUOTA … ()` gegen
  `(STORAGE 0)`, `DELETEACL` gegen `SETACL … ""`, der `DELETED/`-Filter, der relative
  Ordnername bei Proxy-Auth.
- **Integration (F5):** vollständiger Lebenszyklus, Quota einschließlich „nicht gesetzt",
  Anlegen einer vorhandenen Mailbox, sowie **O-8**: der `fqun`-Ordnername, den Cyrus wirklich
  vergibt.
