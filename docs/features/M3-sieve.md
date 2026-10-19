<!-- markdownlint-disable MD013 MD024 MD060 -->

# M3 — ManageSieve und Sieve-Modell

| | |
|---|---|
| **Stufe** | 3 — Mailstore |
| **Hängt ab von** | M1, F4 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 9.4 |
| **Status** | umgesetzt |

---

## Ziel

Sieve-Skripte werden aus einem strukturierten Modell vollständig erzeugt und über ManageSieve
abgelegt. Fremde Skripte werden erkannt und nicht überschrieben.

## Abgrenzung

- Keine fachliche Abwesenheitslogik — das ist **A3**.
- Kein Sieve-Parser für beliebige Skripte. Wir erzeugen, wir lesen nicht zurück.

## Abhängigkeiten und geteilte Dateien

Nutzt M1. Wird von A3 genutzt.

## Vorgaben

- **Erzeugen statt patchen.** Das Altsystem schob Abwesenheit und Weiterleitung per regulärem
  Ausdruck ineinander — das ist die Fehlerquelle, die hier verschwindet.
- Erzeugte Skripte tragen eine Markierung im Kopf.
- Ein aktives Skript **ohne** Markierung wird nicht überschrieben, sondern schreibgeschützt
  angezeigt; die Übernahme ist ausdrücklich zu bestätigen.

## Artefakte

| Datei | wofür |
|---|---|
| `Infrastructure/Mailstore/Sieve/ManageSieveConnection.php` | RFC 5804 über M1s `Transport`: Begrüßung, `STARTTLS`, SASL mit `authzid`, Kommandos, Literale |
| `Infrastructure/Mailstore/Sieve/ManageSieveConnectionPool.php` | eine Sitzung je Autorisierungsidentität, Lebensdauer eine Anfrage |
| `Infrastructure/Mailstore/Sieve/SieveLine.php`, `SieveResponse.php` | Tokenisierung und Antwortcode; hält Zeichenketten und Atome auseinander |
| `Infrastructure/Mailstore/Sieve/SieveCommandFailed.php` | `NO`/`BYE` mit Antwortcode, ohne die Prosa des Servers zu deuten |
| `Infrastructure/Mailstore/Sieve/SieveTrace.php` | Mitschnitt nach M1s Regeln; ein Literal erscheint als Länge, nie als Inhalt |
| `Infrastructure/Mailstore/Sieve/SieveConfiguration.php` | nur der Port ist eigen (U-66) |
| `Infrastructure/Mailstore/Sieve/SieveCapabilities.php` | die Erweiterungen des Servers, gefragt vor dem Schreiben |
| `Infrastructure/Mailstore/Cyrus/CyrusSieveStore.php` | der Adapter |
| `Infrastructure/Mailstore/SieveScriptInstaller.php` | die Übernahmesperre und die Erweiterungsprüfung (U-67) |
| `config/di/sieve.php` | Verdrahtung |

