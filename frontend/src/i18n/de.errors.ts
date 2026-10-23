/**
 * Die deutschen Texte für Fehler — eine eigene Datei, damit der Abgleich mit dem
 * Vertrag an einer Stelle passiert (W2, U-89).
 *
 * **Vollständig und nichts darüber hinaus.** Jeder Wert der Aufzählung
 * `ErrorCode` im gebündelten Vertrag hat hier einen Text, und jeder Text unter
 * `errors.<code>` gehört zu einem Code im Vertrag. `catalogue.test.ts` prüft
 * beides und schlägt fehl, statt zu warnen. Wer einen `ErrorCode` einträgt,
 * trägt im selben Schritt den Text hier ein (U-72, 5).
 *
 * Zwei Namensräume gehören nicht zum Vertrag und sind davon ausgenommen:
 * `errors.client.*` für Zustände, die nur im Browser entstehen, und
 * `errors.field.*` für die Regelschlüsselwörter in `Problem.errors`.
 *
 * Ein Code, den dieser Build nicht kennt, landet nicht als leere Zeile auf dem
 * Schirm: `problemText.ts` zeigt dann `detail` bzw. `title` der Antwort.
 */
export const deErrors = {
  // --- Fehlercodes aus dem Vertrag -----------------------------------------
  // Querschnitt (F2)
  'errors.request.conflict': 'Der Vorgang steht im Widerspruch zum aktuellen Stand der Daten.',
  'errors.request.forbidden': 'Für diesen Vorgang fehlt die Berechtigung.',
  'errors.request.internal_error': 'Im Server ist ein unerwarteter Fehler aufgetreten.',
  'errors.request.malformed_json': 'Die Anfrage war fehlerhaft aufgebaut.',
  'errors.request.method_not_allowed': 'Dieser Vorgang ist an dieser Stelle nicht vorgesehen.',
  'errors.request.not_found': 'Der angeforderte Eintrag existiert nicht.',
  'errors.request.precondition_failed':
    'Der Eintrag wurde zwischenzeitlich von jemand anderem geändert. Bitte neu laden.',
  'errors.request.precondition_required': 'Für diese Änderung fehlt der Stand, auf dem sie aufsetzt.',
  'errors.request.rate_limited': 'Zu viele Versuche. Bitte einen Moment warten.',
  'errors.request.service_unavailable': 'Der Dienst ist vorübergehend nicht verfügbar.',
  'errors.request.unauthenticated': 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.',
  'errors.request.unsupported_media_type': 'Das Format der Anfrage wird nicht unterstützt.',
  'errors.request.validation_failed': 'Die Eingaben sind unvollständig oder unzulässig.',

  // Bau- und Schemazustand (F2, F3)
  'errors.migration.build_stale':
    'Der Server läuft mit einem veralteten Build. Ein Neustart des Deployments behebt das.',
  'errors.migration.schema_outdated': 'Die Datenbank ist nicht auf dem erwarteten Stand.',

  // Fachmodell (F4)
  'errors.account.identity_mismatch': 'Der Kontoname passt nicht zur angegebenen Domain.',
  'errors.account.invalid_identity': 'Der Kontoname ist unzulässig.',
  'errors.address.invalid': 'Die Adresse ist unzulässig.',
  'errors.address.invalid_destination': 'Das Ziel der Weiterleitung ist unzulässig.',
  'errors.address.invalid_local_part': 'Der Teil vor dem @-Zeichen ist unzulässig.',
  'errors.admin.invalid_scope': 'Der Zuschnitt der Verwaltungsrechte ist unzulässig.',
  'errors.domain.invalid_account_limit': 'Die Obergrenze für Konten ist unzulässig.',
  'errors.domain.invalid_folder': 'Der Ordnername ist unzulässig.',
  'errors.domain.invalid_name': 'Der Domainname ist unzulässig.',
  'errors.mailstore.invalid_acl': 'Die Zugriffsrechte auf den Ordner sind unzulässig.',
  'errors.quota.invalid': 'Die Speichergrenze ist unzulässig.',
  'errors.sieve.invalid_rule': 'Die Filterregel ist unzulässig.',

  // Anmeldung, Sitzung und Passwortregeln (S1, S2)
  'errors.auth.invalid_credentials': 'Benutzername oder Passwort stimmen nicht.',
  'errors.auth.password_contains_personal_data':
    'Das Passwort darf den Benutzernamen oder die Domain nicht enthalten.',
  'errors.auth.password_empty': 'Bitte ein Passwort eingeben.',
  'errors.auth.password_invalid_characters': 'Das Passwort enthält unzulässige Zeichen.',
  'errors.auth.password_too_common': 'Dieses Passwort ist zu verbreitet.',
  'errors.auth.password_too_long': 'Das Passwort ist zu lang.',
  'errors.auth.password_too_short': 'Das Passwort ist zu kurz.',
  'errors.auth.refresh_invalid': 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.',
  'errors.auth.refresh_reused':
    'Die Sitzung wurde aus Sicherheitsgründen beendet. Bitte erneut anmelden und die Anmeldung im Zweifel melden.',

  // Verwalterkonten (A4)
  'errors.admin.last_superuser':
    'Die letzte Systemverwaltung kann nicht entfernt oder herabgestuft werden.',
  'errors.admin.not_found': 'Dieses Verwalterkonto existiert nicht.',
  'errors.admin.role_scope_mismatch': 'Rolle und Domainzuschnitt passen nicht zusammen.',
  'errors.admin.self_deletion': 'Das eigene Konto kann nicht gelöscht werden.',
  'errors.admin.unknown_domain': 'Mindestens eine der zugewiesenen Domains existiert nicht.',
  'errors.admin.username_taken': 'Dieser Benutzername ist bereits vergeben.',
  'errors.admin.wrong_current_password': 'Das aktuelle Passwort stimmt nicht.',

  // Domains (A1)
  'errors.domain.leftover_state':
    'Unter diesem Namen sind noch Zuordnungen oder Adressen einer früheren Domain vorhanden. Sie würden für die neue Domain gelten und müssen zuerst entfernt werden.',
  'errors.domain.mailstore_unavailable':
    'Der Mailspeicher ist nicht erreichbar. Der Vorgang wurde nicht ausgeführt.',
  'errors.domain.name_taken': 'Diese Domain existiert bereits.',
  'errors.domain.namespace_conflict':
    'Der Name würde einen bestehenden Benutzernamen mehrdeutig machen. Im Namensschema mit Punkt ergeben info.kunde@example.de und info@kunde.example.de denselben Benutzernamen.',
  'errors.domain.not_empty': 'Die Domain enthält noch Konten.',
  'errors.domain.prefix_taken': 'Dieses Präfix ist bereits vergeben.',
  'errors.domain.rename_incomplete':
    'Die Umbenennung wurde nicht abgeschlossen. Was sich nicht zurücknehmen ließ, steht in der Reparaturwarteschlange.',
  'errors.domain.rename_not_confirmed': 'Die Umbenennung wurde nicht bestätigt.',
  'errors.domain.still_has_addresses':
    'Die Domain hat noch Adressen, Weiterleitungen oder eine Catchall-Adresse. Bitte zuerst in der Adressliste der Domain entfernen.',

  // Postfächer (A2)
  'errors.account.limit_reached': 'Die Domain hat bereits so viele Konten, wie sie haben darf.',
  'errors.account.mailbox_exists':
    'Im Mailspeicher gibt es unter diesem Namen bereits ein Postfach. Es wurde nicht verändert.',
  'errors.account.mailbox_missing': 'Zu diesem Konto gibt es im Mailspeicher kein Postfach.',
  'errors.account.mailstore_unavailable':
    'Der Mailspeicher hat den Schritt nicht ausgeführt. Es wurde nichts geändert.',
  'errors.account.name_taken': 'Diese Adresse oder dieser Benutzername ist bereits vergeben.',
  'errors.account.quota_exceeds_domain': 'Die Quota überschreitet das Kontingent der Domain.',
  'errors.account.setup_incomplete':
    'Das Konto konnte nicht vollständig angelegt werden und wurde nicht angelegt.',
  'errors.account.status_not_enforced':
    'Das Konto kann nicht gesperrt werden: Der Mailserver wertet die Sperre noch nicht aus.',
  'errors.account.username_too_long': 'Der Benutzername, den dieses Konto bekäme, ist zu lang.',

  // Adressen, Weiterleitungen, Abwesenheitsnotiz (A3)
  'errors.address.needed_by_catchall':
    'Der Catchall der Domain leitet an diese Adresse weiter. Bitte zuerst den Catchall ändern.',
  'errors.address.not_editable':
    'Die Einträge dieser Adresse lassen sich nicht gemeinsam bearbeiten. Sie werden einzeln in der Adressliste angezeigt.',
  'errors.address.reserved':
    'Diese Adresse muss eine Mail-Domain haben (postmaster, abuse). Entfernen oder Abschalten muss ausdrücklich bestätigt werden.',
  'errors.address.taken': 'Diese Adresse ist bereits vergeben.',
  'errors.address.unstorable': 'Die Adresse lässt sich in dieser Form nicht speichern.',
  'errors.forward.copy_required':
    'Bei diesem Konto bleibt immer eine Kopie im Postfach. Eine Weiterleitung ohne Kopie ist nicht möglich.',
  'errors.vacation.foreign_script':
    'Das aktive Sieve-Skript wurde nicht von mailadmin angelegt. Es wird nur ersetzt, wenn die Übernahme bestätigt wird.',
  'errors.vacation.mailstore_unavailable':
    'Der Sieve-Dienst hat den Schritt nicht ausgeführt. Es wurde nichts geändert.',
  'errors.vacation.not_supported': 'Der Sieve-Dienst unterstützt keine Abwesenheitsnotizen.',
  'errors.vacation.rejected': 'Der Sieve-Dienst hat die Abwesenheitsnotiz abgelehnt.',

  // Verteilerlisten (A5)
  'errors.list.address_has_extension': 'Eine Listenadresse darf kein „+“ enthalten.',
  'errors.list.address_taken': 'Diese Adresse ist bereits vergeben.',
  'errors.list.already_exists': 'Diese Liste existiert bereits.',
  'errors.list.invalid_address': 'Die Listenadresse ist unzulässig.',
  'errors.list.invalid_member': 'Mindestens eine Mitgliedsadresse ist unzulässig.',
  'errors.list.invalid_name': 'Der Listenname ist unzulässig.',
  'errors.list.invalid_tag': 'Die Listenkennung ist unzulässig.',
  'errors.list.unknown_domain': 'Die Domain der Listenadresse wird hier nicht verwaltet.',

  // Reparaturwarteschlange (A6)
  'errors.repair.already_resolved': 'Dieser Reparatureintrag ist bereits erledigt.',
  'errors.repair.not_retryable':
    'Diesen Eintrag kann mailadmin nicht selbst abschließen. Bitte von Hand beheben und dann als erledigt markieren.',
  'errors.repair.retry_failed': 'Die Wiederholung ist erneut gescheitert. Der Eintrag bleibt offen.',
  'errors.repair.state_changed':
    'Der Zustand passt nicht mehr zum Reparatureintrag. Es wurde nichts geändert.',

  // --- Fehler, die im Client entstehen -------------------------------------
  'errors.client.network': 'Der Server ist nicht erreichbar. Bitte die Verbindung prüfen.',
  'errors.client.malformed_response': 'Die Antwort des Servers war nicht lesbar.',
  'errors.client.unknown': 'Es ist ein unbekannter Fehler aufgetreten.',

  // --- Feldbezogene Verstöße aus `Problem.errors` ---------------------------
  // Der Wert ist ein Schlüsselwort für die verletzte Regel, nie die Eingabe.
  'errors.field.required': 'Pflichtangabe.',
  'errors.field.enum': 'Unzulässiger Wert.',
  'errors.field.maximum': 'Der Wert ist zu groß.',
  'errors.field.minimum': 'Der Wert ist zu klein.',
  'errors.field.maxLength': 'Die Eingabe ist zu lang.',
  'errors.field.minLength': 'Die Eingabe ist zu kurz.',
  'errors.field.pattern': 'Die Eingabe hat nicht das erwartete Format.',
  'errors.field.type': 'Unzulässiger Datentyp.',
  'errors.field.format': 'Die Eingabe hat nicht das erwartete Format.',
  'errors.field.unknown': 'Unzulässige Eingabe.',
} as const;
