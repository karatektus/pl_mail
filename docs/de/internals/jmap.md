<!-- translated-from: internals/jmap.md sha1:ff0608b1c1ab43dfb25895bed051c33b0cf45c7a -->
# JMAP

Was implementiert ist, was bewusst nicht, die ID-Räume und warum jeder von ihnen so aussieht,
wie er aussieht, wie State-Token funktionieren und warum Kalender keine Änderungen berechnen
können, und schließlich Push-Subscriptions. `CLIENT_DEVELOPMENT.md` in `docs/` ist die Referenz
auf Protokollebene für alle, die einen Client schreiben; diese Seite erklärt, warum der Server so
geschnitten ist, wie er ist. Zum Verbinden eines Clients siehe
[Andere Clients](../features/clients.md).

## Oberfläche

Der Server liegt vollständig unter `src/Jmap/`, mit eigenen Verzeichnissen `Method/`, `Mapper/`,
`Protocol/`, `Query/`, `State/`, `Push/` und `Session/`. Die Endpunkte:

| Route | Zweck |
|---|---|
| `/jmap/session` und `/.well-known/jmap` | das Session-Objekt (RFC 8620 §2) |
| `/jmap/api` | der Endpunkt für Methodenaufrufe |
| `/jmap/upload/{accountId}` | Blob-Upload |
| `/jmap/download/{accountId}/{blobId}/{name}` | Blob-Download |
| `/jmap/eventsource` | EventSource-Push |

Authentifiziert wird mit App-Passwörtern an einer zustandslosen Firewall — siehe
[Sicherheitsmodell](security-model.md).

### Implementierte Methoden

**Core** — `Core/echo`, `PushSubscription/get`, `PushSubscription/set`.

**Mail** — `Mailbox/get`, `Mailbox/query`, `Mailbox/changes`, `Mailbox/set`; `Email/get`,
`Email/query`, `Email/changes`, `Email/set`; `Thread/get`, `Thread/changes`, `Thread/set`;
`EmailSubmission/get`, `EmailSubmission/changes`, `EmailSubmission/set`; `Identity/get`,
`Identity/set`; `SearchSnippet/get`.

**Kalender** — `Calendar/get`, `CalendarEvent/get`, `CalendarEvent/query`,
`CalendarEvent/set`.

`App\Jmap\Method\MethodRegistry` indiziert jede mit `app.jmap_method` getaggte Klasse über deren
`name()`; eine Methode hinzuzufügen ist also eine Klasse, die `App\Jmap\Method\JmapMethod`
implementiert, und sonst nichts. `App\Jmap\Protocol\JmapProcessor` führt die Aufrufe der Reihe
nach aus und löst Rückverweise über den `ReferenceResolver` auf; ein fehlschlagender Aufruf
liefert einen Fehler an Ort und Stelle, ohne den Rest abzubrechen.

### Was bewusst nicht implementiert ist

- **`Calendar/set`.** Die Session weist `mayCreateCalendar: false` aus. Die beiden bereitgestellten
  Rollen legt der `CalendarProvisioner` an, einen abonnierten der Abo-Ablauf, und für keines von
  beiden könnte ein JMAP-Create einspringen.
- **`Calendar/changes` und `CalendarEvent/changes`.** Kein Versäumnis — siehe den Abschnitt zum
  State weiter unten.
- **`VacationResponse`, verzögertes Senden in `EmailSubmission` und
  `urn:ietf:params:jmap:calendars`.** Die Submission-Capability weist `maxDelayedSend: 0` aus,
  weil das Senden auf dem Messenger-Bus eingereiht wird und dieser keine Terminierung kennt; es
  gibt also kein Zeitfenster für späteres Senden, das man behaupten könnte.
- **Teilnehmer, Privatsphäre, Erinnerungen und Links in `CalendarEvent/set`.** Jedes davon ist
  mit seinem Grund aufgeführt; siehe unten.

## Capabilities

`App\Jmap\Protocol\Capability` hält die URNs:

