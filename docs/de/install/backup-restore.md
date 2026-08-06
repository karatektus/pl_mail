<!-- translated-from: install/backup-restore.md sha1:5eeb6136c8276242d84ca034efa8286261f42607 -->
# Sichern und Wiederherstellen

Eine plMail-Installation besteht aus drei Dingen, und eine Sicherung, die nur zwei davon enthält,
stellt nichts Brauchbares wieder her. Diese Seite sagt, welche drei das sind, was `app:backup` mit
ihnen macht und wie du eine Installation auf einer anderen Maschine hochbringst.

## Die drei Dinge

| Was | Wo es liegt | Warum der Verlust wehtut |
|---|---|---|
| **Die Datenbank** | im Volume `database_data` | Jede Nachricht, jede Konversation, jedes Label, jeder Filter, jeder Kalender, jeder Benutzer und jedes App-Passwort |
| **Der Verschlüsselungsschlüssel** | `APP_ENCRYPTION_KEY` in `var/secrets/generated.env`, auf dem Volume `app_secrets` | Ohne ihn ist jedes Postfachpasswort und jedes OAuth-Token in der Datenbank dauerhaft unlesbar |
| **Die Blobs** | `attachments/`, `raw/` und `uploads/` unterhalb von `APP_STORAGE_DIR` | Anhangpfade werden relativ zum Projektstammverzeichnis in der Datenbank gespeichert; ohne die Dateien endet nach einer Wiederherstellung jeder Anhang mit 404 |

Die Datei mit den Geheimnissen enthält mehr als den Schlüssel — `APP_SECRET`, `POSTGRES_PASSWORD`,
`MERCURE_JWT_SECRET`, das VAPID-Schlüsselpaar und die bei der Einrichtung gespeicherte
`APP_PUBLIC_URL` —, und das JWT-Schlüsselpaar liegt daneben in `var/secrets/jwt/`. All das lässt
sich neu erzeugen; der Verschlüsselungsschlüssel nicht.

Die *Konfiguration* — die Umgebungswerte, jene Dateien im Secrets-Volume und die Zugangsdaten, die
ein Administrator in ein Formular getippt hat — lässt sich auch für sich allein mitnehmen, ohne jede
E-Mail, als eine einzige verschlüsselte Datei: siehe [Konfigurationssicherung](config-backup.md).
Das ist die Datei, die du willst, wenn du eine Installation neu aufbaust statt eine wiederherstellst.

Zwei Volumes brauchen überhaupt keine Sicherung: `caddy_data`/`caddy_config` und
`mercure_data`/`mercure_config` enthalten TLS-Material und Hub-Zustand, die sich von selbst wieder
herstellen.

**Der typische Fehlerfall ist, die Datenbank zu sichern und sonst nichts.** Das stellt jedes
Postfach mit Zugangsdaten wieder her, die niemand entschlüsseln kann, und jeden Anhanglink zeigend
auf eine Datei, die nicht da ist. Keines von beidem meldet sich, bis jemand versucht, etwas
abzugleichen oder zu öffnen.

## `app:backup`

Ein Befehl für alle drei:

```bash
docker compose exec php php bin/console app:backup /path/inside/the/container
```

Ohne Argument schreibt er nach `var/backups/<Y-m-d_His>/` im Projektverzeichnis. Das Ziel wird mit
`0700` angelegt und enthält:

```
database.sql   pg_dump of everything, --no-owner --no-privileges, mode 0600
attachments/   copied with cp -a
raw/
uploads/       includes avatars, under uploads/avatars/
secrets.env    a copy of var/secrets/generated.env, mode 0600
```

Zwei Optionen: `--skip-secrets`, wenn der Schlüssel anderswo bereits gesichert ist, und
`--skip-storage` für eine Momentaufnahme nur der Datenbank.

`pg_dump` läuft gegen die geparste `DATABASE_URL` — eine Verbindung, die die Anwendung nutzen kann,
kann also auch der Dump nutzen —, und das Passwort wird über `PGPASSWORD` in der Umgebung übergeben
statt auf der Kommandozeile, wo `ps` es jedem Benutzer des Hosts zeigen würde. Das Image liefert
`postgresql-client-18` aus PGDG mit Absicht mit: pg_dump weigert sich schlicht, einen Server zu
sichern, der neuer ist als es selbst, und das Debian-Paket hinkt hinterher.

