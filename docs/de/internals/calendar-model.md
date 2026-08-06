<!-- translated-from: internals/calendar-model.md sha1:ec2e45ae942b88d9913928b05e9c3b7f7cb0aeae -->
# Das Kalendermodell

JSCalendar in jsonb, mit den abfragbaren Teilen in Spalten herausgezogen; die Tabelle der
Termininstanzen, die jede Ansicht tatsächlich liest; Wiederholung und ihre Ausnahmen;
Zeitzonen; und wie eine Besprechung, die zweimal angekommen ist, einmal gezeichnet wird. Zum
Feature selbst siehe [Kalender](../features/calendar.md), zur Sync-Engine, die das alles füllt,
[Die Sync-Engine](calendar-sync-engine.md).

## Der Hybrid, und warum er weder das eine noch das andere ist

`App\Entity\Calendar\CalendarEvent` speichert einen Termin als RFC 8984 JSCalendar in einer
jsonb-Spalte, und alles, wonach eine Abfrage filtert, sortiert oder joint, ist daneben in echte
Spalten gehoben. Der Docblock nennt das die größte Entscheidung des Features, und sie lässt sich
am besten als zwei Absagen wiedergeben.

**Ein maßgeschneidertes Schema wurde abgelehnt**, weil alles, womit dieser Kalender je reden
wird, JSCalendar spricht oder sich sauber dorthin übersetzt — iCalendar in beide Richtungen,
CalDAV und JMAP-Kalender, sobald der Entwurf steht. Teilnehmer, Erinnerungen, Links und
`recurrenceOverrides` hätten in einem selbstgebauten Schema keinen Platz, und sie beim Weg von
Import zu Export zu verlieren geschieht lautlos, was die schlimmste Art von Datenverlust ist.

**Reines jsonb wurde abgelehnt**, weil Postgres keine Bereichslogik auf `"duration": "PT1H"`
betreiben kann. Es gibt keinen Index, der gegen eine Dauer als Zeichenkette die Frage „was liegt
im Juli?" beantwortet.

Also hält `$jscalendar` die Wahrheit, und `title`, `location`, `startsAt`, `endsAt`, `timeZone`,
`isAllDay`, `status` und `privacy` sind Projektionen davon. Was die beiden davon abhält,
einander zu widersprechen, ist keine Disziplin: **`App\Service\Calendar\CalendarEventWriter` ist
das Einzige, was überhaupt eines von beiden schreibt.** Alles schreibt durch ihn — der Editor,
die Extraktion, der Puller der Sync-Engine, der Buchungsdienst, die JMAP-Methode
`CalendarEvent/set`. Ohne ihn gibt es zwei Wahrheiten, und der Fehler ist leise: Ein Aufrufer
setzt `$title`, vergisst `jscalendar['title']`, der Kalender sieht richtig aus, und der
`.ics`-Export ist leer.

`CalendarEventWriter::write()` baut das kanonische Objekt deshalb bei jedem Aufruf aus den
Spalten neu auf und trägt vier Dinge ausdrücklich hinüber, weil sie keine Spalte haben, aus der
sie sich rekonstruieren ließen:

- **`recurrenceOverrides`** — Entscheidungen, die die Nutzerin für einzelne Instanzen getroffen
  hat; die Serie neu zu schreiben ist kein Grund, sie zu verlieren.
- **`participants`** — sie tragen die Zusage, der Editor hat kein Feld für Teilnehmer, und ohne
  das Hinübertragen würde die Korrektur eines Besprechungstitels stillschweigend eine bereits
  angenommene Einladung wieder unbeantwortet machen.
- **`alerts`**, aber anders: Erinnerungen *haben* ein Feld im Editor, ein Aufrufer, der sie
  angibt, sagt also, was die Erinnerungen **sind**, und eine leere Liste ist die Nutzerin, die
  jedes Kästchen abgewählt hat. `null` — was jeder Extractor, jeder Sync-Pull und jede
  Bearbeitung einer einzelnen Instanz übergibt — behält, was gespeichert ist.
