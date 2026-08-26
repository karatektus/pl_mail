<!-- translated-from: CLIENT_DEVELOPMENT.md sha1:b64a75f8a6792e5edb963f9a282f7d3c44d7dad4 -->
# Einen Client für plMail bauen

Alles, was eine Entwicklerin (oder ein Agent) braucht, um einen *neuen* plMail-Client zu schreiben
— eine native iOS- oder Android-App, eine Desktop-App, ein CLI, ein weiteres Web-Frontend —, ohne
vorher die gesamte Symfony-Codebasis zu lesen.

Es geht um drei Dinge, in dieser Reihenfolge:

1. **Was plMail ist**, und die Entwurfsphilosophie, die ein Client übernehmen sollte.
2. **Wie es aussehen und sich anfühlen soll** — das visuelle System, die Layoutregeln, die
   Bewegung, die Texte.
3. **Wie es sich verhält, und über welche API** — JMAP, Authentifizierung, Push, Blobs, Suche.

Zum Installieren und Betreiben des Servers siehe [README.md](../README.md). Zur Entwicklung am
*Server* siehe [CONTRIBUTING.md](../CONTRIBUTING.md). Dieses Dokument setzt voraus, dass der
Server bereits irgendwo läuft.

---

## 0. Lies das zuerst: der Server wird aktiv weiterentwickelt

**plMail wird aktiv weiterentwickelt, und die Serverseite steht für die Bedürfnisse deines
Clients vollständig zur Disposition.** Dieses Dokument beschreibt, was es *heute* gibt. Es ist
eine Momentaufnahme, kein festgeschriebener Vertrag, und einiges, was hier als nicht vorhanden
aufgeführt ist, fehlt nur deshalb, weil es bisher niemand gebraucht hat.

Wenn du also gegen eine Wand läufst — eine fehlende JMAP-Methode, ein Endpunkt, der ein von dir
benötigtes Feld nicht herausgibt, ein Konzept, das in der Datenbank lebt und nicht in der API,
ein für Mobilgeräte falsch gesetztes Limit:

> **Frag nach. Bau nichts drumherum.**
>
> Den Endpunkt, die Methode, das Feld oder die Herstellererweiterung zu ergänzen ist ein völlig
> normaler Ausgang und meistens der *richtige*. Eine clientseitige Umgehung, die fehlendes
> Serververhalten nachbaut, ist fast immer die falsche Antwort: Sie dupliziert Logik, die an eine
> Stelle gehört, sie läuft von der Web-Oberfläche weg, und sie wird still und leise tragend.

Konkret: **halt inne und frag nach, bevor du**

- Serverlogik clientseitig neu implementierst, weil die API sie nicht herausgibt;
- die HTML-/Turbo-Stream-Routen scrapest oder fernsteuerst, weil JMAP etwas nicht hat;
- aggressiv pollst, um eine fehlende Methode zur Änderungsverfolgung auszugleichen;
- lokalen Zustand erfindest (eigene Keywords, Schatten-Flags, nur lokale Label), der nicht
  zurückwandern kann;
- etwas so denormalisierst oder cachest, dass es kaputtginge, wenn der Server es später
  ordentlich täte.

Was dieses Dokument als **nicht implementiert** kennzeichnet — `Email/queryChanges`,
Anchor-Paging, JWT-Ausgabe, eine kontoübergreifende
vereinigte Abfrage — sind allesamt *Kandidaten für den Bau*, keine dauerhaften Einschränkungen.
Bring den Bedarf beim Maintainer zur Sprache und entscheidet gemeinsam, ob er in den Server oder
in den Client gehört.

**Der Kalender war genau dafür das Beispiel, und er ist jetzt das Beispiel dafür, dass es
funktioniert.** In diesem Abschnitt stand früher, es gebe keine JMAP-Kalender-API und ein `using`
mit einem Kalender-URN werde rundheraus abgelehnt. Es gibt sie: `Calendar/get`,
`CalendarEvent/get`, `CalendarEvent/query` und `CalendarEvent/set`, unter
`urn:plmail:params:jmap:calendars`, ausgewiesen in `Capability::SUPPORTED` und aus genau einem
Konto bedient. Siehe [JMAP](internals/jmap.md) zu den ID-Räumen und den zwei Dingen, die
Menschen überraschen — eine `CalendarEvent`-ID ist die *Serie* und nicht eine datierte
Termininstanz, und `CalendarEvent/query` verlangt ein Zeitfenster.

Wenn du etwas baust, das Termine will, **sag es** — die Speicherung ist genau deshalb schon
JSCalendar, damit die API JSCalendar sein kann, und der Zuschnitt der Methoden (`Calendar/get`,
`CalendarEvent/query`, `/changes` gegen den bestehenden `StateManager`) steht fest. Zwei Dinge
solltest du wissen, bevor du fragst:

- JMAP for Calendars ist noch ein **Entwurf** der IETF; wenn das ausgeliefert wird, wird deshalb
  ein Hersteller-URN ausgewiesen (`urn:plmail:params:jmap:calendars`), dem Präzedenzfall
  `urn:plmail:params:jmap:push` folgend. Verdrahte den URN des Entwurfs nicht fest.
- Lies `calendar_event.jscalendar` **nicht** über irgendeinen Seitenkanal, und baue Serienregeln
  nicht clientseitig nach. Termininstanzen werden serverseitig bis zu einem begrenzten Horizont
  materialisiert, und das aus guten Gründen (ein unbegrenztes `FREQ=DAILY` hat keine letzte
  Instanz); ein Client, der Regeln selbst expandiert, wird an Zeitumstellungen und bei
  überschriebenen Instanzen von der Web-Oberfläche abweichen.

**Und die zweite Hälfte dieses Versprechens gibt es jetzt.** „Expandiere Serienregeln nicht
selbst" war eine Anweisung ohne günstigen Weg, ihr zu folgen: Eine eingeklappte
`CalendarEvent/query` benennt eine Serie, ohne zu sagen, an welchen Tagen sie liegt — einen Monat
zu zeichnen hieß also eine Abfrage pro Tag. Schick `expandRecurrences: true`, und dieselbe
Abfrage antwortet mit einem Eintrag je *Termininstanz*, nach Beginn sortiert, wobei
`position`/`limit`/`total` Instanzen zählen — eine Abfrage für den Monat. Jede Instanz-ID lautet
`<eventId>_<recurrenceId>`, z. B. `42_20260304T090000Z`; **behandle sie als opak**, gib sie direkt
an `CalendarEvent/get` (die übliche `#ids`-Paarung funktioniert) und lies `start` und
`recurrenceId` vom Objekt statt aus der ID. Einmalige Termine behalten ihre schlichte Serien-ID,
und ohne das Argument oder mit `false` hat sich an der Antwort nichts geändert.
`CalendarEvent/set` nimmt keine Instanz-ID an und sagt das auch; `seriesId` am Objekt ist die ID,
über die du schreibst. Die vollständige Form, samt dem, was eine expandierte Abfrage verweigert
und warum, steht in [JMAP](internals/jmap.md).

Daraus folgt: **Wenn du hier etwas Überraschendes liest, gleiche es mit dem Code ab, bevor du
darum herum entwirfst.** `src/Jmap/` ist die maßgebliche Quelle, und sie bewegt sich.

---

## 1. Das Produkt

### Was plMail ist

plMail ist ein **selbstgehosteter Mail-Client**. Er läuft auf einer Maschine, die der Nutzerin
gehört — ein NAS, ein Heimserver, ein kleiner VPS —, verbindet sich mit den Postfächern, die sie
ohnehin hat (IMAP, Gmail, Outlook/Microsoft 365), und synchronisiert jede Nachricht in eine
lokale PostgreSQL-Datenbank.

Er ist nachdrücklich **kein Mailserver**. Er nimmt keine Mail aus der Außenwelt entgegen, hostet
keine Domain und betreibt kein MX. Er ist die *Client-Schicht*: eine Oberfläche, ein Suchfeld,
ein Satz Label über so viele Anbieter hinweg, wie die Nutzerin hat.

### Was das für deine App bedeutet

Drei Konsequenzen treiben fast jede clientseitige Entscheidung:

**Der Server ist die maßgebliche Quelle, und er ist schnell.** Die Mail liegt bereits in
Postgres, indiziert, zu Konversationen gebündelt und volltextdurchsuchbar. Deine App sollte keine
konkurrierende Sync-Engine gegen Gmail oder IMAP bauen — sie spricht mit plMail, und plMail
spricht mit den Anbietern. Ein Client, der am Server vorbeigreift, bricht das
Ein-Datenbank-Versprechen, auf dem das ganze Produkt steht.

**Der Server ist *der Server der Nutzerin*.** Er kann im heimischen LAN stehen, hinter Tailscale
oder an einer langsamen ADSL-Leitung. Er kann kurz unerreichbar sein, wenn das NAS neu startet.
Nimm an: schwankende, mitunter hohe Latenz, gelegentlich selbstsignierte Zertifikate oder solche
von einer privaten CA, kein CDN, kein globales Anycast und ein einzelner PHP-Worker-Pool, den ein
sich schlecht benehmender Client tatsächlich erschöpfen kann. Cache großzügig, polle selten,
verfalle sanft, und geh nie in eine Endlosschleife.

**Mehrere Konten sind der Normalfall, nicht der Randfall.** Eine Nutzerin mit einem
dienstlichen Gmail, einem privaten IMAP und einem Outlook-Konto ist die Zielgruppe. Der
vereinigte Posteingang ist die Standardansicht. Jeder Bildschirm deiner App sollte zuerst für
mehrere Konten entworfen sein und erst danach für eines.

### Entwurfsphilosophie

Die Server-Codebasis ist ungewöhnlich meinungsstark, und die Meinungen sind es wert, übernommen
zu werden, denn sie sind es, die das Produkt stimmig wirken lassen.

- **Gmail ist das Vokabular, nicht die Ästhetik.** Label statt Ordner. Konversationen statt
  Nachrichten. Ein einziges Suchfeld mit den Operatoren `from:`/`is:`/`has:`. Wer von Gmail
  kommt, soll keine neuen Substantive lernen müssen. Aber das *Aussehen* ist plMails eigenes —
  weicher, ruhiger, mehr einstellbar.