Der Befehl schließt mit einer von zwei Aussagen, und beide sind lesenswert:

- **`secrets.env` enthält `APP_ENCRYPTION_KEY`** — leg es an einen Ort, an dem der Datenbank-Dump
  nicht liegt. Postfachpasswörter zu verschlüsseln ist sinnlos, wenn die Sicherung den Schlüssel
  daneben heftet; zusammen aufbewahrt ist das Paar für einen Dieb exakt so viel wert wie eine
  unverschlüsselte Sicherung.
- **`APP_ENCRYPTION_KEY` ist *nicht* in dieser Sicherung** — diese Installation bekommt ihn aus der
  Umgebung, es gab also nichts zu kopieren. Die Sicherung sieht vollständig aus und ist es nicht.
  Sichere den Schlüssel dort, wo du ihn konfigurierst.

`cp -a` statt einer flachen Kopie ist Absicht: Die Speicherpfade in der Datenbank zeigen in die
Verzeichnisaufteilung hinein, eine flache Kopie stellt also Dateien wieder her, die niemand findet.

**Der typische Fehlerfall ist, dass der `php`-Container die Blobs nicht sehen kann.** `app:backup`
kopiert `APP_STORAGE_DIR` so, wie dieser Container es sieht, und in der Standard-`compose.yaml`
liegen die Blob-Verzeichnisse auf keinem gemeinsamen Volume — die Kopie des Web-Containers ist also
nicht die, in die der Ingest-Worker geschrieben hat. Bring die Mounts in Ordnung, bevor du der
Sicherung traust; siehe
[den Speicherabschnitt der Docker-Seite](docker.md#storage-and-what-the-stock-file-does-not-persist).

## Nichts plant das ein

`app:backup` fehlt bewusst in `MaintenanceSchedule`. Eine Sicherung, die sich selbst auf dieselbe
Platte legt wie das, was sie sichert, ist eine trügerische Sicherheit, und wohin sie stattdessen
gehört, kannst nur du entscheiden. Steuere sie über cron auf dem Host oder über das, was dein NAS
ohnehin schon verwendet:

```bash
docker compose exec -T php php bin/console app:backup /app/var/backups/nightly
```

Leg `database.sql` und `secrets.env` anschließend an **unterschiedliche Orte**. Genau darum geht es
bei Verschlüsselung im Ruhezustand.

**Der typische Fehlerfall ist eine Sicherung, die noch nie jemand zurückgespielt hat.** Die erste
Wiederherstellung ist nicht der Moment, in dem du entdecken willst, dass die Blob-Verzeichnisse leer
waren.

## Auf einem neuen Host wiederherstellen

Die Reihenfolge zählt, denn die Geheimnisse müssen an Ort und Stelle sein, bevor irgendetwas in die
Datenbank schreibt.

**1. Leg die Compose-Datei auf den neuen Host und starte den Stack nicht.** Falls er schon gestartet
wurde, fahr ihn herunter und entferne seine Volumes — eine Installation, die sich ihr eigenes
`APP_ENCRYPTION_KEY` erzeugt hat, verweigert gegen eine wiederhergestellte Datenbank ohnehin den
Start.

**2. Leg `secrets.env` als `generated.env` zurück.** Ermittle den Volume-Namen
(`docker volume ls`; er ist dein Projektname plus `_app_secrets`) und schreib die Datei hinein:

```bash
docker run --rm -v pl_mail_app_secrets:/secrets -v "$PWD":/backup:ro alpine \
  sh -c 'cp /backup/secrets.env /secrets/generated.env && chmod 600 /secrets/generated.env'
```

Die nackte Datei `postgres_password`, die das Postgres-Image liest, muss nicht wiederhergestellt
werden: Der Generator schreibt sie bei jedem Lauf aus der Zeile `POSTGRES_PASSWORD=` in
`generated.env` neu. Auch das JWT-Schlüsselpaar muss nicht wiederhergestellt werden —
`app:secrets:init` erzeugt es neu, wenn es fehlt, um den Preis, dass jedes bereits ausgestellte
JMAP-JWT ungültig wird. App-Passwörter sind Datenbankzeilen und davon unberührt.

**3. Bring nur die Datenbank hoch und warte auf sie.**

```bash
docker compose up -d database
```

`secrets-init` läuft zuerst, findet jeden Wert bereits in der wiederhergestellten Datei vor und
erzeugt nichts.

**4. Spiel den Dump ein.**

```bash
docker compose exec -T database psql -U app -d app < database.sql
```

**5. Leg die Blobs zurück** in das, was sie auf diesem Host aufnimmt — die benannten Volumes, falls
du sie ergänzt hast, oder das bind-gemountete Verzeichnis, falls du dem Muster aus
`truenas.compose.yaml` gefolgt bist. Worauf es ankommt: Die Pfade unterhalb von `APP_STORAGE_DIR`
müssen dieselben sein wie vorher, denn die Datenbank speichert sie relativ zum
Projektstammverzeichnis.

**6. Starte alles.**

```bash
docker compose up -d
```

Migrationen laufen beim Start, eine Wiederherstellung auf ein **neueres** Image bringt das Schema
also beim Starten mit nach vorn. Eine Wiederherstellung auf ein älteres Image geht nicht rückwärts —
siehe [Aktualisieren](upgrading.md).

**7. Prüfe drei Dinge.** `/healthz` sollte mit 200 und `database: true` antworten; die Kopfzeile im
Administrationsbereich sollte den erwarteten Build zeigen; und ein Mail-Konto sollte sich abgleichen.
Letzteres ist die eigentliche Probe, denn es ist das Erste, was gespeicherte Zugangsdaten
entschlüsselt.

**Der typische Fehlerfall ist ein Container, der mit "APP_ENCRYPTION_KEY cannot decrypt the
credentials already stored in this database" den Start verweigert.** Diese Meldung bedeutet, dass
die Wiederherstellung funktioniert hat und der Schlüssel nicht mitgekommen ist. Nichts wurde
verändert — die Prüfung verweigert, statt Daten zu überschreiben, die der richtige Schlüssel noch
lesen könnte. Den ursprünglichen Schlüssel zurückzulegen ist der einzige Weg, diese Zugangsdaten
wiederzubekommen.

## Die Adresse hinterher ändern

Eine auf einem neuen Host wiederhergestellte Installation wird meist unter einer neuen Adresse
erreicht. `APP_PUBLIC_URL` steht in der wiederhergestellten `generated.env` und enthält daher noch
die alte. Setz sie in der Umgebung oder bearbeite diese Datei, starte den Stack neu — und prüfe die
bei [Google](../providers/google.md) und [Microsoft](../providers/microsoft.md) registrierten
Redirect-URIs erneut, die exakt abgeglichen werden.

**Der typische Fehlerfall ist Push, das nie wieder anläuft.** Die von der alten Installation
registrierten Kanäle zeigen auf die alte Adresse; `app:calendar:push` registriert stündlich neu,
sobald die Adresse stimmt, und `app:push:renew --repair` läuft nächtlich für Mail.

## Fallstricke

**Das Volume `database_data` zu kopieren ist keine Sicherung.** Ein laufender Postgres-Cluster, Datei
für Datei kopiert, ist nicht konsistent. `pg_dump` — und genau das führt `app:backup` aus — ist es.

**`database.sql` und `secrets.env` zusammen zu lagern hebt die Verschlüsselung im Ruhezustand auf.**
Der Befehl sagt das laut, einmal, am Ende eines Laufs, den niemand zweimal liest.

**Eine Installation, die `APP_ENCRYPTION_KEY` aus der Umgebung bekommt, erhält eine Sicherung ohne
Schlüssel darin.** Das ist korrektes Verhalten und der gefährlichste Fall auf dieser ganzen Seite —
deshalb prüft der Befehl, ob der Schlüssel in der kopierten Datei vorhanden ist, statt es
anzunehmen.

**`POSTGRES_PASSWORD` lässt sich nicht dadurch wechseln, dass man eine andere Geheimnisdatei
wiederherstellt.** Postgres wurde mit dem alten initialisiert und behält seine eigene Kopie, die
Anwendung ist also aus einem Cluster ausgesperrt, den sie ansonsten sieht. Stell entweder das
passende Passwort wieder her oder fang mit einem leeren Datenbank-Volume an.

**Sicherungen verlieren mit dem Image an Brauchbarkeit.** Ein Dump von einer Installation mit einem
deutlich älteren Tag lässt sich einwandfrei einspielen und migriert beim ersten Start nach vorn; ein
Dump von einer *neueren* Installation migriert nicht rückwärts. Notiere den Versions-Chip aus der
Kopfzeile des Administrationsbereichs zusammen mit der Sicherung.
