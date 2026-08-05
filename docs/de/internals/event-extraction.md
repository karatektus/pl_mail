<!-- translated-from: internals/event-extraction.md sha1:9ff364a1d934155a93f9ed1822b2a5594898cc2e -->
# Extraktion von Terminen

Wie aus einer `.ics`-Einladung ein Kalendereintrag wird, wie aus einem gewöhnlichen Satz das
Angebot eines solchen wird, und warum das zwei verschiedene Mechanismen sind und nicht einer mit
einem Konfidenzwert. Die Seite für Nutzerinnen ist
[Einladungen und Termine aus Mail](../features/calendar-invitations.md).

## Zwei Wege, und die Grenze dazwischen

| | Extraktion | Vorschläge |
|---|---|---|
| Eingabe | ein `text/calendar`-Teil oder schema.org-Markup | Fließtext in einer gewöhnlichen Nachricht |
| Ausgabe | ein `CalendarEvent`, unbeaufsichtigt geschrieben | eine Zeile `EventProposal`, auf einer Karte angeboten |
| Auf dem Kalender sichtbar | ja, sofort | erst wenn die Nutzerin zustimmt |
| Von späterer Mail revidierbar | ja, durch `EventReconciler` | nein — Annehmen ist eine Entscheidung |
| Interface | `App\Domain\Interface\EventExtractorInterface` | `App\Service\Calendar\Proposal\ProposalDetectorInterface` |

Diese Trennung ist der ganze Entwurf. Ein Extractor liest etwas, das der Absender bereits
geparst hat — eine UID und eine SEQUENCE, oder eine Buchungsnummer in einem JSON-LD-Block —,
das in einen Kalender zu schreiben ist deshalb Rechnen. Ein Detektor liest einen Satz, das in
einen Kalender zu schreiben wäre also eine Vermutung, die neben Tatsachen auftaucht. Die
Asymmetrie der Kosten entscheidet es: Ein verpasster Termin ist ein Ärgernis, eine erfundene
Abflugzeit ist ein verpasster Flug.

Beide hängen am selben Ingest-Haken. `App\Service\Mail\PostIngest\ExtractEventsStep` schickt
`ExtractEventsMessage` mit einer Liste von Nachrichten-Ids ab, und `ExtractEventsHandler` lässt
den Extraktionslauf und den Reconciler pro Nachricht laufen, mit einem Fang pro Nachricht: Eine
unparsbare Einladung darf nicht den Stapel kosten, und der Stapel darf ihretwegen nicht erneut
versucht werden, denn eine Nachricht, die sich nicht parsen lässt, parst sich beim zweiten
Anlauf auch nicht. Vorschläge laufen als eigener Post-Ingest-Step,
`App\Service\Calendar\Proposal\ProposeEventsStep`.

## Das Extractor-Interface

`EventExtractorInterface` wird automatisch mit `app.event_extractor` getaggt und hat vier
Methoden: `supports()`, `extract()`, `stopsCascade()` und `priority()`. Einen hinzuzufügen
heißt, eine Klasse zu schreiben.

**Die Kaskade ist „der Erste gewinnt" pro Dedup-Schlüssel, nicht „der Erste gewinnt"
überhaupt.** Eine Nachricht trägt völlig zu Recht mehrere unabhängige Termine — ein Flug mit
zwei Teilstrecken, eine Bestellung mit drei Paketen —, und eine Einladung kann neben einer
Buchungsbestätigung stehen. Also darf jeder Extractor schauen, und nur eine Kollision auf
demselben Schlüssel wird über die Priorität aufgelöst. `EventExtractionRunner` setzt das als
`$byKey[$event->dedupKey] ??= $event` um, wobei die höhere Priorität zuerst gelaufen ist, und
der Verlierer ist kein Fehler: dass zwei Extractoren sich über eine Buchung einig sind, ist das
System bei der Arbeit.

`stopsCascade()` ist die Ausnahme und existiert für genau einen Fall. Eine echte
iCalendar-Einladung ist maßgeblich, und eine Vermutung weiter unten in der Liste hat ihr nichts
hinzuzufügen.

`supports()` muss billig genug sein, um es bei jeder Nachricht aufzurufen; alles Teure — parsen,
rohes MIME holen — gehört in `extract()`. `ExtractionContext` trägt die Nachricht, das Konto,
die `text/calendar`-Teile, das **unbereinigte** `bodyHtml`, die Header, die kleingeschriebene
Absenderadresse und eine Closure `rawMimeLoader`, die faul ist, weil sie bei Graph ein
API-Aufruf ist und die meisten Nachrichten keine Einladungen sind.

