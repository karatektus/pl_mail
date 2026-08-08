<!-- translated-from: features/calendar-sharing.md sha1:2c51faa4be0e13f0c009ee0e65d7f18715ffe626 -->

# Teilen und Buchen

Zwei Wege, jemanden ohne plMail-Konto einen Teil deines Kalenders sehen oder benutzen zu lassen: ein
**Freigabelink**, der ihm zeigt, was du zeigen willst, und eine **Buchungsseite**, auf der er eine
Stunde davon nehmen kann. Beide stehen unter **Einstellungen → Teilen**, und beide sind allein durch
ein Geheimnis in der URL abgesichert — keine Anmeldung, keine Sitzung, kein zweiter Faktor. Wer die
Adresse hat, ist das Publikum.

Damit ist die Adresse ein Zugangsmittel, und deshalb steht das Wichtigste auf dieser Seite ganz vorn.

## Die Adresse wird einmal gezeigt

Für einen Freigabelink wie für eine Buchungsseite speichert plMail nur einen Hash der Adresse, nie die
Adresse selbst. Ein Datenbankabzug, eine Sicherung auf fremdem Speicher oder ein Lesezugriff, der über
ein ganz anderes Loch gewonnen wurde, liefert deshalb keine funktionierenden URLs.

Was das kostet, sagt das Formular unumwunden, denn es ist das Erste, was Leute zu umgehen versuchen:

> Die Adresse wird einmal gezeigt, beim Anlegen. Sie wird nicht gespeichert und kann nicht erneut
> gezeigt werden — geht sie verloren, gib dem Link eine neue.

Also **kopiere sie auf der Stelle**, wenn du eine anlegst. Es gibt keinen Bildschirm, der sie erneut
zeigen könnte, weil nichts Gespeichertes sie rekonstruieren kann, und aus demselben Grund gibt es an
einer bestehenden Zeile keine Kopierschaltfläche.

**Neue Adresse geben** ist die Rettung, und es ist zugleich die Behebung einer abgeflossenen URL: Es
prägt eine neue Adresse, und die alte funktioniert sofort nicht mehr — *Neue Adresse. Die alte
funktioniert nicht mehr.* Das ist das Richtige für eine Adresse, die ohnehin abhandengekommen ist.

Einen Link oder eine Seite zu bearbeiten, ändert die Adresse **nicht**. Einzuschränken, was ein Link
preisgibt, oder die Stunden zu ändern, die eine Buchungsseite anbietet, darf keine URL zerbrechen, die
du bereits verschickt hast.

## Freigabelinks

**Neuer geteilter Link** fragt nach fünf Dingen.

**Wie du ihn nennst** — nur du siehst das; so findest du den Link wieder, um ihn zu ändern oder zu
widerrufen.

**Welche Kalender** der Link abdeckt. Ausdrücklich, Kalender für Kalender: nicht „alle“ und nicht „die
sichtbaren“, denn die Sichtbarkeit ist eine Einstellung der Seitenleiste, die du für dein eigenes
Lesen änderst, und ein Link, dessen Inhalt ihr folgte, gäbe einen Kalender in dem Moment preis, in dem
du ihn wieder einblendest.

**Was dieser Link preisgibt** — vier Ankreuzfelder: **Titel**, **Ort**, **Beschreibung** und **Wer
dabei ist**.

**Wie weit er reicht** — entweder **Ein rollendes Fenster ab heute**, mit einer Anzahl **Tage im
Voraus**, oder **Zwischen zwei Daten**, mit einem **Erster Tag** und einem **Letzter Tag**. Die beiden
sind wirklich verschiedene Dinge. „Hier ist meine Verfügbarkeit für die nächsten zwei Wochen“ muss mit
dem heutigen Tag mitwandern und so lange brauchbar bleiben, wie der Link lebt; „hier ist mein Kalender
für die Konferenz“ sind zwei feste Daten und darf nicht in die Wochen danach hineinkriechen. Ein
rollendes Fenster steht auf 14 Tagen und reicht höchstens 366.

Das Fenster wird immer in **deiner** Zeitzone aufgelöst, nie in der der lesenden Person. Zwei rollende
Wochen sind zwei Wochen deiner Tage, und ein fester Zeitraum benennt zwei Daten in deinem Kalender —
sonst deckte derselbe Link je nach Ort des Öffnens verschiedene Tage ab, und der letzte Tag eines
Konferenz-Links tauchte über der Datumsgrenze auf oder verschwände.

