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

  'list.field.name': 'Name',
  'list.field.address': 'Adresse',
  'list.field.tag': 'Kennzeichen',
  'list.field.members': 'Mitglieder',

  'list.form.hint.name':
    'Freier Anzeigename mit bis zu 255 Zeichen. Leerzeichen und Umlaute sind möglich, Emojis und Steuerzeichen nicht.',
  'list.form.hint.tag': 'Freitext. mailadmin wertet das Kennzeichen nicht aus.',
  'list.form.hint.members': 'Eine Mailadresse pro Zeile.',
  'list.form.error.memberNotDeliverable':
    'Zeile {{line}}: An dieses Mitglied kann der Verteiler nicht zustellen. Erlaubt sind Adressen ohne Umlaute mit einer Domain mit Punkt, wahlweise hinter einem Namen in der Form „Name <adresse>“; Anführungszeichen, Klammern, Doppelpunkte, Backslashes und „&“ gehen nicht.',
  'list.form.error.memberOutsideLatin1':
    'Zeile {{line}}: Dieses Mitglied enthält Zeichen, die die Datenbank nicht speichern kann, etwa Emojis oder das Euro-Zeichen.',

  'list.create.title': 'Mailingliste anlegen',
  'list.create.submit': 'Anlegen',
  'list.create.saved': 'Liste {{name}} angelegt.',
  'list.create.savedPendingRun':
    'Liste {{name}} angelegt. Sie stellt sofort zu; die Aliasdatei des Mailservers wird erst beim nächsten Konfigurationslauf ergänzt.',

  'list.detail.action.delete': 'Löschen',
  'list.detail.hint.address': 'Die Adresse lässt sich nicht ändern. Zum Ändern der Adresse die Liste löschen und neu anlegen.',
  'list.detail.addressUnusable':
    'Die gespeicherte Adresse ist keine Mailadresse. Sie wird angezeigt, aber nicht automatisch korrigiert.',
  'list.detail.unusableMembers#one':
    'Ein Eintrag ist keine Mailadresse, an die der Verteiler zustellt: {{members}}. Er bleibt erhalten, solange die Mitglieder nicht geändert werden; neue Einträge dieser Art nimmt der Server nicht an.',
  'list.detail.unusableMembers#other':
    '{{count}} Einträge sind keine Mailadressen, an die der Verteiler zustellt: {{members}}. Sie bleiben erhalten, solange die Mitglieder nicht geändert werden; neue Einträge dieser Art nimmt der Server nicht an.',
  'list.detail.unchanged': 'Es gibt nichts zu speichern.',
  'list.detail.saved': 'Liste {{name}} gespeichert.',
  'list.detail.deleted': 'Liste {{name}} gelöscht.',
  'list.detail.deletedPendingRun':
    'Liste {{name}} gelöscht. Mail an ihre Adresse wird ab sofort nicht mehr angenommen; aus der Aliasdatei des Mailservers verschwindet sie erst beim nächsten Konfigurationslauf.',
  'list.detail.reloaded': 'Die Liste wurde neu geladen. Prüfe den Stand und ändere erneut.',

  'list.delete.title': 'Liste {{name}} löschen?',
  'list.delete.message': 'Die Liste und ihre Adresse {{address}} werden entfernt. Das lässt sich nicht rückgängig machen.',
} as const;