Der Runner fängt, was ein Extractor wirft, und protokolliert es: Ein kaputter Extractor darf
weder die Termine kosten, die die anderen gefunden haben, noch die Nachricht.

Kalenderteile werden **allein am Content-Type erkannt, nie an der Disposition** —
Gmail-Einladungen liegen inline, damit keine Büroklammer erscheint, und eine über IMAP kann
beides sein.

## `ExtractedEvent` ist eine Behauptung, keine Zeile

Was ein Extractor zurückgibt, ist `App\Service\Calendar\Extraction\ExtractedEvent`, und es ist
mit Absicht kein `CalendarEvent`. Ob aus einer Behauptung eine Zeile wird, ob sie eine
aktualisiert oder als überholtes Duplikat abgelegt wird, entscheidet `EventReconciler` — die
beiden auseinanderzuhalten ist das, was mehrere Extractoren über dieselbe Nachricht laufen und
sich uneins sein lässt, ohne dass einer von ihnen schreibt.

`$sourcePayload` wird wortwörtlich auf dem entstehenden `EventSourceLink` gespeichert, und
deshalb kann die Extraktion als **Backfill statt als Resync** erneut laufen: Die Eingabe des
Extractors liegt neben seiner Ausgabe, einen Mapper zu verbessern und ihn erneut abzuspielen
braucht also keinen Mailserver. Das ist dieselbe Eigenschaft, die `MessageCategorizer` hat, und
`app:backfill events` ist das, was sie ausübt.

## Die beiden Extractoren

### `IcsEventExtractor`

Die vertrauenswürdigste Quelle, die es gibt, und die einzige, die keine Vermutung ist: die
eigene UID des Absenders, seine eigene SEQUENCE, seine eigene Absage. RFC 5546 hat vor
Jahrzehnten geklärt, was Identität und Revision für Kalendermail bedeuten, und ihm zu folgen
ist es, was plMail mit jedem anderen Client darüber einig macht, welche Aktualisierung welche
ablöst. Es ist der Extractor, der die Kaskade beendet.

`IcsEventExtractor::NAME` ist eine Konstante (`'ics'`), weil der Name inzwischen nicht mehr nur
dort steht — die Einladungskarte findet ihren Termin, indem sie nach der Verknüpfung fragt, die
dieser Extractor hinterlassen hat, und eine zweimal getippte Zeichenkette ist eine Karte, die an
dem Tag lautlos verschwindet, an dem jemand eine der beiden umbenennt.

Drei Gestalten erreichen ihn, und die dritte ist der Grund, warum es `rawMimeLoader` gibt:

- IMAP speichert bereits einen `MessagePart` vom Typ `text/calendar`, mit Bytes auf der Platte.
- Gmail ebenso, inline oder als fauler Stummel.
- **Graph hat überhaupt keinen Teil**, denn ein `text/calendar`-Abschnitt innerhalb von
  `multipart/alternative` ist in seinem Objektmodell kein Anhang. Alles, was es hergibt, ist
  `meetingMessageType` auf der Nachricht, also wird die Einladung stattdessen aus dem rohen MIME
  gelesen — ein Abruf, auf Platte zwischengespeichert, und nur für Nachrichten, die sich selbst
  entsprechend kennzeichnen.

Eine wiederkehrende Einladung wird **umgewandelt** und nicht bloß aufbewahrt. Die RRULE wurde
früher wortwörtlich unter `plmail:rrule` verstaut, und `RecurrenceMaterialiser` liest
`recurrenceRules` und sonst nichts — jemandes wöchentliche Besprechung kam also per Mail an und
erschien einmal auf dem Kalender. Das Versteck ist heute nur noch der Rückfall für eine Regel,
die `RecurrenceRuleConverter` zurückgewiesen hat; warum eine Regel rundheraus zurückzuweisen
besser ist, als das meiste davon umzuwandeln, steht unter
[Das Kalendermodell](calendar-model.md).

Ein VEVENT ohne Ende und ohne Dauer ist ein Zeitpunkt, und iCalendar sagt, ein solcher mit
Datum und Uhrzeit sei als Länge null zu behandeln — aber eine Zeile der Länge null ist in jeder
Ansicht unsichtbar, also bekommt sie nominell eine Stunde.

### `StructuredDataEventExtractor`