### Was jedes Ankreuzfeld tatsächlich tut

**Ohne einen einzigen Haken zeigt der Link nur, wann du belegt bist.** Belegt/Frei ist der Boden und
keine Wahlmöglichkeit — ein Link, der überhaupt nichts preisgäbe, hätte keinen Grund zu existieren.
Die lesende Person sieht deine Tage mit anonymen **Belegt**-Blöcken darauf, gekennzeichnet mit *Dieser
Link zeigt nur, wann der Kalender belegt ist — sonst nichts.*, und Tage, an denen nichts steht, sagen
*An diesem Tag nichts.*

Jedes Ankreuzfeld fügt genau eine Angabe darüber hinaus hinzu. Sie sind bewusst eine Menge und keine
Stufenleiter: Titel zu teilen, aber keine Orte, ist das, was du willst, wenn in deinem Kalender steht,
wo du wohnst, und zu teilen, wer dabei ist, aber nicht die Titel, ist das, was ein Team will, wenn das
Thema vertraulich ist und die Teilnahme nicht. Ein Schieberegler „nichts / etwas / alles“ könnte
keines von beiden ausdrücken.

Die Liste in den Einstellungen zeigt, was jeder Link preisgibt — *Zeigt Titel, Ort* oder *Nur
belegt/frei* —, damit ein Link, den du geöffnet hast, auch als geöffneter Link sichtbar ist.

### Die Privatheit am Termin ist eine Decke, die die Haken nicht anheben können

Jeder Termin hat eine Einstellung zur Privatheit, und sie gewinnt gegen den Link:

- Ein **privater** Termin bleibt ein reiner Belegt-Block, was die Haken des Links auch sagen. Das
  Formular sagt es: *Ein als privat markierter Termin bleibt ein reiner Belegt-Block, egal was hier
  angehakt ist.* Du hast gesagt „dass ich belegt bin, ist in Ordnung, das Thema nicht“, und kein
  Ankreuzfeld an einem Link darf das übergehen — der Link ist eine Entscheidung über ein Publikum, die
  Privatheit ist eine Entscheidung über eine Besprechung, und die engere muss gewinnen, sonst wird die
  weitere zu einem Weg, sie in großem Stil aufzuheben.
- Ein **geheimer** Termin erscheint überhaupt nicht — nicht einmal als Belegt-Block. Seine Existenz
  ist das Detail: Ein Block, der an einem Dienstagnachmittag auftaucht, ist genau das, worauf jemand,
  der den Link liest, reagieren würde.

Was das Zweite kostet, ist real und wird in Kauf genommen: Ein geteilter Kalender mit geheimen
Terminen behauptet, du seist zu Stunden frei, zu denen du es nicht bist, jemand kann also über einen
davon hinweg buchen. Das ist der Handel, um den das Wort „geheim“ bittet; ihn als belegt zu zeigen,
machte die Einstellung bedeutungsgleich mit „privat“.

Abgesagte Termine bleiben aus einem anderen Grund draußen — eine abgesagte Besprechung ist kein
Anspruch auf deine Zeit, sie drin zu lassen, sagte also „belegt“ zu einer Stunde, in der du frei bist.

Nichts davon ist eine Prüfung, die eine Vorlage durchführt. Die Seite wird aus einem Objekt gebaut, aus
dem alles, was der Link nicht preisgibt, bereits entfernt wurde, es gibt also keine Kurzinfo, kein
Datenattribut und keine `.ics`, die einen Titel tragen *könnte*, den der Link nicht freigegeben hat.

### Was die empfangende Person sieht

Ein Kalender, mit denselben vier Ansichten, die auch dein eigener hat — **Tag, Woche, Monat** und
**Agenda** — und einem Umschalter dazwischen, gezeichnet von denselben Vorlagen wie dein eigener
Kalender: dieselben Wochen und Tagesmarkierungen im Monat, dieselben Stunden und platzierten Blöcke in
Woche und Tag, dieselbe Datumsspalte in der Agenda. Sie öffnet im Monat. Die Zeiten werden in deiner
Zone gezeigt und als solche benannt. Ein Link **Zu deinem Kalender hinzufügen** gibt dasselbe Fenster
als `.ics` heraus, damit die eigene Kalender-App es abonnieren kann. Ein Fenster, in dem nichts steht,
sagt *In diesem Zeitraum nichts*. Eine einzelne Seite zeichnet höchstens 2000 Einträge.

