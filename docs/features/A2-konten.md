<!-- markdownlint-disable MD013 MD024 MD060 -->

# A2 — Konten

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | A1, S1, M2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 7.2, 8.1 · E-13, E-14 |
| **Status** | offen |

---

## Ziel

Mailkonten anlegen, ändern, löschen und auflisten, Passwörter setzen, Quota lesen und setzen,
Namensschema je Konto berücksichtigen.

## Abgrenzung

- Keine Aliase und Weiterleitungen — das ist **A3**.
- Keine Massenrücksetzung von Passwörtern; sie fällt nach E-18 aus dem ersten Release.

## Abhängigkeiten und geteilte Dateien

Kernstück der Fachlichkeit. Nutzt S1, M2, F4 und die Muster aus A1.

## Vorgaben

- **Anlegen: Mailstore zuerst, dann Datenbank.** Löschen umgekehrt (Architektur 8.1).
- Neue Passwörter als bcrypt; Bestandshashes bleiben gültig (E-13).
- Namensschema für neue Konten aus der Domain- bzw. globalen Einstellung; bei bestehenden
  Konten aus dem gespeicherten Benutzernamen erkannt (E-14).
- Quota kommt aus Cyrus, nicht aus der Datenbank — eigene Ressource, nicht in der Liste
  mitgeliefert (Architektur 7.2).
- Die Kontenliste zeigt das Hash-Format und lässt danach filtern (E-13).

## Artefakte

- Endpunkte `GET/POST /domains/{domain}/accounts`, `GET/PATCH/DELETE /accounts/{id}`,
  `PUT /accounts/{id}/password`, `GET/PUT /accounts/{id}/quota`
- `Application/Account/` — Anlegen, Ändern, Löschen, Auflisten, Passwort setzen, Quota
- `Infrastructure/Persistence/AccountRepository.php`
- Oberfläche: Liste mit Hash-Format-Spalte, Anlage-Formular, Bearbeiten, Löschbestätigung

## Zu klärende Punkte

1. **Kontenname bei `freenames = NO`.** Das Altsystem zählte hoch (`prefix` + vierstellige
   Nummer) und leitete die nächste Nummer aus dem letzten Eintrag ab — anfällig für Lücken und
   Wettläufe. Übernehmen wir das, oder verlangen wir immer einen freien Namen?
2. **Deaktivieren statt Löschen.** `accountuser` hat **kein** Statusfeld; nur `virtual` hat
   `status`. Ein Konto lässt sich damit nicht sperren, ohne die Zustellung zu ändern. Fügen
   wir ein additives Feld hinzu, oder deaktivieren wir über `virtual.status`? Beides hat
   Nebenwirkungen — `virtual.status = 0` nimmt dem Konto die Adresse, lässt die Anmeldung aber
   zu, weil `allusers` aus `accountuser` liest.
3. **Was bedeutet Quota `0`?** Siehe M2, hier fachlich zu beantworten.
4. **Standardordner.** Bei jeder Anlage anlegen? Auch abonnieren (braucht Proxy-Auth)?
5. **Kontingentprüfung.** `maxaccounts` und `domainquota` — an welcher Stelle geprüft, und was
   passiert bei gleichzeitigen Anlagen?
6. **Umbenennen eines Kontos.** Sieht das Altsystem nicht vor. Bieten wir es an? Es entspricht
   technisch dem Schemawechsel: `renamemailbox` plus Datenbankänderung plus neue Zugangsdaten
   für den Nutzer.
7. **Löschen eines Kontos mit vorhandener Mail.** Vorher warnen, Größe anzeigen? Cyrus löscht
   verzögert, ein Rückholen wäre also eine Zeit lang möglich — anbieten oder nicht?
8. **`prefix` in `accountuser`.** Wird gefüllt, aber wofür? Beim Anlegen den Domainwert
   übernehmen, um webcyradm-kompatibel zu bleiben.

## Definition of Done

- Anlegen erzeugt Mailbox, ACL, Quota und die Datenbankeinträge in der festgelegten Reihenfolge.
- Ein Fehler nach der Mailbox-Anlage hinterlässt keinen Datenbankeintrag und einen Eintrag in
  der Reparaturwarteschlange.
- Löschen entfernt zuerst die Zustellung, dann die Mailbox.
- Ein neu gesetztes Passwort funktioniert für IMAP und SMTP-AUTH (Test gegen F5).
- Die Liste zeigt das Hash-Format und ist danach filterbar.

## Testfälle

- **Anwendungsfall:** Anlage mit Attrappe — Erfolg, Fehler in der Datenbank, Kompensation.
- **Integration (F5):** Anlegen, Passwort setzen, IMAP-Anmeldung, SMTP-AUTH, Löschen.
- **Integration:** Anlage in beiden Namensschemata, jeweils mit Anmeldung.
- **Integration:** Domainmaster kann in fremder Domain kein Konto anlegen.
