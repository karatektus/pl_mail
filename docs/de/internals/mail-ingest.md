<!-- translated-from: internals/mail-ingest.md sha1:dcb2b781f03c1702974cc382857086d18024e21c -->
# Mail-Ingest

Vom Anbieter in die Datenbank: wie aus Bytes eine Zeile `Message` wird, was mit ihr geschieht,
sobald es sie gibt, und warum jedes ordnende Konzept in plMail ein Label ist. Wie das Ergebnis
für eine Nutzerin aussieht, steht unter [Mail](../features/mail.md); wie die Konten verbunden
werden, unter [Konten und Aliase](../features/accounts.md).

## Drei Syncer hinter einem Interface

`App\Domain\Interface\AccountSyncerInterface` hat zwei Methoden, `supports(Account)` und
`sync(Account): list<int>`, und der Rückgabewert ist die Liste der Postfach-Ids, die berührt
wurden, damit der Aufrufer Folgearbeit pro Postfach anstoßen kann.
`SyncAccountMessageHandler` nimmt den getaggten Iterator, greift den ersten Syncer, der das
Konto für sich beansprucht, und weiß von keinem Anbieter irgendetwas.

| Syncer | Beansprucht | Form des Syncs |
|---|---|---|
| `App\Service\Imap\ImapAccountSyncer` | alles, was weder Gmail noch Microsoft ist | zuerst Ordnersuche, dann Nachrichten-Sync pro Postfach |
| `App\Service\Gmail\GmailAccountSyncer` | `Account::isGmail()` | zuerst die Labelliste, dann `historyId`-Delta plus wiederaufnehmbarer Backfill |
| `App\Service\Graph\GraphAccountSyncer` | `Account::isMicrosoft()` | Ordnersuche, dann eine `delta`-Kette pro Ordner |

Die drei unterscheiden sich in mehr als im Protokoll, und in den Unterschieden wohnen die
Fehler.

**IMAP synchronisiert Struktur und Inhalt über einen einzigen Einstiegspunkt.**
`ImapAccountSyncer` lässt `MailboxSyncer` vor `MessageSyncer` laufen, damit Ordner, die ein
anderer Client angelegt hat, erst auftauchen und ihre Labelketten bekommen, bevor eine
Nachricht versucht, sich in eine davon aufzulösen. `MessageSyncer` holt in Stapeln zu 50 und
reicht die rohen RFC822-Bytes weiter, weil webklex sie nach dem Parsen behält — deshalb ist
IMAP der einzige Weg, auf dem das Original gratis gespeichert wird.

**Gmail hat überhaupt keine `Mailbox`-Zeilen.** `GmailApiSyncer` plant die Arbeit direkt auf dem
Konto und fächert `SyncGmailMessageBatchMessage`-Jobs zu je 100 Ids auf, während er mit der
Seitengröße 500 der API auflistet. Die `gmailHistoryId` des Kontos wird als Momentaufnahme
festgehalten, *bevor* irgendeine Nachricht geholt wird, und sie bedeutet ausschließlich „hier
setzt der inkrementelle Sync wieder an" — niemals „der Rückstand ist drin". Letzteres ist der
eigene Zustand von `backfill()`, mit eigenem `BACKFILL_COOLDOWN` von einer Stunde und
`BACKFILL_MAX_ATTEMPTS` von 24. Eine vorhandene `historyId` als „vollständig synchronisiert" zu
lesen ist genau das, was ein Konto früher bei dem stranden ließ, was der erste Lauf zufällig
geholt hatte.

**Graph hat keinen Cursor auf Kontoebene.** Ein Gegenstück zur einen `historyId` gibt es nicht,
also liegt der Delta-Zustand pro Ordner vor, gespeichert als Abbildung `folderId => deltaLink`
auf dem `Account`. Eine Delta-Abfrage ohne gespeicherten Link zählt den Ordner auf und gibt
einen zurück; derselbe Aufruf mit Link liefert nur noch Änderungen. Für Graph ist das ein
Vorgang. Für plMail sind es zwei: einem Ordner mit Link zu folgen ist Sache des Abgleichs, und
die Aufzählung, die den Link erst einbringt, ist Sache des Imports — siehe „Ein Erstimport ist
kein Abgleich" weiter unten.

Die scharfe Kante bei Graph sind die Ordnerbewegungen. Eine Nachricht aus einem Ordner
herauszuschieben erscheint im Delta des Quellordners als `@removed` und im Delta des Zielordners
als Zugang, und die Annahme war, dass beides sich ausgleicht. Das tut es nur, wenn beide
dieselbe Id tragen — und unveränderliche Ids sind genau das, was ein privates
outlook.com-Postfach nicht verlässlich hergibt. Die abtrennende Hälfte traf regelmäßig auf
nichts, und alte Ortslabels häuften sich an: Gelöschte Entwürfe lagen gleichzeitig unter
Entwürfe und im Papierkorb. Also ist das Anhängen **von sich aus exklusiv** und wartet nicht auf
seinen Partner: Der Ordner, in dem eine Nachricht zuletzt gesehen wurde, ist der Ordner, in dem
sie ist — was Exchange ohnehin meint.

