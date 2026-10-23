# Golden-Dateien für das Feld `lists.members`

Gemeinsamer Vertrag zwischen mailadmin (schreibt `lists.members`) und
`maillist/maillist.py` (liest es und verteilt die Mail).

- `<fall>.members` ist der gespeicherte Feldinhalt, byteweise. Keine Datei endet
  automatisch mit einem Zeilenumbruch; was drinsteht, ist der Wert.
- `<fall>.recipients` sind die Empfängeradressen, die `normalize_recipients()`
  daraus ermitteln muss, eine pro Zeile, in dieser Reihenfolge.

`canonical.members` ist zusätzlich genau das, was `MemberList` für die Mitglieder
`Alice Example <alice@example.org>` und `bob@example.org` schreibt.

Getestet in `tests/Unit/Domain/MailingList/MemberFormatGoldenTest.php` und
`maillist/tests/test_member_format.py`.
