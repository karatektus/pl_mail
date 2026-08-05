<!-- translated-from: features/calendar.md sha1:4eeb04dab2b3e02ce739e62b9f9749e82e656a35 -->

# Kalender

plMail führt den Kalender neben der Mail statt in einer eigenen Anwendung, weil das meiste, was
auf einem Kalender landet, ohnehin zuerst als E-Mail ankommt. Diese Seite beschreibt den Kalender
selbst: die vier Ansichten, die zwei Gestalten, die er auf dem Bildschirm annimmt, und alles, was
der Termin-Editor kann. Einladungen und Termine, die aus Nachrichten gelesen wurden, stehen in
[Einladungen und Termine aus E-Mails](calendar-invitations.md), Erinnerungen in
[Erinnerungen](calendar-alerts.md), das Spiegeln fremder Kalender in
[Verbundene Kalender](calendar-sync.md).

![Der Kalender](../screenshots/calendar.png)

## Die zwei Gestalten

Der Kalender ist eine Funktion, die auf zwei Arten gezeichnet wird, und welche davon du bekommst,
hängt davon ab, von wo aus du ihn geöffnet hast, und nicht an einer Einstellung.

**Angedockt neben der Mail.** Die Kalenderschaltfläche in der Kopfleiste schiebt eine Fläche neben
die Nachrichtenliste. Sie ist ein Geschwister der Mail-Fläche und keine Überlagerung, also teilen
sich die beiden die Zeile — der Griff dazwischen verschiebt die Grenze, und die eine wächst genau so
viel, wie die andere schrumpft. Zieh ihn, oder setz den Fokus darauf und nimm die Pfeiltasten; ein
Doppelklick stellt die alte Breite wieder her. Die Breite wird pro Benutzer gemerkt, zwischen 320 und
900 Pixeln, voreingestellt 380, und sie wird serverseitig in die Seite geschrieben, damit die Fläche
schon beim ersten Zeichnen die richtige Breite hat und nicht springt, sobald der Browser nachzieht.

Unter 1024 Pixeln Breite trägt die Zeile keine Seitenleiste, keine lesbare Nachrichtenliste und
keinen lesbaren Kalender mehr auf einmal, also nimmt die Fläche die ganze Zeile und die Mail tritt
zur Seite. Es ist dieselbe Fläche und derselbe Schalter — schließt du sie, steht die Mail wieder
genau so da wie zuvor, ohne dass dazwischen navigiert worden wäre. Unter 768 Pixeln gibt es gar
keine Fläche mehr, und der Kalender ist eine eigene Seite.

**Als eigene Seite.** `/calendar` ersetzt die Mail-Ansicht vollständig. Das ist es, was ein Telefon
bekommt, und was jeder bekommt, der direkt dorthin navigiert oder einem Link folgt.

## Die vier Ansichten

Tag, Woche, Monat und Agenda. Eine Ansicht ist Teil der URL — `/calendar/week/2026-08-05` — und
nicht etwas, das sich der Browser merkt. Damit ist jede Ansicht jedes Datums als Lesezeichen
brauchbar, die Zurück-Schaltfläche des Browsers tut das Naheliegende, und der Zeitraum dahinter
bleibt eine einzige indizierte Abfrage.

| Ansicht | Was sie abdeckt |
|---|---|
| **Tag** | Den einen Tag. |
| **Woche** | Montag bis Sonntag. |
| **Monat** | Sechs Wochen, beginnend am Montag der Woche, in die der Erste fällt — die Tage, die aus den Nachbarmonaten hereinragen, werden also gezeigt, wie ein Monatsraster das immer tut. |
| **Agenda** | Eine fortlaufende Liste der nächsten 30 Tage, die die leere Zeit zwischen den Einträgen überspringt. |

Die Werkzeugleiste über dem Raster trägt **Zurück**, **Heute** und **Weiter**, das Datum oder den
Zeitraum, den du gerade ansiehst, den Ansichtsumschalter und eine Schaltfläche **Neuer Termin**.
Zurück und Weiter springen in der Monatsansicht um einen Monat, in der Wochenansicht um sieben Tage
und überall sonst um einen Tag — die Agenda eingeschlossen, denn sie ist eine fortlaufende Liste und
keine Seite.

Die volle Seite öffnet mit **Woche**. Die angedockte Fläche öffnet mit **Agenda** — sie ist 380
Pixel einer geteilten Zeile, und ein Monatsraster in dieser Breite besteht überwiegend aus leeren
Zellen. Die Fläche bietet trotzdem alle vier Ansichten an, als Symbole statt als Wörter, denn die
Fassung, die den Umschalter ganz weggelassen hatte, ließ die Fläche nichts als ihre Agenda zeigen.