Termine aus dem schema.org-Markup, das eine Buchungsbestätigung ohnehin mitbringt. Google hat
E-Mail-Markup genau dafür geschaffen — Flüge, Pakete, Hotels, Tickets —, und deshalb kann Gmail
dir sagen, was diese Woche ansteht, ohne dass auch nur in der Nähe ein Modell wäre. Der
Absender hat seine eigene Buchung bereits in strukturierte Felder geparst; sie zu lesen ist
Rechnen und kein Schließen.

Priorität 80, unter der Einladung und über allem, was rät, und er beendet die Kaskade **nicht**:
Eine Nachricht trägt routinemäßig mehrere unabhängige Dinge, und ein später geschriebener
Parser für eine bestimmte Fluggesellschaft weiß vielleicht etwas, das dieser hier nicht weiß.

`CONFIDENCE` ist 90 statt der 100 der Einladung. Die Felder sind exakt, ihre Bedeutung ist es
nicht immer: Eine Reiseroute besteht aus mehreren Objekten mit einer Buchungsnummer, eine
Lieferschätzung ist ein Versprechen und keine Tatsache, und der Absender hat sich für eine von
einem halben Dutzend zulässiger Gestalten entschieden.

Die Eingabe ist `Message::$bodyHtml` — unbereinigt und unangetastet, denn der Block `<script
type="application/ld+json">` wird aus `bodyHtmlSafe` entfernt, völlig zu Recht. Genau dafür gibt
es die beiden Kopien, und `BodyHtmlPreservesStructuredDataTest` nagelt es fest. Es steht
außerdem unter dem Einfluss eines möglichen Angreifers, also verlässt sich nichts darauf, dass
ein Feld existiert, dass es den Typ hat, den es haben sollte, oder dass es eine vernünftige
Größe hat: `MAX_EVENTS` ist 25 (eine Nachricht, die mehr behauptet, ist ein Angriff oder ein
Fehler in einer Vorlage, keine Reiseroute), `MAX_TITLE` 200, `MAX_LOCATION` 300,
`MAX_DESCRIPTION` 2000.

Es gibt **keine Sequence**. Diese Quellen tragen keine Revisionsnummer, und eine zu erfinden —
ein Zähler, ein Hash der Payload — würde eine willkürliche Reihenfolge vor dem Reconciler
maßgeblich aussehen lassen. Bleibt sie bei 0, fällt der Reconciler auf den Eingangszeitpunkt der
Mail zurück, und das ist bei einer Bestätigung gefolgt von einer Änderungsmitteilung genau
richtig.

Ein schema.org-Zeitstempel trägt einen **Offset, keine Zone**, und aus einem Offset lässt sich
keine Zone zurückgewinnen: `-05:00` ist im Sommer Chicago und das ganze Jahr über Bogotá. Der
Zeitpunkt ist so oder so exakt, also wird der Termin als UTC mit `timeZone` auf null gespeichert
und in der Zone der Nutzerin gerendert, statt eine Zone zu behaupten, die niemand genannt hat.

## Dedup-Schlüssel

Der Dedup-Schlüssel ist das, worüber eine Extraktion zu sein **behauptet**; er wird benutzt,
bevor es einen Termin gibt, und für Unterdrückungen. Jeder Extractor prägt seine eigene Gestalt:

| Extractor | Schlüssel |
|---|---|
| `IcsEventExtractor` | `'ics:' . $uid` — die UID ist die Identität, also ist sie auch der Schlüssel |
| `StructuredDataEventExtractor` | `'jsonld:' . sha256(issuer \| type \| identity)` |

Der Hash der strukturierten Daten verbindet seine drei Teile mit einem **NUL-Byte**, das in
keinem von ihnen vorkommen kann — `("AB", "1234")` und `("AB1", "234")` sind also nicht dieselbe
Buchung. Die Domain des Ausstellers steht im Schlüssel, weil eine Buchungsnummer sechs Zeichen
lang und nur innerhalb der ausstellenden Firma eindeutig ist. Eine Identität, die leer
herauskommt, wird ganz verworfen: Ohne etwas, woran sich die Buchung wiedererkennen lässt, wäre
jedes erneute Zusenden derselben Bestätigung ein neuer Termin, und keine Änderungsmitteilung
könnte je den finden, um den es ihr geht.