| Konstante | URN | In `using` ausgewiesen |
|---|---|---|
| `CORE` | `urn:ietf:params:jmap:core` | ja |
| `MAIL` | `urn:ietf:params:jmap:mail` | ja |
| `SUBMISSION` | `urn:ietf:params:jmap:submission` | ja |
| `CALENDARS` | `urn:plmail:params:jmap:calendars` | ja |
| `PUSH` | `urn:plmail:params:jmap:push` | nein — nur in der Session |

Zwei davon sind Hersteller-URNs, und beide sind es mit Absicht.

**`CALENDARS` ist bewusst nicht `urn:ietf:params:jmap:calendars`.** JMAP for Calendars ist ein
nicht verabschiedeter Entwurf, dessen Objektform noch in Bewegung ist — zwischen Revisionen
wurden Eigenschaften umbenannt und anders eingeordnet —, und den IETF-URN auszuweisen versprach
damit einen Vertrag, auf den sich kein Client verlassen könnte; ein Client, der ihm glaubte,
ginge bei der Revision nach derjenigen kaputt, gegen die das hier geschrieben wurde. Ein
Hersteller-URN sagt, was wahr ist: Das ist plMails Kalenderoberfläche, und nur was für plMail
geschrieben wurde, sollte sie verwenden. Der Wechsel bei Verabschiedung des Entwurfs ist dann
eine *Ergänzung* statt eines Bruchs, denn beide lassen sich ausweisen, während die Clients
hinüberwandern.

**`PUSH` trägt den öffentlichen VAPID-Schlüssel**, denn RFC 8620 definiert keinen
standardisierten Platz dafür, und ohne ihn kann ein Client `pushManager.subscribe()` nicht
aufrufen. Ein leerer Schlüssel ist für einen Client das Signal, Push gar nicht erst anzubieten.

`Capability::SUPPORTED` ist das, was ein Client in `using` angeben darf; alles andere ist eine
`UnknownCapabilityException`. `PUSH` steht nicht auf dieser Liste, weil es eine Tatsache auf
Session-Ebene ist und nicht etwas, unter dem sich eine Anfrage stellen ließe.

### Session

`App\Jmap\Session\SessionBuilder` legt **ein JMAP-Konto je verbundenem Mail-Konto** offen; eine
einzige Anmeldung zählt damit die gesamte Mail der Nutzerin auf, und ein vereinigter Posteingang
ist Sache des Clients — eine `Email/query` je Konto, im Client zusammengeführt.

Die Core-Limits: `maxSizeUpload` 50 MB, `maxConcurrentUpload` 4, `maxSizeRequestObject` 10 MB,
`maxConcurrentRequests` 4, `maxCallsInRequest` 32, `maxObjectsInGet` 500, `maxObjectsInSet`
500.

Die Kalender-Capability stellt drei Tatsachen auf Kontoebene fest, und jede davon steht dort,
weil ein Client sie sonst auf die harte Tour herausfände:

- **`maxEventsInGet: 100`**, niedriger als die globalen 500, denn `CalendarEvent/get` löst eine
  ID nach der anderen auf — der auf Eigentümerschaft eingegrenzte Lookup ist der einzige, den
  das `CalendarEventRepository` anbietet, und ein Client, der sich an 500 hielte, träfe auf ein
  `requestTooLarge`, das ihm nicht angekündigt wurde.
- **`mayCreateCalendar: false`**, passend zum fehlenden `Calendar/set`.
- **`materialisedHorizon`**, direkt aus `RecurrenceMaterialiser::HORIZON_PAST` und
  `HORIZON_FUTURE` gelesen. Termininstanzen existieren nur innerhalb dieses Horizonts, eine
  Abfrage außerhalb antwortet also aus einem unvollständigen Index — was ausgesprochen wird,
  statt es einen Client als eine Serie entdecken zu lassen, die einfach aufhört.

Der `SessionBuilder` ist eine von nur drei Stellen, die an die Form der Mail-Konto-Entität
gekoppelt sind, und sein Docblock nennt die drei Aufrufe, die er macht, damit eine Umbenennung
genau einen Ort zum Nachsehen hat. Die anderen sind `App\Jmap\Account\AccountResolver` und
`CalendarAccountResolver`.

## ID-Räume