In der Monatsansicht sagt ein Tag, der mehr Einträge trägt, als hineinpassen, **N weitere**, was
diesen Tag öffnet.

### Das Zeitraster, und warum nur die Seite es bekommt

Auf der vollen Seite werden Tag und Woche als Zeitraster gezeichnet: die Stunden links untereinander,
und jeder Termin dort, wo er tatsächlich beginnt, und so lang, wie er tatsächlich dauert. Termine,
die sich überschneiden, werden in Spuren gelegt, sodass drei gleichzeitige Dinge drei schmalere
Blöcke nebeneinander sind — und die Zahl der Spuren bleibt über eine ununterbrochene Kette von
Überschneidungen hinweg gleich, statt sich von Block zu Block zu ändern.

Die angedockte Fläche behält für dieselben zwei Ansichten die ältere Spaltenliste, und das ist eine
Entscheidung und kein Versäumnis. Sieben Spalten platzierter Blöcke hinter einer Zeitleiste brauchen
ungefähr eine Bildschirmbreite, um lesbar zu sein; die Fläche hat 380 Pixel einer Zeile, die sie sich
mit deiner Mail teilt, und ein Raster wäre dort sieben Splitter unter einer Achse, die niemand lesen
könnte. Die Wahl trifft der Server danach, ob er die Fläche oder die Seite zeichnet — und nicht eine
Media Query, denn die Fläche ist auch auf einem 2560 Pixel breiten Monitor schmal, und CSS, das
beides zeichnete und eines davon versteckte, lieferte die ganze Drag-and-drop-Maschinerie an eine
Fläche aus, die nichts damit anfangen kann.

Monat und Agenda haben in keiner der beiden Gestalten eine Zeitachse. Eine Monatszelle ist ein paar
Quadratzentimeter groß und hat keinen Platz zu sagen, wo im Tag etwas liegt; und der ganze Wert einer
Agenda besteht darin, die leere Zeit zu überspringen — also genau das, was eine Rasterachse zeichnen
würde.

Drei Dinge über das Raster sind es wert, gewusst zu werden:

- **Das Ganztagsband ist immer da**, auch in einer Woche, in der nichts steht, damit das Raster beim
  Blättern nicht um eine Zeile springt. Es ist bewusst kein Ablageziel: einen zeitgebundenen Termin
  hineinzuziehen hieße „mach das ganztägig“, und das ist eine Änderung der Art und nicht der Zeit —
  und Arten werden im Editor geändert.
- **Es öffnet auf den Arbeitstag gescrollt** statt auf Mitternacht, was ein Scrollen kostet, um die
  Nacht zu erreichen, statt eines Scrollens, um alles zu erreichen.
- **Ein Klick auf leere Fläche legt keinen Termin an.** Jede Tagesüberschrift trägt ihre eigene
  Schaltfläche **+**, die auch eine echte Station für die Tabulatortaste ist. Ein Klick auf den
  Hintergrund müsste bei jedem Loslassen vom Ende eines Ziehens unterschieden werden, und
  Ziehen-zum-Anlegen ist ein eigenes Stück Arbeit.

Eine Sieben-Tage-Woche wird unterhalb von etwa 640 Pixeln eng — jede Spalte landet bei ungefähr
fünfzig Pixeln, und ein Block sagt seine Farbe und sonst wenig. Die Wege, die ein Telefon tatsächlich
in den Kalender nimmt, führen daran vorbei: die Fläche, die aus der Mail heraus öffnet, behält die
Agenda, und die Tagesansicht ist in der Werkzeugleiste einen Tipper entfernt und eine einzige Spalte
über die volle Breite.

## Einen Termin anlegen und bearbeiten

**Neuer Termin** in der Werkzeugleiste, das **+** an einer Tagesüberschrift und ein Klick auf einen
vorhandenen Termin öffnen alle denselben Editor. Er ist ein Dialog über der Seite, auf der du gerade
warst — der Kalenderseite oder einer Mail-Ansicht mit dem angedockten Kalender daneben — und
Speichern bringt dich dorthin zurück, statt dich in den Kalender zu ziehen.

Die Felder sind **Titel**, **Beginn**, **Ende**, **Ganztägig**, **Kalender**, **Wiederholung**,
**Ort**, **Beschreibung** und **Erinnerungen**, dazu **Ändern**, sobald sich der Termin wiederholt.
Ein Termin, der mit leerem Titel gespeichert wird, heißt *Termin ohne Titel*, statt abgelehnt zu
werden. Ein Ende vor dem Beginn wird abgelehnt: *Diese Zeiten gehen nicht — das Ende muss nach dem
Beginn liegen.*