- **Das `$jscalendarOverlay` eines Extractors**, über das hergeleitete Objekt gelegt, mit der
  hergeleiteten Fassung als Boden, und zwar bevor die Termininstanzen materialisiert werden,
  damit der Termin vollständig ist, wenn der Materialisierer ihn liest.

Die Overlay-Zusammenführung läuft durch `keepAnswersAlreadyGiven()`, was dasselbe Argument in
zugespitzter Form ist: Eine Einladung sagt für alle Zeiten NEEDS-ACTION über die Empfängerin,
denn das war es, was sie beim Versenden sagte. Die Extraktion über gespeicherte Mail erneut
laufen zu lassen ist Routine — ohne das hier fiele also jede Zusage, die die Nutzerin gegeben
hat, auf unbeantwortet zurück, während der Organisator, dem seinerzeit Bescheid gegeben wurde,
weiterhin mehr wüsste als der Bildschirm. Es *behält* immer nur; ein hereinkommender Eintrag,
der eine tatsächliche Antwort nennt, gewinnt, denn das ist die aktualisierte Teilnehmerliste des
Organisators, die zurückkommt.

Leere Abbildungen werden entfernt statt stehen gelassen, für `recurrenceOverrides` wie für
`alerts`: Eine leere Abbildung ist keine Tatsache über einen Termin, und eine stehen gelassene
lässt jeden Termin, der je eine Erinnerung hatte, so aussehen, als habe er immer noch eine — was
für einen Sync ein PUT bedeutet, der immer wieder das Nichts behauptet.

## Termininstanzen: die Tabelle, die eine Ansicht liest

`App\Entity\Calendar\CalendarEventOccurrence` ist eine datierte Instanz. Wiederholung ist der
Teil eines Kalenders, den naive Entwürfe falsch machen, und der Docblock der Entity zählt die
einzigen drei Möglichkeiten auf:

- **RRULEs in PHP ausdehnen, wenn eine Ansicht fragt.** Um „was liegt im Juli?" zu beantworten,
  musst du zuerst jeden je angelegten Serientermin laden — ein Standup von 2019 erzeugt
  immer noch Instanzen — und jeden davon ausdehnen. Nichts ist indizierbar, nichts ist
  seitenweise abrufbar, Belegt/Frei wird quadratisch.
- **Alles materialisieren.** `FREQ=DAILY` ohne `UNTIL` hat kein Ende.
- **Bis zu einem begrenzten Horizont materialisieren.** Diese Tabelle.

Termine ohne Wiederholung bekommen hier ebenfalls genau eine Zeile. Ein einziger Codepfad zum
Lesen ist mehr wert als die Zeilen, die eine Sonderbehandlung spart — und er ist es, der den
Erinnerungs-Durchlauf, den Leser für Buchungsverfügbarkeit, den Leser für Freigaben und die
JMAP-Abfrage dieselben Zeilen lesen lässt.

`$span` ist ein generierter `tsrange`, den Postgres aus `starts_at` und `ends_at` pflegt, er
kann also nicht von ihnen abweichen. Nichts in PHP liest ihn: Das Einzige, was ihn nutzen kann,
ist die Überlappung `&&` in `CalendarEventOccurrenceRepository::findInRange()`, und die ist rohes
DBAL, weil DQL keinen Operator für Bereichsüberlappung kennt und Doctrines API einen GiST-Index
überhaupt nicht erreicht. Die naive Alternative — `starts_at < :to AND ends_at > :from` — ist
nicht bloß langsamer, sie *verfällt*: Ein Btree auf `starts_at` nähert die Sortierung nach
`ends_at` in dem Moment nicht mehr an, in dem es mehrtägige Termine gibt, und der Planer scannt
dann vom Fensteranfang rückwärts auf der Suche nach Terminen, die früher begannen und noch
laufen.