Jede ID, die JMAP herausgibt, ist eine servergegebene Zeichenkette, die ein Client nicht zerlegen
darf, und jede einzelne davon ist in plMail eine Entscheidung darüber, *welcher Tabelle
Autoincrement sie ist*. Das wiegt schwerer, als es klingt: IDs aus verschiedenen Tabellen sind
allesamt schlichte ganze Zahlen, eine nicht übersetzte ID scheitert also nicht — sie benennt ein
echtes, falsches Objekt, das ein Client dann holt und darstellt.

| JMAP-Objekt | plMail-ID | Warum |
|---|---|---|
| Account | Zeilen-ID von `Account` | ein JMAP-Konto ist ein verbundenes Mail-Konto |
| Mailbox | **Zeilen-ID von `LabelBinding`** | ein `Label` ist nutzerbezogen; eine JMAP-Mailbox ist kontobezogen |
| Email | Zeilen-ID von `Message` | |
| Thread | Zeilen-ID von `MessageThread` | |
| EmailSubmission | die **Email**-ID | eine Submission hat keine eigene Tabelle |
| Calendar | Zeilen-ID von `Calendar` | ein Konto liefert die Kalender, es gibt also nichts zu übersetzen |
| CalendarEvent | **Zeilen-ID von `CalendarEvent`** — die Serie | siehe unten |
| blobId | `m-<id>` / `p-<id>` / `u-<id>` | zwei unabhängige Byte-Quellen, dazu vorgemerkte Uploads |

### Eine Mailbox ist ein Binding, kein Label

`App\Entity\Label\Label` gehört der Nutzerin; `App\Entity\Label\LabelBinding` ist der Ort, an dem
dieses Label auf einem Konto materialisiert wird. Ein JMAP-Konto ist ein Mail-Konto, die
kontobezogene Zeile ist dort also das, was eine stabile ID hat.

`App\Jmap\Mapper\EmailMapper` übersetzt deshalb auf dem Weg nach draußen. `mailboxIds` stammt aus
`Message::$labels` — der maßgeblichen Zuordnung je Nachricht — und niemals aus `thread_label`,
das die abgeleitete Vereinigung ist, die der `ThreadLabelSynchronizer` neu berechnet und die für
jede Nachricht einer Konversation eine Mailbox melden würde. Diese Zeilen halten **Label**-IDs,
und auf der Leitung braucht es **Binding**-IDs, und genau die verarbeiten auch `inMailbox` und
der `mailboxIds`-Patch von `Email/set`. Die unübersetzte ID auszugeben scheitert nicht laut: Sie
benennt irgendeine unbeteiligte Mailbox, die zufällig dieselbe Zahl trägt — und genau das war
einmal ausgeliefert und ist das, was der `EmailMapperTest` jetzt festnagelt. Ein Label ohne
Binding auf dem Konto wird weggelassen, statt als eine ID ausgegeben zu werden, die der Client
nicht auflösen kann.

Der `EmailMapper` veröffentlicht außerdem zwei synthetische Body-Parts mit den festen `partId`s
`"text"` und `"html"`, denn plMail speichert einen flachgeklopften Body (`bodyText` /
`bodyHtmlSafe`) statt eines MIME-Part-Baums. Clients behandeln `partId` als opak, und diese
beiden sind je Nachricht stabil, was alles ist, was `fetchTextBodyValues` braucht.

### Eine CalendarEvent-ID ist die Serie

Das ist die ID-Raum-Entscheidung, an der die Kalenderoberfläche hängt, und sie ist nicht die
naheliegende. Die Abfrage, die Termine findet, läuft über `calendar_event_occurrence`, die IDs,
die die Datenbank zurückgibt, sind also **Termininstanz**-IDs, und irgendetwas muss sie
übersetzen.

**Die Serie ist die richtige Einheit**, weil sie das ist, was ein JSCalendar-Event *ist*
(RFC 8984): ein Objekt mit `recurrenceRules` und `recurrenceOverrides`, aus dem ein Client die
Instanzen selbst expandiert. Eine ID je Termininstanz benennte Zeilen, die diese Anwendung bei
jedem Schreibvorgang anlegt und wieder zerstört — der Materialisierer schreibt sie geschlossen
neu —, eine vom Client gespeicherte ID veraltete also in dem Moment, in dem jemand einen Titel
korrigiert.