**Löschen** steht neben **Speichern** im selben Formular. Bei einem Termin, den plMail aus einer
Nachricht gelesen hat, gibt es zusätzlich **Kein Termin**, was etwas anderes ist als Löschen — siehe
[Einladungen und Termine aus E-Mails](calendar-invitations.md). **.ics herunterladen** nimmt den
Termin als Kalenderdatei mit.

### Sich wiederholende Termine

**Wiederholung** bietet *Wiederholt sich nicht*, *Täglich*, *Wöchentlich*, *Monatlich* und
*Jährlich* an. Kunstvollere Regeln — jeden zweiten Dienstag, nur werktags — schreibt plMail nicht,
aber es trägt sie getreu mit, wenn sie von irgendwo kommen, wo man sie schreiben kann: aus einer
Einladung, aus einer importierten `.ics` oder aus einem verbundenen Kalender. Wird ein solcher Termin
im Editor gespeichert, ohne dass das Auswahlfeld angefasst wurde, plättet das die Regel nicht.

Ein Serientermin wird einmal gespeichert, als Regel, und die einzelnen Termininstanzen werden daraus
gezogen. Sie werden in ein begrenztes Fenster um heute herum geschrieben — ein Jahr zurück und zwei
Jahre voraus — mit einer harten Obergrenze von 1000 Termininstanzen je Termin, damit eine Regel, die
„jede Sekunde, für immer“ sagt, endlich statt tödlich ist. Die Ausrechnung geschieht in der Zeitzone
des Termins, ein Standup um 09:00 Berliner Zeit liegt also im November wie im Juli um 09:00 Berliner
Zeit.

### Diese Termininstanz, oder alle

Wenn du den Editor aus einer Termininstanz eines Serientermins heraus öffnest, wird er *auf dieser
Termininstanz* geöffnet: Die gezeigten Zeiten sind die, die diese Instanz tatsächlich hat,
einschließlich jeder Verschiebung, die sie schon hinter sich hat. **Ändern** bietet dann zwei
Antworten an.

**Diesen Termin** ändert nur die geöffnete Termininstanz. Der Serie und jeder anderen Instanz
geschieht nichts — die Änderung wird als Korrektur gegen die Serie abgelegt, geschlüsselt danach, wo
die Regel diese Instanz ursprünglich hingelegt hat, sodass ein zweites Bearbeiten derselben Instanz
die Korrektur aktualisiert, statt eine zweite danebenzustellen.

**Alle Termine** ändert die Serie. Das ist die Antwort, die überrascht, deshalb lohnt es sich zu
sagen, was sie mit den Zeiten tut: Weil der Editor die Zeiten *dieser Termininstanz* zeigte, werden
die Felder als die Änderung gelesen, die du an dieser Instanz vorgenommen hast, und dieselbe
Verschiebung und dieselbe neue Dauer werden auf die Serie angewendet. Eine wöchentliche Besprechung
aus ihrer fünften Instanz heraus umzubenennen, benennt also die Serie um und lässt sie dort
beginnen, wo sie immer begann; diese fünfte Instanz um eine halbe Stunde nach hinten zu schieben und
**Alle Termine** zu wählen, schiebt jede Instanz um eine halbe Stunde nach hinten. Die Alternative —
die Felder als die neuen absoluten Zeiten der Serie zu lesen — zöge die ganze Serie auf den Tag, den
du zufällig gerade ansiehst.

Aus der Art, wie eine Änderung an einer einzelnen Instanz gespeichert wird, folgen zwei Grenzen:

- **Erinnerungen gehören zum ganzen Termin.** Der Editor sagt das an genau der Stelle, an der du
  sonst erwarten würdest, dass die Auswahl für den Geltungsbereich greift: Ein Speichern mit
  *Diesen Termin* schreibt die Zeiten und den Titel für diese Termininstanz und lässt die
  Erinnerungen genau so, wie sie an der Serie waren.
- **Eine einzelne Termininstanz lässt sich nicht in einen Kalender legen, in dem sie nicht steht.**
  Einen neuen Kalender unter *Diesen Termin* anzuhaken, wird ganz abgelehnt, mit *Ein einzelner
  Termin lässt sich nicht allein in einen Kalender legen. Wähle „Alle Termine“, um diesen Termin in
  einen weiteren Kalender zu legen.* Es gibt keine Zeile, die eine einzelne Termininstanz anlegen
  könnte, das Erfüllen des Wunsches hieße also, aus den Zeiten dieser einen Instanz eine Serie zu
  erfinden.

