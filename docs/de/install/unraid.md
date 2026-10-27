<!-- translated-from: install/unraid.md sha1:4dcbe514ddc1777c782d00c1dbd6ecc8e8842f05 -->
# Unraid

Auf Unraid läuft plMail als **ein Container** neben einem **Postgres-Container**, so wie Unraid-Apps
üblicherweise kommen: Community Applications installiert pro Vorlage einen Container, und eine
Datenbank ist eine eigene App. Alles andere, was plMail betreibt (der Webserver, die
Warteschlangen-Worker, der Scheduler, der IMAP-Supervisor und der Mercure-Hub), lebt im
plMail-Container. Das ist der All-in-one-Modus des Images, eingeschaltet mit `APP_ALL_IN_ONE=1`.
Image und Tags sind dieselben wie bei [Docker Compose](docker.md); anders ist nur, wie es gestartet
wird.

## Installieren

1. **Zuerst Postgres.** Installiere einen PostgreSQL-18-Container aus Community Applications (oder
   das offizielle Image `postgres:18` von Hand). Lege eine Datenbank, einen Benutzer und ein
   Passwort fest und hänge ihn ins selbe Docker-Netz wie plMail. In einem eigenen Bridge-Netz
   erreicht plMail ihn über den Containernamen; auf der Standard-Bridge nimmst du stattdessen die
   IP-Adresse des Unraid-Servers und den Postgres-Port.
2. **plMail.** Installiere plMail aus Community Applications und trag `DATABASE_URL` ein:

   ```
   postgresql://plmail:<passwort>@<postgres-container oder host>:5432/plmail?serverVersion=18&charset=utf8
   ```

   Das Passwort ist das einzige Geheimnis, das du eintippst. Alles andere (`APP_SECRET`, der
   Verschlüsselungsschlüssel, der Mercure-Schlüssel, die Schlüsselpaare für Push und Tokens) wird
   beim ersten Start in `/mnt/user/appdata/plmail/secrets` erzeugt.
3. Öffne `http://<server>:30519/` und geh die Einrichtung durch.

Ohne Vorlage derselbe Container von der Kommandozeile:

```sh
docker run -d --name plmail \
  --init --restart=unless-stopped --stop-timeout=35 \
  -p 30519:80 \
  -v /mnt/user/appdata/plmail:/app/var/data \
  -e APP_ALL_IN_ONE=1 \
  -e APP_STORAGE_DIR=/app/var/data \
  -e APP_SECRETS_DIR=/app/var/data/secrets \
  -e APP_SECRETS_FILE=/app/var/data/secrets/generated.env \
  -e DATABASE_URL='postgresql://plmail:<passwort>@postgres:5432/plmail?serverVersion=18&charset=utf8' \
  ghcr.io/karatektus/pl_mail:latest
```

## Was im Container läuft

`app:work` ist der Hauptprozess des Containers und hält alles andere am Laufen: den Webserver
(FrankenPHP, einfaches HTTP auf Port 80 im Container), die Warteschlangen-Worker, den Scheduler,
den IMAP-Supervisor und den Mercure-Hub. Ein Kindprozess, der endet, wird wieder gestartet: nach
einem sauberen Ende sofort, nach einem Absturz mit einer Wartezeit, die bei einer Sekunde beginnt
und sich bis dreißig verdoppelt. Endet `app:work` selbst, endet auch der Container, und Dockers
Neustart-Richtlinie startet ihn wieder.

Der Hub lauscht im Container auf Port 3000, den nichts veröffentlicht; der Webserver leitet
`/.well-known/mercure` dorthin weiter, der Browser spricht also immer nur mit Port 30519.

**"Worker neu starten"** im Admin-Bereich funktioniert wie überall: Die Worker machen fertig, was
sie gerade tun, beenden sich und werden wieder gestartet. **"App neu starten"** stoppt den ganzen
Container, weil nur so ein rotiertes Geheimnis beim Webserver ankommt, und die
Neustart-Richtlinie holt ihn zurück.

