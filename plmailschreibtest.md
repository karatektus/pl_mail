# plMail — Schreibaktionen im Test

**Instanz:** https://mail.vpn.cpy-pst.de · **Version:** v0.1.9 (`132c1bf`)
**Datum:** 22.08.2026, ca. 11:00–12:30 · **Theme:** Beere · **Sprache:** Deutsch
**Umfang:** Markieren, Labeln, Gelesen/Ungelesen, Archivieren, Löschen, Zurückstellen,
Verfassen, Senden, Undo-Send, Zeitversand, Entwürfe, Labels, Filter, Kalendertermine,
Darstellung, Signaturen

> **Endgültiges Löschen habe ich nicht getestet.** Papierkorb leeren und „unwiderruflich
> löschen" fasse ich grundsätzlich nicht an — das lässt sich nicht zurücknehmen. Alles
> unten ist reversibel gewesen, mit den vier Ausnahmen unter „Was noch aufzuräumen ist".

---

## Auf einen Blick

| # | Befund | Bereich | Schwere |
|---|--------|---------|---------|
| 1 | Aus dem Papierkorb führt kein Weg zurück | Mail-Aktionen | Hoch |
| 2 | Archivierte Mails kommen nicht in den Posteingang zurück | Mail-Aktionen | Hoch |
| 3 | Zugewiesene Labels lassen sich nicht mehr entfernen | Labels | Hoch |
| 4 | Gesendete Mail erscheint doppelt, einmal ohne Absender | Gesendet | Hoch |
| 5 | „Dieses Wochenende" stellt in die Vergangenheit zurück | Zurückstellen | Mittel |
| 6 | Zurückgestellt: kein Enddatum, kein Aufheben | Zurückstellen | Mittel |
| 7 | Auswahl-Toolbar desynchronisiert nach der ersten Aktion | Mail-Liste | Mittel |
| 8 | Theme-Wechsel und zurück verliert die Akzentfarbe | Darstellung | Mittel |
| 9 | Bestätigungen völlig uneinheitlich | überall | Mittel |
| 10 | Listen aktualisieren sich nach Aktionen nicht | Mail-Liste | Mittel |
| 11 | Dialogtitel lautet „Titel" | Kalender | Niedrig |
| 12 | Terminname bricht mitten im Wort um | Kalender | Niedrig |
| 13–19 | Wording, Toasts, Platzhalter | diverse | Niedrig |

---

## Hoch

### 1. Aus dem Papierkorb führt kein Weg zurück

**Reproduktion:** Thread in den Papierkorb legen → `/mail/trash` öffnen → Zeile ansehen.

Die Zeilenaktionen im Papierkorb sind exakt dieselben wie überall sonst:

```
Archivieren · Löschen · Zurückstellen · Als ungelesen markieren · Diese Nachricht markieren
```

Es fehlt „Wiederherstellen" bzw. „In den Posteingang verschieben". Der einzige plausible
Ausweg — „Archivieren" — ist im Papierkorb **wirkungslos**: nach dem Klick bleibt die
Nachricht im Papierkorb, das Archiv bleibt leer, der Papierkorb-Zähler unverändert bei 210.
Keine Fehlermeldung, kein Toast, nichts.

Damit ist „Löschen" faktisch eine Einbahnstraße, obwohl es sich nur um einen Ordnerwechsel
handeln sollte.

Erschwerend: das „Löschen" im Papierkorb hat **keine Rückfrage** (kein `data-turbo-confirm`,
kein Dialog). Ob es dort endgültig löscht, habe ich bewusst nicht ausprobiert — falls ja,
ist das ein unwiederbringlicher Datenverlust mit einem einzigen unbestätigten Klick.

### 2. Archivierte Mails kommen nicht in den Posteingang zurück

Gleiches Muster eine Ebene höher. Thread 232 archiviert → `/mail/archive`:

- Zeilenaktionen: `Archivieren · Löschen · Zurückstellen · Als ungelesen · Markieren`
  → bietet „Archivieren" an, obwohl die Mail bereits im Archiv liegt.
