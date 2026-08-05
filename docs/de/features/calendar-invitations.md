<!-- translated-from: features/calendar-invitations.md sha1:d52dd93bd0958dd5e2bbdc87131397c3c2f552a3 -->

# Einladungen und Termine aus E-Mails

Das meiste, was in einen Kalender gehört, kommt zuerst als E-Mail an — eine Besprechungsanfrage,
eine Flugbestätigung, ein Paket mit einem Lieferfenster oder ein Satz, in dem jemand Donnerstag um
zwei zusagt. plMail liest alle vier und behandelt sie unterschiedlich, je nachdem, wie viel es
ehrlicherweise zu wissen behaupten kann.

Es gibt drei Stufen der Zuversicht, und es lohnt sich zu wissen, welche man gerade vor sich hat:

| Was ankam | Was plMail tut | Wo es erscheint |
|---|---|---|
| Eine echte Einladung (ein `text/calendar`-Teil) | Legt sie in den Kalender und bietet dir die Antwortschaltflächen an | Die Karte über der Nachricht, und der Kalender |
| Eine Buchung mit schema.org-Auszeichnung — Flüge, Pakete, Hotels, Restaurants, Tickets, Bestellungen | Legt sie in den Kalender, gekennzeichnet als in der Mail gefunden | Der Kalender, und *Demnächst* |
| Ein Datum in gewöhnlicher Prosa | **Bietet** es an und trägt nichts ein, bis du zustimmst | Nur eine Karte über der Nachricht |

Das Dritte schreibt nie von sich aus in deinen Kalender. Diese Unterscheidung ist in den Daten
durchgesetzt und nicht per Übereinkunft — ein Vorschlag hat nirgends eine Termininstanz, also kann
keine Kalenderansicht ihn versehentlich zeigen.

## Einladungen

Trägt eine Nachricht eine Kalendereinladung, erscheint darüber eine Karte mit dem Titel der
Besprechung, wann sie ist, wo sie ist und wer sie einberufen hat. Die Karte beschreibt die
Besprechung *so, wie sie jetzt steht*, und nicht so, wie diese eine Nachricht sie beschrieben hat:
Ist inzwischen eine Aktualisierung eingetroffen, ist die ursprüngliche Einladung immer noch die
Stelle, an der du antwortest, und die Antwort gehört zur aktuellen Besprechung.

**Zusagen**, **Vielleicht** und **Absagen** sind die drei Antworten. Deine aktuelle Antwort ist
hervorgehoben und wird als Chip neben der Besprechung gezeigt — *Zugesagt*, *Vielleicht*,
*Abgesagt*. Eine Einladung, die du nicht beantwortet hast, sagt *Keine Antwort*.

Darunter klappt **N eingeladen** die vollständige Teilnehmerliste mit der Antwort jeder Person auf,
wobei die Organisation als solche gekennzeichnet ist und deine eigene Zeile fett steht.

Antworten tut zwei getrennte Dinge, und sie können unabhängig voneinander scheitern:

1. **Deine Antwort wird hier immer gespeichert.** Sie steht sofort am Termin, was auch immer als
   Nächstes passiert.
2. **Eine Antwort geht an die Organisation**, als gewöhnliche iTIP-Antwort, über dasselbe Konto, auf
   dem die Einladung ankam — ein Gmail-Konto sendet sie über die Gmail-API, ein IMAP-Konto über sein
   eigenes SMTP. Scheitert das, wird es dir gesagt: *„Deine Antwort wurde hier gespeichert, konnte
   aber nicht an die Organisation gesendet werden.“* Es wird nichts zurückgerollt. Eine Organisation,
   die nie ein „Nein“ gehört hat, hält einen Platz für jemanden frei, der sich für abgesagt hält —
   das sagt man also besser laut, als es zu schlucken.

Es wird keine Kopie der Antwort in Gesendet abgelegt.