Die Batch-Handler entdoppeln in PHP, bevor sie einfügen, doch diese Prüfung ist eine Lesung auf
veraltetem Stand. Was tatsächlich hält, wenn Stapel sich über Läufe oder Retries hinweg
überlappen, ist `uniq_message_gmail_id_account` und sein Graph-Gegenstück auf `message`, mit der
Anbieter-Id vorn in der Spaltenliste, damit der Index auch die reinen Id-Nachschläge bedient.

### Ein erster Import ist kein Abgleich

Ein Abgleich holte früher neben der neuen Mail auch die Vorgeschichte eines Kontos: ein einziger
Job, der jeden IMAP-Ordner von der ältesten Nachricht bis zur neuesten las oder jede
Gmail-Nachrichten-Id auflistete, bevor er eine abrief. Bei einem Konto mit zwanzigtausend
Nachrichten hielt dieser Job die Warteschlange `ingest` so lange besetzt, wie er eben brauchte,
zeigte die meiste Zeit einen leeren Posteingang und stellte die neue Mail aller anderen Konten
dahinter an.

Beides ist jetzt getrennt. Ein Abgleich sorgt dafür, dass neue Mail gesehen wird, und hört auf;
die Vorgeschichte gehört `App\Service\Mail\MailImporter`, auf der Warteschlange
`ingest_backlog` mit eigenem Worker.

- **Eine Nachricht ist eine Seite.** `ImportMailMessage` trägt eine Konto-Id und sonst nichts;
  ihr Handler holt eine Seite und stößt die nächste an. Nirgends darin steckt ein langer Job,
  also wechseln sich die Seiten mehrerer Konten in der Warteschlange ab, und jedes hat einen
  brauchbaren Posteingang, bevor irgendeines sein Archiv hat.
- **IMAP liest jeden Ordner von oben nach unten.** Beim ersten Kontakt setzt `ImapImportPlan`
  `lastSeenUid` an das obere Ende des Ordners und `importFloorUid` direkt darüber. Neue Mail
  ist alles oberhalb des ersten; Vorgeschichte ist alles unterhalb des zweiten, und
  `ImapMailboxImporter` arbeitet sich fünfzig Nachrichten pro Aufruf nach unten. Eine Seite
  entsteht, indem der Server gefragt wird, welche UIDs unterhalb der Marke existieren, und die
  schon gespeicherten herausgenommen werden — eine Nachricht, die da ist, wird nie zweimal
  abgerufen.
- **Ordner kommen in der Reihenfolge, die jemand sich wünschen würde:** Posteingang, Gesendet,
  Entwürfe, eigene Ordner, das Archiv und zuletzt Spam und Papierkorb.
  `MailboxSpecialUse::importRank()` ist die Regel.
- **Gmail fragt zuerst namentlich nach dem Posteingang,** dann blättert es die Auflistung des
  ganzen Postfachs Anfrage für Anfrage durch; das Seiten-Token liegt zwischen den Jobs auf dem
  Konto.
- **Microsoft liest die erste Delta-Runde jedes Ordners seitenweise.** Ein Ordner ohne
  Delta-Link wird auf dem Konto eingeplant (`Account::$graphImport`), statt vom Abgleich
  aufgezählt zu werden, und `GraphApiSyncer::importPage()` geht diese Runde in Schritten von
  fünfzig Nachrichten durch, die neuesten zuerst, in derselben Ordnerreihenfolge nach
  `LabelRole::importRank()`. Die Seite, die die Runde beendet, bringt den Delta-Link des
  Ordners mit, und ab da folgt ihm der Abgleich. Bis dahin fragt der Abgleich einen solchen
  Ordner nur nach dem, was seit der Einplanung eingegangen ist. Ein abgelaufener Delta-Link
  gibt seinen Ordner an den Import zurück, statt im Abgleich neu aufgezählt zu werden.
- **Eine Nachricht, die sich nicht speichern lässt, kostet nur sich selbst.** Die Marke bleibt,
  wo sie ist, und die Seite wird erneut angefragt; nach fünf Versuchen rückt die Marke an ihr
  vorbei, und der Ordner geht weiter.

Jeder gewöhnliche Abgleich endet in `MailImporter::ensureRunning()`, das einen Import startet,
der Arbeit hat und seit fünf Minuten still ist. Das startet den Import eines neuen Kontos, und
es startet einen neu, dessen Kette von Nachrichten gerissen ist.

„Importiert dieses Konto noch" ist `InitialImportState`, und für IMAP wird es an
`importFloorUid` der Ordner abgelesen: `syncedAt` eines Ordners sagt das nicht mehr, weil sein
erster Abgleich jetzt sofort endet und die Vorgeschichte noch aussteht. Ein Microsoft-Konto
importiert, solange `Account::$graphImport` einen Ordner aufführt.

Eines ändert ein Microsoft-Import am Abgleich: Eine Nachricht, die aus einem Ordner entfernt
wurde, ist nur dann gelöscht, wenn sie in keinem anderen angekommen ist, und ein Ordner, der
noch gelesen wird, kann das nicht sagen. Solche Entfernungen bleiben auf dem Konto
(`Account::$graphRemovals`) und werden vom ersten Abgleich beurteilt, bei dem jeder Ordner
geantwortet hat.

## Die Post-Ingest-Pipeline

