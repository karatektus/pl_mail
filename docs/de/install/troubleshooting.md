<!-- translated-from: install/troubleshooting.md sha1:c2602b3577029a9a880454d2a1c78a8c4d349a7b -->
# Fehlersuche

Was `/healthz` bedeutet, wie du eine hängende von einer leeren Warteschlange unterscheidest, wo die
Logs wirklich liegen, und die Fehler, die hier schon jemandem passiert sind.

## `/healthz`

```
GET /healthz
```

```json
{
  "status": "ok",
  "checks": { "database": true, "queue": true, "workers": true }
}
```

Unauthentifiziert, weil Docker-Healthchecks und Uptime-Monitore keine Sitzung haben — und deshalb
bewusst vage. Es beantwortet nur das, was ein solcher Aufrufer ohnehin durch einen Versuch mit der
Anwendung herausfinden könnte: keine Zahlen, keine Adressen, keine Kontonamen, keine Version. Die
Zahlen liegen unter `/admin`, hinter `ROLE_ADMIN`, und ein Test sichert diese Form ab, sodass eine
Ergänzung, die etwas preisgibt, die Suite scheitern lässt.

| Feld | Bedeutung |
|---|---|
| `status` | `ok` mit 200 oder `error` mit 503 |
| `database` | ob die Datenbank erreichbar ist. **Das Einzige, was den Statuscode bestimmt** |
| `queue` | `true`, solange über alle Warteschlangen hinweg weniger als 5000 Nachrichten warten, `false` darüber, `null`, wenn es sich nicht ermitteln ließ |
| `workers` | `true`, wenn jeder Prozess, der jemals einen Heartbeat gemeldet hat, weiter schlägt, `false`, wenn einer veraltet ist, `null`, wenn es überhaupt keine Heartbeats gibt |

**503 nur, wenn die Datenbank unten ist**, denn das ist der eine Fehler, bei dem Ausliefern unmöglich
ist. Eine gestaute Warteschlange bleibt absichtlich bei 200: Mail ist verspätet, nicht verloren, und
den Container neu zu starten würde nicht helfen — es würde eine funktionierende Instanz aus der
Rotation nehmen und die gerade laufende Arbeit obendrein verlieren. Die Schwelle von 5000 ist aus
demselben Grund großzügig, aus dem der erste Abgleich eines großen Postfachs legitim Tausende Jobs
in die Warteschlange stellt und nicht als Störung gelesen werden darf.

`null` ist nicht `false`. Ein Monitor muss "die Warteschlange ist gestaut" von "ich konnte nicht
nachsehen" unterscheiden können, und eine Installation, die noch nie einen Worker laufen hatte, hat
keine Heartbeats — nichts ist fehlgeschlagen, es gibt schlicht noch nichts zu melden.

Der `HEALTHCHECK` des Images zeigt hierher, mit einer Startphase von 60 Sekunden. Früher fragte er
Caddys Metrics-Port ab, der antwortet, sobald der Webserver lauscht — lange bevor PHP die Datenbank
erreichen kann —, sodass ein Stack mit unerreichbarer Datenbank sich selbst als gesund meldete und
`depends_on: service_healthy` auf nichts wartete. Die Worker-Dienste haben keinen HTTP-Server und
schalten den Healthcheck vollständig ab; ihre Lebendigkeit erreicht denselben Endpunkt über
Heartbeats.

**Der typische Fehlerfall ist, allein auf den Statuscode zu alarmieren.** Ein toter Scheduler und
eine stehende Warteschlange antworten beide mit 200. Beobachte das `checks`-Objekt, nicht nur den
HTTP-Status.

## Die Warteschlange

Vier Transports auf dem Doctrine-Transport, jeder mit einem eigenen Worker-Prozess:

| Warteschlange | Container | Was darauf liegt |
|---|---|---|
| `export` | `worker-export` | Alles, was plMail verlässt — Versand, Flag-Pushes, Gmail-Label-Änderungen, Mail aus dem Notifier. Die einzige Warteschlange, auf die jemand wartet |
| `ingest` | `worker-ingest` | Eingehende Mail, Gmail- und Graph-Nachrichten-Batches, Kalenderabgleiche, Terminerkennung |
| `maintenance` | `worker-maintenance` | Nachträgliche Verarbeitungen, Regelläufe über vorhandene Mail, "Jetzt ausführen"-Knöpfe im Administrationsbereich, Registrierung von Kalender-Push |
| `async` | `worker-maintenance` | Stillgelegt. Wird geleert, damit vor der Aufteilung eingestellte Envelopes noch einen Konsumenten haben; hierher wird nichts mehr geroutet |

