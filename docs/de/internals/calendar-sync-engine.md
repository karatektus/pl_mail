<!-- translated-from: internals/calendar-sync-engine.md sha1:1c2e68dbb04935a3d6cc2432fb52874d14be1e68 -->
# Die Sync-Engine

Der Treibervertrag, den jeder Anbieter erfüllt, die Reihenfolge, in der die Engine vorgeht, was
ein totes Token kostet, Push-Kanäle gegenüber Polling, und wie eine lokale Änderung nach außen
gelangt. Das Datenmodell, in das hier geschrieben wird, beschreibt
[Das Kalendermodell](calendar-model.md); die Seite für Anwender ist
[Verbundene Kalender](../features/calendar-sync.md).

## Drei Klassen, weil sie verschiedene Fragen beantworten

| Klasse | Zuständig für |
|---|---|
| `App\Service\Calendar\CalendarSyncService` | die **Reihenfolge**: erst Push, dann Pull, dann das Token; was ein totes Token kostet; was der Kalender danach festhält |
| `App\Service\Calendar\CalendarPusher` | wie aus einer lokalen Zeile ein Schreibvorgang beim Anbieter wird |
| `App\Service\Calendar\CalendarPuller` | was eine entfernte Änderung für eine lokale Zeile bedeutet |

Zusammengelegt lägen die Konfliktregeln, die Wiederaufnahme des Pagings und die Abbildung je
Termin in einer Klasse von vierhundert Zeilen, deren Test erst eine Gegenstelle aufsetzen
müsste, um überhaupt etwas über eine Regel behaupten zu können.

Nur der `CalendarSyncService` führt ein Flush aus. Er steht an der Spitze der Unit of Work eines
Messenger-Handlers, statt ein Schritt innerhalb der Unit of Work eines anderen zu sein, und das
Token, die Termine und die Buchführung müssen gemeinsam landen — sonst liest der nächste
Durchlauf ein Fenster erneut ein, das er bereits angewandt hat. Pusher und Puller hängen sich in
die Unit of Work des Aufrufers ein, wie alles andere in `Service/Calendar/` auch.

## Die Konfliktregeln

Zwei-Wege-Sync lohnt sich nur, wenn „Änderungen kommen zurück" verlässlich ist, und diese
Verlässlichkeit entscheidet sich vollständig daran, was passiert, wenn sich beide Seiten bewegt
haben. Sieben Regeln, in der Reihenfolge ihrer Anwendung, aufgeschrieben, weil sie sonst die
nächste Person aus dem Code rekonstruiert und die dritte davon falsch versteht.

**1. Push vor Pull, immer.** Jede lokal ausstehende Zeile geht zuerst hinaus, damit die Frage,
die der anschließende Pull beantwortet — „hat sich die Gegenstelle ebenfalls geändert?" —, an
eine Gegenstelle geht, die bereits informiert ist. Der Normalfall, in dem die Nutzerin hier
etwas bearbeitet hat und sonst niemand daran war, endet damit ganz ohne Konflikt. Die umgekehrte
Reihenfolge lässt jede lokale Änderung mit ihrem eigenen Echo kollidieren.

**2. Ein übereinstimmendes ETag ist keine Änderung.** Gleiche ETags bedeuten vollständiges
Überspringen: kein Schreiben der Zeile, keine neu materialisierten Termininstanzen, kein
`updated_at`. Das ist der billige Weg für jene rund neunzig Prozent eines Delta-Fensters, die
nur Echo sind, und zugleich die Korrektheitsregel, die verhindert, dass ein Pull unmittelbar
nach einem Push die Kopie der Gegenstelle über das legt, was die Nutzerin in der Zwischenzeit
getippt hat. Beide ETags müssen vorhanden sein, damit die Antwort überhaupt etwas bedeutet — ein
`null` auf einer der beiden Seiten heißt nicht „gleich", sondern „dieser Anbieter versioniert
seine Termine nicht", und die sichere Lesart ist dann: schreiben.

**3. Ein verändertes ETag heißt, die Gegenstelle gewinnt.** Nicht Last-Write-Wins: Es gibt keine
Uhr, die sich beide Seiten teilen, und den Änderungszeitstempel eines Anbieters mit dem eines
Containers zu vergleichen heißt, zwei Schätzungen zu vergleichen. Die Gegenstelle bekommt den
Vorzug, weil sie die eine Kopie ist, die auch andere sehen — eine am Telefon gemachte Änderung
zu verlieren lässt sich beheben, indem man sie noch einmal macht, von dem abzuweichen, was eine
Organisatorin und vier Teilnehmer vor sich haben, dagegen nicht.

**4. Eine Zeile, die sich auf beiden Seiten geändert hat, verliert ihre lokale Änderung, und
zwar laut.** Wegen Regel 1 tritt das nur ein, wenn der Push für diese Zeile fehlgeschlagen ist.
Aufgelöst wird trotzdem zugunsten der Gegenstelle, und das verworfene JSCalendar-Objekt wird vor
dem Überschreiben **vollständig** auf Warnstufe protokolliert. Diese Logzeile *ist* die Regel:
Die Änderung einer Nutzerin spurlos zu verwerfen ist das eine Ergebnis, das hinterher niemand
mehr nachvollziehen kann, und eine Zeile, die „eine Änderung wurde verworfen" sagt, ohne zu
sagen welche, beantwortet keine Frage, die jemand tatsächlich stellt.

**5. In einen schreibgeschützten Kalender wird nie gepusht.** Zugesichert, nicht angenommen:
`CalendarPusher::push()` wirft eine `LogicException` — keine `CalendarSyncException`, denn hier
kann nichts an einer Gegenstelle schiefgehen —, falls die Methode je für einen solchen Kalender
aufgerufen wird. Ausstehende Zeilen auf einem solchen Kalender bleiben unangetastet und werden
einmal pro Durchlauf von `reportUnpushableEdits()` gemeldet. Nichts wird verworfen und nichts
wiederholt: Den ausstehenden Zustand zu löschen ließe die Änderung beim nächsten Pull
verschwinden, ohne dass irgendwo stünde, dass sie je gemacht wurde, und trotzdem zu pushen ist
genau das, was `isReadOnly` verhindern soll.