- Thread-Ansicht, Toolbar: `Zurück · Archivieren · Löschen · Als ungelesen · Label` — kein Posteingang.
- Thread-Ansicht, ⋮-Menü: `Antworten · Weiterleiten · Markieren · Als ungelesen markieren ·
  Archivieren · Diese Nachricht löschen · Original anzeigen · Drucken · Fehlendes Insight melden`
  — kein Posteingang.
- Label-Menü: nur `Templates` und `Neu erstellen`. Systemlabels wie „Posteingang" stehen nicht zur Wahl.

Es gibt also an **keiner** Stelle der Oberfläche eine Aktion, die eine archivierte
Konversation zurück in den Posteingang holt.

### 3. Zugewiesene Labels lassen sich nicht mehr entfernen

**Reproduktion:** Nachricht auswählen → Label-Menü → „Templates" klicken (Label wird gesetzt,
Zeile bekommt den Chip, Seitenleiste zählt hoch). Seite neu laden → dieselbe Nachricht
auswählen → Label-Menü öffnen.

Zwei Probleme:

- **Der Haken zeigt den falschen Zustand.** Direkt nach dem Zuweisen erscheint ein ✓ neben
  „Templates". Nach einem Reload ist der Haken weg — obwohl die Nachricht das Label sichtbar
  trägt. Das Menü liest den tatsächlichen Zuweisungsstand nicht vom Server.
- **Erneutes Klicken entfernt nichts.** Der Haken erscheint wieder (rein lokal), der Chip
  bleibt an der Zeile, der Seitenleisten-Zähler bleibt bei 2. Ein Toggle-Verhalten wird
  angedeutet, existiert aber nicht.

Ein einmal vergebenes Label bleibt damit dauerhaft an der Nachricht kleben.

### 4. Gesendete Mail erscheint doppelt, einmal ohne Absender

Nach einem einzigen erfolgreichen Versand zeigt `/mail/sent` **zwei Zeilen** für dieselbe
Nachricht, beide um 12:09:

```
[I]  NEU  ich    plMail Schreibtest 1 - Ver…   Testnachricht aus dem Schrei…   12:09
[?]           plMail Schreibtest 1 - Ver…                                  12:09
```

Die zweite Zeile hat einen leeren Absender und ein Fragezeichen-Avatar.

Dazu zwei weitere Auffälligkeiten in derselben Liste:

- **„NEU" auf einer selbst gesendeten Mail.** Die Zeile trägt zusätzlich das
  Screenreader-Label „DIR NOCH NIE ANGEZEIGT". Auf einer Nachricht, die man gerade selbst
  abgeschickt hat, ergibt das keinen Sinn.
- **Absendername uneinheitlich.** Die neue Zeile sagt „ich", die beiden älteren Einträge
  sagen „Sven" — identischer Absender, zwei Schreibweisen. Und in einem Gesendet-Ordner
  gehört ohnehin der Empfänger in die Spalte, nicht man selbst. Dass die App das kann,
  zeigt der Entwürfe-Ordner: dort steht korrekt „**An: uptime@joder.dev**".

---

## Mittel

### 5. „Dieses Wochenende" stellt in die Vergangenheit zurück

Getestet am **Samstag, 22.08.2026, 11:52 Uhr**. Das Zurückstellen-Menü bot an:

```
Später heute        Sa., 18:00     ✓ in der Zukunft
Morgen              So., 08:00     ✓ in der Zukunft
Dieses Wochenende   Sa., 08:00     ✗ vier Stunden in der VERGANGENHEIT
Nächste Woche       Mo., 08:00     ✓ in der Zukunft
```

Ich habe „Dieses Wochenende" ausgeführt: die Nachricht verschwand aus dem Posteingang und
landete in „Zurückgestellt" — mit einem Weckzeitpunkt, der bereits verstrichen war.

Zum Vergleich: das **Zeitversand-Menü im Verfassen-Fenster rechnet korrekt** („Morgen früh
So., 08:00" / „Morgen Nachmittag So., 13:00" / „Montagmorgen Mo., 08:00" — alle in der
Zukunft). Die beiden Menüs benutzen offenbar unterschiedliche Datumslogik.

### 6. Zurückgestellt: kein Enddatum, kein Aufheben

