<!-- markdownlint-disable MD013 MD024 MD060 -->

# A3 — Adressen, Aliase, Catchall, Weiterleitungen

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | A2, M3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 3.4 |
| **Status** | offen |

---

## Ziel

Alles, was in der Tabelle `virtual` lebt: weitere Adressen eines Kontos, Aliase, Catchall je
Domain, Weiterleitungen nach innen und außen — sowie Abwesenheitsnotizen über Sieve.

## Abgrenzung

- Keine Konten — das ist **A2**.
- Kein Sieve-Protokoll — das ist **M3**. A3 nutzt das Modell.

## Abhängigkeiten und geteilte Dateien

Teilt sich die Tabelle `virtual` mit A2 (Kontoadressen). Repository und Typerkennung werden
gemeinsam genutzt — Abstimmung nötig.

## Vorgaben

- Die vier Typen aus Architektur 3.4 sind explizit; kein Feldraten im Anwendungscode.
- Ein `virtual`-Datensatz, der in kein Muster passt, wird als unbekannt **angezeigt**, nicht
  verschluckt.
- Abwesenheitsnotizen werden aus dem Modell erzeugt, nie durch Patchen (M3).

## Artefakte

- Endpunkte für Aliase, Catchall, Weiterleitungen und Abwesenheit nach Architektur 7.2
- `Application/Address/` — je Typ ein Satz Anwendungsfälle
- `Infrastructure/Persistence/VirtualRepository.php` mit Typerkennung
- Oberfläche: Adressenliste je Domain, Formulare je Typ

## Zu klärende Punkte

1. **Mehrere Ziele in `dest`.** Das Feld ist ein `longtext` und enthält im Bestand
   kommaseparierte Listen. Trennzeichen, Leerzeichenbehandlung und Höchstzahl festlegen — und
   prüfen, ob Postfix die Schreibweise so akzeptiert, wie wir sie schreiben.
2. **Weiterleitung mit lokaler Kopie.** Im Altsystem entsteht sie dadurch, dass `dest` sowohl
   das lokale Konto als auch die externe Adresse enthält. Modellieren wir das als Schalter
   „Kopie behalten" oder als Liste von Zielen?
3. **`status`-Feld.** Nur `virtual` hat es. Nutzen wir es zum Deaktivieren einer Adresse, ohne
   sie zu löschen? Postfix filtert bereits auf `status = 1`.
4. **Catchall-Konflikte.** Was passiert, wenn eine konkrete Adresse und ein Catchall
   existieren? Postfix bevorzugt die konkrete — muss die Oberfläche das erklären?
5. **Reservierte Adressen.** `postmaster` und `abuse` sollten nicht versehentlich
   weiterleitbar oder löschbar sein. Durchsetzen oder nur warnen?
6. **Adressen fremder Domains als Alias.** Darf ein Domainmaster einen Alias anlegen, dessen
   Ziel in einer fremden lokalen Domain liegt? Das wäre eine Umgehung des Zuschnitts.
7. **Abwesenheit ohne Zeitraum.** Siehe M3, Punkt 6 — fachlich hier zu entscheiden.

## Definition of Done

- Alle vier Typen lassen sich anlegen, ändern, löschen und auflisten.
- Ein unbekannter Bestandseintrag wird angezeigt und nicht beim Speichern zerstört.
- Eine Abwesenheitsnotiz erscheint als gültiges Sieve-Skript und wird wieder entfernt.
- Alle Änderungen sind domain-gescoped und protokolliert.

## Testfälle

- **Unit:** Typerkennung für alle Muster, einschließlich Grenzfällen.
- **Integration:** Alias anlegen, Mail-Zustellung über Postfix prüfen (F5).
- **Integration:** Catchall setzen und wieder entfernen.
- **Integration:** Abwesenheit setzen, Skript prüfen, entfernen.
