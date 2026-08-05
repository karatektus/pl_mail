<!-- translated-from: internals/architecture.md sha1:1a4a42064fa940f8b0838ec7cdfa4e9acf0182bc -->
# Architektur

Die Schichten, was wo liegt, und die Regeln, die das so halten. Diese Seite beschreibt die
Form der Codebasis und nicht ein einzelnes Feature; die Feature-Seiten —
[Mail-Ingest](mail-ingest.md), [Das Kalendermodell](calendar-model.md),
[Die Sync-Engine](calendar-sync-engine.md), [Extraktion von Terminen](event-extraction.md),
[JMAP](jmap.md) und das [Sicherheitsmodell](security-model.md) — setzen sie voraus.

`CODESTYLE.md` §5 schreibt diese Regeln als Regeln auf. Hier steht, warum jede einzelne die
Reibung wert ist, die sie kostet, und an welcher Stelle die Codebasis sie erzwingt, statt bloß
darum zu bitten.

## Der Baum

```
src/
  Command/        Konsolenbefehle, nach Bereich gruppiert (Mail/, Imap/, Push/, Calendar/, Maintenance/…)
  Controller/     HTTP-Aktionen, nach Bereich gruppiert (Mail/, Admin/, Settings/, Sharing/, Webhook/…)
  Domain/         Das Vokabular: Enum/, DTO/, Interface/, Exception/, Model/, Trait/, Helper/, Filter/
  Entity/         Doctrine-Entities, nach Bereich gruppiert (Mail/, Label/, User/, Calendar/, Monitoring/)
  Form/           Symfony-Formulartypen
  Infrastructure/ Verdrahtung Richtung Framework: Doctrine/, Messaging/, Event/, Scheduler/, Setup/, Encryption/
  Jmap/           Eine Protokollimplementierung, in sich geschlossen, mit eigenem Method/, Mapper/, Protocol/
  Repository/     Eines pro Entity, in derselben Gruppierung wie Entity/
  Security/       Authenticators, User Provider, Zwei-Faktor
  Service/        Alles, was etwas entscheidet. Die größte Schicht, mit Absicht.
  Twig/           Extensions und Runtime-Helfer (Vendor/ für übernommenen Upstream-Code)
```

Zwei Grenzen tragen den größten Teil der Last.

`Domain/` enthält keine Framework-Typen. Es ist das Vokabular, in dem der Rest der Anwendung
argumentiert: die Enums, denen ihre Regeln pro Case selbst gehören, die `final readonly` DTOs,
die Schichten überqueren, die Interfaces für die Achsen, die variieren, und die
Exception-Hierarchien. Weil es von nichts abhängt, kann alles von ihm abhängen — ein Treiber
unter `Service/Calendar/Sync/Google/` und eine JMAP-Methode unter `Jmap/Method/Calendar/`
sprechen beide `CalendarSource`, `RemoteEvent` und `EventStatus`, ohne je voneinander zu
erfahren.

`Infrastructure/` enthält den Kleber, den es nur gibt, weil es Symfony und Doctrine gibt:
`src/Infrastructure/Doctrine/Type/EncryptedStringType.php`, die Messenger-Paare aus Message und
Handler, die Event-Subscriber, `src/Infrastructure/Scheduler/MaintenanceSchedule.php` und die
Boot-Prüfungen unter `src/Infrastructure/Setup/`. Nichts hier entscheidet, was eine Operation
bedeutet.

`Service/` ist mit Absicht das größte Verzeichnis. Gruppiert wird zuerst nach Domänenbereich
und erst dann nach Art — `src/Service/Mail/`, `src/Service/Gmail/`,
`src/Service/Calendar/Sync/CalDav/` —, und genau das macht aus „einen Anbieter hinzufügen" ein
neues Verzeichnis statt elf Dateien, die in ein flaches gekippt werden.

## Controller lösen auf, autorisieren, delegieren, rendern

Ein Controller entscheidet nicht, was eine Operation bedeutet. Der Commit, der das begründet
hat, heißt *„Leave controllers with their actions and little else"*, und die Regel zeigt sich
als drei Gewohnheiten: Route-Attribute an der Klasse für das gemeinsame Präfix und an der
Methode für den Rest, `#[IsGranted]` auf Klassenebene mit Overrides pro Aktion nur dort, wo sie
sich wirklich unterscheiden, und die Besitzprüfung in einem einzigen privaten Resolver, damit
keine Aktion sie vergessen kann.