**6. Ein totes Token kostet einen vollständigen Lesevorgang, und zwar einen.** `MAX_RESYNCS` ist
1. Die Engine leert `Calendar::$syncToken` — an der Entität ebenso wie in der lokalen Variablen,
damit auch ein zweiter Pull, der eine Exception wirft, im nächsten Durchlauf wieder bei null
anfängt — und liest erneut. Ein Treiber, der auf ein *leeres* Token mit `requiresFullResync`
antwortet, hat einen Fehler, und darauf in einer Schleife zu laufen hieße, einen Anbieter
endlos zu bombardieren; deshalb ist der zweite Fall eine `CalendarSyncPermanentException`, deren
Meldung benennt, wessen Fehler das ist.

**7. Ein vollständiger Lesevorgang ist maßgeblich für Löschungen.** Ohne Token gibt es keine
Tombstones, eine Entfernung während der Zeit des toten Tokens ist also nur durch Abwesenheit
erkennbar. Lokale Zeilen, deren entfernte ID im vollständigen Lesevorgang nicht aufgeführt war,
werden entfernt — `CalendarEventRepository::findRemoteRowsNotIn()`. **Zeilen ohne `remoteId`
sind von diesem Durchlauf ausgenommen**, und diese Ausnahme ist die tragende Hälfte: Ein Termin,
der hier angelegt und noch nicht gepusht wurde, war nie bei der Gegenstelle, deren Schweigen
sagt über ihn also nichts aus. Das genaue Gegenstück, `findRowsTheRemoteNeverGave()`, ist die
Frage, die das Abbestellen stellt — alles mit `remoteId` ist die Kopie von etwas, das der
Anbieter weiterhin hält, alles ohne existiert nur hier, und diese zweite Menge zu löschen würde
die einzige Kopie einer Restaurantreservierung vernichten, nur weil jemand einen Kalender
abgewählt hat.

Ein Fehlschlag wird in jedem Fall am Kalender festgehalten — `lastSyncedAt` bei Erfolg,
`lastSyncError` bei Misserfolg —, und dieses Festhalten wird **getrennt** von den Terminen
geflusht, denn der interessante Fall ist der, in dem der Sync eine Exception geworfen hat:
Doctrine hat den Manager dann meist bereits geschlossen, und ein Fehler, den niemand aufschreiben
konnte, ist ein Fehler, den niemand sieht. Ist der Manager bereits geschlossen, wird das
protokolliert, statt es mit einem zweiten Manager zu übertünchen.

## Der Treibervertrag

`App\Domain\Interface\CalendarSyncDriverInterface` hat fünf Methoden — `supports`, `discover`,
`pull`, `push`, `delete` — und einen Docblock, der länger ist als die meisten seiner
Implementierungen. Geschützt wird die Eigenschaft, dass die Engine nichts von HTTP, OAuth,
CalDAV oder den Ressourcenformen irgendeines Anbieters weiß. Sechs dokumentierte Regeln halten
das aufrecht.

**Jede ID ist opak.** Eine `RemoteCalendar::$remoteId`, ein `RemoteEvent::$etag`, ein
`CalendarChangeSet::$nextSyncToken` — was ein Treiber dort hineinlegt, kommt Byte für Byte
zurück. Nichts außerhalb des Treibers zerlegt, sortiert, kürzt oder vergleicht so etwas für
irgendetwas anderes als Gleichheit. Eine Google-Ressourcen-ID, ein Graph-Delta-Link und ein
CalDAV-href sind alle bloß Zeichenketten.

**JSCalendar ist das einzige Vokabular für Termine.** Ein Treiber bildet die Darstellung seines
Anbieters an seiner eigenen Grenze auf RFC 8984 ab und zurück. Nichts oberhalb dieser Linie hat
je ein VEVENT oder eine Graph-`event`-Ressource gesehen. Die Alternative — ein Struct aus dem
kleinsten gemeinsamen Nenner von Titel, Beginn und Ende — wirft Teilnehmer, Erinnerungen, Links
und `recurrenceOverrides` schon auf dem Hinweg weg, und zwar stillschweigend.

**Zeiten, die die Grenze überqueren, sind `DateTimeImmutable` in UTC.** Das JSCalendar-Objekt
behält sein eigenes LocalDateTime samt Zone, wie die Spezifikation es verlangt;
`RemoteEvent::$startsAt` und `$endsAt` sind Zeitpunkte, denn genau das sind die lokalen Spalten
und jede Bereichsabfrage. Sie stehen neben dem Objekt, obwohl beide daraus ableitbar wären, weil
der Treiber das Parsen ohnehin schon erledigt hat, um die Antwort des Anbieters zu lesen — und
die Engine dasselbe wiederholen zu lassen wären zwei Implementierungen einer Umwandlung.

**Jeder Fehlschlag ist eine `CalendarSyncException` oder eine Unterklasse davon.**
Transport-Exceptions, JSON-Fehler, Nicht-2xx-Status und XML-Parse-Fehler werden allesamt an der
Treibergrenze übersetzt, sodass Aufrufer nie ein HTTP-Anliegen zu sehen bekommen und nie raten
müssen, ob ein `null` „leer" oder „kaputt" hieß. Die Hierarchie richtet sich danach, was der
Aufrufer tun soll:

```
CalendarSyncException              — unclassified; the transport's retry strategy decides
  ├── CalendarSyncPermanentException  — stop, this will never work
  ├── CalendarSyncThrottledException  — back off and retry
  └── CalendarResyncRequiredException — the token is dead, read from scratch
```

Die Wahl der Unterklasse ist die folgenreichste Entscheidung eines Treibers, und die Anweisung
lautet: Wenn der Antwortkörper nicht deutlich genug ist, lieber die nicht klassifizierte
Basisklasse werfen, als „permanent" zu raten. Die Meldung landet in `Calendar::$lastSyncError`
und wird in der Einstellungsliste angezeigt; sie ist also für einen Menschen formuliert und darf
niemals ein Anmeldegeheimnis oder eine vollständige Anfrage-URL enthalten.

**Nichts in einem Treiber flusht, persistiert oder rührt Doctrine an.** Er liest die zwei
Entitäten, die man ihm reicht, und gibt DTOs zurück. `Calendar::$syncToken`,
`CalendarEvent::$remoteId` und jede andere Spalte zu schreiben ist Sache der Engine — und genau
das macht „das Token wird erst gespeichert, wenn das ganze Fenster angewandt ist" zu einer
Regel, die eine einzige Klasse einhalten kann.

