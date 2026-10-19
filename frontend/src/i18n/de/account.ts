/**
 * Texte des Bereichs `account` — Konten (A2).
 *
 * **Gehört dem Bereichsagenten accounts-addresses** (siehe `frontend/src/pages/README.md`).
 * Jeder Schlüssel hier beginnt mit `account.`; `catalogue.test.ts` prüft das, damit
 * zwei Bereiche nie dieselbe Datei oder denselben Schlüssel anfassen.
 * Eingemischt wird die Datei in `../de.ts`.
 */
export const deAccount = {
  // --- Liste ----------------------------------------------------------------
  'account.list.title': 'Konten',
  'account.list.description':
    'Die Postfächer einer Domain mit Namensschema und dem Format ihres gespeicherten Passworts.',
  'account.list.chooseDomain': 'Bitte eine Domain wählen.',
  'account.list.empty': 'Diese Domain hat keine Konten, die zum Filter passen.',
  'account.list.action.create': 'Konto anlegen',
  'account.list.column.address': 'Adresse',
  'account.list.column.username': 'Benutzername',
  'account.list.column.namingScheme': 'Namensschema',
  'account.list.column.passwordFormat': 'Passwortformat',
  'account.list.column.status': 'Anmeldung',
  'account.list.filter.name': 'Benutzername enthält',
  'account.list.filter.passwordFormat': 'Passwortformat',
  'account.list.filter.allFormats': 'Alle Formate',
  'account.list.status.enabled': 'erlaubt',
  'account.list.status.disabled': 'gesperrt',
  'account.list.unresolvable': 'nicht auflösbar',
  'account.list.passwordFormatWeak': 'Dieses Format ist mit dem Hash leicht zu brechen.',
  'account.list.namingScheme.dotted': 'Punkt (name.domain)',
  'account.list.namingScheme.fqun': 'Vollständig (name@domain)',
  'account.list.passwordFormat.argon2id': 'Argon2id',
  'account.list.passwordFormat.bcrypt': 'bcrypt',
  'account.list.passwordFormat.md5crypt': 'MD5-crypt',
  'account.list.passwordFormat.sha256crypt': 'SHA-256-crypt',
  'account.list.passwordFormat.sha512crypt': 'SHA-512-crypt',
  'account.list.passwordFormat.descrypt': 'DES-crypt',
  'account.list.passwordFormat.sha1hex': 'SHA-1 (hex)',
  'account.list.passwordFormat.mysqlPassword': 'MySQL PASSWORD()',
  'account.list.passwordFormat.plaintext': 'Klartext',
  'account.list.passwordFormat.unknown': 'unbekannt',

  // --- Domainauswahl (Konten und Adressen) ----------------------------------
  'account.domainPicker.label': 'Domain',
  'account.domainPicker.noOptions': 'Keine passende Domain.',
  'account.domainPicker.loading': 'Domains werden geladen …',
  'account.domainPicker.open': 'Domains anzeigen',
  'account.domainPicker.close': 'Domainliste schließen',
  'account.domainPicker.clear': 'Auswahl aufheben',

  // --- Anlegen --------------------------------------------------------------
  'account.create.title': 'Konto anlegen',
  'account.create.description': 'Ein neues Postfach in {{domain}}.',
  'account.create.namingScheme.dotted':
    'Neue Konten dieser Domain bekommen einen Benutzernamen der Form name.{{domain}}. Damit meldet sich das Konto bei IMAP und SMTP an.',
  'account.create.namingScheme.fqun':
    'Neue Konten dieser Domain bekommen ihre Adresse als Benutzernamen (name@{{domain}}). Damit meldet sich das Konto bei IMAP und SMTP an.',
  'account.create.field.localPart': 'Name vor dem @',
  'account.create.field.domainSuffix': '@{{domain}}',
  'account.create.field.username': 'Benutzername: {{username}}',
  'account.create.field.password': 'Passwort',
  'account.create.field.passwordHint': 'Wird als bcrypt gespeichert und nirgends angezeigt.',
  'account.create.quotaDefault': 'Vorgabe der Domain ({{quota}})',
  'account.create.quotaDefaultUnlimited': 'Vorgabe der Domain (unbegrenzt)',
  'account.create.submit': 'Anlegen',
  'account.create.created': 'Konto {{address}} angelegt.',
  'account.create.createdIncomplete':
    'Konto {{address}} angelegt. Die Standardordner fehlen noch; die Reparaturwarteschlange führt sie.',

  // --- Detail ---------------------------------------------------------------
  'account.detail.summaryTitle': 'Stammdaten',
  'account.detail.field.address': 'Adresse',
  'account.detail.field.username': 'Benutzername',
  'account.detail.field.domain': 'Domain',
  'account.detail.field.namingScheme': 'Namensschema',
  'account.detail.field.passwordFormat': 'Passwortformat',
  'account.detail.field.legacyPrefix': 'Präfix (Altfeld)',
  'account.detail.noAddress': 'nicht bestimmbar',
  'account.detail.unresolvable':
    'Der gespeicherte Benutzername passt nicht zur Domain. Das Konto wird angezeigt, lässt sich hier aber nicht ändern.',
  'account.detail.passwordFormatLegacy':
    'Das Passwort liegt noch in einem Altformat vor. Es bleibt gültig; mit dem nächsten gesetzten Passwort wird es bcrypt.',
  'account.detail.passwordFormatBlocksLogin':
    'In diesem Format kann sich das Konto bei IMAP und SMTP nicht anmelden. Ein neu gesetztes Passwort behebt das.',
  'account.detail.action.password': 'Passwort setzen',
  'account.detail.action.delete': 'Konto löschen',

  // --- Anmeldung und Dienste -----------------------------------------------
  'account.access.title': 'Anmeldung und Dienste',
  'account.access.loginAllowed': 'Anmeldung erlaubt',
  'account.access.loginBlocked': 'Anmeldung gesperrt',
  'account.access.disabledInfo': 'Das Konto ist gesperrt. Mail wird weiter zugestellt.',
  'account.access.disabledSince': 'Gesperrt seit {{when}}. Mail wird weiter zugestellt.',
  'account.access.disabledSinceBy': 'Gesperrt seit {{when}} von {{who}}. Mail wird weiter zugestellt.',
  'account.access.servicesTitle': 'Dienstschalter',
  'account.access.servicesHint':
    'Die Schalter werden gespeichert und weitergegeben, sperren aber nichts — wie unter webcyradm. Gesperrt wird über die Anmeldung oben.',
  'account.access.service.imap': 'IMAP',
  'account.access.service.pop': 'POP',
  'account.access.service.sieve': 'Sieve',
  'account.access.service.smtpAuth': 'SMTP-AUTH',
  'account.access.saveServices': 'Schalter speichern',
  'account.access.servicesSaved': 'Dienstschalter gespeichert.',
  'account.access.enabled': 'Anmeldung wieder erlaubt.',
  'account.access.disabled': 'Konto gesperrt.',
  'account.access.confirmDisable.title': 'Konto sperren?',
  'account.access.confirmDisable.message':
    '{{address}} kann sich danach weder über IMAP, POP und ManageSieve noch über SMTP-AUTH anmelden. Mail wird weiter zugestellt; schon offene Verbindungen bleiben bestehen, bis das Programm sie schließt.',
  'account.access.confirmDisable.confirm': 'Sperren',

  // --- Passwort -------------------------------------------------------------
  'account.password.title': 'Passwort setzen',
  'account.password.description':
    'Das neue Passwort für {{address}} gilt sofort für IMAP und SMTP. Es wird als bcrypt gespeichert.',
  'account.password.field': 'Neues Passwort',
  'account.password.submit': 'Passwort setzen',
  'account.password.saved': 'Passwort gesetzt.',

  // --- Quota ----------------------------------------------------------------
  'account.quota.title': 'Speicherplatz',
  'account.quota.loading': 'Quota wird aus dem Mailspeicher gelesen …',
  'account.quota.usage': '{{used}} von {{limit}} belegt',
  'account.quota.usageUnlimited': '{{used}} belegt, ohne Begrenzung',
  'account.quota.usageUnknown': 'Der Mailspeicher meldet für dieses Postfach keine Belegung.',
  'account.quota.share': '{{percent}} % belegt',
  'account.quota.action.edit': 'Quota ändern',
  'account.quota.dialogTitle': 'Quota ändern',
  'account.quota.dialogHint':
    'Senken ist immer möglich. Erhöhen und Aufheben werden gegen das Kontingent der Domain geprüft.',
  'account.quota.saved': 'Quota gespeichert.',
  'account.quota.field.label': 'Quota',
  'account.quota.field.unlimited': 'Unbegrenzt',
  'account.quota.field.limit': 'Begrenzt auf',
  'account.quota.field.amount': 'Größe',
  'account.quota.field.unit': 'Einheit',
  'account.quota.unit.mebibyte': 'MiB',
  'account.quota.unit.gibibyte': 'GiB',

  // --- Löschen --------------------------------------------------------------
  'account.delete.title': 'Konto löschen?',
  'account.delete.message':
    'Das Konto {{address}} wird gelöscht — mit seinem Postfach und allen Nachrichten darin. Es gibt kein Zurückholen.',
  'account.delete.usage': 'Im Postfach liegen derzeit {{used}}.',
  'account.delete.usageUnknown': 'Wie viel im Postfach liegt, ließ sich nicht ermitteln.',
  'account.delete.catchall':
    'Mit entfernt werden die Weiterleitung des Kontos und — falls der Catchall der Domain an dieses Konto zustellt — dieses Ziel im Catchall.',
  'account.delete.done': 'Konto {{address}} gelöscht.',
} as const;