`idx_ceo_span` ist im Mapping als schlichter Index deklariert und wird von der Migration
`USING gist` erzeugt — derselbe Kniff, den `Message::$searchVector` mit seinem GIN-Index
spielt: Der Vergleicher erkennt einen Index an Name und Spalten und sieht die Methode nie an.
Ihn zu deklarieren hält Mapping und Datenbank im Einvernehmen; ihn **nicht** zu deklarieren
lässt jeden Schema-Diff seine Löschung verlangen, und die Löschung macht aus jeder
Kalenderansicht einen sequenziellen Scan, ohne dass irgendetwas fehlschlägt.

`idx_ceo_starts` — nur der Beginn, ohne Eingrenzung auf einen Besitzer — existiert für genau
einen Leser: den Erinnerungs-Durchlauf, der einmal pro Minute fragt „was fängt bald an,
irgendwo auf dieser Installation?". Beide besitzergebundenen Indizes beginnen mit einer Nutzerin
oder einem Kalender und können das nicht beantworten.

## Der Materialisierer und sein Horizont

`App\Service\Calendar\RecurrenceMaterialiser` läuft bei **jedem** Schreiben eines Termins und
schreibt dessen Zeilen komplett neu, statt sie zu vergleichen: Die Menge ist klein, der
Schreibvorgang ist ein DELETE und ein Stapel INSERTs, und ein Vergleich wäre eine zweite
Implementierung dessen, was die Regel bedeutet.

| Konstante | Wert | Was sie begrenzt |
|---|---|---|
| `HORIZON_PAST` | `-1 year` | wie weit zurück Zeilen geschrieben werden; kurz, weil niemand durch eine Serie zurückscrollt |
| `HORIZON_FUTURE` | `+2 years` | weit genug, dass „nächstes Jahr" sofort da ist |
| `MAX_OCCURRENCES` | `1000` | der zweite Gürtel — `FREQ=SECONDLY` innerhalb des Horizonts sind sechzig Millionen Zeilen, und eine `.ics` von einer fremden Person darf das durchaus behaupten |

`clear()` besteht aus zwei Schritten, und beide werden gebraucht: ein rohes DELETE für das, was
committet ist, plus `em->remove()` für das, was diese Unit of Work eingereiht, aber noch nicht
geflusht hat. Rohes SQL sieht die zweite Menge nicht, und die Collection zu leeren nimmt die
INSERTs nicht aus der Warteschlange — zweimal materialisieren vor einem Flush reiht also zwei
Zeilen mit derselben Recurrence-Id ein, und `uniq_ceo_event_recurrence` weist das Paar zurück.
Die Methode ist außerdem public, weil ein Termin, der einen synchronisierten Kalender gerade
verlässt, aus jeder Ansicht verschwinden muss, während seine Zeile noch darauf wartet, dass die
Gegenstelle informiert wird; siehe `CalendarEventWriter::markLocallyDeleted()`.

`CalendarEvent::$recurrenceUntil` fällt als Nebenprodukt an: der letzte Zeitpunkt, zu dem der
Termin stattfinden kann, oder **null im Sinne von „wir haben aufgehört, weil uns der Platz
ausging, nicht weil die Regel endete"**. Genau dieses null liest der nächtliche Durchlauf
erneut.

Dieser Durchlauf ist `app:calendar:materialise`
(`App\Command\Calendar\CalendarMaterialiseCommand`), und es gibt ihn, weil Termininstanzen
gezeichnet werden, wenn ein Termin **gespeichert** wird, und danach nichts das Fenster weiter
schob. Ein heute angelegtes wöchentliches Standup reicht zwei Jahre weit; in achtzehn Monaten
reicht es noch sechs, und irgendwann liegt seine letzte Zeile in der Vergangenheit — ab da wird
die Serie nicht mehr gezeichnet und ihre Erinnerungen lösen nicht mehr aus, weil
`DueAlertReader` Instanzzeilen liest und keine mehr da sind. Angekündigt wird das von nichts:
Den Termin gibt es weiter, und er sagt weiter, dass er sich wöchentlich wiederholt. Sein
Kriterium ist `CalendarEventRepository::findNeedingHorizonExtension()` — alles Unbegrenzte, plus
alles, was nach dem aktuellen Horizont endet — und mit Absicht nicht „alles Wiederkehrende",
denn eine Serie mit einem `UNTIL` innerhalb des Fensters ist bereits bis zu ihrem Ende
gezeichnet. Erneutes Materialisieren ist idempotent, eine verpasste Nacht kostet also nichts und
ein doppelter Lauf ebenso wenig, und geflusht wird in Stapeln von `BATCH` (50) Terminen, statt
eine Transaktion auf `calendar_event_occurrence` über die Länge des Durchlaufs zu halten.