Welche Ansicht auf dem Schirm ist, gehört zur Adresse — `/share/<Adresse>/week/2026-08-10` —, also ist
jede Ansicht ein Link, den man sich merken oder weitergeben kann, und der Zurück-Knopf funktioniert.
Das ist keine Geschmacksfrage: Eine geteilte Seite startet keine Sitzung, es gibt also keinen anderen
Ort, an dem „welche Ansicht“ leben könnte.

Die **Agenda** ist der Ort, an dem die Einzelheiten gelesen werden, und sie ist das, was früher die
Liste der Tage unter dem Monatsraster war. Sie behält deren eine besondere Eigenschaft: Jeder Tag, den
dein Fenster abdeckt, wird gezeigt, auch die leeren, sodass ein Tag ohne Eintrag *An diesem Tag nichts*
sagt, statt übersprungen zu werden. Deine eigene Agenda macht das Gegenteil und überspringt sie, weil
dort die Dichte der Punkt ist; auf einer geteilten Seite muss „am 4. frei“ etwas sein, das die Seite
sagt, und keine Lücke, die die lesende Person erst bemerken muss.

Dein Link veröffentlicht ein Fenster, und keine Ansicht davon ist genau dieses Fenster: Ein Monat hat
42 Zellen, eine Woche sieben Spalten. Wo die beiden auseinandergehen — und bei zwei rollenden Wochen
ist das der größte Teil eines Monats — werden die Tage, die dein Link nicht abdeckt, gedimmt
gezeichnet, in einer Legende als **Tage außerhalb des geteilten Zeitraums** benannt und in der Wochen-
und Tagesansicht mit **Nicht geteilt** beschriftet und ohne Stundenlinien gezeichnet. Dieser
Unterschied ist keine Kosmetik: Eine leere Zelle — oder eine leere Spalte mit vierundzwanzig
gezeichneten Stunden darin — ließe die Seite behaupten, du seiest an Tagen frei, die du nie
veröffentlicht hast. Aus demselben Grund reichen die Schritte ◀ ▶ nur bis zu den Seiten, die dein
Fenster wirklich veröffentlicht, und sind an den Enden ausgegraut, statt in eine Woche oder einen
Monat zu blättern, die leer aussähen. Ein **Heute**-Knopf erscheint nur, wenn dein Fenster den
heutigen Tag enthält — ein Knopf, der nichts tut, wäre schlimmer als keiner.

Chips tragen bewusst keine Kalenderfarbe. Eine geteilte Seite sagt nichts über deine Kalender — nicht
ihre Namen, nicht ihre Farben, nicht wie viele es sind — also wird jeder Chip stattdessen in deiner
Akzentfarbe gezeichnet. Farben ließen jemanden mit einem Belegt/Frei-Link zwei Wochen anonymer Blöcke
danach gruppieren, aus welchem Kalender sie stammen, und das ist Struktur über dein Leben, die kein
Ankreuzfeld freigegeben hat.

Die Seite wird in **deinem** Erscheinungsbild gezeichnet: dein Thema, deine Akzentfarbe, deine
Eckenrundung und Dichte. Sonst geht nichts über dich hinüber — die Seite trägt keinen Namen, keine
Adresse und keinen Hinweis darauf, von welcher Installation sie stammt, und die Vorlage bekommt drei
Zeichenketten statt deines Kontos, könnte also selbst dann nichts ausgeben, wenn jemand eine Zeile
hinzufügte, die es versucht. Ein Konto, das nie gewählt hat, bekommt Papier — das, womit ein neues
Konto beginnt. Ein auf *Dem System folgen* gesetztes Thema wird gegen den Rechner der lesenden Person
aufgelöst, die Seite ist also hell wie dunkel lesbar.

Auf dem Telefon behält das Monatsraster die Form eines Monats und reduziert die Beschriftung in jeder
Zelle auf eine Marke pro Eintrag; die Agenda ist, wo die Einzelheiten gelesen werden, und die
Tagesansicht ist einen Fingertipp entfernt und zeigt eine einzige Stundenspalte über die volle Breite.
Eine Woche mit sieben Spalten ist auf dem Telefon eng — dieselbe Einschränkung, die dein eigener
Kalender hat, mit derselben Antwort.