Der Ordner „Zurückgestellt" taucht in der Seitenleiste erst auf, sobald etwas zurückgestellt
ist — das ist in Ordnung. Der Rest nicht:

- Die Zeile zeigt **nicht, bis wann** zurückgestellt wurde. Nur der Eingangszeitpunkt (11:06).
- Es gibt **kein „Zurückstellung aufheben"**. Das Zurückstellen-Menü bietet dort erneut
  nur dieselben vier Zeitpunkte an.
- In der Thread-Ansicht deutet **nichts** darauf hin, dass die Nachricht zurückgestellt ist.

Kombiniert mit Befund 5 heißt das: eine auf einen vergangenen Zeitpunkt zurückgestellte
Nachricht sitzt in einem Ordner, aus dem sie über die Oberfläche nicht wieder herauskommt.

### 7. Auswahl-Toolbar desynchronisiert nach der ersten Aktion

Mehrfach beobachtet, nicht bei jedem Durchlauf:

- Zwei Zeilen auswählen → Label zuweisen → die Zeilen-Checkboxen sind zurückgesetzt
  (`input:checked` = **0**), die Toolbar zeigt weiterhin „**2**" und bleibt aktiv.
- Eine Zeile auswählen → Label zuweisen → 0 gehakt, Toolbar zeigt „**1**".
- Einmal war eine Zeile sichtbar gehakt, die Aktions-Toolbar erschien aber gar nicht.

Folge: eine weitere Massenaktion trifft eine Auswahl, die man nicht mehr sieht — oder
läuft ins Leere. Ich habe genau das reproduziert: „Archivieren" mit desynchronisierter
Auswahl war ein stiller No-Op (Posteingang unverändert, Archiv leer, keine Meldung).

Die Aktionen selbst funktionieren, wenn die Auswahl frisch ist: sowohl die Zeilen-Aktion
als auch die Toolbar-Aktion „Als gelesen markieren" haben sauber persistiert (Badge 8 → 7 → 6).

### 8. Theme-Wechsel und zurück verliert die Akzentfarbe

**Reproduktion:** Darstellung → Theme „Dunkel" → Theme wieder auf „Hell".

Die Oberfläche rendert danach eine **blaue** Akzentfarbe (`rgb(37, 99, 235)`), während das
gespeicherte Branding unverändert „berry" bleibt:

```
favicon:      /branding/favicon.svg?v=berry
theme-color:  #a21caf   (fuchsia)
Logo oben links: weiterhin violett/pink
Buttons, Pills, aktive Zustände: blau
```

Auch ein vollständiger Reload behebt es nicht — Logo und Oberflächen-Akzent bleiben
auseinander. Erst das erneute, explizite Auswählen des Themes „**Beere**" (unter „Mehr
Themes") stellt `#a21caf` wieder her.

Hintergrund: „Beere" ist ein eigenes Theme, das im DOM ebenfalls als helles Theme geführt
wird. Die kompakte Theme-Auswahl oben zeigt aber nur System/Hell/Papier/Dunkel/Nord/
Dämmerung/Solar — ein Nutzer mit „Beere" sieht dort **keine** seiner Auswahl entsprechende
Kachel markiert und klickt naheliegenderweise „Hell". Damit ist die eigene Theme-Auswahl
still überschrieben.

### 9. Bestätigungen völlig uneinheitlich

Dieselbe Klasse von Aktion wird mal hart abgesichert, mal gar nicht:

| Aktion | Rückfrage |
|---|---|
| Filter löschen | **nativer Browser-Dialog** (`confirm()`) |
| Filter jetzt ausführen | **nativer Browser-Dialog** (`confirm()`) |
| Label löschen (aus der Liste) | keine — sofort weg |
| Kalendertermin löschen | keine — sofort weg |
| Entwurf löschen | keine |
| Mail in den Papierkorb | keine |
| Mail im Papierkorb löschen | keine |

Zwei Dinge stechen heraus:

- Die **nativen Dialoge passen nicht zur App**, die für „Label erstellen", „Label bearbeiten"
  und „Neuer Termin" eigene, gestaltete Modals hat. Sie blockieren außerdem den kompletten
  Tab — während sie offen sind, reagiert die Seite auf nichts mehr.