Die Übersetzung findet an genau einer Stelle statt, in
`App\Jmap\Query\CalendarEventQueryRunner`, und `App\Jmap\Mapper\CalendarEventMapper` trägt die
Begründung. Der Test, der das absichert, gibt jede ausgegebene ID in einen Filter zurück und
prüft, dass sie genau das auswählt, woraus sie stammt — ein ID-Raum überall: `list[].id`, die
IDs von `/query`, von `/get` und von `/set`.

Das veröffentlichte Objekt **ist** das gespeicherte kanonische JSCalendar-Objekt plus die
Hülle, die JMAP ergänzt (`id`, `calendarId`, `uid`, `sequence`, `created`, `updated`,
`isRecurring`). Nichts wird aus den projizierten Spalten neu abgeleitet: Der
`CalendarEventWriter` ist die eine Stelle, an der aus Spalten JSCalendar wird, und eine zweite
Ableitung im Mapper wäre eine zweite Antwort auf die Frage, was der Termin ist. Ein Termin,
dessen `jscalendar` leer ist, wird deshalb nahezu leer veröffentlicht, was ehrlich ist — kein
Writer hat diese Zeile gemacht. `isRecurring` wird veröffentlicht, weil ein Client es an der
Regel allein nicht sehen kann: Eine Regel, die dieser Server nicht umwandeln konnte, wird
wortgetreu gespeichert und expandiert zu einer einzigen Termininstanz; das Vorhandensein von
`recurrenceRules` ist also nicht dieselbe Aussage wie „das wiederholt sich hier".

Der `CalendarMapper` veröffentlicht Schreibbarkeit als `myRights` statt als `isReadOnly`-Flag,
der Mailbox aus RFC 8621 folgend — zwei Schreibweisen von „darf ich hier schreiben?" sind die
Art, wie eine von beiden am Ende nicht mehr abgefragt wird. `isVisible` wird veröffentlicht, aber
nicht ausgewertet: Es ist der Haken in der Web-Seitenleiste, und ein JMAP-Client, der danach
filterte, verbärge auf dem Telefon, was seine Nutzerin im Browser verstecken wollte.

### Kalender werden aus genau einem Konto bedient

Ein `Calendar` gehört der **Nutzerin** — nutzerbezogen wie `Label` und `MailRule`, wobei ein
Mail-Konto stets nur ein optionaler Eigentümer für den einen Kalender ist, in den die
Terminextraktion ablegt. Es gibt keine kontobezogene Identität für einen Kalender, wie
`LabelBinding` sie einem Label gibt.

Die Liste aus jedem Konto zu bedienen veröffentlichte einen Kalender unter drei accountIds. Ein
Client schlüsselt jedes Objekt über `(accountId, id)`, er zeichnete den Kalender also dreimal,
und ein darin angelegter Termin sähe so aus, als existierte er dreifach — ohne jede Möglichkeit
für den Client zu erkennen, dass die drei eines sind.

Deshalb benennt `App\Jmap\Account\CalendarAccountResolver` genau eines: das Konto, das die
Session ohnehin in `primaryAccounts` aufführt, nämlich das erste der Nutzerin. Jedes andere wird
mit `accountNotSupportedByMethod` abgelehnt, RFC 8620s Fehler für genau diesen Fall. Aufgelöst
wird zuerst über den `AccountResolver`, damit eine unbekannte oder fremde accountId weiterhin
`accountNotFound` ergibt — einer Fremden zu sagen, eine ID, die ihr nicht gehört, sei lediglich
*hier nicht unterstützt*, bestätigte, dass es die ID gibt.

Eine Nutzerin ohne Mail-Konto hat keinen Ort, aus dem sich Kalender bedienen ließen, und die
Session weist keine aus. Das ist ein realer Zustand — man kann sein letztes Konto löschen und
einen Kalender behalten —, und er verfällt zu „diese Installation hat kein Kalenderkonto" statt
zu einem Fehler auf Kosten irgendeines anderen Kontos.

### Blob-IDs haben einen Namensraum