**Widerrufen** nimmt einen Link außer Betrieb, ohne die Zeile zu löschen, sodass dir der Nachweis
bleibt; ein widerrufener Link ist in der Liste als solcher gekennzeichnet. **Löschen** entfernt ihn
ganz — *Wer die Adresse noch hat, verliert sie.*

Eine unbekannte Adresse, ein widerrufener Link und eine fehlerhafte Adresse antworten alle mit 404 und
alle gleich. Sie auseinanderzuhalten bestätigte, welche Adressen einmal echt waren, und sagte jemandem,
dem du einen Link geschickt hast, dass du ihn zurückgezogen hast — was eine Tatsache über deine
Verfügbarkeit ist, die du bewusst nicht mehr veröffentlichen wolltest.

## Buchungsseiten

Eine Buchungsseite veröffentlicht eine Menge von Stunden und lässt eine fremde Person eine davon
nehmen. Eine Seite ist eine *Art* von Termin — „30 Minuten Kennenlernen“, „Sprechstunde“ — und nicht
die Verfügbarkeit einer Person, zwei Arten von Termin sind also zwei Seiten.

**Neue Buchungsseite** fragt nach:

| Feld | Was es bedeutet |
|---|---|
| **Wie der Termin heißt** | Wird gezeigt, wer auch immer den Link öffnet, und als Titel in deinem Kalender verwendet |
| **Worum es geht** | Freiwilliger Text auf der öffentlichen Seite |
| **Buchungen landen in** | Der Kalender, in den der Termin geschrieben wird. Synchronisiert dieser Kalender irgendwohin, geht die Buchung mit |
| **Diese Kalender auf Überschneidungen prüfen** | Eine Zeit wird nur angeboten, wenn nichts in diesen Kalendern sie überschneidet |
| **Deine Zeiten gelten in** | Die Zone, in der die Stunden darunter Uhrzeiten sind |
| **Tage, an denen du buchbar bist** | Mo–So; eine neue Seite startet mit Montag bis Freitag |
| **Von** / **Bis** | Die buchbaren Stunden, an jedem gewählten Tag dieselben. Eine neue Seite startet mit 09:00–17:00 |
| **Termindauer (Minuten)** | Voreingestellt 30; fünf Minuten sind das Kürzeste |
| **Puffer (Minuten)** | Ruhezeit, die um alles herum frei bleibt, was schon im Kalender steht. Voreingestellt 0, bis zu vier Stunden |
| **Kürzeste Vorlaufzeit (Minuten)** | Wie bald von jetzt an jemand buchen darf. Voreingestellt 120, bis zu 30 Tage |
| **Buchbar bis (Tage im Voraus)** | Voreingestellt 30, bis zu 366 |

Zwei Einzelheiten sind es wert, hervorgehoben zu werden.

**Die beiden Mengen von Kalendern sind nicht dieselbe Menge, und genau darum geht es.** *Buchungen
landen in* ist der Ort, an den ein Termin geschrieben wird; *Diese Kalender auf Überschneidungen
prüfen* ist das, woran „frei“ gemessen wird. Die brauchbare Konfiguration ist unsymmetrisch —
Buchungen landen in einem Kalender, den du dafür führst, und frei muss frei heißen gegen deinen
Arbeitskalender, deinen privaten und den aus Outlook gespiegelten. Der Zielkalender wird immer auf
Überschneidungen geprüft, ob du ihn angehakt hast oder nicht, was das Formular auch sagt: *Der
Kalender, in dem Buchungen landen, wird immer geprüft — auch ohne Häkchen.* Eine Seite, deren Ziel
nicht in ihrer eigenen Belegt-Menge stünde, buchte sich bei der zweiten Anfrage selbst doppelt.

**Der Puffer gilt für das, was schon in deinem Kalender steht, und nicht für die Zeitfenster.** Ein
Termin von 10:00 bis 11:00 mit einem Puffer von fünfzehn Minuten belegt für die Liste der Zeitfenster
09:45 bis 11:15, es fallen also das Fenster davor und das dahinter weg. Das ist es, was ihn ohne
Sonderfall symmetrisch macht.

