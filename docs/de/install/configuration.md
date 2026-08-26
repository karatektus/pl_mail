<!-- translated-from: install/configuration.md sha1:df52ec964c2f6432f46bad36f04deb0d737cc193 -->
# Konfigurationsreferenz

Jede Umgebungsvariable, die plMail liest, was sie bewirkt, welchen Vorgabewert sie hat und was
schiefgeht, wenn sie auf etwas anderes gesetzt ist. Die übrigen Seiten dieses Abschnitts verweisen
hierher, statt Werte zu wiederholen — das ist also die Seite, die du beim Bearbeiten einer
Compose-Datei offen halten solltest.

## Woher ein Wert kommt

Vier Quellen, die mit dem höchsten Vorrang zuerst:

1. **Eine echte Umgebungsvariable.** Was auf der Umgebung des Containers steht, gewinnt immer gegen
   alles darunter. Nichts, was plMail erzeugt, wird jemals über einen von jemandem gesetzten Wert
   geschrieben.
2. **Die Datei der erzeugten Geheimnisse**, `var/secrets/generated.env`. Beim ersten Start erzeugt
   und sowohl vom Container-Entrypoint als auch von `config/bootstrap_generated_secrets.php`
   geladen, das über Composers `autoload.files` eingebunden wird, damit
   `docker compose exec php php bin/console …` — was den Entrypoint vollständig umgeht — dieselben
   Werte sieht.
3. **`.env.local`**, nicht versioniert. Nützlich, wenn plMail aus einem Checkout läuft; im
   veröffentlichten Image gibt es keine solche Datei.
4. **`.env`**, eingecheckt — daher stammen die Vorgabewerte in den Tabellen weiter unten.

Ein **leerer Wert zählt auf jeder Ebene als nicht gesetzt**. Das ist wichtig, weil `docker compose`
`${APP_ENCRYPTION_KEY:-}` als leere Zeichenkette durchreicht, wenn niemand sie gesetzt hat, und das
als "bereits konfiguriert" zu werten würde die Erzeugung beim ersten Start aushebeln.

Die Falle in dieser Anordnung ist, dass `docker compose` selbst `.env` verwendet: Die Datei neben
der `compose.yaml` ist das, was Compose liest, um `${VAR}` aufzulösen — **ein dort abgelegter Wert
wird also zu einer echten Umgebungsvariablen in jedem Container** und schaltet die Erzeugung für ihn
ab. Deshalb sind `APP_SECRET`, `APP_ENCRYPTION_KEY`, `MERCURE_JWT_SECRET` und `APP_PUBLIC_URL` in
der eingecheckten `.env` leer, unter einem Hinweisbanner. Setze sie dort nur, wenn du sie bewusst
selbst verwalten willst.

## Anwendungsvariablen

Das sind die Variablen aus `.env`, in der Reihenfolge, in der sie dort stehen.

