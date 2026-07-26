<!-- markdownlint-disable MD013 MD024 MD060 -->

# S4 — Revisionsprotokoll

| | |
|---|---|
| **Stufe** | 2 — Zugang |
| **Hängt ab von** | F3, S3 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 13 |
| **Status** | offen |

---

## Ziel

Jede zustandsändernde Operation wird nachvollziehbar: wer, wann, von wo, was vorher, was
nachher. Schließt die Lücke, dass im Altsystem Änderungen nicht zuordenbar waren.

## Abgrenzung

- Kein Anwendungslog — das ist F1.
- Die webcyradm-Tabelle `log` bleibt unangetastet; sie gehört `pam_mysql`.

## Abhängigkeiten und geteilte Dateien

Jedes A-Paket schreibt Einträge. Das Format wird hier festgelegt und ist danach verbindlich.

## Vorgaben

- Tabelle `mailadmin_audit`: Akteur, Zeitpunkt, IP, Request-Id, Aktion, Objekttyp,
  Objektbezeichner, Vorher- und Nachher-Zustand als JSON.
- **Geheimnisse werden maskiert**, nicht gekürzt: Passwortfelder erscheinen als Platzhalter,
  Hashes gar nicht.
- Lesezugriffe werden nicht protokolliert — Anmeldungen und Anmeldeversuche schon.

## Artefakte

- Migration `mailadmin_audit` mit Indizes auf Akteur, Zeitpunkt und Objekt
- `Application/Audit/AuditWriter.php`
- `Application/Audit/Redactor.php`
- Endpunkt `GET /audit` mit Filter und Paginierung
- Oberfläche folgt in W1/A-Paketen

## Zu klärende Punkte

1. **Aufbewahrungsdauer.** Unbegrenzt oder mit Aufräumlauf? Bei einem Werkzeug dieser Größe
   wächst die Tabelle langsam — unbegrenzt ist vertretbar, sollte aber entschieden sein.
2. **Was ist „vorher" bei einer Neuanlage** und „nachher" bei einer Löschung? Vorschlag:
   jeweils `null`, damit die Struktur einheitlich bleibt.
3. **Größenbegrenzung.** Ein Domainumbenennungs-Batch über viele Konten — ein Eintrag für den
   Vorgang oder einer je Konto? Vorschlag: ein Vorgangs-Eintrag plus Einzeleinträge mit
   Verweis darauf.
4. **Schreibt der Audit-Eintrag in derselben Transaktion?** Bei Operationen, die den Mailstore
   berühren, kann es keine gemeinsame Transaktion geben. Wann wird geschrieben — vor dem
   Versuch, nach dem Erfolg, oder beides?
5. **Fehlgeschlagene Operationen protokollieren?** Ein abgelehnter Löschversuch ist
   sicherheitsrelevant. Vorschlag: ja, mit Ergebnisfeld.
6. **Sichtbarkeit für Domainmaster** — abhängig von der Antwort in S3.

## Definition of Done

- Jede schreibende Operation erzeugt genau einen Haupteintrag.
- Kein Eintrag enthält ein Klartextpasswort oder einen Hash — durch Test belegt.
- Der Leseendpunkt ist gescoped und paginiert.
- Ein Eintrag lässt sich über die Request-Id mit dem Anwendungslog verbinden.

## Testfälle

- **Unit:** Maskierung — ein Objekt mit Passwortfeld enthält danach keinen Klartext mehr.
- **Integration:** Konto anlegen, ändern, löschen erzeugt drei Einträge mit korrektem
  Vorher/Nachher.
- **Integration:** abgelehnter Zugriff erzeugt einen Eintrag mit Ergebnis „verweigert".