Die Stunden sind Uhrzeiten in der Zone der Seite, und die ist eine eigene Einstellung und nicht die des
Zielkalenders: 09:00 bleibt neun Uhr morgens, auch wenn die Uhren umgestellt werden, und eine Seite auf
einen Kalender umzuhängen, der in einer anderen Zone angezeigt wird, darf die angebotenen Stunden nicht
stillschweigend verschieben.

Jede Zahl wird gekappt statt geprüft. Eine Seite, deren Stunden verkehrt herum stehen oder deren Liste
der Wochentage leer ist, wird gezeichnet und bietet nichts an, denn eine öffentliche URL, die einen
Fehler wirft, weil du eine 0 in ein Feld getippt hast, ist schlimmer als eine, die still keine Zeiten
hat. Die eine Ablehnung ist eine Seite ohne beschreibbaren Zielkalender: *Wähle einen Kalender, der
Änderungen annimmt, damit Buchungen dort landen können.* Anders als ein Link, der nichts abdeckt, nähme
eine Buchungsseite ohne Ziel Termine an und verlöre sie.

**Abschalten** nimmt eine Seite herunter, ohne ihre Stunden oder ihr Ziel zu verlieren, und das ist es,
was du für zwei Wochen Abwesenheit willst. Eine abgeschaltete Seite antwortet mit 404 wie eine
unbekannte Adresse.

### Was die buchende Person sieht

Eine Karte, die den Termin, seine Länge und die Zone der gezeigten Stunden benennt, und darunter eine
**Woche** deiner Verfügbarkeit: sieben Spalten, jede mit den an diesem Tag noch freien Zeiten. Die
Zeiten werden zuerst in deiner Zone gezogen und dann in ihrer neu gezeichnet — nur die Anzeige ändert
sich; welche Zeitfenster es gibt, entscheiden ausschließlich deine Stunden und dein Kalender. Die
Schritte ◀ ▶ gehen eine Woche weiter und halten an den Enden dessen, was die Seite anbietet, statt in
leere Wochen zu blättern. Die Seite öffnet auf der ersten Woche, in der überhaupt etwas steht — was
zählt, wenn deine kürzeste Vorlaufzeit lang ist: Eine Seite mit zwei Wochen Vorlauf hat diese Woche
schon von Natur aus nichts.

Ein Tag ohne freie Zeit sagt *Nichts frei*, statt zu verschwinden, und bereits vergangene Tage sind
gedimmt. Nichts sagt, **warum** eine Zeit fehlt — eine Lücke am Vormittag ist nicht von einer Stunde
außerhalb deines Arbeitstags zu unterscheiden und eine leere Spalte nicht von einem freien Tag. Wer
die Adresse hält, hat kein Anrecht darauf, deinen Kalender aus der Form seiner Löcher zu lesen.

Sie tragen **Dein Name** und **Deine E-Mail-Adresse** ein — *Bestätigung und Kalenderdatei gehen
hierhin.* — dazu eine freiwillige Notiz, und drücken **Buchung bestätigen**. Zeit wählen und Formular
ausfüllen ist ein Absenden, nicht zwei Seiten.

Wie der geteilte Kalender wird die Seite in deinem Erscheinungsbild gezeichnet und sagt sonst nichts
über dich.

Danach landen sie auf einer Seite, die **Termin gebucht** sagt, und eine Bestätigung mit angehängter
`.ics` geht an sie hinaus. Diese Mail kommt **von dir**, über dein eigenes Konto, denn sie geht an eine
fremde Person und handelt von einem Termin mit dir, und Mail von niemandem über einen Termin mit
niemandem ist schlimmer als gar keine. Der Anhang trägt keine Einladungsmethode, es versucht also kein
Programm, darauf zu antworten.

Lässt dein Kalender nichts frei, sagt die Seite *Zurzeit sind keine Zeiten frei. Versuche es später
noch einmal.* Ist nur diese Woche voll, sagt die Woche selbst *Diese Woche ist nichts frei. Schau eine
Woche vor oder zurück.*