Sobald ein Stapel `Message`-Zeilen existiert und geflusht ist, läuft
`App\Service\Mail\PostIngestPipeline` als gemeinsamer Durchgang darüber. Die drei Sync-Pfade
trugen früher je eine eigene Kopie davon; sie stimmten überein, und genau das war das Problem —
die Reihenfolge ist subtil genug, dass drei Kopien drei Gelegenheiten zum Auseinanderlaufen
sind, und alles, was auf neue Mail reagieren wollte, musste in alle drei verdrahtet werden.

Die Vorbedingung steht im Docblock und ist nicht verhandelbar: **Der Aufrufer hat jede Nachricht
bereits persistiert und geflusht.** Ids müssen existieren, bevor die Konversationsbildung sie
abfragt, und `MailRuleEngine` matcht in SQL gegen `search_vector`, eine generierte Spalte — eine
Nachricht, die die Datenbank nicht erreicht hat, ist für die eigenen Regeln der Nutzerin
unsichtbar.

Pro Nachricht, in dieser Reihenfolge:

1. `MailBodySanitizer::sanitize()` füllt `bodyHtmlSafe`. Das unbereinigte `bodyHtml` bleibt mit
   Absicht erhalten — siehe unten.
2. `RawMessageResolver::store()`, aber nur, wenn der Aufrufer Bytes mitgeliefert hat. Gmail und
   Graph übergeben null und holen bei erster Verwendung nach, weil es für sie ein zweiter
   API-Aufruf ist.
3. `MailChangeRecorder::emailChanged(..., created: true, thread: null)` — das JMAP-Create, und
   zwar **ohne Konversation**, weil `assignThread()` noch nicht gelaufen ist und eine
   Konversation, die dort entsteht, bis zum nächsten Flush keine Id hat. Sie vorher zu lesen
   veröffentlichte die Id 0 an jeden verbundenen Client.
4. `MessageCategorizer::categorize()`.
5. `MessageThreader::assignThread()`, in ein `try` gehüllt — ein Fehlschlag bei der
   Konversationsbildung wird an der Nachricht protokolliert, darf aber nie den Stapel kosten.

Dann, einmal für den ganzen Stapel: `MailRuleEngine::applyToBatch()` (eine Abfrage pro Regel,
nach der Konversationsbildung, damit Archivier- und Papierkorb-Aktionen die Konversation jeder
Nachricht erreichen), ein Flush, ein zweiter Durchgang, der Konversations-Ids pro Konto für
`MailChangeRecorder::threadsTouched()` einsammelt, und ein zweiter Flush für die Zeilen des
Änderungsprotokolls.

Zurück kommt `PostIngestResult` — Nachrichten in Ingest-Reihenfolge, besitzende Konten nach Id
und Konversations-Ids nach besitzendem Konto —, weil die drei Aufrufer nicht gleich zu Ende
kommen: IMAP veröffentlicht sein Mercure-Update und stößt die Kontakt-Ernte eine Ebene höher in
`SyncImapMailboxMessageHandler` an, während Gmail und Graph beides direkt erledigen.

### Steps: der Erweiterungspunkt

`App\Domain\Interface\PostIngestStepInterface` wird automatisch mit `app.post_ingest_step`
getaggt und hat eine Methode, `afterCommit(PostIngestResult)`. Drei Regeln erzwingt die
Pipeline:

**Es gibt genau einen Haken, und er läuft nach dem letzten Flush.** Ein Step sieht nie einen
halbfertigen Stapel. Ein Haken innerhalb der Schleife ließe einen Step eine Nachricht ändern,
die die Regel-Engine danach aus veraltetem Datenbankstand bewertet — und Mail landete an
Stellen, die die Regeln der Nutzerin nicht erklären.

**Steps stoßen an, sie arbeiten nicht.** `afterCommit()` läuft auf dem Worker, der eine
IMAP-Verbindung oder ein Graph-Rate-Limit-Budget hält; ein Parse, ein HTTP-Aufruf oder eine
Bilddekodierung gehören in einen eigenen Handler — und ebenso Arbeit, die bloß schnell ist; so
kamen die Insight- und Vorschlags-Steps dazu, inline zu laufen, und so hörten sie damit auf.
`HarvestContactsStep` ist die eine verbliebene Ausnahme: Er liest Zeilen, die schon im Speicher
liegen, und speist die Kategorisierung.
`App\Service\Mail\PostIngest\ExtractEventsStep` ist die Form zum Abschauen — er sammelt Ids ein
und schickt `ExtractEventsMessage` ab, sonst nichts.

**Steps können keinen Sync scheitern lassen.** `notifySteps()` fängt und protokolliert, was ein
Step wirft, und macht mit dem nächsten weiter. Ein kaputter Step kostet das, was er tun wollte,
niemals die Mail.

Zwei Zweige laufen mit Absicht **nicht** durch die Pipeline: der Gmailify-Anspruch von IMAP und
`SyncGmailMessageBatchHandler::enrichExisting()`. Beide zeigen eine Zeile neu aus, die schon
einmal hindurchgegangen ist — ein zweiter Lauf würde also ein Create für eine Id nachtragen, die
JMAP-Clients längst halten, und Regeln erneut über Mail laufen lassen, die die Nutzerin
inzwischen von Hand eingeordnet haben kann.

## Was auf eine Nachricht folgt, und wo es läuft

`ingest` ist zum Abholen von Mail da und für sonst nichts. Alles, was ein Step einreiht, geht
auf eine von drei anderen Warteschlangen — und welche, hängt von der Mail ab, nicht von der Art
der Arbeit:

| Warteschlange | Prozess | Was darauf liegt |
|---|---|---|
| `enrich_live` | `worker-live` | Folgearbeit für Mail, die gerade eben angekommen ist |
| `enrich` | `worker-enrich` | Folgearbeit für aktuelle Mail aus einem Import; Suchindizierung |
| `enrich_backlog` | `worker-enrich`, nach `enrich` | Alte Mail, die der Assistent nach dem Import einsortiert |

Früher war das eine Warteschlange. Ein erster Import legte pro Stapel einen Modellaufruf, eine
Terminerkennung und einen Bestätigungs-Scan auf `ingest`, neben die Abgleiche — und der eine
Worker, der Mail abholt, fragte stundenlang ein Modell nach Newslettern von 2019, während neue
Mail dahinter wartete.

**Aktuell.** `App\Service\Mail\PostIngest\RecentMailPolicy` entscheidet das, nach dem Datum der
Mail selbst — `receivedAt`, dann `sentAt` — gegen ein Fenster von 30 Tagen
(`ENRICH_RECENT_DAYS`). Jeder Step reiht nur für aktuelle Mail Arbeit ein. Ältere Mail bekommt,
was die Pipeline selbst tut — eine Zeile, eine Konversation, die Kategorie der Regelkaskade, die
Regeln der Nutzerin, ihre Kontakte — und keinen Job. Die Backfill-Aufgaben (`app:backfill
insights`, `event-extraction`, …) sind der Weg, Vergangenes zu lesen, wenn jemand es will.

**Live.** `App\Service\Mail\InitialImportState` sagt, ob ein Konto sein Postfach noch zum ersten
Mal hereinholt: Gmail über `needsBackfill()`, Graph darüber, ob es einen Delta-Link gibt und kein
Ordner mehr zu lesen ist, IMAP darüber, ob kein zum Abgleich aktivierter Ordner noch
Vorgeschichte offen hat. Die Pipeline
fragt einmal pro Stapel und trägt die Antwort in `PostIngestResult::$live`; `EnrichmentRouter`
macht daraus einen `TransportNamesStamp`. Mail eines fertigen Kontos geht auf `enrich_live`,
dessen Worker nie Import-Arbeit vor sich hat. Im Zweifel heißt es „importiert noch" — ein
Gmail-Backfill gilt bis zu eine Stunde zu spät als abgeschlossen —, weil der umgekehrte Fehler
ein ganzes Postfach als live einreihen würde.

**Alte Mail und der Assistent.** `App\Service\Ai\ClassificationCatchUp`, alle Viertelstunde von
`app:ai:classify-backlog` gestartet, reiht die neueste Mail, nach der der Assistent noch nie
gefragt wurde, auf `enrich_backlog` ein — aber erst, wenn jedes Konto dieser Person fertig
importiert ist, und nur, solange die Warteschlange leer ist; das ersetzt einen eigenen Zustand.
`Message::$aiCategorisedAt` ist die Fortschrittsmarke.

**Ein Modell-Host.** Getrennte Worker enden trotzdem an einem Host, der eine Anfrage nach der
anderen beantwortet. `App\Service\Ai\LiveMailPriority` lässt die Massen-Klassifizierung vor jedem
Aufruf auf `enrich_live` schauen und zurücktreten, solange dort etwas fällig ist — Live-Mail
wartet so höchstens hinter einem laufenden Aufruf.

### Mail für den Assistenten zurückhalten

Für jemanden, der vom Assistenten sortieren lässt, ist die Kategorie der Regeln eine Vermutung,
die gleich ersetzt wird. Sofort angezeigt, erschien die Mail unter „Allgemein" und wanderte
Sekunden später zu „Werbung". Wo das Urteil die Antwort also noch ändern kann, wird die
Nachricht **zurückgehalten**: `Message::$categoryHeldAt` wird vor der Konversationsbildung
gesetzt, ihre neue Konversation bekommt keine Kategorie — was jede Tab-Abfrage und
`EmailFilterCompiler::threadCategory()` schon als „in keinem Tab" lesen —, und eine Antwort in
eine bestehende Konversation lässt diese, wo sie ist.

`App\Service\Mail\ClassificationHold` ist die Entscheidung, und sie ist eine Liste von Gründen,
nicht zurückzuhalten: Das Zurückhalten ist unter Administration → KI abgeschaltet; die Mail
gehört zu einem Import; sie ist alt oder die eigene gesendete Kopie des Kontos; oder
`MessageCategorizer::verdictCouldDecide()` sagt, dass die Antwort schon feststeht — wer nach
Regeln sortiert, ein Gmail-Label, eine bekannte Korrespondenzadresse.

Drei Dinge beenden das Zurückhalten, und `App\Service\Mail\HeldMailReleaser` ist das eine Ende,
das sie teilen:

1. `ClassifyMailHandler`, wenn das Modell antwortet.
2. `ReleaseHeldMailMessage`, im Moment des Zurückhaltens mit einer Verzögerung von
   `AiSettings::$holdMaxSeconds` (Vorgabe 60, 2–300) abgeschickt. Meist kommt sie an und findet
   nichts Zurückgehaltenes mehr. Sie hat eine eigene Warteschlange, `release`, und einen Worker,
   der sonst nichts tut (`worker-release`): Der Live-Worker steckt womöglich gerade in genau dem
   Aufruf, der zu lange dauert, und die Freigabe darf nicht auf ihn warten — und auch nicht auf
   einen Versand.
