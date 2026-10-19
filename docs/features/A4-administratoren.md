<!-- markdownlint-disable MD013 MD024 MD060 -->

# A4 — Administratoren

| | |
|---|---|
| **Stufe** | 4 — Fachlichkeit |
| **Hängt ab von** | S3, S1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 7.2 · E-18 |
| **Status** | **umgesetzt** — U-51 bis U-55; Backend und Oberfläche |

---

## Ziel

Administratorkonten verwalten: anlegen, ändern, löschen, Rolle setzen, Domains zuordnen,
eigenes Passwort ändern.

## Abgrenzung

- Keine neuen Rollen. Superuser und Domainmaster bleiben (E-18).
- Keine Mailkonten — das ist **A2**. `adminuser` und `accountuser` sind getrennte Welten.

## Abhängigkeiten und geteilte Dateien

Nutzt S1 (Argon2id für `adminuser`), S3 (Policies) und S4 (`AuditWriter`). Berührt `adminuser`,
`domainadmin` und die Alt-Tabelle `settings`.

Geteilte Dateien, die A4 angefasst hat: der `admin.*`-Block im `ErrorCode`-Enum von
`openapi/components/common.yaml`. `Permission` und `RolePermissions` mussten **nicht** geändert
werden — S3 hat `admin.read`, `admin.write`, `admin.delete` und `profile.password.write` bereits
angelegt und zugeordnet.

## Vorgaben

- Zielverfahren für `adminuser` ist Argon2id; Rehash erfolgt bei jeder Anmeldung über S2.
- Nur Superuser dürfen Administratoren verwalten.
- Die Alt-Tabelle `settings` bleibt unangetastet; eigene Einstellungen kommen in
  `mailadmin_admin_setting` (E-16).

## Artefakte

| | |
|---|---|
| Vertrag | `openapi/paths/admins.yaml`, `openapi/components/admin.yaml` |
| Anwendungsfälle | `src/Application/Admin/` — `ListAdmins`, `GetAdmin`, `CreateAdmin`, `UpdateAdmin`, `DeleteAdmin`, `ChangeOwnPassword` |
| Port und Modell | `AdminStore`, `AdminAccount`, `AdminAssignment`, `AdminEtag`, `AdminOperationFailed`, `AdminErrorCode` |
| Persistenz | `Infrastructure/Persistence/AdminRepository.php`, `InMemoryAdminStore.php` |
| HTTP | `src/Http/Handler/Admins/` — sechs Handler plus `AdminResponder` |
| Migration | `migrations/0050_admin_setting.sql` |
| Container | `config/di/admin.php` |
| Oberfläche | `frontend/src/pages/admins/` — Liste mit Anlegedialog (`/admins`), Detail mit Ändern und Löschen (`/admins/:username`), eigenes Passwort (`/admins/me/password`); Texte in `frontend/src/i18n/de/admin.ts`; jsdom-Tests in `admins.test.tsx` |

Endpunkte: `GET/POST /admins`, `GET/PATCH/DELETE /admins/{username}`,
`PUT /admins/me/password`.

## Zu klärende Punkte

Alle sieben sind beantwortet. Was über das Paketdokument hinausging, steht als U-Entscheidung;
was paket-lokal blieb, ist hier als **Annahme** markiert.

1. **Der letzte Superuser.** Erledigt — U-51. Weder löschen noch herabstufen; gezählt wird in
   der Datenbank im Moment der Änderung, nicht aus dem `ActorContext` abgeleitet.
2. **Selbstlöschung.** Erledigt — U-51, wie vorgeschlagen: nein, `409` mit
   `admin.self_deletion`. **Selbst-Herabstufung ist dagegen erlaubt**, solange man nicht der
   letzte Superuser ist; das ist die Rolle zu übergeben, und die Wirkung ist sofort sichtbar,
   weil Rolle und Zuschnitt pro Anfrage gelesen werden.
3. **Passwortregeln für Administratoren.** Erledigt — U-52: sie sind bereits strenger, U-21
   verlangt zwölf Zeichen statt zehn. Es kommen keine dazu. Zusätzlich verlangt
   `PUT /admins/me/password` das **aktuelle** Passwort, und alle Erneuerungsfamilien des Kontos
   werden danach widerrufen.
4. **`domainadmin` mit `'*'`.** Erledigt — U-53. Beim Schreiben: ein Superuser bekommt genau die
   Wildcard-Zeile, ein Domainmaster genau eine Zeile je Domain — so, wie web-cyradm es schreibt.
   Beim Lesen wird die Zeile gemeldet, wo immer sie steht (Feld `all_domains`), was S3s Lesart
   in U-43 entspricht. `'*'` als Domainname ist ausgeschlossen, zweifach: Schema und
   Anwendungsfall.
5. **`settings`-Zeilen eines gelöschten Administrators.** Erledigt — U-54, wie vorgeschlagen:
   mitlöschen. Dazu `domainadmin` und `mailadmin_admin_setting`, alles in einer Transaktion.
