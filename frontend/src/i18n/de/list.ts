/**
 * Texte des Bereichs `list` — Mailinglisten (A5).
 *
 * **Gehört dem Bereichsagenten domains-admins-lists** (siehe `frontend/src/pages/README.md`).
 * Jeder Schlüssel hier beginnt mit `list.`; `catalogue.test.ts` prüft das, damit
 * zwei Bereiche nie dieselbe Datei oder denselben Schlüssel anfassen.
 * Eingemischt wird die Datei in `../de.ts`.
 */
export const deList = {
  'list.list.title': 'Mailinglisten',
  'list.list.action.create': 'Liste anlegen',
  'list.list.empty': 'Es gibt noch keine Mailinglisten.',
  'list.list.emptyFiltered': 'Keine Liste passt zum Filter.',
  'list.list.filter.name': 'Name enthält',
  'list.list.filter.address': 'Adresse enthält',
  'list.list.filter.member': 'Mitglied enthält',
  'list.list.filter.tag': 'Kennzeichen',
  'list.list.addressUnusable': 'keine Mailadresse',
  'list.list.memberCount#one': '{{count}} Mitglied',
  'list.list.memberCount#other': '{{count}} Mitglieder',
  'list.list.unusableCount#one': '{{count}} unbrauchbar',
  'list.list.unusableCount#other': '{{count}} unbrauchbar',

  'list.hint.configRun':
    'Änderungen an Mailinglisten wirken erst nach dem nächsten Lauf der Konfigurationsverwaltung: Eine neue Liste stellt bis dahin nicht zu, eine gelöschte stellt bis dahin weiter zu.',

  'list.field.name': 'Name',
  'list.field.address': 'Adresse',
  'list.field.tag': 'Kennzeichen',
  'list.field.members': 'Mitglieder',

  'list.form.hint.tag': 'Freitext. mailadmin wertet das Kennzeichen nicht aus.',
  'list.form.hint.members': 'Eine Mailadresse pro Zeile.',

  'list.create.title': 'Mailingliste anlegen',
  'list.create.submit': 'Anlegen',
  'list.create.hint.name':
    'Buchstaben, Ziffern und . - _ +, am Anfang und Ende ein Buchstabe oder eine Ziffer. Der Name lässt sich später nicht ändern.',
  'list.create.saved': 'Liste {{name}} angelegt.',
  'list.create.savedPendingRun': 'Liste {{name}} angelegt. Sie stellt nach dem nächsten Konfigurationslauf zu.',

  'list.detail.action.delete': 'Löschen',
  'list.detail.hint.name': 'Der Name lässt sich nicht ändern. Zum Umbenennen die Liste löschen und neu anlegen.',
  'list.detail.addressUnusable':
    'Die gespeicherte Adresse ist keine Mailadresse. Sie wird angezeigt, aber nicht automatisch korrigiert.',
  'list.detail.unusableMembers#one':
    'Ein Eintrag ist keine Mailadresse: {{members}}. Er bleibt erhalten, solange die Mitglieder nicht geändert werden; neue Einträge dieser Art nimmt der Server nicht an.',
  'list.detail.unusableMembers#other':
    '{{count}} Einträge sind keine Mailadressen: {{members}}. Sie bleiben erhalten, solange die Mitglieder nicht geändert werden; neue Einträge dieser Art nimmt der Server nicht an.',
  'list.detail.unchanged': 'Es gibt nichts zu speichern.',
  'list.detail.saved': 'Liste {{name}} gespeichert.',
  'list.detail.savedPendingRun': 'Liste {{name}} gespeichert. Die Änderung wirkt nach dem nächsten Konfigurationslauf.',
  'list.detail.deleted': 'Liste {{name}} gelöscht.',
  'list.detail.deletedPendingRun': 'Liste {{name}} gelöscht. Sie stellt bis zum nächsten Konfigurationslauf weiter zu.',
  'list.detail.reloaded': 'Die Liste wurde neu geladen. Prüfe den Stand und ändere erneut.',

  'list.delete.title': 'Liste {{name}} löschen?',
  'list.delete.message':
    'Die Liste wird aus der Datenbank entfernt. {{address}} stellt bis zum nächsten Lauf der Konfigurationsverwaltung weiter zu.',
} as const;