3. `app:mail:release-held`, jede Minute, falls keiner der beiden Jobs lief.

Dass das Warten endet, beendet nicht die Frage. Eine Freigabe ohne Urteil ordnet die Mail unter
der Kategorie der Regeln ein, die beim Ingest genau dafür geschrieben wurde; der Modellaufruf
läuft weiter, und wenn seine Antwort eintrifft, verschiebt sie die Mail und sagt es den offenen
Seiten. Nach der Nachricht wird einmal gefragt.

`category_held_at` und `category_released_at` bleiben erhalten, und ihre Differenz ist die Zahl,
die Administration → KI neben dem Schalter zeigt — Median, 95. Perzentil und wie viele
Nachrichten angezeigt wurden, bevor das Modell geantwortet hatte.

## Konversationsbildung

`App\Service\Imap\MessageThreader::assignThread()` ist eine Kaskade aus drei Stufen, und jede
existiert, weil die darunter nicht gut genug ist.

**1. Die Konversations-Id des Anbieters selbst**, aus `Message::$providerThreadKey`. Gmail und
Graph haben die Konversation bereits so gruppiert, wie die Nutzerin sie in ihrem Web-Client
sieht, und lokal Hergeleitetes kann das nicht schlagen.

Die Falle hier ist die Stapelverarbeitung. Das Repository sieht nur, was geflusht ist, und ein
Sync-Stapel ordnet jede Nachricht zu, die er gebaut hat, bevor er am Ende einmal flusht — zwei
Nachrichten einer Gmail-Konversation im selben Stapel verfehlten also beide den Nachschlag,
legten je eine Konversation an, und das zweite INSERT lief in
`uniq_message_thread_provider_key_account` und riss den ganzen Stapel mit. `providerThread()`
hält deshalb eine Abbildung im Speicher mit dem Schlüssel `accountId|providerThreadKey`,
gedeckelt bei `PROVIDER_THREAD_CACHE_LIMIT` (500), und prüft jeden Treffer mit
`EntityManager::contains()` nach, weil der Manager eines Workers zwischen Nachrichten geleert
wird und alles, was er nicht mehr verwaltet, eine tote Referenz ist.

**2. RFC 5322 `References` / `In-Reply-To`.** Ist eine referenzierte Nachricht für dieses Konto
schon synchronisiert, wird ihre Konversation genommen. Wenn nicht, wird trotzdem eine neue
Konversation mit `ThreadingMethod::References` angelegt — die Methode hält fest, was die
Nachricht *anbietet*, nicht ob zufällig ein Treffer zustande kam.

**3. Betreffabgleich, und nur als Rettung.** `SUBJECT_FALLBACK_WINDOW` steht auf `-30 days`:
lang genug für eine träge Konversation, kurz genug, dass ein wiederkehrender Betreff neu
anfängt, statt eine abgestandene Konversation zu verlängern. Er greift **nur** bei Nachrichten
mit einem Antwortpräfix (`REPLY_PREFIX_PATTERN` deckt
`re|fwd|fw|aw|wg|antw|sv|vs|res|rif|tr|doorst` ab, in den Sprachen, in die plMail übersetzt
ist, plus denen, die verbreitete europäische Clients ausgeben), und die Kandidatin muss zudem
eine beteiligte Person teilen. Eine Nachricht, die selbst keine Antwort ist, beginnt immer ihre
eigene Konversation — sonst fällt jede „Ihre Bestellung ist unterwegs"-Benachrichtigung, die je
eintraf, in eine einzige endlose Konversation zusammen.

Konversationsbildung über Gmails eigene `threadId` steht auf der Roadmap und ist nicht erledigt:
Die Id wird auf `Message` mitgeführt, aber Stufe 2 arbeitet für Konten, auf die Stufe 1 nicht
zutrifft, weiterhin über die Message-ID aus dem RFC.

## Kategorisierung

`App\Service\Mail\MessageCategorizer` bestimmt den Posteingangs-Tab **allein aus persistierten
Daten**. Das ist der ganze Entwurf: Dieselbe Logik läuft beim Sync und in
`app:backfill category`, ohne erneutes Abholen und ohne Resync. Header werden roh und
unnormalisiert gespeichert, und jeder Zugriff geht durch einen Helfer, der die gespeicherten
Schlüssel bei Bedarf kleinschreibt — ein zusätzliches Signal braucht deshalb nie einen Resync,
denn der Header steht schon auf der Zeile.

Die Kaskade, der Reihe nach, und die Reihenfolge ist tragend:

1. **Gmails eigene `CATEGORY_*`-Labels gewinnen rundheraus**, sobald `Message::$gmailLabelIds`
   nicht null ist. Gmail hat die Nachricht bereits eingeordnet, und ihm innerhalb eines
   Gmail-Kontos zu widersprechen heißt: ein Tab, der nicht zur Web-Oberfläche passt.
2. **Vorrang für Korrespondenz.** Hat die Nutzerin diesem Absender je geschrieben, ist es
   Allgemein, egal welcher Massenversand-Header dabeisteht.
3. **Foren vor Werbung.** Diskussionslisten tragen ebenfalls `List-Unsubscribe`, Werbung zuerst
   zu prüfen würde also jede Mailingliste als Marketing einsortieren.