- **Alles ist themebar, und das Theme gehört der Nutzerin.** Farbe, Dichte, Eckenradius,
  Transluzenz, Hintergrund — alles von der Nutzerin gesetzt, alles serverseitig synchronisiert.
  Ein Client, der seine Palette fest verdrahtet, ist falsch. Siehe [§2](#2-aussehen-und-verhalten).
- **Label sind das Konzept für die Nutzerin; Postfächer sind Installationstechnik.**
  IMAP-Ordner existieren als Sync-Infrastruktur. Die Nutzerin sieht Label, und eine Nachricht kann
  mehrere tragen. Zeig keine Ordner.
- **Nichts wird je endgültig gelöscht.** „Löschen" heißt „in den Papierkorb verschieben". Der
  Server hat überhaupt keinen Pfad zum endgültigen Löschen — eine Zeile zu löschen verwürfe die
  lokale Kopie einer Mail, die der Anbieter weiterhin hält. Deine destruktive Oberfläche sollte
  „Papierkorb" sagen und rückgängig zu machen sein.
- **Kommentare erklären das *Warum*, und deine Oberfläche sollte es auch.** Der Servercode ist
  voll von „das sieht seltsam aus, weil X uns gebissen hat". Nimm diesen Geist mit in leere
  Zustände und Fehlermeldungen: Sag, was passiert ist und was die Nutzerin tun kann, nie bloß
  „Fehler".
- **Zuerst lokal, dann der Anbieter.** Aktionen wirken lokal und pflanzen sich dann nach außen zu
  Gmail/IMAP/Graph fort. Deine Oberfläche darf und soll optimistisch sein.

### Vorbilder im Repository

Bevor du einen Bildschirm erfindest, sieh dir an, wie die Web-Oberfläche es macht. Die
lehrreichsten Dateien:

| Was | Wo |
|---|---|
| Design-Tokens, Utilities, Theme-Blöcke | [assets/styles/app.css](../assets/styles/app.css) |
| Die App-Hülle (Viewport, PWA, Theme-Bootstrapping) | [templates/\_layout/app.html.twig](../templates/_layout/app.html.twig) |
| Der Aufbau einer Listenzeile | [templates/\_partials/\_thread\_row.html.twig](../templates/_partials/_thread_row.html.twig) |
| Verhalten Liste ⇄ Lesebereich auf Mobilgeräten | [assets/controllers/mail/mail\_pane\_controller.js](../assets/controllers/mail/mail_pane_controller.js) |
| Schublade / Icon-Leiste der Seitenleiste | [assets/controllers/ui/sidebar\_drawer\_controller.js](../assets/controllers/ui/sidebar_drawer_controller.js) |
| Screenshots vom echten Ding | [docs/screenshots/](screenshots/) |

---

## 2. Aussehen und Verhalten

### Das Erscheinungsmodell mit zwei Achsen

plMail trennt **Theme** (die Palette) von **Layout** (die Behandlung). Jedes Theme lässt sich mit
jedem Layout kombinieren. Über beiden liegen numerische Regler, die die Nutzerin einzeln
überschreiben kann.

**Theme** — [`App\Domain\Enum\Theme\Theme`](../src/Domain/Enum/Theme/Theme.php)

| Theme | Fläche | Schrift | Akzent | Dunkel? |
|---|---|---|---|---|
| `system` | folgt dem Betriebssystem | — | `#2563eb` | folgt dem Betriebssystem |
| `light` | `#ffffff` | `#27272a` | `#2563eb` | nein |
| `dark` | `#111827` | `#f4f4f5` | `#3b82f6` | ja |
| `nord` | `#2e3440` | `#eceff4` | `#88c0d0` | ja |
| `dusk` | `#1e1b2e` | `#ede9fe` | `#a78bfa` | ja |
| `solar` | `#fdf6e3` | `#586e75` | `#b58900` | nein |

**Layout** — [`App\Domain\Enum\Theme\Layout`](../src/Domain/Enum/Theme/Layout.php)

| Layout | Radius | Flächenunschärfe | Flächen-Alpha | Charakter |
|---|---|---|---|---|
| `flat` (Standard) | 0.75rem | 0 | 1.0 | Die Rahmenelemente sitzen direkt auf dem Hintergrund; eine deckende Inhaltskarte. |
| `boxed` | 1.0rem | 24px | 0.7 | Alles ist eine schwebende, transluzente Karte über dem Hintergrund. |

Ein Layout auszuwählen *setzt* die Regler unten vor; danach kann die Nutzerin jeden einzeln
überschreiben.

**Dichte** — [`App\Domain\Enum\Theme\Density`](../src/Domain/Enum/Theme/Density.php)

| Dichte | Zeilenabstand (block) | Abstand |
|---|---|---|
| `comfortable` (Standard) | 0.875rem | 0.75rem |
| `cosy` | 0.625rem | 0.5rem |
| `compact` | 0.375rem | 0.375rem |

**Regler der Nutzerin** — die maßgebliche Liste samt Begrenzungen steht in
[`Appearance`](../src/Entity/Embeddable/Appearance.php):

| Feld | Typ | Bereich | Bedeutung |
|---|---|---|---|
| `accent` | hex | `#rrggbb` | Akzentfarbe. Standard `#2563eb`. |
| `paneAlpha` | float | 0.15 – 1.0 | Deckkraft der Flächen des Gerüsts: Seitenleiste, Kopfleiste, Hauptbereich, Kalender. |
| `popoverAlpha` | float | 0.5 – 1.0 | Deckkraft der Flächen, die darüber schweben: Schreibfenster, Dialoge, Menüs, Hinweise. Die Untergrenze liegt absichtlich höher — wo sich beide überlagern, multiplizieren sich die Durchsichtigkeiten. Vorgabe 1.0. |
| `paneBlur` | int | 0 – 60 | Hintergrundunschärfe in px. |
| `radius` | float | 0.0 – 2.0 | Eckenradius in rem, *nur für Flächen*. |
| `scrimAlpha` | float | 0.0 – 0.7 | Schwarzer Schleier über einem eigenen Hintergrundbild. |
| `inkColor` / `inkMuted` / `inkFaint` | hex\|null | — | Überschreibungen der Textfarbe. |
| `mainTint` / `mainAlpha` | hex\|null / float\|null | — | Tönung und Deckkraft speziell der Hauptinhaltsfläche. |
| `backgroundKind` | enum | `theme` \| `preset` \| `solid` \| `custom` | Woher der App-Hintergrund kommt. |
| `backgroundPreset` / `backgroundSolid` / `backgroundFile` | — | — | Der gewählte Hintergrund. |
| `logoStyle` | enum, **nur lesbar** | eines aus `logoStyles` | Die Farbgebung, in der die „pl"-Marke erscheint. |

`Appearance::toArray()` ist das Exportformat (versioniert, `version: 1`), `applyArray()` der
Import. Die Web-Oberfläche lässt Nutzerinnen das als Datei exportieren und importieren.

> **Das ist über JMAP erreichbar.** `Appearance/get` und `Appearance/set` liefern das
> Singleton-Objekt (Id `"singleton"`, kein `accountId` — es hängt an der `User`-Entität), und die
> Appearance-Capability der Session veröffentlicht die Vokabulare und Wertebereiche: `themes`,
> `logoStyles`, `layouts`, `densities`, `backgroundKinds`, `backgroundPresets`, `unreadEmphases`,
> `fontFamilies`, `ranges.previewLines`, `ranges.fontScale`, `ranges.popoverAlpha`. Modelliere
> dieselbe Form mit zwei Achsen aus Theme × Layout mit denselben semantischen Tokens und lies die
> Werte des Servers hinein. Zwei Dinge solltest du vor dem ersten Schreiben wissen: Booleans werden streng geprüft,
> `"1"` und `"0"` werden also abgelehnt statt umgewandelt; und die drei Dichten pro Oberfläche
> brauchen ein ausdrückliches JSON-`null` für „folge der globalen Dichte" — das ist eine andere
> Anweisung als ein weggelassener Schlüssel. Durchgesetzt wird die Regel „verdrahte keine Palette
> fest".

> **`logoStyle` ist nur lesbar — und es ist die Marke, die die Nutzerin tatsächlich vor sich hat.**
> Der Wert stammt aus der festen Menge, die die Session als `logoStyles` veröffentlicht: `"berry"`
> (die Produktvorgabe), `"product-blue"`, `"petrol-copper"` und neunundzwanzig weitere. Ein Client,
> der sie auf eigene Grafiken abbildet, kann die Liste beim Discovery abholen und erkennt so, wann
> er einen Wert in der Hand hält, für den er nichts hat. Behandle einen unbekannten Wert als die
> Vorgabe und nicht als Fehler; die Menge wächst.
>
> Setzen lässt er sich nicht. Das ist weder ein Versehen noch eine Frage der Berechtigung: auf dem
> Server ist der Wert *abgeleitet* — aus dem Theme, daraus, ob die Nutzerin die Marke vom Theme
> gelöst hat, und erst dann aus einer gespeicherten Farbgebung. Jede der zweiunddreißig ist auch ein
> Theme-Name, und standardmäßig kleidet die Wahl eines Themes die Marke passend ein. Deshalb liest
> du hier die Marke so zurück, wie das Web sie in der Kopfleiste und im Favicon zeichnet, und nicht
> eine Datenbankspalte. `logoStyle` mit einem *anderen* Wert zu senden wird mit `invalidProperties`
> abgelehnt; mit genau dem eben gelesenen Wert wird es angenommen und ignoriert, damit
> get → ein Feld ändern → set genauso funktioniert wie bei jeder anderen Eigenschaft. **Um die
> Marke zu bewegen, setze `theme`** — die neue Farbgebung kommt in der `updated`-Map desselben
> Aufrufs zurück. Die Marke vom Theme zu lösen ist heute eine reine Web-Einstellung.

> **Der Radius gilt für Flächen, nicht für Bedienelemente.** Modale, das Verfassen-Fenster,
> Dropdowns, Menüs und Toasts nehmen `--app-radius`. Buttons, Eingabefelder, Chips und
> Listenzeilen behalten einen *festen* kleinen Radius — sie dürfen nicht auf 2rem-Ecken
> anwachsen. Diese Unterscheidung ist Absicht und wird leicht falsch gemacht.

### Semantische Farb-Tokens

Verweise nie auf rohe Palettenwerte. Bau deinen Client gegen denselben Satz semantischer Tokens,
den auch das CSS verwendet, damit ein Themewechsel alles auf einen Schlag neu auflöst. Die
kanonische Liste steht im `@theme inline`-Block von [app.css](../assets/styles/app.css):

| Token | Verwendung |
|---|---|
| `surface` | Hintergrund von Karten und Flächen. |
| `line` | Haarfeine Trenner (sehr geringes Alpha). |
| `raised` / `hover` | Dezente erhabene Füllungen und Hover-Zustände. |
| `ink` / `ink-soft` / `ink-muted` / `ink-faint` | Text, in vier abnehmenden Gewichtungen. |
| `accent` / `accent-strong` / `accent-soft` / `accent-ink` | Der Akzent und seine Varianten. |
| `sunken` | Vertiefte Mulden (Eingabehintergründe, Codeblöcke). |
| `field` / `field-border` | Formularelemente. |
| `danger` / `warning` / `success` / `info` | Status. Jedes hat eine `-soft`-Hintergrundvariante. |
| `inverse` / `inverse-ink` | Tooltips und invertierte Chips. |

Zusammengesetzte Flächen: `pane` (Karte mit Rand und Schatten), `pane-flat` (ohne Schatten),
`popover` (**vollständig deckend** — ein transluzentes Dropdown über einem Fotoraster ist
unlesbar), `main-pane` (die Inhaltskarte, beachtet `mainTint`/`mainAlpha`) und `app-bg` (der
Verlauf beziehungsweise das Bild im Hintergrund plus Schleier).

### Das Mail-Sheet — wo das Theme endet, und wie es endet

Gerenderte Mail-Inhalte übernehmen die Palette der App nicht. Mail kommt für einen weißen
Hintergrund verfasst an, ihr eine dunkle Fläche zu geben ergibt also schwarzen Text auf Schwarz.
Die `mail-sheet`-Utility der Web-Oberfläche deklariert die Palettenkanäle lokal neu, damit
*alles darin* — einschließlich deiner eigenen Rahmenelemente, falls du welche verschachtelst — zu
hellen Werten auflöst.

**Im Web heißt das ein dauerhaft helles Sheet.** Auf einem Telefon geht das nicht: Eine Mail-App,
deren Lesebereich der eine Bildschirm ist, der nachts weiß bleibt, ist nicht akzeptabel, und
Nutzerinnen werden das auch sagen.

Ein nativer Client sollte also dunkel rendern — aber **nicht, indem er alles invertiert**, denn
das ist der Ansatz, der verlässlich kaputt aussieht. Fotos kommen als Negative heraus, Logos in
den falschen Markenfarben, und eine Nachricht, die bereits eigene dunkle Stile mitbringt,
invertiert doppelt zu etwas, das schlimmer ist als beide Extreme.

Wähl je Nachricht eine Strategie, ausgehend davon, was ihr HTML über sich selbst aussagt:

| Die Nachricht | Was zu tun ist |
|---|---|
| Bringt keine eigenen Farben mit — eine getippte Antwort, die meiste private Mail | **Style sie neu** in deiner dunklen Palette. Nichts wird invertiert, also kann nichts wie ein Negativ aussehen. Das ist das beste erreichbare Ergebnis. |
| Hat eine eigene Palette — Newsletter, alles Gestaltete | **Invertiere mit `hue-rotate(180deg)`**, und invertiere `img`, `picture`, `video`, `svg` und Elemente mit Hintergrundbild anschließend *zurück*. Diese zweite Regel ist die, die alle vergessen, und sie auszulassen ist es, was der Invertierung ihren Ruf eingebracht hat. |
| Deklariert bereits `prefers-color-scheme` | Sag ihr, das Schema sei dunkel, und **lass sie in Ruhe.** Die Absenderin hat die Arbeit gemacht. |
| Alles davon, im hellen Erscheinungsbild | Rendere genau so, wie es gesendet wurde. |

Daraus folgt zweierlei. **Biete überall dort einen Weg zurück zum Original an, wo du eine
Nachricht umgeformt hast** — die Invertierung geht bei mancher Mail daneben, und wenn einem
gesagt wird, eine verhunzte Nachricht sei in Ordnung, ist das schlimmer, als zu sehen, dass sie
verhunzt ist. Und beachte, dass `invert`+`hue-rotate` eine Matrixnäherung ist und keine echte
HSL-Rotation; hin- und zurückgedrehte Farben kommen also leicht entsättigt zurück, und das ist
der Preis der Technik.

Was sich nicht geändert hat: **Reich niemals das *Theme* der Nutzerin in den Nachrichtenrenderer
weiter.** Die Nachricht bekommt eine der obigen Behandlungen, nicht die Akzentfarbe, nicht das
Flächen-Alpha und nicht das Hintergrundbild.

### Layout und Navigation

**Desktop / Tablet (≥768px)** — drei Bereiche:

```
┌──────────────────────────────────────────────┐
│ topbar: search, sync, account, settings      │
├────────────┬─────────────────────────────────┤
│ sidebar    │ list        │ reading pane      │
│ Compose ▸  │ (threads)   │ (thread)          │
│ Inbox   12 │             │                   │
│ Starred    │             │                   │
│ Sent       │             │                   │
│ Labels…    │             │                   │
└────────────┴─────────────────────────────────┘
```

Die Seitenleiste klappt zu einer **56px breiten Icon-Leiste** zusammen (Zustand bleibt erhalten;
im Web wird er vor dem ersten Zeichnen angewandt, damit die breite Seitenleiste nie aufblitzt).
Aktive und überfahrene Navigationszeilen verwenden eine Pille im Gmail-Stil, die links über den
Rand hinausläuft und rechts mit vollem Radius abschließt.

**Mobil (<768px)** — die Seitenleiste wird zu einer **einfahrenden Schublade über einem
Hintergrundschleier**, und Liste und Lesebereich werden zu **zwei gestapelten Flächen**: Ein Tipp
auf eine Zeile ersetzt die Liste durch die Konversation, und Zurück führt zur Liste. Im Web wird
das mit `history.pushState` gemacht, sodass der Zurück-Knopf von Gerät oder Browser natürlich
funktioniert — ein nativer Client sollte das auf ein normales Push auf den Navigationsstapel
abbilden.

Das Verfassen ist mobil **bildschirmfüllend**; auf dem Desktop ist es ein **angedocktes Fenster**
unten rechts (`fixed bottom-4 right-6`), und mehrere können gleichzeitig offen sein.

### Die Listenzeile

Aus [`_thread_row.html.twig`](../templates/_partials/_thread_row.html.twig); eine Zeile zeigt:

- **Beteiligte** — alle, die in der Konversation geschrieben haben, älteste zuerst. Nicht die
  neueste Absenderin; das ließ jede Konversation, die man beantwortet hatte, so aussehen, als
  käme sie von einem selbst.
- **Betreff**, mit Rückfall auf ein übersetztes „(kein Betreff)".
- **Ausschnitt** — die ersten rund 100 Zeichen des Klartextkörpers der neuesten Nachricht, ohne
  Tags.
- **Anzahl der Nachrichten**, wenn > 1.
- **Datum** — Zeit der letzten Nachricht.
- **Zustandsmerkmale**: ungelesen (Schriftschnitt/Indikator), markiert, Büroklammer für Anhänge.
- **Hover-Aktionen** (Desktop): archivieren, in den Papierkorb, zurückstellen, gelesen/ungelesen
  markieren, Label.

Ungelesen und markiert stehen als `data-unread` / `data-starred` an der Zeile, sodass das Styling
am Zustand hängt statt an duplizierten Klassen. Mach das nach: eine Zeilenkomponente,
zustandsgesteuert.

**Entwurfsregel (subtil, mach sie richtig):** Eine Zeile öffnet die *Verfassen*-Oberfläche statt
des Lesebereichs nur dann, wenn die Zeile der Entwurf **ist** — also eine Konversation, die eine
einzelne Entwurfsnachricht enthält, oder eine nackte Entwurfszeile. Eine echte Konversation, die
eine ungesendete Antwort trägt, öffnet weiterhin die Konversation, und dieser Entwurf wird von
innerhalb des Lesebereichs bearbeitet. In der Entwurfsliste wird das überstimmt: Dort öffnet jede
Zeile ihren Entwurf.

### Bewegung, Geste und Berührung

- **Bewegung ist funktional, nicht dekorativ.** Schublade fährt ein, Fläche wechselt, Toast
  kommt und geht. Keine Federphysik, kein Parallaxe-Effekt, keine Heldenanimationen auf
  Mail-Zeilen.
- **Beachte reduzierte Transparenz.** Das CSS erzwingt `paneAlpha: 1`, `popoverAlpha: 1`,
  `paneBlur: 0`, `scrimAlpha: 0` unter `prefers-reduced-transparency: reduce`. Mach dasselbe, und
  beachte auch reduzierte Bewegung.
- **Sichere Bereiche.** Die Web-App läuft mit `viewport-fit=cover` und polstert per
  `env(safe-area-inset-*)`. Native Clients bekommen das geschenkt, dürfen aber weder das
  Verfassen-Dock noch die Werkzeugleiste unter dem Home-Indikator sitzen lassen.
- **Die Web-App hat keine Wischgesten auf Zeilen** — Aktionen sind Buttons. Ein nativer Client
  *sollte* Wischen zum Archivieren und in den Papierkorb ergänzen, denn das ist das
  Plattform-Idiom; achte nur darauf, dass alles, was ein Wisch tut, auch über ein ausdrückliches
  Bedienelement erreichbar und rückgängig zu machen ist.
- **Nichts scrollt die Seite seitwärts.** Flächen scrollen ihren eigenen Überlauf. Werkzeugleisten,
  die nicht passen, scrollen horizontal mit ausgeblendeter Bildlaufleiste.

### Texte und Tonfall

Die tatsächlichen Zeichenketten stehen in [translations/](../translations/). Das Register ist
durchgehend ruhig, konkret und eher kleingeschrieben — schlichte Sätze, keine Ausrufezeichen, kein
„Hoppla!". Fehler benennen die Ursache. Die Oberfläche wird auf **Englisch und Deutsch**
ausgeliefert; wenn du Zeichenketten ergänzt, ergänze beide, und entwirf dafür, dass Deutsch etwa
30 % länger ist.

### Grundlinie für Barrierefreiheit

- Texteingaben werden auf kleinen Bildschirmen mit ≥16px gerendert (darunter zoomt iOS beim
  Fokussieren ungeachtet der Viewport-Einstellungen). Halte dich daran.
- Alles Anklickbare muss anklickbar aussehen und sich so verhalten, und alles, was mit dem Zeiger
  erreichbar ist, muss auch per Tastatur und Screenreader erreichbar sein.
- Bedienelemente, die nur aus einem Icon bestehen, tragen in der Web-Oberfläche `aria-label`s.
  Setz native Barrierefreiheitsbeschriftungen.
- Der Kontrast muss in allen sechs Themes halten, und genau dafür gibt es die semantischen Tokens.

---

## 3. Die API

### Welche API du verwenden sollst

plMail stellt unter `/jmap` **JMAP** bereit (RFC 8620 / RFC 8621). **Das ist die API für
Drittanbieter- und native Clients.** Sie ist die einzige stabile, dokumentierte, versionierte
Oberfläche.

Die eigenen Routen der Web-Oberfläche (`/mail/*`, `/compose/*`, `/settings/*`) liefern **HTML und
Turbo Streams**, kein JSON. Sie sind intern, unversioniert, CSRF-geschützt und werden sich ohne
Ankündigung ändern. Bau nicht dagegen.

Bekannte JMAP-Clients, die bereits gegen diesen Server funktionieren: **ltt.rs** (Bearer) und
**Sterna** (Basic). Gegen einen von ihnen zu testen ist der schnellste Weg, deine eigene
Implementierung auf Plausibilität zu prüfen.

### Authentifizierung

Die JMAP-Firewall ist **zustandslos** und akzeptiert zwei Arten von Anmeldegeheimnissen.

**App-Passwörter — heute verfügbar, und das, was du verwenden solltest.**

Die Nutzerin legt eines unter **Einstellungen → App-Passwörter** an. Das Geheimnis wird genau
einmal angezeigt und sieht so aus:

```
plmail_<64 hex chars>
```

Serverseitig wird nur ein SHA-256-Digest gespeichert, dazu ein 6 Zeichen langer Hinweis, damit
die Liste zeigen kann, welches welches ist. Token sind **nutzerbezogen, nicht kontobezogen**: Ein
Anmeldegeheimnis zählt jedes verbundene Mail-Konto auf. Sie lassen sich einzeln widerrufen.
`lastUsedAt` wird höchstens alle 5 Minuten aktualisiert, es ist also ein grobes Signal für
„kürzlich aktiv" und kein Prüfprotokoll.

Schick es auf eine der beiden Arten:

```http
Authorization: Bearer plmail_abc123…
```

```http
Authorization: Basic base64(user@example.com:plmail_abc123…)
```

Wenn du Basic schickst, **wird** der Benutzername gegen die Eigentümerin des Tokens geprüft —
eine falsche Adresse wird mit einer klaren Meldung abgelehnt, statt dass stillschweigend als
diejenige agiert wird, der das Token gehört.

**JWT — verdrahtet, aber noch nicht ausstellbar.** Die Firewall akzeptiert JWTs (für eine
künftige eigene App), und der Server erzeugt beim ersten Start ein Schlüsselpaar, aber **es gibt
derzeit keinen Endpunkt, der eines ausstellt**. Ein `Bearer`-Token, das mit `plmail_` beginnt,
wird zum App-Passwort-Authenticator geleitet; alles andere fällt an JWT durch.

Heute baust du gegen App-Passwörter. Aber wenn du die *eigene* App schreibst, ist ein
ordentlicher Anmelde-Endpunkt, der kurzlebige JWTs ausstellt, genau die Art Sache, um die man
bitten sollte — die meiste Verkabelung ist schon da. Täusch keine Session-Schicht über
App-Passwörtern vor, um darum herumzukommen; frag.

**Form eines Fehlschlags** — `401` mit `application/problem+json` und einer Aufforderung
`WWW-Authenticate: Basic realm="plMail JMAP"`:

```json
{ "type": "urn:ietf:params:jmap:error:unauthorized", "status": 401, "detail": "Invalid or revoked app password." }
```

### Die Session finden

```http
GET /.well-known/jmap        (or GET /jmap/session)
Authorization: Bearer plmail_…
```

Liefert das Session-Objekt. Alles Weitere wird daraus entdeckt — **verdrahte die anderen Pfade
niemals fest**, und lies `apiUrl` und Konsorten immer wieder von hier:

```json
{
  "capabilities": {
    "urn:ietf:params:jmap:core": {
      "maxSizeUpload": 50000000,
      "maxConcurrentUpload": 4,
      "maxSizeRequestObject": 10000000,
      "maxConcurrentRequests": 4,
      "maxCallsInRequest": 32,
      "maxObjectsInGet": 500,
      "maxObjectsInSet": 500,
      "collationAlgorithms": ["i;ascii-numeric", "i;ascii-casemap", "i;unicode-casemap"]
    },
    "urn:ietf:params:jmap:mail": {},
    "urn:ietf:params:jmap:submission": {},
    "urn:plmail:params:jmap:push": {
      "vapidPublicKey": "BN…",
      "fcm": true,
      "fcmConfig": {
        "projectId": "plmail-abc123",
        "applicationId": "1:1234567890:android:0123456789abcdef",
        "apiKey": "AIza…",
        "senderId": "1234567890"
      }
    }
  },
  "accounts": {
    "7": {
      "name": "me@example.com",
      "isPersonal": true,
      "isReadOnly": false,
      "accountCapabilities": {
        "urn:ietf:params:jmap:mail": {
          "maxMailboxesPerEmail": null,
          "maxMailboxDepth": null,
          "maxSizeMailboxName": 255,
          "maxSizeAttachmentsPerEmail": 50000000,
          "emailQuerySortOptions": ["receivedAt", "from", "to", "subject", "size"],
          "mayCreateTopLevelMailbox": true
        },
        "urn:ietf:params:jmap:submission": {
          "maxDelayedSend": 2592000,
          "submissionExtensions": { "FUTURERELEASE": ["HOLDFOR", "HOLDUNTIL"] }
        }
      }
    }
  },
  "primaryAccounts": { "urn:ietf:params:jmap:mail": "7" },
  "username": "me@example.com",
  "apiUrl": "https://mail.example.com/jmap/api",
  "downloadUrl": "https://mail.example.com/jmap/download/{accountId}/{blobId}/{name}?accept={type}",
  "uploadUrl":   "https://mail.example.com/jmap/upload/{accountId}",
  "eventSourceUrl": "https://mail.example.com/jmap/eventsource?types={types}&closeafter={closeafter}&ping={ping}",
  "state": "…"
}
```

**Entscheidendes Modellierungsdetail:** *Je verbundenem Mail-Konto wird ein JMAP-Konto
offengelegt.* Eine Nutzerin mit drei Postfächern sieht unter einer Anmeldung drei JMAP-Konten.
**Der vereinigte Posteingang ist Sache des Clients** — du führst eine `Email/query` je Konto aus
und führst die Ergebnisse selbst zusammen, sortiert nach `receivedAt`. Es gibt keine
serverseitige kontoübergreifende Abfrage.

`urn:plmail:params:jmap:push` ist eine **Herstellererweiterung**, die beschreibt, über welche
Push-Transporte diese Instanz tatsächlich zustellen kann; RFC 8620 definiert für nichts davon
einen standardisierten Platz.

| Schlüssel | Bedeutung |
|---|---|
| `vapidPublicKey` | Dein `applicationServerKey` für eine Web-Push-Subscription. **Leer** heißt, Web Push ist nicht konfiguriert — biete es dann nicht an. |
| `fcm` | Ob Firebase konfiguriert *und* eingeschaltet ist. Immer vorhanden, `true` oder `false`. |
| `fcmConfig` | Die Eingaben für Androids `FirebaseOptions.Builder`. **Fehlt vollständig, wenn `fcm` false ist** — nicht null. |

`fcm` ist immer vorhanden, damit du „dieser Server kann kein FCM" von „dieser Server ist älter als
FCM" unterscheiden kannst; die richtige Reaktion ist jeweils die entgegengesetzte. Bei `fcmConfig`
gilt aus dem entgegengesetzten Grund die entgegengesetzte Regel: Ein Null-Objekt verleitet dazu,
`.projectId` davon zu lesen und null zu bekommen, ein fehlender Schlüssel lässt sich nicht
dereferenzieren. Prüfe zuerst `fcm`.

Beachte: `capabilities` weist den Push-URN aus, aber die *unterstützte* `using`-Liste besteht nur
aus Core, Mail und Submission. Setz den Push-URN nicht in `using`.

#### Push auf Android

Web Push setzt einen **Push-Dienst** voraus: etwas, dem die Endpunkt-URL gehört, das die
Verbindung zum Gerät hält und den verschlüsselten POST des Servers entgegennimmt. Browser bringen
so etwas mit. Eine native Android-App nicht, und Androids eigener Dienst ist FCM, der sein
eigenes Protokoll spricht — der `WebPushSender` kann nicht dorthin posten.

Ein Android-Client hat also drei Möglichkeiten:

1. **UnifiedPush.** Die Nutzerin installiert eine *Distributor*-App; die liefert einen Endpunkt
   nach RFC 8030 und entschlüsselt die `aes128gcm`-Nutzlast nach RFC 8291, die dieser Server
   ohnehin schon sendet. Überhaupt keine Serverkonfiguration.
2. **Firebase.** Nichts, was die Nutzerin installieren müsste, und das, was die meisten
   Android-Nutzerinnen erwarten. Unterstützt, seit der Server einen `FcmSender` hat; die
   Administration muss vorher die Zugangsdaten eines Firebase-Projekts einfügen — Google erfährt
   dann, dass eine Nachricht angekommen ist und wann.
3. **Ein eingebetteter Distributor**, bei dem die App den Socket selbst hält. Kostet je App
   einen Vordergrunddienst und eine dauerhafte Benachrichtigung.

**Firebase gegen eine selbstgehostete Instanz initialisieren.** Die übliche Android-Anordnung —
eine `google-services.json`, die zur Bauzeit verarbeitet wird — kann hier nicht funktionieren: Ein
APK bedient jede Installation, und jede Installation hat ihr eigenes Firebase-Projekt. Also
veröffentlicht der Server stattdessen die vier öffentlichen Werte als `fcmConfig` oben, und du
baust `FirebaseOptions` zur Laufzeit daraus, nachdem du die Session geholt hast:

```kotlin
val options = FirebaseOptions.Builder()
    .setProjectId(config.projectId)
    .setApplicationId(config.applicationId)
    .setApiKey(config.apiKey)
    .setGcmSenderId(config.senderId)
    .build()
```

Alle vier stecken im APK jeder Firebase-App und sind ihrer Natur nach öffentlich; der
Dienstkonto-Schlüssel, der tatsächlich *senden* kann, verlässt den Server nie. Hat die Instanz
mehrere Android-Pakete registriert, wird `de.plmail.google` veröffentlicht, sofern vorhanden,
sonst der erste registrierte Client.

Für (1) kann dieses Repository auch gleich den Push-Dienst liefern, damit Selbsthostende keinen
suchen müssen:

```bash
docker compose --profile push up -d ntfy
```

Das ist die ganze Einrichtung. Es ist standardmäßig aus, fügt keinen plMail-Code hinzu und
braucht keinerlei eigene Konfiguration: Die Endpunkt-URL wird aus dem beim ersten Start gesetzten
`SERVER_NAME` abgeleitet, denn der Host, den die Telefone ohnehin schon erreichen, ist das
Einzige, was sie sein muss. Überschreib `NTFY_BASE_URL`, wenn Push woanders leben soll.

Die abgeleitete URL ist `http://$SERVER_NAME:8090`. Zwei Konsequenzen, die man kennen sollte. Sie
lässt sich nicht so hinter dem eigenen Caddy der App an einem Pfad einfalten, wie der
Mercure-Hub unter `/.well-known/mercure` liegt — ntfy verweigert beim Start eine `base-url` mit
Pfad —, deshalb bekommt sie einen eigenen Port. Und die Endpunkt-URL ist selbst das Geheimnis;
zum offenen Internet hin willst du also TLS davor und `NTFY_BASE_URL` auf die https-Adresse
gesetzt, während im LAN oder über Tailscale der Standard so genügt, wie er ist.

Sie ist in jeden ausgegebenen Endpunkt eingebacken, sie später zu ändern zwingt also jedes Gerät
zur Neuregistrierung.

Nutzlasten werden zum geräteeigenen Schlüssel verschlüsselt, bevor sie dort ankommen; der
Push-Dienst kann Mail also nicht lesen, welchen du auch nimmst. Er erfährt aber, *wann* Mail
ankommt, und das ist das Argument dafür, einen eigenen zu betreiben statt eines öffentlichen.

### Der API-Endpunkt

```http
POST /jmap/api
Content-Type: application/json
Authorization: Bearer plmail_…
```

```json
{
  "using": ["urn:ietf:params:jmap:core", "urn:ietf:params:jmap:mail"],
  "methodCalls": [
    ["Email/query", { "accountId": "7", "filter": { "inMailbox": "42" }, "sort": [{ "property": "receivedAt", "isAscending": false }], "limit": 50 }, "q0"],
    ["Email/get",   { "accountId": "7", "#ids": { "resultOf": "q0", "name": "Email/query", "path": "/ids" }, "properties": ["id","threadId","subject","from","receivedAt","preview","keywords","hasAttachment","mailboxIds"] }, "g0"]
  ]
}
```

Rückverweise (`#ids`) werden unterstützt und sind der vorgesehene Weg, Query und Get in einem
Roundtrip zu paaren — über eine langsame Heimleitung wichtig.

Zwei Argumentdetails, deren Nachlesen billiger ist als ihre Fehlersuche:

- **`accountId` muss eine JSON-Zeichenkette sein.** Eine ganze Zahl wird mit `invalidArguments`
  abgelehnt, nicht umgewandelt.
- **`Email/get` liefert `list` in Repository-Reihenfolge, nicht in der, die du angefragt hast**,
  und berechnet `notFound` über die Differenz. Wenn du es mit `Email/query` gepaart hast, musst
  du das Ergebnis selbst gegen die `ids` der Abfrage neu sortieren, sonst kommt deine Liste nach
  Datenbank-ID sortiert an.

Fehler auf Anfrageebene kommen als `application/problem+json` mit Status 400 und einem `type` von
`urn:ietf:params:jmap:error:notJSON` / `notRequest` / `unknownCapability` zurück.

### Implementierte Methoden

Alles, was in [`src/Jmap/Method/`](../src/Jmap/Method/) registriert ist:

| Methode | Anmerkungen |
|---|---|
| `Core/echo` | |
| `PushSubscription/get` / `PushSubscription/set` | Keine `accountId`; je Nutzerin. |
| `Mailbox/get` / `Mailbox/query` / `Mailbox/changes` / `Mailbox/set` | |
| `Email/get` / `Email/query` / `Email/changes` / `Email/set` | |
| `Thread/get` / `Thread/changes` | `/get` trägt drei plMail-Erweiterungen: `snoozedUntil`, `category`, `isNew`. |
| `Thread/set` | plMail-Erweiterung. Zwei Eigenschaften, `snoozedUntil` und `isNew` — siehe §4. |
| `SearchSnippet/get` | |
| `Calendar/get` | `urn:plmail:params:jmap:calendars`. Kalender liefert genau ein Konto. |
| `CalendarEvent/get` / `CalendarEvent/query` / `CalendarEvent/set` | Eine ID ist die Serie, nicht eine Termininstanz; `/query` verlangt einen Zeitraum, und `expandRecurrences: true` lässt sie je Termininstanz antworten. |
| `EmailSubmission/get` / `EmailSubmission/set` / `EmailSubmission/changes` | |
| `Identity/get` / `Identity/set` | |

**Heute nicht implementiert.** Nichts davon ist ein bewusster Ausschluss — es wurde bisher nur
nicht gebraucht. Wenn dein Client etwas davon will, **frag danach, statt darum herum zu
konstruieren** (siehe [§0](#0-lies-das-zuerst-der-server-wird-aktiv-weiterentwickelt)):

- **`Email/queryChanges` und `Mailbox/queryChanges` gibt es nicht.** `Email/query` liefert
  `canCalculateChanges: false`. Um eine Liste aufzufrischen, führst du die Abfrage erneut aus.
  Nimm `Email/changes` für das Delta auf Objektebene und frag für die Sortierung neu ab.
- **Anchor-basiertes Paging wird nicht unterstützt.** `anchor` löst `unsupportedFilter` aus; nimm
  `position` + `limit`. Negative Positionen (Verankern vom Ende her) werden **von `Email/query`**
  abgelehnt; `Mailbox/query` nimmt sie an und verankert vom Ende.
- **`Email/query` liefert immer `total`; `Mailbox/query` nur mit `calculateTotal: true`.**
- **`VacationResponse/*` und `Blob/copy`** fehlen. `SearchSnippet/get` nicht — es stand hier als
  fehlend, während `SearchSnippetGetMethod` bereits im Baum lag.
- **Kontakte sind nur Autovervollständigung.** `Contact/autocomplete`, unter
  `urn:plmail:params:jmap:contacts`, liefert gereihte Empfängervorschläge; es gibt kein
  `Contact/get`, `/query` oder `/set` — das Adressbuch wird aus Mail-Headern geerntet und ist nicht
  beschreibbar. Kalender werden bedient, unter `urn:plmail:params:jmap:calendars`; es
  gibt kein `Calendar/set` und kein `/changes` auf beiden Typen, weil Kalender keine Änderungen
  berechnen können — siehe [JMAP](internals/jmap.md).

### Objektabbildung — die vier Dinge, die Menschen überraschen

**1. Eine JMAP-`Mailbox` ist ein plMail-*Label-Binding*, kein IMAP-Ordner.**

Label sind nutzerbezogen und reichen über Konten hinweg; ein `LabelBinding` ist die kontobezogene
Ausprägung eines Labels, und *das* ist es, was innerhalb eines JMAP-Kontos eine stabile Identität
hat. Also:

- `Mailbox.id` = Binding-ID.
- `Mailbox.labelId` = die **nutzerbezogene Label-ID**, die dieses Binding materialisiert. Eine
  plMail-Erweiterung, nicht RFC 8621. Binding-IDs sind notwendigerweise kontobezogen, ein aus
  drei Konten erreichbares Label sind also drei Mailboxes mit drei zusammenhanglosen IDs und
  nichts, was sie verbände. Genau das erlaubt es einem Client, sie zu einer einzigen
  Seitenleistenzeile zusammenzuklappen — über `name` abzugleichen bricht in dem Moment, in dem
  das Label in einem Konto umbenannt wird. Es ist **keine** ID, die du an `inMailbox` oder
  `Email/set` übergeben kannst; die nehmen immer Binding-IDs.
- `Mailbox.name` = der **Blatt**-Name (JMAP modelliert Hierarchie über `parentId`, also
  `"Invoices"`, nicht `"Work/Invoices"`).
- `Mailbox.parentId` = die *Binding*-ID des Elternteils, oder `null`, wenn das Elternteil in
  diesem Konto kein Binding hat (damit ein Kind nie auf eine unauflösbare ID zeigt).
- Rollen werden aus plMails `LabelRole` abgebildet: `inbox`, `sent`, `drafts`, `trash`, `junk`
  (plMails `Spam`), `archive`, dazu `flagged`, `important` und `all`. Eine nicht abgebildete
  Rolle verfällt zu `null` — die Mailbox erscheint trotzdem.
- `myRights`: Systemlabel (`role !== null`) lassen sich weder umbenennen noch löschen; eigene
  Label sind vollständig veränderbar. Alles andere ist erlaubt.
- `isSubscribed` spiegelt den Sichtbarkeitsschalter des Labels. Beachte: **Archiv wird
  standardmäßig verborgen angelegt** und erscheint erst, wenn die Nutzerin es sichtbar schaltet.

Die Reihenfolge der Systemlabel in der Seitenleiste liegt fest: Posteingang 0, Gesendet 10,
Entwürfe 20, Spam 30, Papierkorb 40, Archiv 50. Eigene Label sortieren danach, alphabetisch.

**2. `Email.mailboxIds` stammt aus dem Label-Join je Nachricht, übersetzt in den Raum der
Binding-IDs.**

Nicht aus der Vereinigung auf Konversationsebene — die zu lesen meldete für jede Nachricht der
Konversation eine Mailbox. Standardform einer JMAP-Map (`{"42": true}`), und `{}`, wenn leer
(niemals `[]`).

Der Join speichert nutzerbezogene **Label**-IDs, veröffentlicht werden hier aber **Binding**-IDs,
damit sie zu `Mailbox.id` passen und sich unmittelbar an `inMailbox` und `Email/set`
zurückgeben lassen. **Ein ID-Raum durchgehend — es gibt keinen Fall, in dem du übersetzen
musst.** Ein Label, für das das Konto kein Binding hat, wird weggelassen statt als ID
veröffentlicht, die du nicht auflösen könntest.

> Bis Mitte 2026 gab diese Eigenschaft unübersetzte Label-IDs aus. Weil beide
> Autoincrement-Ganzzahlen aus verschiedenen Tabellen sind, *sahen* die falschen IDs meist gültig
> aus und benannten irgendeine unbeteiligte Mailbox; das Symptom war also eine plausible falsche
> Antwort und kein Fehler — und auf einer Installation mit einem Konto war es unsichtbar, weil
> die beiden Sequenzen dort meist gleichlaufen. Wenn du das hier gegen einen älteren Server
> liest, ist es das, was du siehst.

**3. Bodys sind synthetische Parts.**

plMail speichert einen *flachgeklopften* Body (`bodyText` / `bodyHtmlSafe`), keinen MIME-Baum.
Jede Email veröffentlicht deshalb höchstens zwei Body-Parts mit den festen `partId`s **`"text"`**
und **`"html"`**. Sie sind je Nachricht stabil, was alles ist, was `fetchTextBodyValues` /
`fetchHTMLBodyValues` brauchen. Behandle `partId` trotzdem als opak, wie die Spezifikation es
verlangt.

**Achte auf die Groß-/Kleinschreibung: `fetchHTMLBodyValues`, nicht `fetchHtmlBodyValues`.** Das
ist die Schreibweise aus RFC 8621 und das, was der Server liest. Ein nicht erkanntes Argument ist
schlicht abwesend, falsch geschrieben bekommst du also leere `bodyValues` und überhaupt keinen
Fehler.

**Das veröffentlichte HTML ist immer die bereinigte Fassung**, nie die rohe Spalte — dieser Body
wird direkt an Drittanbieter-Clients gereicht, die ihn rendern.

`preview` ist der Klartextkörper, mit zusammengefallenen Leerräumen, gedeckelt auf 256 Zeichen.

**4. Keywords sind teils Spalten, teils Flags.**

| Keyword | Gestützt auf |
|---|---|
| `$seen` | Zeitstempelspalte `seen_at` |
| `$flagged` | Zeitstempelspalte `starred_at` |
| `$draft` | das IMAP-JSON-Array `flags` |
| `$answered` | das IMAP-JSON-Array `flags` |

Jedes **andere Keyword wird abgelehnt**, mit `unsupportedFilter`, wenn danach gefiltert wird.
Erfinde keine eigenen Keywords für deinen eigenen Zustand; sie werden nicht zurückwandern.

Die Form von Adressen wird an der Grenze übersetzt: plMail speichert `{name, address}`, JMAP gibt
`{name, email}` aus. `messageId` / `inReplyTo` / `references` werden als nackte IDs ohne spitze
Klammern ausgegeben.

### Der Neu-Marker: `Thread.isNew`

plMail markiert eine Konversation als **neu**, bis ihre Zeile der Nutzerin tatsächlich vorgelegt
wurde — und danach höchstens noch 24 Stunden, was auch geschieht. Daraus zeichnet das Web seine
„Neu"-Abzeichen, seine Kategorie-Tabs und seine Punkte in der Seitenleiste, und es ist bewusst
**nicht** dieselbe Achse wie ungelesen:

> neu = der Nutzerin nie angezeigt **UND** innerhalb von `MessageThread::NEW_WINDOW` (`PT24H`)
> eingetroffen

Eine Konversation, die du am Laptop gelesen hast, ist für einen Client, der ihre Zeile nie
gezeichnet hat, weiterhin neu; und den Marker zurückzuziehen markiert nichts als gelesen. Die
beiden dürfen sich widersprechen — das ist das Feature, nicht ein Fehler darin. Siehe
[`MessageThread::isNewAt()`](../src/Entity/Mail/MessageThread.php).

**Lesen.** `Thread/get` liefert `isNew` (Boolean) auf jedem Thread, immer vorhanden. Das Zeitfenster
wird serverseitig gegen *eine* Uhrzeitablesung pro Antwort angewendet, zwei Threads einer Antwort
können die Grenze also nicht unterschiedlich sehen. Bau die 24 Stunden nicht im Client nach: das
wäre eine zweite Kopie von `NEW_WINDOW`, die auseinanderläuft, sobald jemand den Wert ändert.

**Zurückziehen.** `Thread/set` nimmt `isNew: false` an und sonst nichts — `true` wird mit
`invalidProperties` abgelehnt. Sende es für die Zeilen, die du der Nutzerin wirklich gezeigt hast,
nachdem du sie gezeigt hast. Es ist idempotent: eine Wiederholung verschiebt den gespeicherten
Zeitstempel nicht, der Eintrag sagt also weiterhin, wann die Zeile *zuerst* angezeigt wurde — du
darfst es gefahrlos bei jedem Zeichnen für jede Zeile senden.

**Warum das wichtiger ist, als es aussieht.** Vorher öffnete sich ein Postfach, das vollständig am
Telefon aufgeräumt worden war, im Browser mit jeder Konversation des letzten Tages noch immer als
„Neu" markiert und allen fünf Kategorie-Tabs noch immer bepunktet, weil nur das Web einen Marker
zurückziehen konnte. Wenn dein Client Nachrichtenlisten zeichnet, melde die Anzeigen zurück — sonst
lässt du die anderen Clients der Nutzerin falsch dastehen.

Das Zurückziehen wird bewusst **nicht** als `Thread`-Statusänderung gemeldet. Ein Client, der eine
Seite Post zeichnet, würde sonst dutzende Statusänderungen an jedes andere Gerät der Nutzerin
schicken, für eine Spalte, von der keines davon dringend erfahren muss; das nächste gewöhnliche
`Thread/get` trägt den neuen Wert.

### Filter für Email/query

Kompiliert vom [`EmailFilterCompiler`](../src/Jmap/Query/EmailFilterCompiler.php). **Alles, was
nicht verstanden wird, löst `unsupportedFilter` aus, statt stillschweigend ignoriert zu werden**
— ein leise verworfener Filter liefert zu viele E-Mails zurück, und der Client kann es nicht
merken.

| Bedingung | Verhalten |
|---|---|
| `inMailbox` | Mailbox-(Binding-)ID. |
| `inMailboxOtherThan` | Nicht leeres Array von Binding-IDs. |
| `before` / `after` | UTCDate gegen `received_at` (`<` und `>=`). |
| `minSize` / `maxSize` | `>=` / `<` auf die Größe in Byte. |
| `hasKeyword` / `notKeyword` | Nur die vier Keywords von oben. |
| `hasAttachment` | Boolescher Wert. |
| `text` | **Echte Volltextsuche** — Postgres `tsvector` + `websearch_to_tsquery('english')`. Gestemmt, gewichtet, kein Teilstring-Scan. |
| `body` / `subject` / `from` | `ILIKE` auf Teilzeichenketten. `from` deckt Adresse und Anzeigename ab. |
| `to` / `cc` / `bcc` | Teilzeichenkette über dem serialisierten JSON-Adressarray (trifft Name oder Adresse). |
| `filename` | Teilzeichenkette über Dateinamen von Anhängen. Inline-Parts haben leere Dateinamen und treffen nie. |
| `listId` | Teilzeichenkette über den kanonisierten `list-id`-Header. |

`AND` / `OR` / `NOT` als FilterOperator lassen sich beliebig verschachteln. Beachte, dass `NOT`
als `NOT (a OR b …)` implementiert ist.

Der `EmailFilterCompiler` versteht außerdem `hasLabel` / `notLabel`, die **nutzerbezogene
Label-IDs** nehmen statt Mailbox-(Binding-)IDs. Die gibt es für Mail-Regeln, die keinen Grund
haben, vom JMAP-ID-Raum zu wissen. Sie gehören nicht zum Filtervokabular für Clients — nimm
`inMailbox`.

**Sortierung:** `receivedAt`, `from`, `to`, `subject`, `size`. **Limit:** gedeckelt bei 500
(`null` oder größer wird 500). `collapseThreads` wird unterstützt.

Die Volltext-Konfigurationszeichenkette (`'english'`) muss zu der passen, mit der die Spalte
erzeugt wurde — eine Abweichung liefert stillschweigend nichts zurück, weil die gestemmten Token
nie zusammenfallen. Versuch einfach nicht, das zu umgehen.

### Die Suchsyntax der Web-Oberfläche

Dein Suchfeld sollte dieselben Operatoren im Gmail-Stil annehmen wie die Web-Oberfläche und sie
in JMAP-Filterbedingungen übersetzen. Aus dem
[`SearchQueryParser`](../src/Service/Search/SearchQueryParser.php):

| Eingetippt | Bedeutet |
|---|---|
| `from:alice` | `from` |
| `to:bob` | `to` |
| `subject:invoice` | `subject` |
| `has:attachment` | `hasAttachment: true` |
| `is:unread` / `is:read` | `notKeyword: "$seen"` / `hasKeyword: "$seen"` |
| `is:starred` | `hasKeyword: "$flagged"` |
| `in:inbox\|sent\|drafts\|trash\|archive\|junk` | `inMailbox` der Mailbox dieser Rolle |
| `after:2024-01-01` / `before:2024-12-31` | `after` / `before` |
| alles Übrige | freier Text → `text` |

Zeichenketten in Anführungszeichen bleiben zusammen. Unbekannte Operatoren fallen zu freiem Text
durch, statt einen Fehler auszulösen — mach diese Nachsicht nach.

### Schreiben: Email/set

Legt Entwürfe an, aktualisiert Keywords und `mailboxIds`, und „zerstört".

- **`destroy` ist ein Verschieben in den Papierkorb**, kein Löschen der Zeile. Es gibt im ganzen
  Produkt keinen Pfad zum endgültigen Löschen. Stell es in deiner Oberfläche als Papierkorb dar.
- Jede Änderung an Mailbox oder Keyword geht durch denselben Propagator, den auch die
  Web-Oberfläche verwendet; eine von deinem Client gemachte Änderung erreicht Gmail / IMAP / Graph
  also genauso wie eine im Browser gemachte. Aus deiner App zu archivieren archiviert in Gmail.
- `ifInState` wird beachtet — nimm es zur Konflikterkennung bei Stapelmutationen.
- Das Anlegen von Entwürfen geht durch denselben Draft-Writer, den auch der Editor verwendet.
- **`attachments` funktioniert bei `update` genauso wie bei `create` und ist ein ganzer Wert.**
  Häng eine Datei an einen bereits bestehenden Entwurf, indem du die vollständige Liste schickst,
  die am Ende dranhängen soll — die `p-`-BlobIDs, die dir `Email/get` für die behaltenen Teile
  gegeben hat, plus die `u-`-BlobID des gerade Hochgeladenen. Weggelassene Teile werden entfernt
  und ihre Bytes gelöscht. Ein per `p-`-BlobID erneut aufgeführter Teil behält seine ID und wird
  nicht neu hochgeladen; gibst du dabei einen anderen `name` an, wird die Datei umbenannt. Du
  musst den Entwurf nicht neu anlegen.
- **Eine BlobID, die sich nicht auflösen lässt, bringt das ganze Update zu Fall.** Du bekommst
  `notUpdated[id].type = "invalidProperties"`, und *nichts* aus diesem Patch wird angewendet —
  weder die Anhänge noch der Betreff daneben. Lade neu hoch und schick den ganzen Patch erneut.

**Semantische Erinnerung:** „archiviert" heißt in plMails Domänenmodell *trägt kein
Posteingangs-Label*. Zum Archivieren entfernst du die Mailbox-ID des Posteingangs. Das
Archiv-Label selbst ist Buchführung über den IMAP-Ort für reine IMAP-Konten und standardmäßig
verborgen.

### Senden: EmailSubmission/set

Das Senden wird auf demselben Message-Bus eingereiht, den auch der Web-Editor verwendet. Diese
Kette führt den gesamten Übergang vom Entwurf zur gesendeten Nachricht selbst durch (fügt
„Gesendet" hinzu, entfernt „Entwürfe", löscht `\Draft`, setzt `sentAt`, richtet das Postfach neu
aus), sodass auch ein Client, der `onSuccessUpdateEmail` weglässt, am Ende richtig dasteht.

```json
["EmailSubmission/set", {
  "accountId": "7",
  "create": { "s1": { "emailId": "#draft1", "identityId": "3" } },
  "onSuccessUpdateEmail": { "#s1": { "mailboxIds/42": null, "mailboxIds/17": true } }
}, "c0"]
```

Was du wissen solltest:

- **Eine Submission hat keine eigene Tabelle — ihre ID *ist* die Email-ID.** plMail sendet jeden
  Entwurf höchstens einmal, die Zuordnung bleibt also eineindeutig. Abrufbar ist sie trotzdem
  vollständig: siehe [Eine Submission zurücklesen](#eine-submission-zurücklesen) weiter unten.
- `undoStatus` wird als **`"pending"`** gemeldet: Der Versand steht wirklich in der Warteschlange
  und hat, wenn der Aufruf zurückkehrt, noch nicht stattgefunden.
- **Die Kulanzfrist der Web-Oberfläche zum Rückgängigmachen wird bei JMAP-Submissions bewusst
  NICHT angewandt.** Ein JMAP-Client hat darum gebeten, *jetzt* zu senden. Wenn du in deiner App
  ein Rückgängig-Fenster willst, bau es clientseitig, indem du den Submission-Aufruf verzögerst.
- **Terminiertes Senden** wird unterstützt, und zwar in der Schreibweise der Spezifikation:
  RFC 8621 §7 führt die SMTP-Erweiterung FUTURERELEASE (RFC 4865) als Envelope-Parameter mit,
  statt eine eigene Eigenschaft zu definieren. Setze `HOLDFOR` (Sekunden) *oder* `HOLDUNTIL`
  (einen Zeitpunkt) — nicht beides — in `envelope.mailFrom.parameters` und lies die echte
  Freigabezeit an `sendAt` ab:

  ```json
  ["EmailSubmission/set", {
    "accountId": "7",
    "create": { "s1": {
      "emailId": "#draft1",
      "envelope": { "mailFrom": { "parameters": { "HOLDFOR": "3600" } } }
    } }
  }, "c0"]
  ```

  `maxDelayedSend` in den Submission-Capabilities des Kontos ist die Obergrenze — 30 Tage — und
  sie wird durchgesetzt, nicht gekappt: Eine längere Haltezeit wird abgelehnt statt verkürzt.
  Eine bereits verstrichene Haltezeit sendet sofort. Der Rest des Envelopes wird geprüft, aber
  nicht angewandt: plMail sendet an die auf der Email gespeicherten Empfänger und mit der dort
  gespeicherten Absenderadresse, eine abweichende `mailFrom.email` oder ein abweichendes
  `rcptTo` wird also abgelehnt statt stillschweigend ignoriert.
- **Abbrechen vor der Freigabe**: Setze `undoStatus` der Submission per Update auf `"canceled"`.
  Das ist verlässlich, solange die Nachricht gehalten wird, ein Wettlauf, sobald sie ohne
  Haltezeit eingereiht wurde, und wird mit `cannotUnsend` abgelehnt, sobald sie gesendet ist.
  Ein Abbruch auf einer Email, die du nie eingereicht hast, wird mit `notFound` abgelehnt — es
  gibt keine Submission zum Abbrechen, und früher hinterließ ein solcher Aufruf eine Markierung,
  die den nächsten Versand der Nutzerin verschluckte.
- Fehler: `invalidProperties` (fehlende/unbekannte `emailId`, fehlerhafter Envelope, zu lange
  Haltezeit), `forbiddenFrom` (eine `identityId`, unter der dieses Konto nicht senden darf),
  `invalidRecipients`, `alreadyExists` (bereits gesendet), `noRecipients`, `cannotUnsend`,
  `notFound` (nichts abzubrechen).

### Eine Submission zurücklesen

`EmailSubmission/get` antwortet ab dem Moment der Annahme, und zwar in allen drei Zuständen der
Spezifikation:

| Zustand | `undoStatus` | `sendAt` |
|---|---|---|
| Eingereiht oder gehalten, noch nicht raus | `"pending"` | wann sie fällig ist — die echte Freigabezeit |
| Vor dem Versand abgebrochen | `"canceled"` | wann sie hinausgegangen *wäre* |
| Gesendet | `"final"` | wann sie tatsächlich hinausging |

Eine Email, die nie eingereicht wurde, ist `notFound`. Das ist der einzige `notFound`-Fall: Er ist
das Fehlen einer Submission, nicht einer ihrer Zustände.

**Das hat sich geändert, und wenn du gegen das alte Verhalten gebaut hast, kannst du jetzt Code
löschen.** Eine gehaltene Submission antwortete früher die ganze Haltezeit über mit `notFound` und
tauchte dann als `"final"` auf — die Freigabezeit aus der Create-Antwort war also die einzige
Kopie, die existierte. Ging diese Antwort verloren, war der Termin nicht mehr erfahrbar. Clients
mussten eine eigene, gerätelokale Liste terminierter Sendungen führen, und ein Telefon und ein
Laptop im selben Konto konnten sich nicht darüber einigen, wann eine Nachricht hinausgeht. Tu das
nicht mehr: `sendAt` aus `EmailSubmission/get` ist maßgeblich und gilt für jedes Gerät.

Praktisch heißt das:

- **Frage die Submission ab, nicht die Email**, wenn es um den Versand geht.
  `EmailSubmission/changes` meldet alle drei Übergänge — das Einreichen als `created`, einen
  angenommenen Abbruch als `updated` und das tatsächliche Hinausgehen als `updated` —, eine Liste
  terminierter Sendungen lässt sich also allein aus dem Änderungsprotokoll aktuell halten.
- **`sendAt` einer `"pending"`-Submission ist eine Zusage über die Warteschlange, keine Garantie
  auf die Sekunde.** Es ist der Zeitpunkt, ab dem der Worker starten darf, und eine ausgelastete
  Installation startet später. Zeig es als Uhrzeit, nicht als Countdown auf null.
- Eine Submission, die beim Ausrollen dieser Funktion gerade gehalten wurde, behält ihr altes
  Verhalten — `notFound`, bis sie gesendet ist —, weil ihre Freigabezeit nur je im
  Warteschlangeneintrag stand. Davon gibt es höchstens eine Haltezeit lang welche.

**Identitäten** kommen aus derselben Liste, die auch das Von-Auswahlfeld des Web-Editors zeigt —
die sendefähigen Aliase des Kontos, das primäre zuerst. Ein Konto ohne Alias-Zeilen ergibt eine
synthetische Identität für die Kontoadresse selbst. Lass immer die Nutzerin wählen, und
voreingestellt ist das primäre: Die `identityId` einer Submission entscheidet über die
Absenderadresse, mit der die Nachricht tatsächlich hinausgeht, und eine, die keine Identität
dieses Kontos ist, wird mit `forbiddenFrom` abgelehnt, statt auf die Kontoadresse
zurückzufallen.

### Blobs: hochladen und herunterladen

**Upload** — `POST {uploadUrl}` mit rohen Bytes und einem `Content-Type`:

```json
{ "accountId": "7", "blobId": "u-91", "type": "image/png", "size": 40213 }
```

Höchstens 50 MB (passend zu `maxSizeUpload`); größer ergibt `tooLarge` / 413. Der angegebene Typ
wird als Metadatum gespeichert und zurückgegeben, genau wie die Spezifikation es verlangt —
nichts wird geparst oder geglaubt. Uploads sind *vorgemerkt*: Ungenutzte räumt ein geplanter
`app:prune:blobs`-Job weg, lade also nahe an dem Zeitpunkt hoch, an dem du den Blob referenzierst.

**Download** — `GET {downloadUrl}` mit ausgefüllten `{accountId}`, `{blobId}`, `{name}`.

`blobId` hat einen Namensraum und ist opak: `m-<id>` (die RFC822-Quelle einer ganzen Nachricht),
`p-<id>` (ein Anhangs-Part), `u-<id>` (ein vorgemerkter Upload). Zerleg sie nicht — den
Namensraum gibt es gerade deshalb, weil die zugrunde liegenden Tabellen unabhängige
Autoincrement-IDs haben.

**Sicherheitsverhalten, um das du herum entwerfen musst:** Der Query-Parameter `accept` wird
**ignoriert** (ihn zu beachten ließe eine Aufruferin HTML als Bild umetikettieren).
`X-Content-Type-Options: nosniff` wird immer gesetzt, und **nur `image/*` wird inline
ausgeliefert** — alles andere kommt mit einer Attachment-Disposition zurück. Das wiegt hier
schwerer als in der Web-Oberfläche, weil ein JMAP-Client die URL womöglich direkt an eine Webview
weiterreicht. Bau keinen Betrachter, der inline gerendertes Beliebiges voraussetzt.

Das Segment `{name}` dient nur dem Dateinamen beim Download und wird nie für das Nachschlagen
geglaubt.

### Aktuell bleiben: Push

Drei Mechanismen, absteigend danach, was du bevorzugen solltest.

**1. `PushSubscription` — die richtige Antwort für Zustellung im Hintergrund.**

Zwei Transporte hinter einem Objekt. Ein Create mit `url` und `keys` ist eine
**Web-Push**-Subscription; ein Create mit `fcmToken` eine **Firebase**-Subscription. `fcmToken`
ist eine plMail-Erweiterung des Objekts aus RFC 8620; alles andere — `deviceClientId`, `types`,
`expires`, der Handshake — ist identisch.

```jsonc
// Web Push
["PushSubscription/set", { "create": { "s1": {
  "deviceClientId": "phone-42",
  "url": "https://ntfy.example.com/…",
  "keys": { "p256dh": "…", "auth": "…" },
  "types": ["Email", "Mailbox"]
}}}, "0"]

// Firebase — nur, wenn die Session "fcm": true sagt
["PushSubscription/set", { "create": { "s1": {
  "deviceClientId": "phone-42",
  "fcmToken": "cX9…:APA91b…",
  "types": ["Email", "Mailbox"]
}}}, "0"]
```

Die beiden Formen schließen einander aus. Ein Create mit `fcmToken` *und* `url` (oder `keys`)
wird mit `invalidProperties` abgelehnt und benennt den Konflikt, statt dass eines für dich
ausgewählt wird. Ein Create mit `fcmToken` auf einer Instanz, auf der FCM nicht konfiguriert oder
abgeschaltet ist, wird mit `forbidden` abgelehnt — prüf zuerst die Capability; das hier ist nur
das Auffangnetz.

`PushSubscription/get` meldet, welche Art du bekommen hast, als schreibgeschütztes `transport` mit
`"webpush"` oder `"fcm"`. Du brauchst das, weil `deviceClientId` je Gerät stabil ist und ein
erneutes Registrieren die Zeile *ersetzt*: Ein Telefon, das von einem UnifiedPush-Distributor auf
Firebase gewechselt ist, hat eine Subscription, nicht zwei. Weder `keys` noch `fcmToken` werden je
zurückgegeben — beides ist die Adresse eines Geräts, und sie zurückzuspiegeln hieße, dass jede
Person, die eine Antwort lesen kann, dorthin pushen könnte. `url` ist bei einer
FCM-Subscription `null`.

**Ein FCM-Token zu rotieren** ist ein `PushSubscription/set`-`update` mit `fcmToken` auf einer
bestehenden FCM-Subscription — die einzige Adress-Eigenschaft, die ein Update ändern darf, weil
Android Tokens nach eigenem Zeitplan neu ausstellt. Es spannt den Handshake neu: `verified` fällt
auf false zurück, und an das neue Token geht sofort eine frische `PushVerification`, die du genauso
behandelst wie die erste. `url` und `keys` bleiben nur beim Anlegen setzbar; wo eine verschlüsselte
Nutzlast hingeht, ändert man mit einem neuen Create.

**Es gibt einen verpflichtenden Verifikations-Handshake, und er ist der springende Punkt.** Beim
Anlegen schickt der Server sofort ein `PushVerification`-Objekt an die Adresse, die du angegeben
hast — per POST an den Endpunkt bei Web Push, als gewöhnliche FCM-Datennachricht bei Firebase, in
beiden Fällen dasselbe JSON. Du liest den Code daraus und schickst ihn per
`PushSubscription/set`-Update zurück. **Bis du das tust, empfängt die Subscription nichts.** Genau
das verhindert, dass der Endpunkt ein offenes Relay wird — ohne ihn könnte jede Person mit einem
Konto die Adresse einer Fremden registrieren. Plane diesen Roundtrip in deinem Einstieg ein.

**Jeder Versuch, dein Gerät zu erreichen, wird serverseitig protokolliert, und du kannst der
Nutzerin sagen, wo sie nachsieht.** Die Verifikation, auf die du wartest, und jede spätere
`StateChange` schreiben eine Zeile, die diese Nutzerin unter **Einstellungen →
Benachrichtigungen** sieht (pro Gerät: Transportweg, Bestätigungsstand und die letzte Zustellung
mit ihrem Ergebnis) und eine Administratorin unter **/admin/push**. „Die App hat sich registriert
und nie ihren Code bekommen" ist damit beantwortbar, ohne dass jemand ein Container-Log liest: Die
Zeile sagt, ob der Server es versucht hat und was der Transportweg geantwortet hat —
`UNREGISTERED`, ein 410 oder „übersprungen, diese Installation hat keine Schlüssel". Protokolliert
wird der `@type` der Nutzlast und sonst nichts von ihr, das Protokoll wird also nie zu einer
Aufzeichnung der Mail-Aktivität; bau nichts, das mehr voraussetzt.

**2. EventSource (SSE) — für eine Sitzung im Vordergrund, kurz.**

`GET {eventSourceUrl}`, `text/event-stream`. Sendet unmittelbar beim Verbinden ein
`state`-Ereignis (damit du ohne zusätzlichen Roundtrip weißt, wo du stehst), danach weitere
`state`-Ereignisse bei Änderungen, dazu `ping`-Ereignisse (standardmäßig 30 s, mindestens 5 s).

**Lies das, bevor du es verwendest:** Jede Verbindung **belegt für ihre gesamte Lebensdauer einen
PHP-Worker**. Unter FrankenPHP ist das eine harte Kapazitätsgrenze — N verbundene Clients heißt N
belegte Worker, und wenn alle vergeben sind, beantwortet der Server keine gewöhnlichen Anfragen
mehr. Auf einem heimischen NAS ist N klein. Folglich **schließt der Server jede Verbindung nach
300 Sekunden hart** und erwartet, dass du dich neu verbindest. Verbinde dich mit Backoff neu, und
**trenn die Verbindung, sobald deine App in den Hintergrund geht**. Zustellung im Hintergrund
gehört auf Web Push, nicht hierher.

`?closeafter=state` gibt dir einen StateChange und ein sofortiges Schließen — der billige Weg,
neu zu synchronisieren, ohne eine Verbindung zu halten.

**3. Polling** — der Rückfall. Halt es selten; das hier ist irgendjemandes Raspberry Pi.

**Was ein Push tatsächlich enthält:**

```json
{ "@type": "StateChange", "changed": { "7": { "Email": "9", "Mailbox": "3" } } }
```

Bewusst winzig. **JMAP pusht nie Mail-Inhalte, nur die Nachricht, dass sich ein State-Token
bewegt hat.** Danach rufst du `Email/changes` auf, um herauszufinden, was. Verfolgte Typen:
`Mailbox`, `Email`, `Thread`, `EmailSubmission`. `Identity` ist ausgenommen — es ändert sich nur,
wenn die Nutzerin ihre eigenen Adressen bearbeitet, was sie gerade in deiner App getan hat.

**Über FCM kommt dasselbe JSON als Datennachricht an**, nie als `notification`-Nutzlast — die
Systemleiste darf nichts zeichnen, bevor deine App es gesehen hat, denn nur du weißt, ob die
Nutzerin ohnehin gerade auf dieses Postfach schaut. Das Objekt von oben ist der String-Wert eines
einzelnen Datenschlüssels:

```json
{
  "message": {
    "token": "cX9…:APA91b…",
    "data": { "payload": "{\"@type\":\"StateChange\",\"changed\":{\"7\":{\"Email\":\"9\"}}}" },
    "android": { "priority": "HIGH", "ttl": "86400s", "collapse_key": "plmail-state-change" }
  }
}
```

`RemoteMessage.getData()["payload"]` ist also ein JSON-String, und dessen `@type` ist entweder
`StateChange` oder `PushVerification`. Die Collapse-Keys sind je Typ getrennt —
`plmail-state-change` und `plmail-push-verification` —, damit ein Rückstau von StateChanges auf den
neuesten zusammenfällt, ohne je eine unzugestellte Verifikation zu verwerfen. Nachrichten leben
24 Stunden.

Ein Token, zu dem FCM `UNREGISTERED` oder `NOT_FOUND` meldet, **löscht die Subscription**, genau
wie ein 404/410 bei Web Push. Kontingent-Ablehnungen und Firebase-Ausfälle tun das nicht.

Jedes Token kommt aus demselben State-Manager, den auch die `/get`- und `/changes`-Methoden
verwenden; ein Push und ein anschließendes `/changes` können sich also nie widersprechen.

**Änderungen seitenweise:** `/changes` liefert höchstens **256** Zeilen je Aufruf und setzt
`hasMoreChanges`. Die Grenze ist für Mobilgeräte bewusst bescheiden — lauf in einer Schleife, bis
sie sich löst.

### Das Sync-Modell, von Anfang bis Ende

Zu verstehen, woher die Mail kommt, hilft dir, in deiner Oberfläche die richtigen Erwartungen zu
setzen:

| Kontoart | Einlesen | Sofortige Zustellung |
|---|---|---|
| IMAP | `webklex/php-imap`, eine IDLE-Verbindung je Postfach, beaufsichtigt | IMAP IDLE — funktioniert im LAN, keine öffentliche URL nötig |
| Gmail | Gmail REST + Batch API über OAuth2 | Google Cloud Pub/Sub watch → `/gmail/push` (verlangt öffentliches HTTPS und eine einmalige Einrichtung der Instanz) |
| Outlook / M365 | Microsoft Graph über OAuth2 (**nicht IMAP** — Exchange Online blockiert es unter den Security Defaults) | Graph-Subscriptions → `/webhook/graph` (verlangt öffentliches HTTPS) |

Ein geplanter Polling-Sync (alle 15 Minuten) sichert alle davon ab, wann immer kein Push
verfügbar ist. Also: **Deine App sollte allein aufgrund von Push nie behaupten, Mail sei „auf dem
neuesten Stand"**, und sollte ein manuelles Aktualisieren anbieten. Ebenso: Hämmere nicht auf
einen Sync-Endpunkt ein — der Server versucht es ohnehin schon.

Der Server holt die **gesamte** Historie eines Kontos — es gibt keine Aufbewahrungseinstellung,
die sich erweitern ließe. Was es gibt, ist ein **Nachladen**, das bei einem großen Postfach dauert:
Die neueste Mail kommt zuerst, der Rest folgt über spätere Läufe, alte Mail kann also
*vorübergehend* fehlen. Genau dafür meldet die Konto-Capability
`urn:plmail:params:jmap:sync` der Session `backfillPending`. Findet eine datierte Suche nichts und
ist dieses Flag gesetzt, lautet die ehrliche Meldung „ältere Mail trifft noch ein" — und nicht
„keine Ergebnisse", und niemals „erweitere das Sync-Fenster".

Konversationen werden derzeit über **RFC-Message-IDs** gebildet, nicht über Gmails eigene
`threadId`. Rechne mit gelegentlichen Abweichungen davon, was die Gmail-Weboberfläche
zusammenfasst.

---

## 4. Verhalten: was deine App tun muss

### Checkliste zur Funktionsgleichheit

Grob danach geordnet, wie sehr Nutzerinnen sie vermissen werden.

**Lesen**
- Vereinigter Posteingang über Konten hinweg (clientseitig zusammengeführt; siehe
  [§3](#die-session-finden)).
- Konversationen, die neueste ausgeklappt, ältere eingeklappt.
- Ungelesen / markiert / Anhang auf einen Blick.
- Volltextsuche mit der Operatorsyntax von oben.
- Anhänge und Inline-Bilder; die ursprüngliche (rohe) Nachricht auf Wunsch über den Blob
  `m-<id>`.

**Schreiben**
- Verfassen, antworten, allen antworten, weiterleiten. Formatierter Text.
- Autovervollständigung von Kontakten (serverseitig aus synchronisierter Mail geerntet; frag
  `Contact/autocomplete` unter `urn:plmail:params:jmap:contacts` — der Server reiht über das ganze
  Adressbuch und schlägt damit jeden lokalen Cache. Das Adressbuch des Betriebssystems bleibt eine
  sinnvolle *Ergänzung* für Menschen, denen der Nutzer noch nie geschrieben hat).
- Senden aus jedem Konto **und jedem sendefähigen Alias** — zeig immer die Von-Auswahl.
- Automatisches Speichern von Entwürfen.
- Senden rückgängig machen (bei JMAP clientseitig; siehe oben).
- Terminiertes Senden, gesteuert vom Server statt von deinem eigenen Timer. Die Haltezeit steht im
  Envelope (`HOLDFOR` / `HOLDUNTIL`), und `EmailSubmission/get` meldet die Freigabezeit und
  `"pending"` zurück, solange die Nachricht gehalten wird — eine Liste „Terminiert" lässt sich
  also aus der Antwort des Servers selbst bauen. Hier stand früher das Gegenteil, weil eine
  gehaltene Submission mit `notFound` antwortete; siehe
  [Eine Submission zurücklesen](#eine-submission-zurücklesen).

**Ordnen**
- Label: anwenden, entfernen, anlegen, löschen. Verschachtelte Label gibt es im Datenmodell; die
  *Oberfläche* für verschachtelte Label steht noch auf der Server-Roadmap, flach mit Pfaden ist
  also in Ordnung.
- Archivieren = Posteingangs-Label entfernen. Papierkorb = `destroy`. Beides rückgängig zu machen.
- Zurückstellen — eine Konversation später zurückholen. Eine Eigenschaft **auf
  Konversationsebene** (`MessageThread.snoozedUntil`), offengelegt als `Thread/set`, einer
  plMail-Erweiterung, die sie und `isNew` annimmt und sonst nichts. Sie läuft über
  denselben `ThreadSnoozeService` wie die Web-Oberfläche, ein aus einem Client gesetztes
  Zurückstellen bedeutet also dasselbe wie eines im Browser — genau darum geht es, und genau
  deshalb ist ein lokal geführtes Zurückstellen weiterhin die falsche Idee: Es widerspräche der
  Web-Oberfläche und ginge bei einer Neuinstallation kaputt. Dieser Abschnitt sagte früher, das
  Zurückstellen sei gar nicht offengelegt, und führte sich selbst als kanonischen Fall für
  „frag nach, bau nichts drumherum" an; jemand hat nachgefragt.
- Gelesen/ungelesen markieren, mit Stern versehen.

**Einstellungen**
- Erscheinungsbild — `Appearance/get` und `Appearance/set` liefern das gesamte Objekt, samt
  `fontFamily`, `fontScale`, `previewLines`, `unreadEmphasis`, `accountCorner`, `listAvatars`,
  den drei Dichten pro Oberfläche und dem nur lesbaren `logoStyle`
  (siehe [§2](#2-aussehen-und-verhalten)). Bau das Token-System
  trotzdem; lies die Werte des Servers hinein, statt eigene Vorgaben zu erfinden.
- Kontoliste und -reihenfolge.
- Benachrichtigungseinstellungen.
- Die Verwaltung von App-Passwörtern gibt es heute nur im Web; verlinke dorthin, statt sie
  nachzubauen.

### Interaktionsregeln

- **Sei optimistisch, dann gleiche ab.** Änderungen an Stern, Gelesen-Status, Archiv und Label
  sollten in der Oberfläche sofort greifen und danach gegen den zurückgegebenen State abgeglichen
  werden. Der Server pflanzt ohnehin asynchron nach außen fort.
- **Jede destruktive Aktion ist rückgängig zu machen**, und das Rückgängig lebt für ein paar
  Sekunden in einem Toast am unteren Bildschirmrand. Papierkorb, Archivieren und Senden folgen in
  der Web-Oberfläche alle diesem Muster.
- **Eine Konversation zu lesen markiert sie als gelesen**, aber erst, nachdem sie tatsächlich
  angezeigt wurde — nicht beim Vorabladen.
- **Zurück heißt immer zurück.** Der mobile Wechsel Liste ⇄ Konversation ist ein echter
  Navigationsschritt.
- **Offline ist ein vollwertiger Zustand, kein Fehler.** Ein Heimserver ist öfter unerreichbar als
  einer in der Cloud. Zeig gecachte Mail, reihe Mutationen ein, sag klar, dass du offline bist,
  und versuch es erneut.
- **Polle nie aggressiv.** Keine Auffrischschleifen alle 5 Sekunden, kein offenes SSE im
  Hintergrund, kein erneutes Abfragen der ganzen Liste bei jedem Wechsel in den Vordergrund.

### Fehlerbehandlung

| Situation | Was du zeigst |
|---|---|
| 401 | „Dein App-Passwort wurde widerrufen oder ist ungültig" → erneute Anmeldung. Nicht stillschweigend wiederholen. |
| `unsupportedFilter` | Ein Fehler in deinem Query-Builder. Protokolliere ihn; zeig keine rohen JMAP-Fehler. |
| `tooLarge` beim Upload | Nenn die Grenze von 50 MB. |
| Server nicht erreichbar | „Dein Server ist nicht erreichbar" — mit dem Hostnamen. Die Leute hosten selbst; der Hostname ist für sie wirklich nützlich. |
| Leere Suchergebnisse | Hatte die Anfrage einen Datums- oder `before:`-Anteil und ist `backfillPending` gesetzt, sag, dass ältere Mail noch eintrifft. |
| Keine Konten verbunden | Verlinke tief in die Kontoeinrichtung der Web-Oberfläche; das Anlegen eines Kontos umfasst OAuth-Abläufe, die in einen Browser gehören. |

### Was du nicht tun solltest

- **Bau nicht gegen die HTML-/Turbo-Routen.** Sie werden sich ändern.
- **Verdrahte `apiUrl`, `uploadUrl`, `downloadUrl` oder `eventSourceUrl` nicht fest.** Lies sie
  jedes Mal aus dem Session-Objekt.
- **Zerleg `blobId` nicht.** Sie hat serverseitig einen Namensraum, und die Spezifikation
  verbietet es.
- **Nimm nicht ein einziges Konto an.** Niemals.
- **Halt keine SSE-Verbindung im Hintergrund.** Du legst damit jemandes Mailserver lahm.
- **Rendere Nachrichten-HTML nicht naiv auf dunklem Hintergrund** — wähl je Nachricht eine
  Strategie, und invertiere Bildmaterial zurück, wenn du invertierst (siehe
  [§2](#das-mail-sheet--wo-das-theme-endet-und-wie-es-endet)). Und rendere Blobs, die keine
  Bilder sind, nicht inline.
- **Erfinde keine Keywords** — alles jenseits von `$seen`, `$flagged`, `$draft`, `$answered` wird
  abgelehnt.
- **Implementiere kein endgültiges Löschen.** Es gibt es nicht, und es sollte es nicht geben.
- **Bau keine Umgehung für eine fehlende Serverfunktion, ohne vorher zu fragen.** Der Server wird
  aktiv weiterentwickelt, und ihn zu erweitern ist eine normale, verfügbare Option — siehe
  [§0](#0-lies-das-zuerst-der-server-wird-aktiv-weiterentwickelt). Eine Umgehung, die Serverlogik
  im Client dupliziert, ist schlimmer als eine einzeilige Anfrage.

---

## 5. Eine Entwicklungsumgebung aufsetzen

```bash
docker compose up --build
```

Vorher ist nichts auszufüllen — die Geheimnisse werden beim ersten Start erzeugt, und die eine
Einstellung ohne sinnvolle Voreinstellung (die Adresse, unter der plMail erreicht wird) wird im
Einrichtungsbildschirm abgefragt. Öffne die App, leg die erste Administratorin an, füge ein
Postfach hinzu.

Dann für deinen Client: **Einstellungen → App-Passwörter → eines anlegen**, und richte deinen
Client auf `https://localhost/.well-known/jmap`.

Nützlich während der Entwicklung:

```bash
docker compose exec php bin/console debug:router
```

```bash
docker compose exec php bin/console app:mail:sync
```

Ein Test-Stack mit eigenem Postgres (damit du nie echte Mail anfasst) steht über
`npm run test:env:up` bereit und wird unter `http://127.0.0.1:8001` ausgeliefert. Die vollständige
Referenz der Konsolenkommandos und die Testsuiten stehen in
[CONTRIBUTING.md](../CONTRIBUTING.md).

---

## 6. Kurzreferenz

**Endpunkte**

| Pfad | Methode | Zweck |
|---|---|---|
| `/.well-known/jmap`, `/jmap/session` | GET | Session finden |
| `/jmap/api` | POST | Alle Lese- und Schreibvorgänge |
| `/jmap/upload/{accountId}` | POST | Blob-Upload |
| `/jmap/download/{accountId}/{blobId}/{name}` | GET | Blob-Download |
| `/jmap/eventsource` | GET | SSE-Zustandsänderungen |

**Grenzwerte**

| Grenzwert | Wert |
|---|---|
| Uploadgröße | 50 MB |
| Gleichzeitige Uploads | 4 |
| Größe des Anfrageobjekts | 10 MB |
| Gleichzeitige Anfragen | 4 |
| Aufrufe je Anfrage | 32 |
| Objekte je `/get` | 500 |
| Objekte je `/set` | 500 |
| Limit von `Email/query` | 500 (harte Grenze) |
| Zeilen aus `/changes` | 256 je Aufruf |
| Lebensdauer einer SSE-Verbindung | 300 s |
| Schreibdrosselung für `lastUsedAt` beim App-Passwort | 300 s |

**Der Stack, zur Einordnung**

Symfony 8 / PHP 8.4 · PostgreSQL 18 · Doctrine ORM · Symfony Messenger (Doctrine-Transport) ·
Mercure (Live-Aktualisierungen der Web-Oberfläche) · FrankenPHP · AssetMapper + Tailwind v4 +
Hotwire Turbo/Stimulus · libsodium-verschlüsselte Anmeldedaten · AGPL-3.0 · `linux/amd64` und
`linux/arm64`.

**Punkte auf der Server-Roadmap, die Clients betreffen werden**

Die sind bereits geplant. Wenn dein Client einen davon früher braucht, sag es — Prioritäten sind
verhandelbar, und eine konkrete Client-Anforderung ist der beste Grund, etwas vorzuziehen.

- Den Umbau auf Label abschließen (Label als *das* Konzept für die Nutzerin; Mailbox vollständig
  zur IMAP-Sync-Infrastruktur herabgestuft).
- Konversationsbildung über Gmails eigene `threadId` statt über Message-IDs.
- Eingehender IMAP-Flag-Abgleich über den IDLE-Strom.
- Oberfläche für verschachtelte Label.
- Ein Endpunkt zur JWT-Ausgabe für eine eigene App.
