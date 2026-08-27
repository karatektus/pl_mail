<!-- translated-from: install/upgrading.md sha1:955598218f74f2ecb8e9af61865ceb81d9296df3 -->
# Aktualisieren

plMail migriert seine eigene Datenbank beim Start. Diese eine Entscheidung prägt alles auf dieser
Seite: Sie ist der Grund, warum ein Upgrade aus zwei Befehlen besteht, warum der Image-Tag, den du
ziehst, mehr zählt als sonst, warum die Kopfzeile im Administrationsbereich überhaupt eine
Build-Nummer zeigt und warum Zurückrollen bedeutet, eine Sicherung einzuspielen, statt den vorigen
Tag zu ziehen.

## Migrationen laufen automatisch, bei jedem Start

`frankenphp/docker-entrypoint.sh` wartet auf die Datenbank und führt dann in jedem
Anwendungscontainer `app:db:migrate` aus — im Webserver, im IMAP-Supervisor, in allen vier Workern.
Dieser Befehl ist `doctrine:migrations:migrate --all-or-nothing --no-interaction` mit einer
Ergänzung: Er hält für den gesamten Lauf einen Postgres-Advisory-Lock.

Der Lock ist keine Zierde. Sechs Container starten im Abstand von Millisekunden gegen eine
Datenbank, alle sechs lesen das Migrationsregister, bevor einer von ihnen hineingeschrieben hat, und
alle sechs entscheiden, dass dieselbe Migration aussteht. Ohne den Lock gewinnt einer, die übrigen
blockieren an seiner Tabellensperre, werden bei seinem Commit freigegeben und sterben an einem
Schema, das sich bereits bewegt hat — `SQLSTATE[42701]: Duplicate column`. Unter `set -e` sind das
fünf Dienste, die nie starten. Mit dem Lock warten die Verlierer, lesen ein bereits aktuelles
Register und beenden sich, ohne etwas gefunden zu haben.

Daraus folgen drei Dinge, und sie sind der Grund für diese Seite:

- **Es gibt kein Zeitfenster, in dem du die Migration vor ihrem Lauf begutachten könntest.** Das
  neue Image zu starten *ist* ihr Lauf.
- **Eine fehlgeschlagene Migration reißt den Container mit**, weil der Entrypoint bei einem Fehler
  anhält. Das ist gewollt: Ein Container, der Anfragen gegen ein halb migriertes Schema bedient, ist
  schlimmer als ein Container, der gar nicht erst gestartet ist.
- **Das Warten auf den Lock hat eine Frist.** Standardmäßig fünf Minuten, überschreibbar mit
  `--lock-timeout`. Danach verweigert der Befehl die Migration, statt anzunehmen, der Halter sei tot
  — was auch immer den Lock hält, könnte noch mitten in der Migration stecken, und eine zweite
  Migration obendrauf ist genau das, was der Lock verhindern soll.

Das CHANGELOG nennt die Schemaänderung in jeder Version, die eine hat, ganz oben im Eintrag,
zusammen mit der Angabe, ob sie rein additiv ist. Lies das, bevor du ziehst; es ist die einzige
Vorschau, die du bekommst.

**Der typische Fehlerfall ist ein Stack, in dem ein Container beim Halten des Locks feststeckt.**
Alles andere wartet fünf Minuten, meldet "timed out waiting for another container to finish running
migrations" und beendet sich. Finde den festhängenden Container, bevor du die übrigen neu startest.

## Image-Tags

Der Release-Workflow veröffentlicht ein Multi-Architektur-Manifest und richtet mehrere Tags darauf:

| Tag | Wem er folgt |
|---|---|
| `latest` | der jüngsten **Veröffentlichung** — er bewegt sich nur, wenn ein `v*`-Tag gepusht wird |
| `main` | der Spitze des Standard-Branches, veröffentlicht oder nicht |
| `0.0.16` | einer Veröffentlichung, festgenagelt. Ohne führendes `v`: Der Git-Tag heißt `v0.0.16`, der Image-Tag nicht |
| `0.0` | dem neuesten Patch einer Minor-Linie |
| `sha-1a2b3c` | einem Commit, exakt festgenagelt |

`latest` folgte früher dem Standard-Branch, wodurch jeder Merge direkt auf den Tag ging, den die
README den Leuten zum Ziehen nennt — einschließlich unfertiger Arbeit und Migrationen, die beim
Start laufen. Genau deswegen wurde das geändert.

Wähle bewusst. `latest` ist für die meisten Installationen richtig. `main` ist dafür da, das laufen
zu lassen, woran gerade gearbeitet wird, und das Changelog wird es noch nicht beschreiben. Eine
festgenagelte Version ist richtig, wenn du Upgrades lieber planst als empfängst.