Die Regel hat Zähne, weil die Alternative schon zugebissen hat. `Thread/set` in JMAP und der
Zurückstellen-Knopf im Web laufen beide durch `App\Service\Mail\ThreadSnoozeService`; der
Web-Endpunkt schrieb früher direkt `MessageThread::$snoozedUntil` und sonst nichts. Die
Konversation blieb damit im Posteingang liegen — lokal wie beim Anbieter —, während ihre Zeile
aus der Liste verschwand, bis der Durchlauf eine Konversation „weckte", die nie fort gewesen
war. Wo zwei Aufrufer sich wirklich unterscheiden müssen, wird der Unterschied an beiden Enden
benannt: Ein Formular-Post bekommt bei einem nicht parsbaren Datum den Rückfall auf „in 1 Tag",
wo `ThreadSetMethod::snoozeDate()` ihn ablehnt, und der Kommentar jeder Seite zeigt auf die
andere.

## Jede Abfrage lebt in einem Repository

Außerhalb von `src/Repository` gibt es kein `createQueryBuilder`. Eine Abfrage, die zweimal
gebraucht wird, ist eine benannte Repository-Methode; eine Abfrage, die einmal gebraucht wird,
aber einen Grund hat, ist eine benannte Repository-Methode, deren Docblock diesen Grund nennt.
`App\Repository\Calendar\CalendarEventRepository` zeigt den Gewinn am deutlichsten —
`findOneByRemoteId()`, `findOneByUid()`, `findPendingSync()`, `findRemoteRowsNotIn()` und
`findRowsTheRemoteNeverGave()` tragen jeweils den Absatz, der erklärt, welche Menge sie
auswählen, und die Sync-Engine liest sich dadurch als Folge von Entscheidungen statt als Folge
von Abfragen.

Rohes DBAL ist erlaubt und sagt dazu, warum es roh sein musste.
`CalendarEventRepository::findOneByRemoteInstanceId()` prüft die Existenz eines jsonb-Schlüssels,
wofür es weder einen DQL-Operator noch eine registrierte Funktion gibt; geschrieben ist das als
`jsonb_exists()` statt mit dem gleichbedeutenden Operator `?`, weil DBAL ein nacktes `?` als
Positionsplatzhalter liest und die Abfrage verweigert.
`CalendarAlertDeliveryRepository::claim()` ist roh, weil der ganze Sinn ein einziges
`INSERT … ON CONFLICT DO NOTHING` ist, für das das ORM keinen Ausdruck kennt.

## Interfaces für die Achse, die variiert

`src/Domain/Interface/` enthält ein Interface pro einsteckbarer Achse, und die Liste ist mit
Absicht kurz:

| Interface | Die Achse |
|---|---|
| `AccountSyncerInterface` | wie Mail für ein Konto geholt wird |
| `MailSenderInterface` | wie Mail hinausgeht |
| `PushSubscriptionManagerInterface` | Push-Registrierung für ein Mail-**Konto** |
| `CalendarPushSubscriptionManagerInterface` | Push-Registrierung für einen gespiegelten **Kalender** |
| `CalendarSyncDriverInterface` | eine Art entfernter Kalender |
| `IntegrationDriverInterface` (+ `VerifiableDriverInterface`, `SearchableDriverInterface`, `TimelineDriverInterface`) | ein externer Datei- oder Fotodienst |
| `EventExtractorInterface` | eine Art, Termine in einer Nachricht zu finden |
| `ProposalDetectorInterface` | eine Art, ein Datum aus Fließtext zu lesen |
| `PostIngestStepInterface` | etwas, das auf frisch eingegangene Mail reagieren will |
| `AlertChannelInterface` | ein Weg, auf dem eine Erinnerung eine Person erreicht |

