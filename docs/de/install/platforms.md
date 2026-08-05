<!-- translated-from: install/platforms.md sha1:727c18329d917d9982252fd27e182991b46a96e2 -->
# Plattform-Hinweise

plMail führt überall dieselben Container aus, deshalb geht es auf dieser Seite fast nur um die
beiden Dinge, die sich zwischen einem Linux-Server, einem Mac, Windows über WSL2 und einem NAS
tatsächlich unterscheiden: **wem die Dateien hinter einem Bind-Mount gehören** und **wie viel
Arbeitsspeicher der Docker-Host dem Stack wirklich zugesteht**. Fang mit
[Docker Compose](docker.md) an; hier stehen nur die Abweichungen.

Images werden für `linux/amd64` und `linux/arm64` als ein einziges Manifest veröffentlicht, jede der
folgenden Plattformen führt plMail also nativ aus. Es gibt keinen QEMU-Schritt einzuplanen, weder
auf einem Mac mit Apple Silicon noch auf einem ARM-NAS.

## Was der Stack vom Host verlangt

Die Standarddatei startet acht dauerhaft laufende Container — den Webserver, den IMAP-Supervisor,
vier Messenger-Worker, Postgres und den Mercure-Hub — dazu `secrets-init`, das sich wieder beendet.
Unter dem optionalen Profil `push` kommt ein neunter hinzu, ntfy.

Die Zahlen, die tatsächlich irgendwo festgeschrieben sind: PHPs `memory_limit` steht in
`frankenphp/conf.d/10-app.ini` auf `2G`, jeder Messenger-Worker ist durch `--memory-limit=256M`
begrenzt und startet sich bei `--time-limit=3600` neu, `upload_max_filesize` ist `25M` und
`post_max_size` `60M`. Postgres läuft mit `shared_preload_libraries=pg_stat_statements` und im
Übrigen mit dem, was die Vorgaben des Images ihm geben.

**Der typische Fehlerfall ist ein Host, der Speicher überbucht und dann per OOM tötet.** Ein Worker,
der mitten im Handler getötet wird, hinterlässt seine Nachricht zur erneuten Zustellung — das ist
verkraftbar; der Container, der beim Halten des Migrations-Advisory-Locks getötet wird, ist es
nicht: Jeder andere Container wartet die fünf Minuten Lock-Timeout ab und verweigert danach die
Migration.

## Linux-Server

Der unkomplizierte Fall und der, für den die Standard-`compose.yaml` geschrieben ist: Jeder
dauerhafte Pfad ist ein **benanntes Volume**, das Docker anlegt und besitzt, es gibt also nichts zu
`chown`en und keine Eigentümerfrage zu beantworten.

Die Container laufen intern als root. Der Entrypoint versucht, POSIX-ACLs auf `var/` zu setzen
(`setfacl -R -m u:www-data:rwX …`), und behandelt ein Scheitern als Hinweis statt als Fehler — als
root kann er ohnehin alles unterhalb von `var/` schreiben.

Zwei Dinge ändern sich in dem Moment, in dem du ein benanntes Volume gegen einen **Bind-Mount**
tauschst, was du tun wirst, wenn die Mail auf einer bestimmten Platte liegen soll:

- **Postgres läuft als uid 999** in `postgres:18-alpine` — in älteren Hauptversionen war es uid 70.
  Es kann sein Datenverzeichnis nicht in einem root-eigenen Elternverzeichnis anlegen, dieses
  Elternverzeichnis muss also mit den richtigen Besitzrechten existieren, bevor der Container
  startet. `truenas.compose.yaml` löst das, indem `secrets-init` das Postgres-Unterverzeichnis
  anlegt und per `chown -R 999:999` überträgt, bevor irgendetwas anderes läuft; dieses Muster lässt
  sich auf jede Bind-Mount-Installation übertragen.
- **ACLs werden womöglich nicht unterstützt.** Auf ZFS, NFS oder allem, was NFSv4-ACLs verwendet,
  scheitert `setfacl` mit "Operation not supported". Der Entrypoint gibt einen Hinweis aus und macht
  weiter — früher brach er den Start ab, unter `set -e`, weshalb dieser Fehler heute ausdrücklich
  behandelt wird.

**Der typische Fehlerfall ist ein Bind-Mount, dessen Elternverzeichnis Docker als root angelegt
hat.** Postgres beendet sich während der Initialisierung mit einem Rechtefehler, der einen Pfad
nennt, und die Anwendungscontainer verbrauchen anschließend ihre 60 Datenbankversuche im Warten auf
etwas, das nie hochkommen wird.

## Windows, über WSL2

Docker Desktop mit dem WSL2-Backend führt dieselben Linux-Container aus, am Stack ändert sich also
nichts. Zwei Details auf der Host-Seite schon.

