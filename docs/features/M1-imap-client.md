<!-- markdownlint-disable MD013 MD024 MD060 -->

# M1 — IMAP-Client

| | |
|---|---|
| **Stufe** | 3 — Mailstore |
| **Hängt ab von** | F1, F4 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 9.2 |
| **Status** | **fertig** — Unit-Tests grün, gegen F5 gefahren |

---

## Ziel

Ein kleiner, getesteter IMAP-Client für Adminkommandos: TLS mit Zertifikatsprüfung, eindeutige
Command-Tags, korrekte Literale, Zeitüberschreitungen, Wiederverwendung der Verbindung.

## Abgrenzung

- Keine Mailstore-Fachlogik — das ist **M2**.
- Kein ManageSieve — das ist **M3**.
- Kein Postfachzugriff für Endnutzer. Nur Administration.

## Abhängigkeiten und geteilte Dateien

Wird von M2 und M3 genutzt. Integrationstests laufen gegen F5.

## Vorgaben

- **`ext-imap` wird nicht verwendet** — ab PHP 8.4 nicht mehr im Kern und für Adminkommandos
  ungeeignet.
- TLS ist Pflicht, mit Zertifikatsprüfung. Kein Schalter zum Abschalten, auch nicht für Tests
  — die Testumgebung bringt eine eigene CA mit.
- Eindeutige Command-Tags statt eines konstanten Präfixes.
- Zeitüberschreitungen auf Verbindungsaufbau und Lesevorgang.
- Fehler des Servers werden zu typisierten Ausnahmen, nicht zu Rückgabewerten.

## Artefakte

- `Infrastructure/Mailstore/Imap/Connection.php`
- `Infrastructure/Mailstore/Imap/CommandTag.php`
- `Infrastructure/Mailstore/Imap/ResponseParser.php`
- `Infrastructure/Mailstore/Imap/Literal.php` — Quoting und Literale
- Ausnahmehierarchie: Verbindungsfehler, Anmeldefehler, Kommandofehler, Zeitüberschreitung
- `Infrastructure/Mailstore/Imap/ConnectionPool.php` — Wiederverwendung je Anfrage

## Zu klärende Punkte

1. ~~**IMAPS oder STARTTLS?**~~ Entschieden: **IMAPS auf 993 als Vorgabe**, `STARTTLS`
   konfigurierbar über `MAILADMIN_IMAP_TLS`. `TlsMode` hat bewusst **keinen** Klartextfall —
   der Schalter, den Befund S-5 beseitigen soll, existiert nicht.
2. **Mindest-Cyrus-Version.** *Annahme:* **3.x**, entwickelt und getestet gegen 3.10.2 (die
   Version des Zielservers, siehe `docker/mail/Dockerfile`). Der Adapter liest `QUOTA` und `ACL`
   über den Tokenizer statt über feste Spalten, ist also gegenüber Formatunterschieden robust;
   eine kleinere Untergrenze wird nicht zugesagt, weil sie nicht geprüft ist.
3. ~~**Zeichensatz für Ordnernamen.**~~ Entschieden: **modifiziertes UTF-7 wird unterstützt**
   (`ModifiedUtf7`, von Hand geschrieben, ohne `mbstring`/`iconv` — E-12). Beim Lesen duldsam:
   ein Name, der nicht dekodiert, kommt unverändert zurück statt zu verschwinden (U-22).
4. **Verbindungsabbruch mitten in einem Stapel.** Weiterhin offen, und **A1s Entscheidung**, nicht
   M1s: der Pool stellt eine abgerissene Sitzung beim nächsten Zugriff neu her, weil auf ihr noch
   nichts gesendet wurde. Ob eine Domainumbenennung nach einem Abbruch fortsetzt oder abbricht,
   entscheidet der Anwendungsfall, der weiß, was halb getan ist.
5. ~~**Proxy-Authentifizierung.**~~ Entschieden: **der Client beherrscht sie**
   (`Connection::authenticateFor()`, SASL PLAIN mit `authzid`). Gegen F5 geprüft. Sie in M2 zu
   verschieben hätte bedeutet, den Mechanismus zweimal zu bauen.
6. ~~**Protokollierung.**~~ Entschieden: `ProtocolTrace`, aus per Vorgabe, über
   `MAILADMIN_IMAP_TRACE` einschaltbar und **in Produktion nicht einschaltbar**. Kommandos mit
   Geheimnissen werden unabhängig davon redigiert; Literale erscheinen nur als `{n}`.

## Definition of Done

Alle fünf Punkte sind erfüllt und durch je einen Test gehalten.

| Zusage | Nachweis |
|---|---|
| Verbindung mit selbstsigniertem Zertifikat gelingt **nur** bei bekannter CA | `CertificateVerificationTest` gegen F5 |
| Ein Mailboxname mit Leerzeichen und Sonderzeichen wird korrekt übertragen | `ImapCommandTest::testAnAwkwardNameSurvivesIntact`, `ModifiedUtf7Test` |
| Eine hängende Verbindung bricht mit einer Ausnahme ab, nicht mit einer Endlosschleife | `ConnectionTest::testAHangingServerEndsInATimeoutAndNotALoop` |
| Mehrere Kommandos laufen über eine Verbindung | `ConnectionTest::testSeveralCommandsShareOneConnectionAndCountUp`, `ConnectionPoolTest` |
| Kein Passwort erscheint in einem Log | `ProtocolTraceTest::testTheAuthenticationExchangeLeavesNoCredentialBehind`, `ImapCommandTest` (beide Wege) |

Dazu, nicht gefordert aber tragend:
`ConnectionTest::testCredentialsAreRefusedOnAnUnencryptedConnection` — die Zeile, die diesen
Client von dem aus Befund S-5 unterscheidet.

## Testfälle

- **Unit:** Quoting und Literale, Antwort-Zerlegung, Tag-Zuordnung.
- **Integration (F5):** Anmeldung, einfaches Kommando, Abmeldung.
- **Integration:** Zertifikatsprüfung schlägt bei unbekannter CA fehl.
- **Integration:** Zeitüberschreitung greift.