Die Implementierungen liegen im Verzeichnis des jeweiligen Anbieters und werden über eine
Registry aufgelöst — `MailSenderRegistry`, `IntegrationDriverRegistry`,
`CalendarSyncDriverRegistry`, `CalendarPushRegistry` —, von denen jede einen getaggten Iterator
entgegennimmt und die erste Implementierung zurückgibt, die den Gegenstand für sich
beansprucht. Einen Anbieter hinzuzufügen ist ein Verzeichnis, nie eine Änderung an einem
`switch`.

Zwei der obigen Aufspaltungen liest du besser als Argument denn als Tabellenzeile.

**Push sind zwei Interfaces und nicht ein verbreitertes.**
`CalendarPushSubscriptionManagerInterface` steht neben `PushSubscriptionManagerInterface`, weil
der Gegenstand ein anderer ist und nicht bloß ein engerer: Dort drüben nimmt jede Methode einen
`Account` und liest Spalten auf `Account`, während Graph `me/calendars/{id}/events` abonniert
und ein einziges Microsoft-Mailkonto sechs Kalender spiegeln kann, von denen jeder eigenes
Abonnement, eigenes Secret und eigenen Ablauf braucht. Eine Verbreiterung auf
`Account|Calendar` würde jede Methode in `GmailPushSubscriptionManager` und
`GraphSubscriptionManager` mit einem `instanceof` eröffnen — aus einem Vertrag zur Compile-Zeit
würde eine Kaskade zur Laufzeit. Das Kalender-Interface hat außerdem mit Absicht kein
`messageKey()`: Der Mail-Vertrag hat eines, weil die Kontoeinstellungen pro Anbieter einen
eigenen Text zu einem Bedienelement rendern, das die Nutzerin bedient — und für Kalender-Push
gibt es kein Bedienelement.

**Suche und Zeitleiste stehen getrennt vom Datei-Treiber.** `SearchableDriverInterface` und
`TimelineDriverInterface` sind nicht in `IntegrationDriverInterface` eingefaltet, weil eine
WebDAV-Freigabe zu beidem nichts beizutragen hat und ein Einfalten fünf Treiber zwingen würde,
eine Methode mitzuschleppen, die wirft. `VerifiableDriverInterface` wurde in die andere
Richtung herausgetrennt — `verify()` ist das eine, was jede Verbindung dem schuldet, womit sie
sich verbindet, und ein CalDAV-Kalendertreiber muss es beantworten können, ohne so zu tun, als
hielte er Dateien.

`config/services.yaml` trägt das Tagging, und sein `_instanceof`-Block ist die Stelle, an der
die Grenzen in erzwingbarer Form stehen. Dort steht ausdrücklich, dass ein Kalender-Sync-Treiber
**kein** Integrationstreiber ist und nicht als solcher getaggt werden darf, dass ein
Kalender-Push-Manager weder das eine noch das andere ist und dass ein Alert-Kanal keines von
den dreien ist.

## DTOs überqueren Grenzen

Alles, was zwischen Schichten wandert und mehr als zwei Felder hat, ist eine `final readonly`
Klasse unter `src/Domain/DTO/` und kein Array. Der Docblock sagt, was sie trägt, das ihre
Member nicht offensichtlich nahelegen — `App\Domain\DTO\Mail\IngestedMessage` trägt das
besitzende Konto mit sich, statt die Pipeline es von der Nachricht ablesen zu lassen, denn
unter Gmailify holt ein Gmail-Konto Mail ab, die an ein Geschwisterkonto adressiert ist, und
die beiden sind nicht dasselbe.

Der stärkste Fall ist `App\Domain\DTO\Calendar\SharedOccurrence`, wo das DTO die
Sicherheitsmaßnahme **ist** und nicht eine Bequemlichkeit: Ein öffentliches Template bekommt nie
ein `CalendarEvent` zu sehen, deshalb kann ein Belegt/Frei-Link keinen Titel über einen
Tooltip, ein Data-Attribut, eine JSON-Payload oder eine `.ics` durchsickern lassen — das Objekt,
das gerendert wird, hat schlicht keinen. Siehe das [Sicherheitsmodell](security-model.md).

## Messenger: drei Transports und drei Worker

`config/packages/messenger.yaml` deklariert drei aktive Transports, und die Aufteilung dreht
sich darum, wer wartet:

