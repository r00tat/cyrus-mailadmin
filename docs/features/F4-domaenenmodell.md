<!-- markdownlint-disable MD013 MD024 MD060 -->

# F4 — Domänenmodell

| | |
|---|---|
| **Stufe** | 1 — Gerüst |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 3.3 und 3.4 |
| **Status** | fertig |

---

## Ziel

Die Fachbegriffe als Value Objects mit ihren Regeln — insbesondere `MailboxIdentity`, das die
drei Schreibweisen eines Kontos an genau einer Stelle beherrscht, und die vier Typen, die sich
die Tabelle `virtual` teilen.

## Abgrenzung

- Kein Datenbankzugriff, kein IMAP. Reine Fachlogik, vollständig ohne Infrastruktur testbar.
- Keine Anwendungsfälle — die gehören zu den A-Paketen.

## Abhängigkeiten und geteilte Dateien

Grundlage für M1, M2, M3 und alle A-Pakete. Änderungen hier wirken überall.

## Vorgaben

- **Der Cyrus-Benutzername wird erzeugt, nie zerlegt.** Einzige erlaubte Auswertung: enthält
  der gespeicherte Name ein `@` (Architektur 3.3).
- `MailboxIdentity` immer aus dem Tripel (Local Part, Domain, Namensschema).
- Namensschema eines bestehenden Kontos wird erkannt, nie aus der Konfiguration abgeleitet
  (E-14).
- Die vier `virtual`-Typen sind explizite Klassen, kein Feldraten im Anwendungscode
  (Architektur 3.4).

## Artefakte