**Ein Serientermin ist ein Termin, und eine geänderte Instanz ist ein Override darauf.** Ein
Treiber gibt eine Instanz nie als eigenständigen Termin aus, denn eine zweite Zeile ist an dem
Tag, auf den sie verschoben wurde, ein Duplikat neben einer Serie, die sie weiterhin am alten
Platz zeichnet. Es gibt zwei Arten, das auszudrücken, und welche ein Treiber verwendet,
entscheidet sein Anbieter, nicht der Geschmack:

- Ein Anbieter, dessen Ressource **atomar** ist — CalDAV, wo alle Komponenten mit derselben UID
  in einer `.ics` ankommen —, legt die gesamte `recurrenceOverrides`-Map in das
  JSCalendar-Objekt des Masters. Diese Map ist konstruktionsbedingt vollständig, die Engine
  *ersetzt* also, was sie hält, und eine zurückgeschobene Instanz ist eine Map, in der die
  Ressource sie nicht mehr erwähnt.
- Ein Anbieter, dessen Instanzen **eigene Ressourcen** sind — Googles `recurringEventId`,
  Graphs `type: exception` —, liefert ein `RemoteEvent` mit `$seriesRemoteId` und
  `$recurrenceId`, und die Engine trägt es in die Map des Masters ein, ohne den Rest anzurühren,
  denn ein Delta-Fenster ist immer nur eine Aussage über die Instanzen, die es nennt. Eine
  ausgefallene Instanz ist `RemoteEvent::deletedInstance()`, niemals `deleted()`: Die Serie lebt,
  und ein Tombstone gegen eine Zeile, die es nicht gibt, bewirkt schlicht gar nichts.
  **`$recurrenceId` ist der URSPRÜNGLICHE Beginn der Instanz, nicht der verschobene** — es ist
  der einzige Name, den eine Instanz behält, sobald sie einmal verschoben wurde.

Ein Override, dessen Serie die Engine als Zeile nicht kennt, wird protokolliert und verworfen.
Aus einer Instanz einen Master zu erzeugen hieße, eine Serie mit einer Termininstanz und der
falschen Regel zu erfinden.

Ein Treiber darf sich außerdem auf drei Dinge verlassen, hier festgehalten, damit keine
Implementierung sich zweimal dagegen absichert: Er wird nie aufgefordert, in einen
schreibgeschützten Kalender zu pushen, bekommt nie einen `Calendar` gereicht, dessen Quelle er
in `supports()` nicht für sich reklamiert hat, und wird nie nebenläufig für denselben Kalender
aufgerufen — der Durchlauf verschickt eine Nachricht pro Kalender, und der Handler ist der
einzige Aufrufer.

### `pull()` im Besonderen

Ein leeres Token bedeutet „alles", und der Treiber muss dann jeden Termin liefern, der
gegenwärtig existiert. Er darf für einen vollständigen Lesevorgang **keine** Tombstones
zurückgeben: Es gibt nichts, wogegen ein Tombstone stünde, und die Engine behandelt einen
vollständigen Lesevorgang als maßgeblich. Die eine Ausnahme ist eine abgesagte *Instanz*, die
absichtlich auch von einem vollständigen Lesevorgang geliefert wird — sie ist kein Tombstone
gegen eine Zeile, sondern eine Tatsache über eine Serie, die es weiterhin gibt, und ein
vollständiger Lesevorgang, der sie fallen ließe, würde jede von der Nutzerin abgesagte Instanz
wiederauferstehen lassen.

`$syncToken` wird ausdrücklich übergeben, statt von `$calendar` gelesen zu werden, damit die
Engine nach einem Resync mit `null` erneut lesen kann, ohne zuvor das geleerte Token in die
Datenbank zu schreiben. Die beiden widersprechen sich genau einmal, nämlich bei diesem zweiten
Aufruf, und das ist so gewollt.

Ein totes Token wird gemeldet, indem `CalendarChangeSet::resyncRequired()` zurückgegeben wird,
und nicht durch eine Exception, denn ein abgelaufenes Token ist ein *normaler* Ausgang, wenn man
einen Kalender abfragt, den eine Woche lang niemand angefasst hat. Eine
`CalendarResyncRequiredException` zu werfen ist erlaubt und wird identisch behandelt, für den
Fall, dass die Entdeckung zu tief passiert, um sie noch zurückgeben zu können;
`CalendarSyncService::pullOnce()` normalisiert beides, damit die Schleife nur auf eine Sache
schauen muss.

`CalendarChangeSet::$instances` ist das ungewöhnliche Feld: eine Liste von `RemoteInstance`, die
sagt, für welche Termininstanz jede Instanz-ID des Anbieters steht — **einschließlich derer, bei
denen sich nichts getan hat**. Es ist keine Änderung und wird auch nicht als solche angewandt.
Es wird absichtlich aus `$events` herausgehalten, denn ein Treiber, der zweiundfünfzig
unveränderte Termininstanzen im Jahr als Termine aufführt, brächte die Engine dazu, unter ihren
eigenen Änderungen die auszusortieren, die nichts aussagen.

## Die vier Treiber

| Treiber | Erreicht über | Änderungserkennung | Instanzen | Schreibzugriff |
|---|---|---|---|---|
| `Sync\Google\GoogleCalendarSyncDriver` | die OAuth-Freigabe des Mail-Kontos | `syncToken` auf `events.list` | eigene Ressourcen unterhalb der Serie | ja, soweit `accessRole` es zulässt |
| `Sync\Graph\GraphCalendarSyncDriver` | die OAuth-Freigabe des Mail-Kontos | `calendarView/delta` über ein begrenztes Fenster | eigene Ressourcen; `@removed` trägt nur eine ID | ja |
| `Sync\CalDav\CalDavCalendarDriver` | eine `Integration` | sync-collection (RFC 6578), sonst getctag + calendar-query | innerhalb der `.ics` des Masters | soweit die Rechte es zulassen |
| `Sync\IcsUrl\IcsUrlCalendarDriver` | eine `Integration` | HTTP-ETag / Last-Modified | innerhalb der Datei | nie |

`App\Domain\DTO\Calendar\CalendarSource` ist das, was `supports()` und `discover()`
entgegennehmen, denn die Entdeckung findet statt, bevor irgendeine `Calendar`-Zeile existiert —
die Nutzerin hat gerade etwas verbunden, und die Frage lautet „welche Kalender gibt es dort?".
Es trägt entweder ein `Account` oder eine `Integration` und strukturell genau eines von beidem,
über zwei benannte Konstruktoren. Es ist eine Vereinigung zweier nullbarer Felder statt eines
Interfaces über dem Paar, weil `Account` und `Integration` sonst nichts gemeinsam haben und das
auch nicht bekommen werden: Ein Interface ohne Methoden ist ein Kommentar, der sich als Typ
ausgibt.

