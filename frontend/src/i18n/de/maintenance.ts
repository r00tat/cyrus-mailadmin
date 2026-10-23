/**
 * Texte des Bereichs `maintenance` — Betrieb: Reparaturen, Abgleich, Zustand (A6).
 *
 * **Gehört dem Bereichsagenten operations** (siehe `frontend/src/pages/README.md`).
 * Jeder Schlüssel hier beginnt mit `maintenance.`; `catalogue.test.ts` prüft das, damit
 * zwei Bereiche nie dieselbe Datei oder denselben Schlüssel anfassen.
 * Eingemischt wird die Datei in `../de.ts`.
 */
export const deMaintenance = {
  // --- Reparaturwarteschlange: Liste -------------------------------------------
  'maintenance.repairs.title': 'Reparaturwarteschlange',
  'maintenance.repairs.description':
    'Was zusammengesetzte Vorgänge zwischen Datenbank und Mailstore liegen gelassen haben. '
    + 'Nichts wird von selbst repariert: ein Eintrag wird wiederholt oder abgeschlossen, wenn jemand es veranlasst.',
  'maintenance.repairs.empty': 'Keine Einträge in der Warteschlange.',
  'maintenance.repairs.column.id': 'Nr.',
  'maintenance.repairs.column.recordedAt': 'Erfasst',
  'maintenance.repairs.column.operation': 'Vorgang',
  'maintenance.repairs.column.step': 'Schritt',
  'maintenance.repairs.column.subject': 'Betroffen',
  'maintenance.repairs.column.status': 'Status',
  'maintenance.repairs.column.attempts': 'Fehlversuche',
  'maintenance.repairs.column.actions': 'Aktionen',
  'maintenance.repairs.filter.status': 'Status',
  'maintenance.repairs.filter.statusAll': 'Alle',
  'maintenance.repairs.filter.operation': 'Vorgang (Anfang)',
  'maintenance.repairs.filter.step': 'Schritt',
  'maintenance.repairs.status.open': 'offen',
  'maintenance.repairs.status.resolved': 'erledigt',
  'maintenance.repairs.status.retried': 'wiederholt',
  'maintenance.repairs.status.dismissed': 'abgeschlossen',
  'maintenance.repairs.notRetryable': 'nur abschließbar',

  // --- Reparaturwarteschlange: Aktionen ----------------------------------------
  'maintenance.repairs.action.retry': 'Wiederholen',
  'maintenance.repairs.action.resolve': 'Abschließen',
  'maintenance.repairs.action.retryEntry': 'Eintrag {{id}} wiederholen',
  'maintenance.repairs.action.resolveEntry': 'Eintrag {{id}} abschließen',
  'maintenance.repairs.retry.title': 'Schritt wiederholen?',
  'maintenance.repairs.retry.message':
    'Eintrag {{id}}: mailadmin führt den Schritt „{{step}}“ für „{{subject}}“ erneut aus. '
    + 'Ist der Zustand nicht mehr der beschriebene, geschieht nichts.',
  'maintenance.repairs.retry.deleteWarning':
    'Dieser Schritt löscht das Postfach samt aller darin liegenden Mails endgültig.',
  'maintenance.repairs.retry.success': 'Der Schritt wurde wiederholt; der Eintrag ist erledigt.',
  'maintenance.repairs.resolve.title': 'Eintrag abschließen?',
  'maintenance.repairs.resolve.message':
    'Eintrag {{id}} wird als abgeschlossen markiert. In Cyrus und in den Tabellen ändert sich dabei nichts — '
    + 'nur abschließen, wenn der Rest auf anderem Weg erledigt wurde.',
  'maintenance.repairs.resolve.note': 'Notiz (optional)',
  'maintenance.repairs.resolve.noteHint':
    'Was stattdessen getan wurde, für alle, die die Warteschlange später lesen. Höchstens 500 Zeichen.',
  'maintenance.repairs.resolve.success': 'Der Eintrag ist abgeschlossen.',

  // --- Reparaturwarteschlange: Einzelansicht -----------------------------------
  'maintenance.repairs.detail.title': 'Eintrag {{id}}',
  'maintenance.repairs.detail.section.state': 'Stand',
  'maintenance.repairs.detail.section.operator': 'Angaben für den Betrieb',
  'maintenance.repairs.detail.section.operatorHint':
    'So, wie der Server sie protokolliert hat (englisch). Sie beschreiben den Vorgang für den Betrieb und sind keine Meldung.',
  'maintenance.repairs.detail.section.parameters': 'Parameter einer Wiederholung',
  'maintenance.repairs.detail.field.recordedAt': 'Erfasst',
  'maintenance.repairs.detail.field.operation': 'Vorgang',
  'maintenance.repairs.detail.field.step': 'Schritt',
  'maintenance.repairs.detail.field.subject': 'Betroffen',
  'maintenance.repairs.detail.field.status': 'Status',
  'maintenance.repairs.detail.field.requestId': 'Vorgangsnummer',
  'maintenance.repairs.detail.field.attempts': 'Fehlversuche',
  'maintenance.repairs.detail.field.lastAttemptAt': 'Letzter Versuch',
  'maintenance.repairs.detail.field.lastError': 'Letzter Fehler',
  'maintenance.repairs.detail.field.resolvedAt': 'Erledigt am',
  'maintenance.repairs.detail.field.resolvedBy': 'Erledigt von',
  'maintenance.repairs.detail.field.note': 'Notiz',
  'maintenance.repairs.detail.field.intended': 'Vorgesehen',
  'maintenance.repairs.detail.field.reached': 'Erreicht',
  'maintenance.repairs.detail.field.reason': 'Grund',
  'maintenance.repairs.detail.parameters.none': 'Keine — dieser Schritt lässt sich nicht von mailadmin wiederholen.',
  'maintenance.repairs.detail.parameters.name': 'Name',
  'maintenance.repairs.detail.parameters.value': 'Wert',
  'maintenance.repairs.detail.auditLink': 'Im Revisionsprotokoll ansehen',
  'maintenance.repairs.detail.empty': '—',

  // --- Abgleich -----------------------------------------------------------------
  'maintenance.reconciliation.title': 'Abgleich',
  'maintenance.reconciliation.description':
    'Vergleicht Datenbank und Mailstore und zeigt, was nicht zueinander passt. Es wird nichts verändert.',
  'maintenance.reconciliation.run': 'Abgleich starten',
  'maintenance.reconciliation.rerun': 'Erneut abgleichen',
  'maintenance.reconciliation.running': 'Abgleich läuft …',
  'maintenance.reconciliation.notRun':
    'Der Abgleich liest alle Konten, Adressen und Listen und listet jedes Postfach in Cyrus. '
    + 'Er läuft deshalb nur auf Anforderung und wird nicht gespeichert.',
  'maintenance.reconciliation.checkedAt': 'Abgeglichen: {{checkedAt}}',
  'maintenance.reconciliation.checked':
    'Verglichen wurden {{accounts}} Konten, {{mailboxes}} Postfächer und {{virtualRows}} Adresszeilen.',
  'maintenance.reconciliation.clean': 'Keine Abweichungen gefunden.',
  'maintenance.reconciliation.summary.title': 'Zusammenfassung',
  'maintenance.reconciliation.summary.category': 'Kategorie',
  'maintenance.reconciliation.summary.count': 'Befunde',
  'maintenance.reconciliation.findings.count#one': '{{count}} Befund',
  'maintenance.reconciliation.findings.count#other': '{{count}} Befunde',
  'maintenance.reconciliation.column.subject': 'Betroffen',
  'maintenance.reconciliation.column.domain': 'Domain',
  'maintenance.reconciliation.column.related': 'Gegenstück',
  'maintenance.reconciliation.column.reason': 'Präzisierung',
  'maintenance.reconciliation.category.accountWithoutMailbox': 'Konto ohne Postfach',
  'maintenance.reconciliation.category.mailboxWithoutAccount': 'Postfach ohne Konto',
  'maintenance.reconciliation.category.shadowMailbox': 'Schatten-Postfach',
  'maintenance.reconciliation.category.unresolvableAccount': 'Nicht zuordenbares Konto',
  'maintenance.reconciliation.category.ambiguousUsername': 'Mehrdeutiger Benutzername',
  'maintenance.reconciliation.category.virtualOwnerMissing': 'Adresse ohne Besitzer',
  'maintenance.reconciliation.category.virtualTargetMissing': 'Ziel ohne Empfänger',
  'maintenance.reconciliation.category.virtualUnclassified': 'Nicht einzuordnende Adresszeile',
  'maintenance.reconciliation.categoryHint.accountWithoutMailbox':
    'Ein Konto ohne sein Postfach. Mail an dieses Konto wird abgewiesen.',
  'maintenance.reconciliation.categoryHint.mailboxWithoutAccount':
    'Ein Postfach, zu dem kein Konto gehört.',
  'maintenance.reconciliation.categoryHint.shadowMailbox':
    'Ein Postfach unter dem Namen, den ein bestehendes Konto im jeweils anderen Namensschema hätte.',
  'maintenance.reconciliation.categoryHint.unresolvableAccount':
    'Ein Konto, dessen Benutzername nicht zu seiner Domain passt.',
  'maintenance.reconciliation.categoryHint.ambiguousUsername':
    'Ein Benutzername mit Punkten, den auch ein Konto einer bestehenden Subdomain haben könnte.',
  'maintenance.reconciliation.categoryHint.virtualOwnerMissing':
    'Eine Adresszeile, deren Besitzer kein Konto ist und kein Domain-Platzhalter.',
  'maintenance.reconciliation.categoryHint.virtualTargetMissing':
    'Ein Ziel, das ins Leere führt: ein Benutzername ohne Konto oder eine lokal zugestellte Adresse, die nichts beantwortet.',
  'maintenance.reconciliation.categoryHint.virtualUnclassified':
    'Eine Adresszeile, die in keinen der vier Typen passt.',

  // --- Systemzustand ----------------------------------------------------------
  'maintenance.health.title': 'Systemzustand',
  'maintenance.health.description':
    'Jede Prüfung läuft beim Aufruf frisch: eine Abfrage an die Datenbank, eine Anmeldung an IMAP, '
    + 'eine geprüfte TLS-Sitzung zu ManageSieve.',
  'maintenance.health.refresh': 'Erneut prüfen',
  'maintenance.health.overall.ok': 'Alle Prüfungen bestanden.',
  'maintenance.health.overall.degraded': 'Mindestens eine Prüfung ist nicht bestanden.',
  'maintenance.health.checkedAt': 'Geprüft: {{checkedAt}}',
  'maintenance.health.column.component': 'Komponente',
  'maintenance.health.column.status': 'Ergebnis',
  'maintenance.health.column.code': 'Ursache',
  'maintenance.health.column.duration': 'Dauer',
  'maintenance.health.component.database': 'Datenbank',
  'maintenance.health.component.imap': 'IMAP',
  'maintenance.health.component.managesieve': 'ManageSieve',
  'maintenance.health.status.ok': 'in Ordnung',
  'maintenance.health.status.error': 'Fehler',
  'maintenance.health.status.skipped': 'übersprungen',
  'maintenance.health.duration': '{{milliseconds}} ms',
  'maintenance.health.noCode': '—',
  'maintenance.health.listDelivery.title': 'Listenzustellung',
  'maintenance.health.listDelivery.mode': 'Zustellweg',
  'maintenance.health.listDelivery.mode.alias': 'Aliasdatei (Altweg noch aktiv)',
  'maintenance.health.listDelivery.mode.transport': 'Pipe-Transport',
  'maintenance.health.listDelivery.transportDomain': 'Transportdomain',
  'maintenance.health.listDelivery.managed':
    'Die Transportdomain {{domain}} ist zugleich als Domain angelegt. Postfix stellt Mail an sie dann '
    + 'lokal zu, und der Listentransport erhält sie nie. Entferne die Domain oder wähle eine andere '
    + 'Transportdomain.',
  'maintenance.health.listDelivery.managedUnknown':
    'Ob die Transportdomain zugleich als Domain angelegt ist, konnte nicht geprüft werden, weil die '
    + 'Datenbank nicht antwortet.',
} as const;