Namensraum durchgängig `Mailadmin\Domain\` (U-15), alle Wertobjekte `final readonly class`
(U-1). Keine Klasse kennt PDO, IMAP oder HTTP.

### `Domain/Model/` — Adressen und Identität

| Datei | Zweck |
|---|---|
| `DomainName.php` | Maildomain. Zwei Türen: `fromString()` streng, `fromStoredValue()` duldsam mit `isConventional()`-Kennzeichnung (U-22). |
| `LocalPart.php` | Teil vor dem `@`. Darf Punkte enthalten — der Grund, warum `MailboxIdentity` existiert. Kennt den `+`-Trenner. |
| `EmailAddress.php` | `local@domain`. Die einzige Klasse, die eine Zeichenkette am `@` zerlegen darf — beim Benutzernamen ist das verboten. |
| `NamingScheme.php` | Enum `Dotted`/`Fqun`, `detect()` (die einzige Zerlegung) und `resolve()` (die dreistufige Auflösung für **neue** Konten). |
| `MailboxIdentity.php` | Das Kernstück: Adresse, Cyrus-Benutzername, IMAP-Mailbox, Ordnername, Spool-Pfad — alles abgeleitet, nichts geraten. |
| `Domain.php` | Domain-Entität mit Kontingenten, Standardordnern und Schemavorgabe. |
| `Account.php` | Konto-Entität; `id()` ist der Cyrus-Benutzername (U-18). |
| `AccountServices.php` | Die vier Flags `imap`, `pop`, `sieve`, `smtpauth`. |
| `AccountLimit.php` | `maxaccounts` mit „unbegrenzt" als eigenem Zustand. |
| `DefaultFolders.php` | `domain.folders`, kommasepariert, Format erhalten. |
| `PasswordFormat.php` | Enum für die Hash-Format-Anzeige (U-22). **Nur der Datentyp** — die Erkennung gehört S1. |
| `AdminRole.php` | `Superuser`/`DomainMaster`, Abbildung auf `adminuser.type`. |
| `ReservedLocalPart.php` | `postmaster`, `abuse` und Verwandte — als Feststellung, nicht als Verbot. |

### `Domain/Model/Virtual/` — die vier plus eins

| Datei | Zweck |
|---|---|
| `VirtualRow.php` | Die Zeile, unverändert durchgereicht. |
| `VirtualEntry.php` | Schnittstelle: `type()`, `row()`, `alias()`, `destinations()`, `isEnabled()`. |
| `VirtualEntryType.php` | Enum der fünf Zustände. |
| `VirtualEntryClassifier.php` | **Die einzige Stelle**, die Feldbelegungen auswertet. |
| `MailboxAddress.php`, `Alias.php`, `CatchAll.php`, `Forward.php` | Die vier Typen aus Architektur 3.4. |
| `UnknownVirtualEntry.php`, `UnknownReason.php` | Der fünfte Zustand, mit Begründung — angezeigt, nicht übergangen. |
| `DestinationList.php` | `virtual.dest` als Liste. |

### `Domain/Mailstore/` — was M2 braucht

`Quota.php` (Kibibyte, „unbegrenzt" als Zustand), `Acl.php`, `AclRight.php`, `AclSet.php`.
Die Schnittstellen `Mailstore` und `SieveStore` selbst legt **M2** an.

### `Domain/Sieve/` — Modell und Rendering

`SieveRuleSet.php`, `VacationRule.php`, `RedirectRule.php`, `FilterRule.php`,
`FilterCondition.php`, `FilterAction.php`, `MatchType.php`, `ConditionJoin.php`,
`SieveRenderer.php`, `SieveScript.php`. **Kein ManageSieve-Protokoll** — das ist M3.

### `Domain/Access/` — wer handelt

`ActorContext.php`, `DomainScope.php`. **Nur das Wertobjekt**; Durchsetzung und Policies
gehören S3.

### `Domain/Exception/` — Fehlercodes

`DomainError.php` (Basis mit `code()` und `context()`), `DomainErrorCode.php` (die Liste),
`InvalidAddress.php`, `InvalidMailboxIdentity.php`, `InvalidDomainConfiguration.php`,
`InvalidQuota.php`, `InvalidAcl.php`, `InvalidSieveRule.php`, `InvalidActorContext.php`.

Die Codes folgen `<ressource>.<zustand>` (U-7). **F2 muss sie in das `ErrorCode`-`enum` des
Vertrags aufnehmen:** `domain.invalid_name`, `domain.invalid_folder`,
`domain.invalid_account_limit`, `address.invalid_local_part`, `address.invalid`,
`address.invalid_destination`, `account.invalid_identity`, `account.identity_mismatch`,
`quota.invalid`, `mailstore.invalid_acl`, `sieve.invalid_rule`, `admin.invalid_scope`.

### Was Folgepakete daran anknüpfen

| Ort | Erweiterungspunkt |
|---|---|
| `MailboxIdentity::create()` | Neues Konto: Tripel hinein, alle Formen heraus. |
| `MailboxIdentity::fromStoredUsername($name, $domainOfRecord)` | Bestandskonto: **immer beide Werte**, nie den Namen allein. F3 baut damit die Zeile ab, A2 die Kontodarstellung. |
| `NamingScheme::resolve()` | A1/A2 für neue Konten; die Domain-Einstellung kommt aus `mailadmin_domain_setting`, der globale Wert aus `MAILADMIN_NAMING_SCHEME`. |
| `VirtualEntryClassifier::classifyAll($rows, $accountDomains)` | A3/F3: eine Abfrage für die Domains der referenzierten Konten, dann klassifizieren. Kein SQL im Modell. |
| `Domain\Mailstore\Quota`, `AclSet` | M2 gibt sie aus `getQuota()`/`getAcl()` zurück. |
| `SieveRenderer::render()` | M3 schickt das Ergebnis per `PUTSCRIPT`; `SieveScript::isForeign()` entscheidet, ob überhaupt geschrieben werden darf. |
| `ActorContext` | S3 hängt die Policies daran, jeder Anwendungsfall nimmt ihn als ersten Parameter. |
| `PasswordFormat` | S1 gibt die Erkennung eines Hashes als diesen Enum zurück (siehe unten). |
| `DomainError::code()` | Der Fehlerhandler bildet ihn auf `problem+json` ab; `context()` füllt `errors`. |

## Zu klärende Punkte

Alle sechs sind beantwortet. Wo `05-umsetzungsentscheidungen.md` die Antwort gibt, steht die
U-Nummer; die übrigen sind **nach gängiger Praxis entschieden und ausdrücklich zur Korrektur
gedacht**.

1. **Erlaubte Zeichen im Local Part.** Das Altsystem hatte keine Validierung und ließ Adressen
   wie `f*o*o+b*a*r@…` zu. Welche Zeichen erlauben wir? Vorschlag: konservativ — Buchstaben,
   Ziffern, `.`, `-`, `_`, `+`; kein führender oder abschließender Punkt.

   > **Beantwortet — Annahme (folgt dem Vorschlag), erweitert um U-22.** Für **neue** Adressen
   > gilt genau diese Liste, zusätzlich kein doppelter Punkt und höchstens 64 Byte (RFC 5321).
   > Durchgesetzt von `LocalPart::fromString()`.
   >
   > Für **Bestandsdaten** gibt es `LocalPart::fromStoredValue()`: es weist nur zurück, was
   > unbrauchbar ist (leer, `@`, `/`, Steuerzeichen, Leerraum, über 255 Byte) und kennzeichnet
   > alles Übrige mit `isConventional() === false`. Ein `f*o*o+b*a*r`-Konto bleibt lesbar und
   > fällt in der Oberfläche auf — es zurückzuweisen hieße, ein laufendes Postfach unsichtbar zu
   > machen. `DomainName` hat dieselben zwei Türen.

2. **Internationalisierte Domainnamen.** Das Altsystem hatte ein Werkzeug zur
   Punycode-Umwandlung. Speichern wir Domains immer in Punycode und zeigen Unicode an, oder
   umgekehrt? Im Bestand existieren `xn--`-Domains — hier ist Einheitlichkeit wichtig.

   > **Beantwortet — Annahme: Punycode ist die gespeicherte Form.** `DomainName` nimmt nur
   > ASCII-Labels an (`xn--…` eingeschlossen) und weist Unicode-Eingaben mit dem Hinweis auf
   > die Umwandlung zurück. `isInternationalized()` sagt, ob eine Anzeige in Unicode angeboten
   > werden sollte.
   >
   > **Warum die Umwandlung nicht im Modell steckt:** `idn_to_ascii()` verlangt `ext-intl`.
   > Eine Pflicht-Erweiterung für eine Umwandlung, die der Browser mit `URL`/`Intl` ohnehin
   > beherrscht, widerspricht E-12. Die Umwandlung gehört damit an den Rand — Frontend oder
   > HTTP-Schicht —, und zwar **vor** dem Modell.

3. **Groß- und Kleinschreibung.** Domains und Local Parts durchgängig kleinschreiben? Cyrus hat
   `lmtp_downcase_rcpt: yes`. Was passiert mit vorhandenen Einträgen in Großschreibung?

   > **Beantwortet — Annahme: Schreibweise erhalten, Vergleich ohne Rücksicht auf sie.**
   > `equals()` vergleicht bei `DomainName`, `LocalPart`, `EmailAddress` und `MailboxIdentity`
   > case-insensitiv; `toLowerCase()` erzeugt die kanonische Form, wird aber nirgends erzwungen.
   >
   > **Warum nicht einfach kleinschreiben:** `accountuser.username` ist eine `varchar
   > binary`-Spalte — eine Abfrage mit anders geschriebenem Namen trifft die Zeile nicht, und
   > ein erzeugter Name, der sich in der Schreibweise unterscheidet, würde beim
   > `renamemailbox` das falsche Postfach treffen. `fromStoredUsername()` gibt deshalb den
   > gespeicherten Namen zeichengenau wieder zurück (Test dazu vorhanden). Neue Domains und
   > Konten **sollten** kleingeschrieben angelegt werden — das entscheiden A1/A2 an der
   > Eingabegrenze, nicht das Modell durch stilles Umschreiben (E-18).

4. **Reservierte Adressen.** Das Altsystem kannte eine Liste (`root`, `postmaster`, …).
   Übernehmen, konfigurierbar machen oder streichen?

   > **Beantwortet — Annahme: übernehmen als Feststellung, nicht als Verbot.**
   > `ReservedLocalPart::NAMES` führt `abuse`, `hostmaster`, `mailer-daemon`, `postmaster`,
   > `root`, `webmaster`. `isMandatory()` hebt die beiden hervor, die RFC 2142 verlangt
   > (`abuse`, `postmaster`).
   >
   > Ob eine Verwendung gewarnt oder verweigert wird, entscheidet A3 (dessen offener Punkt 5) —
   > das Modell stellt die Tatsache bereit, damit die Antwort nicht an zwei Stellen
   > auseinanderläuft. Konfigurierbarkeit ist später eine Zeile in `mailadmin_setting` und
   > heute keine Investition wert.

5. **Quota-Einheit.** Cyrus rechnet `STORAGE` in **Kilobyte**. Die Oberfläche zeigt vermutlich
   MB oder GB. Wo findet die Umrechnung statt, und was bedeutet der Wert `0`?

   > **Beantwortet — Annahme.** Das Modell rechnet **ausschließlich in Kibibyte**, weil das die
   > Einheit von `SETQUOTA … STORAGE` ist. `Quota::ofMebibytes()`/`ofGibibytes()` gibt es als
   > Eingabehilfe; die **Anzeige** in MB oder GB ist Sache des Frontends — dieselbe Trennung wie
   > bei der Zeit (U-14).
   >
   > **`0` hat zwei Bedeutungen, je nach Herkunft, und deshalb zwei Fabrikmethoden:**
   >
   > | Herkunft | Bedeutung von `0` | Fabrik |
   > |---|---|---|
   > | Cyrus (`GETQUOTA`) | Kontingent von null Byte — Zustellung schlägt fehl | `Quota::ofKibibytes(0)` |
   > | `domain.quota`, `domain.domainquota` | unbegrenzt (webcyradm-Konvention) | `Quota::fromLegacyKibibytes(0)` |
   >
   > „Unbegrenzt" ist ein eigener Zustand (`isUnlimited()`), keine magische Zahl — das
   > Altsystem reichte dafür die Zeichenkette `NOT-SET` durch den Code. „Nicht gemessen"
   > (`usedKibibytes() === null`) ist wiederum etwas anderes als „null belegt", und
   > `isExceeded()` antwortet dort `false`: unbekannt darf nie als „voll" gelesen werden.
   > M2 bestätigt die Zuordnung gegen einen echten Server.

6. **Der `+`-Trenner.** Postfix ist mit `recipient_delimiter = +` konfiguriert. Muss das Modell
   Adressteile davor abschneiden, wenn es Zuordnungen sucht?

   > **Beantwortet — Annahme: das Modell kennt den Trenner, wendet ihn aber nie von selbst an.**
   > `LocalPart::RECIPIENT_DELIMITER`, `detail()` und `withoutDetail()` benennen ihn an einer
   > Stelle; `EmailAddress::baseAddress()` liefert die Adresse ohne Detail.
   >
   > **Warum kein automatisches Abschneiden:** welche Adresse zugestellt wird, entscheidet
   > Postfix, nicht wir. Eine Suche, die stillschweigend alles hinter dem `+` verwirft, fände
   > die falsche Zeile — und `info+shop@…` ist als Aliaseintrag zulässig und im Bestand
   > möglich. Wo eine Zuordnung den Basisteil braucht, fragt der Anwendungsfall ausdrücklich
   > danach.

### Zusätzlich entschieden (nicht im ursprünglichen Fragenkatalog)

- **Widersprüchliche Bestandsdaten brechen die Erkennung ab, statt zu raten.** Passen
  `accountuser.username` und `accountuser.domain_name` nicht zusammen, wirft
  `fromStoredUsername()` `account.identity_mismatch`. Es gibt keine zweite Quelle für das Ende
  des Local Part; ein Ratewert erzeugte ein unerreichbares Postfach. F3/A2 zeigen solche Zeilen
  als defekt an (U-22).
- **Der Spool-Pfad ist Diagnoseausgabe.** Die Anwendung fasst das Dateisystem nicht an. Die
  beiden Formen aus Architektur 3.3 sind unverändert übernommen; das Hash-Zeichen (`q` für
  Namen, die nicht mit einem Buchstaben beginnen) ist aus dem Cyrus-Quelltext abgeleitet und in
  F5 zu bestätigen.
- **`user/<local>/<Ordner>@<domain>` im `fqun`-Schema.** Bei virtuellen Domains steht die Domain
  am Ende des **gesamten** Mailboxnamens, nicht hinter dem Benutzernamen. `imapFolder()` bildet
  das ab; M2 bestätigt es gegen F5.
- **`ActorContext` liegt in `Domain/Access/`, nicht in `Application/`.** S3 nennt
  `Application/ActorContext.php`; das Fachmodell darf aber nicht auf `Application` zeigen, und
  die Policies in `Domain/Policy/` brauchen ihn. S3 verwendet `Mailadmin\Domain\Access\ActorContext`.
- **Sieve-Skripte werden mit CRLF gerendert** (RFC 5228), die Abwesenheitsnotiz immer als
  `text:`-Literal mit Dot-Stuffing — ein Text mit Zeilenumbrüchen, Anführungszeichen und
  Umlauten überlebt damit unverändert.

## Definition of Done

- [x] `MailboxIdentity` erzeugt in beiden Namensschemata korrekte Formen und lässt sich nicht
  aus einem einzelnen String konstruieren — der Konstruktor ist privat, ein Test prüft das per
  Reflection.
- [x] Ein Local Part mit Punkten führt in keinem Schema zu einer falschen Zerlegung; ein Local
  Part, der den Domainnamen enthält, ebenfalls nicht (die `REPLACE`-Fragilität aus 3.3 ist als
  Test festgehalten).
- [x] Jeder `virtual`-Datensatz wird genau einem der fünf Typen zugeordnet, einschließlich
  `UnknownVirtualEntry` mit Begründung.
- [x] Keine Klasse im Paket kennt PDO oder IMAP; keine Abhängigkeit auf `src/Infrastructure`
  oder `src/Http`.
- [x] `src/Domain` läuft auf PHPStan-Stufe **max** fehlerfrei (`composer analyse:domain`).

## Testfälle

- **Unit:** Local Part mit Punkten in beiden Schemata, hin und zurück.
- **Unit:** Schemaerkennung aus gespeicherten Benutzernamen, mit und ohne `@`.
- **Unit:** Typerkennung für alle vier `virtual`-Muster plus einen unbekannten Fall.
- **Unit:** Grenzfälle bei Validierung — leerer Local Part, führender Punkt, überlange Namen.

Umgesetzt in `tests/Unit/Domain/` (233 Tests): `Model/MailboxIdentityTest.php`,
`Model/NamingSchemeTest.php`, `Model/LocalPartTest.php`, `Model/DomainNameTest.php`,
`Model/EmailAddressTest.php`, `Model/DomainTest.php`, `Model/AccountTest.php`,
`Model/Virtual/VirtualEntryClassifierTest.php`, `Model/Virtual/DestinationListTest.php`,
`Mailstore/QuotaTest.php`, `Mailstore/AclTest.php`, `Sieve/SieveRendererTest.php`,
`Sieve/SieveRuleSetTest.php`, `Access/ActorContextTest.php`.

Über die geforderten Fälle hinaus abgesichert: der Rundlauf Erzeugen → Erkennen für beide
Schemata, die zeichengenaue Wiedergabe des gespeicherten Benutzernamens, der Local Part, der
den Domainnamen enthält (mit dem `str_replace`-Gegenbeispiel im Test), und dass ein
klassifizierter `virtual`-Eintrag seine Ursprungszeile unverändert mit sich führt.