### Google

Nutzt die OAuth-Freigabe des Mail-Kontos mit — `MailProvider::Google::scopes()` fragt
`https://www.googleapis.com/auth/calendar` ohnehin schon an — und hält keine eigenen
Anmeldedaten. Die Folge ist nicht theoretisch: Googles Zustimmungsdialog lässt Nutzer einzelne
Scopes abwählen, ein tadellos funktionierendes Mail-Konto kann also ein Token halten, das jeder
Kalender-Endpunkt zurückweist. Das wird beim ersten Aufruf entdeckt und als dauerhafter
Fehlschlag gemeldet, dessen Meldung die Abhilfe benennt.

**Serientermine werden als Serie geholt.** `singleEvents` bleibt `false`, ein wöchentliches
Standup kommt also einmal samt seiner RRULE an, und der lokale Materialisierer expandiert es.
Google die Expansion zu überlassen, machte aus einer Zeile Hunderte und das Fenster des
Sync-Tokens bedeutungslos.

`INITIAL_WINDOW` ist `-1 year`: Ein Kalender, der seit einem Jahrzehnt in Gebrauch ist, enthält
Zehntausende Termine, und ein unbegrenzter erster Lesevorgang holt sie alle. Die Richtung nach
vorn ist bewusst unbegrenzt. Der Preis ist ehrlich benannt — ein Termin, der älter als das
Fenster ist, wird nie bekannt, und ein vollständiger Resync stellt dasselbe Fenster wieder her
statt eines weiteren —, aber eine früher begonnene Serie kommt dennoch an, weil Google eine Serie
anhand ihrer Instanzen findet. `PAGE_SIZE` ist Googles eigener Standardwert 250;
`WRITABLE_ACCESS_ROLES` ist eine Positivliste aus `owner` und `writer`, so formuliert, damit
eine später von Google ergänzte Rolle als nicht beschreibbar gilt, bis jemand anders entscheidet.

Dem Paging wird jedes Mal bis zum Ende gefolgt, denn `nextSyncToken` kommt erst auf der letzten
Seite — einen Seiten-Cursor zurückzugeben, als wäre er eine Sync-Position, ist die Art, wie ein
Delta-Feed stillschweigend auf halbem Weg stehen bleibt und nie wieder aufholt.

### Microsoft Graph

Graph bietet Delta auf genau einer Kalenderfläche an: `calendarView`. Ein `events/delta` gibt es
nicht. Die Wahl steht also zwischen einem Delta über ein begrenztes Fenster und dem vollständigen
Neuauflisten von `/me/calendars/{id}/events` alle fünfzehn Minuten, für immer, ohne jede
Möglichkeit, von einer Löschung anders als durch Vergleich der Gesamtmenge zu erfahren. Das
Fenster gewinnt, und es sind `RecurrenceMaterialiser::HORIZON_PAST` und `HORIZON_FUTURE`, **aus
den Konstanten gelesen** statt abgeschrieben — die beiden müssen übereinstimmen, und der Weg
dazu ist, nur eines von beiden zu haben.

**Der Preis ist, dass `calendarView` Serien expandiert und dieser Treiber das rückgängig machen
muss.** Eine wöchentliche Besprechung kommt als rund fünfzig Einträge mit `type: occurrence` und
einer `seriesMasterId` an. Die Expansion durchzulassen brächte fünfzig Zeilen in
`calendar_event`, fünfzig UIDs, die kein anderer Client kennt, und fünfzig Pushes zurück an
Graph, sobald jemand „die Besprechung" zum ersten Mal bearbeitet. Eine Termininstanz ist hier
also kein Termin — sie ist eine *Erwähnung einer Serie*, und der Master wird einmal pro Serie
geholt und einmal ausgegeben. Ein `exception`-Eintrag ist eine Erwähnung *und* eine Tatsache
über eine Instanz und wird deshalb zu einem Override mit dem Schlüssel `originalStart`.

**Eine abgesagte Instanz ist nur an dem erkennbar, was ein früheres Fenster notiert hat.** Graph
meldet sie als `@removed` mit einer ID und sonst nichts — keine Serie, kein Beginn —, und die
Ressource ist fort, man kann sie also nicht fragen. Deshalb wird jede Termininstanz und jede
Ausnahme, die ein Fenster erwähnt, zusätzlich als `RemoteInstance` gemeldet, und die Engine hebt
sie auf (siehe unten).

Zwei bewusste Auslassungen: **kein `$select`**, denn ein `$select` an einem Delta-Aufruf haftet
an der ganzen Kette, und eine einzige Eigenschaft, die Graph nicht projizieren will, reißt die
gesamte Anfrage mit sich — genau so brachte `meetingMessageType` einmal die Synchronisierung von
Outlook-Postfächern vollständig zum Erliegen. Und **kein `Prefer: IdType="ImmutableId"`**, denn
die ID eines Termins ändert sich, wenn er zwischen Kalendern wandert, und dieser Treiber
synchronisiert einen Kalender nach dem anderen: Ein Weggang ist hier eine Löschung und dort
drüben eine Anlage, gleich welches ID-Schema gilt.

### CalDAV

Der eine Treiber, der mit Software spricht, die hier niemand ausgesucht hat: Nextcloud,
Radicale, Baïkal, Fastmail, iCloud, eine Synology-Kiste im Schrank. Jede Fähigkeit wird
**erfragt** statt vorausgesetzt — Schreibbarkeit über `current-user-privilege-set`, inkrementelle
Lesevorgänge über `supported-report-set` —, und es gibt in der ganzen Datei keinen Zweig für
einen einzelnen Hersteller. Ein Server, von dem plMail noch nie gehört hat, funktioniert an dem
Tag, an dem man den Treiber auf ihn richtet.

Zwei Wege, Änderungen zu lesen:

- **sync-collection (RFC 6578)**, wo es angeboten wird. Ein REPORT mit dem gespeicherten Token
  antwortet mit den geänderten Terminen und einem `<status>404</status>` je Entfernung, und das
  ist genau ein `CalendarChangeSet`. Es ist der einzige Mechanismus hier, der eine Löschung
  inkrementell ausdrücken kann.