`CalendarEvent::$dedupKeyVersion` hält fest, welche Formel die Schlüssel auf den Quellverweisen
eines Termins erzeugt hat. Die Herleitung eines Schlüssels zu ändern verwaist jeden Termin, der
schon nach der alten Art verschlüsselt ist — eine Aktualisierung kommt an, trifft auf nichts und
wird zum Duplikat. Die Spalte kostet jetzt ein Smallint und macht daraus später einen
Backfill zum Neuverschlüsseln statt eines Datenverlusts.

## Abgleich

`App\Service\Calendar\EventReconciler` macht aus Behauptungen das, was der Kalender zeigt. Hier
spielt sich das Leben einer Buchung ab — eine Bestätigung, dann eine Änderung, dann eine Absage,
meist über eine Konversation verteilt und nicht immer der Reihe nach. Das falsch zu machen zeigt
sich als drei Kopien eines Abendessens oder als eine Besprechung, die sich klammheimlich selbst
wieder absagt, weil eine ältere Mail zuletzt synchronisiert wurde.

Sechs Regeln, jede davon, weil die naheliegende Alternative schlechter ist:

1. **Die Identität ist die UID, eindeutig pro Kalender.** Bei einer Einladung ist das die eigene
   UID des Absenders, wortwörtlich.
2. **Die spätere Revision gewinnt, nach SEQUENCE, hilfsweise nach Eingangszeitpunkt der Mail.**
   Zustellung außer der Reihe ist normal, eine ältere Revision, die nach einer neueren eintrifft,
   wird also *abgelegt* statt angewendet.
3. **Eine überholte Extraktion wird trotzdem verzeichnet, mit `applied = false`.** Das ist es,
   was „warum steht das in meinem Kalender?" beantwortbar macht, und es ist der Unterschied
   zwischen einer Beweiskette und einer Vermutung.
4. **Eine Absage setzt einen Status; sie löscht nie.** Nutzerinnen wollen sehen, dass die Sache
   abgesagt wurde, und die Zeile zu löschen kämpft gegen jeden, der sie zurückhaben will. Jede
   Termininstanz des Termins wird als abgesagt markiert statt entfernt.
5. **Ein von der Nutzerin bearbeiteter Termin wird nie überschrieben.**
   `CalendarEvent::$isUserEdited` wird von `CalendarEventWriter::markUserEdited()` in dem Moment
   gesetzt, in dem eine Person einen extrahierten Termin bearbeitet: Eine spätere Mail mag mehr
   über die Buchung wissen, aber sie weiß nicht mehr als die Nutzerin.
6. **Und ebenso wenig ein Termin, für den das hier nie zuständig war.**
   `EventSource::mayBeRewrittenByMail()` zieht diese Grenze, und die Behauptung wird trotzdem
   beim Termin abgelegt, damit die Beweiskette erhalten bleibt.

Wohin der Termin geht, entscheidet
`App\Service\Calendar\ExtractedEventCalendarResolver`: standardmäßig auf den eigenen Kalender
des Kontos — `CalendarProvisioner` legt neben jedem Mailkonto einen an, damit es die Antwort
immer gibt — mit `Account::SETTING_CALENDAR_TARGET` als Übersteuerung für alle, die alles an
einer Stelle haben wollen. Diese Übersteuerung wird **gegen die Nutzerin geprüft** und nicht
einfach geglaubt, denn eine Einstellung ist eine Zeichenkette in einem jsonb-Beutel, und eine
Kalender-Id, die inzwischen gelöscht wurde oder jemand anderem gehört, muss zurückfallen, statt
zu werfen oder etwas durchsickern zu lassen.

### Der Nachschlag in der Unit of Work

`findOneByUid()` fragt die Datenbank, und die sieht ein eingereihtes INSERT nicht. Zwei
Nachrichten in einem Stapel, die dieselbe UID tragen, fanden also beide nichts, legten beide
einen Termin an, und der Flush wurde von `uniq_calendar_event_calendar_uid` zurückgewiesen. Ein
erneutes Zusenden und das Original landen routinemäßig im selben Stapel — ein Backfill
verarbeitet ein ganzes Postfach auf einmal, und eine Einladung wird meist mehr als einmal
verschickt. Also läuft `pendingByUid()` über
`EntityManager::getUnitOfWork()->getScheduledEntityInsertions()`, statt sich eine Property zu
halten: Die eingereihte Menge ist die tatsächliche Antwort auf „was wird es nach dem Flush
geben?", sie wird zwischen Stapeln von `em->clear()` geleert, ohne dass man an ein Zurücksetzen
denken müsste, und der Dienst bleibt zustandslos.

## Herkunft