Drei Prozesse statt drei Transports in einem Worker, weil ein Worker, der bereits in einem langen
Handler steckt, nichts anderes mehr annehmen kann, wie auch immer die Warteschlangen priorisiert
sind — weshalb ein Klick auf Senden früher darauf wartete, dass ein Gmail-Batch fertig wurde.

Vom Terminal aus:

```bash
docker compose exec php php bin/console messenger:stats
docker compose exec php php bin/console messenger:failed:show
```

Das **Warteschlangen-Panel im Administrationsbereich** ist die bessere Sicht, wenn keine Mail mehr
ankommt, weil es die zwei Zustände unterscheidet, die eine Zahl nicht unterscheiden kann: Es nennt
die Nachrichten, die ein Worker *gerade jetzt* hält — den Handler, seine Nutzdaten und wie lange er
sie schon hält — oberhalb der Liste all dessen, was noch wartet. Eine hängende Warteschlange sieht
damit anders aus als eine bloß tiefe. Siehe [Administration](../features/admin.md).

Wiederholungen sind begrenzt und je Warteschlange verschieden: `export` gibt fünf Versuche über rund
anderthalb Minuten, weil seine Fehler ein Relay sind, das eine Verbindung ablehnt, und weil jemand
zusieht; `ingest` und `maintenance` geben fünf Versuche über etwa achteinhalb Minuten, weil ihre
Fehler Ratenbegrenzungen Dritter sind, deren Abklingen Minuten dauert. Was sie erschöpft, landet im
Transport `failed`, den das Panel im Administrationsbereich wiederholen oder verwerfen kann.

**Der typische Fehlerfall ist eine Warteschlange ohne Konsumenten.** Nichts meldet einen Fehler —
Nachrichten sammeln sich an, und die Anwendung sieht gesund aus. Das ist schon passiert: Ein
Entwicklungs-Stack, dessen Override noch den Dienst `messenger-worker` von vor der Aufteilung
definierte, ließ einen Worker laufen, der nur die stillgelegte Warteschlange `async` konsumierte —
Versand und Abgleiche stauten sich, ohne dass irgendwo ein Fehler auftauchte.

## Der Scheduler

Die wiederkehrenden Jobs stehen in `App\Infrastructure\Scheduler\MaintenanceSchedule` und werden von
einem Container ausgelöst, der `scheduler_default` konsumiert. **Ohne diesen Container wird keiner
von ihnen ausgelöst.**

| Takt | Befehl |
|---|---|
| jede Minute | `app:mail:wake-snoozed` |
| jede Minute | `app:calendar:alerts` |
| `*/15` | `app:mail:sync` |
| `7-59/15` | `app:calendar:sync --stale` |
| stündlich um :20 | `app:calendar:push` |
| täglich 03:50 | `app:calendar:materialise` |
| täglich 04:00 | `app:push:renew --repair` |
| täglich 04:30 | `app:monitoring:prune` |
| sonntags 05:00 | `app:prune:blobs` |

```bash
docker compose exec php php bin/console debug:scheduler
```

Der Zeitplan ist zustandsbehaftet, ein Worker, der über einen geplanten Lauf hinweg unten war, holt
ihn also nach, statt den Tag zu überspringen — und nachgeholt wird nur der zuletzt versäumte Lauf,
weil das alles idempotente Durchläufe sind und den Rückstand von gestern fünfmal abzuarbeiten
Verschwendung wäre. Die Zeiten sind über die frühen Morgenstunden verteilt statt auf Mitternacht
gestapelt, weil sie sich einen Worker teilen.

**Der typische Fehlerfall ist die Symptomliste, nicht ein Fehler.** Kein Mail-Polling, keine
zurückgestellte Konversation, die je aufwacht, kein Kalenderabgleich, keine Erinnerungen, kein
Aufräumen der Protokolle, kein Aufräumen der Blobs — alles auf einmal und alles lautlos. In diesem
Zustand war das Projekt, bevor es den Scheduler gab, mit unbegrenzt wachsenden Logs und verwaisten
Blobs. Wenn mehrere zusammenhanglose Dinge "nicht mehr funktionieren", prüfe zuerst, ob es diesen
Container gibt.

## Worker und Heartbeats

Lebendigkeit wird über Heartbeats bestimmt und nicht über Prozesse, weil der Web-Container nicht in
die anderen hineinsehen kann. Jeder dauerhaft laufende Prozess schreibt eine Zeile, geschlüsselt
über `APP_CONTAINER_NAME`, und wann etwas als veraltet gilt, hängt vom Typ ab:

| Prozesstyp | Veraltet nach |
|---|---|
| `imap-idle` | 2100s — knapp über der 29-minütigen IDLE-Erneuerung |
| `imap-supervise` | 300s |
| `messenger-worker` | 120s — der Listener schlägt alle 30s |
| alles andere | 600s |