- **Label löschen ohne Rückfrage** ist besonders unglücklich: der Bearbeiten-Dialog erklärt
  ausdrücklich, dass Löschen das Label „aus allen Konten" und samt aller untergeordneten
  Labels entfernt. Genau diese Aktion ist aus der Liste mit einem einzigen Klick erreichbar,
  ohne die Warnung.

Ein **Rückgängig** gibt es für keine dieser Aktionen. Beim Archivieren, Löschen und
Labeln erscheint nicht einmal ein Toast.

### 10. Listen aktualisieren sich nach Aktionen nicht

- Nach dem Archivieren von Thread 232 verschwand die Zeile aus dem Posteingang, der Zähler
  blieb aber bei „**1–5 von 5**" statt 4.
- Nach dem Löschen aus dem Archiv zeigte die Archiv-Liste die Nachricht **weiterhin** an,
  inklusive „1–1 von 1". Erst ein manueller Reload räumte auf.
- Nach dem Löschen des letzten Entwurfs blieb „1–1 von 1" stehen, die Liste war leer und
  der Leerzustand „Keine Entwürfe." erschien **nicht**.

Die Zeile wird also lokal entfernt, ohne dass Pager und Leerzustand mitgezogen werden.

---

## Niedrig

### 11. Der Bearbeiten-Dialog eines Termins heißt „Titel"

Termin anklicken → der Dialog trägt als Überschrift wörtlich „**Titel**" statt des
Terminnamens oder „Termin bearbeiten". Offensichtlich ist das Feldlabel in die
Dialogüberschrift gerutscht.

Im selben Dialog: der Link unten links heißt „**.ics herunterladen**" — führender Punkt,
klein geschrieben, direkt neben „Löschen" ohne Trennung. Besser: „Als ICS herunterladen".

### 12. Terminname bricht mitten im Wort um

Ein Termin „ZZ Testtermin" wird im Wochenraster als

```
ZZ
Testter
min
```

dargestellt. Kein Silbentrennungsschutz, kein Auslassungszeichen — und das, obwohl die
Tagesspalte ansonsten komplett leer war.

### 13. Weitere Kleinigkeiten aus dem Schreibvorgang

| Ort | Beobachtung |
|---|---|
| Verfassen, Kopfzeile | Statusanzeige „**noch 5 Zeichen bis zum Speichern**" — technisch gedacht, an der Stelle wo sonst der Betreff steht |
| Toast nach Zeitversand | „Geplant für 23. Aug., 08:00 **5** Versand abbrechen" — die nackte „5" (vermutlich ein Countdown) steht unbeschriftet mitten im Text |
| Empfänger-Autocomplete | drei Zeilen für einen Treffer: „sven@joder.dev hinzufügen…", „sven@joder.dev", „Keine weiteren Ergebnisse" |
| Filter-Formular | Feldlabel „**Account**" direkt neben „Name" — überall sonst heißt es „Konto" |
| Signatur speichern | kein Toast, kein Hinweis; der Button verblasst nur |
| Signatur leeren | nach dem Leeren fehlt der Platzhalter „Keine Signatur", das Feld wirkt kaputt statt leer |
| Sync-Button oben rechts | `aria-label="Sync now"` — englisch in der deutschen Oberfläche |
| Label-Dropdown | `bg-pane-soft` = `rgba(255,255,255,0.6)` mit nur `blur(8px)` — die Nachrichtenliste liest sich durch das Menü hindurch. Benutzermenü (`rgb(255,255,255)`) und Such-Panel (`bg-surface`) sind deckend, das Label-Menü ist der Ausreißer |
| Zeilen-Hover | die Aktionsicons **ersetzen** den Zeitstempel — man verliert das Datum genau in dem Moment, in dem man handeln will |
| Modale | „Label erstellen", „Label bearbeiten", „Neuer Termin" zeigen 5–8 Sekunden lang nur einen Spinner, bevor der Inhalt erscheint |

---

## Was einwandfrei funktioniert hat

- **Versand end-to-end.** Mail an sven@joder.dev geschrieben, gesendet, korrekt im
  Posteingang zugestellt. Entwurf wurde nach dem Versand automatisch aufgeräumt.