- **getctag plus calendar-query**, wo nicht — was kein seltener Rückfall ist: Radicale, ältere
  Baïkal-Versionen und etliche Appliance-Server bieten überhaupt kein sync-collection an. Das
  ctag ist ein einziger Wert für die gesamte Collection: Gleichheit heißt, dass sich nirgends
  etwas geändert hat, und das ist die Antwort auf die meisten Abfragen und kostet ein PROPFIND.

Hat sich das ctag *doch* bewegt, fordert der Treiber einen **vollständigen Resync an, statt die
Auflistung zurückzugeben**. Das sieht verschwenderisch aus und ist Absicht: Eine calendar-query
antwortet mit allem, was gegenwärtig existiert, und sagt nichts über Löschungen, und die Engine
behandelt eine Auflistung nur dann als maßgeblich, wenn sie mit leerem Token gefragt hat. Die
Auflistung gegen ein lebendes Token zurückzugeben würde jede Bearbeitung anwenden und jeden
gelöschten Termin für immer behalten.

Beide Mechanismen speichern ihre Position im selben `Calendar::$syncToken`, und die zwei
Schreibweisen sind nicht auseinanderzuhalten — was in beide Richtungen ungefährlich ist und
keinerlei Flag brauchte. Ein ctag, das einem Server vorgelegt wird, der inzwischen
sync-collection gelernt hat, kommt als `valid-sync-token`-Precondition zurück und wird zum
Resync; ein Sync-Token, das gegen ein ctag verglichen wird, passt schlicht nie, was ebenfalls ein
Resync ist. Beides heilt sich in einer Abfrage selbst.

Jede `remoteId` ist hier eine **absolute URL**, bei Kalendern wie bei Terminen: Ein href hat nur
gegenüber dem Server Bedeutung, der ihn ausgegeben hat, das Bootstrapping nach RFC 6764 landet
regelmäßig auf einem anderen Host als dem, den die Nutzerin eingetippt hat, und die
Basisadresse einer Verbindung lässt sich nachträglich ändern.

### ICS-Feeds

Der vierte Treiber, und der einzige, dessen Gegenstelle keine Frage beantworten kann. Es gibt
keinen Delta-Feed, kein Änderungstoken, keine Ressourcen-ID je Termin und niemanden, den man um
Erlaubnis bitten könnte.

- **Identität ist die UID.** Ein `RemoteEvent::$remoteId` *ist* hier die UID, was zulässig ist,
  weil IDs opak sind und nur auf Gleichheit verglichen werden — und es zahlt sich real aus: Die
  Besprechung, die eine Einladung bereits in einen anderen Kalender gelegt hat, wird über den
  Rückfall-Lookup des `CalendarPuller` wiedererkannt.
- **Die Änderungserkennung ist die von HTTP.** ETag und Last-Modified werden, getrennt durch
  `TOKEN_SEPARATOR`, in `Calendar::$syncToken` gepackt; der Trenner ist `\x1f`, ein Unit
  Separator statt eines Pipe-Zeichens, denn ein ETag ist eine opake, in Anführungszeichen
  gesetzte Zeichenkette, die legal jedes druckbare Zeichen enthalten darf, und RFC 9110 §5.5
  verbietet Steuerzeichen in Feldwerten. Ein unveränderter Kalender ist ein 304 ohne
  Antwortkörper.
- **Eine Änderung gibt das Token preis**, genau wie der ctag-Rückfall bei CalDAV und aus genau
  demselben Grund. Zwei Downloads pro tatsächlicher Änderung, dazwischen keiner, und im
  Gegenzug ein Kalender, der keine Geister ansammelt.
- **Schreibschutz ist eine Tatsache, keine Einstellung.** `isReadOnly` ist am entdeckten
  Kalender fest auf `true` verdrahtet, und `push()` sowie `delete()` werfen, statt stillschweigend
  nichts zu tun: Die Engine verspricht, sie nie aufzurufen, ein Erreichen beider ist also ein
  Fehler, und Schweigen an dieser Stelle sähe aus wie Änderungen, die beim nächsten Durchlauf
  spurlos verschwinden.

`REMOTE_ID` ist buchstäblich `'feed'` und nicht die URL, und das gleich doppelt:
`Calendar::$remoteId` fasst 255 Zeichen, und eine signierte Feed-Adresse überschreitet das
regelmäßig; außerdem würde eine Adresse, die die Nutzerin später korrigiert, den Kalender
verwaisen lassen, der sie spiegelt. Die Adresse liegt an der `Integration`. Siehe
[ICS-Feeds](../providers/ics-feeds.md).

## Instanzidentität und der nackte Tombstone

`CalendarEvent::$remoteInstances` ist eine jsonb-Map von der opaken Instanz-ID des Anbieters auf
den **ursprünglichen Beginn** dieser Termininstanz, als ISO-8601-Zeitpunkt in UTC mit dem `Z`
daran — `CalendarEvent::INSTANCE_START_FORMAT`, einmal benannt, weil es ein Vertrag zwischen dem
Puller, der schreibt, und den Treibern, die lesen, ist. Das `Z` ist keine Verzierung: Ein ohne
`Z` geschriebener Wert wird in der Zone gelesen, die der Lesende gerade hält, und das ist für
einen Berliner Kalender eine Instanz mit zwei Stunden Versatz.

Es existiert, weil Microsofts Tombstone eine ID trägt und sonst nichts. So wie er ist, passt er
auf keine Zeile — eine Instanz war noch nie eine —, die Löschung bewirkt also nichts, und die
Termininstanz, die die Nutzerin in Outlook entfernt hat, wird für immer weitergezeichnet.
`CalendarPuller::recognisedInstance()` fragt zuerst die Map (über
`CalendarEventRepository::findOneByRemoteInstanceId()`, ein `jsonb_exists`-Lookup auf einem
GIN-Index) und verwandelt den Tombstone zurück in `RemoteEvent::deletedInstance()`.

Drei Entwurfsnotizen zu der Spalte:

- **Schlüssel ist die ID, nicht der Beginn**, obwohl ein Push in die andere Richtung liest. Die
  eine Frage, die sich in PHP nicht beantworten lässt, ist „zu wem gehört diese Instanz-ID?" —
  gestellt an die gesamte Tabelle, ausgehend von einem Tombstone, der sonst nichts nennt —, und
  ein jsonb-Schlüssel ist das, was ein Index beantworten kann. Der umgekehrte Lookup, den ein
  Push braucht, ist ein Durchsehen der Map einer einzelnen Serie, die ohnehin schon im Speicher
  liegt.
