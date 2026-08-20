<!-- translated-from: features/appearance.md sha1:a1b8d3fb220de4397af2ae30aac351ee3a0451da -->

# Darstellung

**Einstellungen → Darstellung** entscheidet, wie plMail für dich aussieht und für sonst
niemanden. Jedes Bedienelement wirkt, sobald du es anfasst, und speichert sich selbst — einen
Speichern-Knopf gibt es auf dieser Seite nicht.

![Dunkler Modus](../screenshots/inbox-dark.png)

## Die zwei Achsen

Das Theme wählt die Palette; das Layout wählt, wie sie aufgetragen wird. Sie sind voneinander
unabhängig, und deshalb sind es zwei Bedienelemente und nicht eine lange Liste.

**Theme** bietet **System**, **Hell**, **Papier**, **Dunkel**, **Nord**, **Dämmerung** und
**Solar**, jedes als Farbtupfer aus seinen eigenen Farben. Ein neues Konto startet auf
**Papier** und nicht auf System: „folge dem Betriebssystem“ löst sich zu schlichtem Weiß oder
schlichtem Dunkel auf, je nachdem, was der Rechner gerade bevorzugt, und das sind hier die
beiden am wenigsten durchdachten Paletten. System bleibt für alle da, die das Betriebssystem
entscheiden lassen wollen, und es wird im Browser aufgelöst, damit die Seite beim Laden nie
kurz das falsche zeigt.

**Layout** ist **Flach** oder **Kacheln**. Flach setzt Kopf- und Seitenleiste direkt auf den
Hintergrund und lässt nur den Hauptbereich als Karte stehen; Kacheln lässt jede Fläche als
eigene Karte schweben. Eines davon zu wählen setzt die Regler darunter auf die Zahlen dieses
Layouts, damit die beiden nie einen Zustand beschreiben, den keines der Layouts hervorbringen
würde. Flach ist die Vorgabe.

## Das Logo

Das „pl"-Zeichen hat zweiunddreißig Farbstimmungen — einfarbige, Zweiklänge, Tinte mit einem
farbigen Schwung und Verläufe, die über die Striche laufen — und unter **Logo** suchst du deine
aus. Die Wahl folgt dir überallhin, wo das Zeichen auftaucht: Die Kopfleiste trägt sie sofort,
und auch das Tab-Symbol wird in deiner Farbstimmung ausgeliefert, der Browser-Tab passt also zu
der Seite, die er öffnet. Ein neues Konto beginnt mit **Beere**, dem hauseigenen Verlauf. Dunkle
Themes bekommen von jeder Farbstimmung die Striche für dunkle Oberflächen — ein tintendunkles
Zeichen verschwindet so nie in einer dunklen Kopfleiste.

## Die Live-Vorschau

Neben den Bedienelementen steht eine zweite Karte, die eine Beispiel-Seitenleiste, eine
Nachrichtenliste und einen Lesebereich in genau dem zeigt, was du gerade gewählt hast. Jede
Änderung landet dort, während du sie machst. Nichts davon ist deine Post — die Zeilen sind
erfunden, und die Vorschau lädt nie eine Nachricht.

Die Grenze zwischen den beiden Karten lässt sich ziehen, von **240** bis **900** Pixeln, und wo du
sie stehen lässt, wird an deinem Konto gemerkt und nicht am Browser. Die Seite öffnet sich beim
nächsten Besuch und am nächsten Rechner also in deiner Breite. Auch die Pfeiltasten bewegen sie, und
ein Doppelklick stellt sie auf ihren Standard von 304 zurück.

Das Maximum ist eine Obergrenze und kein Versprechen. Die Karte mit den Bedienelementen wird nicht
unter ihre eigene Untergrenze gedrückt, die Vorschau wächst also in das hinein, was das Fenster
übrig hat, und hört auf einem schmalen früher auf — bei 1280 Pixeln erreicht sie etwa 374, wie weit
du auch ziehst.