- **Undo-Send.** „Senden" schaltet auf „Wird gesendet… zum Abbrechen klicken", das Formular
  wird ausgegraut; ein Klick bringt die Nachricht bearbeitbar zurück, der Entwurf bleibt erhalten.
- **Zeitversand.** Über die Vorschläge geplant, sauber als Chip „🕐 Geplant 23. Aug., 08:00 ✕"
  im Entwürfe-Ordner sichtbar, per ✕ abgebrochen mit Toast „Geplanter Versand abgebrochen."
  Das ist die beste Interaktion in der ganzen App.
- **Entwurf-Autosave.** „Wird gespeichert…" → „Entwurf gespeichert", Betreff wandert in die
  Fenstertitelzeile, Empfänger-Chips funktionieren.
- **Markieren (Stern).** Setzen und Entfernen persistiert, Ordner „Markiert" füllt sich korrekt.
- **Gelesen/Ungelesen.** Persistiert sowohl über die Zeilenaktion als auch über die Toolbar
  als auch durch Öffnen des Threads; Zähler laufen korrekt mit.
- **Label anlegen, umbenennen, umfärben, löschen.** Alles sauber, Seitenleiste aktualisiert live.
- **Filter-Builder.** Der beste Teil der Einstellungen: Live-Vorschau der Regel als Satz
  („Wenn Betreff enthält … → Label vergeben Templates") **plus** Live-Trefferzähler
  („Trifft auf 0 vorhandene Nachrichten zu") schon beim Tippen. Anlegen, Ausführen
  („Auf 0 Nachrichten angewendet") und Löschen funktionierten.
- **Kalendertermin.** Anlegen, Bearbeiten, Löschen funktionieren; der Kalender-Auswahlbereich
  im Dialog erklärt sein Verhalten ausführlich und richtig.
- **Dunkles Theme.** Sauber durchgezogen, keine unlesbaren Stellen gefunden.
- **Signatur.** Speichern und Leeren persistieren.

Nebenbei bestätigt: die **404-Seite bleibt auch bei aktivem dunklem Theme im beigen
„Papier"-Look** und auf Englisch. Ein Nutzer im Dunkelmodus bekommt bei einem Tippfehler
in der URL eine hellbeige englische Seite.

---

## Was noch aufzuräumen ist

Vier Dinge konnte ich **nicht** zurücksetzen, weil die Oberfläche keinen Weg dafür anbietet
— genau die Befunde 1–3:

1. **Thread 232** (`bahn-bkk@brand-styleguide.de`, 9d58da21…) liegt im **Papierkorb** und
   lässt sich von dort nicht zurückholen.
2. **Thread 229** (`markenportal@…`, 8f488568…) liegt in **Zurückgestellt**, auf einen
   bereits vergangenen Zeitpunkt, ohne Möglichkeit zum Aufheben.
3. Das Label **Templates** klebt an zwei Portal-Testmails (Threads 234 und 233) und lässt
   sich per UI nicht mehr abziehen.
4. Meine Testmail „plMail Schreibtest 1 - Versand" liegt noch **zweimal in Gesendet**
   (die Kopie im Posteingang habe ich in den Papierkorb gelegt).

Außerdem sind vier Portal-Testmails jetzt als gelesen markiert.

Alles andere ist zurückgestellt: Testlabel gelöscht, Testfilter gelöscht, Testtermin
gelöscht, Entwurf gelöscht, geplanter Versand abgebrochen, Signatur wieder leer,
Theme „Beere" und Logo „Dem Theme folgen" wiederhergestellt, Stern entfernt.

**Ein Hinweis in eigener Sache:** ich hatte vor dem Test die Darstellungseinstellungen
notiert, dabei aber nicht festgehalten, *welches* Theme aktiv war — die Theme-Kacheln
melden ihren Zustand nicht über die üblichen Attribute. Beim Zurückstellen habe ich
deshalb zuerst „Hell" gewählt und damit „Beere" überschrieben. Aufgefallen ist es an der
plötzlich blauen Akzentfarbe; wiederhergestellt habe ich es über „Mehr Themes" → „Beere".
Genau dieser Stolperstein steckt in Befund 8.