plMail hat zwei unabhängige Quellen herunterladbarer Bytes — eine ganze `Message` (ihre
RFC822-Quelle) und einen einzelnen `MessagePart` (einen Anhang) — in verschiedenen Tabellen mit
unabhängigen Autoincrement-IDs, dazu vorgemerkte Uploads. Eine nackte ID auszugeben macht den
Blob `239049` mehrdeutig, und der Download-Endpunkt kann ihn nicht auflösen. `App\Jmap\Blob\BlobId`
stellt `m-`, `p-` oder `u-` voran, was für Clients opak bleibt — RFC 8620 §1.6.3 verlangt das
ohnehin —, und `parse()` liefert für alles Fehlgeformte `null`, damit Aufrufer mit `notFound`
antworten, statt der Eingabe zu vertrauen.

## State

`App\Jmap\State\ChangeLog` ist ein rein anhängendes Log, und sein
Autoincrement-Primärschlüssel **ist** das State-Token. Der State eines Clients für ein Paar
`(accountId, objectType)` ist die höchste dafür aufgezeichnete Sequenz; `/changes` liefert Zeilen
mit `sequence > sinceState`, gedeckelt auf `StateManager::DEFAULT_MAX_CHANGES` (256, bewusst
bescheiden für Mobilgeräte), wobei `hasMoreChanges` gesetzt wird, wenn es mehr gibt.

`StateManager::changesSince()` weist ein leeres `sinceState` mit `invalidArguments` zurück und
ein nicht numerisches mit **`cannotCalculateChanges`**, was die richtige Art des Verfallens ist:
Einem Client, der ein Token hält, das dieser Server nicht mehr deuten kann, wird gesagt, er möge
neu synchronisieren, statt ihm eine falsche Antwort zu geben.

Alles, was Änderungszeilen für Mail schreibt, geht durch
`App\Service\Mail\MailChangeRecorder`; siehe [Mail-Ingest](mail-ingest.md) dazu, warum diese
Schicht über dem `StateManager` existiert und warum `record()` bewusst nicht flusht.

### Warum Kalender keine Änderungen berechnen können

`App\Jmap\Calendar\CalendarState::FIXED` ist buchstäblich die Zeichenkette `'fixed'`,
zurückgegeben von jeder Kalendermethode, und der Docblock der Klasse führt die Begründung
vollständig aus.

Das Token für Mail ist vertrauenswürdig, **weil das Log vollständig ist**: Jeder Pfad, der eine
für JMAP sichtbare Mail-Eigenschaft ändert, ruft `StateManager::record*` auf, und zwar über den
`MailChangeRecorder`, den es genau deshalb gibt, damit nicht fünf Aufrufer jeweils dieselben zwei
Dinge vergessen können.

Für Kalender gibt es keinen solchen Recorder, und aus `src/Jmap/` heraus ließe sich auch keiner
geben. Ein Termin ändert sich an vier Stellen — die Sync-Engine, die einen entfernten Kalender
holt, die Extraktion, die eine Nachricht liest, der Web-Editor und `CalendarEvent/set` —, und nur
die letzte liegt in diesem Verzeichnis. **Ein Log, das ein Viertel der Schreibvorgänge
aufzeichnete, wäre schlimmer als keines**: Das Token stünde still, während ein Pull den ganzen
Tag ersetzt, und ein Client, der States vergleicht, schlösse daraus, dass sich nichts geändert
hat, und holte nie neu. Ein unvollständiges Log ist keine schwächere Fassung eines vollständigen,
es ist eine Lüge mit einer Zahl daran.

Also ist der State fest, und die Methoden sagen das auf die einzige andere Weise, die das
Protokoll anbietet: `canCalculateChanges` ist `false`, es gibt kein `Calendar/changes` und kein
`CalendarEvent/changes`, und ein Client führt seine Abfrage erneut aus — was `Email/query` ohnehin
schon verlangt und spezifikationskonform ist.