4. **Soziale Netzwerke über die Absenderdomain**, aus einer kleinen, signalstarken Liste
   `SOCIAL_DOMAINS`.
5. **Updates**, dann Allgemein als Vorgabe.

`explain()` liefert dieselbe Entscheidung samt der Stufe, die sie getroffen hat, und dem
Signal, auf das sie angesprungen ist — auf Anfrage neu berechnet statt gespeichert. Weil nur
persistierte Daten gelesen werden, kostet die Erklärung einen Durchgang über Header, die
ohnehin im Speicher liegen, und sie kann nicht von einer Spalte abweichen, die eine ältere
Fassung dieser Regeln geschrieben hat. Das ist es, was die „Warum ist das hier?"-Auskunft in der
Nachrichtenansicht rendert.

Die Tabs lesen das nicht. Sie filtern auf `MessageThread::$category`, eine gespeicherte Spalte,
die aus den Nachrichten des Threads nach dem Jüngsten aufgelöst wird — die Live-Erklärung und der
Tab können also auseinanderlaufen, und was sie zusammenhält, ist, wer die Spalte zuletzt
geschrieben hat. Drei Stellen tun das: der Sync über `PostIngestPipeline`, noch vor dem
Threading; `app:backfill category` mit einem `DISTINCT ON` je Konto; und
`SyncGmailMessageBatchHandler::enrichExisting()`, wo eine **Neubeschriftung** durch Gmail
ankommt.

Die letzte wiegt schwerer, als sie klingt. Gmail sortiert nach der Zustellung ein und sortiert
neu ein, sooft du Post zwischen den Tabs verschiebst — `CATEGORY_*` kommt und geht also auf
Zeilen, die plMail seit Monaten hält, und genau die noch einmal zu lesen ist der Zweck des
Relabel-Abrufs. Die Anreicherung überschreibt `gmailLabelIds`, also das einzige Signal, das die
Kaskade auf einer Gmail-Zeile überhaupt befragt, und berechnet die Kategorie deshalb im selben
Atemzug neu und übernimmt sie über `MessageThreader::refreshCategory()` auf den Thread. Ohne
diesen Schritt stand in der Nachrichtenansicht „Werbung — sagt Gmail" über Post, die der
Posteingang bis zum nächsten Backfill unter Allgemein ließ.

## Labels sind der eine Mechanismus

`App\Entity\Label\Label` gehört der **Nutzerin**, nicht einem Konto. Wo ein Label auf
Anbieterseite materialisiert ist, steht in `App\Entity\Label\LabelBinding`, eine Zeile pro
(Label, Konto), die die Gmail-Label-Id, die Graph-Ordner-Id oder die Verknüpfung zum
IMAP-Postfach trägt. Zwei Konten, die einen Ordner namens „Belege" synchronisieren, laufen auf
ein `Label` mit zwei Bindings zusammen, und der vereinigte Posteingang fällt aus dem Modell
heraus, statt beim Rendern durch Zusammenführen über den Namen rekonstruiert zu werden — was
`SidebarCounts` früher tat.

`Mailbox` ist zu reiner IMAP-Sync-Infrastruktur herabgestuft und erreicht sein Label über das
Binding. Eine zweite Verknüpfung auf `Mailbox` würde spiegelbildlich genau die Asymmetrie
wiederherstellen, die die Binding-Tabelle beseitigen soll.

`App\Service\Label\LabelResolver` ist das eine Find-or-Create, und immer in zwei Schritten:
erst das `Label` auf Nutzerebene auflösen, dann sein `LabelBinding` für das gerade
synchronisierte Konto. Beide Sync-Schichten benutzen ihn — `MailboxSyncer` beim Abbilden von
IMAP-Ordnern, `GmailLabelSyncer` beim Aufspalten von Gmails Benennung `Work/Invoices` in eine
Elternkette —, sodass Verschachtelung gleich funktioniert, egal welcher Anbieter sie erzeugt
hat. Zwischengespeichert werden **Ids** von Entities, nie Entities selbst, damit langlaufende
Handler ein `em->clear()` überleben.

### Was eine Operation bedeutet

`App\Service\Mail\ThreadStatusUpdater` besitzt die Bedeutung von Markieren, Archivieren,
Wegwerfen, Labeln und Als-gelesen-Markieren. Jedes davon ist zuerst eine Labeländerung —
die Datenbank ist die Quelle der Wahrheit — und wird danach asynchron von
`App\Service\Label\LabelChangePropagator` weitergegeben. Archivieren ist *das Entfernen des
Posteingang-Labels*. Papierkorb heißt: Papierkorb dazu, Posteingang weg.

Jede Methode dort endet mit einem Flush und protokolliert die JMAP-Änderung zuerst, und diese
Reihenfolge ist der Sinn der Klasse: Eine Änderung aus der Web-Oberfläche, die das Protokoll
überspringt, ist für verbundene JMAP-Clients unsichtbar, bis zufällig etwas anderes die
Konversation berührt — und früher wurden die beiden nur dadurch im Gleichschritt gehalten, dass
jede Controller-Aktion daran dachte, beides aufzurufen.

