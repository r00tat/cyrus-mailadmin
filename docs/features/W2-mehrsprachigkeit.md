<!-- markdownlint-disable MD013 MD024 MD060 -->

# W2 — Mehrsprachigkeit

| | |
|---|---|
| **Stufe** | 5 — Oberfläche |
| **Hängt ab von** | W1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 11.4 · E-16 |
| **Status** | offen |

---

## Ziel

Die Oberfläche ist vollständig übersetzbar, ohne dass später Texte aus dem Code gefischt werden
müssen. Ausgeliefert wird zunächst Deutsch.

## Abgrenzung

- Keine Übersetzung der API. Sie bleibt sprachneutral und liefert stabile Fehlercodes (E-16).
- Keine zweite Sprache im ersten Wurf — nur die Struktur dafür.

## Abhängigkeiten und geteilte Dateien

Baut auf W1 auf. Die Fehlercodes stammen aus F2 und den A-Paketen; die Zuordnung Code → Text
wird hier gepflegt.

## Vorgaben

- Keine festverdrahteten Oberflächentexte.
- Fehlermeldungen aus dem `code`-Feld übersetzen; ist ein Code unbekannt, dient `title` bzw.
  `detail` als Rückfall, damit die Oberfläche auch bei neuen Fehlerarten benutzbar bleibt.
- Datum, Zahlen und Speichergrößen über `Intl`.
- Sprachwahl aus `Accept-Language`, überschreibbar je Administrator; Ablage in
  `mailadmin_admin_setting`.

## Artefakte

- Übersetzungsbibliothek und ihre Einrichtung
- `frontend/src/i18n/de.json` und die Schlüsselstruktur
- Abbildung Fehlercode → Text
- Endpunkt oder Erweiterung für die Spracheinstellung je Administrator
- Prüflauf, der fehlende und überzählige Schlüssel meldet

## Zu klärende Punkte

1. **Bibliothek.** `react-i18next` ist verbreitet und mächtig, aber nicht klein. Eine schlanke
   eigene Lösung würde zu E-12 passen — braucht aber Pluralformen und Interpolation. Abwägen.
2. **Schlüsselkonvention.** Vorschlag `<bereich>.<ansicht>.<element>`, etwa
   `account.list.column.quota`. Festlegen, bevor die ersten Ansichten entstehen — nachträglich
   umbenennen ist teuer.
3. **Pluralformen und Interpolation.** Werden sie gebraucht? Bei Angaben wie „3 Konten" ja.
4. **Wo liegen die Fehlercode-Texte** — bei den übrigen Übersetzungen oder in einer eigenen
   Datei? Eigene Datei erleichtert den Abgleich mit dem Backend.
5. **Prüfung in der Bauphase.** Bricht ein fehlender Schlüssel den Build, oder gibt es nur eine
   Warnung? Ein harter Abbruch verhindert, dass Sprachdateien verrotten.
6. **Englisch als zweite Sprache** — für die Veröffentlichung (E-5) naheliegend. Von Anfang an
   mitpflegen oder später?
7. **Zeitzone für Anzeigen.** Das Revisionsprotokoll speichert vermutlich UTC. Anzeige in der
   lokalen Zeit des Browsers oder in einer konfigurierten Zone?

## Definition of Done

- Keine sichtbare Zeichenkette steht im Code.
- Alle Fehlercodes der bestehenden Endpunkte haben eine Übersetzung.
- Ein unbekannter Fehlercode führt zu einer verständlichen Rückfallmeldung, nicht zu einer
  leeren Zeile.
- Der Prüflauf meldet fehlende und überzählige Schlüssel.
- Datum und Größen erscheinen im deutschen Format.

## Testfälle

- **Unit:** Fehlercode-Abbildung, einschließlich unbekanntem Code.
- **Unit:** Formatierung von Größen und Datum.
- **Prüflauf:** absichtlich entfernter Schlüssel wird gemeldet.