`App\Entity\Calendar\EventSourceLink` hält fest, welche Nachricht einen Termin auf den Kalender
gebracht hat und was genau sie gesagt hat. Es ist eine Many-to-many-Beziehung mit Metadaten und
keine nullbare `message_id` auf dem Termin, weil **keine der beiden Richtungen einfach ist**:
Eine Nachricht kann mehrere Termine erzeugen, und ein Termin wird typischerweise von mehreren
Nachrichten erzeugt, die über eine Konversation verteilt sind.

`$payload` ist die tragende Spalte — das extrahierte Fragment genau so, wie es gelesen wurde —
und `$applied = false` heißt „diese Nachricht wurde gelesen, und sie hat verloren": ein
veraltetes Duplikat oder eine Aktualisierung, die nach einer neueren ankam.
`uniq_event_source_link` auf `(event_id, message_id, extractor)` hält es bei einer Zeile pro
Extractor pro Nachricht pro Termin; `idx_event_source_link_dedup` ist das, was ein Backfill zum
Neuverschlüsseln benutzen würde.

`App\Service\Calendar\InviteReader` liest das für die Karte über einer Nachricht wieder aus, und
er lädt **pro Konversation, nicht pro Nachricht**: Die Karte wird von einem Partial gezeichnet,
das einmal pro Nachricht eingebunden ist, ein Nachschlag über die Nachricht wäre also eine
indizierte Abfrage pro Zeile in jeder Konversation, die irgendwer öffnet — nur um für fast alle
„keine Einladung" zu antworten. Er implementiert `ResetInterface`, statt sich auf eine Konvention
pro Request zu verlassen, denn er hält Entities, und unter einer Worker-Laufzeit reicht ein
Cache, der seinen Request überlebt, Objekte heraus, die einem geschlossenen Entity Manager
gehören.

## Unterdrückung

`App\Entity\Calendar\EventSuppression` ist die Absage einer Nutzerin, gemerkt. Klein, leicht auf
später zu verschieben, und der Unterschied zwischen einem Feature, das Leute mögen, und einem,
das sich anfühlt, als kämpfe es gegen sie: Extraktion ist von Natur aus wiederholbar, ohne diese
Tabelle würde also jeder Backfill genau das zurückbringen, was die Nutzerin gerade weggeklickt
hat.

Der Schlüssel ist **`sha256(dedupKey)` und nicht die Termin-Id**, aus zwei Gründen, die beide
zählen — die Absage muss das Löschen des Termins überleben, und sie muss die *nächste* Nachricht
über dieselbe Buchung abfangen, bevor daraus einer entsteht. `uniq_event_suppression` auf
`(usr_id, dedup_key_hash)` macht die Absage idempotent, und die Spalte hat feste Breite 64, weil
niemand das Original braucht.

`EventReconciler` fragt nach der Unterdrückung, **bevor irgendetwas angelegt wird**, und der Weg
über Vorschläge schreibt in dieselbe Tabelle — „kein Termin" bedeutet also dasselbe, egal welcher
Mechanismus ihn angeboten hat.

## Vorschläge: ein Datum aus Fließtext lesen

Drei Stufen, von denen zwei gebaut sind.

### Stufe eins — das Gatter für die Form

`App\Service\Calendar\Proposal\DateShapeGate` ist ein einziger regulärer Ausdruck über Text, der
ohnehin im Speicher liegt: keine Abfrage, keine Platte, kein Netz. Fast alle Mail stirbt hier,
und nur was überlebt, ist es wert, in Sätze zerlegt und richtig gelesen zu werden. Es ist das,
was das teure Parsen bezahlbar macht — und, wie der Docblock anmerkt, das, was Stufe drei
überhaupt denkbar macht: Ein Modell, das zu jeder Nachricht befragt wird, ist eine Rechnung;
eines, das zu den wenigen Prozent Mail befragt wird, in denen ein Wochentag vorkommt, nicht.

Es ist **mit Absicht zu großzügig**. Ein Gatter, das genau zu sein versuchte, wäre der Parser ein
zweites Mal geschrieben, die beiden würden auseinandergehen, und der Fehlerfall wäre der
unsichtbare: eine Nachricht, die der Parser hätte lesen können und die das Gatter weggeworfen
hat, was niemandem auffällt, weil nichts angezeigt wurde. Es antwortet „vielleicht" und „ganz
sicher nicht", und gehandelt wird nur nach der zweiten Antwort.