Zwei Schutzvorrichtungen in der Ausdehnung gibt es, weil eine Regel feindselig oder auch
einfach kaputt sein kann:

- Ein Iterator, der nicht vorrückt, hält bei der ersten Wiederholung mit einer Warnung an. Der
  Deckel für Instanzen allein würde aus einer nicht vorrückenden Regel statt eines Hängers
  tausend identische Zeilen machen, und das ist der schwerer zu bemerkende Fehler.
- Eine unbrauchbare Regel — eine, die `RecurrenceRuleConverter` zurückgewiesen hat, oder eine,
  bei der `RRuleIterator` geworfen hat — fällt auf eine einzelne Termininstanz mit `isRecurring`
  false zurück. Eine Instanz ist so halb falsch; ein stillschweigend leerer Kalender ist
  schlimmer.

Der Horizont in der Vergangenheit **überspringt**, statt anzuhalten: Eine Regel, die 2019 begann,
muss trotzdem durchlaufen werden, um zu den diesjährigen Instanzen zu kommen.

## Wiederholungsregeln, in beide Richtungen

`App\Service\Calendar\RecurrenceRuleConverter` übersetzt zwischen den `recurrenceRules` von
JSCalendar (RFC 8984 §4.3.3) und iCalendar-RRULE. Beide Richtungen wohnen dort, und die
Rückrichtung fehlte früher — zwei der drei Wege, auf denen eine Regel plMail erreicht, nämlich
eine per Mail geschickte Einladung und eine CalDAV-Ressource, behielten sie deshalb wortwörtlich
unter `plmail:rrule` und dehnten sie zu einer einzigen Termininstanz aus. Eine wöchentliche
Besprechung von einem Kalenderserver tauchte einmal auf. Google schrieb derweil seine eigene
Kopie, weil es nichts zum Aufrufen gab.

**Alles, was nicht getreu übersetzt werden kann, führt zur Zurückweisung der ganzen Regel.**
Nicht „lässt den Teil weg, den es nicht verstanden hat": `FREQ=MONTHLY;BYDAY=2FR` mit einem
unlesbaren `BYDAY` wird zu „monatlich an dem Tag, an dem es begann", und das ist eine
Besprechung, die jemand verpasst, statt einer Besprechung, die sichtbar fehlt. Eine
zurückgewiesene Regel kommt als null zurück, der Aufrufer behält die RRULE wortwörtlich, und ein
Push stellt dem Absender seine eigene Regel zurück. Das Einzige, was weggelassen statt
zurückgewiesen wird, ist ein Teilname, den RFC 5545 nicht definiert — dessen Grammatik ist
geschlossen, ein unbekannter Name ist also eine Herstellererweiterung.

`secondly` und `minutely` fehlen mit Absicht in der Frequenztabelle. Beide sind nach RFC 5545 und
RFC 8984 zulässig, und sabres `RRuleIterator` akzeptiert sie bei der Validierung, aber sein
Vorrückschritt hat für keines von beiden einen Zweig und liefert ewig denselben Zeitpunkt. Sie zu
übersetzen ergäbe einen Iterator, der sich nie bewegt, und der Deckel für Instanzen macht daraus
statt eines Hängers tausend identische Zeilen — schlimmer, weil es aussieht, als habe es
funktioniert.

## Overrides: wo eine Serie aufhört, eine Regel zu sein