6. **Token-Widerruf.** Erledigt — U-52. Beim **Löschen** und bei der **eigenen
   Passwortänderung** werden die Erneuerungsfamilien widerrufen. Bei einer **Rollenänderung
   nicht**, und das ist kein Versäumnis: S2 liest Rolle und Zuschnitt pro Anfrage
   (Architektur 5.3), die Änderung wirkt also schon mit der nächsten Anfrage. Ein gelöschtes
   Konto ergibt aus demselben Grund sofort `401`; der Widerruf räumt nur die Zeilen ab.
7. **Verwaiste `domainadmin`-Einträge.** Erledigt — U-53: **angezeigt und markiert**, nicht
   übergangen (U-22). Feld `orphaned_domains`, ermittelt mit einem `LEFT JOIN` über die ganze
   Seite. Bereinigt wird nichts (E-18), aber neu vergeben werden darf eine nicht existierende
   Domain nicht.

### Nachbesserungen an der Oberfläche (U-133)

- **Domainzuordnung:** Ein getippter, nicht mit der Eingabetaste bestätigter Domainname wird beim
  Verlassen des Felds übernommen (`autoSelect`) und geht so mit dem Speichern hinaus; doppelte
  Einträge werden zusammengefasst.

### Annahmen, die A4 paket-lokal getroffen hat

- **Der `ETag` deckt genau die sieben Felder der Darstellung ab** und wird beim Schreiben nur
  geprüft, wenn der Client `If-Match` schickt (U-55, Punkt 2). Ob die API `If-Match` irgendwann
  **verlangt** (`428`), ist eine Entscheidung für alle Ressourcen und nicht für diese eine.
- **`admin.not_found` ist ein eigener Fehlercode**, anders als bei A1 (U-55, Punkt 1). Die
  Begründung hängt daran, dass `admin.*` Superuser-Sache bleibt.
- **`mailadmin_admin_setting` entsteht ohne Lesepfad** (U-54). Wer die erste Einstellung
  einführt — vermutlich W1 für die Sprache (E-16) —, findet die Tabelle und die Abräumung
  bereits vor.
- **Die Adminliste wird nicht domain-gescoped.** `Repository::scopedTo()` braucht eine
  Domainspalte, und `adminuser` hat keine. Was die Liste begrenzt, ist die Berechtigung:
  `admin.read` hält nur der Superuser. Steht so im Klassenkommentar von `ListAdmins`, damit es
  nicht wie ein vergessenes `scopedTo()` aussieht.

## Definition of Done

- [x] Anlegen, Ändern, Löschen funktionieren, ausschließlich für Superuser.
- [x] Der letzte Superuser lässt sich weder löschen noch herabstufen.
- [x] Ein Bestandsadministrator mit Alt-Hash meldet sich an und hat danach Argon2id — das ist
      S2s `SignIn`, unverändert; A4 macht den Zustand zusätzlich sichtbar (`password_format`)
      und lässt einen Alt-Hash auch beim eigenen Passwortwechsel verifizieren.
- [x] Eigenes Passwort ändern funktioniert ohne `admin:write` — `profile.password.write`, das
      beide Rollen halten.
- [x] Alle Änderungen sind protokolliert — `AuditWriter` aus S4, innerhalb der Transaktion
      (U-63).
- [x] Oberfläche: Liste, Anlegen, Ändern und Löschen für Superuser; Hashverfahren und Altverfahren
      sowie verwaiste Domainzuordnungen sind in Liste und Detail markiert. Anlegen ist ein Dialog,
      weil jeder Name — auch `new` — ein Administrator sein kann. Das eigene Konto lässt sich
      nicht löschen (Schaltfläche gesperrt, Server prüft ohnehin). Das eigene Passwort ändern
      beide Rollen unter `/admins/me/password`; danach meldet die Oberfläche ab, weil der Server
      alle Sitzungen beendet. Die Sprachwahl liegt bereits auf der Übersichtsseite.

## Testfälle

- [x] **Unit:** Prüfung „letzter Superuser" — `UpdateAdminTest`, `DeleteAdminTest`.
- [x] **Unit:** `AdminEtagTest` — jedes Feld der Darstellung bewegt den Validator.
- [x] **Anwendungsfall:** Domainmaster erhält bei allen Adminendpunkten `AccessDenied` (die
      Middleware macht `403` daraus) — je ein Fall in jedem Testfile; die Antwort über die
      Kette prüft `AuthorizationChainTest` aus S3.
- [x] **Anwendungsfall:** Anmeldung mit Alt-Hash — `ChangeOwnPasswordTest` fährt DES-crypt,
      md5crypt, sha512crypt, SHA1-hex und Klartext durch und weist den Rehash nach.
- [x] **Integration:** Löschen entfernt `domainadmin`-, `settings`- und
      `mailadmin_admin_setting`-Zeilen und lässt die eines zweiten Kontos stehen —
      `AdminRepositoryIntegrationTest`.
- [x] **Vertrag:** `AdminEndpointTest` hält das `sort`-Enum und die Allowlist des Repositorys
      deckungsgleich, ebenso das `Admin`-Schema und `AdminAccount::representation()`.