Auf einem schmalen Bildschirm steht die Vorschau gar nicht erst daneben. **Vorschau anzeigen** im
Kopf der Einstellungskarte blendet sie stattdessen darüber ein und lässt jedes Bedienelement dort,
wo es war; **Vorschau ausblenden** räumt sie wieder weg. Dieser Zustand wird bewusst *nicht*
gemerkt: Er startet bei jedem Laden geschlossen, denn er ist ein Blick und keine Entscheidung
darüber, wie die Seite geformt sein soll.

## Was eine Listenzeile zeigt

**Nachrichtenliste** entscheidet, wie viel jede Zeile der Mailliste trägt. Jede Vorgabe ist das, was
die Liste schon vor diesen Bedienelementen tat — es bewegt sich also nichts, bis du etwas änderst.

| Bedienelement | Was es tut |
|---|---|
| **Konto-Ecke** | Das farbige Dreieck unten links in einer Zeile, das sagt, über welches Konto die Nachricht eingegangen ist. Standardmäßig an; gezeichnet wird es ohnehin nur in einer Liste, die mehrere Konten mischt |
| **Absenderkreise** | Der farbige Kreis mit der Initiale des Absenders — siehe den Fallstrick unten, dieser hier verschwindet nicht |
| **Vorschauzeilen** | **Keine**, **Eine Zeile** oder **Zwei Zeilen** aus dem Nachrichtentext unter dem Betreff |
| **Ungelesene Zeilen** | **Dezent**, **Standard** oder **Stark** — wie laut die Liste sagt, dass eine Zeile ungelesen ist |

**Ungelesene Zeilen** ändert den Farbton hinter der Zeile und den Akzentbalken daneben, und sonst
nichts. Der fette Absender und der fette Betreff bleiben bei jeder Einstellung, und zwar mit Absicht:
Fettschrift ist das Signal, das eine farbenblinde Leserin, eine lichtdurchlässige Fläche über einem
Foto und einen Ausdruck übersteht, und das ist es nicht wert, eingetauscht zu werden. Dezent nimmt
den Farbton ganz weg, Stark vertieft ihn und setzt einen Balken an die vordere Kante der Zeile.

Zwei Zeilen sind die Obergrenze, weil die Zeile nur für zwei Platz hat, und die zweite wird nur im
gestapelten Layout gezeichnet — auf einem breiten Bildschirm teilen sich Betreff und Vorschau
absichtlich eine Zeile, eine zweite würde den Betreff dort aus seiner eigenen Zeile schieben.

## Typografie

**Schriftart** bietet **System**, **Grotesk**, **Serif** und **Dickengleich**. Jede davon ist ein
Stapel aus Schriften, die der Rechner ohnehin schon hat: plMail liefert keine Webschrift mit und holt
keine nach, die Auswahl funktioniert also auf einer Installation ohne jede Verbindung nach außen
genauso, und ein Wechsel kostet keinen Download. System ist die Vorgabe und sieht auf dem Gerät, auf
dem du das liest, heimisch aus; die anderen drei sind eine Vorliebe und keine Verbesserung.

**Textgröße** skaliert die gesamte Oberfläche zwischen **0,875** und **1,25**. Beide Enden sind dort,
wo die App tatsächlich geöffnet und angeschaut wurde, und nicht dort, wo eine runde Zahl lag — am
kleinen Ende wird nichts abgeschnitten und am großen läuft nichts über, auch wenn die
Einstellungsnavigation oben herum auf mehr Zeilen umbricht.

**Das Verfassen-Fenster liegt absichtlich außerhalb davon.** Der Editor behält seine eigene Schrift
und Größe, was du hier auch einstellst, denn eine im Verfassen-Fenster gewählte Größe ist
*Formatierung* je Nachricht: Sie wird in das HTML der Nachricht geschrieben und geht an die
Empfängerin hinaus. Wer eine Nachricht schreibt, muss sehen, was er verschickt, und nicht, was er auf
dieser Seite eingestellt hat.

## Bewegung

**Bewegung** ist **Voll**, **Dezent** oder **Keine** und legt fest, wie stark sich die Oberfläche
bewegt, wenn etwas erscheint — eine Nachricht, die in der Liste ankommt, das Schreibfenster, das
aufgeht, ein Menü, das herunterklappt.

