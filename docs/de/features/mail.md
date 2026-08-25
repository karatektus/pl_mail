<!-- translated-from: features/mail.md sha1:379d57cb5ef3c1b66d58d115dfcffc017b92f280 -->

# Mail

Alles, was zwischen dem Eintreffen einer Nachricht und dem Moment passiert, in dem du mit ihr
fertig bist: sie lesen, sie später wiederfinden, sie weglegen und antworten.

![Der plMail-Posteingang](../screenshots/inbox.png)

## Die Seitenleiste, und was die Listen bedeuten

Die linke Leiste ist nach Labels geordnet und nicht nach Ordnern, denn ein Label reicht über
alle Konten, die du verbunden hast. Ein Klick auf **Posteingang** zeigt die Posteingänge aller
Konten auf einmal; ein Klick auf ein Label, das du angelegt hast, zeigt alles, was es trägt —
gleich, wo es angekommen ist.

| Eintrag | Was er auflistet |
|---|---|
| **Posteingang** | Alles, was noch im Posteingang liegt, über alle Konten hinweg, neueste Konversation zuerst |
| **Markiert** | Konversationen mit mindestens einer markierten Nachricht |
| **Zurückgestellt** | Konversationen, die auf ihre Weckzeit warten |
| **Gesendet**, **Entwürfe**, **Papierkorb** | Das jeweilige Systemlabel, über alle Konten hinweg |
| **Archiv** | Standardmäßig ausgeblendet — schalte das Archiv-Label unter **Einstellungen → Labels** sichtbar, um es zu bekommen |
| **Konten** | Eine Zeile je Konto; ein Klick darauf öffnet den Posteingang dieses Kontos |

Ein Klick auf ein Konto öffnet **dessen Posteingang** — dieselbe Frage, die der Posteingang ganz
oben stellt, nur über ein Postfach statt über alle. Gesendet, Entwürfe, Spam und Papierkorb dieses
Kontos stehen als Ordnerzeilen darunter, einen Klick weiter; die Kontozeile ist kein Archiv über
alles, was das Konto je enthalten hat.

Klappst du unter **Konten** ein Konto auf, siehst du die Labels, die es dort tatsächlich gibt.
Diese Liste ist absichtlich schmaler als die Label-Liste der Seitenleiste selbst: die
Seitenleiste meint „über alle Konten hinweg“, die Liste pro Konto beantwortet „was hat dieses
Postfach wirklich“. Welches Konto du aufgeklappt gelassen hast, merkt sich der Server und nicht
der Browser — die Seitenleiste wird also bereits aufgeklappt gezeichnet, statt nach dem
Seitenaufbau aufzuspringen.

Die Überschriften **Labels** und **Konten** klappen zu. Eine davon zuzuklappen wird an deinem
Konto gemerkt und nicht am Browser, folgt dir also auf dein Telefon, und sie wird bereits zugeklappt
gezeichnet, statt sich kurz nach dem Seitenaufbau zuzuschnappen. Was zuklappt, ist der ganze
Abschnitt — eine Wand aus Labels ist das, was die Konten aus einem kurzen Fenster nach unten
drückt —, ein verschachtelter Label-Baum klappt für alle, die verschachteln, weiterhin für sich
allein zu.

Ein Ungelesen-Badge ist ein Link. Ein Klick öffnet dieselbe Ansicht ohne alles bereits Gelesene —
Posteingang, **Markiert**, **Archiv**, **Spam**, **Zurückgestellt** oder ein Label, unter einem
Konto oder über alle hinweg —, sodass die Zahl auf der Pille genau die Anzahl der Zeilen ist, auf
denen du landest. Genau darum zählt sie Konversationen und nicht ungelesene Nachrichten: Eine
Konversation mit drei ungelesenen Antworten ist eine Zeile, und ein Badge, den du anklickst, muss
sagen, wie viele Zeilen er dir gibt.

Solange eine Liste eingegrenzt ist, sagt sie **Nur ungelesene**, mit **Alle anzeigen** daneben —
sonst sieht eine gefilterte Liste aus wie eine, in der wirklich nichts liegt. Alles andere an der
Ansicht übersteht den Filter: das Konto, auf das sie eingegrenzt war, die Sortierung, der Tab. So
bringt dich **Alle anzeigen** dorthin zurück, wo du warst, und nicht irgendwohin daneben.

**Papierkorb** und **Entwürfe** sind keine Links, die Sammelzahl über **Labels** ebenfalls nicht.
Die ersten beiden tragen eine Gesamtzahl statt eines Ungelesen-Zählers, dort wird also gar nicht
nach Ungelesenem gefragt; die Sammelzahl steht für mehrere Listen auf einmal und hat keine einzelne,
die sie öffnen könnte.

Eine zugeklappte Überschrift **Labels** trägt das Ungelesene, das darunter verborgen ist, als
Anzahl von Konversationen. Es ist bewusst nicht die Summe der einzelnen Zahlen: Eine Konversation
unter zwei Labels würde doppelt gezählt, und die Überschrift würde mehr versprechen, als das
Aufklappen zeigen kann. **Konten** bekommt keine solche Zahl, weil es keine ehrliche gibt — jene
Zähler gelten je Konto, und geladen ist immer nur das eine, das du aufgeklappt hast.

Jede Liste umfasst fünfzig Konversationen pro Seite. **Neuer** und **Älter** in der
Werkzeugleiste blättern.

## Tabs

Der Posteingang ist in die fünf Gmail-Kategorien aufgeteilt — **Allgemein**, **Soziale
Netzwerke**, **Werbung**, **Updates** und **Foren**. Bei einem Gmail-Konto vertraut plMail
Gmails eigenen `CATEGORY_*`-Labels. Bei allem anderen leitet es die Kategorie aus Headern ab,
die ohnehin schon gespeichert sind — darum braucht eine Neukategorisierung nie eine erneute
Synchronisierung.

Jeder Tab liest sich wie bei Gmail: ein Symbol — ausgefüllt auf dem Tab, auf dem du gerade bist
— und, solange in einer Kategorie Post liegt, die dir nie gezeigt wurde, ein Fähnchen **„3 neu”**
in der Farbe der Kategorie, mit einer zweiten Zeile, die nennt, von wem diese neue Post ist,
neueste zuerst. Das ist mit Absicht die einzige Zahl auf einem Tab: Ungelesenes hat schon den
Badge in der Seitenleiste und die fetten Zeilen selbst, also behält der Tab das eine, das nur er
sagen kann. Die Leiste ist live — Fähnchen und Absendernamen aktualisieren sich an Ort und
Stelle, wenn Post eintrifft, ohne auf ein Neuladen zu warten, und ein Hinweis verschwindet in
dem Moment, in dem du seinen Tab anschaust.

