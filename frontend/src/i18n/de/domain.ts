/**
 * Texte des Bereichs `domain` — Domains (A1).
 *
 * **Gehört dem Bereichsagenten domains-admins-lists** (siehe `frontend/src/pages/README.md`).
 * Jeder Schlüssel hier beginnt mit `domain.`; `catalogue.test.ts` prüft das, damit
 * zwei Bereiche nie dieselbe Datei oder denselben Schlüssel anfassen.
 * Eingemischt wird die Datei in `../de.ts`.
 */
export const deDomain = {
  // Liste
  'domain.list.title': 'Domains',
  'domain.list.action.create': 'Domain anlegen',
  'domain.list.filter.name': 'Name enthält',
  'domain.list.empty': 'Noch keine Domain.',
  'domain.list.emptyFiltered': 'Keine Domain passt zum Filter.',
  'domain.list.unconventionalName': 'Altname',

  // Felder, in Liste, Detail und Formular gleich benannt
  'domain.field.name': 'Domainname',
  'domain.field.accounts': 'Konten',
  'domain.field.maxAccounts': 'Höchstzahl Konten',
  'domain.field.defaultQuota': 'Kontingent je neuem Konto',
  'domain.field.domainQuota': 'Kontingent der Domain',
  'domain.field.namingScheme': 'Benutzernamen neuer Konten',
  'domain.field.defaultFolders': 'Standardordner neuer Postfächer',
  'domain.field.legacyPrefix': 'Präfix (Altfeld)',
  'domain.field.transport': 'Transport (Altfeld)',
  'domain.field.freeNames': 'Freie Benutzernamen (webcyradm, ohne Wirkung)',
  'domain.field.freeAddress': 'Freie Adressen (webcyradm, ohne Wirkung)',

  // Werte
  'domain.value.unlimited': 'unbegrenzt',
  'domain.value.accountsOfLimit': '{{count}} von {{limit}}',
  'domain.value.accountsUnlimited#one': '{{count}} Konto, keine Höchstzahl',
  'domain.value.accountsUnlimited#other': '{{count}} Konten, keine Höchstzahl',
  'domain.value.schemeInherited': 'Systemvorgabe: {{scheme}}',
  'domain.value.noFolders': 'keine',
  'domain.value.yes': 'ja',
  'domain.value.no': 'nein',

  // Namensschema
  'domain.scheme.value.inherited': 'Systemvorgabe übernehmen',
  'domain.scheme.value.dotted': 'mit Punkt (name.example.test)',
  'domain.scheme.value.fqun': 'mit @ (name@example.test)',

  // Kontingentfeld
  'domain.quota.unitLabel': 'Einheit',
  'domain.quota.unit.kib': 'KiB',
  'domain.quota.unit.mib': 'MiB',
  'domain.quota.unit.gib': 'GiB',
  'domain.quota.readBack': 'Entspricht {{value}}. Leer heißt unbegrenzt.',

  // Formular (Anlegen und Ändern)
  'domain.form.section.limits': 'Grenzen',
  'domain.form.section.mailboxes': 'Neue Postfächer',
  'domain.form.section.legacy': 'Altfelder',
  'domain.form.hint.maxAccounts': 'Leer heißt unbegrenzt; 0 heißt, dass kein Konto angelegt werden kann.',
  'domain.form.hint.defaultQuota': 'Gilt für Konten, die künftig angelegt werden. Leer heißt unbegrenzt.',
  'domain.form.hint.domainQuota': 'Wird angezeigt, aber nicht bei jeder Kontoänderung durchgesetzt. Leer heißt unbegrenzt.',
  'domain.form.hint.namingScheme':
    'Bestimmt nur die Benutzernamen künftiger Konten. Bestehende Konten behalten ihren Namen.',
  'domain.form.hint.namingSchemeEffective':
    'Bestimmt nur die Benutzernamen künftiger Konten; die Systemvorgabe ist derzeit „{{scheme}}“. Bestehende Konten behalten ihren Namen.',
  'domain.form.hint.defaultFolders':
    'Ein Ordner je Zeile. Gilt für Postfächer, die künftig angelegt werden; bestehende bleiben unverändert.',
  'domain.form.hint.legacy': 'Diese Schalter stammen aus webcyradm. mailadmin speichert sie, wertet sie aber nicht aus.',
  'domain.form.error.notANumber': 'Bitte eine Zahl eingeben.',

  // Anlegen
  'domain.create.title': 'Domain anlegen',
  'domain.create.description':
    'Pflicht ist nur der Name. Was leer bleibt, erhält den Standardwert, den auch webcyradm gesetzt hätte.',
  'domain.create.hint.name': 'Internationalisierte Namen bitte in Punycode (xn--…) angeben.',
  'domain.create.submit': 'Anlegen',
  'domain.create.saved': 'Domain {{name}} angelegt.',
  'domain.create.doneTitle': '{{name}} ist angelegt',

  // Nächste Schritte nach dem Anlegen
  'domain.nextSteps.created': 'Die Domain {{name}} ist in der Datenbank angelegt.',
  'domain.nextSteps.title': 'Noch zu erledigen',
  'domain.nextSteps.intro':
    'Diese Schritte liegen außerhalb von mailadmin. mailadmin kann sie nicht ausführen und prüft sie nicht. Mail für {{name}} kommt erst an, wenn sie erledigt sind.',
  'domain.nextSteps.none': 'Für diese Domain ist außerhalb von mailadmin nichts mehr zu tun.',
  'domain.nextSteps.notStored': 'Die Häkchen dienen nur der Übersicht und werden nicht gespeichert.',
  'domain.nextSteps.open': 'Zur Domain',
  'domain.nextSteps.another': 'Weitere Domain anlegen',
  'domain.nextSteps.step.saltPillar.title': 'Domain im Salt-Pillar eintragen',
  'domain.nextSteps.step.saltPillar.detail':
    'Die Konfiguration des Mailservers gehört Salt. mailadmin schreibt sie nicht.',
  'domain.nextSteps.step.saltApply.title': 'Konfigurationslauf ausführen',
  'domain.nextSteps.step.saltApply.detail': 'Erst danach ist der Pillar-Eintrag auf dem Server wirksam.',
  'domain.nextSteps.step.cyrusLoginrealm.title': 'Domain in die Cyrus-loginrealms aufnehmen',
  'domain.nextSteps.step.cyrusLoginrealm.detail':
    'Nötig, weil neue Konten von {{name}} Benutzernamen mit @ erhalten. Die Datei verwaltet Salt.',
  'domain.nextSteps.step.dnsMx.title': 'MX-Eintrag setzen',
  'domain.nextSteps.step.dnsMx.detail': 'Ohne MX-Eintrag erreicht keine Mail für {{name}} diesen Server.',
  'domain.nextSteps.step.dnsSpf.title': 'SPF-Eintrag setzen',
  'domain.nextSteps.step.dnsSpf.detail': 'Legt fest, welche Server für {{name}} senden dürfen.',
  'domain.nextSteps.step.dnsDkim.title': 'DKIM-Schlüssel veröffentlichen',
  'domain.nextSteps.step.dnsDkim.detail': 'Der öffentliche Schlüssel gehört als TXT-Eintrag in die Zone von {{name}}.',
  'domain.nextSteps.step.dnsDmarc.title': 'DMARC-Eintrag setzen',
  'domain.nextSteps.step.dnsDmarc.detail': 'Sagt empfangenden Servern, wie sie mit nicht authentifizierter Mail umgehen sollen.',

  // Detail
  'domain.detail.action.edit': 'Bearbeiten',
  'domain.detail.action.rename': 'Umbenennen',
  'domain.detail.action.delete': 'Löschen',
  'domain.detail.section.limits': 'Grenzen',
  'domain.detail.section.mailboxes': 'Postfächer',
  'domain.detail.section.legacy': 'Altfelder',
  'domain.detail.domainQuotaNote':
    'Das Kontingent der Domain wird angezeigt, aber nicht bei jeder Kontoänderung durchgesetzt.',
  'domain.detail.legacyNote': 'Diese Werte stammen aus webcyradm. mailadmin zeigt sie, wertet sie aber nicht aus.',
  'domain.detail.unconventionalName':
    'Dieser Domainname würde heute so nicht mehr angenommen (etwa Großbuchstaben oder ein Unterstrich). Er wird angezeigt, wie er gespeichert ist.',
  'domain.detail.link.accounts#one': '{{count}} Konto anzeigen',
  'domain.detail.link.accounts#other': '{{count}} Konten anzeigen',
  'domain.detail.link.addresses': 'Adressen anzeigen',
  'domain.detail.deleted': 'Domain {{name}} gelöscht.',

  // Löschen
  'domain.delete.title': '{{name}} löschen?',
  'domain.delete.message':
    'Die Domain und ihre Zuordnungen zu Domainverwaltern werden entfernt. Eine Domain mit Konten oder Adressen lehnt der Server ab.',
  'domain.delete.hasAccounts#one': 'Die Domain hat noch {{count}} Konto; das Löschen wird abgelehnt.',
  'domain.delete.hasAccounts#other': 'Die Domain hat noch {{count}} Konten; das Löschen wird abgelehnt.',

  // Bearbeiten
  'domain.edit.title': 'Bearbeiten',
  'domain.edit.heading': '{{name}} bearbeiten',
  'domain.edit.nameHint': 'Der Name lässt sich hier nicht ändern. Umbenennen ist ein eigener Vorgang auf der Seite der Domain.',
  'domain.edit.unchanged': 'Es wurde nichts geändert.',
  'domain.edit.saved': 'Domain {{name}} gespeichert.',
  'domain.edit.reloaded':
    'Die Domain wurde inzwischen anderweitig geändert. Das Formular zeigt jetzt den aktuellen Stand; bitte die Änderung erneut eingeben.',

  // Umbenennen
  'domain.rename.title': '{{name}} umbenennen',
  'domain.rename.submit': 'Umbenennen',
  'domain.rename.field.newName': 'Neuer Domainname',
  'domain.rename.consequence.mailboxes#one':
    'Das Postfach der Domain wird im Mailspeicher umbenannt, bevor die Datenbank geändert wird. Das kann dauern.',
  'domain.rename.consequence.mailboxes#other':
    'Alle {{count}} Postfächer der Domain werden im Mailspeicher umbenannt, bevor die Datenbank geändert wird. Das kann dauern.',
  'domain.rename.consequence.logins':
    'Der Domainname steckt in jedem Benutzernamen: Alle Konteninhaber melden sich danach mit dem neuen Namen an.',
  'domain.rename.consequence.external':
    'Salt-Pillar, DNS und DKIM kennen den neuen Namen nicht; sie müssen außerhalb von mailadmin nachgezogen werden.',
  'domain.rename.error.sameName': 'Der neue Name ist derselbe wie der bisherige.',
  'domain.rename.done': '{{from}} heißt jetzt {{to}}.',
  'domain.rename.incomplete.title': 'Umbenennung nicht abgeschlossen',
  'domain.rename.incomplete.detail':
    'Die Seite zeigt den Stand, den der Server jetzt meldet ({{name}}). Bitte die Reparaturwarteschlange prüfen, bevor die Umbenennung erneut versucht wird.',
  'domain.rename.incomplete.repairs': 'Zur Reparaturwarteschlange',
} as const;