- **Voll** — Dinge kommen von irgendwoher und setzen sich. Fast alles ist innerhalb einer
  Viertelsekunde erledigt. Die Nachrichtenliste ist die Ausnahme, gleich doppelt, und darum gehen die
  beiden folgenden Abschnitte.
- **Dezent** — dieselben Hinweise, nur als Einblendung: Nichts bewegt sich, nichts verschiebt sich.
  Etwas schneller als Voll, denn ohne Weg gibt es für das Auge weniger zu verfolgen, und dieselbe
  Dauer fängt an, sich wie eine Verzögerung anzufühlen.
- **Keine** — genau das, was plMail getan hat, bevor es das alles gab.

Einen Ordner öffnen, suchen oder umblättern ist die andere Ausnahme, und sie hat die umgekehrte
Form. Jede Zeile ist in etwa zwei Bildern an ihrem Platz — viel zu schnell, um ihr zuzusehen — aber
sie tun es nacheinander. Was du siehst, ist also eine Welle, die durch die Liste läuft, und nicht
eine einzelne Zeile, die sich bewegt. Die Liste selbst animiert nicht: Ein graues Rechteck, das
einblendet, sagt dir, dass sich ein Rechteck geändert hat. Gedeckelt ist das bei acht Zeilen, also
sind eine Liste mit fünfzig und eine mit sechs nach etwa einer Sechstelsekunde fertig.

### Neue Post ist die Ausnahme

Eines dauert deutlich länger als alles andere, und das ist Absicht: Eine Unterhaltung, die gerade
wirklich angekommen ist, fällt von oben in die Liste, und die Zeilen darunter rücken nach, um ihr
Platz zu machen. Die ganze Geste dauert etwa achthundert Millisekunden — ein Vielfaches von allem
anderen in plMail.

Bezahlbar ist das, weil es *selten* ist. Die Liste wird ständig neu gezeichnet, nach jedem Stern,
jedem Archivieren, jeder Sammelaktion und jedem Abgleich, und nichts davon spielt diese Geste. Nur
Post, die noch nie auf deinem Bildschirm war, tut das — bei einem normalen Postfach ein paar Mal in
der Stunde.

Bei **Dezent** fällt das auf dieselbe kurze Einblendung zusammen wie alles andere: kein Fallen, kein
Weg, kein Warten.

### Das Einzige, was es kostet

Neue Post braucht etwa eine halbe Sekunde, bis sie liegt, und nach etwas, das sich bewegt, kann man
danebengreifen. Die Zeile behält die ganze Zeit ihre volle Breite und ihre eigene Zeile, ein Klick in
die Mitte tut also, wonach er aussieht — aber das Auswahlkästchen ganz links und die Aktionen ganz
rechts sind unterwegs und bis zum Stillstand nicht ganz dort, wo sie sein werden.

Nichts wird dabei eingefroren und kein Klick weggeworfen. Es ist nur möglich, in dieser halben
Sekunde ein Stück leere Liste zu treffen statt das Bedienelement, das gerade dorthin unterwegs ist.
Beim Öffnen eines Ordners gibt es dieses Problem nicht: Das ist vorbei, bevor eine Hand sich bewegt
hat.

Wenn dich das stört, ist die Einstellung oben die Antwort, und genau dafür gibt es sie: **Dezent**
behält die Hinweise und nimmt jeden Pixel Weg heraus, **Keine** nimmt die Animation heraus.

**Verlangt dein System weniger Bewegung, gewinnt das** — egal, was hier steht, und ohne
Rückfrage. Wer seinem Rechner gesagt hat, dass Bewegung ihm schlecht bekommt, hat plMail nicht nach
seiner Meinung gefragt.

## Dichte, und wie ein Bereich seine eigene bekommt

**Dichte** ist **Komfortabel**, **Gemütlich** oder **Kompakt** und setzt Zeilenhöhe und Abstände in
der ganzen App. Darunter können **Seitenleiste**, **Nachrichtenliste** und **Lesebereich** diese
globale Wahl jeweils **übernehmen** — was sie alle tun, bis du etwas anderes sagst — oder sich eine
der drei selbst nehmen.

