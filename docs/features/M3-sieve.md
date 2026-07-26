<!-- markdownlint-disable MD013 MD024 MD060 -->

# M3 — ManageSieve und Sieve-Modell

| | |
|---|---|
| **Stufe** | 3 — Mailstore |
| **Hängt ab von** | M1, F4 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 9.4 |
| **Status** | offen |

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

- `Infrastructure/Mailstore/Sieve/ManageSieveConnection.php`
- `Infrastructure/Mailstore/CyrusSieveStore.php`, `InMemorySieveStore.php`
- `Domain/Sieve/SieveRuleSet.php` — Regeln als Modell
- `Domain/Sieve/SieveRenderer.php`
- `Domain/Sieve/VacationRule.php`, `RedirectRule.php`
- Erkennung der Markierung und des „fremdes Skript"-Zustands

## Zu klärende Punkte

1. **Skriptname.** Das Altsystem verwendete `sieve`. Übernehmen (damit vorhandene Skripte
   erkannt werden) oder einen eigenen Namen wählen und das alte migrieren? Für
   Abwärtskompatibilität spricht der alte Name.
2. **Welche Sieve-Erweiterungen** werden vorausgesetzt? Mindestens `vacation`. Kommen
   `fileinto`, `regex`, `envelope` dazu? Jede Erweiterung, die der Server nicht kann, macht
   das Skript ungültig — die Fähigkeiten sollten beim Verbinden abgefragt werden.
3. **Vacation-Parameter.** `:days` (Altsystem: 1), `:addresses`, `:subject`. Wird `:days`
   konfigurierbar? Ein Wert von 1 bedeutet, dass derselbe Absender täglich eine
   Abwesenheitsnotiz bekommt.
4. **Zeichensatz.** Sieve-Skripte sind UTF-8. Das Altsystem rechnete mit `iconv` um. Wie werden
   Umlaute in Betreff und Text behandelt, und wie werden Anführungszeichen maskiert?
5. **Mehrere Skripte je Nutzer.** ManageSieve erlaubt sie; das Altsystem nutzte eines. Bleiben
   wir bei einem, oder wird das aktive Skript nur ergänzt?
6. **Zeitraum für die Abwesenheit.** Sieve `vacation` kennt kein Start- und Enddatum. Soll die
   Anwendung ein Datum verwalten und das Skript automatisch entfernen — dann braucht es einen
   geplanten Lauf, den es sonst nicht gäbe.

## Definition of Done

- Ein Regelsatz aus Abwesenheit und Weiterleitung ergibt ein gültiges Skript, das der Server
  annimmt und aktiviert.
- Ein vorhandenes fremdes Skript wird erkannt und **nicht** überschrieben.
- Ein Text mit Umlauten, Anführungszeichen und Zeilenumbrüchen überlebt den Weg unverändert.
- Beide Implementierungen der Schnittstelle bestehen denselben Vertragstestsatz.

## Testfälle

- **Unit:** Renderer — Abwesenheit allein, Weiterleitung allein, beides, keins.
- **Unit:** Maskierung von Sonderzeichen im Betreff und im Text.
- **Integration (F5):** Skript schreiben, aktivieren, zurücklesen, löschen.
- **Integration:** fremdes Skript vorhanden — die Anwendung verweigert das Überschreiben.