Eine geänderte Instanz ist ein JSCalendar-PatchObject, abgelegt unter der **LocalDateTime, an
die die Regel sie ursprünglich gesetzt hat** — nie unter der, wohin sie verschoben wurde.
`CalendarEventOccurrence::$recurrenceId` ist dieselbe Tatsache in der Instanzzeile: die einzige
stabile Art zu sagen „die, die am 3. sein sollte", nachdem sie auf den 5. gezogen wurde, und
das, was eine zweite Bearbeitung derselben Instanz ihren Patch aktualisieren lässt, statt einen
neuen danebenzustapeln.

Der Materialisierer liest fünf Schlüssel aus einem Override. Vier davon sind der Patch, den
`App\Service\Calendar\EventInstanceEditor` schreibt — die vier Dinge, die eine Termininstanz
überhaupt zeichnen kann —, und der fünfte ist gar kein Patch-Feld: `excluded` wird von
`RecurrenceRuleConverter::exclusionOverrides()` geschrieben, denn der eine Override-Wert, dessen
einzige Aufgabe es ist, exakt richtig zu sein, sollte keine zweite Stelle haben, die ihn richtig
treffen muss.

| Schlüssel | Wirkung |
|---|---|
| `start` | wird an dem Tag gezeichnet, an den sie ging |
| `duration` | eine verschobene Instanz ist regelmäßig auch anders lang |
| `title` | wird nur geschrieben, wenn er sich vom Titel der Serie unterscheidet — ein Patch, der den Serientitel wiederholt, ist die Behauptung, diese Instanz sei umbenannt worden |
| `status: cancelled` | die Zeile bleibt und wird durchgestrichen |
| `excluded: true` | die Instanz ist gänzlich vom Kalender verschwunden |

`duration` zu lesen fehlte früher. Eine Instanz, die in den Nachmittag gezogen wurde, weil sie
zur Retro wurde, wurde mit dem richtigen Beginn und der Länge der Serie gezeichnet — und das ist
eine Besprechung, die in jeder Ansicht in die nächste hineinragt.

**Ein Patch ist ein Teilstück, und das ist die Disziplin.** Ein ganzes Terminobjekt in die
Abbildung zu schreiben — die naheliegende Abkürzung, wenn der Editor ohnehin jedes Feld
abgeschickt hat — würde für eine Instanz einen Ort, eine Beschreibung und ein Ganztags-Kennzeichen
behaupten, die niemand liest und die die nächste Leserin nicht von einer Entscheidung der
Nutzerin unterscheiden kann.

Eine einzelne Instanz abzusagen ist `{"excluded": true}`, und diese Schreibweise gehört
`RecurrenceRuleConverter::exclusionOverrides()`, statt an jeder Aufrufstelle von Hand
hingeschrieben zu werden: Es ist der eine Override-Wert, dessen einzige Aufgabe darin besteht,
exakt richtig zu sein.

`App\Service\Calendar\EventMover` ist der Weg für das Ziehen auf dem Raster, und er gibt **die
beiden gleichen Antworten wie der Editor**, über dieselben zwei Dienste, damit ein Ziehen und
ein Speichern, die dasselbe meinen, keine unterschiedlichen Daten erzeugen können. „Diese
Termininstanz" ist `EventInstanceEditor::edit()`; „alle" ist ein `CalendarEventWriter::write()`,
dessen Zeiten durch `EventInstanceEditor::seriesTimesFor()` gelaufen sind — das die *Differenz*
anwendet, die das Ziehen ausgemacht hat, und nicht deren absoluten Wert. Die absoluten Zeiten des
gezogenen Blocks als die der Serie zu schreiben hat einmal eine wöchentliche Besprechung auf den
Wochentag umgesetzt, an dem zufällig ihre fünfte Instanz angeklickt wurde.

## Zeitzonen, und was floating bedeutet

Zeitstempel sind UTC in einer schlichten `timestamp`-Spalte, mit der IANA-Zone daneben, statt
`timestamptz`. Das passt zu jedem anderen Zeitstempel in der Anwendung, es passt dazu, wie CalDAV
und sabre das modellieren, und es umgeht Doctrines verlustbehaftetes Lesen von `datetimetz` auf
Postgres.

