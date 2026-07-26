<!-- markdownlint-disable MD013 MD024 MD060 -->

# A6 — Abgleich, Reparaturwarteschlange, Zustandsprüfung

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | A2, M2, F3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 8.2 |
| **Status** | offen |

---

## Ziel

Sichtbar machen, was zwischen Datenbank und Mailstore auseinanderläuft, offene
Reparaturvorgänge anzeigen und wiederholbar machen, und die Erreichbarkeit der Abhängigkeiten
melden.

## Abgrenzung

- **Es wird nichts automatisch bereinigt** (E-18). Der Abgleich zeigt an; das Handeln bleibt
  beim Menschen.
- Kein Abgleich gegen die Salt-generierte Konfiguration (E-9).

## Abhängigkeiten und geteilte Dateien

Nutzt M2 zum Auflisten der Mailboxen und die Repositories aus A1 und A2.

## Vorgaben

- Der Abgleich prüft **beide Cyrus-Namensräume** — den flachen und den virtdomains-Baum. Das
  Altsystem sah nur einen und übersah dadurch Schatten-Postfächer (Architektur 8.2).
- Die Reparaturwarteschlange nimmt auf, was bei zusammengesetzten Operationen hängen bleibt.

## Artefakte

- Endpunkte `GET /maintenance/reconciliation`, `GET /health`, Endpunkte für die
  Reparaturwarteschlange
- `Application/Maintenance/Reconcile.php`
- `Infrastructure/Persistence/RepairQueueRepository.php`
- Migration `mailadmin_repair_queue`
- Oberfläche: Abgleichsansicht, Warteschlange mit Wiederholen-Funktion

## Zu klärende Punkte

1. **Laufzeit des Abgleichs.** Er listet alle Mailboxen und vergleicht sie mit allen
   Datenbankeinträgen. Bei größeren Installationen kann das dauern. Synchron im Anfragebetrieb,
   oder als angestoßener Lauf mit gespeichertem Ergebnis?
2. **Wie werden beide Namensräume abgefragt?** Ein `LIST`-Muster reicht möglicherweise nicht,
   weil die Namensräume unterschiedlich aufgebaut sind. In M2 zu klären, hier zu nutzen.
3. **Verzögert gelöschte Mailboxen** liegen in einem eigenen Namensraum und dürfen nicht als
   verwaist gemeldet werden — sonst ist die Liste nach jeder Löschung falsch.
4. **Kategorien der Abweichung.** Vorschlag: Konto ohne Mailbox, Mailbox ohne Konto,
   `virtual`-Eintrag ohne Konto, Schatten-Postfach im falschen Namensraum. Vollständig machen.
5. **Wiederholung aus der Warteschlange.** Welche Vorgänge sind sicher wiederholbar? Anlegen
   ja, Löschen mit Vorsicht. Jeder Eintrag braucht die Information, ob er es ist.
6. **Tiefe der Zustandsprüfung.** Nur Erreichbarkeit, oder auch eine echte Anmeldung gegen
   Cyrus? Letzteres ist aussagekräftiger, belastet aber bei häufiger Abfrage.
7. **Darf `/health` ohne Anmeldung erreichbar sein?** Praktisch für Überwachung, verrät aber
   den Zustand der Infrastruktur. Vorschlag: knappe öffentliche Antwort, Details nur angemeldet.

## Definition of Done

- Der Abgleich findet ein absichtlich erzeugtes Konto ohne Mailbox und eine Mailbox ohne Konto.
- Er findet ein Schatten-Postfach im jeweils anderen Namensraum.
- Verzögert gelöschte Mailboxen erscheinen nicht als Abweichung.
- Ein fehlgeschlagener Anlagevorgang landet in der Warteschlange und lässt sich wiederholen.
- `/health` meldet Datenbank, IMAP und ManageSieve getrennt.

## Testfälle

- **Integration (F5):** je Abweichungskategorie ein herbeigeführter Fall.
- **Integration:** Mailbox löschen, Abgleich meldet sie nicht als verwaist.
- **Anwendungsfall:** Wiederholung aus der Warteschlange mit Attrappe.
- **Integration:** `/health` bei abgeschaltetem Cyrus meldet den Ausfall, ohne die ganze
  Anwendung scheitern zu lassen.