Eine Zeile, die das Vierfache ihrer Schwelle überschritten hat, wird ganz entfernt — weit genug
gefasst, dass ein kurzzeitig verklemmter Prozess auf dem Dashboard rot erscheint, bevor er
verschwindet.

Worker beenden sich bei `--time-limit=3600` und werden von Compose neu gestartet, eine Heartbeat-
Lücke von ein, zwei Sekunden um die volle Stunde ist also normal. **Administration → System →
Worker neu starten** bittet sie alle, sich am Ende ihrer aktuellen Nachricht zu beenden; die
Anwendung hat keinen Docker-Socket und braucht auch keinen, denn bei `restart: unless-stopped`
*ist* Beenden gleich Neustarten.

**Der typische Fehlerfall ist ein nicht gesetztes `APP_CONTAINER_NAME`.** Der Schlüssel fällt auf
den Hostnamen zurück, der sich bei jedem Neuerzeugen eines Containers ändert, sodass sich das
Dashboard mit toten Workern füllt, die nie wirklich tot waren.

## Wo die Logs liegen

**Containerausgabe.** In `prod` schreibt Monolog JSON nach `php://stderr`, alles steht also in
`docker compose logs`:

```bash
docker compose logs -f php
docker compose logs -f worker-ingest
docker compose logs -f scheduler
```

Der Haupt-Handler ist `fingers_crossed` auf `error` mit einem Puffer von 50 Nachrichten, das heißt,
gewöhnliche Info-Zeilen werden verworfen — *bis* etwas fehlschlägt, und dann werden die 50
vorangegangenen Nachrichten mit ausgegeben. Deshalb kommt ein Fehler im Log meist mit seinem eigenen
Kontext an, und deshalb findest du keine stille Spur einer Operation, die geklappt hat.
Deprecations gehen auf einem eigenen Kanal nach stderr. In `dev` geht dieselbe Ausgabe nach
`var/log/dev.log`.

**Der Protokollbrowser im Administrationsbereich.** Ein zweiter Handler schreibt in die Datenbank,
und **Administration → Protokolle** durchsucht sie: 100 Einträge pro Seite, filterbar nach
Mindeststufe von Info bis Critical, mit dem Containernamen in jeder Zeile. Die niedrigste
aufbewahrte Stufe ist `APP_DB_LOG_LEVEL`, standardmäßig `warning`. Alles ab Warnstufe, das noch
niemand gelesen hat, umrandet das Benutzermenü — bernsteinfarben oder rot — auf jeder Seite, und
zwar nur für Administratoren. Den Browser zu öffnen ist das, was sie als gesehen markiert.

`app:monitoring:prune` bewahrt standardmäßig 14 Tage Protokolleinträge und 30 Tage Heartbeats auf,
nächtlich.

**Der typische Fehlerfall ist, in der Produktion nach einer Spur auf Info-Ebene zu suchen.**
`fingers_crossed` bedeutet, dass sie nie geschrieben wurde, sofern kein Fehler folgte. Senke
`APP_DB_LOG_LEVEL` vorübergehend auf `info`, wenn die Datenbankseite mehr aufbewahren soll, und setz
es zurück — auf `debug` wächst die Tabelle schnell.

## Ein bestimmtes Konto untersuchen

```bash
docker compose exec php php bin/console app:mail:test-connection      # IMAP/SMTP probe
docker compose exec php php bin/console app:imap:test --account=ID    # connection and folder listing
docker compose exec php php bin/console app:graph:diagnose            # what Graph actually permits
docker compose exec php php bin/console app:mail:sync ACCOUNT_ID      # dispatch a sync by hand
```

Speziell für Gmail-Push zeigt das Panel **Gmail-Webhooks** im Administrationsbereich das Topic, den
exakten Endpunkt, den Pub/Sub aufrufen muss, und warum eine Benachrichtigung abgewiesen wurde.

**Der typische Fehlerfall ist, aus dem falschen Container heraus zu testen.** Über
`docker compose exec php` sind diese Befehle in Ordnung, denn das lädt die erzeugten Geheimnisse
über Composers Autoload-Dateien. Ein blankes `docker run` gegen das Image ist es nicht — es hat kein
Secrets-Volume, hält also einen anderen Verschlüsselungsschlüssel und kann keine einzige
gespeicherte Zugangsberechtigung lesen.

## Fehler, die tatsächlich vorgekommen sind