`ThreadLabelSynchronizer` macht die Labels einer Konversation anschließend zur **Vereinigung**
der Labels ihrer Nachrichten — Gmail-Semantik, bei der eine Konversation unter einem Label
erscheint, sobald irgendeine Nachricht darin dieses Label trägt. Wegen dieser hergeleiteten
Vereinigung liest `EmailMapper` `Message::$labels` und nie `thread_label`: Die Vereinigung zu
lesen würde für jede Nachricht einer Konversation jedes Postfach melden.

Die Abbildung auf den Anbieter, die `LabelChangePropagator` vornimmt, ist die Stelle, an der die
drei Anbieter aufhören, austauschbar zu sein:

- **Gmail** — alles ist eine Labeländerung über `messages.batchModify`.
- **IMAP** — Stern und Gelesen werden auf Flags abgebildet; Archivieren, Papierkorb und Löschen
  auf Verschiebungen. Ein eigenes Label anzuhängen bleibt rein in der Datenbank, denn der
  physische Ordner bleibt unangetastet, solange das Ortslabel steht. Eines *abzunehmen* löst nur
  dann eine physische Verschiebung aus, wenn das abgenommene Label das Ortslabel der Nachricht
  war, denn eine Nachricht muss irgendwo wohnen. Der Ersatzort wird in dieser Reihenfolge
  bestimmt: ein verbliebenes System-Label Papierkorb/Spam mit hinterlegtem Ordner, dann ein
  verbliebenes eigenes Label mit Ordner (das zuletzt angehängte gewinnt), dann — wenn nichts
  Ordnergestütztes übrig ist — das Archiv.
- **Graph** — eine Ordnerverschiebung ersetzt den Ort, statt ihm etwas hinzuzufügen.

Für IMAP-Verschiebungen müssen die Aufrufer die Nachrichten **vor** `flush()` übergeben, damit
`message->mailbox` noch den Quellordner zeigt; der Propagator hält sich die Abbildung
`messageId => sourceMailboxId` fest und zeigt die Nachricht optimistisch schon auf das Ziel um.

### Zurückstellen ist Archivieren mit Wecker, ausgedrückt in Labels

`App\Service\Mail\ThreadSnoozeService` ist der klarste Fall für die Regel vom einen
Mechanismus. Zurückstellen entfernt das Posteingang-Label und hängt `LabelRole::Snoozed` an; es
schreibt keine Statusspalte und blendet danach Zeilen aus. Zwei Dinge folgen daraus gratis: Die
Invariante „eine Nachricht trägt mindestens ein Label" hält ohne einen zweiten Mechanismus, und
der Stapel der zurückgestellten Konversationen ist über jeden Weg erreichbar, der ohnehin auf
Labels arbeitet — die Seitenleiste, eine Abfrage, der vereinigte Feed.

Die Labeländerung **wandert nach außen**, genau wie beim Archivieren. Das ist der Zweck und kein
Nebeneffekt: Eine zurückgestellte Konversation soll auch in Gmails Posteingang aus dem Weg
sein. Die Weitergabe geschieht, *bevor* die Labels sich lokal bewegen, damit ein IMAP-Job noch
den Quellordner sieht.

`LabelRole::Snoozed` ist die eine Rolle, deren `hasProviderFolder()` false antwortet — sie hat
kein IMAP-Special-Use und kein Gegenstück beim Anbieter, und `MailboxSpecialUse` bildet nie auf
sie ab. Ein Push, der sie als Ordner behandelt, kann nur nach einer Id suchen, die nie jemand
gesetzt hat, und eine Nachricht, die sie trägt, sähe für alles, was Orte zählt, so aus, als sei
sie an zwei Stellen zugleich.

`wake()` markiert die Konversation als ungelesen, und der Lesezustand, den sie hatte, ist
tatsächlich verloren. Das ist ein bewusster Handel: Eine Konversation, die in dem Zustand
zurückkommt, in dem du sie verlassen hast, ist eine, an der du bereits gelernt hast
vorbeizuscrollen.

## Das Änderungsprotokoll, das JMAP liest

Jede Mail-Änderung schreibt eine Zeile nach `jmap_change_log` (`App\Jmap\State\ChangeLog`). Der
autoinkrementierende Primärschlüssel **ist** das Zustandstoken: Der Zustand eines Clients für
ein Paar `(accountId, objectType)` ist die höchste dafür verzeichnete Sequenz, und
`Email/changes` liefert die Zeilen mit `sequence > sinceState`.

`accountId` ist mit Absicht ein Skalar und kein `ManyToOne` — diese Zeilen werden aus
langlaufenden Sync-Handlern geschrieben, und Entity-Referenzen über einen `flush()` hinweg zu
halten ist dort die dokumentierte Fußangel.

`App\Jmap\State\StateManager` ist die Fassade, und `record()` **persistiert nur**: Es flusht
nie, damit die Protokollzeile in der schon laufenden Unit of Work des Aufrufers committet wird.
Daraus erbt jeder Aufrufer zwei Konsequenzen, und beide haben schon zugebissen:

1. **Die Ids müssen bereits existieren**, ein `record`-Aufruf gehört also hinter den Flush, der
   sie prägt. Eine Nachricht, die vor ihrem Insert verzeichnet wird, verkündet die Id 0.
2. **Hier flusht nichts.** Die Protokollzeilen reiten auf dem Flush des Aufrufers hinaus, in
   derselben Unit of Work wie die Änderung, die sie beschreiben — eine Änderung, die
   zurückgerollt wird, nimmt ihre Ankündigung also mit.

