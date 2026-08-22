# plMail — Crawl-Befunde

**Instanz:** https://mail.vpn.cpy-pst.de · **Version:** v0.1.9 (`132c1bf`)
**Datum:** 22.08.2026 · **Sprache:** Deutsch · **Theme:** Hell · **Layout:** Flach
**Umfang:** alle Mail-Ordner, Thread-Ansicht, Suche, Compose, Kalender, alle 14 Einstellungs-Sektionen, Admin, 404

---

## Auf einen Blick

| # | Befund | Bereich | Schwere |
|---|--------|---------|---------|
| 1 | `/compose/new` rendert komplett ohne CSS | Compose | Hoch |
| 2 | 404-Seite ist englisch und im falschen Theme | Fehlerseiten | Hoch |
| 3 | Integrationen lädt erst nach ~16 s | Einstellungen | Hoch |
| 4 | Papierkorb listet jede Nachricht dreifach | Mail-Liste | Hoch |
| 5 | Vorschau zeigt „Email is only available as html" | Mail-Liste | Hoch |
| 6 | Roher HTML-Code in der Spam-Vorschau | Mail-Liste | Mittel |
| 7 | „Gesendet" zeigt Absender statt Empfänger | Mail-Liste | Mittel |
| 8 | Sie/Du gemischt | Texte | Mittel |
| 10 | Buttons/Zeilen brechen aus den Karten aus | Einstellungen | Mittel |
| 11 | Kein `<h1>`, kein `<main>`, `<nav>` ohne Label | Barrierefreiheit | Mittel |
| 12 | ISO-Datumsformat nur unter „Sicherheit" | Texte | Niedrig |
| 13–24 | Wording-, Icon- und Layout-Details | diverse | Niedrig |

---

## Hoch

### 1. `/compose/new` rendert komplett ohne Layout

**Route:** `https://mail.vpn.cpy-pst.de/compose/new` (direkt aufgerufen)

Die Route liefert ein nacktes `<turbo-frame>`-Fragment aus. Im Browser gemessen:

```
stylesheets: 0      hasTurboFrame: true
styleTags:   1      firstChild:    TURBO-FRAME
lang:        ""     title:         ""
```