- **Eine Spalte statt einer Tabelle**, und der Handel wird offen benannt: Eine Zeile je Instanz
  ließe sich besser indizieren, aber jeder Lesevorgang liest die Map genau eines Termins durch
  etwas, das diesen Termin bereits hält, und die Einträge haben kein Eigenleben.
- **Bis zum Horizont der Termininstanzen beschnitten.** Eine ID für eine Instanz, die keine
  Ansicht zeigen kann, beantwortet keine Frage. `rememberInstances()` ersetzt außerdem eine ID,
  deren ursprünglichen Beginn bereits eine andere ID für sich beansprucht, denn Microsoft
  vergibt bei manchen Bearbeitungen einen neuen Schlüssel für eine Termininstanz, und ein Push
  an die tote ID würde eine Ressource patchen, die es nicht gibt.

## Ein Fenster anwenden

`CalendarPuller::apply()` läuft in zwei Durchgängen, und diese Aufteilung ist es, die ein
Override landen lässt, das *vor* seinem Master ankommt:

1. Jeder Termin, der keine Instanz ist: Identität ist zuerst `remoteId`, dann `uid` innerhalb des
   Kalenders. Der Rückfall auf die uid ist keine Nettigkeit — eine per Mail eintreffende Einladung
   erzeugt einen Termin mit der UID der Organisatorin, und dieselbe Besprechung im verbundenen
   Kalender trägt diese UID zusammen mit einer entfernten ID, die plMail noch nie gesehen hat.
   Ohne ihn legt das Annehmen einer Einladung die Besprechung zweimal in den Kalender.
2. Jede Instanz, gruppiert nach Serie, ein Schreibvorgang je Serie statt einer je Instanz. Die
   Zeilen, die der erste Durchgang hervorgebracht hat, werden **vor** dem Repository befragt, und
   das ist keine Optimierung: Hier flusht nichts, eine soeben angelegte Serie liegt also in der
   Unit of Work und nicht in der Datenbank, und `findOneByRemoteId()` antwortete mit `null` und
   verwürfe jedes Override, das im selben Fenster ankam wie seine Serie.

Dann das Beschneiden, falls dies ein vollständiger Lesevorgang war, und erst danach das Token —
`Calendar::$syncToken` wird **zuletzt** geschrieben, nachdem jeder Termin des Fensters angewandt
wurde. Ein Absturz auf halber Strecke liest im nächsten Durchlauf dasselbe Fenster erneut, und
jede Operation hier ist idempotent, das kostet also eine Wiederholung; das Token zuerst zu
speichern hieße, über alles hinwegzugehen, was noch nicht angewandt war, und es nie wieder
anzusehen.

Die eigene entfernte ID einer Instanz steht bewusst **nicht** in der `$seen`-Liste, die das
Beschneiden verwendet. Sie hat nie eine lokale Zeile benannt, sie aufzuführen schützte also
nichts — und sie wegzulassen ist genau das, was einen vollständigen Lesevorgang die doppelten
Zeilen aufräumen lässt, die verschobene Instanzen früher erzeugt haben.

Ein aus einer Instanz gebauter Patch stellt `start` in der Zone der **Serie** dar und nicht in
der der Instanz, denn RFC 8984 §4.3.3 expandiert eine Regel in der `timeZone` des Termins selbst.
Eine Instanz, die eine andere Zone für sich beansprucht — Graph tut das bei einer auf Reisen
verschobenen Termininstanz —, landete sonst zur richtigen Uhrzeit am falschen Ort.

## Eine lokale Änderung markieren und pushen

Nichts leitet daraus ab, dass sich eine Zeile geändert hat. `App\Domain\Enum\Calendar\SyncState`
hält die Absicht im Moment der Bearbeitung fest, und die Alternative — eine Schattenkopie dessen,
was zuletzt gepusht wurde — verdoppelt den Speicher für jeden Termin und antwortet immer noch
falsch, sobald Vergleich und Serialisierer sich über Schlüsselreihenfolge oder ein `null` uneins
sind.

| Fall | Bedeutung |
|---|---|
| `Clean` | im Gleichschritt mit der Gegenstelle, oder zu keiner Gegenstelle gehörend |
| `PendingCreate` | hier angelegt und nie gesendet; hat noch keine `remoteId` |
| `PendingUpdate` | hier seit dem letzten erfolgreichen Push geändert |
| `PendingDelete` | hier gelöscht; die Zeile wartet darauf, dass die Gegenstelle informiert wird |

Der Preis ist, dass ein Schreibvorgang, der das Markieren vergisst, eine Änderung ist, die nie
das Haus verlässt — weshalb das Markieren am `CalendarEventWriter` sitzt, der einen Klasse, durch
die ohnehin jeder lokale Schreibvorgang geht:

- `markLocallyChanged()` wendet `SyncState::afterLocalEdit()` an. Aufgerufen wird es von dem, was
  die Änderung gemacht hat, und **nie von `write()` selbst**, denn `write()` ist auch der Weg,
  auf dem die Sync-Engine anwendet, was sie gerade *gelesen* hat, und ein Markieren dort ließe
  jeden Pull einen Push der Daten der Gegenstelle direkt an sie selbst einreihen. Auf einem
  Kalender, der nichts spiegelt, ist es wirkungslos, Aufrufer brauchen also keine eigene Kopie
  der Frage „ist das hier synchronisiert?".
- `markLocallyCreated()` ist getrennt, weil `afterLocalEdit()` einen brandneuen Termin nicht von
  einem sauberen unterscheiden kann und das Raten anhand einer leeren `remoteId` genau für den
  einen Fall falsch ist, auf den es ankommt — auch ein Termin, dessen Anlage noch aussteht, hat
  keine `remoteId`.
- `markLocallyDeleted()` setzt `PendingDelete` und **leert die Termininstanzen sofort**. Jede
  Ansicht liest Termininstanzen und keine liest Termine, die Löschung wirkt also sofort, ohne dass
  irgendeine Ansicht von der Existenz von `PendingDelete` erfahren müsste. Der Rückgabewert sagt,
  ob der Aufrufer die Entität noch entfernen muss: `false` heißt, die Zeile ist jetzt Sache des
  Pushers.