**Lass das Projekt innerhalb des WSL2-Dateisystems.** Ein Pfad unterhalb von `/home/...` in der
Distribution liegt auf einem nativen Linux-Dateisystem; ein Pfad unterhalb von `/mnt/c` liegt auf
dem Windows-Dateisystem, erreicht über eine Übersetzungsschicht, mit anderer Leistung und anderer
Semantik für Dateisperren und Besitzrechte. Benannte Volumes umgehen die Frage vollständig, was ein
weiteres Argument dafür ist, die Volumes der Standarddatei unangetastet zu lassen.

**Der Arbeitsspeicher gehört der WSL2-VM, nicht Windows.** Die Werte aus "Was der Stack vom Host
verlangt" weiter oben stammen aus dem, was WSL2 sich nehmen darf; konfiguriert wird das in
`.wslconfig` und nicht in Docker. Ein Stack, der sich verhält, als wäre die Maschine viel kleiner,
als sie ist, verweist meist auf diese Datei.

Wenn du zusätzlich die Browser-Testsuite von Windows aus ausführen willst: Einem Lauf zuzusehen
erfordert WSLg unter Windows 11 oder einen X-Server; ohne beides nimm die Variante mit
Trace-Aufzeichnung und sieh dir die Aufnahme hinterher an. Das betrifft ausschließlich die
Entwicklung — [CONTRIBUTING](../../CONTRIBUTING.md#tests) behandelt es.

**Der typische Fehlerfall ist, einen Windows-Pfad in die Container einzuhängen.** Es sieht aus, als
funktioniere es, und dann weicht das darunterliegende Sperrverhalten von dem ab, was der Entrypoint
erwartet — dieselbe Klasse von Problem, die `flock` unter macOS als Mutex für die
Abhängigkeitsinstallation unbrauchbar gemacht hat.

## macOS

Apple Silicon zieht das `linux/arm64`-Image und führt es nativ aus.

Der relevante Unterschied ist wiederum das Dateisystem hinter einem Bind-Mount. Docker Desktop teilt
Host-Verzeichnisse über VirtioFS, und zwei Punkte dazu sind in diesem Repository dokumentiert, weil
sie gemessen und nicht vermutet wurden:

- `setfacl` scheitert auf einer VirtioFS-Freigabe, der ACL-Schritt des Entrypoints wird also mit
  einem Hinweis übersprungen.
- `flock` ist beratend und schließt auf einem solchen Mount *containerübergreifend* nicht
  zuverlässig aus. Die Abhängigkeitsinstallation des Entwicklungs-Entrypoints benutzt deshalb
  `mkdir` als Mutex, weil das eine einzelne atomare Operation ist, die mit `EEXIST` fehlschlägt.
  Vorher wurde beobachtet, wie zwei Container einen durch `flock` geschützten Block im Abstand von
  17 ms betraten.

Keines von beidem betrifft das veröffentlichte Image, das `vendor/` einbackt und benannte Volumes
verwendet — beides zählt, wenn du einen Quelltextbaum einhängst, und das ist das Entwicklungs-Setup.

Wie unter Windows ist der verfügbare Arbeitsspeicher der der VM, konfiguriert in Docker Desktop, und
nicht der des Macs insgesamt.

**Der typische Fehlerfall ist die Annahme, ein Bind-Mount verhalte sich wie eine lokale Platte.**
Beim Lesen und Schreiben tut er das; bei Sperren, ACLs und Besitzrechten nicht, und jeder dieser
Punkte hat hier bereits etwas kaputtgemacht.

## NAS-Geräte

Ein NAS ist genau die Art Maschine, für die plMail gedacht ist, und das Repository liefert
`truenas.compose.yaml` als ausgearbeitetes Beispiel mit — lies es auch dann, wenn dein NAS kein
TrueNAS ist, denn jede Entscheidung darin ist eine, vor der du ebenfalls stehen wirst.

**Ports.** Ein NAS, dessen eigene Verwaltungsoberfläche bereits 80 und 443 belegt, verlangt, dass
plMail umzieht. Setze `HTTP_PORT` und `HTTPS_PORT` (und `HTTP3_PORT`, falls du HTTP/3 direkt
ausliefern willst), oder mach es wie die TrueNAS-Datei: Gib dem Dienst `php`
`SERVER_NAME: ":80"`, damit FrankenPHP schlichtes HTTP ausliefert, leg das auf einen hohen Port —
dort `30080` — und terminiere TLS davor. Siehe [Hinter einem Reverse-Proxy](reverse-proxy.md).

**Ein Verzeichnis statt sechs.** `truenas.compose.yaml` setzt `APP_STORAGE_DIR=var/data` und legt
`APP_SECRETS_DIR` und `APP_SECRETS_FILE` in denselben Baum, um dann auf jedem Dienst einen einzigen
Host-Pfad auf `/app/var/data` zu binden. Das Ergebnis ist ein einziges Verzeichnis mit den
Geheimnissen, Anhängen, Rohnachrichten, Uploads und dem Postgres-Cluster — eine Sache zum
Snapshotten, eine Sache zum Sichern, und jeder Dienst sieht nachweislich denselben
Verschlüsselungsschlüssel. Richte es auf ein Dataset statt auf ein einfaches Verzeichnis, wenn du
Snapshots deiner Mail willst.

**Wieder Besitzrechte.** Das `secrets-init` jener Datei legt die Unterverzeichnisse an, überträgt
das Postgres-Verzeichnis per `chown -R 999:999` und tut dann etwas Nachahmenswertes:

```sh
chmod o+x /app/var/data || setfacl -m u:999:--x /app/var/data || true
```

Ein mit der TrueNAS-Voreinstellung "Apps" angelegtes Dataset ist `770 apps:apps`, was uid 999 keine
Möglichkeit gibt, in das Verzeichnis zu wechseln, in dem ihre eigenen Daten liegen. Ein reines
Ausführungsrecht gewährt genau dieses Wechseln, ohne das Auflisten oder Lesen von irgendetwas zu
erlauben.

**Interpolation.** Der YAML-Installer von TrueNAS unterstützt `${VAR}` nicht, weshalb jene Datei
jede Einstellung in einen `x-config`-Block hebt und sie über YAML-Anker referenziert. Hat die
App-Oberfläche deines NAS dieselbe Einschränkung, gilt derselbe Trick; führt sie echtes
`docker compose` aus, sind die Standarddatei und eine `.env` daneben einfacher.

**Image-Tags zählen hier mehr**, weil eine NAS-App-Seite meist einen "Redeploy"-Knopf und keine
sichtbare Version hat. `truenas.compose.yaml` setzt `pull_policy: always`, ein Redeploy holt sich
also einen neueren Build des jeweils gesetzten Tags. Wähle den Tag bewusst — siehe
[Aktualisieren](upgrading.md).

**Android-Push ohne Google** ist der optionale Container, dessen Start sich auf einem NAS am meisten
lohnt: `docker compose --profile push up -d` bringt ntfy hoch, dem die Endpunkt-URL gehört, mit der
ein UnifiedPush-Distributor auf dem Telefon spricht. Seine Basis-URL wird aus `SERVER_NAME`
abgeleitet und in jeden ausgegebenen Endpunkt eingebacken; `NTFY_BASE_URL` richtig zu setzen, bevor
du die Adresse an Telefone gibst, erspart dir also, später jedes Gerät neu zu registrieren.

**Der typische Fehlerfall ist eine NAS-App-Oberfläche, die verbirgt, welche Container es gibt.** Zu
prüfen ist `scheduler`. Eine Installation ohne ihn sieht vollkommen gesund aus und pollt still nie,
weckt nie eine zurückgestellte Konversation, gleicht nie einen Kalender ab und löst nie eine
Erinnerung aus.

## Fallstricke

**Nur auf einigen dieser Plattformen bekommst du benannte Volumes geschenkt.** Die Standard-
`compose.yaml` verwendet sie, und ihre Blob-Verzeichnisse gehören nicht dazu — siehe
[den Speicherabschnitt der Docker-Seite](docker.md#storage-and-what-the-stock-file-does-not-persist).
Auf einem NAS wirst du mit ziemlicher Sicherheit stattdessen Bind-Mounts verwenden, und dann sind
die Besitzrechte deine Sache.

**Postgres läuft nicht in jedem Image unter uid 999.** Vor der 18er-Reihe war es uid 70. Ein aus
einer älteren Anleitung übernommenes `chown` hinterlässt einen Cluster, der nicht schreiben kann.

**ACL-Fehlschläge sind Hinweise, keine Fehler — aber erst, seit sie dazu gemacht wurden.** Wenn du
"POSIX ACLs are not supported on this filesystem; skipping setfacl for var/" in den Logs siehst, ist
das die erwartete Ausgabe auf ZFS, NFS und Docker-Desktop-Freigaben, und es ist nichts falsch.

**ARM ist ein erstklassiges Ziel und muss es bleiben.** Beide Architekturen werden auf nativen
Runnern gebaut und zu einem Manifest zusammengeführt; alles, was du einem selbst gebauten Image
hinzufügst, muss also auch ein arm64-Artefakt auflösen.

**Ein Stack, der monatelang gut läuft und dann bei einem ersten Abgleich stehen bleibt, hat ein
Speicherproblem.** Der erste Abgleich eines großen Postfachs ist die anstrengendste Phase, die
plMail je erlebt: Er stellt Tausende Jobs in die Warteschlange, vier Worker arbeiten gleichzeitig
daran, und jeder darf 256M belegen, bevor er sich neu startet.