| Symptom | Ursache |
|---|---|
| Anhang-Downloads enden mit 404, Blob-Daten verschwinden, wenn ein Container neu erzeugt wird | Die Blob-Verzeichnisse liegen auf keinem gemeinsamen Volume. Siehe [Docker Compose](docker.md#storage-and-what-the-stock-file-does-not-persist) |
| Drei von sechs Containern starten nie, in den Logs steht `column … already exists` | Gleichzeitige Migrationen beim Start. Behoben durch den Advisory-Lock in `app:db:migrate`; wenn du das heute siehst, führt irgendetwas `doctrine:migrations:migrate` direkt aus |
| Ein Container verweigert den Start: "APP_ENCRYPTION_KEY cannot decrypt the credentials already stored" | Ein Dienst ohne den Mount `app_secrets`, oder ein Schlüssel, der sich unter laufendem Stack geändert hat |
| Der Entrypoint bricht ab, bevor irgendetwas startet, nach einer Meldung über ACLs | War ein Fehlschlag von `setfacl` auf ZFS, NFS oder einer Docker-Desktop-Freigabe unter `set -e`. Das ist heute ein Hinweis und kein Fehler — wenn du den Hinweis siehst, ist nichts falsch |
| Ein Stack meldet sich als gesund, während die Datenbank unerreichbar ist | Der alte Healthcheck fragte Caddys Metrics-Port ab. `/healthz` hat ihn abgelöst |
| Alles funktioniert, aber nichts aktualisiert sich je von selbst | `MERCURE_PUBLIC_URL` zeigt dorthin, wo der Browser nicht hinkommt. Siehe [Hinter einem Reverse-Proxy](reverse-proxy.md) |
| Gmail-Push liefert für jede Benachrichtigung 403 | `GMAIL_PUBSUB_VERIFICATION_TOKEN` nicht gesetzt oder passt nicht zum `?token=` am Pub/Sub-Abonnement. Es scheitert nach der sicheren Seite |
| Kalender-Push registriert sich nie, Warnung bei der Registrierung | `APP_PUBLIC_URL` ohne HTTPS, oder ein Loopback-Host, oder ein Google-Cloud-Projekt, dessen Domain-Verifizierung noch aussteht |
| **Überhaupt keine Erinnerungen, für niemanden, jede Minute aufs Neue** | Ein Konto, dessen Anzeigeadresse ein IMAP-Benutzername statt einer Adresse war, warf beim Bau der Erinnerungsmail eine Exception, beendete damit den ganzen Durchlauf und verlor die in diesem Stapel bereits beanspruchten Erinnerungen. An beiden Enden behoben — die Nachricht wird innerhalb des eigenen `try` des Kanals gebaut, und der Deliverer überlebt einen werfenden Kanal ohnehin |
| Versand und Abgleiche stauen sich und nichts arbeitet sie ab, nirgends ein Fehler | Ein Worker, der eine Warteschlange konsumiert, in die nichts geroutet wird. Prüfe `messenger:stats` gegen die vier Transports oben |

**Der typische Fehlerfall, der fast der ganzen Tabelle gemeinsam ist, ist Stille.** Nahezu keiner
davon erzeugt einen Fehler auf einer Seite; sie erzeugen eine Abwesenheit. Genau deshalb meldet das
Panel im Administrationsbereich Warteschlangentiefe und Heartbeats und nicht nur Fehler.

## Fallstricke

**Ein 200 von `/healthz` bedeutet "weiter ausliefern", nicht "alles funktioniert".** Nur die
Datenbank bestimmt den Statuscode. Hinter einem 200 kann jeder Worker tot sein — absichtlich, weil
die Anwendung bereits abgeglichene Mail weiterhin ausliefert.

**`workers: null` auf einer frischen Installation ist richtig.** Es hat noch nichts geschlagen. Der
Wert wird erst aussagekräftig, nachdem die Worker einmal gelaufen sind.

**Die Warteschlangenschwelle liegt aus gutem Grund bei 5000.** Sie zu senken, um Probleme früher zu
bemerken, führt dazu, dass der erste Abgleich eines großen Postfachs sich selbst als Störung meldet
und ein Orchestrator einen gesunden Container neu startet.

**Ein fehlender `scheduler`-Container ist in jedem Panel unsichtbar, das Fehler zeigt.** Nichts
schlägt fehl; die Dinge passieren bloß nie. `debug:scheduler` und die Heartbeat-Liste sind die
Stellen, an denen es sichtbar wird.

**`app:reset --full` ist kein Schritt der Fehlersuche.** Es geht zurück in den Zustand vor dem
ersten Start: jede Tabelle, jeder Benutzer, die gespeicherten Dateien. Es existiert, damit eine
Installation, deren unlesbare Daten wirklich entbehrlich sind, neu anfangen kann, und die Prüfung
des Verschlüsselungsschlüssels lässt es bewusst zu — siehe
[CONTRIBUTING](../../CONTRIBUTING.md#when-the-keys-disagree).

**Logs aus `docker compose logs php` sind nur die des Web-Containers.** Sechs Container laufen mit
demselben Image, und die interessante Zeile steht meist in dem Worker, dem die Warteschlange gehört,
auf der die Arbeit lag.