Eine Absage zeigt den Titel durchgestrichen und sagt *Dieser Termin wurde abgesagt.*, ohne
Antwortschaltflächen. Der Termin bleibt durchgestrichen im Kalender stehen, statt zu verschwinden —
„wurde das abgesagt, oder habe ich mir das eingebildet?“ ist eine Frage, die ein Kalender
beantworten können sollte.

Eine Einladung zu beantworten, zählt **nicht** als Bearbeiten des Termins. Die Organisation bleibt
die Instanz dafür, wann die Besprechung ist, eine spätere Nachricht, die sie verschiebt, verschiebt
sie hier also auch nach deiner Zusage noch.

## Termine aus einer Buchung

Bestätigungsmail von Fluggesellschaften, Paketdiensten, Hotels, Restaurants und Ticketverkäufern
trägt regelmäßig eine maschinenlesbare Auszeichnung der Buchung — die absendende Stelle hat sie
bereits in strukturierte Felder zerlegt, sie zu lesen ist also Rechnen und kein Raten. Das ist es,
was es sicher macht, sie ungefragt in den Kalender zu schreiben.

Jeder solche Termin trägt eine Art, die über das Symbol entscheidet, mit dem er gezeichnet wird:

**Termin** · **Lieferung** · **Flug** · **Zug** · **Übernachtung** · **Reservierung** ·
**Mietwagen** · **Ticket** · **Bestellung** · **Anruf**

Die Art ist auch das, was den Termin als aus der Mail stammend kennzeichnet und nicht als von dir
angelegt. Öffne einen im Editor, und er sagt es: *„Dieser Termin stammt aus einer E-Mail. Wenn du ihn
bearbeitest, überschreiben spätere Nachrichten zur selben Buchung deine Änderungen nicht mehr.“*

Dieser Satz ist eine echte Regel und keine Warnung. Buchungsmail kommt in einer Folge an — eine
Bestätigung, dann eine Änderung, dann vielleicht eine Stornierung — und oft in der falschen
Reihenfolge, also überarbeitet plMail denselben Termin weiter, während mehr Mail dazu eintrifft. Eine
spätere Fassung gewinnt anhand der Versionsnummer, die die absendende Stelle angegeben hat,
ersatzweise anhand des Eintreffens der Mail; eine überholte Aussage wird trotzdem festgehalten, damit
„warum steht das in meinem Kalender?“ beantwortbar bleibt. Eine Stornierung setzt den Status und
streicht den Termin durch; sie löscht die Zeile nie.

Sobald du den Termin selbst bearbeitest, hört das auf. Deine Fassung ist die, die bleibt.

### Kein Termin

Ein aus der Mail gelesener Termin hat im Editor eine Schaltfläche **Kein Termin**, und die ist etwas
anderes als Löschen. Löschen heißt „ab jetzt nicht mehr“. Verwerfen heißt „das war nie ein Termin“,
und nur das Zweite ist es wert, gemerkt zu werden:

> Diesen Termin entfernen und nicht wieder anlegen? Spätere E-Mails zur selben Buchung tragen ihn
> nicht erneut in den Kalender ein.

Gemerkt wird dabei die Identität der Buchung und nicht die Zeile, sodass die *nächste* Nachricht zu
derselben Buchung ebenfalls abgewiesen wird — und genau die wäre es, die sonst eine zweite Kopie
dessen zurückbrächte, was du gerade weggeworfen hast. Es überlebt außerdem einen erneuten Lauf der
Auswertung über deine gespeicherte Mail, den plMail immer dann macht, wenn das Lesen besser geworden
ist.

Nur ausgelesene Termine lassen sich verwerfen. Hinter einem von Hand angelegten Termin steht keine
Aussage, und nichts würde ihn je wieder anlegen.

## Daten in Prosa

Eine Nachricht mit „sollen wir Donnerstag um 14:00 sagen?“ trägt keine Auszeichnung und keine
Einladung. plMail liest sie trotzdem und tut dann das einzig Ehrliche, was zur Verfügung steht: Es
bietet sie an.