Löschen funktioniert genauso. **Alle Termine** entfernt die Serie; **Diesen Termin** nimmt eine
Termininstanz heraus und lässt die Serie und jede andere Instanz unberührt.

## Ziehen und in der Länge ändern

Auf dem Zeitraster lässt sich ein Terminblock auf eine andere Zeit oder einen anderen Tag ziehen und
an seiner Unterkante in der Länge ändern. Änderungen rasten auf fünfzehn Minuten ein. Während des
Ziehens wird nichts geschrieben — die Vorschau wird im Browser gezeichnet und wieder verworfen, und
die Änderung wird beim Loslassen als ganz gewöhnliches Formular abgeschickt, sodass das Raster, das
du danach ansiehst, das vom Server gezeichnete ist. Eine fehlgeschlagene Anfrage kann keinen Block
dort stehen lassen, wo die Datenbank ihm widerspricht.

Die Tastatur leistet dasselbe: Setz den Fokus auf einen Block, halte dann **Alt** und verschieb ihn
mit den Pfeiltasten, **Alt+Umschalt** ändert seine Dauer, **Enter** speichert und **Escape** nimmt es
zurück. Jede Änderung wird angesagt, ein Screenreader hört also, wohin der Block gewandert ist.

Ein Serientermin stellt vor dem Abschicken in einem Dialog dieselbe Frage nach dieser einen Instanz
oder allen, die ein Ziehen sonst stillschweigend beantworten würde. Ein Termin in einem Kalender, der
keine Änderungen annimmt, lässt sich gar nicht erst ziehen und sagt das auch.

## Ein Termin in mehreren Kalendern

Der Bereich **Kalender** im Editor ist ein Ankreuzfeld je Kalender, der dir gehört, angehakt überall
dort, wo diese Besprechung schon steht. Die Liste führt jeden Kalender auf und nicht nur die, in
denen die Besprechung steht, sagt also zweierlei auf einmal:

- **Hak einen Kalender an, in dem er nicht steht**, und beim Speichern wird dort eine Kopie
  angelegt.
- **Nimm den Haken bei einem weg, in dem er steht**, und diese Kopie bleibt unangetastet — sie wird
  nicht gelöscht. Der Editor schreibt es aus: *„nimm den Haken weg, um diese Kopie unverändert zu
  lassen — sie unterscheidet sich dann von den anderen und erscheint als eigener Eintrag.“*

Das Zweite ist die Falle, und der Grund dahinter ist es wert, verstanden zu werden. Jede Kopie einer
Besprechung trägt dieselbe UID, weil eine UID die Besprechung bezeichnet und nicht die Zeile: Genau
das lässt eine Aktualisierung der Organisation, eine erneut importierte `.ics` und die eigene Kopie
eines Anbieters jede Kopie der Besprechung finden. Der Kalender fasst Kopien nur so lange zu einem
einzigen Eintrag zusammen, *wie sie sich einig sind* über die fünf Dinge, die man daran sehen kann:
Beginn, Ende, Titel, Ganztägigkeit und ob abgesagt wurde. Lässt du eine Kopie zur alten Zeit zurück,
ist sie sich nicht mehr einig, löst sich also heraus und wird als eigener Eintrag an ihrem eigenen
Tag gezeichnet. Das ist Absicht: Ein zusammengefasster Eintrag, der stillschweigend einen Sieger
kürte, versteckte eine echte Uneinigkeit hinter einem aufgeräumteren Bildschirm.

Ein zusammengefasster Eintrag zeigt die Farben aller Kalender, die er abdeckt, und sagt *In Arbeit,
Privat* in seiner Kurzinfo. Ein Klick darauf öffnet einen Editor, in dem diese Kalender bereits
angehakt sind.

Ein Kalender, der keine Änderungen annimmt — die Spiegelung eines veröffentlichten Feeds oder ein
geteilter Kalender, den du nur lesen darfst — erscheint in der Liste, gekennzeichnet mit *Dieser
Kalender nimmt keine Änderungen an*, und lässt sich nicht anhaken. Alle Haken wegzunehmen, wird
abgelehnt: *Nichts ausgewählt, also nichts geändert. Wähle mindestens einen Kalender.*

**Löschen liest dieselben Haken.** Die Kopien in angehakten Kalendern gehen, und der Rest bleibt
genau, wo er ist, die schreibgeschützten eingeschlossen. Ein angehakter Kalender, in dem die
Besprechung nie stand, hat nichts zu löschen und wird schlicht übersprungen.

Ein zusammengefasster Termin, der gezogen wird, hat keine Ankreuzfelder anzubieten und bedeutet
deshalb das, was der Editor voreingestellt meint: Jede beschreibbare Kopie zieht mit.