## Die drei Optionen in der Vorlage

Die Vorlage trägt unter Extra Parameters `--init --restart=unless-stopped --stop-timeout=35` ein.
Jede davon zählt:

- `--init` setzt ein minimales Init vor `app:work`, das die Prozesse aufräumt, die der
  IMAP-Supervisor zurücklässt. Compose macht dasselbe mit `init: true`.
- `--restart=unless-stopped` holt den Container nach "App neu starten" oder einem Absturz zurück.
- `--stop-timeout=35` gibt den Workern Zeit, fertig zu werden: `app:work` wartet beim Stoppen bis
  zu 25 Sekunden auf seine Kindprozesse. Docker selbst wartet nur 10 Sekunden und beendet sie dann
  mitten in dem, was sie gerade tun.

## Daten und Sicherung

Alles liegt unter einem Pfad, `/mnt/user/appdata/plmail` auf dem Host (`/app/var/data` im
Container): `secrets/`, Anhänge, Rohnachrichten und Uploads. Die Datenbank liegt im eigenen
Appdata des Postgres-Containers. Sichere beides. In `secrets/` liegt der Verschlüsselungsschlüssel,
und ohne ihn ist jedes gespeicherte Mail-Passwort unlesbar. Siehe
[Sichern und Wiederherstellen](backup-restore.md).

Der Container läuft standardmäßig als root, wie der Compose-Stack. Als `nobody:users`
(`--user 99:100`) geht es auch, solange der Appdata-Ordner diesem Benutzer gehört.

## Hinter einem Reverse Proxy

plMail liefert einfaches HTTP auf 30519. Für HTTPS stellst du es hinter den Reverse Proxy, den du
auf Unraid ohnehin benutzt (SWAG, Nginx Proxy Manager), und trägst die öffentliche Adresse bei der
Einrichtung ein. Live-Aktualisierungen laufen über dieselbe Adresse, der Proxy muss also nur alles
an Port 30519 weiterreichen. Zu `TRUSTED_PROXIES` und dem Rest siehe
[Hinter einem Reverse Proxy](reverse-proxy.md).

## Aktualisieren

Unraid zeigt ein Update für plMail an, sobald unter dem Tag der Vorlage ein neues Image erscheint.
Wendest du es an, wird das Image geladen und ein neuer Container gestartet, und die Migrationen
laufen beim Start wie überall. Siehe [Aktualisieren](upgrading.md).

## Fallstricke

**Ohne `--restart` stoppt "App neu starten" plMail endgültig.** Der Knopf beendet den Container und
verlässt sich darauf, dass Docker ihn wieder startet. Ein Container ohne Neustart-Richtlinie bleibt
gestoppt, bis ihn jemand im Docker-Tab startet.

**Postgres auf der Standard-Bridge ist nicht über den Namen erreichbar.** Containernamen werden nur
in einem selbst angelegten Netz aufgelöst. Auf der Standard-Bridge muss `DATABASE_URL` die
IP-Adresse des Servers und den Port nennen, auf dem Postgres veröffentlicht ist.

**Eine `DATABASE_URL` ohne Passwort wird ignoriert.** plMail liest eine URL ohne Passwort als
"keine Datenbank konfiguriert" und baut sich eine eigene aus `POSTGRES_HOST`, `POSTGRES_USER` und
einem erzeugten Passwort, von dem der Postgres-Container nichts weiß. Die Verbindung wird dann
abgelehnt.

**Eine bestehende Compose-Installation nach Unraid umzuziehen heißt Sichern und Wiederherstellen**,
nicht Volumes kopieren: Das Postgres des Compose-Stacks ist das Alpine-Image, und ein von ihm
geschriebenes Datenverzeichnis darf kein Debian-basiertes öffnen. Siehe
[Sichern und Wiederherstellen](backup-restore.md).