Der Wert ist bewusst **keine Zahl**. Sollten Kalender später doch am Änderungslog teilnehmen —
ein `CalendarChangeRecorder` neben dem `MailChangeRecorder`, aufgerufen von allen vier Writern,
dazu passende `JmapObjectType`-Fälle —, dann werden Token zu Sequenzen, und ein Client, der noch
`'fixed'` hält, fällt bei der `ctype_digit`-Prüfung in `changesSince()` durch und wird zum
Neusynchronisieren aufgefordert. Das ist die richtige Art des Verfallens, und sie ist umsonst.

## Abfragen

`Email/query` kompiliert Filter über `App\Jmap\Query\EmailFilterCompiler`, der **eine unbekannte
Bedingung namentlich zurückweist, statt sie zu ignorieren**. Ein stillschweigend fallen
gelassener Filter liefert zu viel, und der Client hat keine Möglichkeit, das zu bemerken.

`CalendarEvent/query` führt `CalendarEventOccurrenceRepository::findInRange()` aus — dieselbe
`tsrange &&`-Überlappung gegen den GiST-Index, die jede Kalenderansicht macht, statt einer
zweiten, eigens für JMAP geschriebenen Abfrage. Zwei bewusste Verweigerungen:

**Das Zeitfenster ist Pflicht.** Eine unbegrenzte Abfrage lässt sich aus diesem Index gar nicht
beantworten: Termininstanzen sind nur bis zum Horizont materialisiert, „alles" käme also
vollständig aussehend zurück und hörte doch zwei Jahre in der Zukunft auf, und ein Client kann
eine Kürzung, die niemand gemeldet hat, nicht erkennen. Die Verweigerung ist die einzige Antwort,
die nicht lügt.

**Kein `FilterOperator`.** AND/OR/NOT über eine Bereichsüberlappung müsste außerhalb des Index
ausgewertet werden, und das ist genau der sequenzielle Scan, den der Index vermeiden soll; und
ein ODER zweier Zeitfenster sind zwei Abfragen, die ein Client stellen kann. Es wird namentlich
zurückgewiesen, aus demselben Grund, aus dem der `EmailFilterCompiler` eine unbekannte Bedingung
zurückweist.

## Schreiben

### `CalendarEvent/set`

`App\Jmap\Calendar\JmapEventWriter` kümmert sich um das Protokoll — ein JSCalendar-Objekt von der
Leitung lesen, zurückweisen, was sich nicht getreu speichern lässt, und einen JMAP-Patch auf die
Parameterliste des Writers abbilden — und **rührt keine Spalte an**. Was ein Termin *ist*, gehört
dem `CalendarEventWriter`, den sich der Web-Editor und die Sync-Engine teilen; ein JMAP-Client,
der einen Titel ohne `jscalendar['title']` setzte, brächte einen Termin hervor, der in der App
richtig aussähe und beim Export leer wäre.

**Unbekannte Eigenschaften werden zurückgewiesen, nicht verworfen**, wobei `invalidProperties`
sie benennt. Ein Client, dessen `participants` stillschweigend verworfen würden, glaubte, jemanden
eingeladen zu haben, ohne je das Gegenteil erfahren zu können. Die ausgelassenen Eigenschaften
und ihre Gründe:

| Eigenschaft | Warum nicht |
|---|---|
| `participants` | eine Zu- oder Absage wird über den Einladungsablauf beantwortet, der eine iTIP-Antwort verschickt; hier eine Teilnehmerliste entgegenzunehmen hieße, Antworten festzuhalten, von denen niemand erfährt |
| `privacy` | es gibt keinen Writer-Parameter dafür, und die Spalte direkt zu schreiben ist genau das, was diese Klasse nicht tun darf |
| `alerts`, `links` | es gibt nichts, woraus sie projiziert werden könnten — der Writer baut das kanonische Objekt bei jedem Schreibvorgang aus den Spalten neu auf, sie überlebten also ein Speichern und verschwänden beim nächsten |

Zeiten werden als striktes LocalDateTime geparst (RFC 8984 §4.1.2): kein Offset, kein
angehängtes `Z`. `2026-06-02T09:00:00Z` anzunehmen sieht nach Entgegenkommen aus und ist keines —
es sagt UTC, die Zone daneben sagt Europe/Berlin, und zu raten, was der Client gemeint hat,
verschiebt eine Besprechung stillschweigend um Stunden.