Über der Nachricht erscheint eine Vorschlagskarte mit gestricheltem Rand und ohne Kalenderfarbe, weil
noch nichts in einen Kalender eingetragen wurde — sie sagt *„In dieser E-Mail gefunden — im Kalender
steht noch nichts.“* Darunter steht der Satz, aus dem gelesen wurde, wörtlich zitiert, und darin
liegt der Sinn der Karte: Eine Vermutung, deren Beleg man sehen kann, ist in einer Sekunde zu
beurteilen, ein nacktes Datum mit einer Hinzufügen-Schaltfläche ist überhaupt nicht zu beurteilen.

**In den Kalender** schreibt den Termin, und zwar in den Kalender, in den das Konto der Nachricht
einsortiert, und behält den Satz als Beschreibung des Termins, damit die Begründung die Karte
überlebt. Der Termin ist von da an ein ganz gewöhnlicher: keine „in deiner E-Mail gefunden“-Hinweise,
keine Abgleicherei, die ihn überarbeitet, volle Zuversicht — du hast den Satz gelesen und ihm
zugestimmt.

**Kein Termin** merkt sich die Ablehnung genauso, wie es ein Verwerfen tut, sodass das Datum nicht
erneut angeboten wird, wie oft die Nachricht auch gelesen wird.

### Wann plMail bereit ist zu raten

Durchgehend Genauigkeit vor Vollständigkeit, denn die beiden Fehler kosten nicht dasselbe: Ein
entgangener Vorschlag ist eine Karte, die niemand sieht, und eine Karte, die anbietet, „Sale endet
Freitag!“ in den Kalender zu legen, ist der Moment, in dem jemand nach dem Schalter sucht. Jede Regel
lehnt im Zweifel ab.

Eine Nachricht kommt nur in Betracht, wenn **alle** diese Punkte zutreffen:

- Sie ist kein Entwurf und gehört jemandem.
- Sie wurde beim Eintreffen nach **Allgemein** einsortiert — Massen-, Werbe- und Mailinglisten-Mail
  fällt heraus.
- Sie trägt keine Listen- oder Bulk-Header, auch wenn sie trotzdem unter Allgemein gelandet ist. Ein
  Laden, dem du einmal geschrieben hast, bleibt ein Laden.
- Eine deiner eigenen Adressen steht in **An** oder **Cc**. Ein Datum, das an eine Liste geht, ist
  eine Ankündigung; ein Datum, das an dich geht, ist eine Verabredung. Diese Regel entfernt das
  meiste, was die vorigen überlebt hat.
- Es gibt nichts, was eine Auswertung lesen könnte — kein Kalenderteil, keine Besprechungsmarkierung,
  keine schema.org-Auszeichnung. Ein echter Termin ist unterwegs und wird besser sein als eine
  Vermutung.
- Das Datum liegt nicht in der Vergangenheit und höchstens ein Jahr voraus, gemessen am **Datum der
  Nachricht selbst** und nicht an jetzt, sodass erneutes Lesen alter Mail zu dem Urteil kommt, zu dem
  es an dem Tag gekommen wäre, an dem die Mail ankam.
- Nichts daran wurde zuvor abgelehnt.

Und der Text selbst muss eindeutig sein. Gelesen werden ausdrückliche und halb ausdrückliche Formen
auf Deutsch und Englisch — `04.08.2026 um 14 Uhr`, `4. August 2026, 14:00`, `2026-08-04 14:00`,
`Samstag um 15 Uhr`, `Saturday at 3pm`, `tomorrow at 9`, `next Tuesday 10:30` — mit einer Dauer,
sofern die Mail eine nennt (`2 Stunden`, `90 Minuten`, `2 hours`).

Drei Regeln prägen das Lesen:

- **Ein Datum und eine Uhrzeit, im selben Satz, nahe beieinander.** Ein Datum allein wird abgelehnt,
  denn „gültig bis 31.12.2026“ in einer Fußzeile ist ein Datum. Eine Uhrzeit allein wird aus
  demselben Grund abgelehnt, nur fehlt hier der Tag statt der Stunde. Und ein Absatz, der in seiner
  ersten Zeile eine Frist und in seiner letzten eine Öffnungszeit nennt, stellt zwei Tatsachen fest
  und keine Verabredung.
- **Der erste Satz, der beides hergibt, gewinnt** — nicht das erste Datum in der Nachricht.
  Signaturblöcke, Copyright-Jahre und Abmeldefußzeilen tragen alle Daten, und keines davon trägt
  eines neben einer Uhrzeit.
- **Relative Wörter beziehen sich auf die Nachricht.** „Samstag“ in einer Mail, die an einem Freitag
  gesendet wurde, meint auch ein Jahr später noch diesen Samstag.

Was ausdrücklich abgelehnt wird: eine zweistellige Jahreszahl (`04.08.26`), Tag und Monat in Ziffern
ganz ohne Jahr (`04.08.`), ein Datum mit Schrägstrichen, das die eigene Locale als unmöglich liest
(`13/25/2026`), und eine nackte Stunde, die kein *um* und kein *at* einleitet — „Raum 3, Plätze 12“
ist nicht halb eins. Stunden werden auf einer 24-Stunden-Uhr gelesen, sofern nichts anderes dabei
steht, `tomorrow at 9` ist also neun Uhr morgens.

Ausgeschrieben **wird** `4. August` angenommen: Der Monatsname nimmt die Mehrdeutigkeit, und das Jahr
ergibt sich vorwärts aus der Nachricht.

## Demnächst

Wenn etwas, das plMail in deiner Mail gefunden hat, ansteht, erscheint in der Kopfleiste eine
Schaltfläche mit dem Symbol des nächsten Dings — ein Flugzeug, ein Paket, ein Zug. Ein Druck darauf
öffnet **Demnächst**: alles aus der Mail Gelesene, das in den nächsten zwei Wochen fällig ist, das
Nächste zuerst, bis zu zwölf Einträge.

Jede Zeile nennt das Symbol der Art, wann es ist, was es ist und aus welcher Nachricht es stammt. Das
Letzte ist das, worauf es ankommt: Jede Zeile ist eine Vermutung, die ein Programm über deine Mail
angestellt hat, und „warum steht das in meinem Kalender?“ muss mit einem Klick beantwortbar sein,
sonst lässt sich die Vermutung gar nicht prüfen. Eine Zeile, deren Nachricht inzwischen weg ist, wird
ohne den Link gezeichnet statt mit einem toten.

Die Schaltfläche ist bewusst nur da, wenn es etwas zu öffnen gibt. Ein Bedienelement, das immer da
ist und meistens „nichts in Sicht“ sagt, erzieht Menschen dazu, es nicht zu drücken; eines, das
auftaucht, wenn ein Paket ansteht, ist eine Nachricht.

Zwei Dinge stehen **nicht** in dieser Liste, und beides ist Absicht:

- **Termine, die du selbst eingetippt hast.** Die Liste handelt davon, was plMail gelesen hat, und
  nicht von deinem Kalender — der ist einen Klick entfernt und ist besser darin, ein Kalender zu
  sein.
- **Vorschläge.** Ein Datum, das aus einem Satz gelesen und noch nicht angenommen wurde, hat nichts
  in einer Liste verloren, der die Leute glauben, dass sie wahr ist, und ohne den Satz, aus dem es
  stammt, ließe es sich ohnehin nicht beurteilen. Ein Vorschlag wird dort beantwortet, wo sein Beleg
  steht: an der Nachricht.

Es werden nur sichtbare Kalender gelesen, einen Kalender auszublenden versteckt seine Buchungen hier
also ebenfalls.