**Die Ausdehnung geschieht in der Zone des Termins selbst.** Ein Standup um 09:00 Berliner Zeit
ist im November wie im Juli um 09:00 Berliner Zeit, und das sind verschiedene UTC-Zeitpunkte —
in UTC auszudehnen würde die Besprechung zweimal im Jahr stillschweigend um eine Stunde
verschieben. Also wird der Startwert in die Zone des Termins umgerechnet, dort iteriert, und
jedes Ergebnis wieder zurückgerechnet.

`RecurrenceMaterialiser::zoneOf()` ist **public**, und der Docblock sagt warum: Der Schlüssel
eines Override ist eine LocalDateTime in der Zone der Serie — ein Erzeuger, der auf die Zone der
Nutzerin zurückfällt, wo der Ausdehner auf UTC zurückfällt, würde also jeden Patch eines
floating Termins unter einem Schlüssel ablegen, unter dem nie nachgesehen wird: ein Override,
der stillschweigend nichts tut. `EventInstanceEditor` fragt dort nach, statt den Rückfall zu
wiederholen, und `CalendarPuller` führt eine eigene Kopie derselben Regel mit einem Kommentar,
dass die beiden übereinstimmen müssen.

**Ganztägige Termine sind floating**: lokale Mitternacht mit `timeZone` auf null, und sie dehnen
sich in UTC aus, was floating gerade bedeutet — überall dieselbe Uhr an der Wand.
`CalendarEventWriter::write()` erzwingt die Paarung, indem er `timeZone = null` schreibt, sobald
`isAllDay` wahr ist, damit ein Aufrufer die beiden nicht widersprüchlich setzen kann.

`App\Service\Calendar\CalendarTimeResolver` besitzt die andere Hälfte: in welcher Uhrzeit an der
Wand ein Kalender *gelesen* wird, und wie die Ziffern, die ein Browser abschickt, wieder zu
Zeitpunkten werden. Die Zone kommt vom **Kalender**, nicht aus dem Profil der Nutzerin —
`UserTimezoneResolver` beantwortet „auf welche Uhr schaut diese Person?", was für einen
gerenderten Zeitstempel richtig ist, während die eigene Zone eines Kalenders die ist, in der ein
Termin ohne eigene Zone gespeichert und gezeigt wird. Die beiden dürfen ehrlich auseinandergehen:
ein geteilter Arbeitskalender, festgenagelt auf die Zeit im Büro. Jedes Parsen dort ist total —
eine unbrauchbare Zone oder ein nicht parsbares Datum liefert einen Rückfallwert oder null,
statt zu werfen, denn all das kommt aus einem Request.

## Eine Besprechung, zwei Zeilen

Eine Besprechung kann plMail auf zwei ehrlichen Wegen gleichzeitig zweimal erreichen: aus ihrer
Einladung extrahiert auf den Standardkalender, und vom Anbieter gespiegelt auf einen
Remote-Kalender. Beide Zeilen sind richtig. `CalendarPuller` fällt zwar bereits von `remoteId`
auf `uid` zurück, aber begrenzt auf einen Kalender, und hier sind es zwei.

Nichts führt die Zeilen zusammen. Die Dopplung wird **auf dem Bildschirm** beantwortet, von
`App\Service\Calendar\EventClusterer`.

**UID plus Startzeitpunkt ist der Gruppierungsschlüssel, und er ist der einzig ehrliche.** Über
Titel und Zeit zu gruppieren würde ein wöchentliches Vieraugengespräch, das mit zwei
verschiedenen Personen zur selben Stunde stattfindet, zu einem Chip zusammenfallen lassen — eine
Besprechung, die klammheimlich vom Kalender verschwindet, und das ist die schlimmste Gestalt,
die ein Kalenderfehler annehmen kann. Der Beginn steht im Schlüssel, weil zwei Instanzen einer
Serie derselbe *Termin* sind, aber nicht dieselbe *Besprechung*.

