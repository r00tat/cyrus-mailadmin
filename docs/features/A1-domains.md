<!-- markdownlint-disable MD013 MD024 MD060 -->

# A1 — Domains

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | F2, F3, F4, S3, M2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 7.2 und 8.1 |
| **Status** | offen |

---

## Ziel

Domains anlegen, ändern, löschen und auflisten, einschließlich Kontingenten, Standardordnern
und der Umbenennung als bestätigte Stapeloperation.

## Abgrenzung

- Keine Konten — das ist **A2**.
- Kein Schreiben von DKIM, `loginrealms` oder DNS. Die Anwendung weist nur darauf hin (E-4,
  E-9).

## Abhängigkeiten und geteilte Dateien

Erstes fachliches Paket. Legt die Muster fest, denen A2 bis A6 folgen: Repository-Aufbau,
Handler-Zuschnitt, Policy-Anbindung, Audit-Aufruf, Schema-Abschnitt.

## Vorgaben

- Umbenennung ändert **nicht nur die Datenbank**. Sie erfordert `renamemailbox` über alle
  Konten der Domain — genau das unterließ das Altsystem und machte Konten unerreichbar.
- Reihenfolge und Kompensation nach Architektur 8.1.
- Im Anlage-Dialog erscheint ein Hinweis auf die externen Schritte: Pillar-Eintrag,
  Konfigurationslauf, DNS-Einträge (MX, SPF, DKIM, DMARC).

## Artefakte

- Endpunkte `GET/POST /domains`, `GET/PATCH/DELETE /domains/{domain}`
- `Application/Domain/CreateDomain.php`, `UpdateDomain.php`, `DeleteDomain.php`,
  `RenameDomain.php`, `ListDomains.php`
- `Infrastructure/Persistence/DomainRepository.php`
- Schema-Abschnitt, Policies, Audit-Aufrufe
- Oberfläche: Liste, Anlegen, Bearbeiten, Löschen mit Bestätigung

## Zu klärende Punkte

1. **Das Feld `prefix`.** Es ist `NOT NULL UNIQUE` und wird im Altcode als Ersatzschlüssel für
   die Domain missbraucht. Wir können es nicht entfernen (E-1). Was schreiben wir bei neuen
   Domains hinein — den Domainnamen selbst? Und was passiert, wenn dieser Wert kollidiert?
2. **`maxaccounts`.** Wird das Kontingent durchgesetzt, und was passiert bei Unterschreitung
   durch nachträgliche Änderung? Ein Wert von `0` — unbegrenzt oder gesperrt?
3. **`domainquota`.** Die Prüfung erfordert, die Quota **aller** Konten der Domain aus Cyrus
   zu summieren. Bei großen Domains ist das teuer. Bei jeder Kontoanlage, oder
   zwischengespeichert?
4. **`freenames` und `freeaddress`.** Beide steuern im Altsystem, wie frei Kontennamen und
   Adressen gewählt werden dürfen. Behalten wir die Semantik, oder werden sie zu
   Kompatibilitätsfeldern ohne Wirkung?
5. **Löschen einer Domain mit Konten.** Verweigern und auf die Konten verweisen, oder
   kaskadieren mit ausdrücklicher Bestätigung? Das Altsystem löschte kaskadierend.
6. **`transport`.** Im Bestand überall `cyrus`; das Altsystem brach bei allem anderen ab.
   Feldwert einfach übernehmen und ignorieren?
7. **Standardordner (`folders`).** Kommaseparierte Liste. Format beibehalten? Und was passiert
   bei einer Änderung — gilt sie nur für neue Konten?
8. **Umbenennung bei nicht erreichbarem Mailstore.** Verweigern ist die sichere Antwort —
   bestätigen.

## Definition of Done

- Anlegen, Ändern, Löschen und Auflisten funktionieren, domain-gescoped.
- Eine Umbenennung benennt Datenbank **und** alle Postfächer um; ein Abbruch hinterlässt einen
  Eintrag in der Reparaturwarteschlange und einen verständlichen Zustand.
- Der Hinweis auf die externen Schritte erscheint beim Anlegen.
- Jede Änderung erzeugt einen Audit-Eintrag.

## Testfälle

- **Unit:** Kontingentprüfungen, Validierung des Domainnamens.
- **Anwendungsfall:** Umbenennung mit Attrappe — Erfolg, Fehler in der Mitte, Kompensation.
- **Integration (F5):** Umbenennung über mehrere Postfächer, danach sind alle erreichbar.
- **Integration:** Domainmaster darf keine Domain anlegen.