| Transport | Trägt | Retry |
|---|---|---|
| `export` | alles, was plMail verlässt — Sendungen, Weitergabe von Flags und Labels, Anhang-Uploads, Mail- und Notifier-Nachrichten | 2s Basis, ×3, 5 Versuche, Deckel bei 60s |
| `ingest` | hereinkommende Mail und die Arbeit, die unmittelbar folgt, dazu der Kalender-Sync | 5s Basis, ×3, 5 Versuche, Deckel bei 300s |
| `maintenance` | Backfills, Regelläufe über vorhandene Mail, `RunCommandMessage`, Registrierung von Kalender-Push | wie `ingest` |

`export` ist mit Absicht enger getaktet: Seine Fehler sind ein Relay, das eine Verbindung
verweigert, oder ein Anbieter, der kurz blinzelt — beides vergeht in Sekunden, und jemand
schaut auf das Ergebnis. Das Fenster von `ingest` waren früher die Symfony-Vorgaben — 1s/2s/4s
—, eine Spanne von sieben Sekunden, die jeden Versuch aufbrauchte, bevor ein Rate Limit
überhaupt eine Chance hatte abzulaufen; ein behebbarer Fehler landete damit genauso zuverlässig
im Dead Letter wie ein unbehebbarer. `max_delay` bleibt innerhalb des `--time-limit=3600` des
Workers, damit ein verzögerter Retry nie auf einen Neustart warten muss.

Getrennte Transports allein genügen nicht. Ein Worker, der bereits in einem langen Handler
steckt, kann eine Sendung nicht aufnehmen, wie hoch sie auch priorisiert ist — also hat jeder
Transport seinen eigenen Prozess: `worker-export`, `worker-ingest` und `worker-maintenance` in
`compose.yaml`. Ein vierter Transport, `async`, wird ohne Routing weitergeführt, damit
Envelopes, die vor der Aufteilung eingereiht wurden, noch irgendwo landen können; der
Maintenance-Worker leert ihn.

Zwei Routing-Entscheidungen sind tragend und nicht bloß ordentlich:

- **`SyncCalendarMessage` geht auf `ingest`, nicht auf `export`,** obwohl ein Kalender-Sync
  ebenso nach außen schreibt wie er liest. Niemand wartet darauf — eine lokale Änderung ist
  gespeichert und auf dem Schirm, bevor der Push überhaupt abgeschickt wird —, und sie auf die
  Sendewarteschlange zu legen würde deren einziges Versprechen schwächen.
- **`RegisterCalendarPushMessage` geht auf `maintenance`,** neben die `SyncCalendarMessage`, die
  für denselben Kalender abgeschickt wird. Auf `ingest` stünde sie hinter jenem ersten
  vollständigen Kalenderlauf in der Schlange, und der Push-Kanal ginge damit Minuten nach dem
  Abonnieren auf — ausgerechnet bei den großen Kalendern, bei denen Push am meisten zählt. Dass
  sie *überhaupt* geroutet wird, ist der Punkt: Eine Messenger-Nachricht ohne Routing wird in
  dem Prozess behandelt, der sie abgeschickt hat, und das legte einen Aufruf zu Google oder
  Microsoft in den HTTP-Request, mit dem der Kalender angehakt wurde.

`ApplyGmailLabelsMessage` war bis vor kurzem ungeroutet, und das hieß: jede Gmail-Labeländerung
— archivieren, in den Papierkorb, mit Stern versehen, als gelesen markieren — machte einen
Live-Aufruf an die Google-API mitten im HTTP-Request, auf den die Nutzerin wartete, während
ihre Gegenstücke für IMAP und Graph längst in der Warteschlange standen. Derselbe Klick verhielt
sich also unterschiedlich, je nachdem, auf welchem Konto er landete.

Die Messages selbst sind `readonly` und tragen **ausschließlich Ids und Skalare**, niemals
Entities, und sie sind 1:1 nach Namen an ihren Handler gebunden (`SyncAccountMessage` /
`SyncAccountMessageHandler`). Das ist kein Stil: Handler laufen auf langlebigen Workern, die den
Entity Manager zwischen den Envelopes leeren, und eine serialisierte Entity ist damit eine
Referenz auf einen Manager, den es nicht mehr gibt.

