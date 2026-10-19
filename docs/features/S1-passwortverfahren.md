<!-- markdownlint-disable MD013 MD024 MD060 -->

# S1 — Passwortverfahren

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F1 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitte 5.5 und 14 · E-3, E-13 · U-21, U-22, U-28 |
| **Status** | fertig |

---

## Ziel

Ein `PasswordHasher`, der alle im Bestand vorkommenden Hash-Formate prüft, neue Passwörter im
Zielverfahren schreibt und erkennt, wann ein Hash erneuert werden muss.

## Abgrenzung

- Kein Anmeldeablauf, keine Token — das ist **S2**.
- Keine Massenrücksetzung; sie fällt nach E-18 aus dem ersten Release.

## Abhängigkeiten und geteilte Dateien

Wird von S2 (Anmeldung), A2 (Kontopasswort) und A4 (Adminpasswort) genutzt. Die Schnittstelle für
diese drei steht unten unter [Für S2, A2 und A4](#für-s2-a2-und-a4).

**Offen gegenüber F2:** Die sechs Fehlercodes aus `PasswordErrorCode` müssen in das `ErrorCode`-
`enum` in `openapi/components/common.yaml` aufgenommen werden (U-7). Solange das nicht geschehen
ist, kennt der Vertrag sie nicht.

## Vorgaben

| Tabelle | Zielverfahren | Grund |
|---|---|---|
| `adminuser` | Argon2id | kein externer Konsument |
| `accountuser` | bcrypt (`$2y$`) | muss `crypt(3)`-lesbar bleiben, `pam_mysql` prüft mit |

- **Niemals ein Verfahren für `accountuser` wählen, das `crypt(3)` nicht versteht.**
  Eskalationsfall.
- Alle Vergleiche über `hash_equals()`.
- Unbekannter Benutzername durchläuft eine Dummy-Verifikation gegen Zeitanalyse.
- Bestandshashes bleiben gültig; es gibt keine Zwangsumstellung (E-13).

## Artefakte

### Fachmodell — `src/Domain/Password/` (PHPStan Stufe max)

| Datei | Zweck |
|---|---|
| `PasswordSubject.php` | `adminuser` / `accountuser`. Trägt Zielformat, Mindest- und Höchstlänge und die Frage, ob ein externer Konsument mitprüft. **Die Absicherung des Verfahrens.** |
| `Password.php` | Wertobjekt für ein geprüftes Klartextpasswort. Privater Konstruktor, kein `__toString()`, geschwärztes `__debugInfo()`, `__sleep()` wirft. |
| `PasswordPolicy.php` | U-21, Regel für Regel. |
| `PersonalTerms.php` | „Passwort enthält Benutzer- oder Domainnamen", aus `LocalPart`, `DomainName`, `MailboxIdentity`. |
| `PasswordFormatDetector.php` | `detect(string): PasswordFormat` — Architektur 5.5 als Code. |
| `CommonPasswordList.php` | Schnittstelle zur Sperrliste. |
| `InMemoryCommonPasswordList.php` | Sperrliste im Speicher, für Tests. |
| `InvalidPassword.php` | Regelverstoß, abgeleitet von `DomainError`. Nennt nie das Passwort. |
| `PasswordErrorCode.php` | Sechs stabile Codes mit Präfix `auth.` (U-7). |

Das Enum `PasswordFormat` stammt aus F4 (`src/Domain/Model/`) und wurde unverändert übernommen.

### Infrastruktur — `src/Infrastructure/Security/`

| Datei | Zweck |
|---|---|
| `PasswordHasher.php` | `verify()`, `verifyDummy()`, `hash()`, `needsRehash()`, `detectFormat()`, `mysqlPassword()`. |
| `PasswordHashingOptions.php` | Kostenparameter aus der Umgebung, mit Unter- und Obergrenzen. **Kein Algorithmus-Schalter.** |
| `PasswordHashingFailed.php` | Der Hasher hat etwas anderes erzeugt als das Zielformat. |
| `PasswordGenerator.php` | Vorschlag im Format `Xk9m-Pq4t-Wz7n-Rb3d`. |
| `FileCommonPasswordList.php` | Liest `resources/common-passwords.txt`, einmal, träge. |

### Daten und Verdrahtung

- `resources/common-passwords.txt` — 12 261 Einträge, 100 KB. Neues Verzeichnis der obersten
  Ebene, deshalb mit eigener sperrender `resources/.htaccess` und mit `resources` in der
  `RewriteRule` der Wurzel-`.htaccess` (U-25).
- `config/di/security.php` — `CommonPasswordList` → `FileCommonPasswordList`,
  `PasswordHashingOptions` aus der `Config`. Hasher, Policy und Generator baut PHP-DI selbst.

### Tests

`tests/Unit/Domain/Password/` (96 Tests) und `tests/Unit/Infrastructure/Security/` (115 Tests).

---

## Für S2, A2 und A4

### Prüfen

```php
$hasher->verify($eingegeben, $row['password']);   // bool, erkennt das Format selbst
```

Deckt alle Formate aus Architektur 5.5 ab. Ein leerer oder unlesbarer Hash ergibt immer `false`.

**Unbekannter Benutzername:**

```php
$row === null
    ? $hasher->verifyDummy($eingegeben, PasswordSubject::AccountUser)   // immer false
    : $hasher->verify($eingegeben, $row['password']);
```

`verifyDummy()` gibt immer `false` zurück, nimmt sich dafür aber dieselbe Zeit wie eine echte
Prüfung. Ohne diesen Zweig verrät die Antwortzeit, welche Postfächer es gibt. Das Subjekt wählt,
welche Kosten simuliert werden — eine Adminanmeldung soll eine Argon2id-Prüfung kosten, eine
Kontoanmeldung eine bcrypt-Prüfung.

### Setzen

```php
$password = $policy->accept($klartext, PasswordSubject::AccountUser, $terms);
$hash     = $hasher->hash($password);
```

`accept()` wirft `InvalidPassword` mit einem stabilen `code()`. `hash()` gibt eine Zeichenkette
zurück, die direkt in die Spalte geht.

`$terms` ist optional und liefert die Prüfung „enthält den eigenen Namen":

```php
PersonalTerms::forMailbox($identity);                 // Konto mit MailboxIdentity
PersonalTerms::forAddress($localPart, $domain);       // Konto vor dem Anlegen
PersonalTerms::forAdmin($adminBenutzername);          // adminuser
PersonalTerms::none();                                // kein Kontext
```

### Zielformat wählen

**Gar nicht.** Es gibt keinen Algorithmusparameter — nirgends in S1. Das Format ergibt sich aus
`PasswordSubject`, das im `Password` steckt. Wer in `accountuser` schreibt, nimmt
`PasswordSubject::AccountUser`, und damit ist bcrypt gesetzt.

### Erneuern

```php
if ($hasher->verify($eingegeben, $gespeichert)) {
    if ($hasher->needsRehash($gespeichert, $subjekt)) {
        $repository->storePasswordHash($id, $hasher->hash($policy->accept($eingegeben, $subjekt, $terms)));
    }
    // ... anmelden
}
```

Nur nach erfolgreicher Prüfung, nie bedingungslos (E-13). `needsRehash()` meldet `true` für jedes
Altformat, für das falsche Zielformat und für das richtige Format mit veralteten Kosten.

**Ein Sonderfall, der keiner ist:** Für Klartext im Bestand meldet `needsRehash()` bereits `true`,
also braucht es keinen eigenen Zweig — der gewöhnliche Weg schreibt beim ersten erfolgreichen
Prüfen um.

**Ein Stolperstein:** `accept()` kann beim Erneuern werfen, weil das Bestandspasswort die heutigen
Regeln nicht erfüllt (etwa acht Zeichen). Es gibt keine Zwangsumstellung (E-13) — in diesem Fall
wird **nicht** erneuert und **nicht** abgewiesen: die Anmeldung gelingt, der alte Hash bleibt
stehen. Wer das anders will, braucht eine Entscheidung, keinen Bugfix.

### Anzeigen (U-22)

```php
$hasher->detectFormat($gespeichert);   // PasswordFormat
```

Für das Feld `password_format` der Kontodarstellung. `PasswordFormat` beantwortet dazu
`isCurrent()`, `isLegacy()`, `isWeak()` und `isCryptCompatible()`.

### Vorschlagen

```php
$generator->generate(PasswordSubject::AccountUser, $terms);   // Password
```

### In Tests

Kein Mock. `new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 4, argon2MemoryKib: 8192, argon2TimeCost: 1))`
ist die echte Klasse zu Testkosten. `PasswordHashingOptions::of()` umgeht die Untergrenzen
absichtlich; `fromConfig()` tut das nicht.

Es gibt bewusst **keine Schnittstelle** zu `PasswordHasher`. Ein Double dürfte einen Hash in einem
Format zurückgeben, das hier niemand akzeptieren würde — und der Zweck dieser Klasse ist, dass es
so einen Hash nicht gibt.

---

## Wie „nie ein Verfahren ohne `crypt(3)` für `accountuser`" abgesichert ist

Fünf Lagen, keine davon ein Kommentar:

1. **Kein Algorithmusparameter.** `hash()` nimmt ein `Password`, sonst nichts. Es gibt keine
   Signatur in S1, in die man ein Verfahren hineinreichen könnte.
2. **Geschlossenes `match`.** `PasswordSubject::targetFormat()` bildet zwei Fälle auf zwei
   Literale ab. Keine Konfiguration, keine Umgebungsvariable, kein Vorgabewert.
3. **Kein Schalter in der Konfiguration.** `PasswordHashingOptions` kennt nur Kosten.
   `PasswordHashingOptionsTest` prüft, dass dort kein Algorithmusname vorkommt.
4. **Prüfung des Ergebnisses.** `PasswordHasher::guard()` erkennt das Format des erzeugten Hashes
   neu und wirft, wenn es nicht das Zielformat ist oder `crypt(3)` es nicht lesen kann.
   Zusätzlich prüft es für `accountuser` mit `crypt()` gegen den erzeugten Hash — dieselbe
   Frage, die `pam_mysql` später stellt. `PasswordHasherGuardTest` löst jeden dieser Zweige aus.
5. **Prüfung der Invariante über alle Fälle.** `PasswordSubjectTest` prüft für **jeden** Fall des
   Enums: wer einen externen Konsumenten hat, hat ein `crypt(3)`-lesbares Zielformat. Ein dritter
   Fall, der später dazukommt, wird mitgeprüft.

Dazu passend die Gegenrichtung: `needsRehash()` meldet für einen Argon2id-Hash in `accountuser`
`true`. Ein solcher Hash — von Hand eingetragen oder aus einer Fehlkonfiguration — überlebt die
nächste Passwortsetzung nicht.

---

## Beantwortete Punkte

### 1. Bcrypt-Kostenfaktor → **10**, konfigurierbar, Untergrenze 10

**Annahme, nicht durch eine U-Nummer gedeckt.** `pam_mysql` prüft bei **jedem** IMAP- und
SMTP-Login; ein POP3-Client im Minutentakt bezahlt den Faktor jede Minute. 11 verdoppelt das.
10 ist PHPs Vorgabe und der Wert, der ohne Messung auf der Zielhardware nicht erhöht werden
sollte. Über `MAILADMIN_PASSWORD_BCRYPT_COST` anzuheben; **nach unten geht es nicht**, die
Untergrenze ist ebenfalls 10.

Die Messung gehört nach F5 — dort steht eine Umgebung mit echtem Cyrus.

### 2. Argon2id-Parameter → **64 MiB, 4 Durchgänge, 1 Thread**

**Annahme.** Das sind PHPs eigene Vorgaben und liegen über der OWASP-Empfehlung (19–46 MiB).
Für `adminuser` gibt es keine Poll-Last, also kein Grund, unter dem zu bleiben, was die Sprache
vorschlägt. Konfigurierbar über `MAILADMIN_PASSWORD_ARGON2_*`, mit Untergrenzen.

### 3. Passwortregeln → **U-21**

Vollständig dort entschieden und hier umgesetzt: Mindestlänge 12/10, Höchstlänge 72 Byte für
`accountuser` (abgelehnt, nicht abgeschnitten), keine Zeichenklassen, kein Ablauf, Sperrliste,
keine eigenen Namen im Passwort, kein externer Dienst.

Drei Ergänzungen, die U-21 nicht nennt — **Annahmen**:

- **Absolute Obergrenze 4096 Byte** für jedes Subjekt. U-21 nennt für `adminuser` keine Grenze;
  eine unbegrenzte Eingabe ist ein einfacher Weg, den Server megabyteweise hashen zu lassen.
  4096 ist der Wert, den Django verwendet.
- **Steuerzeichen werden abgelehnt.** Ein NUL-Byte ist der Fall, auf den es ankommt: `crypt(3)`
  und `pam_mysql` arbeiten mit C-Zeichenketten und hören dort auf. Das wirksame Geheimnis wäre
  kürzer als das eingegebene — und kürzer als das, was die Regel gemessen hat.
- **Ungültiges UTF-8 wird abgelehnt.** Ohne das hätte die Zeichenzählung keine Bedeutung, und
  Oberfläche, Datenbank und Cyrus würden dieselben Bytes verschieden lesen.

Nicht abgelehnt werden Leerzeichen, führende und abschließende Leerzeichen und Emoji. Es wird
auch nichts abgeschnitten oder normalisiert: was eingegeben wurde, wird gehasht.

Die Mindestlänge zählt **Zeichen**, die Höchstlänge **Bytes** — die eine folgt der Lesbarkeit,
die andere bcrypts Grenze. Gezählt wird mit PCRE (`/u`), nicht mit `mb_strlen()`: `ext-mbstring`
steht nicht in `composer.json` (E-12).

### 4. Format erzeugter Passwörter → **lesbar, `Xk9m-Pq4t-Wz7n-Rb3d`**

**Annahme.** Der Administrator legt ein Postfach an und muss das Passwort weitergeben, meist am
Telefon oder auf Papier. Ein Zeichen, das man vorlesen kann, verhindert einen Anruf; ein Zeichen
mehr Entropie verhindert nichts, was 93 Bit nicht schon verhindern.

Das Alphabet lässt `0`, `O`, `1`, `l`, `I` weg — 57 Zeichen. 16 Zeichen in vier Gruppen ergeben
rund 93 Bit. `5`/`S` und `2`/`Z` bleiben drin: gesprochen sind sie eindeutig.

Erzeugt wird mit `random_int()`. Jeder Vorschlag läuft durch dieselbe `PasswordPolicy` wie ein
getipptes Passwort.

### 5. MySQL-`PASSWORD()` → **nachgebildet, bleibt drin**

`'*' . strtoupper(sha1(sha1($pw, true)))`, kein Datenbank-Roundtrip. Gegen den veröffentlichten
Vektor geprüft: `PASSWORD('password')` = `*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19`.

Die Frage des Paketdokuments war, ob das Format im Bestand überhaupt vorkommt. **Nicht geprüft** —
das hieße, den Produktivbestand zu lesen, und der Nutzen wäre gering: die Erkennung ist eindeutig
(41 Zeichen, führender `*`, Großbuchstaben-Hex), die Nachbildung sind zwei Zeilen, und ein
Weglassen würde genau die Konten aussperren, für die Architektur 5.5 das Format aufführt. Der
Preis dafür, es zu behalten, ist niedriger als der Preis, sich zu irren.

### 6. Klartext im Bestand → **kein Sonderzweig nötig**

`needsRehash()` meldet für Klartext bereits `true`, also schreibt der gewöhnliche Weg beim ersten
erfolgreichen Prüfen um. Ein zusätzlicher „sofort"-Zweig wäre eine zweite Stelle mit derselben
Bedeutung.

Ob Klartext im Bestand vorkommt, ist ebenfalls nicht am Produktivsystem geprüft. Architektur 5.5
führt das Format auf; die Unterstützung kostet eine `hash_equals()`-Zeile.

**Eine Regel, die dabei wichtiger ist als die Frage:** Ein leerer Wert in der Spalte gilt als
`Unknown`, nicht als Klartext. Sonst öffnete ein nie gesetztes Passwort das Konto für jeden, der
nichts eingibt.

### Zwei Mehrdeutigkeiten, die entschieden werden mussten

Die Bestandsdaten erlauben zwei Lesarten, und nur eine kann gelten:

- **13 Zeichen aus dem crypt-Alphabet** sind DES-crypt, nicht ein 13 Zeichen langes
  Klartextpasswort. Ebenso **40 Hex-Zeichen** = SHA1, nicht Klartext. Grund: die andere Lesart
  ließe jeden, der die Tabelle lesen kann, sich durch Eintippen des gespeicherten Hashes
  anmelden. `PasswordHasherTest::testSubmittingTheStoredHashDoesNotAuthenticate` hält das fest.
- **Was wie ein Hash aussieht, aber unbekannt ist** (`$argon2i$`, `$y$`, `{SSHA}`) ist `Unknown`,
  nicht Klartext. Es prüft nie erfolgreich und meldet immer `needsRehash()`.

---

## Definition of Done

| Punkt | Stand |
|---|---|
| Jedes gelistete Format wird korrekt geprüft, positiv und negativ | erfüllt — `PasswordHasherTest`, je Format zwei Fälle |
| `needsRehash()` meldet für jedes Altformat `true`, für das Zielformat `false` | erfüllt, für **beide** Subjekte |
| Ein `hash()`-Ergebnis für `accountuser` wird von `crypt(3)` verifiziert | erfüllt — als Unit-Test **und** als Prüfung im Produktivcode |
| Kein Zweig gibt einen Hash oder ein Klartextpasswort in ein Log | erfüllt — keine Klasse in S1 kennt einen Logger; ein Test prüft die Quelltexte, ein zweiter prüft jede Ausnahmemeldung |

Offen bleibt der Integrationstest aus F5: bcrypt-Hash aus PHP, Anmeldung über IMAP und SMTP-AUTH.
Der Unit-Test prüft dieselbe Zusicherung gegen PHPs `crypt()`; die Bestätigung gegen einen
laufenden Cyrus und `pam_mysql` gehört in die F5-Umgebung.

## Testfälle

| Fall | Ort |
|---|---|
| Je Format ein richtiges und ein falsches Passwort | `PasswordHasherTest::testTheCorrectPasswordVerifies` / `…AWrongPassword…` |
| DES-crypt — nur die ersten acht Zeichen zählen | `PasswordHasherTest::testDesCryptIgnoresEverythingPastTheEighthCharacter` |
| `needsRehash()` je Format | `PasswordHasherTest::testEveryLegacyFormatWantsARehashForBothSubjects` |
| MySQL-`PASSWORD()` gegen den veröffentlichten Vektor | `PasswordHasherTest::testMysqlPasswordMatchesThePublishedVector` |
| Dummy-Verifikation: immer `false`, gleiche Kosten | `PasswordHasherTimingTest` — strukturell und gemessen |
| Der Schutzwall für `accountuser` | `PasswordHasherGuardTest` |
| U-21 Regel für Regel | `PasswordPolicyTest` |
| Keine Ausnahmemeldung trägt das Passwort | `PasswordPolicyTest::testNoMessageOrContextEverCarriesThePassword` |
| Integration: IMAP- und SMTP-AUTH-Anmeldung mit einem PHP-Hash | **offen, F5** |

Alle Hashes in den Tests werden dort erzeugt. Nichts stammt aus `docs/internal/` oder aus der
Produktivdatenbank.

---

## Die Sperrliste

`resources/common-passwords.txt`, 12 261 Einträge, alles klein geschrieben, ein Eintrag je Zeile,
`#` beginnt einen Kommentar.

**Zusammensetzung und warum sie nicht die reine 10-k-Liste ist:** U-21 nennt „die 10 000
häufigsten Passwörter". Diese Liste besteht aber fast vollständig aus Passwörtern mit sechs bis
neun Zeichen — von den 10 000 Einträgen sind **51** zehn Zeichen oder länger und **10** zwölf oder
länger. Da U-21 gleichzeitig eine Mindestlänge von 10 bzw. 12 vorschreibt, wäre die Liste zu
über 99 Prozent unerreichbar. Deshalb enthält die Datei:

- die vollständige Liste `10k-most-common.txt` (10 000 Einträge) — die Vorgabe aus U-21, wörtlich;
- zusätzlich alle Einträge von 10 bis 72 Zeichen aus `xato-net-10-million-passwords-100000.txt`
  (2 343) — das ist der Teil, der tatsächlich greift.

Nach Kleinschreibung und Entdopplung bleiben 12 261 Einträge, davon 2 312 in erreichbarer Länge.
Ein Test hält die Untergrenzen fest.

**Quelle:** [SecLists](https://github.com/danielmiessler/SecLists), MIT-Lizenz, abgerufen am
2026-07-27. Die Herkunft steht im Kopf der Datei.

**Neu erzeugen:**

```sh
base=https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials
curl -sS -o 10k.txt      "$base/10k-most-common.txt"
curl -sS -o xato100k.txt "$base/xato-net-10-million-passwords-100000.txt"

{ cat 10k.txt; awk 'length($0)>=10 && length($0)<=72' xato100k.txt; } \
  | tr -d '\r' | tr 'A-Z' 'a-z' | grep -v '^$' | grep -v '^#' | LC_ALL=C sort -u
```

Das Ergebnis unter den bestehenden Kommentarkopf schreiben.

**Bewusst nicht getan:** kein Abstreifen von Ziffernsuffixen, keine Leetspeak-Normalisierung. Das
würde mehr fangen und gleichzeitig anfangen, Passwörter aus Gründen abzulehnen, die sich in einem
Formular nicht erklären lassen. Wenn das gewünscht ist, ist es eine Entscheidung.

---

## Gewünschte Einträge in `.env.example`

**Nicht von S1 eingetragen** — die Datei gehört nicht zu diesem Paket. Alle vier sind optional;
ohne sie gelten die Vorgaben.

```ini
# Password hashing cost (S1). The algorithm is not configurable: adminuser is
# always Argon2id, accountuser always bcrypt, because pam_mysql verifies that
# column with crypt(3).

# bcrypt work factor for accountuser. pam_mysql pays this on every IMAP and
# SMTP login, so raising it costs mail-fetch latency. Range 10..15, default 10 —
# measure on the target hardware before going higher.
#MAILADMIN_PASSWORD_BCRYPT_COST=10

# Argon2id for adminuser. Nothing polls this table, so it may be expensive.
#MAILADMIN_PASSWORD_ARGON2_MEMORY_KIB=65536
#MAILADMIN_PASSWORD_ARGON2_TIME_COST=4
#MAILADMIN_PASSWORD_ARGON2_THREADS=1
```

## Neues Verzeichnis der obersten Ebene

**Ja: `resources/`.** Nach U-25 versehen mit einer Kopie von `src/.htaccess` und in der
`RewriteRule` der Wurzel-`.htaccess` aufgenommen — die einzige Änderung an dieser Datei.
`tests/Unit/Deployment/HtaccessProtectionTest.php` läuft grün.