Das öffentliche POST ist auf sechs Versuche pro Stunde und Adresse begrenzt, denn es legt Zeilen an und
verschickt Mail, was die Definition eines Spam-Vektors ist — und die Adresse in der URL hilft dabei
nicht, da die missbrauchende Person dieselbe hält. Wer die Grenze reißt, bekommt zu lesen: *Das sind
viele Buchungen von einem Ort. Bitte warte etwas, bevor du es erneut versuchst.* Das Lesen der Seite
ist bewusst nicht begrenzt: Eine Grenze dort ließe eine einzige fremde Person deine veröffentlichte
Seite durch Neuladen aus dem Netz nehmen.

### Wie ein gebuchter Termin in deinem Kalender aussieht

Ein gewöhnlicher Termin, in dem Kalender, den die Seite nennt, zu der Stunde, die genommen wurde. Drei
Dinge kennzeichnen ihn:

- **Der Titel ist der Name der Seite und der der buchenden Person**, in dieser Reihenfolge — *30
  Minuten Kennenlernen — Sam Reyes*. Deine Woche ist eine Liste von Titeln, und „30 Minuten
  Kennenlernen“ viermal hintereinander sagt nichts.
- **Die Beschreibung trägt, wer gebucht hat**: `Booked by Sam Reyes <sam@example.com>`, gefolgt von
  ihrer Notiz. Das ist auch die einzige Kopie, die mitwandert — ein abgeglichener Kalender trägt die
  Beschreibung zum Anbieter, du weißt also auch dann noch, wer gebucht hat und wie du die Person
  erreichst, wenn du die Besprechung auf dem Telefon liest.
- **Ein Abzeichen** — *Über deine Buchungsseite gebucht* — denn es ist die einzige Art von Termin in
  deinem Kalender, die jemand von außerhalb dieser Installation entstehen lassen kann, und „welchen
  davon habe ich nicht selbst eingetragen?“ ist eine Frage, die du beim ersten Missbrauch einer Seite
  stellen wirst.

Die buchende Person ist bewusst **kein** Teilnehmer am Termin. Eine Teilnehmerliste zu einem Anbieter
zu schieben, ist die Art, wie der Anbieter beschließt, die Einladung erneut an alle darin zu senden,
und das schickte einer fremden Person zusätzlich zu der Bestätigung, um die sie gebeten hat, eine
Besprechungsanfrage, um die sie nicht gebeten hat.

Der Termin ist ansonsten völlig normal: Er wird wie alles andere in einen verbundenen Kalender
geschoben, er lässt sich bearbeiten, verschieben und löschen, und ihn abzusagen gibt das Zeitfenster
wieder frei. Es gibt hier keinen Zwischenspeicher — die Liste der Zeitfenster wird bei jedem
Seitenaufruf neu aus deinem Kalender gebaut, eine abgesagte Besprechung bringt ihre Stunde also beim
nächsten Neuladen zurück, und eine auf Donnerstag gezogene nimmt das Loch mit.

An einer Buchung kann nichts durch später eintreffende Mail umgeschrieben werden. Die einzige Partei,
die Mail mit ihrer Identität senden könnte, ist die buchende Person, und die hat kein Recht, eine
Stunde in deinem Kalender zu verschieben.

### Zwei Menschen, ein Zeitfenster

Eine Doppelbuchung wird von der Datenbank abgelehnt und von sonst nichts. Zwei fremde Menschen, die im
selben Augenblick auf **Buchung bestätigen** drücken, lesen beide die Liste der Zeitfenster, bevor eine
von ihnen geschrieben hat, jedes Erst-prüfen-dann-schreiben antwortet also beiden „frei“ — dieses
Fenster zu verengen macht den Fehler seltener und nie unmöglich, und „seltener“ ist die denkbar
schlechteste Eigenschaft für einen Fehler, der stillschweigend zwei Menschen in einen Termin steckt.
Die Datenbank ist die einzige Beteiligte, die beide Anfragen sieht, also ist sie die einzige, die
zwischen ihnen entscheiden kann.

Die unterlegene Person sieht die Seite erneut, ohne das Zeitfenster und mit einer Zeile:

> Diese Zeit wurde gerade vergeben. Bitte wähle eine andere.

Die Buchung der gewinnenden Person ist vollständig. Termin und Buchung der unterlegenen werden
gemeinsam rückgängig gemacht — sie wurden als eine Arbeitseinheit geschrieben, die Ablehnung hat den
Termin also mitgenommen, und es bleibt nichts halb Geschriebenes zurück.