### `Thread/set` — eine Erweiterung

Der Thread aus RFC 8621 ist schreibgeschützt, denn eine Konversation ist aus ihren Emails
abgeleitet, und es gibt nichts daran, was sich ändern ließe. plMails Variante weicht in genau
einem Punkt ab: Eine Konversation lässt sich zurückstellen, und dieser Zustand gehört der
Konversation und nicht irgendeiner Nachricht darin.

Sie ist bewusst eng gehalten — `create` und `destroy` werden rundheraus abgelehnt, denn
Konversationen entstehen, wenn Mail eintrifft, und vergehen, wenn ihre letzte Nachricht
verschwindet, und ein Client, der eine herbeizaubern könnte, beschriebe etwas, für das der Rest
des Systems keine Bedeutung hat. `update` nimmt eine Eigenschaft entgegen.

Das Setzen geht über `App\Service\Mail\ThreadSnoozeService`, denselben Dienst, den auch die
Web-Oberfläche verwendet, damit Zurückstellen dasselbe bedeutet, welcher Client es auch gesetzt
hat: Die Konversation verlässt den Posteingang, erhält das Label „Zurückgestellt", und diese
Änderung pflanzt sich nach außen zum Anbieter fort. Der eine bewusste Unterschied zwischen den
Aufrufern ist an beiden Enden benannt — ein Formular-Post bekommt bei einem nicht lesbaren Datum
den Rückfall „in 1 Tag", wo `ThreadSetMethod::snoozeDate()` es zurückweist.

Standard-Clients kennen diese Methode weder, noch brauchen sie sie; `Thread/get` antwortet
weiterhin mit den zwei Eigenschaften der Spezifikation plus einer, die sie ignorieren werden.

### `EmailSubmission/set`

Delegiert an dieselbe Kette aus `SendMessageMessage`, `SendMessageHandler` und
`MessageSendService`, die auch der Senden-Knopf des Web-Editors verwendet. Dieser Dienst führt
den Übergang vom Entwurf zur gesendeten Nachricht bereits durch — fügt „Gesendet" hinzu, entfernt
„Entwürfe", löscht das `\Draft`-Flag, setzt `sentAt`, richtet das Postfach neu aus —, sodass auch
ein Client, der `onSuccessUpdateEmail` weglässt, am Ende richtig dasteht.

**Eine Submission hat keine eigene Tabelle: Ihre ID ist die Email-ID.** Das genügt dem
Objektmodell, weil plMail jeden Entwurf höchstens einmal sendet (`MessageSendService` tut nichts
mehr, sobald `sentAt` gesetzt ist); die Zuordnung bleibt also eineindeutig, und
`EmailSubmission/get` kann aus der `Message` rekonstruieren.

`undoStatus` wird als `pending` gemeldet: Der Versand liegt auf dem Bus in der Warteschlange und
hat, wenn der Aufruf zurückkehrt, tatsächlich noch nicht stattgefunden. Das Rückgängig-Fenster
des Web-Editors wird bewusst **nicht** angewandt — ein JMAP-Client hat darum gebeten, jetzt zu
senden.

## Push

Zwei Mechanismen, und beide werden von derselben geleerten Menge angetrieben.

Der `StateManager` sammelt verschmutzte `(account, type)`-Paare im Speicher, während Änderungen
aufgezeichnet werden, und der `JmapPushSubscriber` leert sie einmal am Ende der Anfrage oder des
Handlers. Die Token werden **nach** dem Flush des Aufrufers gelesen, sodass es die Werte sind,
die ein Client tatsächlich aus `/changes` sehen wird — sie zum Zeitpunkt der Aufzeichnung zu
lesen pushte einen State, den es noch gar nicht gibt. Ein Gmail-Stapel, der fünfzig Nachrichten
importiert, erzeugt daher eine Benachrichtigung und nicht fünfzig.

`App\Jmap\Push\PushDispatcher` macht daraus je abonniertem Gerät einen `StateChange`. Er löst
Konten zuerst zu ihren Eigentümern zurück auf, denn eine Subscription gehört einer Nutzerin,
während Änderungen je Konto aufgezeichnet werden, und filtert jede Subscription auf die
Objekttypen, die sie angefragt hat.