Deutsch und Englisch stehen in einer Alternation statt in je einem Durchgang pro Sprache, weil
die Mail dieser Nutzerin beides ist und keine Nachricht sagt, welches von beidem. Jeder
Wochentags- und Monatsname steht **vollständig** da statt als Stamm mit Platzhalter: Die erste
Fassung benutzte `(?:mon|die|sam|mai)[a-zäöü]*`, und das trifft „money", „die", „same" und
„mail" — der deutsche bestimmte Artikel und das Wort „mail" kommen praktisch in jeder Nachricht
vor, das Gatter ließ also alles durch und hörte auf, ein Gatter zu sein. Alternativen mit Umlaut
werden ohne führendes `\b` gematcht, denn PCREs `\b` ist ohne UCP reines ASCII, und `ü` ist damit
kein Wortzeichen.

### Stufe zwei — der deterministische Detektor

`App\Service\Calendar\Proposal\DeterministicDateDetector` ist die eine Implementierung von
`ProposalDetectorInterface`. Detektoren lesen; sie dürfen nicht entscheiden, ob das Datum
angeboten werden darf.

### Die Rauschregeln, die an einer Stelle wohnen

`App\Service\Calendar\Proposal\EventProposer` entscheidet, *wann es zulässig ist zu raten*, und
alles darüber wohnt dort und nirgendwo sonst — denn es wird ein zweiter Detektor kommen, und der
darf keine eigene Meinung darüber mitbringen, was als Rauschen zählt. Durchgehend Genauigkeit
vor Vollständigkeit: Eine Karte, die anbietet, „Angebot endet Freitag!" in jemandes Kalender zu
schreiben, ist der Moment, in dem diese Person das Feature für dumm hält und nach dem Schalter
sucht.

- **Kein Entwurf, und keine Mail ohne Besitzerin.** Es gibt niemanden, dem etwas vorzuschlagen
  wäre.
- **Nur Allgemein.** `MessageCategorizer` hat Massen-, Marketing- und Listenmail beim Ingest
  bereits aus denselben persistierten Headern aussortiert, die auch ein Backfill sieht — das
  hier ist also das Lesen einer Spalte und kein zweiter Satz Regeln, dem es freistünde, dem
  ersten zu widersprechen. Eine *unkategorisierte* Nachricht wird ebenfalls abgelehnt, denn
  „noch nicht eingeordnet" ist nicht „als persönlich eingeordnet".
- **Keine Listen- oder Massenversand-Header, auch wenn die Kategorie Allgemein sagt.** Der
  Vorrang für Korrespondenz zieht jeden, dem die Nutzerin je geschrieben hat, zurück nach
  Allgemein, was für einen Tab richtig ist und hier falsch: Ein Laden, dem sie einmal geschrieben
  hat, schickt weiterhin Newsletter, und die nennen Termine. `BULK_HEADERS` prüft `list-id`,
  `list-post`, `list-unsubscribe`, `x-mailman-version`, `x-google-group-id`, `feedback-id` und
  `x-csa-complaints` erneut.
- **An die Nutzerin adressiert.** Eine der Adressen des Kontos muss in To oder Cc auftauchen.
  Ein Datum, das einer Liste verkündet wird, ist eine Ankündigung; ein Datum, das dir geschickt
  wird, ist eine Verabredung. Das ist die eine Regel, die den größten Teil dessen entfernt, was
  die vorherigen überlebt.
- **Nichts, was ein Extractor lesen könnte.** Ein `text/calendar`-Teil, der
  Graph-Besprechungs-Header oder schema.org-Markup bedeuten, dass ein echter Termin unterwegs
  ist. Die Extraktion läuft asynchron, auf den Termin zu warten wäre also ein Wettlauf — auf das
  *Signal* hin abzulehnen ist dieselbe Antwort ohne den Wettlauf, und für den Backfill-Fall, in
  dem die Extraktion schon gelaufen ist, wird zusätzlich das Ergebnis geprüft.
- **Nichts in der Vergangenheit, nichts jenseits von `HORIZON_DAYS` (365)** — beides gemessen am
  **Datum der Nachricht selbst**, nie an `now()`, damit ein Backfill über alte Mail zu dem
  Urteil kommt, zu dem er an ihrem Eingangstag gekommen wäre. Ein Jahr ist mit Absicht
  großzügig: Was dahinter liegt, sind Vertragsformulierungen, Gewährleistungsfristen und
  Verlängerungstermine — echte, korrekt geparste Daten, die niemand im Kalender haben will.
- **Nichts, was bereits abgelehnt wurde**, über dieselbe Tabelle `EventSuppression`, die auch
  extrahierte Termine benutzen.

