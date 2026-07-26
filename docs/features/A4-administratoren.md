<!-- markdownlint-disable MD013 MD024 MD060 -->

# A4 — Administratoren

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | S3, S1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 7.2 · E-18 |
| **Status** | offen |

---

## Ziel

Administratorkonten verwalten: anlegen, ändern, löschen, Rolle setzen, Domains zuordnen,
eigenes Passwort ändern.

## Abgrenzung

- Keine neuen Rollen. Superuser und Domainmaster bleiben (E-18).
- Keine Mailkonten — das ist **A2**. `adminuser` und `accountuser` sind getrennte Welten.

## Abhängigkeiten und geteilte Dateien

Nutzt S1 (Argon2id für `adminuser`) und S3 (Policies). Berührt `adminuser`, `domainadmin` und
die Alt-Tabelle `settings`.

## Vorgaben

- Zielverfahren für `adminuser` ist Argon2id; Rehash erfolgt bei jeder Anmeldung über S2.
- Nur Superuser dürfen Administratoren verwalten.
- Die Alt-Tabelle `settings` bleibt unangetastet; eigene Einstellungen kommen in
  `mailadmin_admin_setting` (E-16).

## Artefakte

- Endpunkte `GET/POST /admins`, `GET/PATCH/DELETE /admins/{username}`,
  `PUT /admins/me/password`
- `Application/Admin/` — Anwendungsfälle
- `Infrastructure/Persistence/AdminRepository.php`
- Oberfläche: Liste, Anlegen, Rollen- und Domainzuordnung, eigenes Passwort

## Zu klärende Punkte

1. **Der letzte Superuser.** Löschen oder Herabstufen muss verhindert werden, sonst ist das
   System nicht mehr verwaltbar. Prüfung im Anwendungsfall.
2. **Selbstlöschung.** Darf ein Superuser sich selbst löschen? Vorschlag: nein.
3. **Passwortregeln für Administratoren** — strenger als für Mailkonten? Diese Konten haben
   deutlich mehr Rechte.
4. **`domainadmin` mit `'*'`.** Siehe S3, Punkt 3 — hier wird es geschrieben, dort gelesen.
   Beide Pakete müssen dieselbe Bedeutung annehmen.
5. **Was passiert mit den `settings`-Zeilen** eines gelöschten Administrators? Das Altsystem
   löschte sie mit. Übernehmen.
6. **Token-Widerruf beim Löschen und bei Rollenänderung.** Siehe S2, Punkte 4 und 6.
7. **Verwaiste `domainadmin`-Einträge.** Im Bestand können Zuordnungen zu nicht mehr
   existierenden Domains oder Administratoren liegen. Anzeigen oder stillschweigend übergehen?

## Definition of Done

- Anlegen, Ändern, Löschen funktionieren, ausschließlich für Superuser.
- Der letzte Superuser lässt sich weder löschen noch herabstufen.
- Ein Bestandsadministrator mit Alt-Hash meldet sich an und hat danach Argon2id.
- Eigenes Passwort ändern funktioniert ohne `admin:write`.
- Alle Änderungen sind protokolliert.

## Testfälle

- **Unit:** Prüfung „letzter Superuser".
- **Integration:** Domainmaster erhält bei allen Adminendpunkten `403`.
- **Integration:** Anmeldung mit Alt-Hash, danach Rehash geprüft.
- **Integration:** Löschen entfernt `domainadmin`- und `settings`-Zeilen.