Ein veraltetes Formular — eine Seite, die offen stand, während sich die Stunden darunter änderten —
bekommt mit Absicht eine andere Meldung: *That time is no longer being offered. Please choose another.*
Jemandem zu sagen, er habe ein Rennen verloren, an dem er nie teilgenommen hat, schickte ihn für nichts
durch die Schleife.

## Fallstricke

**Die Adresse kann nie wieder gezeigt werden.** Nicht von der Administration, nicht aus der Datenbank,
nicht aus einer Sicherung. Hast du den Dialog geschlossen, ohne sie zu kopieren, nimm **Neue Adresse
geben** und verschick die neue.

**Ein Belegt/Frei-Link gibt trotzdem dein Muster preis.** Er gibt nichts *Konkretes* preis — keine
Titel, keine Orte, keine Namen — aber wer ihn liest, sieht genau, in welchen Stunden welcher Tage du
besetzt bist. Das ist der ganze Zweck, und es lohnt sich, bewusst zu entscheiden, wem du ihn schickst.

**Termine als geheim zu markieren, lässt einen Freigabelink über deine Verfügbarkeit lügen.** Sie
verschwinden, statt als belegt zu erscheinen, der Link zeigt dich also zu Stunden frei, zu denen du es
nicht bist. Das ist es, worum „geheim“ bittet; willst du die Stunde blockiert haben, ohne das Detail,
nimm **privat**.

**Einen Link zu bearbeiten kann ihn stillschweigend öffnen.** Ein weiteres Ankreuzfeld an einem
bestehenden Link zu setzen, ändert, was alle sehen, die seine Adresse schon haben, ohne dass sich die
Adresse ändert. Das ist Absicht — einzuschränken, was ein Link preisgibt, darf kein erneutes Verschicken
verlangen — aber es schneidet in beide Richtungen. Die Liste in den Einstellungen zeigt, was jeder Link
preisgibt, und **Neue Adresse geben** ist das, was du nimmst, wenn du jemanden abschneiden wolltest.

**Ein widerrufener Link und ein unbekannter sind von außen nicht zu unterscheiden.** Beide sind ein
404. Es gibt bewusst keine Seite „dieser Link wurde widerrufen“.

**Eine Buchungsseite zu löschen, löscht die darüber gebuchten Termine.** Die Rückfrage sagt es:
*„…“ löschen? Die bereits gebuchten Termine gehen mit.* Nimm **Abschalten**, wenn sie nur eine Weile
unten sein soll.

**Eine Buchungsseite mit unmöglichen Stunden bietet nichts an, statt sich zu beschweren.** Ein Ende vor
einem Beginn, ein Zeitfenster, das länger ist als der Arbeitstag, oder kein angehakter Wochentag ergeben
alle eine Seite, die sagt, es seien keine Zeiten frei. Die Zahlen werden gekappt, wo das geht, und so
sieht „gekappt“ aus, wenn die Kombination Unsinn ist.

**Eine Seite, die keine Zeitfenster anbietet, tut möglicherweise genau das, was du ihr gesagt hast.**
Prüf die kürzeste Vorlaufzeit gegen den Horizont: Eine Vorlaufzeit, die länger ist als das Fenster nach
vorn, lässt überhaupt nichts Buchbares übrig.

**Nur dein erstes E-Mail-Konto kann die Bestätigung senden.** Die Bestätigung und die Kalenderdatei für
die buchende Person gehen darüber hinaus. Eine Installation, deren erstes Konto nicht senden kann, ist
eine Installation, deren Buchende nichts hören — die Buchung selbst landet trotzdem in deinem Kalender,
denn ein Mailserver, der eine Verbindung ablehnt, ist kein Grund, jemandem den Termin wieder wegzunehmen.

---

**Verwandt:** [Kalender](calendar.md) · [Verbundene Kalender](calendar-sync.md) ·
[Erinnerungen](calendar-alerts.md) · [Sicherheit](security.md)

**Wie es funktioniert:** [Das Sicherheitsmodell](../internals/security-model.md) — wie öffentliche
Token gespeichert werden und was ein öffentlicher Link erreichen kann.
[Das Kalendermodell](../internals/calendar-model.md) — die Privatheit von Terminen und wo die Felder
eines gebuchten Termins liegen.
