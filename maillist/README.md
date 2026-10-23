# maillist

Zustellskript für Mailinglisten. Postfix ruft es über einen `pipe(8)`-Transport auf, es liest die
Liste aus der Tabelle `lists` und verteilt die Mail an jedes Mitglied über das lokale Postfix. Der
Listenschlüssel kommt als Argument (`-- ${user}`); ohne Argument greift während der Umstellung die
Rückfallebene über `Delivered-To`.

Installation und Betrieb: [docs/deployment/maillist.md](../docs/deployment/maillist.md).

## Aufbau

Das Skript besteht aus fünf Modulen, die flach nebeneinander liegen und zusammen ein Release bilden
(U-147):

| Datei | Inhalt |
|---|---|
| `maillist.py` | Einstieg, den Postfix aufruft: Argumente, Datenbankabfrage, Zustellablauf, `--check` |
| `config.py` | Konfiguration, Logging, Exit-Codes |
| `members.py` | Lesen des Felds `members`; Gegenstück zu `src/Domain/MailingList/MemberList.php` |
| `headers.py` | Schleifen- und Bounce-Erkennung, Listenköpfe |
| `delivery.py` | SMTP-Zustellung und Fehlerberichte an die Admins |

`maillist.py` importiert die anderen aus seinem eigenen Verzeichnis, auch wenn der Interpreter mit
`-I` läuft. Wer ein Modul hinzufügt, trägt es in `CODE_FILES` in `install`, in `MAILLIST_FILES` in
`bin/package-release` und in `py-modules` in `pyproject.toml` ein; ein Test prüft, dass alle drei
Listen zum Verzeichnis passen.

## Herkunft und Lizenz

Ursprünglich von Erich Malzer im Projekt maillist-py unter der Apache License 2.0 geschrieben. Als
Teil von mailadmin weitergeführt. Diese Datei ist das erforderliche Attributions-Notice.

## Konfiguration

Alle Einstellungen stehen in einer INI-Datei. Der Pfad kommt aus der Umgebungsvariable
`MAILLIST_CONFIG`, der Standard ist `/etc/mailadmin-maillist/maillist.ini`. Vorlage mit allen
Schlüsseln: [maillist.ini.example](maillist.ini.example). Zugangsdaten stehen nie im Quelltext.

`maillist.py --check` prüft Konfiguration, Logdatei und Datenbankverbindung.

## Exit-Codes

`pipe(8)` wertet den Exit-Code nach `sysexits.h` aus.

| Fall | Code | Wirkung in Postfix |
|---|---|---|
| zugestellt, oder bewusst verworfen (Schleife, Bounce) | `0` | erledigt |
| falscher Aufruf, z. B. kein Listenschlüssel und kein eindeutiges `Delivered-To` | `64` (`EX_USAGE`) | Bounce |
| Liste nicht gefunden, oder kein brauchbarer Empfänger | `67` (`EX_NOUSER`) | Bounce |
| unerwarteter Fehler | `70` (`EX_SOFTWARE`) | Bounce, Fehlerbericht an die Admins |
| Datenbank, SMTP oder Konfiguration vorübergehend unbrauchbar | `75` (`EX_TEMPFAIL`) | erneuter Versuch |

Im Pipe-Betrieb gibt das Skript nichts aus, weil Postfix stdout und stderr in den Bounce kopiert.
Ausnahme ist die feste Zeile `maillist: configuration unusable`.

## Tests

```bash
uv run --project maillist --extra dev pytest maillist/tests -q
uv run --project maillist --extra dev ruff check maillist maillist/install
uv run --project maillist --extra dev ruff format --check maillist maillist/install
```

Die Golden-Tests lesen `tests/Fixtures/MemberFormat` und brauchen deshalb den Checkout. Eine Änderung
an `members.py` braucht die passende Änderung an `MemberList` und einen Fall in den Golden-Dateien.
