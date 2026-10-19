import { deAccount } from './de/account';
import { deAddress } from './de/address';
import { deAdmin } from './de/admin';
import { deAudit } from './de/audit';
import { deDomain } from './de/domain';
import { deList } from './de/list';
import { deMaintenance } from './de/maintenance';
import { deErrors } from './de.errors';

/**
 * Die deutschen Texte. Neben `de.errors.ts` der einzige Ort im Frontend, an dem
 * ein sichtbarer Satz steht (E-16).
 *
 * **Schlüsselkonvention (U-88)** — bindend für jede weitere Ansicht:
 *
 * - `<bereich>.<ansicht>.<element>[.<detail>]`, etwa
 *   `account.list.column.quota`. Der Bereich ist eine Ressource der API im
 *   Singular (`domain`, `account`, `address`, `admin`, `list`, `audit`,
 *   `maintenance`) oder ein Rahmenbereich (`app`, `nav`, `signIn`, `signOut`,
 *   `overview`, `preferences`, `state`, `role`, `format`, `action`).
 *   `keyConvention.ts` hält die Liste; ein neuer Bereich wird dort eingetragen.
 * - Jedes Segment `lowerCamelCase`, flach in dieser Datei, Punkte im Schlüssel.
 * - Pluralformen als `<schlüssel>#<kategorie>` mit den CLDR-Kategorien
 *   (`#one`, `#other`, …); aufgerufen wird der Grundschlüssel mit `count`.
 * - Platzhalter als `{{name}}`. Zahlen werden beim Einsetzen nach der Sprache
 *   formatiert.
 * - Fehlertexte stehen in `de.errors.ts` unter `errors.<code>`.
 *
 * **Aufteilung (U-130).** Diese Datei hält nur den Rahmen: Navigation,
 * Zustände, die geteilten Bausteine unter `components/`. Die Texte der
 * fachlichen Bereiche stehen je Ressourcenbereich in `de/<bereich>.ts` und
 * werden unten eingemischt — so bearbeiten parallel arbeitende Bereiche nie
 * dieselbe Datei. Ein Schlüssel eines Ressourcenbereichs gehört **nur** in
 * dessen Datei; `catalogue.test.ts` lehnt ihn hier ab.
 *
 * `catalogue.test.ts` prüft Form, Vollständigkeit und Gebrauch jedes Schlüssels.
 */