### Die Vorschlagszeile, und was Annehmen bedeutet

`App\Entity\Calendar\EventProposal` ist eine eigene Tabelle und kein Zustand auf
`CalendarEvent`, und das ist der ganze Sinn des Features und nicht eine Einzelheit daran:
`CalendarEventWriter` materialisiert bei jedem Schreiben Termininstanzen, jede Bereichsabfrage
liest Termininstanzen, und `UpcomingEventIndicator` lässt daraus den Punkt in der Kopfleiste
leuchten — eine Terminzeile ist also *konstruktionsbedingt sichtbar*, und eine Vermutung in
dieser Tabelle zu halten würde es zur Aufgabe jeder Ansicht machen, daran zu denken, sie
auszuschließen. Eine Ansicht, die es einmal vergisst, ist eine erfundene Abflugzeit in jemandes
Kalender. **Ein Vorschlag materialisiert nichts und kann deshalb nicht durchsickern: Es gibt
keine Termininstanz zu finden.**

Annehmen kippt keine Spalte. Es schreibt über `CalendarEventWriter` ein `CalendarEvent`, und die
Vorschlagszeile verschwindet. Ablehnen schreibt eine `EventSuppression` mit dem Schlüssel
`$dedupKeyHash`.

`$sourceSentence` wird aufbewahrt und ist keine Zierde: Eine Vermutung, deren Beleg sichtbar
ist, lässt sich in einer Sekunde beurteilen, während ein nacktes Datum mit einem
Hinzufügen-Knopf daneben sich überhaupt nicht beurteilen lässt — es wird also nach Münzwurf
geklickt oder ignoriert.

`uniq_event_proposal_message_starts_at` ist die Absicherung, die tatsächlich hält — `EventProposer`
fragt zwar, bevor er schreibt, aber diese Prüfung ist eine Lesung auf Daten, die ein anderer
Worker gerade zu ändern im Begriff sein kann, und ein Backfill, der läuft, während Mail
eintrifft, bearbeitet dieselbe Zeile aus zwei Richtungen. Die Nachricht steht im Index vorn,
weil jede Lesung lautet „was schlägt *diese* Nachricht vor?". Es gibt mit Absicht **keinen**
Index auf `usr_id` über den des Fremdschlüssels hinaus und keinen auf `starts_at`: Nichts läuft
über diese Tabelle nach Nutzerin oder nach Datum, ein Index für eine Abfrage, die niemand
stellt, wäre also Schreibkosten bei jedem Ingest.

Ein angenommener Vorschlag wird zu `EventSource::AcceptedProposal`, nicht zu `Manual`. Der Tag,
die Uhrzeit und der Titel waren plMails Lesart des Satzes einer anderen Person, und die Nutzerin
hat ihr lediglich zugestimmt — wenn sich ein solcher Termin also als eine Stunde daneben
herausstellt, ist der Parser der Verdächtige, und das ist eine Frage, die keine Abfrage mehr
stellen kann, sobald die Zeile behauptet, ein Mensch habe sie getippt. Er trägt keine
`ExtractionKind`, `isExtracted()` antwortet also false und keine der „in deiner Mail
gefunden"-Auskünfte erscheint daneben, und `mayBeRewrittenByMail()` antwortet false, weil er
einmal von der Person entschieden wurde, deren Kalender es ist.

## Die Stufe, die mit Absicht nicht gebaut ist

`ProposalDetectorInterface` hat eine Implementierung und existiert trotzdem. Der Docblock sagt
das unumwunden, denn ein Interface mit einer Implementierung sollte es normalerweise nicht
geben:

> Die Stufe nach dieser ist ein Modell. […] Dieser Detektor wird eine Klasse sein, die dieses
> Interface implementiert, mit `app.proposal_detector` getaggt und mit niedrigerer Priorität, so
> dass er nur zu dem befragt wird, was der deterministische nicht lesen konnte. Sonst ändert
> sich nichts: nicht `EventProposer`, nicht die Entity, nicht die Karte, nicht die Rauschregeln
> — und genau das ist der Punkt, denn die Rauschregeln sind der Teil, der nicht von dem neu
> entschieden werden darf, der das Modell hinzufügt.
>
> Es gibt bislang KEINEN Modellcode, keine Konfiguration und keine Abhängigkeit dafür, und hier
> soll auch nichts davon hinzukommen. Das hier ist die Naht, nicht das Feature.