`App\Service\Mail\MailChangeRecorder` sitzt obendrauf, weil die entity-freie Form von
`StateManager` jedem Aufrufer dieselben zwei Dinge zu merken überlässt: welchem Objekttyp das
gerade Geschriebene angehört, und dass eine Email, die sich bewegt hat, auch die Konversation
bewegt hat, in der sie liegt. Davon gab es fünf Kopien, und die, die die Konversation vergaßen,
sahen beim Lesen nicht offensichtlich falsch aus. Es gibt mit Absicht keine Methode pro Feature
— das automatische Speichern eines Entwurfs, ein Anhang und ausgehende Mail sind dieselbe
Ankündigung.

Konversationen werden immer als *updated* verzeichnet, nie als created: Eine brandneue
Konversation von einer gewachsenen zu unterscheiden hieße zu fragen, ob auch jede einzelne ihrer
Nachrichten neu ist, und RFC 8620 §5.2 verlangt von Clients ohnehin, eine Id aus `updated` zu
holen, die sie noch nicht haben.

`StateManager` sammelt außerdem die schmutzigen Paare `(account, type)` im Speicher, und
`JmapPushSubscriber` leert diese Sammlung einmal pro Request oder Handler — ein Gmail-Stapel,
der 50 Nachrichten importiert, erzeugt so eine Push-Benachrichtigung statt fünfzig. Siehe
[JMAP](jmap.md).

## Fallstricke

**`bodyHtml` und `bodyHtmlSafe` sind beide echt, und keines ist überflüssig.** Die bereinigte
Kopie ist das, was gerendert wird; die rohe ist das, was `StructuredDataEventExtractor` liest,
denn der Block `<script type="application/ld+json">`, den er braucht, wird aus der sicheren
Fassung entfernt — völlig zu Recht. `BodyHtmlPreservesStructuredDataTest` nagelt das fest. Die
beiden zusammenzulegen kostet die [Extraktion von Terminen](event-extraction.md) aus
Buchungsbestätigungen, und zwar lautlos.

**Ungelesen richtet sich nach `Message::$seenAt`, nicht nach dem IMAP-Flag `\Seen`.** Es gibt
einen Test, der danach benannt ist. Eingehender IMAP-Flag-Sync über den IDLE-Strom ist nicht
implementiert — Flags wandern nur nach außen —, eine Nachricht in einem anderen Client als
gelesen zu markieren spiegelt sich also nicht zurück, und das Anbieter-Flag als Wahrheit zu
lesen ließe die beiden in die verwirrendere Richtung auseinanderfallen.

**Eine JMAP-Änderung vor dem Flush zu verzeichnen, der die Id prägt, veröffentlicht die Id 0.**
Deshalb kündigt `PostIngestPipeline` Konversationen in einem zweiten Durchgang an statt in
ihrer Schleife, und deshalb nimmt `MailChangeRecorder::emailChanged()` überhaupt eine nullbare
Konversation entgegen.

**`search_vector` ist eine generierte Spalte, und kein PHP liest sie.** Sie ist rein deshalb als
`insertable: false, updatable: false` gemappt, damit sie im Schema bleibt;
`idx_message_search_vector` ist als schlichter Index deklariert und wird von der Migration
`USING gin` gebaut, weil Doctrines Vergleicher einen Index an Name und Spalten erkennt und die
Methode nie ansieht. Ohne die Deklaration will jeder Schema-Diff den Index löschen —
und der gelöschte Index macht aus der Suche einen sequenziellen Scan, ohne dass irgendetwas
fehlschlägt.

**Das Label `Archive` ist Ortsbuchführung, kein Zustand.** „Archiviert" heißt im Domänenmodell
„trägt kein Posteingang-Label"; das Label `Archive` gibt es, damit die Ortslabel-Invariante auch
für schlichte IMAP-Konten hält, deren Server einen physischen Archivordner hat. Es wird
versteckt angelegt und kann sichtbar gemacht werden — deshalb kann ein Archiv-Eintrag in
jemandes Seitenleiste stehen oder eben nicht.

**Ein Post-Ingest-Step, der echte Arbeit tut, bremst jeden Sync auf der Installation.** Das
Interface dokumentiert „anstoßen, nicht arbeiten", und die Pipeline kann es nicht erzwingen; der
Fehler ist keine Fehlermeldung, sondern ein Postfach, das langsamer wird, je mehr Features an
einen Haken gehängt werden, der auf dem verbindungshaltenden Worker läuft.

**Ein Step, der ohne `EnrichmentRouter` einreiht, stellt Live-Mail hinter einen Import.** Die
Routing-Tabelle schickt jede Post-Ingest-Nachricht auf `enrich`; nur der Stamp bringt die Mail
eines fertigen Kontos auf `enrich_live`. Abschicken mit `$this->router->stampsFor($result)`, und
nur für `RecentMailPolicy::recentIds()`.

**Eine zurückgehaltene Nachricht, die nichts freigibt, ist Mail, die niemand im Posteingang
sieht.** Ihre Konversation hat keine Kategorie und steht damit in keinem Tab. Deshalb wird die
zeitgesteuerte Freigabe in `ClassifyMailStep` vor der Feature-Prüfung abgeschickt, und deshalb
sichert sie ein Durchlauf auf einem anderen Worker ab.