export const deInterface = {
  // --- Produkt und Rahmen ---------------------------------------------------
  'app.name': 'mailadmin',
  'app.title': '{{page}} — mailadmin',

  // --- Navigation -----------------------------------------------------------
  'nav.label': 'Hauptnavigation',
  'nav.open': 'Navigation öffnen',
  'nav.close': 'Navigation schließen',
  'nav.overview': 'Übersicht',
  'nav.account': 'Konto',
  'nav.ownPassword': 'Eigenes Passwort ändern',
  'nav.breadcrumbs': 'Pfad',
  'nav.section.management': 'Verwaltung',
  'nav.section.operations': 'Betrieb',
  'nav.domains': 'Domains',
  'nav.accounts': 'Konten',
  'nav.addresses': 'Adressen',
  'nav.lists': 'Mailinglisten',
  'nav.admins': 'Administratoren',
  'nav.repairs': 'Reparaturen',
  'nav.reconciliation': 'Abgleich',
  'nav.health': 'Systemzustand',
  'nav.audit': 'Revisionsprotokoll',

  // --- Anmeldung ------------------------------------------------------------
  'signIn.title': 'Anmelden',
  'signIn.subtitle': 'Verwaltung für Postfächer, Domains und Adressen.',
  'signIn.username': 'Benutzername',
  'signIn.password': 'Passwort',
  'signIn.submit': 'Anmelden',
  'signIn.submitting': 'Wird angemeldet …',
  'signIn.usernameRequired': 'Bitte einen Benutzernamen eingeben.',
  'signIn.passwordRequired': 'Bitte ein Passwort eingeben.',

  // --- Abmelden -------------------------------------------------------------
  'signOut.action': 'Abmelden',
  'signOut.inProgress': 'Wird abgemeldet …',

  // --- Übersicht ------------------------------------------------------------
  'overview.title': 'Übersicht',
  'overview.greeting': 'Angemeldet als {{username}}.',
  'overview.roleLabel': 'Rolle',
  'overview.scopeLabel': 'Domains',
  'overview.scopeAll': 'Alle Domains',
  'overview.scopeNone': 'Keine Domain zugewiesen.',
  'overview.scopeCount#one': 'Eine Domain zugewiesen.',
  'overview.scopeCount#other': '{{count}} Domains zugewiesen.',
  'overview.permissionsLabel': 'Berechtigungen',
  'overview.sessionExpiresLabel': 'Token gültig bis',
  'overview.pendingViews':
    'Die fachlichen Ansichten für Domains, Konten, Adressen und Listen folgen mit den nächsten Arbeitspaketen.',

  // --- Rollen ---------------------------------------------------------------
  'role.superuser': 'Systemverwaltung',
  'role.domainmaster': 'Domainverwaltung',

  // --- Allgemeine Zustände --------------------------------------------------
  'state.loading': 'Wird geladen …',
  'state.error.title': 'Das hat nicht geklappt',
  'state.error.retry': 'Erneut versuchen',
  'state.error.reload': 'Neu laden',
  'state.error.requestId': 'Vorgangsnummer: {{requestId}}',
  'state.error.requestIdHint':
    'Bitte die Vorgangsnummer bei einer Rückfrage angeben — damit lässt sich der Vorgang im Protokoll finden.',
  'state.notFound.title': 'Seite nicht gefunden',
  'state.notFound.detail': 'Die aufgerufene Adresse gibt es nicht.',
  'state.notFound.back': 'Zur Übersicht',
  'state.crash.title': 'Die Oberfläche ist auf einen Fehler gelaufen',
  'state.crash.detail': 'Ein erneutes Laden der Seite hilft meist.',
  'state.crash.reload': 'Seite neu laden',
  'state.forbidden.title': 'Keine Berechtigung',
  'state.forbidden.detail': 'Diese Ansicht ist für die angemeldete Rolle nicht freigegeben.',

  // --- Allgemeine Schaltflächen (nur, was die Bausteine selbst brauchen) ----
  'action.cancel': 'Abbrechen',
  'action.confirm': 'Bestätigen',
  'action.delete': 'Löschen',
  'action.save': 'Speichern',
  'action.saving': 'Wird gespeichert …',
  'action.close': 'Schließen',

  // --- Tabellen (components/DataTable) -------------------------------------
  'table.empty': 'Keine Einträge.',
  'table.sort.ascending': 'aufsteigend sortiert',
  'table.sort.descending': 'absteigend sortiert',
  'table.filter.clear': 'Filter leeren',
  'table.filter.invalidDotted':
    'Nur Kleinbuchstaben, Ziffern und „_“, Teile durch Punkte getrennt (etwa „account.create“). Der Filter wird so nicht angewendet.',
  'table.filter.invalidPlain':
    'Nur Kleinbuchstaben, Ziffern und „_“ (etwa „account“). Der Filter wird so nicht angewendet.',
  'table.pagination.rowsPerPage': 'Einträge je Seite',
  'table.pagination.displayed': '{{from}}–{{to}} von {{count}}',
  'table.pagination.first': 'Erste Seite',
  'table.pagination.previous': 'Vorige Seite',
  'table.pagination.next': 'Nächste Seite',
  'table.pagination.last': 'Letzte Seite',

  // --- Bestätigungsdialog (components/ConfirmDialog) ------------------------
  'confirm.typeToConfirm': 'Zur Bestätigung bitte „{{value}}“ eingeben.',
  'confirm.typedLabel': 'Bestätigung',

  // --- Eigene Einstellungen (W2) -------------------------------------------
  'preferences.language.title': 'Sprache und Zeit',
  'preferences.language.label': 'Sprache der Oberfläche',
  'preferences.language.followBrowser': 'Wie im Browser eingestellt ({{language}})',
  'preferences.language.saved': 'Gespeichert.',
  'preferences.language.timeZone':
    'Zeiten erscheinen in der Zeitzone dieses Browsers ({{timeZone}}). Gespeichert wird in UTC.',
  // Der Name jeder Sprache, in dieser Sprache geschrieben. Eine neue
  // Sprachdatei trägt hier ihre eigenen Namen für alle Sprachen ein.
  'preferences.localeName.de': 'Deutsch',

  // --- Formate --------------------------------------------------------------
  // Binäre Einheiten, weil Cyrus in KiB rechnet und `domain.quota` KiB speichert.
  'format.size.byte': '{{value}} B',
  'format.size.kibibyte': '{{value}} KiB',
  'format.size.mebibyte': '{{value}} MiB',
  'format.size.gibibyte': '{{value}} GiB',
  'format.size.tebibyte': '{{value}} TiB',
} as const;

/**
 * Die Bereichsdateien, nach Ressourcenbereich (U-88) — der Schlüssel hier ist
 * zugleich das Präfix, das jeder Text der Datei tragen muss.
 */
export const deAreas = {
  domain: deDomain,
  admin: deAdmin,
  list: deList,
  account: deAccount,
  address: deAddress,
  maintenance: deMaintenance,
  audit: deAudit,
} as const;

export const de = {
  ...deInterface,
  ...deDomain,
  ...deAdmin,
  ...deList,
  ...deAccount,
  ...deAddress,
  ...deMaintenance,
  ...deAudit,
  ...deErrors,
} as const;