`afterLocalEdit()` ist ein erschöpfendes `match` mit zwei bewussten Fixpunkten: Ein
`PendingCreate` bleibt eine Anlage, so oft es auch bearbeitet wird, denn es hochzustufen schickte
ein Update für eine Ressource, von der die Gegenstelle nie gehört hat; und ein `PendingDelete`
wird durch eine Bearbeitung nicht rückgängig gemacht.

`SyncState::pendingCases()` wird aus `isPending()` abgeleitet statt ausgeschrieben, damit ein
fünfter Fall nicht hinzugefügt und stillschweigend in der Abfrage vergessen werden kann, die die
Arbeit findet — was sich exakt als „manche Änderungen synchronisieren nie" äußern würde.

Der `CalendarPusher` arbeitet `findPendingSync()` ab, **nach ID sortiert**, damit die Pushes in
der Reihenfolge hinausgehen, in der die Änderungen gemacht wurden. Das zählt für die eine Abfolge,
die nicht kommutativ ist: Ein Termin, der angelegt und wieder gelöscht wurde, bevor eines von
beidem draußen war, ergibt eine `PendingCreate`-Zeile, die bereits fort ist, und jede andere
Reihenfolge pushte die Löschung für eine Ressource, von der die Gegenstelle nichts weiß.

Anlegen und Aktualisieren sind eine Operation, und was von beidem es ist, ergibt sich aus
`$remoteId` statt aus einem Flag: Die ID ist die Tatsache, und ein Flag daneben ist eine zweite
Kopie, die widersprechen kann. Sowohl `remoteId` als auch `etag` werden auch bei einem Update
zurückgeschrieben, denn ein Anbieter, der einem Termin beim Bearbeiten eine neue ID gibt, ließe
die lokale Zeile sonst auf eine ID zeigen, die nicht mehr auflöst — und der nächste Pull hielte
dieselbe Besprechung für eine Fremde und schriebe eine zweite Kopie.

Fehlschläge gelten **je Termin**, und geschluckt wird nur die
`CalendarSyncPermanentException`: `abandon()` protokolliert das gesamte JSCalendar-Objekt auf
Fehlerstufe und lässt die Zeile `Clean`. Ein Termin, den die Gegenstelle dauerhaft abgelehnt hat,
würde sonst bei jedem Durchlauf erneut angeboten, für immer, und eine Warteschlange, die etwas
nachweislich Unmögliches wiederholt, wiederholt irgendwann nichts anderes mehr. Drosselung und
Resync werden durchgelassen, denn beide bedeuten, dass die *Verbindung* unbrauchbar ist und
nicht diese Zeile.

### Instanzen pushen

Eine Serie wird mit ihren Instanzen gepusht, und die beiden Hälften reisen je nach Anbieter
verschieden — dieselbe Aufteilung, die der Pull macht. Ein atomarer Treiber schreibt die
Overrides in die Ressource hinein. Ein Treiber, dessen Instanzen eigene Ressourcen sind, muss
nach dem Schreiben des Masters für jedes Override die Instanz des Anbieters zum **ursprünglichen**
Beginn finden und dorthin schreiben: verschoben, umbenannt oder verlängert per Patch, abgesagt per
`{"excluded": true}`. `App\Domain\DTO\Calendar\InstanceOverride::listOf()` löst die gespeicherte
Map in die Zeitpunkte auf, die beide brauchen.

**Eine Instanz nicht platzieren zu können darf den Push nicht scheitern lassen.** Der Master ist
zu diesem Zeitpunkt bereits geschrieben, und ein Treiber, der eine Exception würfe, ließe die
Engine ohne die Möglichkeit zurück, die soeben zurückgekommene ID festzuhalten — was bei einer
Anlage im nächsten Durchlauf eine zweite Kopie der Besprechung bedeutet.

Ein Treiber darf `CalendarEvent::$remoteInstances` lesen, um eine Instanz direkt zu adressieren,
und darf es nicht schreiben: Wie jede andere Spalte gehört sie der Engine.

## Push-Kanäle gegenüber Polling

**Push ist nie tragend.** Eine selbstgehostete Installation hat womöglich überhaupt keine
öffentlich erreichbare HTTPS-Adresse; eine fehlgeschlagene Registrierung heißt also „bleib beim
Polling" und nicht „Fehler". Der Durchlauf alle fünfzehn Minuten läuft ungeachtet dessen und wird
von alldem nicht berührt.

| | Google Calendar | Microsoft Graph |
|---|---|---|
| Mechanismus | `events.watch`-Kanal, ein schlichter Webhook | `/subscriptions` über `me/calendars/{id}/events` |
| Endpunkt | `POST /webhook/google/calendar` | `POST /webhook/graph/calendar` |
| Nachweis | Kanal-Token in `X-Goog-Channel-Token` | `clientState` im Antwortkörper |
| Lebensdauer | eine Woche, je nachdem, was Google gewährt | knapp drei Tage |
| Erneuerung | neu registrieren, dann den alten Kanal stoppen | `PATCH` auf das Ablaufdatum |
| Abbau | `channels/stop` mit (id, resourceId) | `DELETE /subscriptions/{id}` |

`App\Service\Calendar\Push\CalendarPushRegistry` ermittelt den Manager und antwortet für einen
Kalender, den niemand für sich beansprucht, mit **null**, statt zu werfen — ein CalDAV-Kalender
hat kein Push, und ein von Hand angelegter lokaler ebenso wenig, jeder Aufrufer überspringt das
also klaglos. Die Manager liegen unter `Service/Calendar/Push/` und nicht neben dem Sync-Treiber
des jeweiligen Anbieters, und zwar absichtlich: `Sync/Google/` bedeutet „der Sync-Treiber und
die Teile, aus denen er zusammengesetzt ist", und wer das Verzeichnis öffnet, um einen Pull zu
verstehen, sollte unterwegs nicht der Kanalregistrierung begegnen.