## Der Scheduler

`App\Infrastructure\Scheduler\MaintenanceSchedule` ist die eine Stelle, an der wiederkehrende
Arbeit deklariert wird. Konsumiert wird sie von `messenger:consume scheduler_default` — dem
Dienst `scheduler` in `compose.yaml` — und **sonst läuft davon nichts**, was genau der Zustand
war, in dem das Projekt vorher steckte: Logs und verwaiste Blobs wuchsen ohne Grenze.

| Cron | Befehl | Warum diese Taktung |
|---|---|---|
| `*/15 * * * *` | `app:mail:sync` | Weder Gmail-Push noch Graph-Abonnements garantieren Zustellung, und IDLE-Verbindungen brechen ab; Polling ist die Rückfallebene |
| `7-59/15 * * * *` | `app:calendar:sync --stale` | Der Mechanismus für CalDAV und ICS-Feeds, die Rückfallebene für Google und Graph. Gegen die Viertelstunde versetzt, damit er sich nicht auf den Mail-Durchlauf stapelt — beide teilen sich einen Worker |
| `20 * * * *` | `app:calendar:push` | Stündlich, nicht weil so schnell etwas abliefe, sondern weil die Registrierung aus *Deployment*-Gründen scheitert, die mit dem Klick, der den Kalender verbunden hat, nichts zu tun haben |
| `* * * * *` | `app:mail:wake-snoozed` | Eine Minute ist die Einheit, in der Menschen eine Weckzeit wählen |
| `* * * * *` | `app:calendar:alerts` | Dasselbe Argument, und zusätzlich ist das Intervall die Schranke dafür, wie spät eine Erinnerung kommen kann |
| `0 4 * * *` | `app:push:renew --repair` | Gmail-Watches halten 7 Tage, Graph-Abonnements etwa 3 |
| `50 3 * * *` | `app:calendar:materialise` | Rollt den Horizont der Termininstanzen vorwärts, damit einem Serientermin nicht klammheimlich die Termine ausgehen |
| `30 4 * * *` | `app:monitoring:prune` | Log-Einträge und tote Heartbeats |
| `0 5 * * 0` | `app:prune:blobs` | Wöchentlich; er läuft drei Verzeichnisbäume ab, und eine Woche Waisen ist ein Rundungsfehler |

Der Zeitplan ist `stateful()` gegen den Cache und `processOnlyLastMissedRun(true)`. Ein Worker,
der über einen geplanten Lauf hinweg unten war, holt also nach, statt den Tag stillschweigend
zu überspringen — aber nur einmal, denn das sind allesamt idempotente Durchläufe, und einen
Rückstau fünffach abzuspielen ist reine Verschwendung. Die Zeiten sind über die frühen Stunden
verteilt statt auf Mitternacht gestapelt, aus demselben Grund, aus dem der Kalender-Durchlauf
versetzt ist: ein Worker, und ein langes Aufräumen soll keinen Sync aufhalten.

Jeder dieser Befehle ist auch von Hand aufrufbar, und jeder Befehl im Baum steht mit einer
einzeiligen Beschreibung in `CONTRIBUTING.md`. Diese Tabelle gehört zur Definition of Done.

## Entities und die Invarianten, die strukturell sind

Nur Attribute, `Types::`-Konstanten, `enumType:` für Enums und `onDelete` an der Join-Spalte
deklariert, damit die Datenbank erzwingt, was der Code annimmt. Zustand ist public, mit
`public private(set)` dort, wo von außen nicht geschrieben werden darf; im gesamten Baum
`src/Entity` gibt es acht Methoden `public function get…`, und jede davon tut etwas, das eine
Property nicht kann.

Zwei Gewohnheiten seien hervorgehoben, weil dort die Korrektheit tatsächlich wohnt.

**Jeder Index und jedes Unique Constraint trägt einen Kommentar, der sagt, was er schützt und
warum die Spalten in dieser Reihenfolge stehen.** `uniq_calendar_booking_page_start` auf
`calendar_booking` ist keine Optimierung — es ist das Einzige, was zwei Fremde davon abhält,
dieselbe halbe Stunde zu nehmen, und die Id der Seite steht vorn, weil das Constraint von den
Zeitfenstern einer Seite handelt. `uniq_calendar_event_calendar_uid` begrenzt die
Eindeutigkeit der UID auf einen Kalender, und genau das macht die Kopie einer Besprechung auf
einem zweiten Kalender legal statt bloß geduldet.