| Variable | Was sie bewirkt | Vorgabe | Erforderlich | Wenn sie falsch ist |
|---|---|---|---|---|
| `APP_ENV` | Symfony-Umgebung. | `dev` in `.env`; `compose.yaml` überschreibt das mit `${APP_ENV:-prod}` | Ja | `dev` auf einer echten Installation startet Debug-Kernel und Profiler und schaltet `DefaultSecretsGuard` ab, der ausgelieferte Platzhalter-Geheimnisse nur in `prod` ablehnt. |
| `APP_SECRET` | Symfonys Anwendungsgeheimnis. Signiert unter anderem Remember-me-Cookies. | leer — 32 zufällige Bytes, hexadezimal, beim ersten Start erzeugt | Ja, aber erzeugt | Eine Änderung macht jedes Remember-me-Cookie ungültig, alle werden also abgemeldet. |
| `APP_SHARE_DIR` | Nichts. Kein Code in diesem Repository liest sie. | `var/share` | Nein | Nichts. Sie bleibt bestehen, weil das Entfernen einer Variablen eine Änderung an `.env` wäre; behandle sie als wirkungslos. |
| `APP_SECRETS_FILE` | Pfad zur Datei der erzeugten Geheimnisse, relativ zum Projektstammverzeichnis, sofern er nicht mit `/` beginnt. | `var/secrets/generated.env` | Ja | Zeigt ein Dienst dorthin, wo die anderen nicht hinsehen, erzeugt er seinen eigenen Verschlüsselungsschlüssel. `EncryptionKeyProbe` bemerkt das beim Start und verweigert den Start des Servers. |
| `APP_STORAGE_DIR` | Wurzel für alles, was plMail als Datei schreibt, relativ zum Projektstammverzeichnis: `attachments/`, `raw/`, `uploads/` (Avatare liegen unter `uploads/avatars/`). | `var` | Ja | Anhangpfade werden relativ zum Projektstammverzeichnis in der Datenbank gespeichert; eine Änderung auf einer Installation, in der bereits Mail liegt, macht daher jede vorhandene Datei unauffindbar. |
| `APP_PUBLIC_URL` | Die Adresse, unter der plMail von außen erreicht wird — die, die Google und Microsoft zurückrufen. | leer; wird auf dem Einrichtungsbildschirm abgefragt und in die erzeugte Datei geschrieben | Für Push: ja | Leer, `http://` oder ein Loopback-Host, und Kalender-Push sowie Graph-Mail-Push registrieren sich überhaupt nicht. Siehe [Hinter einem Reverse-Proxy](reverse-proxy.md). |
| `APP_DEFAULT_TIMEZONE` | Die Uhr, die jemandem angezeigt wird, der in den Einstellungen nie eine gewählt hat. | `Europe/Berlin` (zugleich der Parameter `app.fallback_timezone`, der greift, wenn die Variable fehlt) | Nein | Eine Installation mit falscher Zone zeigt allen diesen Personen Zeiten, die um ihren eigenen Versatz plausibel falsch sind. Zeitstempel werden in UTC gespeichert und nur zur Anzeige umgerechnet, eine Korrektur rendert also neu, statt umzuschreiben. Nicht zu verwechseln mit der PHP-Zeitzone des Containers, die in `frankenphp/conf.d/10-app.ini` fest auf `UTC` steht. |
| `DEFAULT_URI` | Basis-URI, die der Router beim Erzeugen von URLs außerhalb einer HTTP-Anfrage verwendet. | `http://localhost` | Nein | Links in Mail, die aus einem Konsolenbefehl entsteht, zeigen auf localhost. Push-Rückrufe verwenden das nicht — sie verwenden `APP_PUBLIC_URL`. |
| `DATABASE_URL` | Doctrine-Verbindung. | `postgresql://app@database:5432/app?serverVersion=18&charset=utf8` — bewusst ohne Zugangsdaten | Ja, aber zusammengesetzt | Eine DSN **ohne** Passwort wird als "niemand hat eine Datenbank konfiguriert" gewertet, und das erzeugte `POSTGRES_PASSWORD` wird eingesetzt. Eine DSN mit Passwort wird als Absicht gewertet und unangetastet gelassen — ein falsches Passwort hier wird also wörtlich verwendet, und Postgres lehnt die Verbindung ab. Sie ist nicht leer, weil Doctrine den Treiber aus dem Schema liest und eine leere DSN das Aufwärmen des Caches beim Image-Build mit "could not find driver" scheitern lässt. |
| `MESSENGER_TRANSPORT_DSN` | Transport hinter den Warteschlangen `export`, `ingest`, `maintenance` und `async`. | `doctrine://default?auto_setup=0` | Ja | `auto_setup=0` ist Absicht: Die Tabelle gehört einer Migration. Zeigt das woanders hin, ohne dass es für jede Warteschlange einen Konsumenten gibt, staut sich Mail, und nichts arbeitet sie ab — ohne Fehlermeldung irgendwo. |
| `MAILER_DSN` | Symfonys Mailer-Transport. | `null://null` | Nein | Heute nichts. plMails eigene ausgehende Mail — Erinnerungsmails, iTIP-Antworten, Buchungsbestätigungen — läuft bewusst **nicht** darüber: Sie geht über das eigene Konto der jeweiligen Person via `MailSenderRegistry` hinaus, weil plMail ein Mail-Client ist und kein Dienst mit eigenem ausgehendem Relay. Die Einstellung ist verdrahtet, und niemand erreicht sie: `MailerInterface` wird in `src/` in keinen Dienst injiziert, es gibt also keinen Codepfad, der diesen Transport benutzt. Der Entwicklungs-Stack hat dafür einen Mailpit-Container betrieben, der folglich nie eine Nachricht bekommen hat — er ist entfernt. |
| `APP_ENCRYPTION_KEY` | Base64-kodierter 32-Byte-Schlüssel für libsodium secretbox hinter dem Doctrine-Typ `encrypted_string` — jedes Postfachpasswort und jedes OAuth-Token. | leer — beim ersten Start erzeugt | Ja, aber erzeugt | Geht er verloren, sind die gespeicherten Zugangsdaten unwiederbringlich. Ein Schlüssel, der nicht zu den gespeicherten Daten passt, hindert den Webserver am Start. Siehe [weiter unten](#der-verschlüsselungsschlüssel). |
| `APP_DEV_USER_EMAIL` | Vorgeschlagene Antwort auf die erste Frage von `app:setup`. | leer | Nein | Nichts; die Frage lässt sich von Hand beantworten. Der Test-Stack setzt sie, um einen bekannten Benutzer anzulegen. |
| `APP_DEV_USER_PASSWORD` | Vorgeschlagene Antwort auf die zweite Frage von `app:setup`. | leer | Nein | Wie oben. Setz das niemals auf einer Installation, die für andere erreichbar ist. |
| `APP_DEMO_MODE` | Macht die ganze Instanz zu einer öffentlichen Demo: Jede Besucherin, die `/demo` aufruft, bekommt ein Wegwerf-Konto mit vorbefülltem Postfach, das Senden wird abgefangen und von einem Skript beantwortet, und eine Leiste am Seitenfuß liefert auf Knopfdruck Mail aus. Schaltet außerdem Mail-Sync, Push-Erneuerung, Kalender-Sync und die Formulare ab, mit denen sich ein echtes Postfach verbinden lässt. | `0` (und `false`, wenn die Variable ganz fehlt) | Nein | Versehentlich auf einer echten Installation an, und der Mail-Sync steht, gesendete Mail verschwindet stillschweigend, und `/demo` gibt Fremden eine funktionierende Sitzung. **Setz das nie auf einer Instanz mit echter Mail.** Siehe [Demo-Modus](demo-mode.md). |
| `APP_DEMO_TTL` | Wie lange das Wegwerf-Konto einer Besucherin lebt, bevor `app:demo:reap` es löscht. Eine ISO-8601-Dauer. | `PT2H` | Nein | Ein unlesbarer Wert fällt auf zwei Stunden zurück, statt einen Fehler zu werfen — ein Tippfehler verkürzt also nichts und macht nichts kaputt. Setzt du ihn sehr lang, kommt der Reaper mit den Besuchern nicht mehr hinterher. Wird nur gelesen, wenn `APP_DEMO_MODE` an ist. |
| `APP_DEMO_IMPRESSUM_NAME` | Betreiber, der im Impressum der Demo (`/impressum`) genannt wird. | leer | Für eine öffentliche Demo in Deutschland: ja | Leer, und die Seite zeigt statt eines Namens eine sichtbare Warnung. Das ist Absicht: § 5 TMG verlangt einen echten Betreiber, und ein Impressum, das niemanden nennt, sieht von weitem korrekt aus, ohne es zu sein. Wird nur gelesen, wenn `APP_DEMO_MODE` an ist. |
| `APP_DEMO_IMPRESSUM_ADDRESS` | Postanschrift auf derselben Seite. Zeilenumbrüche bleiben erhalten. | leer | Wie oben | Wie oben. |
| `APP_DEMO_IMPRESSUM_EMAIL` | Kontaktadresse auf derselben Seite, als `mailto:`-Link. | leer | Wie oben | Wie oben. |
| `APP_DEMO_PRIVACY_HOST` | Das Unternehmen, das den Server betreibt; wird in der Datenschutzerklärung der Demo (`/datenschutz`) als Auftragsverarbeiter genannt. | leer | Für eine öffentliche Demo: ja | Leer, und die Seite zeigt statt eines Namens eine sichtbare Warnung. Wer die Maschine betreibt, verarbeitet in deinem Auftrag die IP-Adressen der Besucher und muss genannt werden; prüfe, ob ein Auftragsverarbeitungsvertrag besteht — die Seite sagt, dass es einen gibt. Wird nur gelesen, wenn `APP_DEMO_MODE` an ist. |
| `MERCURE_URL` | Die Hub-Adresse, an die die **Anwendung** innerhalb des Docker-Netzes veröffentlicht. | `http://mercure/.well-known/mercure` | Ja | Falsch, und nichts wird veröffentlicht: keine Live-Aktualisierungen, kein sichtbarer Fehler auf der Seite. |
| `MERCURE_PUBLIC_URL` | Die Hub-Adresse, die der **Browser** abonniert. | `https://localhost/.well-known/mercure`, sowohl in `.env` als auch in `compose.yaml` | Ja | Falsch, und der Browser öffnet einen Stream dorthin, wo er nicht hinkommt — Maillisten hören auf, sich selbst zu aktualisieren, während der Rest der Anwendung funktioniert. Wird nur dann aus `APP_PUBLIC_URL` abgeleitet, wenn sie nicht gesetzt oder leer ist, was die Standard-`compose.yaml` verhindert. Siehe [Hinter einem Reverse-Proxy](reverse-proxy.md). |
| `MERCURE_JWT_SECRET` | Signiert die Publisher- und Subscriber-JWTs. | leer — 32 zufällige Bytes, hexadezimal, beim ersten Start erzeugt | Ja, aber erzeugt | Anwendung und Hub müssen denselben Wert halten. Weichen sie voneinander ab, weist der Hub jeden Subscriber ab — aus Sicht des Browsers lautlos. |
| `GOOGLE_OAUTH_CLIENT_ID` | Google-OAuth-Client. | leer | Für Gmail | Ohne sie kann "Mit Google anmelden" nicht starten. Ein unter **Administration → Integrationen** gespeicherter Wert gewinnt gegen diesen. Siehe [Google](../providers/google.md). |
| `GOOGLE_OAUTH_CLIENT_SECRET` | Client-Secret des Google-OAuth-Clients. | leer | Für Gmail | Wie oben. |
| `GMAIL_PUBSUB_TOPIC` | Vollständiger Topic-Name, in den Gmail Watch-Benachrichtigungen veröffentlicht. | `projects/your-project-id/topics/gmail-push` — ein Platzhalter, kein funktionierender Wert | Für sofortige Gmail-Zustellung | Der Platzhalter ist kein Topic, das dir gehört, also scheitert jeder `watch`-Aufruf. Die Projekt-ID ist kleingeschrieben und weicht oft vom Anzeigenamen ab. |
| `GMAIL_PUBSUB_VERIFICATION_TOKEN` | Gemeinsames Geheimnis, das als `?token=…` an den Pub/Sub-Push-Endpunkt angehängt wird. | leer | Für sofortige Gmail-Zustellung | **Scheitert nach der sicheren Seite.** Ohne konfiguriertes Token weist `POST /gmail/push` jede Benachrichtigung mit 403 ab, statt unverifizierte anzunehmen. Ein nicht passendes Token bewirkt dasselbe. |
| `MICROSOFT_OAUTH_CLIENT_ID` | Client-ID der Azure-App-Registrierung. | leer | Für Outlook | Ohne sie kann "Mit Microsoft anmelden" nicht starten. Ebenfalls unter Administration → Integrationen überschreibbar. |
| `MICROSOFT_OAUTH_CLIENT_SECRET` | Azure-Client-Secret. | leer | Für Outlook | Wie oben. |
| `MICROSOFT_OAUTH_TENANT` | Welche Microsoft-Konten sich anmelden dürfen: `common`, `organizations`, `consumers` oder eine Tenant-GUID. | `common` | Nein | Muss zu den unterstützten Kontotypen der App-Registrierung passen, sonst scheitert die Zustimmung mit `AADSTS50194` — zum Zeitpunkt der Zustimmung, nicht bei der Einrichtung. Siehe [Microsoft](../providers/microsoft.md). |
| `APP_DB_LOG_LEVEL` | Niedrigste Monolog-Stufe, die für den Protokollbrowser im Administrationsbereich in der Datenbank aufbewahrt wird. | `warning` | Nein | Der **Standard** — Administration → Protokolle kann ihn ohne Neustart überschreiben und tut das, bis wieder *aus der Umgebung* gewählt wird. Auf `info` oder `debug` gesenkt füllt sich die Tabelle `log_entry` schnell; `app:monitoring:prune` bewahrt standardmäßig 14 Tage auf, und das sind auf `debug` sehr viele Zeilen. |
| `APP_CONTAINER_NAME` | Welchem Container eine Protokollzeile und ein Worker-Heartbeat zugeordnet werden. | `web` in `.env`; in `compose.yaml` je Dienst gesetzt | Nein | Ist sie nicht gesetzt, fällt der Heartbeat-Schlüssel auf den Hostnamen zurück — der sich bei jedem Neuerzeugen eines Containers ändert, sodass sich im Administrationsbereich tote Worker ansammeln, bis der Durchlauf für Veraltetes sie einsammelt. |
| `TRUSTED_PROXIES` | Proxys, deren `X-Forwarded-*`-Headern Symfony glaubt. | `127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` | Hinter einem Proxy: ja | Zu eng, und Symfony sieht die Adresse des Proxys und `http`; zu weit, und ein Client kann seine eigene Adresse fälschen. Beide Folgen sind in [Hinter einem Reverse-Proxy](reverse-proxy.md) ausbuchstabiert. |
| `JWT_SECRET_KEY` | Pfad zum privaten Schlüssel, mit dem die JWTs der JMAP-Firewall signiert werden. | `%kernel.project_dir%/var/secrets/jwt/private.pem` | Ja | Er liegt mit Absicht neben den erzeugten Geheimnissen: Die Schlüssel sind nicht im Image, ein Dienst mit eigener Kopie würde also Token ablehnen, die die anderen signiert haben. Erzeugt von `app:secrets:init`. |
| `JWT_PUBLIC_KEY` | Der zugehörige öffentliche Schlüssel. | `%kernel.project_dir%/var/secrets/jwt/public.pem` | Ja | Wie oben. |
| `JWT_PASSPHRASE` | Passphrase für dieses Schlüsselpaar. | leer | Nein | Muss zum Schlüsselpaar auf der Platte passen. Sie zu ändern, ohne die Schlüssel neu zu erzeugen, macht jedes JWT unprüfbar. |
| `VAPID_SUBJECT` | Kontaktkennung, die mit Web Push gesendet wird, nach RFC 8292. Muss eine `mailto:`- oder `https:`-URL sein. | `mailto:admin@example.com` | Für Web Push | Manche Push-Dienste lehnen eine Anfrage ab, deren Subject keine von ihnen akzeptierte URL ist. |
| `VAPID_PUBLIC_KEY` | Öffentlicher Schlüssel für Web Push / JMAP `PushSubscription`. | leer — von `app:secrets:init` erzeugt | Ja, aber erzeugt | Browser binden ein Abonnement an den öffentlichen Schlüssel, mit dem es angelegt wurde. Wird er gewechselt, empfängt jedes Gerät lautlos keine Benachrichtigungen mehr, bis es neu abonniert. |
| `VAPID_PRIVATE_KEY` | Der zugehörige private Schlüssel. | leer — erzeugt | Ja, aber erzeugt | Wie oben. |
| `INTEGRATIONS_ALLOW_HTTP` | Ob jemand für eine selbst gehostete Integration einen `http://`-Server angeben darf. | `false` | Nein | Siehe [den SSRF-Schutz](#der-ssrf-schutz) weiter unten. |
| `INTEGRATIONS_ALLOWED_HOSTS` | Kommagetrennte Hosts, die von der Sperre privater Adressbereiche ausgenommen sind. | leer | Nein | Siehe [den SSRF-Schutz](#der-ssrf-schutz) weiter unten. |

**Der typische Fehlerfall bei dieser Tabelle ist, ein Leerfeld für einen fehlenden Wert zu halten.**
Vier der obigen Leerfelder sind tragend: Sie sind leer, damit die Erzeugung beim ersten Start
stattfindet, und eines davon mit einem anderswoher kopierten Wert zu füllen ist der Weg, auf dem
zwei Dienste sich über den Verschlüsselungsschlüssel uneins werden.

## Variablen, die nur die Compose-Datei und der Entrypoint lesen

Diese tauchen nie in `.env` auf — Compose setzt sie in `compose.yaml` ein, oder der Entrypoint liest
sie direkt, wenn er `DATABASE_URL` zusammensetzt.

| Variable | Was sie bewirkt | Vorgabe | Wenn sie falsch ist |
|---|---|---|---|
| `SERVER_NAME` | Der Hostname, den Caddy im `php`-Container bedient. | `localhost, php:80` | Caddy entscheidet daran, ob es TLS terminiert. `:80` liefert schlichtes HTTP aus, was du hinter einem Reverse-Proxy willst. Ein Hostname bringt Caddy dazu, ein Zertifikat dafür zu beschaffen. |
| `HTTP_PORT` | Host-Port, der auf Container-Port 80/tcp abgebildet wird. | `80` | Ein bereits belegter Port hindert den `php`-Container am Start. |
| `HTTPS_PORT` | Host-Port, der auf Container-Port 443/tcp abgebildet wird. | `443` | Wie oben. |
| `HTTP3_PORT` | Host-Port, der auf Container-Port 443/udp abgebildet wird. | `443` | Nur relevant, wenn du HTTP/3 direkt ausliefern willst. |
| `POSTGRES_USER` | Datenbankrolle, sowohl auf dem Dienst `database` als auch in der zusammengesetzten DSN. | `app` | Eine Änderung, nachdem das Datenbank-Volume existiert, richtet die Anwendung auf eine Rolle, die Postgres nie angelegt hat. |
| `POSTGRES_DB` | Datenbankname, an denselben zwei Stellen. | `app` | Wie oben. |
| `POSTGRES_VERSION` | Tag des `postgres`-Images und `serverVersion` in der zusammengesetzten DSN. | `18` | Das Image liefert `postgresql-client-18` für `pg_dump` mit, und pg_dump weigert sich, einen Server zu sichern, der neuer ist als es selbst — ein neuerer Server macht also `app:backup` kaputt. |
| `POSTGRES_PASSWORD` | Datenbankpasswort. | leer — 24 zufällige Bytes, hexadezimal, beim ersten Start erzeugt | Wird von `app:reset` nie gewechselt: Postgres wurde damit initialisiert und behält seine eigene Kopie, ein neues sperrt die Anwendung also aus der Datenbank aus, die sie gerade zurückgesetzt hat. |
| `POSTGRES_HOST` | Host in der zusammengesetzten DSN. Wird nur vom Entrypoint gelesen. | `database` | Nur relevant, wenn plMail außerhalb dieser Compose-Datei läuft. |
| `POSTGRES_CHARSET` | `charset` in der zusammengesetzten DSN. Nur Entrypoint. | `utf8` | — |
| `APP_SECRETS_DIR` | Verzeichnis, in das `generate-secrets` schreibt. | `/app/var/secrets` | Muss zu `APP_SECRETS_FILE` passen. `truenas.compose.yaml` verschiebt beides unter einen Bind-Mount. |
| `NTFY_PORT` | Host-Port für den optionalen ntfy-Container. | `8090` | Wird nur unter dem Profil `push` verwendet. |
| `NTFY_BASE_URL` | Basis-URL, die ntfy in Push-Endpunkten ausgibt. | `http://${SERVER_NAME:-localhost}:${NTFY_PORT:-8090}` | Wird in jeden ausgegebenen Endpunkt eingebacken; eine spätere Änderung macht daher jedes bestehende Abonnement ungültig, und jedes Gerät muss sich neu registrieren. |
| `NTFY_AUTH_DEFAULT_ACCESS` | ntfys Standard-Zugriffsrichtlinie. | `read-write` | Bewusst offen — UnifiedPush-Topics sind nicht erratbar und Nutzdaten sind für das Gerät verschlüsselt —, aber setz sie auf `deny-all` und leg einen Benutzer an, wenn das hier im Internet steht statt über ein VPN erreicht zu werden. |
| `TEST_HTTP_PORT` | Host-Port für den Test-Stack in `compose.test.yaml`. | `8001` | Nur für die Entwicklung. |

**Der typische Fehlerfall ist hier, nur eine Hälfte eines Paares zu bearbeiten.** `POSTGRES_USER`,
`POSTGRES_DB` und `POSTGRES_PASSWORD` werden sowohl vom Datenbankcontainer bei der Initialisierung
gelesen als auch von der Anwendung, wenn sie ihre DSN zusammensetzt — und die Datenbank liest sie
nur das eine Mal, wenn ihr Volume angelegt wird.

## Was erzeugt wird, und wo

`frankenphp/generate-secrets.sh` erzeugt diese beim ersten Start unter einer Sperre in
`var/secrets/generated.env`, und zwar nur für Namen, die nicht bereits gesetzt sind:

| Name | Wie er erzeugt wird |
|---|---|
| `APP_SECRET` | 32 Bytes aus `/dev/urandom`, hexadezimal |
| `APP_ENCRYPTION_KEY` | 32 Bytes, Base64 — die Größe, die libsodium secretbox verlangt |
| `POSTGRES_PASSWORD` | 24 Bytes, hexadezimal, zusätzlich als nackte Datei `postgres_password` geschrieben, die das Postgres-Image über `POSTGRES_PASSWORD_FILE` liest |
| `MERCURE_JWT_SECRET` | 32 Bytes, hexadezimal |

`app:secrets:init` ergänzt die beiden, für die PHP nötig ist — ein VAPID-Schlüsselpaar und das
lexik-JWT-Schlüsselpaar —, nachdem die Migrationen gelaufen sind, sodass es eine Datei zu sichern
gibt statt mehrerer. `APP_PUBLIC_URL` wird vom Einrichtungsbildschirm in dieselbe Datei geschrieben.

Dass drei der vier hexadezimal statt Base64 sind, ist Absicht: `POSTGRES_PASSWORD` wird in eine DSN
eingesetzt und `MERCURE_JWT_SECRET` in eine Caddy-Konfiguration, und keines von beidem verträgt `+`,
`/` oder `=`.

**Der typische Fehlerfall ist ein Dienst, der die Datei nicht sieht.** Er erzeugt sich eigene Werte
und schreibt von da an Zugangsdaten, die der Rest der Flotte nicht lesen kann.

## Der Verschlüsselungsschlüssel

`APP_ENCRYPTION_KEY` ist der eine Wert, bei dem ein Fehler nicht behebbar ist. Es ist der
libsodium-Schlüssel hinter dem Doctrine-Typ `encrypted_string`, jedes Postfachpasswort und jedes
OAuth-Refresh-Token in der Datenbank ist also ohne ihn unlesbar.

`App\Infrastructure\Setup\EncryptionKeyProbe` läuft bei jedem Containerstart und versucht, ein Konto
mit gespeicherten Zugangsdaten zu laden. Scheitert das, **verweigert es den Start des Webservers**,
statt den Container Konten unter einem Schlüssel speichern zu lassen, den die halbe Flotte nicht
lesen kann. Bei einem Konsolenaufruf warnt es stattdessen und macht weiter — weil eine Weigerung
dort genau den Befehl blockieren würde, der die Lage repariert.

CONTRIBUTING enthält die vollständige Darstellung dazu,
[warum dieser Schlüssel anders ist](../../CONTRIBUTING.md#why-app_encryption_key-is-different) und
was zu tun ist, [wenn die Schlüssel nicht übereinstimmen](../../CONTRIBUTING.md#when-the-keys-disagree);
das [Sicherheitsmodell](../internals/security-model.md) behandelt, wovor die Verschlüsselung schützt
und wovor nicht. Ihn zu sichern ist Thema von [Sichern und Wiederherstellen](backup-restore.md).

**Der typische Fehlerfall ist ein Schlüsselwechsel unter laufendem Stack.** Die übrigen Dienste
halten den alten Schlüssel bis zu ihrem Neustart im Prozessspeicher, eine Zeit lang kann also die
eine Hälfte nicht lesen, was die andere schreibt.

## Der SSRF-Schutz

Zwei Variablen lockern eine Prüfung, und es lohnt sich, genau zu sagen, wofür diese Prüfung da ist.

Selbst gehostete Integrationen — Nextcloud, Immich — lassen eine angemeldete Person ihre eigene
Serveradresse eintippen. Das richtet plMails ausgehenden HTTP-Client dorthin, wohin diese Person
will, und zwar aus einem Containernetz heraus, in dem auch Postgres und der Mercure-Hub liegen.
`App\Service\Integration\IntegrationUrlValidator` weist jede Adresse ab, die nach `127.0.0.0/8`,
`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10` oder `0.0.0.0/8`
auflöst, und weist `http://` grundsätzlich ab.

- **`INTEGRATIONS_ALLOW_HTTP=true`** erlaubt es, ein App-Passwort über unverschlüsseltes HTTP zu
  senden. In einem LAN ist das oft genau das, was gewünscht ist, und es ist standardmäßig aus, damit
  es eine bewusste Entscheidung bleibt und kein Versehen.
- **`INTEGRATIONS_ALLOWED_HOSTS=nextcloud.lan,10.0.0.5`** nimmt benannte Hosts von der Sperre
  privater Adressbereiche aus. Jeder Name, den du hinzufügst, ist ein Name, auf den jemand plMail
  richten kann — einen ganzen Bereich einzutragen oder den Host, auf dem die Datenbank läuft, gibt
  einer authentifizierten Person eine Anfrage aus dem Inneren deines Netzes.

Wer `baseUrl` in der Provider-Konfiguration unter **Administration → Integrationen** festlegt,
entfernt die Angriffsfläche vollständig, und wo das möglich ist, ist es die bessere Antwort: Der
selbst eingetragene Wert wird ignoriert, auch ein alter, der vor dem Festlegen gespeichert wurde.

**Der typische Fehlerfall ist, dass dies keine Abwehr gegen DNS-Rebinding ist.** Ein Hostname, der
zum Verbindungszeitpunkt auf eine private Adresse auflöst, kommt weiterhin durch, weil sich die
aufgelöste IP nicht in Symfonys HTTP-Client festnageln lässt.

## Werte, die stattdessen in der Datenbank konfiguriert werden

Manche Einstellungen sind gar keine Umgebungsvariablen, und ein gespeicherter Wert **gewinnt** gegen
die Umgebung, solange er nicht leer ist:

| Einstellung | Wo |
|---|---|
| Client-ID und Client-Secret für Google und Microsoft | Administration → Integrationen |
| Microsoft-Tenant | Administration → Integrationen |
| Gmail-Pub/Sub-Topic und Verifizierungstoken | Administration → Integrationen |
| Welche Dateiintegrationen überhaupt angeboten werden | Administration → Integrationen |
| Basis-URLs der Integrationsanbieter (das SSRF-Festlegen von oben) | Administration → Integrationen |

Das ist der Weg, den die [Administrationsseite](../features/admin.md) dokumentiert und auf den
`truenas.compose.yaml` verweist, denn dort wird die exakte Redirect-URI angezeigt, die in die
Konsole des Anbieters einzufügen ist.

**Der typische Fehlerfall ist ein halb ausgefüllter Eintrag.** Ein gespeicherter Wert überdeckt die
Umgebung nur, wenn er tatsächlich gesetzt ist — ein Eintrag mit Client-ID und ohne Secret schaltet
also keine funktionierende `.env` still ab. Ein Eintrag mit beidem hingegen, einmal ausgefüllt und
dann vergessen, führt dazu, dass Änderungen an der Umgebung nichts mehr bewirken.

## Fallstricke

**Ein Geheimnis in `.env` ist ein Geheimnis in jedem Container.** Compose liest diese Datei, um
`${VAR}` aufzulösen, wodurch alles darin überall zu einer echten Umgebungsvariablen wird — und eine
echte Umgebungsvariable ist genau das, was dem Entrypoint sagt: "Das wurde mitgegeben, erzeuge
keinen Wert." Ein dort eingecheckter Wert würde von jeder existierenden plMail-Installation geteilt.

**`DATABASE_URL` ist nicht leer und darf es nicht sein.** Der eingecheckte Wert ist eine DSN ohne
Zugangsdaten, weil Doctrine den Treiber aus dem Schema liest und eine leere DSN keines hat — was das
Aufwärmen des prod-Caches während des Image-Builds mit "could not find driver" scheitern lässt. Die
Prüfung des Entrypoints lautet deshalb "trägt diese DSN ein Passwort?" und nicht "ist sie gesetzt?".
Eine DSN, die du *mit* Passwort mitgibst, wird als Absicht gewertet und vollständig in Ruhe
gelassen.

**`MERCURE_PUBLIC_URL` hat einen Vorgabewert, der nicht abgeleitet ist.**
`config/bootstrap_generated_secrets.php` baut ihn aus `APP_PUBLIC_URL` — aber nur, wenn er nicht
gesetzt oder leer ist, und `compose.yaml` setzt ihn bedingungslos auf
`https://localhost/.well-known/mercure`. Auf jeder Installation, die nicht unter `https://localhost`
erreicht wird, setze ihn ausdrücklich.

**Ein leeres `GMAIL_PUBSUB_VERIFICATION_TOKEN` bedeutet "alles ablehnen", nicht "alles annehmen".**
Für einen aus dem Internet erreichbaren Endpunkt ist das die richtige Vorgabe, aber es bedeutet:
Richtest du Pub/Sub ein und vergisst das Token, werden Benachrichtigungen mit 403 abgewiesen und
keine Mail kommt sofort an — was genauso aussieht wie ein Topic, das nie angelegt wurde.

**`APP_STORAGE_DIR` lässt sich auf einer befüllten Installation nicht ändern.** Anhangpfade in der
Datenbank sind relativ zum Projektstammverzeichnis und enthalten dieses Präfix; ein Verschieben
macht also alles bereits Gespeicherte unauffindbar. Verschiebe den *Inhalt* gleichzeitig an den
neuen Ort, oder lass es, wie es ist.

**`POSTGRES_VERSION` ist an das `pg_dump` des Images gekoppelt.** Das Dockerfile installiert
`postgresql-client-18` aus PGDG genau deshalb, weil pg_dump sich weigert, einen Server zu sichern,
der neuer ist als es selbst. Hebst du den Server über 18, ohne neu zu bauen, scheitert `app:backup`
in dem Moment, in dem jemand eine Sicherung braucht.