Eine Ausnahme lohnt sich zu kennen: Ein Absender, dem du selbst geschrieben hast, wird zurück
nach Allgemein geholt, ganz gleich, welcher Massenmail-Header auf der Nachricht steht. Öffne
den Bereich **Details** einer Nachricht, dann sagt die Zeile **Kategorie**, welche Regel
entschieden hat und auf welchen Header oder welche Domain sie angesprungen ist.

## Die Markierung „Neu“

Eine Konversation ist **neu**, bis ihre Zeile dir *gezeigt* wurde. Nicht geöffnet — gezeigt.
Scrollst du im Posteingang an etwas vorbei und klickst es nie an, verschwindet die Markierung, denn
es überrascht dich nicht mehr; die Konversation bleibt ungelesen, denn gelesen hast du sie immer
noch nicht. Das sind zwei verschiedene Fragen, und plMail behält beide Antworten.

Sie erscheint als gefülltes Fähnchen **Neu** neben dem Absender in der Zeile, als Zähler-Fähnchen
**„3 neu”** auf den Kategorie-Tabs des Posteingangs — der Hinweis, den du aus Gmail kennst — und
als stiller Punkt, ohne Zahl, auf den Labels und Rollen der Seitenleiste und auf Markiert.
Fähnchen wie Punkt bedeuten „hier ist etwas angekommen”, und genau das sagt ein
Ungelesen-Zähler nicht.

Nichts bleibt länger als **24 Stunden** nach seiner letzten Nachricht neu, ob du die Zeile je zu
sehen bekommen hast oder nicht. Eine Markierung, die sich nur abtragen lässt, indem man jede Zeile
anschaut, ist eine Schuld und keine Markierung — also läuft sie von selbst ab.

Auch Suchergebnisse räumen das Fähnchen ab: Eine Zeile, deren Absender und Betreff du gerade in
einer Trefferliste gelesen hast, ist dir gezeigt worden, in welcher Liste auch immer.

## Das Radar

plMail liest bestimmte Fakten aus Mails heraus, während sie ankommen: Sendungsnummern aus
Versandbestätigungen, Flüge aus Airline-Buchungen und Check-in-Mails, Veranstaltungstickets,
Issue- und Pull-Request-Aktivität aus GitHub-Benachrichtigungen, Einmalcodes zum Anmelden,
Rechnungen mit Betrag und Fälligkeit sowie Abos, die sich demnächst verlängern, und Testphasen, die
auslaufen. Jeder Fund wird eine kleine Karte.
Was ein Datum trägt, reiht sich im Kalender-Panel **Demnächst** ein, unter **Auf deinem Radar**;
eine Konversation, in der etwas gefunden wurde, zeigt ihre Karten außerdem in einer Leiste
**In dieser Konversation gefunden** über den Nachrichten.

Auch ein Shop, der nie einen Zusteller nennt, ergibt ein Paket. Amazon nennt eine Bestellnummer und
einen Link in den eigenen Tracker und nirgends eine Sendungsnummer — genau das trägt dann die Karte.
Und ein Liefertag, der in Worten statt in Ziffern steht ("Arriving today", "Ankunft Montag"), wird
gegen den Eingang der Mail aufgelöst und nie gegen die Uhr: Wer eine alte Mail noch einmal liest,
landet auf demselben Tag wie beim ersten Mal.

### Die Leiste über der Mailliste

Datierte Insights erscheinen außerdem als Band direkt über der Mailliste — höchstens drei, das
Nächste zuerst, jeweils mit dem, was es ist, wann es ist, und der einen Schaltfläche, die sich
lohnt (**Verfolgen**, oder die Sache auf GitHub). Es ist dieselbe Menge, die auch das Radar-Panel
führt, nur dort gesagt, wo du ohnehin bist: Das Panel beantwortet "Was steht an?", wenn du danach
suchst — das Band sagt dir, dass ein Paket in Zustellung ist, wenn du nicht gesucht hast.

Das Band hält sich selbst aktuell. Findet ein Durchlauf etwas Neues, aktualisiert es sich an Ort
und Stelle, ohne Neuladen und ohne dass du auf einer bestimmten Seite sein musst.

**Das ✕ am Band heißt "gerade nicht", nicht "nie".** Blendest du es aus, bleibt es weg, bis ein
Insight auftaucht, das es dir noch nie gezeigt hat — ein Paket, das morgen losgeht, holt es zurück,
das eben Weggewischte bleibt fort. Dauerhaft ist das Ausblenden einer einzelnen Karte über ihr
`⋮`-Menü, und wenn die letzte Karte geht, geht das Band mit. Ganz loswerden kannst du es mit einem
Schalter unter **Einstellungen → Radar**; der blendet nur das Band aus — die Fakten werden weiter
gelesen und landen weiter im Radar-Panel und in der Konversation.

Das Band nimmt Höhe und nie Breite: Keine Spalte gibt dafür ein Pixel her, es wird erst geholt,
wenn die Mail schon auf dem Schirm ist, und an einem gewöhnlichen Tag, an dem es nichts zu sagen
gibt, ist es gar nicht da.

Das Auslesen ist deterministisch und lokal. Es läuft auf deinem eigenen Server, gegen
Absender-Domains, Header und feste Muster — Sendungsnummern, Flugcodes, `#123` —, ohne
Cloud-Dienst und ohne Modell: Nichts rät, und eine Mail, die auf kein bekanntes Muster passt,
ergibt schlicht nichts. Das ist ein bewusster Tausch — das Radar übersieht lieber ein Paket, als
eines zu erfinden.

Jede Quelle hat ihren eigenen Schalter unter **Einstellungen → Radar**. Schaltest du eine aus,
entstehen aus ihr keine neuen Karten mehr; was sie schon gefunden hat, bleibt, bis du es
ausblendest. Die Menge der Quellen ist absichtlich erweiterbar — ein Extraktor, der zu einem Build
hinzukommt, trägt sich selbst auf dieser Einstellungsseite ein und läuft los, ohne dass sich sonst
etwas ändert.

