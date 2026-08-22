<!-- translated-from: install/docker.md sha1:76b39de6d79bca245417628dafa8d329a76f4ba7 -->
# Installation mit Docker Compose

Der unterstützte Weg, von Anfang bis Ende: was du brauchst, was `docker compose up` tatsächlich tut,
wie der erste Administrator angelegt wird und wofür jeder einzelne Container da ist.

Alles Folgende geht von der unveränderten `compose.yaml` aus dem Repository aus, die das
veröffentlichte Image zieht. Ein Build aus dem Quelltext ist ein Entwicklungs-Setup und gehört nach
[CONTRIBUTING](../../CONTRIBUTING.md#development-setup).

## Was du brauchst

- Eine Maschine mit Docker und Docker Compose.
- Sonst nichts. Vor dem ersten Start ist keine Konfiguration auszufüllen.

Images werden für `linux/amd64` und `linux/arm64` veröffentlicht, jeweils auf einem nativen Runner
gebaut und zu einem einzigen Manifest zusammengeführt. Ein Mac mit Apple Silicon, ein ARM-NAS oder
ein 64-Bit-Raspberry-Pi führt plMail also ohne Emulation aus. Je nach Maschine unterscheiden sich
einige Details — siehe [Plattform-Hinweise](platforms.md).

Über die Dimensionierung lohnt es sich nachzudenken, eine feste Regel gibt es nicht. PHPs
`memory_limit` steht im Image auf `2G`, jeder der vier Messenger-Worker ist durch
`--memory-limit=256M` begrenzt und startet sich bei `--time-limit=3600` neu, und Postgres, der
Mercure-Hub und der IMAP-Supervisor wollen jeweils ihren eigenen Anteil obendrauf. Der erste
Abgleich eines großen Postfachs ist die anstrengendste Phase überhaupt.

Das genügt für IMAP-Postfächer. Gmail und Outlook brauchen zusätzlich OAuth-Zugangsdaten — siehe
[Google](../providers/google.md) und [Microsoft](../providers/microsoft.md).

**Der typische Fehlerfall ist, einen Mailserver zu erwarten.** plMail ist ein Client. Es empfängt
keine Mail aus dem Internet, hostet deine Domain nicht und braucht nirgends Port 25.

## Beschaffen

```bash
git clone https://github.com/karatektus/pl_mail.git
cd pl_mail
```

Der Clone dient allein der `compose.yaml` — der Anwendungscode liegt im Image. Wenn du lieber nicht
klonen möchtest, genügt es, nur die `compose.yaml` zu kopieren, sofern du daneben eine `.env`
anlegst oder jede Vorgabe aus der [Konfigurationsreferenz](configuration.md) akzeptierst.

**Der typische Fehlerfall ist eine herumliegende `compose.override.yaml`.** Compose lädt diese Datei
automatisch, sobald sie existiert, und die aus diesem Repository schaltet jeden Dienst vom
veröffentlichten Image auf ein lokal gebautes Entwicklungs-Image mit eingehängtem Quelltext um.
Genau deshalb ist sie nicht versioniert; eingecheckt ist nur `compose.override.yaml.dist`. Kopiere
sie nicht, außer du entwickelst.

## Starten

```bash
docker compose up -d
```

Öffne dann [https://localhost](https://localhost).

**Schließe die Einrichtung ab, bevor du irgendetwas anderes machst.** Solange es noch keinen Nutzer
gibt, steht `/install` jedem offen, der die Instanz erreicht — genau so legst du dein eigenes Konto
an, und genau so wird jemand anderes zum Administrator, wenn er schneller da ist. Das ist der
übliche Handel bei selbst gehosteter Software, und das Fenster ist real: die README empfiehlt einen
Reverse-Proxy davor, und TrueNAS gibt Port `30080` nach außen. Leg das Konto an, dann ist das
Fenster zu.

Der erste Start dauert länger als spätere, und er führt eine bestimmte Abfolge von Schritten aus,
die du kennen solltest:

1. **`secrets-init` läuft und beendet sich.** Es erzeugt `APP_SECRET`, `APP_ENCRYPTION_KEY`,
   `POSTGRES_PASSWORD` und `MERCURE_JWT_SECRET` in `var/secrets/generated.env` auf dem gemeinsamen
   Volume `app_secrets`, dazu eine nackte Datei `postgres_password`. Es läuft vor allem anderen,
   weil Postgres und Mercure ihre Geheimnisse beim Anlegen des Containers lesen und nicht darauf
   warten können, dass die Anwendung sie ihnen reicht. Bei jedem späteren Start findet es die Datei
   vor und tut nichts.
2. **`database` startet** und liest sein Passwort über `POSTGRES_PASSWORD_FILE`. Jeder
   Anwendungsdienst wartet auf dessen Healthcheck, der eine Startphase von 60 Sekunden zugesteht.
3. **Jeder Anwendungscontainer führt denselben Entrypoint aus.** Er lädt die erzeugten Geheimnisse,
   setzt `DATABASE_URL` aus `POSTGRES_PASSWORD` zusammen — es sei denn, du hast eine DSN mit einem
   eigenen Passwort mitgegeben —, wartet bis zu 60 Versuche lang auf die Datenbank und führt dann
   `app:db:migrate` aus: Doctrines Migrate unter einem Postgres-Advisory-Lock, damit die sechs
   gemeinsam startenden Container nicht kollidieren.
4. **`app:secrets:init` läuft**, nach den Migrationen. Es prüft, ob der geltende
   Verschlüsselungsschlüssel die bereits gespeicherten Zugangsdaten entschlüsseln kann, und erzeugt
   anschließend ein VAPID-Schlüsselpaar sowie das JMAP-JWT-Schlüsselpaar, falls diese fehlen.
5. **Caddy nimmt den Betrieb auf.** Der `php`-Container veröffentlicht 80/tcp, 443/tcp und 443/udp —
   umlegbar über `HTTP_PORT`, `HTTPS_PORT` und `HTTP3_PORT` — und bedient die Namen aus
   `SERVER_NAME`, das `compose.yaml` auf `localhost, php:80` setzt. Außerdem leitet er
   `/.well-known/mercure*` an den Hub-Container weiter, sodass der Browser den Hub auf derselben
   Origin erreicht und kein CORS nötig ist.

`docker compose up -d --wait` blockiert, bis die Healthchecks bestehen — in einem Skript die bessere
Form.

**Der typische Fehlerfall ist, die Logs des falschen Containers zu lesen.** Sechs Dienste laufen mit
demselben Image, und alle sechs migrieren beim Start; die, die das Rennen um den Advisory-Lock
verloren haben, geben "another container is running migrations" aus — sie sind nicht die, die du
untersuchen willst.

## Den ersten Administrator anlegen

Öffne die Anwendung, dann bietet sie an, einen anzulegen. Die Seite `/install` fragt nach Namen,
Adresse, Passwort und der öffentlichen URL, unter der plMail erreichbar sein wird — vorbelegt mit
der Adresse, unter der du die Seite geöffnet hast, was immer dann richtig ist, wenn du sie so
erreichst wie später alle anderen auch.

Dasselbe vom Terminal aus:

```bash
docker compose exec php php bin/console app:setup
```

`/install` ist unauthentifiziert, weil es niemanden gibt, den man authentifizieren könnte. Geschützt
ist die Seite durch genau eine Bedingung — die Installation hat keine Benutzer —, geprüft beim
Rendern der Seite, beim Absenden des Formulars und ein drittes Mal innerhalb des gesperrten
Schreibvorgangs. In dem Moment, in dem ein Benutzer existiert, antwortet die Seite dauerhaft mit
404.

Die öffentliche URL, die du angibst, wird nach `var/secrets/generated.env` geschrieben und nicht in
eine Konfigurationsdatei, weil ein dauerhaft laufender Worker, der ein Push-Abonnement aufbaut,
keine Anfrage hat, aus der er einen Hostnamen ableiten könnte. Das Speichern fordert außerdem jeden
Worker zum Neustart auf, damit er den Wert übernimmt, statt bis zu seinem nächsten Recycling am
alten festzuhalten. Ein über die Umgebung gesetztes `APP_PUBLIC_URL` gewinnt gegen das, was der
Bildschirm gespeichert hat — eine Installation, die es setzt, bleibt davon also unberührt.

**Der typische Fehlerfall ist, die Frage nach der öffentlichen URL mit `https://localhost` zu
beantworten.** Das wird akzeptiert, und es ist die Vorbelegung, wenn du plMail auf der Maschine
einrichtest, auf der es läuft — aber Google und Microsoft werden eine Loopback-Adresse nie
erreichen, also bleibt Push für Mail und Kalender aus. Korrigiere es später über die Umgebung, oder
rufe die Einrichtungsseite über die Adresse auf, die du tatsächlich verwenden willst.

## Wofür jeder Container da ist

| Dienst | Was darin läuft | Warum er ein eigener Container ist |
|---|---|---|
| `secrets-init` | `generate-secrets`, dann Ende | Postgres und Mercure brauchen ihre Geheimnisse beim Anlegen des Containers, bevor die Anwendung existiert |
| `php` | FrankenPHP, das die Anwendung ausliefert | Der einzige Anwendungsdienst mit HTTP-Server und damit der einzige, dessen Image-Healthcheck aktiv bleibt — die übrigen schalten ihn ab und melden Lebendigkeit stattdessen über Heartbeats |
| `database` | `postgres:18-alpine` mit vorgeladenem `pg_stat_statements` | — |
| `mercure` | Der Mercure-Hub | Live-Aktualisierungen — die Mailliste, die sich von selbst auffrischt |
| `imap-supervisor` | `app:imap:supervise` | Startet und überwacht je einen `app:imap:idle`-Prozess pro IDLE-fähigem Postfach, damit gewöhnliche IMAP-Mail in dem Moment ankommt, in dem sie eintrifft |
| `worker-export` | `messenger:consume export` | Alles, was plMail verlässt, und die einzige Warteschlange, auf die jemand wartet. In einem eigenen Prozess, damit ein Versand nie hinter einem Abgleich steht |
| `worker-ingest` | `messenger:consume ingest` | Eingehende Mail und die Arbeit, die unmittelbar darauf folgt |
| `worker-maintenance` | `messenger:consume maintenance async` | Nachträgliche Verarbeitungen, Regelläufe über vorhandene Mail, administrative Durchläufe. Leert außerdem die stillgelegte Warteschlange `async` |
| `scheduler` | `messenger:consume scheduler_default` | Löst alles Wiederkehrende aus. **Ohne diesen Container plant sich nichts von selbst** |
| `ntfy` | ntfy, unter dem Profil `push` | Optional. Android-Push ohne Google — starte ihn mit `docker compose --profile push up -d` |

Drei Prozesse statt drei Transports in einem Worker, weil ein Worker, der bereits in einem langen
Handler steckt, nichts anderes mehr annehmen kann, wie auch immer die Warteschlangen priorisiert
sind. Genau das war das ursprüngliche Problem: Ein Klick auf Senden wartete hinter einem
Gmail-Batch.

**Der typische Fehlerfall ist, den Dienst `scheduler` wegzulassen.** Ohne ihn wird überhaupt nichts
Wiederkehrendes ausgelöst — kein Abgleich per Polling, kein Aufwachen zurückgestellter
Konversationen, kein Kalenderabgleich, keine Erinnerungen, kein Aufräumen — und nirgends erscheint
ein Fehler, weil nichts fehlgeschlagen ist. `php bin/console debug:scheduler` listet auf, was laufen
sollte.

## Speicher, und was die Standarddatei nicht dauerhaft ablegt

Die Compose-Datei deklariert zehn benannte Volumes:

| Volume | Enthält |
|---|---|
| `app_secrets` | `generated.env`, `postgres_password`, das JWT-Schlüsselpaar. Von **jedem** App-Dienst eingehängt, in `database` und `mercure` nur lesend |
| `app_attachments`, `app_raw`, `app_uploads` | Anhänge, Rohnachrichten und zwischengelagerte JMAP-Uploads. Von jedem App-Dienst eingehängt, denn die Worker schreiben sie und der Web-Container liefert sie aus |
| `database_data` | Der PostgreSQL-Cluster |
| `caddy_data`, `caddy_config` | Caddys TLS-Material und Zustand |
| `mercure_data`, `mercure_config` | Zustand des Hubs |
| `ntfy_data` | Zustand der Benachrichtigungs-Topics |

Die drei Blob-Volumes fehlten bis vor Kurzem, und der Fehler war auf lehrreiche Weise lautlos. Das
Dockerfile hat bewusst kein `VOLUME /app/var/` — ein anonymes Volume dort gab jedem Container seine
eigene Kopie —, die dauerhaften Pfade werden also pro Dienst deklariert. Die beiden
Deployment-Dateien, die tatsächlich jemand bearbeitet hat, `compose.override.yaml.dist` und
`truenas.compose.yaml`, deklarierten sie. Die Standarddatei `compose.yaml`, also genau die Datei,
zu der die README greifen lässt, nicht. Anhänge, die ein Sync-Worker schrieb, waren damit für den
Web-Container unsichtbar, der den Download ausliefern sollte, und beide Kopien starben beim
nächsten `docker compose up`, das einen Container neu anlegte. Nichts warf einen Fehler: Mail kam
an, die Liste zeichnete sich, und der Download lieferte 404.

**Wenn du eine Standard-`compose.yaml` von vor dieser Korrektur betreibst, lies den
Deployment-Hinweis in
[CHANGELOG.md](https://github.com/karatektus/pl_mail/blob/main/CHANGELOG.md), bevor du ziehst.**
Ein leeres Volume über ein Verzeichnis zu hängen verdeckt, was darin liegt — gelöscht wird nichts,
aber die Dateien sind nicht mehr sichtbar, bis sie herüberkopiert sind.

`truenas.compose.yaml` geht einen anderen Weg: Es setzt `APP_STORAGE_DIR=var/data` und bindet ein
einziges Host-Verzeichnis an `/app/var/data`, sodass Anhänge, Rohnachrichten, Uploads und die
Geheimnisse alle unter einem Pfad landen — eine Sache zum Snapshotten und eine zum Sichern.

## Alltagsbetrieb

```bash
docker compose ps                  # what is up
docker compose logs -f php         # the web container
docker compose logs -f scheduler   # the recurring jobs
docker compose exec php php bin/console list
```

Als Administrator angemeldet findest du die Zahlen unter **Administration**: welche Worker leben,
wie tief die Warteschlangen sind, der Push-Zustand pro Konto, die Datenbankgröße und ein
durchsuchbares Protokoll. Siehe [Administration](../features/admin.md).

Die Konsolenbefehle sind in [CONTRIBUTING](../../CONTRIBUTING.md#console-commands) aufgeführt; die,
zu denen man beim Betrieb am häufigsten greift, sind `app:backup`, `app:user:promote`,
`app:user:2fa-disable` und `app:mail:test-connection`.

**Der typische Fehlerfall ist, einen Konsolenbefehl in einem Container auszuführen, der die
Geheimnisse nicht sieht.** `docker compose exec php …` ist in Ordnung —
`config/bootstrap_generated_secrets.php` lädt die erzeugte Datei genau für diesen Fall. Ein blankes
`docker run` gegen das Image ist es nicht.

## Wie es weitergeht

- Postfächer hinzufügen: [IMAP und SMTP](../providers/imap-smtp.md), [Google](../providers/google.md),
  [Microsoft](../providers/microsoft.md), [CalDAV](../providers/caldav.md)
- Von außen erreichbar machen: [Hinter einem Reverse-Proxy](reverse-proxy.md)
- Alles Konfigurierbare: [Konfigurationsreferenz](configuration.md)
- Bevor echte Mail darin liegt: [Sichern und Wiederherstellen](backup-restore.md)

## Fallstricke

**`docker compose up` liest `compose.override.yaml` mit, ob du das wolltest oder nicht.** Eine aus
einem Entwicklungsexperiment übrig gebliebene Kopie schaltet den gesamten Stack stillschweigend auf
ein lokal gebautes Image ohne einkompilierten Anwendungscode um — der Entrypoint fällt dann in
seinen `symfony/skeleton`-Zweig, und der Container gerät in eine Absturzschleife.

**Jeder Anwendungsdienst muss `app_secrets` einhängen.** Ein Dienst ohne diesen Mount erzeugt sich
ein eigenes `APP_ENCRYPTION_KEY` und kann fortan nicht mehr lesen, was die anderen geschrieben
haben. `EncryptionKeyProbe` bemerkt das beim Start und verweigert den Start des Servers, statt
Konten zu speichern, die sonst niemand lesen kann.

**`compose.prod.yaml` baut aus dem Quelltext und verlangt `APP_SECRET`.** Es setzt
`target: frankenphp_prod` und `APP_SECRET: ${APP_SECRET}` ohne Vorgabewert, scheitert also
unmittelbar, wenn diese Variable nicht gesetzt ist — und sie zu setzen schaltet die Erzeugung dieses
Werts ab. Die Datei existiert für Leute, die ihr eigenes Image bauen, nicht als der normale Weg.

**Ein erster Abgleich stellt Tausende Jobs in die Warteschlange, und das ist kein Fehler.**
`/healthz` bewertet die Warteschlange erst jenseits von 5000 wartenden Nachrichten als überlastet —
gerade damit ein legitimer erster Abgleich keine Störung meldet. Siehe
[Fehlersuche](troubleshooting.md).

**Migrationen laufen bei jedem Start automatisch.** Das ist Absicht, und es hat Folgen für das
Zurückrollen — lies [Aktualisieren](upgrading.md), bevor du ein neues Image ziehst.
