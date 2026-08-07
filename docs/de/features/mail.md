<!-- translated-from: features/mail.md sha1:26c30111dd6ca95675a31086a02bf21913b27b6b -->

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
| **Konten** | Eine Zeile je Konto; ein Klick darauf listet nur dieses Konto |

Klappst du unter **Konten** ein Konto auf, siehst du die Labels, die es dort tatsächlich gibt.
Diese Liste ist absichtlich schmaler als die Label-Liste der Seitenleiste selbst: die
Seitenleiste meint „über alle Konten hinweg“, die Liste pro Konto beantwortet „was hat dieses
Postfach wirklich“. Welches Konto du aufgeklappt gelassen hast, merkt sich der Server und nicht
der Browser — die Seitenleiste wird also bereits aufgeklappt gezeichnet, statt nach dem
Seitenaufbau aufzuspringen.

Jede Liste umfasst fünfzig Konversationen pro Seite. **Neuer** und **Älter** in der
Werkzeugleiste blättern.

## Tabs

Der Posteingang ist in die fünf Gmail-Kategorien aufgeteilt — **Allgemein**, **Soziale
Netzwerke**, **Werbung**, **Updates** und **Foren** — jede mit einem Zähler für Ungelesenes.
Bei einem Gmail-Konto vertraut plMail Gmails eigenen `CATEGORY_*`-Labels. Bei allem anderen
leitet es die Kategorie aus Headern ab, die ohnehin schon gespeichert sind — darum braucht eine
Neukategorisierung nie eine erneute Synchronisierung.

Eine Ausnahme lohnt sich zu kennen: Ein Absender, dem du selbst geschrieben hast, wird zurück
nach Allgemein geholt, ganz gleich, welcher Massenmail-Header auf der Nachricht steht. Öffne
den Bereich **Details** einer Nachricht, dann sagt die Zeile **Kategorie**, welche Regel
entschieden hat und auf welchen Header oder welche Domain sie angesprungen ist.

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
Nachrichten werden nicht gelöscht; sie behalten ihre übrigen Labels.

## Suche

Das Suchfeld steht auf jeder Seite oben und antwortet unter `/mail/search`. Freier Text läuft
als echte Volltextabfrage gegen Postgres — mit Stammformen und Rangfolge, nicht als Suche nach
Teilzeichenketten — und lässt sich mit allen Operatoren kombinieren, die du eintippst.

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

Vorschläge erscheinen beim Tippen. Operatoren werden aus der Liste oben vervollständigt;
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

Die Suche erfasst nur Mail, die synchronisiert wurde. Wie weit das zurückreicht, ist eine
Einstellung pro Konto; siehe [Konten und Aliase](accounts.md).

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

**Dateien anhängen** nimmt Dateien von deinem Rechner, gedeckelt auf **25 MB je Datei**. **Aus
einem Dienst anhängen** öffnet die Dateiauswahl für jeden verbundenen Dienst, aus dem plMail
herunterladen kann.

Entwürfe speichern sich zwei Sekunden nach dem letzten Tastendruck selbst, und ein Entwurf
entsteht erst, wenn der Text mindestens fünf Zeichen hat — sonst hätte jeder versehentliche
Tastendruck einen erzeugt. Das Fenster zu schließen, es herauszulösen oder eine Datei
anzuhängen erzwingt jeweils vorher ein Speichern. Der Papierkorbknopf im Schreibfenster löscht
den Entwurf tatsächlich, statt das Fenster darüber zuzuklappen.

## Senden rückgängig machen

Auf Senden zu drücken reiht die Nachricht mit **zehn Sekunden** Verzögerung ein und antwortet
mit einer Einblendung **Wird gesendet…**, die einen Knopf **Rückgängig** trägt. Eine Antwort
direkt in der Konversation überspringt die Einblendung: die Nachricht hängt sofort an der
Konversation, und die Antwortleiste wird zu einem Countdown, den du zum Abbrechen anklicken
kannst.

Rückgängig rennt der Mail nicht hinterher — es setzt eine Markierung, die der Sendeauftrag beim
Aufwachen prüft, sodass über den Abbruch entschieden ist, bevor irgendetwas übertragen wird.
Die Nachricht wird wieder zu dem Entwurf, der sie war, und der Editor öffnet sich dort wieder,
wo er stand.

## Wo du weiterliest

- [Mail-Ingest](../internals/mail-ingest.md) — der Weg vom Anbieter in die Datenbank, Zuordnung
  zu Konversationen und Kategorisierung.
- [Konten und Aliase](accounts.md) — Synchronisierungsfenster, sofortige Zustellung,
  Absendeadressen.
- [Filter](filters.md) — Mail beim Eintreffen sortieren, und eine Regel auf vorhandene Mail
  anwenden.
- [JMAP](../internals/jmap.md) — dieselben Operationen, wie ein Client sie sieht.

## Fallstricke

**Der Knopf Rückgängig verschwindet zwei Sekunden vor der Nachricht.** Der Sendeauftrag wird
zehn Sekunden zurückgehalten, die Einblendung mit Rückgängig verblasst aber schon nach acht. Es
ist nichts kaputt, wenn eine Nachricht hinausgeht, nachdem der Knopf weg ist — das Zeitfenster
war wirklich zu.

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

**Die Suche findet nichts, was älter ist als dein Synchronisierungsfenster.** Mail, die nicht
synchronisiert wurde, ist nicht durchsuchbar, so sicher du auch bist, dass es sie gibt.
Erweitere das Fenster unter **Einstellungen → E-Mail-Konten** und lass den nächsten Lauf weiter
zurückgehen.

**Ein zu großer Anhang kann ohne Grund je Datei scheitern.** PHP verwirft den gesamten
Request-Body, sobald er `post_max_size` überschreitet — es kommt also nichts an, worüber sich
ein Fehler melden ließe. plMail antwortet für den gesamten Upload mit „Upload too large“, statt
zu schweigen, kann dir aber nicht sagen, welche Datei schuld war.

**Eine Nachricht in einem anderen Client als gelesen zu markieren kommt nicht zurück.**
Statusänderungen wandern nur nach außen; ein eingehender Abgleich der IMAP-Flags über den
IDLE-Strom ist nicht umgesetzt.