Neue Extraktoren lesen alte Mails nicht von allein noch einmal. `app:backfill insights` geht die
Mails, die schon in der Datenbank liegen, einmal durch und reicht sie jedem eingeschalteten
Extraktor — derselbe Durchlauf, den auch die anderen Backfill-Aufgaben machen.

Einmalcodes sind die einzige Quelle, deren Zeitpunkt ein Ablauf ist und kein Termin. Die Karte
trägt den Moment, an dem der Code laut Mail nicht mehr gilt — "gültig für 10 Minuten", "gültig bis
09:30" —, und verschwindet damit vom Radar, wenn er tot ist, statt dort weiter benutzbar
auszusehen. Nennt die Mail keine Gültigkeit, trägt die Karte gar keine Zeit: Zehn Minuten zu raten
würde entweder einen Code zurückziehen, der noch geht, oder schlimmer, einen toten frisch
aussehen lassen.

### Eine Mail melden, die das Radar übersehen hat

Weil nichts rät, ergibt eine Mail in einer Form, für die noch niemand einen Parser geschrieben hat,
schlicht nichts — und das sieht genauso aus wie eine Mail, in der nichts drinsteht. **Fehlendes
Insight melden** im `⋮`-Menü einer Nachricht ist der Weg zu sagen, welches von beidem es war. Ein
kurzer Dialog fragt, was plMail hier hätte erkennen sollen — "Das ist eine Rechnung, fällig am 3."
— und legt die Meldung ab.

**Melden gibt die Mail weiter.** Die Meldung nimmt eine Kopie der Mail mit: Absender, Betreff,
Ankunftszeit und den Anfang des Textes, dazu deine Notiz. Diese Kopie landet in einem Bereich, den
deine Administration lesen und herunterladen kann, und der Dialog sagt dir das, bevor du
abschickst — denn ein Parser lässt sich nur an der Form einer echten Mail schreiben. Melde die
Mail, deren Form erkannt werden soll, und nicht eine, deren Inhalt du lieber für dich behältst.