Die Dichte ist die einzige Einstellung, die so funktioniert, und der Grund ist baulich und kein
Mangel an Bedienelementen: Nachrichtenliste und Lesebereich sind *eine* gemalte Fläche, die sich
Hintergrund, Weichzeichnen und Rahmen teilen. Deckkraft oder Eckenradius können sich zwischen ihnen
also nicht unterscheiden, ohne diese Fläche zu zerteilen. Die Dichte kann es, weil sie Innenabstand
in Zeilen ist und keine Eigenschaft der Fläche, auf der die Zeilen sitzen.

Auf einem Berührungsgerät hält jeder Bereich seine komfortable Zeilenhöhe, welche Dichte du auch
wählst — **Kompakt** auf dem Telefon kostet dich also nie Fläche zum Antippen.

## Die übrigen Bedienelemente

| Abschnitt | Was er einstellt |
|---|---|
| **Hauptbereich** | Ein Hintergrundfarbton und eine Deckkraft für den Inhaltsbereich allein, oder **An Glas-Deckkraft angleichen**, um den Flächen zu folgen |
| **Glas** | **Deckkraft**, **Weichzeichnen**, **Eckenradius** und **Hintergrundabdunklung** — wie viel vom Hintergrund durch deine Flächen scheint |
| **Text** | Textfarbe sowie **Gedämpft** und **Sehr blass**, oder **Automatisch ableiten**, damit diese beiden aus der Hauptfarbe errechnet werden |
| **Akzentfarbe** | Die eine Signalfarbe, als Hexwert |
| **Hintergrund** | **Theme-Standard**, eine einfarbige Fläche, eines von acht mitgelieferten Bildern, oder **Bild hochladen** |

Jedes Zahlen-Bedienelement ist begrenzt, ein von außen getippter oder importierter Wert wird
also gekappt statt übernommen: Deckkraft zwischen 0,15 und 1, Weichzeichnen zwischen 0 und 60
Pixeln, Eckenradius zwischen 0 und 2 rem, Hintergrundabdunklung zwischen 0 und 0,7 und die
Textgröße zwischen 0,875 und 1,25.

Die Akzentfarbe muss ein sechsstelliger Hexwert mit führendem `#` sein. Alles andere fällt auf
die Vorgabe zurück, statt gespeichert zu werden, und dasselbe gilt für die drei Textfarben und
den Farbton des Hauptbereichs, die dann „nicht gesetzt“ sind.

## Hintergründe

Vier Arten: der **des Themes**, eine **einfarbige Fläche** deiner Wahl, eines von **acht
mitgelieferten Bildern** oder ein **eigener Upload**.

**Bild hochladen** nimmt JPEG, PNG und WebP. Die Datei wird je Benutzer gespeichert, und ein
neues Bild hochzuladen löscht das vorherige — eine Galerie früherer Hintergründe gibt es nicht.

Alle vier wirken in dem Moment, in dem du sie wählst, und sie wirken genau so, wie ein Neuladen
wirken würde. Es gibt keinen Zwischenschritt, in dem die Einstellung gespeichert ist und der
Bildschirm noch hinterherhinkt.

Etwas anderes als den Hintergrund des Themes zu wählen hebt die Untergrenze der Flächendeckkraft auf
0,45, was der Regler auch sagt, sowohl bei den Flächen als auch im Hauptbereich. Darunter hört
Text auf einem Foto auf, lesbar zu sein, und eine lesbare Oberfläche ist mehr wert als der
letzte Rest Transparenz. Diese Untergrenze wandert mit dem Hintergrund mit: Zurück zum
Theme-Standard löst sie sofort und nicht erst beim nächsten Neuladen.

## Export und Import

**Theme exportieren** lädt `plmail-theme.json` herunter. **Theme importieren** nimmt eine
solche Datei zurück. **Auf Standard zurücksetzen** stellt alles dorthin, wo ein neues Konto
beginnt.