## Kalender verwalten

Unter **Einstellungen → Kalender** werden Kalender angelegt, umbenannt, umgefärbt, mit einer Zeitzone
versehen, aus den Ansichten ausgeblendet und zu dem ernannt, in dem neue Termine landen. Die Zeitzone
eines Kalenders ist die, in der er gelesen wird, und die, in der ein Termin ohne eigene Zone
angezeigt wird.

plMail legt zwei Arten von Kalender für dich an: einen Standardkalender je Benutzer und einen je
E-Mail-Konto, in den die Termine einsortiert werden, die in der Mail dieses Kontos gefunden wurden.
Keiner von beiden lässt sich löschen — ein Löschen hieße nur, dass er wieder angelegt wird, und die
Termine wären inzwischen weg. Kalender, die du selbst angelegt hast, und Spiegelungen entfernter
Kalender lassen sich löschen; das Löschen nimmt jeden Termin darin mit.

Einen Kalender auszublenden, blendet ihn überall aus, und zwar einheitlich: die Ansichten, die
Anzeige des Nächsten in der Kopfleiste und die Liste *Demnächst* lesen ausschließlich sichtbare
Kalender.

## Fallstricke

**Den Haken bei einem Kalender wegzunehmen, entfernt den Termin nicht daraus.** Es heißt „lass diese
Kopie in Ruhe“. Die Kopie bleibt, wo sie ist, ist sich mit den geänderten nicht mehr einig und
erscheint von da an als eigener Eintrag. Um eine Besprechung aus einem Kalender zu nehmen, hak diesen
Kalender an und drück **Löschen** — das Löschen wirkt auf genau die angehakten.

**„Alle Termine“ wendet deine Änderung als Verschiebung an, nicht als absolute Zeit.** Öffnest du die
fünfte Termininstanz einer wöchentlichen Besprechung, änderst den Beginn auf Donnerstag 14:00 und
wählst **Alle Termine**, wandert die gesamte Serie um diese Differenz. Wenn du nur diese eine Woche
meintest, wähl **Diesen Termin**.

**Eine Kopie, die in einem zweiten Kalender angelegt wird, beginnt als die schlichte Serie.** Sie
bekommt keine Teilnehmenden — eine Teilnehmerliste zu einem Anbieter zu schieben, ist die Art, wie
der Anbieter beschließt, die Einladung erneut an alle darin zu senden — und keine der Verschiebungen
einzelner Termininstanzen, die das Original bereits angesammelt hatte. Diese Instanzen bleiben, wo
sie im Original sind, und werden als eigene Einträge gezeichnet, bis du sie in beiden Kopien
verschiebst.

**Die angedockte Fläche zeichnet nie das Zeitraster, wie breit du sie auch ziehst.** Sie ist in jeder
Breite bewusst die Spaltenliste. Für Stunden an der Seite nimm die Kalenderseite.

**Der Editor öffnet nie innerhalb der Fläche.** Er wird stattdessen am oberen Rand der Seite
gezeichnet, weil sowohl die Kalender- als auch die Mail-Fläche einen Hintergrundfilter tragen, der
alles beschneidet, was innerhalb von ihnen positioniert wird. Das ist unsichtbar, solange es
funktioniert, und vollständig kaputt, sobald nicht.

**Erinnerungen fallen nicht unter die Auswahl des Geltungsbereichs.** Eine Erinnerung, die bei
ausgewähltem *Diesen Termin* gesetzt wird, gilt für die ganze Serie, und es gibt keine Möglichkeit,
einer einzelnen Termininstanz eine eigene Erinnerung zu geben.

**Eine Termininstanz jenseits des ausgeschriebenen Fensters gibt es noch nicht.** Termininstanzen
werden ein Jahr zurück und zwei Jahre voraus ab dem letzten Speichern des Termins geschrieben. Einem
Serientermin, der lange nicht angefasst wurde, können am fernen Ende deshalb die gezeichneten
Instanzen ausgehen; ein erneutes Speichern schreibt sie von heute an neu.

---

**Verwandt:** [Einladungen und Termine aus E-Mails](calendar-invitations.md) ·
[Erinnerungen](calendar-alerts.md) · [Verbundene Kalender](calendar-sync.md) ·
[Teilen und Buchen](calendar-sharing.md)

**Wie es funktioniert:** [Das Kalendermodell](../internals/calendar-model.md) —
das JSCalendar-Objekt hinter einem Termin, wie Termininstanzen ausgeschrieben werden und wie
Abweichungen gespeichert sind.