Meldest du dieselbe Mail noch einmal, entsteht keine zweite Meldung — du korrigierst die erste,
deine Notiz steht dabei zum Ändern bereit. Was die Administration mit dem Stapel macht, steht unter
[Administration → Gemeldete Mails](admin.md#gemeldete-mails).

## Konversationen

Antworten klappen zu einer Konversation zusammen, von alt nach neu sortiert, die letzte
Nachricht aufgeklappt. Jede Nachricht hat ihr eigenes Menü: **Markieren**, **Archivieren**,
**Als ungelesen markieren**, **Diese Nachricht löschen**, **Drucken** und **Original anzeigen**.

**Original anzeigen** setzt die Nachricht aus der gespeicherten Header-Zuordnung und dem
dekodierten Text wieder zusammen — ein roher RFC-822-Blob wird nicht aufbewahrt — und stellt
die SPF-, DKIM- und DMARC-Urteile daneben, sofern der Anbieter einen
`Authentication-Results`-Header hinterlassen hat. Sowohl dieser Punkt als auch **Drucken**
öffnen in einem eigenen Tab.

HTML-Texte werden direkt eingebettet dargestellt und nicht in einem iframe. Vorher läuft der
Text genau einmal, beim Import, durch einen Sanitizer: `cid:`-Verweise werden auf plMails
eigene Anhang-Route umgeschrieben, `<style>`-Blöcke werden auf die Elemente heruntergezogen,
für die sie galten, und Skripte, Formulare, iframes und Klassen fallen weg. Links werden
gezwungen, außerhalb der App zu öffnen.

## Labels

Labels legst du selbst an, über **Label erstellen** in der Seitenleiste oder unter
**Einstellungen → Labels**. Ein Label hat einen Namen, eine von neun Farben — Grau, Rot,
Orange, Bernstein, Grün, Petrol, Blau, Violett oder Rosa — und wahlweise ein übergeordnetes
Label, worüber die Verschachtelung läuft. Ein Label lässt sich aus der Seitenleiste ausblenden,
ohne es zu löschen.

Ein neu angelegtes Label ändert beim Anbieter nichts. Gmail bekommt es, sobald es das erste Mal
wirklich auf eine Nachricht angewendet wird; bei einfachem IMAP wird nie ein Ordner dafür
erzeugt, denn ein Ordner spielt erst dann eine Rolle, wenn eine Nachricht körperlich umzieht.
Strukturelle Änderungen — Umbenennen und Löschen — nach außen zu spiegeln, ist abgeschaltet,
solange du es nicht pro Konto einschaltest; siehe [Konten und Aliase](accounts.md).

Ein Label zu löschen entfernt es aus **jedem** Konto und nimmt untergeordnete Labels mit.
Nachrichten werden nicht gelöscht; sie behalten ihre übrigen Labels. Löschen kannst du eines aus dem
Label-Dialog der Seitenleiste ebenso wie aus **Einstellungen → Labels**, und die Bestätigung sagt
klar, dass das Label aus jedem Konto verschwindet und wie viele untergeordnete Labels mitgehen —
statt dich etwas bestätigen zu lassen, dessen Reichweite sie verschwiegen hat.

## Suche

Das Suchfeld steht auf jeder Seite oben und antwortet unter `/mail/search`. Freier Text läuft
als echte Volltextabfrage gegen Postgres — mit Stammformen und Rangfolge, nicht als Suche nach
Teilzeichenketten — und lässt sich mit allen Operatoren kombinieren, die du eintippst. Hostnamen
und Adressen werden nach ihren Bestandteilen indiziert: `wirhub` findet also auch eine Mail, in
deren Text nur `help.wirhub.de` steht.

| Operator | Nimmt | Trifft |
|---|---|---|
| `from:` | einen Namen oder eine Adresse | Absenderadresse oder Anzeigename |
| `to:` | eine Adresse | die An-Liste |
| `cc:` | eine Adresse | die Cc-Liste |
| `subject:` | Wörter | die Betreffzeile |
| `label:` | einen Labelnamen | ein Label, das du angelegt hast |
| `has:attachment` | — | akzeptiert auch `has:attachments` |
| `is:unread`, `is:read`, `is:starred` | — | Lesestatus und Markierungen |
| `in:` | einen Postfachnamen | siehe unten |
| `after:` | ein Datum | empfangen am oder nach |
| `before:` | ein Datum | empfangen vor |

`in:` nimmt `inbox`, `sent`, `drafts` (oder `draft`), `trash` (oder `deleted`, oder `bin`),
`junk` (oder `spam`), `archive` (oder `archived`) und `snoozed`. Werte in Anführungszeichen
bleiben zusammen, `from:"Ada Lovelace"` funktioniert also.

Vorschläge erscheinen beim Tippen — und **echte Treffer** ebenfalls: die zehn neuesten passenden
Konversationen, unter den Operator-Vorschlägen, etwa ab dem dritten Zeichen. Sie sind eine Vorschau
und nicht die Antwort: Es laufen nur die Durchgänge, die schnell genug für jeden Tastendruck sind.
Ein Treffer, den es nur als Bruchstück mitten in einem Wort in einem langen Text gibt, ist deshalb
nicht dabei. Die Eingabetaste startet die vollständige Suche — dort steckt er.

Sobald deine Anfrage einen Operator trägt, tritt die Vorschau ganz zurück. Sie kann `is:unread`
oder `label:` nicht einlösen, und zehn ungefilterte Zeilen unter einer gefilterten Anfrage sehen
aus wie zehn eingelöste — das wäre schlimmer, als gar nichts zu zeigen.

Operatoren werden aus der Liste oben vervollständigt;
`from:`, `to:` und `cc:` vervollständigen gegen deine gesammelten Kontakte, denn sich nicht zu
erinnern, wie ein Absender sich schreibt, ist meistens der Grund, warum du das Suchfeld
geöffnet hast. Die Eingabetaste übernimmt den hervorgehobenen Vorschlag, solange die Liste
offen ist, und schickt die Suche sonst ab; die Tabulatortaste übernimmt ihn, die Pfeiltasten
bewegen sich darin, und das erste Escape schließt die Liste, während ein zweites das Feld
leert. Die letzten acht Suchen bleiben im Browser und werden angeboten, sobald das Feld leer
und aktiv ist.

Ein Operator, den plMail nicht erfüllen kann — `is:important`, `in:nowhere`, ein Datum, das
keines ist — wird zu gewöhnlichem Text, statt weggeworfen zu werden. Das ist Absicht: ein
verworfener Filter ist ein Filter, den du verlangt und nicht bekommen hast, und heraus käme
eine Seite mit allem, was sich liest, als wäre die Suche ignoriert worden. Als freier Text
findet er wenig bis nichts, und das ist immerhin die Wahrheit.

Eine Abfrage, die aus nichts als einem halb getippten Operator besteht — `from:` ohne Wert —
liefert eine leere Seite und nicht das ganze Postfach.

Ergebnisse kommen **Neueste zuerst**. Der Schalter neben der Seitenblätterung stellt auf
**Relevanteste zuerst** um, also auf den Volltext-Rang — der beste Treffer führt, wann immer er
angekommen ist. Was du wählst, wird für deine nächste Suche gemerkt, und das Blättern behält es
bei. Ein Wechsel der Reihenfolge beginnt wieder auf der ersten Seite, denn Seite vier der einen
Reihenfolge ist Seite vier von nichts in der anderen.

Die Suche erfasst nur Mail, die synchronisiert wurde. plMail synchronisiert alles, was ein Konto
hält, und es gibt keine Einstellung, die das begrenzt — ein großes Postfach ist also erst einige
Läufe nach dem Hinzufügen vollständig durchsuchbar und nicht sofort; siehe
[Konten und Aliase](accounts.md).

## Mail bearbeiten

Die Knöpfe in der Zeile und die Werkzeugleiste tun dasselbe, die Werkzeugleiste über alles
Ausgewählte. Neben der Auswahl aller Konversationen steht ein Menü mit **Alle**, **Keine**,
**Gelesene**, **Ungelesene** und **Markierte**.

Markieren, **Archivieren**, **Löschen**, **Label vergeben**, **Als gelesen markieren** /
**Als ungelesen markieren** und **Zurückstellen** gelten für eine ganze Konversation oder für
eine einzelne Nachricht, und alle wandern zusätzlich nach außen zum Anbieter, statt nur zu
ändern, was du hier siehst. Archivieren ist als Entfernen des Posteingangs-Labels modelliert
und als sonst nichts — wo die Nachricht danach landet, ist Sache des Anbieters.

**Aktualisieren** in der Werkzeugleiste reiht für jedes deiner aktiven Konten eine
Synchronisierung ein und dreht sich, bis diese Aufträge abgearbeitet sind.

## Zurückstellen

Zurückstellen ist Archivieren mit Wecker. Die Konversation verlässt den Posteingang sofort —
auch beim Anbieter, nicht nur in plMails Sicht darauf —, bekommt das Label Zurückgestellt und
kommt zurück, wenn ihre Zeit um ist.

Das Menü bietet **Später heute**, **Morgen**, **Dieses Wochenende**, **Nächste Woche**, **Datum
und Uhrzeit wählen** und, bei bereits Zurückgestelltem, **Zurückholen**. Später heute ist 18:00
Uhr und wird nur angeboten, solange das noch bevorsteht; die anderen drei landen um 08:00 Uhr
am nächsten Tag, am kommenden Samstag und am kommenden Montag. Alle vier werden in deinem
Browser berechnet, denn der Server sieht für die Sitzung nie eine Zeitzone und würde „morgen
früh“ sonst dort auflösen, wo der Container sich vermutet.

`app:mail:wake-snoozed` läuft jede Minute und ist das, was Konversationen zurückbringt. Eine
geweckte Konversation wird als **ungelesen** markiert — das ist der Sinn der Funktion, denn
eine Konversation, die in dem Zustand wiederkommt, in dem du sie verlassen hast, hast du
bereits zu überscrollen gelernt. Der Lesestatus, den sie hatte, ist tatsächlich verloren.

Die Liste Zurückgestellt ist nach der letzten Nachricht der Konversation sortiert, wie jede
andere Liste auch, und nicht nach der Weckzeit.

## Anhänge

Anhänge erscheinen als Chips unter der Nachricht, bei Bildern mit einer Vorschau. Ein Klick
lädt sie herunter; nur Bilder werden je eingebettet ausgeliefert, damit per E-Mail geliefertes
HTML niemals auf plMails eigener Herkunft laufen kann.

**Ein PDF öffnet sich in einem Leser**, statt heruntergeladen zu werden — Seiten, Zoom, und der
Download-Knopf bleibt in der Werkzeugleiste. Gezeichnet wird das Dokument von plMails eigenem Code
in deinem Browser, nicht vom PDF-Plugin des Browsers; genau das hält die Regel oben aufrecht: die
Datei wird weiterhin als Anhang ausgeliefert, und nichts darin darf auf plMails Herkunft laufen.
Dafür wird nichts irgendwohin geschickt — gelesen wird auf deinem Rechner.

### Ein PDF unterschreiben

**Unterschreiben** in der Werkzeugleiste des Lesers öffnet ein Feld zum Zeichnen. Zeichne deine
Unterschrift mit Maus, Trackpad oder Finger, klick auf **Auf die Seite setzen** und zieh sie an die
richtige Stelle — der Griff in der Ecke ändert die Größe. Danach entweder **Mit unterschriebener
Kopie antworten**, was eine Antwort mit der fertigen Datei im Anhang öffnet, oder **Unterschriebene
Kopie herunterladen**.

Zwei Dinge gehören klar gesagt.

**Das ist eine sichtbare Unterschrift.** Es ist ein Bild deines Namens auf der Seite — genau so, als
hättest du das Dokument ausgedruckt, unterschrieben und wieder eingescannt, und es ist genau so viel
wert wie das. Es ist *keine* digitale Signatur im kryptografischen Sinn: es gibt kein Zertifikat,
nichts wird irgendwo registriert, und es beweist nicht, dass das Dokument danach unverändert
geblieben ist. Wenn von dir eine *qualifizierte* elektronische Signatur verlangt wird, ist das hier
nicht die richtige.

**Zum Unterschreiben verlässt das Dokument deinen Browser nicht.** Gestempelt wird auf deinem
Rechner, und plMails Server sieht das Ergebnis nur, wenn du damit antwortest. Nichts geht an einen
Signaturdienst, weil es keinen gibt.

**Einmal speichern statt jedes Mal neu zeichnen.** Unter Einstellungen → Profil gibt es neben deinem
Bild ein Feld zum Zeichnen; zeichne deine Unterschrift dort, und der Leser bekommt einen Knopf
**Gespeicherte Unterschrift verwenden**, der sie setzt, ohne dass du sie neu zeichnen musst. Sie
wird als Bild gespeichert, gehört dir, wird nur an dich ausgeliefert, und du kannst sie an derselben
Stelle wieder entfernen. Was die Unterschrift *bedeutet*, ändert das nicht — es ist so oder so
dasselbe Bild eines Namens.

Ein passwortgeschütztes PDF lässt sich hier nicht unterschreiben — der Leser sagt das, und lesen
funktioniert weiterhin. Es zu bearbeiten hieße, den Schutz zu entfernen, den es bekommen hat, und
das ist keine Entscheidung, die plMail still für dich treffen sollte.

**Speichern in** auf einem Anhang schiebt ihn hinaus in einen verbundenen Dienst — siehe
[Dateien und Integrationen](integrations.md). Das funktioniert auch für Gmail- und
Microsoft-Nachrichten, deren Anhang nie plMails Platte berührt hat; er wird beim ersten Zugriff
materialisiert.

## Verfassen

**Schreiben** öffnet ein Fenster, das unten rechts angedockt ist. Antwortest du aus einer
Konversation heraus, öffnet sich der Editor auf einem breiten Bildschirm stattdessen am Fuß der
Konversation; darunter fällt er auf das angedockte Fenster zurück. So oder so bietet das
Fenster formatierten Text, Kontakt-Vervollständigung in den Adressfeldern und eine
**From**-Auswahl, die jedes aktive Konto und jede Absendeadresse darauf auflistet.

Die Vervollständigung bietet ausschließlich **deine eigenen** gesammelten Kontakte an. Auf einer
Installation mit mehreren Personen sind die Korrespondenten der einen für die andere unsichtbar, und
eine Adresse, die zu einem anderen Konto gehört, lässt sich auf keinem Weg auf deinen Entwurf
setzen.

**Dateien anhängen** nimmt Dateien von deinem Rechner, gedeckelt auf **25 MB je Datei**. **Aus
einem Dienst anhängen** öffnet die Dateiauswahl für jeden verbundenen Dienst, aus dem plMail
herunterladen kann.

Entwürfe speichern sich zwei Sekunden nach dem letzten Tastendruck selbst, und ein Entwurf
entsteht, sobald es mindestens fünf Zeichen Text **oder** einen Betreff gibt — ein allein
getippter Betreff ist es wert, behalten zu werden. Das Fenster zu schließen, es herauszulösen oder
eine Datei anzuhängen erzwingt jeweils vorher ein Speichern. Unterhalb dieser Schwelle fragt das
Verlassen der Seite nach, statt das Getippte zu verlieren. Der Papierkorbknopf im Schreibfenster
löscht den Entwurf tatsächlich, statt das Fenster darüber zuzuklappen.

Auf dem Telefon bricht die Werkzeugleiste um, statt aus dem Bild zu scrollen, und die Sendepille
wandert hinauf in die Kopfzeile des Fensters, dorthin, wo früher der Knopf saß.

### Emoji

Der Smiley öffnet den vollständigen Unicode-Satz, mit Kategorien, Suche, Hauttönen und den zuletzt
verwendeten. Eine Auswahl fügt das Zeichen dort ein, wo der Cursor stand.

Eingefügt wird **ausschließlich** bei einer Auswahl. Tippst du `:)` oder `:smile:`, bleiben `:)`
und `:smile:` Zeichen für Zeichen in der Nachricht stehen — plMail schreibt Getipptes nicht in
Bildchen um.

Die Suche läuft in der Sprache des Fensters, denn die Emoji-Daten werden je Sprache mitgeliefert
statt nachgeladen. Sowohl diese Daten als auch die Schrift für die farbigen Emoji liefert plMail
selbst aus; zur Laufzeit wird nichts von einem CDN geholt, sodass die Auswahl auch auf einer
Installation ganz ohne Weg nach draußen funktioniert.

### Bilder in der Nachricht

Der Bildknopf, ein Einfügen aus der Zwischenablage und ein Ziehen-und-Ablegen auf den Editor tun
alle dasselbe: Das Bild kommt **in den Text**, an die Stelle, an der du es abgelegt hast, und nicht
als Anhang daneben. Dieselbe Obergrenze von 25 MB wie bei einem Anhang, und nur Bilder — alles
andere wird mit einer Begründung in der Statuszeile abgelehnt.

Eingebettete Bilder reisen als `cid:`-Teile der Nachricht, was jedes Mailprogramm auflösen kann;
die Empfängerin sieht also das Bild und keinen kaputten Verweis zurück auf plMail. Sie sind keine
Anhänge und werden auch nicht als solche gezählt: Eine Nachricht, deren einziges Bild du in den
Text gesetzt hast, trägt keine Büroklammer.

### Links

Der Linkknopf öffnet ein kleines Feld für die **URL** und eines für den **Text**, wobei der Text
mit dem gefüllt ist, was du markiert hattest. Ein bloßes `example.com` wird verstanden und als
`https://` gespeichert — es ist kein Pfad innerhalb von plMail. Verlinken lassen sich nur Web-,
Mail- und Telefonadressen; alles andere wird gleich dort abgelehnt, statt beim Speichern
stillschweigend zu verschwinden.

Klickst du einen Link an, der schon im Editor steht, zeigt dasselbe Feld sein anderes Gesicht: die
Adresse sowie **Öffnen**, **Ändern** und **Entfernen**. Es schließt sich mit Escape, bei einem
Klick daneben und sobald der Cursor den Link verlässt.

### Signatur

Signaturen wohnen unter **Einstellungen → Signaturen**. Jedes Konto hat eine, und jede seiner
Absendeadressen kann sie überschreiben. Drei Zustände sind wichtig, und sie sind nicht dasselbe:

| Zustand | Womit diese Adresse unterschreibt |
|---|---|
| **Erbt** | Mit der Signatur des Kontos. Das tut eine Adresse, solange du nichts anderes sagst. |
| **Eigene** | Mit dem, was du für diese Adresse geschrieben hast, statt mit der des Kontos. |
| **Bewusst ohne** | Mit einer eigenen Signatur, die leer gelassen wurde. Die Adresse unterschreibt mit nichts, obwohl das Konto eine Signatur hat. |

Die letzten beiden sind beide „eine eigene Signatur“; der Unterschied ist, ob sie leer ist. Genau
deshalb wird ein leeres Feld nicht als „erben“ gelesen — eine private Adresse, die ohne Signatur
sendet, auf einem dienstlichen Postfach, das mit einem Block unterschreibt, ist der ganze Grund für
diese Einstellung.

Im Schreibfenster wird die Signatur für dich eingesetzt, oberhalb des zitierten Textes, bei einer
neuen Nachricht ebenso wie bei einer Antwort, mit einem leeren Absatz davor — damit der Cursor im
Schreibraum steht und nicht mitten im Gruß. Eine **Weiterleitung** bleibt bewusst ohne Signatur —
ihr Inhalt ist die Mail, die du weitergibst, und der Cursor startet in der **An**-Zeile, denn ohne
Empfängerin geht eine Weiterleitung nirgendwohin; **Signatur einfügen** holt den Block, wenn du ihn
willst. Dieser Knopf ersetzt den Block an Ort und Stelle, statt einen zweiten anzuhängen, und
dasselbe tut ein Wechsel des Kontos in der **From**-Auswahl: Der Signaturblock wird getauscht, ein
bereits getippter Absatz überlebt den Wechsel.

Schreibst du direkt in der Konversation, bekommst du eine **verkürzte Kopfzeile**: das
**An**-Feld selbst, tippbar, und dahinter — hinter dem Pfeil daneben — eingeklappt Absender, Cc,
Bcc und Betreff. Bei einer Antwort änderst du die Empfängerin selten, bei einer Weiterleitung
immer, und genau sie fehlt einer Weiterleitung ja noch. Also steht dort das Feld und nicht eine
Zeile, die davon erzählt.

Eine Weiterleitung öffnet mit dem Original hinter der Kapsel **Zitierten Text anzeigen**. Wenn du
es lieber gleich ausgeklappt siehst: Der Schalter steht unter **Einstellungen → Allgemein →
Verfassen** — eingeklappt bleibt die Vorgabe. So oder so zählt das Zitat als Inhalt der Nachricht:
Eine Weiterleitung ohne ein eigenes Wort darüber wird nicht als leere Mail hinterfragt.

### Später senden

Der Pfeil neben **Senden** öffnet **Später senden**: *Morgen früh* (08:00), *Morgen Nachmittag*
(13:00), *Montagmorgen* und **Datum & Uhrzeit wählen** für alles andere. Ist morgen bereits Montag,
entfällt der dritte Eintrag, denn ein Menü, das denselben Zeitpunkt unter zwei Namen anbietet, ist
ein Menü, bei dem man nachsehen muss.

Jede Zeit wird auf **deiner** Uhr gelesen — Zeitzone und 12- oder 24-Stunden-Format aus
**Einstellungen → Allgemein**, nicht aus dem Browser — und das Menü sagt, welche Zone es meint. Ein
Laptop, der noch auf der Zeit eines anderen Kontinents steht, verschiebt deinen Morgen nicht.

Die Untergrenze ist **eine Minute**: Darunter ist „planen“ nur „senden“ mit schlechterem
Rückgängig, und die Auswahl lehnt es schon im Fenster ab, ohne den Server zu fragen. Die
Obergrenze sind **30 Tage**, dieselbe Grenze, die JMAP-Clients genannt bekommen — Webfenster und
Telefon können sich also nicht darüber uneinig sein, was erlaubt war.

Eine geplante Nachricht ist ein Entwurf, der zurückgehalten wird. Das steht in ihrer Zeile unter
**Entwürfe** und in der Entwurfszeile innerhalb einer Konversation — *Geplant …* —, und **Geplanten
Versand abbrechen** steht im Menü dieser Zeile. Die Einblendung ist längst verblasst, wenn jemand
nachsehen geht, und genau darum steht es dort. Ein Abbruch ist auch auf jedem anderen Gerät
sichtbar, nicht nur auf dem, das ihn ausgelöst hat.

### Weitere Optionen

**Weitere Optionen** — die **⋮** neben der Werkzeugleiste — trägt die drei Dinge, die ändern, was
die Nachricht *ist*, statt wie sie aussieht:

- **Priorität** — *Keine Priorität*, *Niedrig*, *Normal* oder *Hoch*. „Keine Priorität“ ist etwas
  anderes als „Normal“: Eine unangetastete Nachricht sagt nichts über ihre Dringlichkeit und trägt
  überhaupt keine Prioritäts-Header.
- **Lesebestätigung anfordern** — siehe unten.
- **Nur-Text-Modus** — wirft die Formatierung weg und sendet eine reine Textnachricht. Er warnt
  vorher, und zwar nur dann, wenn es Formatierung zu verlieren gibt. Solange das Fenster offen ist,
  lässt sich das zurücknehmen; sobald der Entwurf als Nur-Text gespeichert wurde, ist die
  Formatierung endgültig fort.

**Verschlüsseln** sitzt im selben Menü, ausgegraut, und sagt warum: Es gibt noch keine
Verschlüsselung. Der Knopf wird benannt statt versteckt, denn ein Schloss, das nichts tut, ist die
eine Lüge, die ein Mailprogramm nicht erzählen darf.

## Lesebestätigungen

Eine Lesebestätigung ist eine Nachricht, die an den Absender zurückgeht und sagt, dass seine Mail
angezeigt wurde. plMail beherrscht beide Richtungen, und die empfangende ist die, die man genau
lesen sollte — sie ist eine Datenschutzeinstellung und keine Bequemlichkeit.

### Eine anfordern

**Lesebestätigung anfordern** im Menü „Weitere Optionen“ des Schreibfensters. Die Anforderung nennt
die Adresse, aus der du **sendest**, Alias eingeschlossen, und nicht das Konto: Eine Bestätigung
muss an die Adresse zurückkommen, die gefragt hat, sonst kann sie auf dem Rückweg nichts der
Nachricht zuordnen.

Kommt eine an, trägt die Nachricht unter **Gesendet** ein **Gelesen am …**. Die Bestätigung selbst
wird als gelesen markiert und aus dem Posteingang genommen, statt gelöscht zu werden — sie ist also
da, wenn du sie willst, und nicht im Weg, wenn nicht.

### Um eine gebeten werden

**Einstellungen → Aliase → Standards fürs Schreiben**, je Adresse. Drei Betriebsarten:

| Betriebsart | Was dein Postfach tut |
|---|---|
| **Nie senden** | Es wird nichts gesendet, und der Absender erfährt nichts. **Das ist die Vorgabe.** |
| **Jedes Mal fragen** | Die Nachricht zeigt *… möchte erfahren, wann du diese Nachricht liest*, mit **Bestätigung senden** und **Nein danke**. |
| **Immer senden** | Eine Bestätigung geht automatisch hinaus, sobald du die Nachricht als gelesen markierst. |

Nie ist die Vorgabe und bleibt sie, bis du sie änderst — mit Absicht. Eine Bestätigung belegt, dass
eine bestimmte Adresse aktiv ist, gelesen wird und zu einer bestimmten Minute gelesen wurde, und
genau das bekommt, wer danach fischt, indem er einen einzigen Header setzt. Wer diese Einstellung
nie öffnet, sendet nichts.

Zwei Dinge engen das weiter ein, beide in dieselbe Richtung:

- **Eine Bestätigung geht nur hinaus, wenn du eine Nachricht selbst als gelesen markierst.** Stellt
  eine Synchronisierung fest, dass die Nachricht anderswo längst gelesen wurde, geht nichts hinaus:
  Eine Bestätigung behauptet, dass ein Mensch die Nachricht angezeigt hat, und ein
  Synchronisierungslauf, der von letztem Dienstag erfährt, kann das nicht behaupten.
- **Eine Anforderung, die woandershin zeigt als zum Absender, wird auf „fragen“ zurückgestuft**,
  wie die Adresse auch eingestellt ist. Widerspricht die Rückadresse dem, von wem die Mail kam,
  beantwortet plMail sie nicht automatisch — es fragt dich und nennt den Widerspruch. Nicht
  Schweigen, denn die legitime Fassung dieser Form ist ein Massenversender, der an seiner
  Bounce-Adresse sammelt.

## Senden rückgängig machen

Auf Senden zu drücken reiht die Nachricht mit **zehn Sekunden** Verzögerung ein und lässt das
Fenster genau dort, wo es ist. Der Senden-Knopf wird selbst zum Weg zurück: Er liest sich
**Wird gesendet…** mit *zum Abbrechen klicken* darunter, und ein zweiter Klick auf denselben Knopf
sagt das Senden ab. Mehr Abbruch gibt es nicht — keine Einblendung, keine Leiste am Fuß der
Konversation — und es geht im schwebenden Fenster genauso wie in einer Antwort, die du direkt in
der Konversation schreibst.

Solange das Senden läuft, ist die Nachricht selbst nicht mehr erreichbar: Das Fenster zeigt eine
Kopie von Post, die schon weg ist, die Felder sind also eingefroren statt bearbeitbar. Läuft das
Zeitfenster ab, schließt sich das Fenster von allein, eine Einblendung **Nachricht gesendet**
bestätigt es, und die Nachricht nimmt ihren Platz in der Konversation ein — außer es war eine
**Weiterleitung**: Die beginnt eine eigene Konversation und steht darum unter **Gesendet** und
nicht unter der Mail, aus der du sie weitergeleitet hast.

Ein Abbruch sagt nichts. Der Entwurf, der mit allem darin zurückkommt — Empfänger, Betreff, Text,
Anhänge —, ist die Bestätigung, und das ist in beiden Fenstern gleich.

Rückgängig rennt der Mail in dem Sinne nicht hinterher, auf den es ankommt: Der Abbruch und der
Sendeauftrag machen es in einem Schritt unter sich aus, die Datenbank entscheidet also, wer gewonnen
hat, und nur einer von beiden macht weiter. Gewinnst du, wird die Nachricht wieder zu dem Entwurf,
der sie war, und der Editor öffnet sich dort wieder, wo er stand.

Verlierst du — drückst du Rückgängig in den letzten Momenten, nachdem der Auftrag die Nachricht
bereits an sich genommen hat —, wird dir das klar gesagt: *Zu spät — diese Nachricht war schon
gesendet.* Du bekommst keine bearbeitbare Kopie von Post, die schon draußen ist. Weil das angebotene
Fenster acht Sekunden gegen zehn Sekunden Rückhaltung ist, ist Verlieren selten und nicht die Regel.

## Wo du weiterliest

- [Mail-Ingest](../internals/mail-ingest.md) — der Weg vom Anbieter in die Datenbank, Zuordnung
  zu Konversationen und Kategorisierung.
- [Konten und Aliase](accounts.md) — Postfächer verbinden, sofortige Zustellung,
  Absendeadressen.
- [Zustand der Konten](health.md) — wenn keine Mail mehr ankommt, und die Reparatur, die das
  Postfach behält.
- [Filter](filters.md) — Mail beim Eintreffen sortieren, und eine Regel auf vorhandene Mail
  anwenden.
- [JMAP](../internals/jmap.md) — dieselben Operationen, wie ein Client sie sieht.

## Fallstricke

**Der Abbruch verschwindet zwei Sekunden vor der Nachricht.** Der Sendeauftrag wird zehn Sekunden
zurückgehalten, das Fenster bietet *zum Abbrechen klicken* aber nur acht Sekunden lang an und
schließt sich dann. Es ist nichts kaputt, wenn eine Nachricht hinausgeht, nachdem das Angebot weg
ist — das Zeitfenster war wirklich zu.

**Das Fenster in diesen Sekunden zu schließen hält das Senden nicht auf.** Der Senden-Knopf ist der
Abbruch; der Schließen-Knopf bleibt bloß ein Schließen. Machst du das Fenster oder den Tab zu, geht
die Mail hinaus.

**Ein Rückgängig kann verlieren, und das Verlieren wird dir gesagt.** Klickst du den Abbruch ganz am
Rand des Fensters, kann die Antwort *Zu spät — diese Nachricht war schon gesendet* lauten. Das ist das
ehrliche Ergebnis und kein Fehlschlag: Die Alternative wäre, dir einen bearbeitbaren Entwurf von Post
zurückzugeben, die bereits unterwegs ist, und das liest sich wie ein Abbruch, der funktioniert hat.

**Ein Label aus der Seitenleiste zu löschen ist dasselbe Löschen wie das in den Einstellungen.** Es
ist kein „aus dieser Ansicht entfernen“ — der Dialog ist eine Abkürzung zu genau derselben Operation,
über alle Konten hinweg, untergeordnete Labels eingeschlossen.

**Ein Bild in der Signatur kann kein eingebettetes Bild sein.** Ein Bild in einer *Signatur* wird
als gewöhnlicher Bildverweis gespeichert und nicht als `cid:`-Teil: Die Bereinigung, durch die
jede Signatur geschrieben wird, entfernt bewusst genau das Attribut, das daraus eines machen
würde — damit eine Signatur nichts einschmuggeln kann, das aussieht wie eines der eingebetteten
Bilder der Nachricht selbst. Eingebettete Bilder funktionieren im Nachrichtentext, dort, wo du sie
hinsetzt.

**„Neu“ ist nicht „ungelesen“, und das Fähnchen zu verlieren heißt nicht, etwas gelesen zu
haben.** An einer Zeile im Posteingang vorbeizuscrollen räumt ihr „Neu“ ab, denn die Zeile wurde
dir vorgelegt. Die Konversation bleibt ungelesen, bis du sie öffnest. Ungelesen und nicht neu ist
der Normalzustand von allem, was du dir noch vorgenommen hast.

**Ein „Neu“, das du nie gesehen hast, läuft trotzdem ab.** Nichts ist länger als 24 Stunden nach
seiner letzten Nachricht neu. Nach zwei Wochen Abwesenheit kommst du also in einen Posteingang ganz
ohne „Neu“ zurück — das ist die Markierung, die funktioniert, und keine, die ausgeblieben ist.

**Der Nur-Text-Modus lässt sich nur zurücknehmen, solange der Entwurf nicht gespeichert ist.** Die
Warnung sagt das vor dem Umschalten. Ist der Entwurf einmal als Nur-Text abgelegt, gibt es
nirgends mehr eine Formatierung, zu der man zurückkehren könnte.

**Ein geplanter Versand kann nicht näher als eine Minute liegen.** Die nächste volle Minute
einzutippen ist genau die Zeit, die man zum Ausprobieren wählt, und sie wird abgelehnt — mit
Begründung, im Fenster, ohne Weg zum Server. Alles von einer Minute bis dreißig Tagen wird
angenommen.

**Eine zurückgestellte Konversation zu wecken markiert sie als ungelesen, und der alte
Lesestatus ist weg.** Das ist gewollt und kein Versehen, heißt aber, dass du nach dem
Zurückstellen einer bereits gelesenen Konversation eine ungelesene vorfindest.

**Ein Zurückstellzeitpunkt in der Vergangenheit wird angenommen.** Er bedeutet, dass der
nächste Durchlauf eine Minute später die Konversation sofort zurückbringt. Das ist eine
harmlose Art, „bring das jetzt zurück“ zu sagen, und kein Fehler.

**Ein Label zu löschen löscht es überall.** Die Label-Liste der Seitenleiste gilt über alle
Konten, also gilt das Löschen es auch — untergeordnete Labels eingeschlossen. Das `destroy`
eines `Mailbox/set` in einem JMAP-Client ist die Operation pro Konto; die Weboberfläche hat
kein Gegenstück dazu.

**Die Suche erfasst nur Mail, die schon angekommen ist.** plMail synchronisiert alles, was ein
Konto hält, aber ein großes Postfach braucht mehrere Läufe, bis es vollständig herüber ist, und
was noch nicht da ist, ist nicht durchsuchbar — so sicher du auch bist, dass es das gibt. Das
braucht Zeit, keine Einstellung.

**Ein zu großer Anhang kann ohne Grund je Datei scheitern.** PHP verwirft den gesamten
Request-Body, sobald er `post_max_size` überschreitet — es kommt also nichts an, worüber sich
ein Fehler melden ließe. plMail antwortet für den gesamten Upload mit „Upload too large“, statt
zu schweigen, kann dir aber nicht sagen, welche Datei schuld war.

**Eine Nachricht in einem anderen Client als gelesen zu markieren kommt nicht zurück.**
Statusänderungen wandern nur nach außen; ein eingehender Abgleich der IMAP-Flags über den
IDLE-Strom ist nicht umgesetzt.

**Eine Mail zu melden gibt eine Kopie von ihr weiter.** *Fehlendes Insight melden* ist keine Stimme
und kein Daumen nach unten: Absender, Betreff und der Anfang des Nachrichtentextes werden dort
abgelegt, wo eine Administration sie lesen und herunterladen kann. Genau das macht einen neuen
Extraktor schreibbar, und deshalb sagt der Dialog es, bevor du abschickst. Auf einer Installation,
die du nicht selbst betreibst, melde die Mail, deren Form erkannt werden soll — nicht eine, deren
Inhalt du lieber behältst.

**Ein Einmalcode ohne genannte Gültigkeit bekommt keinen Ablauf.** Die Karte zeigt den Code und
keine Zeit, und sie bleibt, bis du sie ausblendest. plMail erfindet keine zehn Minuten, also kann
die Karte dir nicht sagen, ob der Code noch geht — das kann nur die Mail, aus der er stammt.
