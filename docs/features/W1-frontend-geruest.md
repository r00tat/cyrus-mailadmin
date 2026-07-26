<!-- markdownlint-disable MD013 MD024 MD060 -->

# W1 — Frontend-Gerüst

| | |
|---|---|
| **Stufe** | 5 — Oberfläche |
| **Hängt ab von** | F2, S2 |
| **Pflichtlektüre** | [CLAUDE.md](../../CLAUDE.md) · [Architektur](../03-architektur.md), Abschnitt 11 · E-15 |
| **Status** | offen |

---

## Ziel

Eine lauffähige Single-Page-Anwendung: Build, Routing, generierter API-Client,
Anmeldeablauf, Fehlerbehandlung, Navigation und Grundlayout. Die fachlichen Ansichten kommen
mit den A-Paketen.

## Abgrenzung

- Keine fachlichen Ansichten — die gehören zu A1 bis A6.
- Keine Mehrsprachigkeit — das ist **W2**. W1 legt aber schon Textschlüssel an statt fester
  Zeichenketten.
- Kein Offline-Betrieb, kein Service Worker, kein Manifest (E-6).

## Abhängigkeiten und geteilte Dateien

Braucht ein Schema aus F2 und die Endpunkte aus S2. Legt die Muster fest, denen die
fachlichen Ansichten folgen.

## Vorgaben

- **Zugriffstoken im `localStorage`** (E-15). Das **Erneuerungstoken bleibt im
  HttpOnly-Cookie** — Eskalationsfall, wenn jemand es anders will.
- Der API-Client wird aus `openapi.yaml` typisiert erzeugt; kein handgepflegtes API-Modul.
- **Die Content-Security-Policy nicht aufweichen.** Erzeugt der Bundler Inline-Skripte, wird
  der Bundler angepasst.
- Berechtigungen kommen aus `GET /auth/me`; die Oberfläche blendet aus, das Backend entscheidet.
- Abmelden räumt `localStorage` **und** Cookie ab.

## Artefakte

- `frontend/` mit Build-Konfiguration
- Generierung der Typen aus dem Schema, als Build-Schritt
- `frontend/src/api/client.ts` — setzt `Authorization`, wertet `problem+json` aus, behandelt
  `401` mit einmaliger Erneuerung
- Anmeldeansicht, Navigation, Layout, Fehler- und Ladezustände
- Auslieferung nach `public/assets/` mit Inhalts-Hash im Dateinamen

## Zu klärende Punkte

1. **Build-Werkzeug.** Vite ist naheliegend. Bestätigen.
2. **UI-Bibliothek.** Fertige Komponentensammlung (schnell, aber Abhängigkeit und fremdes
   Aussehen) oder eigenes, schlankes CSS (mehr Arbeit, passt zu E-12)? Das ist die
   folgenreichste Entscheidung dieses Pakets.
3. **Tabellen.** Sortierung, Filter und Paginierung kommen vom Server. Braucht es dafür eine
   Bibliothek, oder genügt eine eigene Komponente?
4. **Formulare und Validierung.** Bibliothek oder eigenes? Die Fehlerabbildung aus
   `problem+json` muss hineinpassen.
5. **Verhalten bei parallelen `401`.** Mehrere gleichzeitige Anfragen dürfen nicht mehrere
   Erneuerungen auslösen — Bündelung festlegen.
6. **Was passiert bei abgelaufener Sitzung mitten im Formular?** Eingaben verwerfen oder nach
   erneuter Anmeldung wiederherstellen?
7. **Zugänglichkeit.** Welcher Anspruch — Tastaturbedienbarkeit und sinnvolle Beschriftungen
   als Mindestmaß?

## Definition of Done

- Anmeldung, Navigation und Abmeldung funktionieren gegen die echte API.
- Ein abgelaufenes Token wird einmalig erneuert; scheitert das, erscheint die Anmeldung.
- Fehler aus `problem+json` erscheinen verständlich, mit Request-Id für Rückfragen.
- Der Build erzeugt keine Inline-Skripte; die CSP greift ohne Ausnahme.
- Keine feste Zeichenkette in der Oberfläche — alles über Schlüssel.

## Testfälle

- **Unit:** API-Client — `401`-Behandlung, Bündelung, Fehlerabbildung.
- **Komponente:** Anmeldeformular mit gültigen und ungültigen Daten.
- **End-to-End:** Anmelden, navigieren, abmelden; danach ist kein Token mehr gespeichert.