`PushSubscription/set` (RFC 8620 §7.2.2) trägt keine accountId — Subscriptions gelten je
authentifizierter Nutzerin — und **der Verifikations-Handshake ist der springende Punkt**. Beim
Anlegen schickt der Server sofort ein `PushVerification`-Objekt per POST an die vom Client
angegebene URL; der Client liest den Code daraus und schickt ihn per Update zurück, und bis er
das tut, empfängt die Subscription nichts. Ohne das könnte jede Person mit einem Konto die URL
einer Fremden registrieren und plMail dazu bringen, bei jeder Zustandsänderung dorthin zu posten.
Der Code beweist, dass wer die URL registriert hat, auch lesen kann, was dort ankommt.

`/jmap/eventsource` ist die andere Hälfte, für Clients, die eine Verbindung halten statt eines
Web-Push-Endpunkts; die Session weist ihn mit den Parametern `types`, `closeafter` und `ping`
aus.

## Fallstricke

**Eine Label-ID auszugeben, wo eine Binding-ID gemeint ist, scheitert nicht.** Beide sind ganze
Zahlen aus verschiedenen Tabellen, der Client zeichnet also eine echte Mailbox, die zufällig
dieselbe Zahl trägt. Dieselbe Fehlerform ist der Grund, warum Termininstanz-IDs an genau einer
Stelle in Termin-IDs übersetzt werden und warum es für beides einen Test gibt, der jede
ausgegebene ID durch einen Filter zurückführt.

**Eine Kalendermethode mit der falschen accountId ergibt `accountNotSupportedByMethod` und nicht
`accountNotFound`** — aber erst, nachdem die Eigentümerschaft nachgewiesen ist. Die beiden
Prüfungen zu vertauschen machte aus dem Fehler ein Orakel für IDs.

**`CalendarEvent/get` ist bei 100 gedeckelt, nicht bei 500.** Es löst eine ID nach der anderen
auf, weil `findOneForUser()` auf die Eigentümerin eingrenzt, was den Termin einer anderen Person
von einem nicht existierenden ununterscheidbar macht. Die Session nennt die Grenze, damit ein
Client von `requestTooLarge` nicht überrascht wird.

**Aus `CalendarState` eine Zahl zu machen, ohne einen vollständigen Recorder zu haben, ist genau
der Fehlschlag, gegen den es geschrieben wurde.** Ein Token, das sich für ein Viertel der
Schreibvorgänge bewegt, ist schlimmer als eines, das sich nie bewegt, denn ein Client wird ihm
glauben.

**`jmap_change_log` hat ein `pruneOlderThan()` und keinen Aufrufer.** Der Primärschlüssel ist
eine 32-Bit-Ganzzahl, das Log wächst also über die Lebensdauer der Installation — eine Zeile je
Nachricht je Synchronisierung, plus eine je berührter Konversation. Der Kommentar an der Entität
benennt die zwei Auswege und ihre Folgen: Auf `bigint` zu wechseln bedeutet, die Eigenschaft auf
`?string` umzutypen (Doctrine hydratisiert bigint als Zeichenkette), und einen Pruner zu ergänzen
bedeutet, dass Clients unterhalb der neuen Untergrenze `cannotCalculateChanges` bekommen und neu
synchronisieren.

**Eine neue, für JMAP sichtbare Mutation, die den `MailChangeRecorder` umgeht, ist für Clients
unsichtbar**, bis irgendetwas anderes dieselbe Konversation anfasst. Es gibt keinen Test, dem
eine fehlende Ankündigung auffiele; genau darum gibt es den Recorder überhaupt, statt dass jede
Aufrufstelle den `StateManager` zweimal aufruft.

**Leere JMAP-Maps müssen als `{}` serialisiert werden und nicht als `[]`.** Der `SessionBuilder`
und jede `/set`-Methode setzen für ein leeres Array ausdrücklich ein `stdClass` ein, denn PHPs
JSON-Encoder unterscheidet die beiden nicht, und ein Client, der ein Array liest, wo ein Objekt
spezifiziert ist, scheitert schon an der Session selbst.