Davon getrennt trägt die Kalenderschaltfläche in der Kopfleiste einen farbigen Punkt für das, was
**heute** noch bevorsteht — innerhalb der Stunde, in wenigen Stunden oder später heute. Es sind drei
Bänder und kein Countdown, denn eine Farbe, von der man eine Zahl ablesen muss, ist kein Punkt, und
es zählt nur der heutige Tag: Eine Besprechung, die vor einer Stunde endete, ist keine Neuigkeit, und
die von morgen ist nicht dringend.

## Mail erneut lesen, die du schon hast

Die Auswertung und das Erkennen von Vorschlägen werden beide mit der Zeit besser, und beide lassen
sich über bereits synchronisierte Mail erneut laufen lassen:

```bash
docker compose exec php php bin/console app:backfill events
docker compose exec php php bin/console app:backfill proposals
```

Ohne Argument aufgerufen, listet `app:backfill` die verfügbaren Aufgaben auf und fragt nach. Ein
erneuter Lauf ist ungefährlich: Was du verworfen hast, bleibt verworfen, und was du selbst bearbeitet
hast, wird nicht überschrieben.

## Fallstricke

**Deine Antwort kann gespeichert werden, ohne dass die Organisation davon erfährt.** Die beiden
Hälften sind mit Absicht unabhängig. Kann die Antwort nicht gesendet werden, sagt die Meldung das —
und das ist der einzige Hinweis, den du bekommst. Prüf, ob das Konto, auf dem die Einladung ankam,
überhaupt senden kann.

**Für Daten in Prosa wird ausschließlich Mail unter Allgemein gelesen.** Ist eine Nachricht, von der
du einen Vorschlag erwartet hast, in einer anderen Kategorie gelandet, trägt sie Listen-Header oder
war sie an eine Liste statt an dich gerichtet, erscheint keine Karte, und es ist nichts kaputt. Das
ist die Regel, die das meiste Rauschen entfernt, und es ist auch die, die gelegentlich etwas
entfernt, was du haben wolltest.

**Einen Vorschlag oder einen ausgelesenen Termin zu verwerfen, gilt dauerhaft für diese Buchung.**
Beides schreibt eine Ablehnung, geschlüsselt auf die Identität der Buchung, spätere Mail zu derselben
Buchung wird also ebenfalls abgewiesen. Es gibt keinen Bildschirm zum Rückgängigmachen; leg den
Termin von Hand an, wenn du es dir anders überlegst.

**Einen ausgelesenen Termin zu bearbeiten, beendet, dass er der Buchung folgt.** Das ist es, was der
Hinweis im Editor meint. Korrigierst du eine falsche Zeit von Hand, verschiebt eine spätere Mail, die
die Besprechung verlegt, sie nicht mehr für dich.

**Einen ausgelesenen Termin zu löschen, ist nicht dasselbe wie ihn zu verwerfen.** Ein einfaches
Löschen hält, bis die nächste Nachricht zu dieser Buchung ankommt oder bis die Auswertung das nächste
Mal erneut läuft — und dann ist er wieder da. **Kein Termin** ist das, was hält.

**Eine abgesagte Besprechung bleibt im Kalender.** Sie wird durchgestrichen statt entfernt, denn die
brauchbare Antwort auf „war da heute nicht etwas?“ ist der durchgestrichene Eintrag und nicht eine
Lücke. Lösch sie selbst, wenn sie weg soll.

**Demnächst reicht genau zwei Wochen weit.** Ein Flug in drei Wochen steht im Kalender und nicht in
der Liste. Zwei Wochen sind so weit voraus, wie eine Buchung noch etwas ist, weswegen man heute
handeln würde.

---

**Verwandt:** [Kalender](calendar.md) · [Erinnerungen](calendar-alerts.md) ·
[Verbundene Kalender](calendar-sync.md)

**Wie es funktioniert:** [Termine aus Mail auslesen](../internals/event-extraction.md) — wie aus
einer Einladung und wie aus einem Satz ein Kalendereintrag wird, und wie spätere Mail ihn
überarbeitet.