`EventSource::Llm` ist die andere Hälfte desselben Vorbehalts. Den Case gibt es schon jetzt,
weil die Alternative eine Einbahnstraße ist: Landen geratene Termine je ungekennzeichnet neben
geparsten, gibt es keine Abfrage mehr, die sie wieder trennt. `isTrusted()` antwortet allein für
ihn false — eine nicht vertrauenswürdige Quelle ist dokumentiert als eine, die nie nach außen
auf einen verbundenen Kalender geschoben wird, in der Konfidenz gedeckelt ist und mit einer
ausdrücklichen Bestätigung angezeigt wird.

Diese Methode ist zugleich das hauseigene Beispiel dafür, warum Erschöpfung zählt. Früher war sie
`self::Llm !== $this`, was für **jeden nach ihm geschriebenen Case** „vertrauenswürdig"
antwortet — die nächste unbeaufsichtigte Quelle hätte also stillschweigend die Erlaubnis zum
Kalenderschreiben geerbt, die die Methode vorenthalten soll. Heute ist sie ein erschöpfendes
`match` über jeden Case ohne `default`, derselbe Durchfall, den
`Integration\Provider::authKind()` mitschleppte, bis er geschlossen wurde.

`EventSource::AcceptedProposal` vervollständigt das Argument von der anderen Seite: Eine
Vermutung, die eine Person bestätigt hat, und eine Vermutung, die niemand gesehen hat, sind
verschiedene Tatsachen, und das Enum ist die einzige Stelle, an der eine von beiden
aufgeschrieben wird — denn die entstehende Zeile sieht ansonsten gleich aus. Die beiden
zusammenzufalten würde die eine Tatsache verlieren, die einen angenommenen Vorschlag mit voller
Konfidenz zeigbar macht: dass ein Mensch ihn angesehen hat.

## Fallstricke

**Die Herleitung eines Dedup-Schlüssels zu ändern verwaist jeden Termin, der schon nach der
alten Art verschlüsselt ist.** Die Aktualisierung kommt an, trifft auf nichts und wird zum
Duplikat — und im selben Moment hören die Unterdrückungen der Nutzerin auf zu greifen, denn sie
sind auf den Hash der alten Zeichenkette geschlüsselt. `CalendarEvent::$dedupKeyVersion` und
`EventSourceLink::$dedupKey` gibt es, damit das ein Backfill ist; beide helfen nicht, wenn die
Version nicht hochgezählt wird.

**`bodyHtml` an Ort und Stelle zu bereinigen löscht die Extraktion strukturierter Daten.** Der
JSON-LD-Block ist ein `<script>`-Tag. Genau deshalb ist `bodyHtmlSafe` die gerenderte Kopie, und
`BodyHtmlPreservesStructuredDataTest` ist das, was es bemerkt.

**Ein Extractor, der die Kaskade beendet, beendet sie für die ganze Nachricht.** Nur
`IcsEventExtractor` tut das, und nur wenn er tatsächlich etwas erzeugt hat — der Runner prüft
`[] !== $byKey`, bevor er abbricht.

**Ein Post-Ingest-Step muss anstoßen statt arbeiten**, und die Extraktion ist der Grund, warum es
die Regel gibt: Sie kann ein Parsen, ein Lesen von der Platte oder das Holen von rohem MIME
bedeuten, auf einem Worker, der eine IMAP-Verbindung hält.

**`EventSource::isTrusted()` hat derzeit keinen Aufrufer in `src/`.** Die Folgen, die sein
Docblock beschreibt — Status Tentative, ein Deckel für die Konfidenz, nie nach außen geschoben —
sind bislang nirgends erzwungen und müssten an dem Tag verdrahtet werden, an dem eine nicht
vertrauenswürdige Quelle hinzukommt.

**Ein Vorschlag ist kein Termin und darf vor dem Annehmen nie als einer sichtbar werden.** Die
Trennung ist strukturell — keine Instanzzeile —, alles, was Vorschläge zu einem Kennzeichen auf
`CalendarEvent` „vereinfacht", führt also genau den Fehler wieder ein, den die Tabelle unmöglich
machen sollte.

**Sowohl das Gatter als auch die Rauschregeln messen am Datum der Nachricht und nicht an jetzt.**
Eine Änderung, die in `EventProposer` nach `new DateTimeImmutable()` greift, lässt
`app:backfill proposals` andere Antworten liefern als den Live-Weg, und der Unterschied zeigt
sich nur darin, dass alte Mail nichts mehr vorschlägt.
