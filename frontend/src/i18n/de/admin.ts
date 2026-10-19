/**
 * Texte des Bereichs `admin` — Administratoren (A4).
 *
 * **Gehört dem Bereichsagenten domains-admins-lists** (siehe `frontend/src/pages/README.md`).
 * Jeder Schlüssel hier beginnt mit `admin.`; `catalogue.test.ts` prüft das, damit
 * zwei Bereiche nie dieselbe Datei oder denselben Schlüssel anfassen.
 * Eingemischt wird die Datei in `../de.ts`.
 */
export const deAdmin = {
  'admin.list.title': 'Administratoren',
  'admin.list.action.create': 'Administrator anlegen',
  'admin.list.action.ownPassword': 'Eigenes Passwort ändern',
  'admin.list.empty': 'Es gibt noch keine Administratoren.',
  'admin.list.emptyFiltered': 'Kein Administrator passt zum Filter.',
  'admin.list.filter.username': 'Benutzername enthält',
  'admin.list.filter.role': 'Rolle',
  'admin.list.filter.anyRole': 'Alle Rollen',

  'admin.field.username': 'Benutzername',
  'admin.field.role': 'Rolle',
  'admin.field.domains': 'Domains',
  'admin.field.password': 'Passwort',
  'admin.field.passwordFormat': 'Hashverfahren',

  'admin.passwordFormat.argon2id': 'Argon2id',
  'admin.passwordFormat.bcrypt': 'bcrypt',
  'admin.passwordFormat.md5crypt': 'MD5-crypt',
  'admin.passwordFormat.sha256crypt': 'SHA-256-crypt',
  'admin.passwordFormat.sha512crypt': 'SHA-512-crypt',
  'admin.passwordFormat.descrypt': 'DES-crypt',
  'admin.passwordFormat.sha1hex': 'SHA-1 (hex)',
  'admin.passwordFormat.mysqlPassword': 'MySQL PASSWORD()',
  'admin.passwordFormat.plaintext': 'Klartext',
  'admin.passwordFormat.unknown': 'Unbekannt',
  'admin.passwordFormat.legacy': 'Altverfahren',
  'admin.passwordFormat.legacyHint':
    'Das Passwort liegt in einem Altverfahren vor. Es bleibt gültig und wird bei der nächsten Anmeldung auf Argon2id umgestellt.',

  'admin.scope.all': 'Alle Domains',
  'admin.scope.none': 'Keine Domain zugeordnet',
  'admin.scope.orphanedChip': '{{domain}} (verwaist)',
  'admin.scope.orphanedHint': 'Die Zuordnung verweist auf eine Domain, die es nicht mehr gibt.',

  'admin.form.hint.password': 'Mindestens zwölf Zeichen.',
  'admin.form.hint.domains': 'Die Domains, die diese Domainverwaltung sehen und verwalten darf.',
  'admin.form.hint.domainsOrphaned':
    'Verwaiste Zuordnungen sind markiert. Entferne sie vor dem Speichern, sonst lehnt der Server die Änderung ab.',
  'admin.form.domainsNoOptions': 'Keine passende Domain',

  'admin.create.title': 'Administrator anlegen',
  'admin.create.submit': 'Anlegen',
  'admin.create.hint.username': 'Der Name, mit dem sich der Administrator anmeldet. Er lässt sich später nicht ändern.',
  'admin.create.saved': 'Administrator {{username}} angelegt.',

  'admin.detail.action.delete': 'Löschen',
  'admin.detail.selfDeletion': 'Das eigene Konto lässt sich nicht löschen.',
  'admin.detail.section.state': 'Zustand',
  'admin.detail.section.change': 'Ändern',
  'admin.detail.wildcardDomainmaster':
    'Diese Domainverwaltung ist allen Domains zugeordnet (Altbestand mit Platzhalter-Zuordnung).',
  'admin.detail.orphaned#one': 'Eine Zuordnung ist verwaist: {{domains}}',
  'admin.detail.orphaned#other': '{{count}} Zuordnungen sind verwaist: {{domains}}',
  'admin.detail.field.newPassword': 'Neues Passwort',
  'admin.detail.hint.newPassword': 'Leer lassen, um das Passwort nicht zu ändern.',
  'admin.detail.hint.ownRole': 'Das ist dein eigenes Konto. Die letzte Systemverwaltung herabzustufen lehnt der Server ab.',
  'admin.detail.unchanged': 'Es gibt nichts zu speichern.',
  'admin.detail.saved': 'Administrator {{username}} gespeichert.',
  'admin.detail.deleted': 'Administrator {{username}} gelöscht.',
  'admin.detail.reloaded': 'Der Administrator wurde neu geladen. Prüfe den Stand und ändere erneut.',

  'admin.delete.title': 'Administrator {{username}} löschen?',
  'admin.delete.message':
    '{{username}} kann sich danach nicht mehr anmelden; alle offenen Sitzungen enden. Domain-Zuordnungen gehen verloren.',

  'admin.ownPassword.title': 'Eigenes Passwort ändern',
  'admin.ownPassword.description': 'Das Passwort, mit dem du dich bei mailadmin anmeldest.',
  'admin.ownPassword.field.current': 'Aktuelles Passwort',
  'admin.ownPassword.field.new': 'Neues Passwort',
  'admin.ownPassword.field.repeat': 'Neues Passwort wiederholen',
  'admin.ownPassword.error.mismatch': 'Die beiden Eingaben stimmen nicht überein.',
  'admin.ownPassword.signOutNotice':
    'Nach der Änderung enden alle Sitzungen, auch diese. Du meldest dich danach mit dem neuen Passwort an.',
  'admin.ownPassword.submit': 'Passwort ändern',
  'admin.ownPassword.saved': 'Passwort geändert. Bitte melde dich neu an.',
} as const;