Ergebnis ist unformatiertes HTML — Systemschrift, native Buttons, alle Zustände gleichzeitig sichtbar
(z. B. „Senden Wird gesendet… zum Abbrechen klicken" als ein Textblock). Über den Button „Schreiben"
im Frame-Kontext ist die Ansicht dagegen korrekt.

**Trifft echte Nutzer bei:** Bookmark auf die URL, Öffnen in neuem Tab, Rücksprung nach Session-Ablauf,
Turbo-Fallback ohne JS.

**Fix:** Non-Frame-Requests auf `/compose/new` in das Basis-Layout rendern (bzw. auf `/mail/inbox` mit
geöffnetem Compose-Dock umleiten).

### 2. 404-Seite ist englisch und ignoriert die Theme-Einstellung

**Route:** beliebige unbekannte URL, z. B. `/gibtesnicht-404-test`

```
lang: "en"                    data-theme: "paper"
title: "Page not found — Mail"
```

Drei Abweichungen auf einer Seite:

- **Sprache:** „Nothing at this address", „The page you asked for is not here…", „Back to your mail" —
  die restliche Oberfläche ist durchgängig deutsch.
- **Theme:** beige/braun (Theme „Papier") statt des eingestellten Themes „Hell" und der Marken-Akzentfarbe.
- **Logo:** generisches Umschlag-Icon + „Mail" statt des „PL"-Logos aus dem Header.

**Fix:** Fehler-Templates ins normale Layout hängen, Locale und Theme aus der Session ziehen.

### 3. Einstellungen → Integrationen bleibt ~16 Sekunden auf „Wird geladen…"

**Route:** `/settings?section=integrations`

Der Lazy-Frame `settings-integrations-frame` (`src=/settings/integrations/list`) startet seinen Request
erst weit nach dem Seitenaufbau. Performance-API der Seite:

```
Navigation:  TTFB 155 ms · DOMContentLoaded 1311 ms · load 1317 ms
/settings/integrations/list:  start 16426 ms · Dauer 92 ms
(zweiter Aufruf)              start 29684 ms · Dauer 94 ms
```

Der Request selbst ist mit 92 ms schnell — er wird nur ~16 s zu spät ausgelöst. Der Frame liegt oberhalb
des Falzes, `loading="lazy"` ist hier also falsch bzw. der Intersection-Trigger greift nicht.
Inhalt danach: „Noch keine Dienste verbunden" + 6 gesperrte Dienste — nichts, was 16 s rechtfertigt.

Derselbe Effekt ist unter `/admin` („System"-Panel) zu sehen.

**Fix:** `loading="eager"` für sichtbare Frames, oder den Trigger reparieren.

### 4. Papierkorb listet jede Nachricht dreifach

**Route:** `/mail/trash` — 195 Einträge

Jede Test-Mail erscheint exakt dreimal, mit identischem Absender, identischem Betreff-Hash und identischem
Zeitstempel:

```
brandhub@fuchs.com   ced5f3faa7e983962553a6e3503db393   6. Aug.   ×3
noreply@markenportal-uk-koeln.de  41c4722fc2b407f44cf13f297769d838  3. Aug.  ×3
Medios AG Hub        a13495206df754b65ca929bbde528638   1. Aug.   ×3
noreply@brandportal.brita.net  420291e93f77267c55c052f1a370d4fd  1. Aug.  ×3
brandhub@avm.de      9fd6d83d6f48243b7988ded8459011d0   1. Aug.   ×3
… (durchgängig über alle 50 Einträge der ersten Seite)
```

Drei Kopien = drei Konten (sven@ / uptime@ / pmd@). Im **Posteingang** wird derselbe Fall korrekt zu einer
Zeile gruppiert — im Papierkorb greift die Thread-Gruppierung nicht. Dadurch sind die 195 Einträge
faktisch ~65 Nachrichten.

**Fix:** dieselbe Thread-/Message-ID-Gruppierung wie in `/mail/inbox` auch für Trash (und vermutlich Spam) anwenden.

### 5. Listen-Vorschau zeigt „Email is only available as html"

**Route:** `/mail/inbox` — betrifft aktuell die Mehrzahl der Zeilen

Zwei Probleme in einem String:

1. **Englisch** in einer deutschen Oberfläche.
2. **Sachlich falsch.** Öffnet man dieselbe Nachricht (z. B. Thread 217, `brandhub@fuchs.com`), zeigt die
   Detailansicht sauber lesbaren Text („Testmail for "https://brand.fuchs.com", sent at …" plus
   Fußzeile). Der Text ist also da — nur der Snippet-Generator kommt an ihn nicht heran.

**Fix:** Vorschau aus dem HTML-Part erzeugen (Tags strippen, Entities dekodieren); den Fallback-String
übersetzen und neutraler formulieren („Keine Vorschau verfügbar").

---

## Mittel

### 6. Roher HTML-Code in der Vorschau

**Route:** `/mail/spam`

```
Follow-up: wsc-haibach.de in eine App verwandeln?
  <p style="margin:0 0 16px 0;"> Hallo, ich möchte mich kurz noch einmal melden und würde mich freuen
```

Das Style-Attribut wird als sichtbarer Text ausgegeben. Gegenstück zu Befund 5: mal wird HTML gar nicht
verarbeitet, mal ungefiltert durchgereicht.

### 7. „Gesendet" zeigt den Absender statt des Empfängers

**Route:** `/mail/sent`

```
S  Sven   Another one   Beispiel   10:17
S  Sven   Test          Foobat     10:16
```

In einem Gesendet-Ordner ist „Sven" (= man selbst) die einzige Information, die niemand braucht.
Üblich und erwartbar ist der Empfänger, meist mit Präfix „An: …".

### 8. Sie und Du gemischt

Die Oberfläche duzt durchgängig — „**Dein** Profil", „Wie sich das Verfassen-Fenster für **dich** verhält",
„Schreibe **deine** Nachricht…", „Was plMail aus **deinen** Mails herausliest".

**Ausnahme: Einstellungen → Aliase.** Dort wird gesiezt:

- „Was jede **Ihrer** Adressen beim Senden und Empfangen standardmäßig tut."
- „Wenn ein Absender benachrichtigt werden möchte, sobald **Sie** seine Nachricht gelesen haben."


### 10. Buttons und Zeilen brechen aus den Karten aus

Drei Stellen, gleiches Muster — ein Element ignoriert das Padding seiner Karte:

| Route | Element | Beobachtung |
|---|---|---|
| `?section=signature` | Button „Signatur speichern" | steht **auf** dem rechten Kartenrand, überlappt die Rahmenlinie |
| `?section=general` | Zeile „Zitierter Text beim Weiterleiten" | Text beginnt links **außerhalb** des Karten-Paddings, der Toggle ragt rechts über die Karte hinaus |
| Compose-Dock | Papierkorb-Icon | fällt in eine eigene zweite Zeile links unten statt neben „Senden" zu stehen |

### 11. Barrierefreiheit: Dokumentstruktur

Im DOM des Posteingangs gemessen:

```
Überschriften:  ["H2: \"\""]        ← einzige Überschrift, und die ist leer
<main>:         nicht vorhanden
<nav>:          3 Stück, alle ohne aria-label
Skip-Link:      nicht vorhanden
```

Screenreader-Nutzer haben damit weder eine Überschriftennavigation noch eine Möglichkeit, die drei
Navigationsbereiche (Ordner-Sidebar, Konten, Mobil-Drawer) auseinanderzuhalten oder zu überspringen.

**Positiv:** Icon-Buttons haben durchgängig `aria-label`, alle `<img>` haben `alt`, `lang="de"` ist gesetzt,
Kontraste liegen über 4,5:1 (`text-ink-faint` 4,64:1, `text-ink-muted` 6,33:1).

---

## Niedrig — Texte und Wording

| Route | Ist | Anmerkung |
|---|---|---|
| `?section=security` | `Aktiviert am 2026-08-10`, `Läuft ab am 2026-09-21`, `Zuletzt verwendet 2026-08-22 10:38` | ISO-Format; überall sonst „19. Aug.", „17.–23. Aug. 2026" |
| `?section=security` | `Chrome on Windows` | englischer Gerätestring |
| `?section=calendars` | Kalender `Personal` (Standard) | untranslatierter Default; sonst alles deutsch. Die Zeile hat außerdem nur 2 statt 4 Aktions-Icons |
| `?section=insights` | „Streifen über der **Maillist**" | sonst „Nachrichtenliste" (so auch unter Darstellung) |
| `?section=notifications` | `dann "Zum Home-Bildschirm"` | gerade Anführungszeichen; sonst durchgängig „…" |
| `?section=profile` | „PNG, JPEG, GIF oder WebP" steht zweimal (im Picker und darunter mit „, bis 4 MB") | Dopplung |
| `?section=app-passwords` | Platzhalter „Wofür ist es? z. B. iPhone — **Sterna**" | „Sterna" wirkt wie ein vergessener Testwert |
| Compose | Feldlabel „**Betr**" | „Von" und „An" sind ausgeschrieben; Platzhalter daneben heißt „Betreff" |
| `/mail/trash` (Papierkorb) | Absender „**ich**" bei eigener Nachricht | in `/mail/sent` heißt dieselbe Person „Sven" |
| Sidebar (aria) | „Neue Nachrichten in Gesendet 0", „Neue Nachrichten in Papierkorb 195" | „neue Nachrichten" passt nicht zu Gesendet/Entwürfe/Papierkorb |

### Leerzustände sind vier verschiedene Formulierungen

```
/mail/starred          Keine markierten Nachrichten.
/mail/drafts           Keine Entwürfe.
/mail/archive          Keine Konversationen mit diesem Label.   ← „Archiv" ist für Nutzer kein Label
/mail/label/Templates  Keine Konversationen mit diesem Label.
/mail/account/1        In diesem Konto ist noch nichts angekommen.
?section=filters       Noch keine Filter
?section=sharing       Noch keine geteilten Links.
```

Auch die Zähler-Zeile über den Listen ist uneinheitlich: „7 Labels", „4 Kalender", „0 Filter" — aber
„Keine geteilten Links" statt „0 geteilte Links".

---

## Niedrig — Konsistenz und Technik

### Einstellungen öffnen die falsche Sektion
`/settings` ohne Parameter landet auf **E-Mail-Konten**, obwohl **Profil** der erste Eintrag der
Seitennavigation ist.

### „Primär" bedeutet zwei verschiedene Dinge
Unter **E-Mail-Konten** trägt nur `sven@joder.dev` das Badge „Primär" (= Standard-Absenderkonto).
Unter **Aliase** tragen **alle drei** Konten das Badge „Primär" (= Hauptadresse des jeweiligen Kontos).
Gleiches Wort, gleiches Styling, andere Bedeutung.

### Zwei Dropdown-Stile nebeneinander
Zeitzone, Uhrzeitformat und Layout nutzen custom-gestylte Selects. Unter **Aliase** stehen daneben
native Browser-`<select>` („Nie senden", „Konto-Standard (Nie senden)") in Systemoptik. In derselben
Zeile stehen dort zudem zwei Selects für dieselbe Adresse ohne erklärendes Label — welcher gewinnt,
ist nicht ersichtlich.

### ~90 JS-Dateien pro Seitenaufruf
Der Posteingang lädt **99 Ressourcen**, darunter praktisch alle Stimulus-Controller der App:

```
controllers/admin/*        (4)   ← auf der Mail-Seite nicht gebraucht
controllers/calendar/*     (2)
controllers/onboarding/*   (2)
controllers/rules/*        (3)
controllers/settings/*     (8)
controllers/compose/*      (9)
controllers/insight/*      (3)
…
```

Über HTTP/2 im LAN unkritisch (load 1,3 s), über eine langsame Mobilverbindung aber spürbar.
Lazy-Import je Route wäre der offensichtliche Hebel.

### Schriftgröße 9 px
Die Sidebar-Abschnittsüberschriften (`LABELS`, `KONTEN`) laufen auf `font-size: 9px` bei `font-weight: 900`.
Unter 10–11 px wird Versalschrift auch bei gutem Kontrast schwer lesbar.

### Kalender-Wochenansicht startet bei 03:00
`/calendar` scrollt initial so, dass 00:00–02:00 abgeschnitten über der Ganztägig-Zeile hängen.
Üblich ist ein Start am Arbeitstagbeginn (07:00–08:00) oder an der aktuellen Uhrzeit.

### Papierkorb-Inhalte bleiben „ungelesen"
Auf der ersten Papierkorb-Seite (50 von 195) sind bis auf zwei Einträge alle als ungelesen markiert.
Löschen setzt den Gelesen-Status also nicht — vertretbar, sorgt aber dafür, dass der Papierkorb-Zähler
dauerhaft dreistellig bleibt.

---

## Zu prüfen (nicht sicher reproduziert)

- **Tooltip überlagert das Benutzermenü.** Beim Öffnen des Avatar-Menüs blieb der Tooltip
  „info@cpy-pst.de" sichtbar und legte sich über die obere rechte Ecke des Menüs. Tooltips sollten
  beim Öffnen eines Popovers verschwinden. Nur einmal beobachtet.
- **Verzögerter Repaint beim Ordnerwechsel.** Nach einem Wechsel z. B. Spam → Papierkorb zeigte die
  Ansicht mehrere Sekunden noch den vorherigen Ordner. Kann auch ein Artefakt der Screenshot-Erfassung
  sein — beim gezielten Nachmessen war der Wechsel schnell.

---

## Nicht geprüft

- **Mobile Breakpoints.** Ein Verkleinern des Fensters ließ sich in dieser Sitzung nicht auf den
  Viewport durchreichen. Die Mobil-Sidebar existiert im DOM (`account-folders-*-drawer`), das Verhalten
  wurde aber nicht verifiziert.
- **Dunkle Themes.** Ein Theme-Wechsel hätte eine Einstellung des Kontos verändert — bewusst ausgelassen.
- **Schreibende Aktionen.** Senden, Löschen, Verschieben, Filter anlegen, Konten ändern wurden nicht
  ausgeführt.

---

## Was sauber ist

- **Keine externen Hosts.** Alle 99 Requests gehen an `mail.vpn.cpy-pst.de`; kein CDN, kein Tracker,
  keine Google Fonts. Datenschutzrechtlich unauffällig.
- **Keine fehlgeschlagenen Requests.** Alle Assets, Frames und API-Aufrufe antworten mit 200.
- **Keine Konsolenfehler** über den gesamten Durchlauf.
- **Suche funktioniert.** `/mail/search?q=…` liefert korrekt gefilterte Treffer („3 Treffer" für „medios"),
  Vorschlagsliste inklusive.
- **Ungelesen-Zähler ist korrekt.** Die Abweichung zwischen Badge (8) und sichtbar ungelesenen Zeilen (4)
  löst sich auf: der Zähler summiert die Tabs „Allgemein" **und** „Benachrichtigungen", die Liste zeigt
  nur einen davon. Sichtbar wird das erst über die Tab-Badges („1 neu" / „2 neu").
- **Barrierefreiheits-Basics** teilweise sehr ordentlich: `aria-label` auf allen Icon-Buttons, `role=combobox`
  mit `aria-controls` auf der Suche, `alt` auf allen Bildern, Kontraste über AA.
- **2FA ist aktiv**, Wiederherstellungscodes vorhanden, gemerkte Geräte einzeln widerrufbar.