Der Export trägt die Version, das Theme, das Layout, den Akzent, alle vier Glas-Zahlen, die
Dichte und die drei bereichseigenen Dichten, die Einstellungen der Nachrichtenliste, Schriftart und
Textgröße, die Hintergrundwahl, die Textfarben und die Einstellungen des Hauptbereichs. Er trägt
bewusst **nicht** dein hochgeladenes Hintergrundbild: Ein Dateiname bedeutet auf der
Installation von jemand anderem nichts, ein eigener Hintergrund wird also als **Theme-Standard**
exportiert.

Die Breite der Vorschau trägt er ebenfalls nicht. Sie ist eine Vorliebe dazu, wie diese
Einstellungsseite geformt ist, und kein Teil davon, wie die App aussieht — das Theme von jemand
anderem zu importieren lässt deine Vorschau also genau dort, wo du sie gelassen hast.

Der Import prüft die Version und lehnt eine Datei ab, die nicht Version 1 ist. Alles in den
Daten, was plMail nicht als gültigen Wert kennt, wird ignoriert statt gespeichert, und ein
Layout in der Datei wird vor den einzelnen Zahlen angewendet, damit ein Export, der geschrieben
wurde, bevor es ein bestimmtes Bedienelement gab, trotzdem irgendwo Sinnvollem landet.

## Sprache

**Einstellungen → Allgemein → Sprache** legt fest, in welcher Sprache die Oberfläche angezeigt
wird. Deine Mail bleibt genau so, wie sie geschrieben wurde — es wird nichts übersetzt und
nichts umgeschrieben.

plMail liefert **English**, **Deutsch** und **Pirate English** mit. Eine Änderung lädt die
Seite neu, statt sie nachzubessern, denn jede Zeichenkette auf dem Bildschirm muss neu
gerendert werden.

Derselbe Abschnitt trägt die **Zeitzone**, die über die Uhrzeiten und Daten entscheidet, die
dir angezeigt werden. Auch sie schreibt nichts um — derselbe Zeitpunkt wird schlicht auf deiner
Uhr gelesen — und sie darf auf dem Server-Standard stehen bleiben.

Darunter entscheidet das **Uhrzeitformat**, ob diese Zeiten im 12- oder im 24-Stunden-Format
geschrieben werden: `2:30 pm` oder `14:30`. Es gilt überall dort, wo eine Uhrzeit auftaucht — in
der Mailliste, in einem Thread, auf einem Kalender-Chip, in der Agenda, an der Stundenachse des
Tagesrasters —, denn eine Einstellung, die an den meisten Stellen greift und an einer nicht, liest
sich als Fehler und nicht als Wahlmöglichkeit.

Der Standard ist **Der Sprache folgen**, und darauf steht anfangs jede: Deutsch liest 14:30,
Englisch liest 2:30 pm. Das ist ein echter Zustand und kein getarnter Wert — lässt du ihn stehen,
wandert das Format mit der Sprache mit. Wer eines von beiden ausdrücklich wählt, legt es fest,
gleich welche Sprache später eingestellt wird.

## Wo du weiterliest

- [Mail](mail.md) — die Listen und Flächen, die diese Einstellungen anmalen.
- [Andere Clients](clients.md) — eine App eines Drittanbieters hat ihre eigene Darstellung;
  hier geht es allein um die Weboberfläche.
- [Client development](../CLIENT_DEVELOPMENT.md) — das Zwei-Achsen-Modell, seine semantischen
  Farbtoken und wie man sie nachbaut, für alle, die einen Client schreiben, der wie plMail
  aussehen soll.

## Fallstricke

**Ein eigener Hintergrund überlebt einen Export nicht.** Er wird absichtlich ausgelassen, denn
die Datei liegt auf dieser Installation, und ein Dateiname würde sich nirgendwo sonst auflösen.
Der Import deines eigenen Exports lässt dich deshalb auf dem Theme-Standard, bis du das Bild
erneut hochlädst.

