/**
 * Texte des Bereichs `audit` — Revisionsprotokoll (S4).
 *
 * **Gehört dem Bereichsagenten operations** (siehe `frontend/src/pages/README.md`).
 * Jeder Schlüssel hier beginnt mit `audit.`; `catalogue.test.ts` prüft das, damit
 * zwei Bereiche nie dieselbe Datei oder denselben Schlüssel anfassen.
 * Eingemischt wird die Datei in `../de.ts`.
 */
export const deAudit = {
  // --- Liste ------------------------------------------------------------------
  'audit.list.title': 'Revisionsprotokoll',
  'audit.list.description':
    'Wer wann was geändert hat, und von wo. Lesezugriffe stehen nicht darin, Anmeldungen und abgelehnte Versuche schon.',
  'audit.list.timeZone': 'Zeiten in der Zeitzone dieses Browsers ({{zone}}).',
  'audit.list.empty': 'Keine Einträge für diese Auswahl.',
  'audit.list.column.recordedAt': 'Zeitpunkt',
  'audit.list.column.actor': 'Wer',
  'audit.list.column.action': 'Vorgang',
  'audit.list.column.object': 'Objekt',
  'audit.list.column.domain': 'Domain',
  'audit.list.column.outcome': 'Ergebnis',
  'audit.list.column.requestId': 'Vorgangsnummer',

  // --- Filter (U-65) ------------------------------------------------------------
  'audit.list.filter.actor': 'Benutzername (genau)',
  'audit.list.filter.action': 'Vorgang (Anfang)',
  'audit.list.filter.objectType': 'Objektart',
  'audit.list.filter.objectId': 'Objekt (genau)',
  'audit.list.filter.domain': 'Domain',
  'audit.list.filter.outcome': 'Ergebnis',
  'audit.list.filter.outcomeAll': 'Alle',
  'audit.list.filter.requestId': 'Vorgangsnummer',
  'audit.list.filter.partOf': 'Teil von Eintrag',
  'audit.list.filter.since': 'Von',
  'audit.list.filter.until': 'Bis',
  'audit.list.filter.reset': 'Alle Filter zurücksetzen',

  // --- Ergebnis und Rolle -------------------------------------------------------
  'audit.outcome.succeeded': 'ausgeführt',
  'audit.outcome.denied': 'abgelehnt',
  'audit.outcome.failed': 'gescheitert',
  'audit.role.superuser': 'Superuser',
  'audit.role.domainmaster': 'Domainmaster',

  // --- Einzelansicht --------------------------------------------------------------
  'audit.detail.title': 'Protokolleintrag {{id}}',
  'audit.detail.field.recordedAt': 'Zeitpunkt',
  'audit.detail.field.actor': 'Wer',
  'audit.detail.field.actorRole': 'Rolle',
  'audit.detail.field.clientIp': 'Herkunft',
  'audit.detail.field.requestId': 'Vorgangsnummer',
  'audit.detail.field.action': 'Vorgang',
  'audit.detail.field.object': 'Objekt',
  'audit.detail.field.domain': 'Domain',
  'audit.detail.field.outcome': 'Ergebnis',
  'audit.detail.field.reason': 'Grund (für den Betrieb, englisch)',
  'audit.detail.field.partOf': 'Teil von Eintrag',
  'audit.detail.field.before': 'Vorher',
  'audit.detail.field.after': 'Nachher',
  'audit.detail.none': '—',
  'audit.detail.notExisting': 'existierte nicht',
  'audit.detail.redactedHint': 'Passwörter und Hashes sind bereits beim Schreiben entfernt worden.',
  'audit.detail.filter.request': 'Alle Einträge dieser Anfrage',
  'audit.detail.filter.object': 'Verlauf dieses Objekts',
  'audit.detail.filter.operation': 'Alle Einträge dieses Vorgangs',
} as const;
