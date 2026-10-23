/**
 * Texte des Bereichs `address` — Adressen (A3).
 *
 * **Gehört dem Bereichsagenten accounts-addresses** (siehe `frontend/src/pages/README.md`).
 * Jeder Schlüssel hier beginnt mit `address.`; `catalogue.test.ts` prüft das, damit
 * zwei Bereiche nie dieselbe Datei oder denselben Schlüssel anfassen.
 * Eingemischt wird die Datei in `../de.ts`.
 */
export const deAddress = {
  // --- Liste ----------------------------------------------------------------
  'address.list.title': 'Adressen',
  'address.list.description':
    'Jede Zeile der Adresstabelle einer Domain — auch die, die in kein Muster passen.',
  'address.list.chooseDomain': 'Bitte eine Domain wählen.',
  'address.list.empty': 'Keine Zeilen, die zum Filter passen.',
  'address.list.action.createAlias': 'Alias anlegen',
  'address.list.action.deleteEntry': 'Zeile {{alias}} entfernen',
  'address.list.column.alias': 'Adresse',
  'address.list.column.type': 'Art',
  'address.list.column.destinations': 'Ziele',
  'address.list.column.sender': 'Darf senden',
  'address.list.column.status': 'Status',
  'address.list.column.actions': 'Aktionen',
  'address.list.filter.alias': 'Adresse enthält',
  'address.list.filter.type': 'Art',
  'address.list.filter.allTypes': 'Alle Arten',
  'address.list.type.mailboxAddress': 'Kontoadresse',
  'address.list.type.alias': 'Alias',
  'address.list.type.catchall': 'Catchall',
  'address.list.type.forward': 'Weiterleitung',
  'address.list.type.mailingList': 'Mailingliste',
  'address.list.type.unknown': 'Unbekannt',
  'address.list.unknownReason.noAlias': 'Die Zeile hat keine Adresse.',
  'address.list.unknownReason.noDestination': 'Die Zeile hat kein Ziel.',
  'address.list.unknownReason.aliasIsNotAnAddress': 'Die Adresse ist weder Mailadresse noch Kontoname.',
  'address.list.unknownReason.catchallWithoutDomain': 'Catchall ohne Domain.',
  'address.list.unknownReason.unusableValue': 'Ein Feld enthält einen unbrauchbaren Wert.',
  'address.list.unknownReason.listDestinationMismatch':
    'Die Zeile leitet an eine Mailingliste weiter, ist aber nicht deren eigener Eintrag.',
  'address.list.reserved': 'reserviert',
  'address.list.noDestination': 'kein Ziel',
  'address.list.senderNone': 'niemand',
  'address.list.status.enabled': 'aktiv',
  'address.list.status.disabled': 'deaktiviert',
  'address.list.deleteEntry.title': 'Zeile entfernen?',
  'address.list.deleteEntry.message':
    'Die Zeile {{alias}} → {{destinations}} wird aus der Adresstabelle entfernt. Postfix liest sie danach nicht mehr.',
  'address.list.deleteEntry.done': 'Zeile {{alias}} entfernt.',

  // --- Rohzeilen ------------------------------------------------------------
  'address.entries.label': 'Gespeicherte Zeilen',
  'address.entries.username': 'Feld username',

  // --- Gemeinsame Formularfelder --------------------------------------------
  'address.form.destinations': 'Ziele',
  'address.form.destinationsHint':
    'Mailadressen oder Benutzernamen von Konten, eines je Zeile oder durch Komma getrennt.',
  'address.form.sender': 'Darf als diese Adresse senden',
  'address.form.senderHint': 'Nur ein Konto unter den Zielen kann das Senderecht bekommen.',
  'address.form.senderAuto': 'Automatisch (das einzige Ziel, wenn es ein Konto ist)',
  'address.form.senderNone': 'Niemand',

  // --- Alias ----------------------------------------------------------------
  'address.alias.createTitle': 'Alias anlegen',
  'address.alias.description': 'Ein Alias leitet Mail an seine Ziele weiter.',
  'address.alias.field.localPart': 'Name vor dem @',
  'address.alias.field.domainSuffix': '@{{domain}}',
  'address.alias.field.enabled': 'Aktiv',
  'address.alias.action.create': 'Anlegen',
  'address.alias.action.delete': 'Alias löschen',
  'address.alias.created': 'Alias {{address}} angelegt.',
  'address.alias.saved': 'Alias gespeichert.',
  'address.alias.deleted': 'Alias {{address}} gelöscht.',
  'address.alias.destinations': 'Ziele: {{destinations}}',
  'address.alias.sender': 'Darf senden: {{account}}',
  'address.alias.senderNone': 'Niemand darf als diese Adresse senden.',
  'address.alias.disabledHint':
    'Ein deaktivierter Alias nimmt auch das Senderecht zurück. Mail an die Adresse fällt auf den Catchall der Domain durch, wenn es einen gibt.',
  'address.alias.notEditable':
    'Die gespeicherten Zeilen dieser Adresse lassen sich nicht gemeinsam bearbeiten. Sie werden einzeln in der Adressliste entfernt.',
  'address.alias.deleteTitle': 'Alias löschen?',
  'address.alias.deleteMessage':
    'Alle Alias-Zeilen von {{address}} werden entfernt. Mail an die Adresse wird danach abgewiesen oder geht an den Catchall.',

  // --- Reservierte Adressen (U-109) -----------------------------------------
  'address.reserved.message':
    '{{address}} ist eine reservierte Adresse (RFC 2142), die erreichbar sein sollte — Beschwerden und Rückläufer anderer Server gehen dorthin. Wirklich entfernen?',
  'address.reserved.confirmRemove': 'Trotzdem entfernen',
  'address.reserved.disableTitle': 'Reservierte Adresse deaktivieren?',
  'address.reserved.disableMessage':
    '{{address}} ist eine reservierte Adresse (RFC 2142), die erreichbar sein sollte. Wirklich deaktivieren?',
  'address.reserved.confirmDisable': 'Trotzdem deaktivieren',

  // --- Catchall -------------------------------------------------------------
  'address.catchall.title': 'Catchall',
  'address.catchall.explanation':
    'Nimmt Mail an jede Adresse von {{domain}} an, die keine eigene aktive Zeile hat. Eine konkrete Adresse gewinnt, solange sie aktiv ist; sind alle ihre Zeilen deaktiviert, fällt sie auf den Catchall durch.',
  'address.catchall.loading': 'Catchall wird geladen …',
  'address.catchall.none': 'Diese Domain hat keinen Catchall.',
  'address.catchall.destinations': 'Ziele: {{destinations}}',
  'address.catchall.sender': 'Darf als jede Adresse der Domain senden: {{account}}',
  'address.catchall.senderNone': 'Niemand darf als Catchall senden.',
  'address.catchall.notEditable':
    'Die gespeicherten Catchall-Zeilen lassen sich nicht gemeinsam bearbeiten. Sie werden einzeln in der Adressliste entfernt.',
  'address.catchall.action.create': 'Catchall einrichten',
  'address.catchall.action.edit': 'Catchall ändern',
  'address.catchall.action.remove': 'Catchall entfernen',
  'address.catchall.dialogTitle': 'Catchall für {{domain}}',
  'address.catchall.destinationsHint':
    'Ein Ziel in dieser Domain muss eine aktive Adresse oder ein Konto sein, sonst ginge die Mail im Kreis.',
  'address.catchall.enabled': 'Aktiv',
  'address.catchall.saved': 'Catchall gespeichert.',
  'address.catchall.removed': 'Catchall entfernt.',
  'address.catchall.confirmRemove.title': 'Catchall entfernen?',
  'address.catchall.confirmRemove.message':
    'Mail an Adressen von {{domain}} ohne eigene Zeile wird danach abgewiesen.',

  // --- Weiterleitung --------------------------------------------------------
  'address.forwards.title': 'Weiterleitung',
  'address.forwards.loading': 'Weiterleitung wird geladen …',
  'address.forwards.none': 'Keine Weiterleitung eingerichtet.',
  'address.forwards.destinationsHint': 'Wohin die Mail zusätzlich oder statt ins Postfach geht.',
  'address.forwards.keepCopy': 'Kopie im Postfach behalten',
  'address.forwards.copyRequired':
    'Bei diesem Namensschema bekommt das Postfach immer eine Kopie.',
  'address.forwards.noCopyHint':
    'Ohne Kopie erreicht die Mail das Postfach nicht — auch die Abwesenheitsnotiz antwortet dann nicht.',
  'address.forwards.enabled': 'Aktiv',
  'address.forwards.withCopy': 'Mit Kopie im Postfach.',
  'address.forwards.withoutCopy': 'Ohne Kopie im Postfach.',
  'address.forwards.notEditable':
    'Die gespeicherten Zeilen dieses Kontos sind nicht alle Weiterleitungen und lassen sich hier nicht bearbeiten.',
  'address.forwards.action.save': 'Weiterleitung speichern',
  'address.forwards.action.remove': 'Weiterleitung entfernen',
  'address.forwards.saved': 'Weiterleitung gespeichert.',
  'address.forwards.removed': 'Weiterleitung entfernt.',
  'address.forwards.confirmRemove.title': 'Weiterleitung entfernen?',
  'address.forwards.confirmRemove.message': 'Mail an das Konto geht danach nur noch ins Postfach.',

  // --- Abwesenheit ----------------------------------------------------------
  'address.vacation.title': 'Abwesenheitsnotiz',
  'address.vacation.loading': 'Abwesenheitsnotiz wird geladen …',
  'address.vacation.state.none': 'Keine Abwesenheitsnotiz aktiv.',
  'address.vacation.state.active': 'Die Abwesenheitsnotiz ist aktiv, bis sie entfernt wird.',
  'address.vacation.state.foreign':
    'Aktiv ist ein fremdes Sieve-Skript („{{name}}“), das mailadmin nicht geschrieben hat. Es wird nur nach Rückfrage ersetzt.',
  'address.vacation.state.unreadable':
    'Das Skript „{{name}}“ stammt von mailadmin, lässt sich aber nicht zurücklesen. Speichern ersetzt es.',
  'address.vacation.scriptLabel': 'Aktives Sieve-Skript',
  'address.vacation.field.subject': 'Betreff',
  'address.vacation.field.message': 'Text',
  'address.vacation.field.interval': 'Abstand in Tagen',
  'address.vacation.field.intervalHint': 'So lange bekommt derselbe Absender keine zweite Antwort (1–60).',
  'address.vacation.field.addresses': 'Antwortet für',
  'address.vacation.field.addressesHint':
    'Empfängeradressen, eine je Zeile. Leer: die eigene Adresse des Kontos.',
  'address.vacation.action.save': 'Abwesenheit speichern',
  'address.vacation.action.remove': 'Abwesenheit entfernen',
  'address.vacation.saved': 'Abwesenheitsnotiz gespeichert.',
  'address.vacation.removed': 'Abwesenheitsnotiz entfernt.',
  'address.vacation.takeOver.title': 'Fremdes Skript ersetzen?',
  'address.vacation.takeOver.message':
    'Das aktive Sieve-Skript „{{name}}“ wurde nicht von mailadmin geschrieben. Es wird deaktiviert, bleibt aber gespeichert; seine Regeln gelten danach nicht mehr.',
  'address.vacation.takeOver.confirm': 'Übernehmen',
  'address.vacation.confirmRemove.title': 'Abwesenheitsnotiz entfernen?',
  'address.vacation.confirmRemove.message': 'Absender bekommen danach keine automatische Antwort mehr.',
  'address.vacation.confirmRemove.foreign':
    'Das aktive Sieve-Skript „{{name}}“ wurde nicht von mailadmin geschrieben. Es wird deaktiviert, nicht gelöscht; seine Regeln gelten danach nicht mehr.',
} as const;