Bereits aus F4/M2 vorhanden und unverändert übernommen: `Domain/Sieve/SieveRuleSet.php`,
`SieveRenderer.php`, `VacationRule.php`, `RedirectRule.php`, `SieveScript.php` (Markierung und
„fremdes Skript"), `Domain/Mailstore/SieveStore.php`, `InMemorySieveStore.php`,
`SieveOperationFailed.php`. Am Port kam `deactivate()` hinzu (U-68).

## Zu klärende Punkte

Alle sechs sind entschieden; die Begründungen stehen in
[U-69](../05-umsetzungsentscheidungen.md). Hier nur das Ergebnis, jeweils als **Annahme**
gekennzeichnet, wo sie A3 noch betrifft.

1. **Skriptname.** `sieve` bleibt — nur so wird ein vorhandenes webcyradm-Skript gefunden.
   Unterschieden wird über die Markierung im Kopf, nicht über den Namen.
2. **Erweiterungen.** Nur, was die Regelmenge selbst deklariert (`vacation`, bei einem
   einsortierenden Filter `fileinto`). Die Fähigkeiten werden beim Verbinden gelesen und **vor**
   dem Schreiben verglichen.
3. **`:days`.** Teil der Regel, Vorgabe 7, Obergrenze 60. *Annahme für A3:* die Oberfläche bietet
   den Wert an; eine 1 wird nicht voreingestellt.
4. **Zeichensatz.** Durchgehend Oktette, kein `iconv`. Gegen den echten Server byteweise geprüft.
5. **Mehrere Skripte.** Ein verwaltetes Skript; fremde Skripte werden aufgezählt und nicht
   angefasst.
6. **Zeitraum.** Nicht in M3 — Sieve `vacation` kennt keinen. *Annahme:* A3 entscheidet, ob ein
   Datum verwaltet wird; M3 liefert keinen geplanten Lauf und tut nicht so, als gäbe es einen.

**Weitere Annahmen dieses Pakets**

- Der Adapter liegt unter `Infrastructure/Mailstore/Cyrus/CyrusSieveStore.php` statt direkt unter
  `Infrastructure/Mailstore/`, wie die Artefaktliste unten es schreibt — dort liegt bereits
  `Cyrus/CyrusMailstore.php`, und die beiden Cyrus-Adapter gehören nebeneinander.
- Der Verbindungspool wird, wie der von M1, **nicht** vom Front-Controller geschlossen; das
  `finally`, das `ConnectionPool::closeAll()` aufrufen soll, existiert noch nicht. M3 erbt diese
  Lücke bewusst, statt sie einseitig für die eigene Hälfte zu schließen.
- **Wer ein Konto löscht, beendet vorher dessen Sieve-Sitzung** (U-70 d). Das betrifft A2 und A6
  und ist keine Annahme, sondern eine Messung.

### Nachtrag aus A3: die Modellzeile (U-102)

Seit A3 schreibt `SieveRenderer` direkt unter die Markierung eine zweite Kopfzeile
`# mailadmin-model: <base64url(JSON)>` mit dem vollständigen Regelsatz (`SieveRuleSetCodec`,
Version 1). Sie ist das Einzige, woraus ein verwaltetes Skript wieder gelesen wird
(`SieveScript::ruleSet()`); das Sieve darunter wird nie geparst — Punkt „erzeugen, nicht patchen"
bleibt also unberührt. Ein Skript mit Markierung, aber ohne lesbare Modellzeile (etwa eines, das
M3 vor A3 geschrieben hat), gilt als *unlesbar*: es ist unseres und wird beim nächsten Schreiben
ersetzt, aber nicht zusammengeführt. Zu Punkt 6: A3 verwaltet **keinen** Zeitraum (A3, Punkt 7).

## Definition of Done

- [x] Ein Regelsatz aus Abwesenheit und Weiterleitung ergibt ein gültiges Skript, das der Server
      annimmt und aktiviert — `CyrusSieveStoreIntegrationTest::testARenderedRuleSetIsAcceptedAndActivated`.
- [x] Ein vorhandenes fremdes Skript wird erkannt und **nicht** überschrieben —
      `testAForeignScriptIsNotOverwrittenAndATakeoverIsExplicit`, gegen den echten Server.
- [x] Ein Text mit Umlauten, Anführungszeichen und Zeilenumbrüchen überlebt den Weg unverändert —
      `SieveStoreContract::testAMessageWithUmlautsQuotesAndNewlinesSurvivesUnchanged`, byteweise.
- [x] Beide Implementierungen der Schnittstelle bestehen denselben Vertragstestsatz —
      `tests/Support/Mailstore/SieveStoreContract.php`, ausgeführt von
      `InMemorySieveStoreContractTest` und `CyrusSieveStoreIntegrationTest`.

## Testfälle

- **Unit:** Renderer — Abwesenheit allein, Weiterleitung allein, beides, keins. *(F4,
  `SieveRendererTest`)*
- **Unit:** Maskierung von Sonderzeichen im Betreff und im Text. *(F4, dazu
  `ManageSieveConnectionTest` für die Maskierung auf Protokollebene)*
- **Unit:** ManageSieve — keine Anmeldeinformation auf unverschlüsselter Leitung, Verwerfen der
  Fähigkeiten von vor dem Handshake, `{n+}` in einem Schreibvorgang, überlanges Serverliteral
  abgelehnt.
- **Unit:** Adapter gegen einen geskripteten Server — Ablehnung **ohne** Antwortcode, Sitzung als
  der Nutzer, gekappte Servermeldung.
- **Integration (F5):** Skript schreiben, aktivieren, zurücklesen, deaktivieren, löschen.
- **Integration:** fremdes Skript vorhanden — die Anwendung verweigert das Überschreiben.
