<!-- translated-from: install/upgrading.md sha1:63d7f67ccfcd3af64bfc2b3b8399fef1a36352c7 -->
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

**`POSTGRES_VERSION` ist nichts, was man während eines Upgrades nebenbei anhebt.** Das Image liefert
`postgresql-client-18` mit, und `pg_dump` weigert sich, einen Server zu sichern, der neuer ist als
es selbst — ein über die Client-Version hinaus angehobenes Postgres macht also `app:backup` in dem
Moment kaputt, in dem du es das nächste Mal brauchst.