**Der Deckkraftregler hört unter einem Foto auf, etwas zu bedeuten.** Alles unter 0,45 wird
stillschweigend angehoben, solange ein Hintergrund verwendet wird, der nicht vom Theme kommt — eine
einfarbige Fläche eingeschlossen. Der Regler bewegt sich weiterhin; die Darstellung folgt ihm nicht
bis nach unten.

**Absenderkreise lassen sich nicht abschalten, nur leeren.** Dieser Kreis *ist* das Auswahlkästchen
der Zeile: Das eigentliche Eingabefeld sitzt dahinter, und jede Sammelaktion in der Werkzeugleiste
liest es. Ihn auszuschalten übermalt die Identität — Farbe und Buchstabe gehen — und lässt einen
schlichten umrandeten Kreis an derselben Stelle stehen, sodass Auswahlfläche und Geometrie der Zeile
genau dort bleiben, wo sie waren. Eine Zeile ganz ohne Kreis wäre eine Zeile, die du nicht auswählen
kannst.

**Die Konto-Ecke taucht nur in einer Liste auf, die Konten mischt.** Auf einer Installation mit einem
einzigen Konto wurde sie nie gezeichnet, und sie einzuschalten lässt sie nicht erscheinen. Es ist
nichts kaputt; es gibt schlicht keine Mehrdeutigkeit, die sie auflösen könnte.

**Die Textgröße erreicht das Verfassen-Fenster nicht.** Der Editor behält mit Absicht seine eigene
Schrift und Größe, denn diese Größe ist Formatierung, die im versendeten HTML landet. Die Oberfläche
größer zu stellen macht die Nachricht, die du schreibst, nicht größer — und das soll es auch nicht.

**Nur die Dichte darf sich zwischen Bereichen unterscheiden.** Seitenleiste, Nachrichtenliste und
Lesebereich bekommen je ihre eigene Zeilenhöhe; Deckkraft, Weichzeichnen, Radius und der Rest bleiben
global. Liste und Lesebereich sind eine gemalte Fläche, es gibt für diese also gar keinen Ort, an dem
sie sich unterscheiden könnten.

**Kompakt verkleinert die Zeilen auf dem Telefon nicht.** Auf einem Berührungsgerät hält jeder
Bereich seine komfortable Höhe, die Einstellung sieht dort also aus, als hätte sie nichts getan. Hat
sie — auf jedem Zeigegerät, von dem aus du dasselbe Konto benutzt.

**Die Vorschau erreicht nicht immer 900.** Sie wächst in das hinein, was das Fenster übrig hat, und
die Bedienelemente daneben haben eine Untergrenze, die sie nicht unterschreiten. In einem 1280 Pixel
breiten Fenster hört die Grenze bei etwa 374 auf. Dass sie nicht weiter geht, ist die Kappung bei der
Arbeit und kein klemmender Griff.

**Die Breite der Vorschau wird gemerkt, ob sie auf dem Telefon offen ist, nicht.** Die Breite folgt
deinem Konto auf jedes Gerät. **Vorschau anzeigen** auf einem schmalen Bildschirm startet jedes Mal
geschlossen, wenn du die Seite öffnest.

**Ein Layout zu wählen überschreibt die Glas-Regler.** Genau das *ist* das Layout-Bedienelement
— eine Voreinstellung für diese Zahlen. Setze erst das Layout, dann die Zahlen, nicht
andersherum.

**Ein Hexwert ohne sechs Stellen ist kein Fehler, sondern ein Rückfall.** Der Akzent kehrt
stillschweigend zur Vorgabe zurück und die drei Textfarben werden stillschweigend „nicht
gesetzt“ — ein Tippfehler sieht also aus, als hätte das Bedienelement keine Wirkung.

**Der Import lehnt alles ab, was nicht Version 1 ist.** Es gibt keine Migration älterer oder
neuerer Dateien; die Antwort ist eine Ablehnung und kein teilweises Anwenden.

**Die Darstellung gilt je Benutzer, nicht je Gerät.** Es gibt keinen Weg, auf dem Telefon das
dunkle und auf dem Schreibtisch das helle Theme zu haben, außer **System** zu wählen und jedes
Gerät selbst entscheiden zu lassen.
