# Golden-Dateien für das Feld `lists.members`

Gemeinsamer Vertrag zwischen mailadmin (schreibt `lists.members`) und
`maillist/maillist_members.py` (liest es für das Zustellskript `maillist/maillist.py`).

- `<fall>.members` ist der gespeicherte Feldinhalt, byteweise. Keine Datei endet
  automatisch mit einem Zeilenumbruch; was drinsteht, ist der Wert.
- `<fall>.recipients` sind die Empfängeradressen, die `normalize_recipients()`
  daraus ermitteln muss, eine pro Zeile, in dieser Reihenfolge.

`canonical.members` ist zusätzlich genau das, was `MemberList` für die Mitglieder
`Alice Example <alice@example.org>` und `bob@example.org` schreibt.

Getestet in `tests/Unit/Domain/MailingList/MemberFormatGoldenTest.php` und
`maillist/tests/test_member_format.py`.

Die Fälle `non-ascii`, `no-dot-domain`, `trailing-dot`, `group-syntax`, `quotes`, `comments`,
`backslash`, `domain-literal`, `entity-without-semicolon` und die `control-*`-Fälle halten fest, welche Einträge das Skript überspringt. Für neue
Mitglieder lehnt `MemberList` genau diese ab (`list.member_not_deliverable`, U-137), dazu jeden
Eintrag mit einem Steuerzeichen, auch einem Tab, den gespeicherte Einträge tragen dürfen.