**Ein Cluster wird nur zusammengeführt, solange seine Mitglieder übereinstimmen**, und zwar in
genau den fünf Dingen, die eine Nutzerin an einem Chip bemerken würde: Beginn, Ende, Titel,
Ganztags und ob abgesagt wurde. In dem Moment, in dem sie sich widersprechen, zerfällt der
Cluster wieder in Cluster aus je einem Element, und die Ansichten zeichnen je einen Chip. Das ist
Absicht: Ein zusammengeführter Chip, der klammheimlich einen Sieger bestimmt, versteckt einen
echten Widerspruch — eine Aktualisierung, die den einen Weg erreicht hat und den anderen nicht —
hinter einer aufgeräumteren Oberfläche. Und **ein Widerspruch spaltet die ganze Gruppe**, statt
die Untergruppe zusammenzuführen, die zufällig in der Mehrheit ist, denn eine Mehrheit ist ein
Sieger mit ein paar Zwischenschritten.

Wiederholung ist mit Absicht keines der fünf. Zwei Kopien, von denen die eine sich wiederholt und
die andere nicht, stimmen in der gemeinsamen Termininstanz überein und sonst in nichts, und die
wiederkehrende Kopie zeichnet an jedem späteren Tag ihre eigenen Chips, ohne Partner zum
Zusammenführen — was genau das sichtbare Signal dafür ist, dass die beiden sich unterscheiden.

Die Absage wird aus der Instanzzeile **und** aus dem Status des Termins gelesen, denn die
Bereichsabfrage wirft abgesagte Instanzzeilen weg, bevor eine Ansicht sie sieht: Der Widerspruch,
der tatsächlich bis zum Bildschirm kommt, ist ein Status „cancelled" auf der einen Kopie und
„confirmed" auf der anderen, und diese beiden zusammenzuführen würde eine laufende Besprechung
zeichnen, von der einem Weg bereits gesagt wurde, dass sie abgesagt ist.

`copiesOf()` beantwortet dieselbe Frage über Termine statt über Termininstanzen, für den Editor,
und vergleicht dieselben fünf Felder über dieselbe private Signatur — zwei Implementierungen von
„dieselbe Besprechung" wären sich einig, bis eine davon von einem sechsten Feld erführe.

### Eine Kopie teilt die UID der Besprechung

`App\Service\Calendar\EventCopyResolver` verwandelt „auf welchen Kalendern liegt das?" in jeden
Kalender, der der Nutzerin gehört, angehakt dort, wo die Besprechung schon liegt — einen leeren
anzuhaken legt die Kopie also dort an. Die Entscheidung, um die sich das ganze Feature dreht:

**Eine Kopie trägt die UID der Besprechung. Sie bekommt keine eigene.** Vier Gründe, und sie
verstärken sich gegenseitig:

1. `EventClusterer` erkennt eine Besprechung an UID plus Beginn, zwei Zeilen mit verschiedenen
   UIDs sind also *konstruktionsbedingt* zwei Besprechungen — eine Kopie mit eigener UID würde
   für immer einen zweiten Chip zur selben Stunde zeichnen, und keine spätere Bearbeitung könnte
   die beiden zusammenführen.
2. Das Schema wurde für den geteilten Fall gebaut: `uniq_calendar_event_calendar_uid` begrenzt
   die Eindeutigkeit auf **einen** Kalender, und aus demselben Grund ist jeder
   Identitätsnachschlag in `CalendarEventRepository` auf einen Kalender begrenzt.
3. RFC 5546 hat längst entschieden, dass eine UID die Besprechung über Kalender und Postfächer
   hinweg bezeichnet und nicht eine Zeile. Sie neu zu prägen hieße, jedem Client, der die `.ics`
   liest, das Gegenteil zu behaupten.
4. Sie ist es, die Aktualisierungen am Laufen hält. Eine spätere Nachricht des Organisators, ein
   erneuter `.ics`-Import und ein Pull vom Anbieter treffen alle über die UID.