**Zeitstempel kommen aus `App\Domain\Trait\TimestampableTrait`, auf jeder Entity, ohne
Ausnahme** — auch auf den Tabellen, die einmal geschrieben werden. Eine Regel für jede Entity
ist mehr wert als die Bytes, die eine Ausnahme spart, und nichts muss entscheiden, welche Art
Entity gerade vor ihm liegt. Der Trait braucht `#[ORM\HasLifecycleCallbacks]` an der
aufnehmenden Klasse und Doctrine tut ohne das stillschweigend nichts, deshalb prüft
`TimestampableTest` jede aufnehmende Entity auf das Attribut — die eine Anforderung, die der
Trait selbst nicht erzwingen kann.

## Fallstricke

**Eine neue Messenger-Message ohne Routing-Eintrag läuft synchron.** Symfony behandelt eine
ungeroutete Message im abschickenden Prozess, und ein Job, der geschrieben wurde, um Arbeit aus
einem HTTP-Request herauszuholen, bleibt damit stillschweigend darin.
`config/packages/messenger.yaml` benennt das für `RegisterCalendarPushMessage`, und
`CalendarSubscriberTest` prüft das Routing; eine neue Message bekommt weder das eine noch das
andere geschenkt.

**Ein neuer Befehl in `MaintenanceSchedule` tut nichts, solange der Dienst `scheduler` nicht
läuft.** Das ist ein eigener Container, der `scheduler_default` konsumiert. `php bin/console
debug:scheduler` zeigt den nächsten Lauf jedes Eintrags und ist der schnellste Weg
herauszufinden, dass die Antwort „nie" lautet.

**Eine neue getaggte Implementierung erbt die Reihenfolgeregeln ihrer Registry.** Die
Registries nehmen die *erste* Implementierung, die den Gegenstand für sich beansprucht — ein zu
breit geschriebenes `supports()` stiehlt also einem anderen Treiber stillschweigend die Arbeit,
statt zu scheitern. `MailSenderRegistry` sortiert nach ausdrücklicher Tag-Priorität
(`GmailApiSender` bei 10, `SmtpMailSender` bei 0), gerade weil die Deklarationsreihenfolge kein
Vertrag ist.

**Ein `_instanceof`-Tag wird von allem geerbt, was das Interface implementiert.** Deshalb ist
`VerifiableDriverInterface` mit `app.integration_driver` getaggt und
`CalendarSyncDriverInterface` mit Absicht nicht: Ein Kalendertreiber, der zusätzlich
`VerifiableDriverInterface` implementiert — `CalDavCalendarDriver` und `IcsUrlCalendarDriver`
tun beides —, muss über die Pfade zum Verbinden und Testen erreichbar sein, ohne in der Registry
des Dateiauswahl-Dialogs aufzutauchen.

**Eine Repository-Methode, die einmal benutzt wird, ist trotzdem eine Repository-Methode.** Die
Versuchung, ein `findBy()` in einen Service hineinzuschreiben, ist genau das, was den Vorläufern
der Sync-Engine ihre Begründungen ausgelöscht hat: `findRemoteRowsNotIn()` und
`findRowsTheRemoteNeverGave()` sind exakte Komplemente, und welches von beiden ein Aufrufer
will, ist eine Entscheidung mit einem Absatz dahinter, kein Filter zum Abtippen.

**Einen Enum-Case hinzuzufügen ist nur dort gefahrlos, wo das `match` erschöpfend ist.** Das
Muster in dieser Codebasis ist `match ($this)` über jeden Case ohne `default`, ein neuer Case
ist damit ein Fehler quasi zur Compile-Zeit. Wo jemand ein Prädikat stattdessen als Vergleich
geschrieben hat — das `self::Llm !== $this`, das `EventSource::isTrusted()` einmal war —, hat der
nächste Case stillschweigend genau die Erlaubnis geerbt, die die Methode vorenthalten sollte.