**Der typische Fehlerfall ist `pull_policy: always` auf einem beweglichen Tag.**
`truenas.compose.yaml` setzt das, ein Redeploy von der NAS-App-Seite holt sich also, worauf dieser
Tag inzwischen zeigt — bequem bei `latest` und überraschend bei `main`.

## Das Upgrade

```bash
docker compose pull
docker compose up -d --wait
```

Jeder Anwendungsdienst läuft mit demselben Image, alle werden also neu erzeugt und kommen mit dem
neuen Code hoch. `--wait` blockiert, bis die Healthchecks bestehen, was in einem Skript etwas wert
ist: Der Healthcheck des `php`-Containers ist `/healthz`, und das antwortet erst, wenn PHP die
Datenbank erreichen kann.

Prüfe danach zwei Dinge:

1. **Die Kopfzeile im Administrationsbereich**, um zu sehen, welcher Build tatsächlich läuft.
2. **`/healthz`**, um zu sehen, ob die Worker zurückgekommen sind — siehe
   [Fehlersuche](troubleshooting.md).

Solltest du jemals auf einem anderen Weg als einem Container-Neustart migrieren, starte die Worker
danach über **Administration → System** neu. Ein Worker hält Doctrines Metadaten für seine gesamte
Lebensdauer im Cache, kann nach einer Migration also weiter Spalten abfragen, die es nicht mehr
gibt, bis ihn etwas neu startet. Dieser Knopf schreibt einen Zeitstempel in einen Cache-Pool, den
sich die Container teilen; jeder Messenger-Worker vergleicht ihn in jeder Schleife mit seiner
eigenen Startzeit und beendet sich, und `restart: unless-stopped` bringt ihn zurück. Der
IMAP-Supervisor ist kein Messenger-Worker und liest dasselbe Signal selbst.

**Der typische Fehlerfall ist, einen einzelnen Dienst zu aktualisieren.** Alle teilen sich das Image
und alle migrieren; ein Stack, in dem nur `php` neu erzeugt wurde, hat Worker, die den Code von
gestern gegen das Schema von heute ausführen.

## Erkennen, welcher Build läuft

Als Administrator angemeldet trägt die Kopfzeile jeder **Administrations**-Seite einen Chip mit der
Referenz, aus der das Image gebaut wurde, und den ersten sieben Zeichen des Commits. Er steht in der
Kopfzeile und nicht in einem Panel, weil die Frage, die er beantwortet, gestellt wird, während man
etwas anderes ansieht.

Er stammt aus zwei Build-Argumenten, `APP_VERSION` und `APP_COMMIT`, die der Release-Workflow aus
der Metadata-Action und dem Commit-SHA einstempelt. Das Image hat kein `.git`, das man fragen könnte
— der Quelltext wird hineinkopiert, und die Historie bleibt draußen —, ein Image, das niemand
gestempelt hat, weiß also ehrlicherweise nicht, was es ist. In diesem Fall fällt `AppVersion` auf
`git describe --tags --always --dirty` zurück, sofern es aus einem Checkout läuft, und meldet
andernfalls **`development`**; dann wird der Chip lieber gar nicht gerendert, als auf jeder Seite
Mobiliar zu zeigen.

Also: Ein Chip bedeutet ein gebautes, getaggtes Image. Kein Chip bedeutet entweder ein lokal
gebautes Image ohne Build-Argumente oder einen Checkout. Beides ist legitim; keines von beidem ist
das, was `docker compose pull` liefert.

**Der typische Fehlerfall ist, dem Tag statt dem Chip zu glauben.** Zwei Images können sich beide
`main` nennen. Der Commit im Chip ist das Einzige, was sie unterscheidet, und er ist der Grund,
warum die Bezeichnung allein nicht genügt.

## pgvector einschalten

Die semantische Suche vergleicht zwei Vektoren mit `plmail_embed_distance()`. Das ausgelieferte
Datenbank-Image hat keine `vector`-Erweiterung, also ist diese Funktion eine plpgsql-Schleife:
korrekt, und rund 0,107 ms pro Vergleich bei 1024 Dimensionen. Über die 2.000 Kandidaten, die eine
Suche betrachtet, sind das etwa 0,63 s Rechenzeit pro semantischer Suche.

pgvector ersetzt die Schleife durch eine SIMD-Variante. Gemessen an 74.000 Zeilen mit 1024
Dimensionen, PostgreSQL 18.4:

| | 20.000 Zeilen | 74.000 Zeilen |
|---|---|---|
| plpgsql-Schleife | 2,14 s | 7,70 s |
| pgvector `<=>` | 1,23 s | 4,51 s |

**1,75× — nicht die Größenordnung, die das Wort „SIMD" nahelegt.** Die Vektoren liegen als `real[]`,
also zahlt jede Zeile ein Detoast und eine Umwandlung `real[]` → `vector`, bevor überhaupt gerechnet
wird, und diese Umwandlung ist der größte Teil der Kosten. Es sind echte 0,63 s → 0,36 s im
semantischen Zweig einer Suche, und es ist nicht das Tausendfache, das ein Index wäre — warum der
Index eine eigene, größere Aufgabe ist, steht in
[den KI-Interna](../internals/ai-assist.md).

Das ist optional. Ohne pgvector wird nichts schlechter, und danach muss nichts neu indiziert werden.

### Warum plMail ein eigenes Image baut, statt `pgvector/pgvector` zu ziehen

Weil dieses Image auf Debian beruht und dieser Stack auf Alpine, und weil das eine gegen das andere
zu tauschen die libc unter einem Datenträger austauscht, auf dem die Datenbank bereits jeden Index
geschrieben hat. Das wurde getestet: Index-Scans liefern falsche Zeilen, bei leerem Protokoll. musl
hinterlegt keine Collation-Version, also ist `datcollversion` `NULL`, also greift Postgres' eigene
Prüfung nie — und jeder collation-abhängige Index, 48 in plMails eigenem Schema, davon 24 `UNIQUE`,
und 84 samt der System-Kataloge, wird
stillschweigend mit dem falschen Vergleicher durchlaufen.

`docker/postgres/Dockerfile` baut `FROM postgres:${POSTGRES_VERSION}-alpine` und fügt pgvector
hinzu. Gleiche libc, gleiche Server-Binaries, gleiches Format auf der Platte; das Image ist 1 MB
größer und sonst identisch. Es gibt nichts neu zu bauen und keinen Dump-and-Restore einzuplanen.

### So geht es

Nimm ab jetzt eine Datei in jeden compose-Befehl auf:

```bash
docker compose -f compose.yaml -f compose.pgvector.yaml pull
docker compose -f compose.yaml -f compose.pgvector.yaml up -d --wait
```

Der erste Lauf kompiliert pgvector — etwa 30 Sekunden, einmal, danach aus dem Cache. **Behalte beide
`-f`-Flags in jedem späteren Befehl.** `docker compose up -d` allein liest nur `compose.yaml` und
setzt das Standard-Image zurück; das ist kein Fehler und erzeugt keine Warnung.

Prüfe, ob es gegriffen hat:

```bash
docker compose -f compose.yaml -f compose.pgvector.yaml exec database \
    psql -U app -d app -c "SELECT extversion FROM pg_extension WHERE extname = 'vector'"
```

### Die Reihenfolge zählt, und genau das ist der Fallstrick

Die Migration, die den Funktionskörper umstellt, fragt die Datenbank, ob pgvector verfügbar ist —
und sie fragt **einmal**, bei dem Start, an dem sie zuerst läuft. Machst du beides in einem Befehl,
findet sie die Erweiterung bereits vor. Aktualisierst du erst und tauschst das Datenbank-Image
nächste Woche, hat sie schon mit „nein" geantwortet, ihre Zeile ins Verzeichnis geschrieben und
schaut nie wieder nach: `migrate` meldet „already at the latest version", und die Schleife bleibt.

Wenn du an diesem Punkt bist, stelle die Frage neu:

```bash
docker compose -f compose.yaml -f compose.pgvector.yaml exec php \
    php bin/console doctrine:migrations:execute --down \
    'DoctrineMigrations\Version20260901120000' --no-interaction
docker compose -f compose.yaml -f compose.pgvector.yaml exec php \
    php bin/console doctrine:migrations:migrate --no-interaction
```

Diese eine Migration zurückzunehmen ist von Bauart sicher: Ihr `down()` installiert die
plpgsql-Schleife, die ohnehin schon installiert ist. Es wird nichts verworfen und keine Daten
angefasst.

### Zurück

Lass `-f compose.pgvector.yaml` weg und fahre den Stack hoch. Die Datenbank kehrt auf demselben
Datenträger zum Standard-Image zurück — wieder dieselbe libc, also wieder nichts neu zu bauen — und
`plmail_embed_distance()` behält den Körper, den sie gerade hat. Eine Funktion, die `<=>` gegen
einen Server aufruft, der diesen Operator nicht mehr hat, scheitert zur Abfragezeit; nimm die
Migration also in derselben Sitzung zurück:

```bash
docker compose exec php php bin/console doctrine:migrations:execute --down \
    'DoctrineMigrations\Version20260901120000' --no-interaction
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

## Zurückrollen

Es gibt keinen Downgrade-Pfad, und der Grund steht im ersten Abschnitt dieser Seite. Die Migrationen
liefen beim Start, bis du weißt, dass du die vorige Version willst, hat sich das Schema also bereits
bewegt.

Jede Migration in diesem Repository liefert zwar ein `down()` mit, eine bewusste Umkehrung mit
`doctrine:migrations:migrate` unter Nennung einer früheren Version ist also *möglich*. Sie ist
selten das, was du willst: `down()` verwirft, was `up()` hinzugefügt hat, also auch die Daten darin,
und der nächste Start eines neueren Images migriert direkt wieder nach oben.

Der unterstützte Weg zurück ist:

1. Fahr den Stack herunter.
2. Stell die Datenbank aus der Sicherung wieder her, die **vor** dem Upgrade angefertigt wurde —
   siehe [Sichern und Wiederherstellen](backup-restore.md).
3. Nagle das Image auf den Tag fest, auf dem du warst: `ghcr.io/karatektus/pl_mail:0.0.15`, nicht
   `latest`.
4. Bring es hoch und bestätige, dass der Chip die festgenagelte Version zeigt.

Alles, was zwischen der Sicherung und dem Zurückrollen passiert ist, ist weg — das ist der ehrliche
Preis und der Grund, unmittelbar vor einem Upgrade eine Sicherung anzufertigen und nicht bloß
nächtlich.

**Der typische Fehlerfall ist, den alten Tag zu ziehen und sonst nichts.** Der alte Code startet
gegen eine Datenbank, die ihm voraus ist: Doctrine meldet Migrationsversionen, von denen es nie
gehört hat, und die Anwendung fragt ein Schema ab, das für eine Version geformt ist, die sie nicht
ist. Nichts davon lässt sich aus dem Symptom heraus diagnostizieren.

## Fallstricke

**Ein neues Image zu starten heißt, seine Migrationen anzuwenden.** Es gibt keinen getrennten
Schritt, keine Bestätigung und keinen Probelauf. Sichere vorher; die gesamte Seite
[Sichern und Wiederherstellen](backup-restore.md) ist die Voraussetzung für diese hier.

**`latest` bewegt sich nur bei Veröffentlichungen, `main` bei jedem Merge.** Wählst du `main`, um
"aktuell zu bleiben", lässt du dich auf unveröffentlichte Schemaänderungen ein — auf einem Stack,
der automatisch migriert.

**Eine fehlgeschlagene Migration ist ein Container, der nicht startet, keine kaputte Anwendung.** Das
ist so beabsichtigt. Lies die Logs des Containers, der den Lock *hielt*; die anderen sagen nur, dass
sie gewartet haben.

**Bei einem ungestempelten Build fehlt der Versions-Chip, er steht nicht auf "unbekannt".** Ein
Checkout, der nie aus einem Tag gebaut wurde, hat keine Version, und das schlicht zu sagen ist
besser als ein Wort, das klingt, als sei etwas schiefgegangen. Lies einen fehlenden Chip nicht als
kaputte Installation.

**Worker halten Doctrines Metadaten für ihre gesamte Lebensdauer im Cache.** Alles, was das Schema
ändert, ohne die Container neu zu erzeugen, braucht anschließend Administration → System → Worker
neu starten, sonst fragen die Worker weiter nach Spalten, die es nicht mehr gibt.

**Über pgvector wird einmal entschieden, bei dem Start, der seine Migration zuerst ausführt.** Das
Datenbank-Image danach zu tauschen ändert von sich aus nichts — die zwei Befehle, die die Frage neu
stellen, stehen unter [pgvector einschalten](#pgvector-einschalten).

**`-f compose.pgvector.yaml` wegzulassen setzt das Datenbank-Image stillschweigend zurück.** Compose
warnt nicht, wenn ein Dienst, den es neu erzeugt, aus einer Datei stammt, die du nicht mehr
übergeben hast. Ist `plmail_embed_distance()` dann noch der pgvector-Körper, scheitert jede
semantische Suche an einem fehlenden Operator.

**`POSTGRES_VERSION` ist nichts, was man während eines Upgrades nebenbei anhebt.** Das Image liefert
`postgresql-client-18` mit, und `pg_dump` weigert sich, einen Server zu sichern, der neuer ist als
es selbst — ein über die Client-Version hinaus angehobenes Postgres macht also `app:backup` in dem
Moment kaputt, in dem du es das nächste Mal brauchst.