Die vier Registrierungsspalten an `Calendar` — `pushChannelId`, `pushResourceId`, `pushSecret`,
`pushExpiresAt` — sind Spalten und keine Schlüssel im `settings`-jsonb-Beutel, und das ist die
eine Entscheidung dort, über die zu streiten sich lohnt. Daran schlagen zwei
unauthentifizierte, aus dem Internet erreichbare Webhooks eine Benachrichtigung nach und prüfen
sie dagegen; es braucht einen Unique-Index (`uniq_calendar_push_channel_id`, unique, damit eine
Benachrichtigung nicht mehrdeutig sein kann, wobei Postgres beliebig viele NULLs für den
Normalzustand der Spalte zulässt) und einen laufzeitkonstanten Vergleich gegen eine bekannte
Spalte. `pushResourceId` wird gespeichert, weil ein Google-Kanal gestoppt wird, indem man das Paar
`(id, resourceId)` per POST schickt, und die resourceId ausschließlich in der Antwort auf den
watch-Aufruf zu sehen ist — sie nicht aufzubewahren heißt, den Kanal nie stoppen zu können.
`clearPushChannel()` leert alle vier auf einmal, denn ein Abbau, der die ID leerte und das
Geheimnis stehen ließe, hinterließe einen Kalender, der Benachrichtigungen für einen Kanal
verifiziert, den er nicht mehr stoppen kann.

`pushExpiresAt` ist das, was der **Anbieter** gewährt hat, nicht das, worum plMail gebeten hat.
Google steht es frei, weniger als die angeforderte TTL zu gewähren, und stattdessen anhand einer
lokalen Konstante zu erneuern ist die Art, wie ein Kanal einen Tag vor dem ersten Ersetzungsversuch
stillschweigend stirbt.

`App\Service\Calendar\Push\PushCallbackUrl` baut die Callback-Adresse aus der konfigurierten
öffentlichen Basis-URL und nicht aus der eingehenden Anfrage — Reverse Proxies sind der
Normalfall im Betrieb, eine aus der Anfrage abgeleitete URL trägt also einen internen Hostnamen
oder ein `http://` nach der TLS-Terminierung, und in dem geplanten Kommando, das diese Kanäle
tatsächlich registriert, gibt es überhaupt keine Anfrage. Sie wird bei jedem Aufruf neu
ermittelt und nie als Zeichenkette injiziert, denn die Worker laufen lange, und die öffentliche
Adresse wird typischerweise erst nach ihrem Start im Einrichtungsbildschirm gespeichert. Sie
formuliert die Erreichbarkeitsregel des `GraphSubscriptionManager` (HTTPS, kein Loopback-Name) neu,
statt sie aufzurufen, und der Docblock sagt das auch: Die beiden dürfen auseinanderlaufen; was
nicht auseinanderlaufen darf, ist die Antwort.

**Nichts registriert einen Kanal außer `app:calendar:push`.** Einen Kalender zum Spiegeln
anzuhaken verschickt eine `RegisterCalendarPushMessage`, aber der stündliche Durchlauf ist der
Wiederholungsversuch und nicht der einzige Weg hinein — Registrierungen scheitern aus
Betriebsgründen, die mit dem Klick nichts zu tun haben, und an den Abo-Ablauf gebunden bekämen
diese Kalender nie Push, bis sie jemand neu abonniert.

Eine Benachrichtigung trägt in beiden Mechanismen **nichts** darüber, was sich geändert hat,
jeder Webhook tut also genau eine Sache — eine `SyncCalendarMessage` für den benannten Kalender
verschicken —, und jede Entscheidung bleibt in der Engine. Googles erste Benachrichtigung nach
der Registrierung ist ein `X-Goog-Resource-State: sync`-Handshake, der nur „der Kanal ist offen"
bedeutet; darauf zu reagieren legte für jede Registrierung und jede stündliche Erneuerung der
ganzen Installation einen vollständigen Kalenderlesevorgang in die Warteschlange. Zur
Domainverifikation, die Google verlangt, bevor es überhaupt etwas zustellt und für die Microsoft
kein Gegenstück hat, siehe [Google](../providers/google.md).

## Fallstricke

**Ein Treiber, der bei einem vollständigen Lesevorgang Tombstones zurückgibt, löscht Dinge
doppelt, und ein Treiber, der eine abgesagte Instanz weglässt, lässt sie wiederauferstehen.** Die
beiden Regeln sehen symmetrisch aus und sind es nicht: Ein Tombstone gegen eine Zeile ist bei
einem vollständigen Lesevorgang nichts, während eine ausgeschlossene Instanz eine Tatsache über
eine Serie ist, die es weiterhin gibt.

**Einen Seiten-Cursor als `nextSyncToken` zurückzugeben schneidet den Feed stillschweigend ab.**
Die Engine speichert wortgetreu, was man ihr gibt; der Fehlschlag ist also ein Kalender, der
aufhört sich zu aktualisieren, ohne dass irgendwo ein Fehler stünde.

**Ein zu breit geschriebenes `supports()` stiehlt einem anderen Treiber die Kalender.** Die
Registry nimmt den ersten Treiber, der Ja sagt, und `supports()` darf keine I/O betreiben — es
wird bei jeder Synchronisierung jedes Kalenders einmal je registriertem Treiber aufgerufen.

**`Calendar::$syncToken` von Hand zu leeren ist ein vollständiger Lesevorgang, und ein
vollständiger Lesevorgang beschneidet.** Zeilen mit einer `remoteId`, die die Auflistung nicht
erwähnt, werden entfernt. Bei Graph im Besonderen ist das Fenster begrenzt, ein aus
`HORIZON_PAST` herausgealterter Termin fehlt in der Auflistung, und seine Zeile geht dahin — was
die gewollte Antwort ist, denn seine Termininstanzen wären im selben Durchlauf ohnehin vom
Materialisierer verworfen worden, von außen aber nicht offensichtlich.

**`markLocallyChanged()` innerhalb von `write()` wäre eine Endlosschleife mit sich selbst.** Jeder
Pull reihte einen Push der Daten der Gegenstelle zurück an sie ein. Allein aus diesem Grund liegt
das Markieren beim Aufrufer.

**Eine Bearbeitung einzelner Instanzen erreicht bei Google oder Graph die Gegenstelle nur dann,
wenn das `push()` des Treibers sie platziert.** Die Instanzplatzierung zu überspringen verliert
die Änderung lokal nicht — es heißt, dass die Änderung allein in plMail sichtbar ist, bis ein
vollständiger Lesevorgang mit irgendeiner Ausnahme für diese Serie die gesamte Map ersetzt und
den lokalen Patch mitnimmt.

**Alles, was `MAX_RESYNCS` erhöht, öffnet die Schleife wieder, die damit geschlossen wurde.**
Eins ist die Zahl; ein Treiber, der auf ein leeres Token mit `requiresFullResync` antwortet, hat
einen Fehler, und die permanente Exception existiert, um ihn zu benennen, und nicht, um einen
Anbieter zu bombardieren, bis das Kontingent aufgebraucht ist.