**Die UID wird einmal pro Request geprägt, im Resolver, und nicht pro Zeile vom Writer.**
`CalendarEventWriter::write()` prägt nur für eine Zeile, die keine hat, denn sonst würde eine
Besprechung, die auf drei Kalendern angelegt wird, drei UIDs und drei Chips erzeugen, sobald sie
gespeichert ist. `newUid()` ist genau für diesen einen Aufrufer public, statt dass der Resolver
eine zweite Art buchstabiert, eine UID herzustellen — zwei Schreibweisen wären sich einig, bis
eine von ihnen vom Domain-Teil erführe. Dieser Domain-Teil ist ein Literal und nicht der
Hostname der Installation, damit eine UID sich nicht ändert, wenn die Anwendung hinter einen
anderen Namen umzieht.

Der Preis wird in Kauf genommen und ausgesprochen: Zwei Zeilen unter einer UID lassen sich
anhand der UID allein nicht auseinanderhalten. Das galt schon an dem Tag, an dem eine Besprechung
sowohl extrahiert als auch gespiegelt ankommen konnte.

Versteckte Kalender werden von `optionsFor()` trotzdem **aufgeführt**, nur eben nicht angehakt.
Sie wegzulassen würde aus „leg das bitte auch auf meinen Archivkalender" ein Insert machen, das
mit einem 500er gegen `uniq_calendar_event_calendar_uid` läuft.

## Fallstricke

**Ein Schreibvorgang, der `CalendarEventWriter` umgeht, erzeugt einen Termin, der richtig
aussieht und leer exportiert.** Eine Spalte ohne ihr JSCalendar-Gegenstück zu setzen ist in der
Oberfläche nicht zu bemerken. Der JMAP-Writer (`App\Jmap\Calendar\JmapEventWriter`) sagt aus
diesem Grund ausdrücklich, dass er keine Spalte anfasst.

**Ein Override-Schlüssel in der falschen Zone tut stillschweigend nichts.** Der Schlüssel ist
eine LocalDateTime in der Zone der *Serie*; die Zone eines floating Termins ist UTC und nicht die
der Nutzerin. Alles, was einen Override erzeugt, muss `RecurrenceMaterialiser::zoneOf()` fragen,
statt selbst eine Zone zu bestimmen.

**Alles, was ein Patch über `start`, `duration`, `title` und `status` hinaus sagt, wird
ignoriert** — diese vier plus `excluded`, das auf dem anderen Weg oben ankommt. Der
Materialisierer liest sie und sonst nichts, ein „reichhaltigerer" Patch sind also Daten, die die
Speicherung überstehen und nie eine Ansicht beeinflussen.

**Den Horizont zu verlängern ist nicht umsonst, und ihn zu verkürzen kostet Erinnerungen.** Der
Horizont der Termininstanzen ist zugleich der Horizont der Erinnerungen — eine Erinnerung gibt es
nur dort, wo es eine Instanzzeile gibt —, und `MAX_LEAD` von `DueAlertReader` mit `+31 days` ist
die passende Schranke am anderen Ende. Die JMAP-Session verkündet `materialisedHorizon` direkt
aus den beiden Konstanten, damit einem Client gesagt ist, ab wo seine Abfrage aufhört,
vertrauenswürdig zu sein.

**`idx_ceo_span` oder `idx_calendar_event_remote_instances` aus dem Mapping zu entfernen lässt
nichts fehlschlagen.** Beide sind als schlichte Indizes deklariert und werden von der Migration
mit einer anderen Methode gebaut; undeklariert löscht sie der nächste Schema-Diff, und das
Symptom ist ein Kalender, der langsamer wird, statt eines Kalenders, der kaputtgeht.

**Duplikate zusammenzuführen ist eine Entscheidung zur Renderzeit ohne gespeicherte Id.**
`copiesOf()` leitet erneut aus der UID her, statt eine Cluster-Id durch die URL zu fädeln, denn
ein Cluster ist eine Tatsache über die Daten im Moment des Lesens, und eine geprägte Id wäre
eine Behauptung, die der nächste Schreibvorgang widerlegen kann. Alles, was einen Cluster
zwischenspeichert, führt genau das wieder ein.
