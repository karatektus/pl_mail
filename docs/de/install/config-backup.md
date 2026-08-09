<!-- translated-from: install/config-backup.md sha1:ab75ddb4d5167f0eea8ae7d9b327df79e6eb5f88 -->
# Konfigurationssicherung

Die *Konfiguration* einer Installation ist nicht dasselbe wie ihre *Daten*, und beide gehen auf
unterschiedliche Weise verloren. Die Daten zu verlieren ist eine Katastrophe; die Konfiguration zu
verlieren ist ein Dienstagnachmittag, an dem du herausfindest, wo der Firebase-Schlüssel geblieben
ist, zu welchem Google-Projekt der OAuth-Client gehörte und wie `APP_PUBLIC_URL` früher lautete.
[Sichern und Wiederherstellen](backup-restore.md) behandelt die Daten. Diese Seite behandelt das
andere: **Administration → Sicherung**, wo jede Einstellung und jede Zugangsdatei dieser Installation
in einer einzigen passwortverschlüsselten Datei landet — und wieder zurückkommt.

Keine einzige Nachricht, kein Kontakt und kein Kalendereintrag steckt darin. Die **Personen** aber
schon — jede mit ihrem Passwort, ihrem zweiten Faktor, ihren Postfächern und allem anderen, was sie
eingerichtet hat. Denn eine Sicherung, die ihren eigenen Betreiber nicht benennen kann, ist die
Sicherung eines Servers und nicht die einer Installation. Bis v0.0.20 war das nicht so, und eine
Wiederherstellung endete damit, dich um einen frei erfundenen Administrator zu bitten und jedes
Postfach von Hand neu anzulegen.

Die Datei ist bei einer gewöhnlichen Installation weiterhin wenige Kilobyte groß, passt also in einen
Passwortmanager, und sie auf eine frische Installation zurückzuspielen ist etwas Ungefährliches statt
etwas, das man einmal macht und nie wieder.

## Wo Konfiguration tatsächlich liegt

An vier Orten, und zu wissen, welcher welcher ist, ist der größte Teil davon zu verstehen, was ein
Import tut und wann er wirkt. Die letzten beiden sind beide die Datenbank; sie stehen getrennt, weil
eine Wiederherstellung sie entgegengesetzt behandelt — sie überschreibt die Einstellungen des
Betreibers und sie überschreibt niemals eine Person.

| Wo | Was dort liegt | Kann plMail das schreiben? |
|---|---|---|
| **Die Datei mit den erzeugten Geheimnissen** | `APP_SECRET`, `APP_ENCRYPTION_KEY`, die VAPID-Schlüssel, die OAuth-Zugangsdaten, `APP_PUBLIC_URL` — `var/secrets/generated.env`, beim ersten Start erzeugt und vom Entrypoint geladen, bevor sonst irgendetwas läuft. (`MERCURE_JWT_SECRET` liegt ebenfalls hier, bleibt aber außerhalb der Sicherung — er paart die App dieser Maschine mit dem Hub dieser Maschine) | **Ja**, und die Werte wirken ab dem nächsten Containerstart |
| **Das Secrets-Volume** | `jwt/private.pem`, `jwt/public.pem` — Dateien daneben, auf dem Volume `app_secrets`, das jeder Dienst einbindet | **Ja.** Pro Datei und pro Installation gemessen |
| **Die Datenbank** | Das Firebase-Projekt, die Mail-OAuth-Registrierungen, die Einstellungen der Integrationsanbieter — alles, was ein Administrator in ein Formular getippt hat statt in eine Datei | **Ja**, sofort |
| **Die Datenbank, noch einmal** | Die Benutzer, und je Benutzer die Mailkonten samt Zugangsdaten, Aliasse, Integrationen, Filter, Labels, Kalender und veröffentlichte Links | **Ja**, sofort — aber nur solche, die diese Installation noch nicht hat. Siehe [Benutzer](#benutzer) |

**Das ist nicht dieselbe Aussage, die plMail früher gemacht hat.** Frühere Versionen führten jeden
Umgebungswert als etwas auf, das nur der Betreiber setzen kann, und druckten zwei Dutzend Zeilen zum
Einfügen in `.env.local`. Das beruhte auf einer Annahme, die für die Art, wie plMail betrieben wird,
falsch ist: Niemand bearbeitet diese Werte von Hand. Sie werden *beim ersten Start erzeugt*, von
`frankenphp/generate-secrets.sh` in `var/secrets/generated.env`, jeder Dienst bindet das Volume mit
dieser Datei ein, und der App-Prozess kann sie schreiben. Also schreibt der Import sie, und was er
dir danach schuldet, ist ein einziger Satz — starte den Stack neu — statt einer Liste von Aufgaben.

### Die Rangfolge, die alles davon entscheidet

Vollständig dargelegt in der [Konfigurationsreferenz](configuration.md#woher-ein-wert-kommt); die
Kurzfassung, höchste zuerst:

1. **eine echte Umgebungsvariable** — Compose, deine Shell, `docker run -e`;
2. **`var/secrets/generated.env`**;
3. `.env.local`, dann `.env`.

Ein leerer Wert zählt auf jeder Ebene als nicht gesetzt, denn Compose reicht `${APP_SECRET:-}` als
leere Zeichenkette durch, wenn niemand etwas gesetzt hat. Beide Leser wenden genau diese Regel an:
`load_generated_secrets` im Entrypoint überspringt jeden Namen, den `printenv` schon beantwortet, und
`config/bootstrap_generated_secrets.php` überspringt jeden Namen, den `$_SERVER` schon hat.

Ein wiederhergestellter Wert wirkt also ab dem nächsten Start — **es sei denn, etwas in der echten
Prozessumgebung setzt denselben Namen auf etwas Nichtleeres**. Das ist der einzige Fall, vor dem die
Prüfung noch warnt, und alles, was von der alten Wand aus Anweisungen übrig ist. In der
mitgelieferten `compose.yaml` ist das inzwischen **ein** Name: `MERCURE_PUBLIC_URL`. Sie legt drei
auf einen nichtleeren Vorgabewert fest — die anderen beiden sind `MAILER_DSN` und
`MESSENGER_TRANSPORT_DSN` —, aber diese beiden werden gar nicht mehr exportiert, aus dem Grund unter
[Warum die DSNs des Betriebs nicht in der Sicherung sind](#warum-die-dsns-des-betriebs-nicht-in-der-sicherung-sind).
Alles Übrige reicht sie als `${NAME:-}` durch.

`truenas.compose.yaml` ist die Ausnahme, und zwar bewusst: Es ist eine von Hand gepflegte Datei, die
`APP_SECRET`, `APP_ENCRYPTION_KEY`, `DATABASE_URL`, `MERCURE_JWT_SECRET` und den Rest aus
YAML-Ankern setzt, weil es auf dieser Plattform keine `.env` neben der Compose-Datei gibt. Auf diesem
Weg verwaltest du diese Werte selbst, und die Prüfung sagt das zu jedem einzelnen — was richtig ist
und kein Fehlschlag der Wiederherstellung; die zurückgegebenen Zeilen sind die, die in die Anker
gehören.

## Exportieren

**Administration → Sicherung → Konfiguration exportieren.** Passwort zweimal eintippen und
**Sicherung herunterladen** drücken. Heraus kommt `plmail-config-<datum>.backup`.

Das Passwort wird nirgends gespeichert, und es gibt keine Wiederherstellung dafür. Genau deshalb wird
es zweimal getippt: Ein vertipptes Passwort ergibt eine Datei, die in Ordnung aussieht und sich an
dem Tag als nicht zu öffnen erweist, an dem sie gebraucht wird.

Die Datei wird im Arbeitsspeicher gebaut und direkt an den Browser gestreamt — nichts Entschlüsseltes
wird jemals auf dem Server auf die Platte geschrieben, und die Antwort ist als `no-store` markiert,
damit kein Proxy eine Kopie behält.

### Was darin steckt

Nur Namen; die Werte gehören dir.

**Umgebung** — jede dieser Variablen, für die diese Installation tatsächlich einen Wert gesetzt hat.
Leere werden weggelassen statt als Leerstring exportiert.

```
APP_ENCRYPTION_KEY  APP_SECRET
MERCURE_PUBLIC_URL  JWT_PASSPHRASE
APP_PUBLIC_URL
VAPID_SUBJECT  VAPID_PUBLIC_KEY  VAPID_PRIVATE_KEY
GOOGLE_OAUTH_CLIENT_ID  GOOGLE_OAUTH_CLIENT_SECRET
GMAIL_PUBSUB_TOPIC  GMAIL_PUBSUB_VERIFICATION_TOKEN
MICROSOFT_OAUTH_CLIENT_ID  MICROSOFT_OAUTH_CLIENT_SECRET  MICROSOFT_OAUTH_TENANT
INTEGRATIONS_ALLOW_HTTP  INTEGRATIONS_ALLOWED_HOSTS
TRUSTED_PROXIES  APP_DEFAULT_TIMEZONE  APP_DB_LOG_LEVEL  DEFAULT_URI
```

**Bewusst nicht exportiert**, weil sie die Maschine beschreiben und nicht die Installation, und weil
sie mitzunehmen das Ziel eher kaputtmacht als konfiguriert: `APP_ENV`, `APP_DEBUG`, die
`APP_DEV_USER_*`-Fixtures, `APP_CONTAINER_NAME`, `APP_SECRETS_FILE`, `JWT_SECRET_KEY`,
`JWT_PUBLIC_KEY`, `APP_STORAGE_DIR`, `APP_SHARE_DIR`, `MERCURE_URL`, `DATABASE_URL`,
`POSTGRES_PASSWORD`, `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN` und `MERCURE_JWT_SECRET`. Der *Inhalt*
der JWT-Schlüssel reist mit; die Pfade, unter denen sie liegen, gehören der jeweils lesenden
Installation, und `MERCURE_URL` ist die netzinterne Adresse eines Nachbarcontainers.
`MERCURE_JWT_SECRET` existiert, damit App und Mercure-Hub *derselben Maschine* übereinstimmen — der
Hub liest ihn genau einmal, beim Containerstart. Ein wiederhergestellter Wert tauscht also den
Schlüssel einer laufenden Paarung zur Hälfte aus, und jedes Live-Update stirbt, bis der ganze Stack
neu startet; eine frische Installation erzeugt ihren eigenen, und beide Hälften stimmen vom ersten
Moment an überein. Die übrigen vier sind die Infrastruktur des jeweiligen Betriebs — siehe [Warum die Datenbank-Zugangsdaten gar nicht erst in der Sicherung sind](#warum-die-datenbank-zugangsdaten-gar-nicht-erst-in-der-sicherung-sind)
und [Warum die DSNs des Betriebs nicht in der Sicherung sind](#warum-die-dsns-des-betriebs-nicht-in-der-sicherung-sind).
Jede davon ist in der [Konfigurationsreferenz](configuration.md) beschrieben.

**Dateien**, adressiert über einen logischen Namen statt über einen Pfad, damit das Ziel sie dorthin
legt, wo seine eigene Konfiguration sie erwartet:

```
jwt/private.pem  jwt/public.pem
```

**Datenbank**:

```
fcmConfig             das Firebase-Projekt: der Service-Account-Schlüssel, die aus
                      google-services.json geparste Client-Konfiguration und ob
                      Push eingeschaltet ist
mailProviders         pro Anbieter (google, microsoft): Client-ID, Client-Secret,
                      der Pub/Sub-Verifizierungstoken, der Einstellungs-Bag
integrationProviders  pro Anbieter (nextcloud, immich, googleDrive, …): aktiviert,
                      Basis-URL, Client-ID, Client-Secret, der Einstellungs-Bag
```

<a id="benutzer"></a>
**Benutzer** — ein Objekt mit der E-Mail-Adresse als Schlüssel, denn danach ordnet ein Import zu.
Pro Person:

```
das Konto            Anzeigename, Passwort-HASH (nie ein Passwort — den Klartext
                     gibt es nirgends), Administratorrolle, Sprache, Zeitzone,
                     Erscheinungsbild, Oberflächen-Einstellungen, Onboarding-Stand
zweiter Faktor       das TOTP-Geheimnis, sein Bestätigungsdatum und die noch
                     ungenutzten Wiederherstellungscodes (bereits SHA-256-Digests)
App-Passwörter       Name, Hinweis und Hash je Zugangsdatum, mit lastUsedAt und
                     revokedAt. Sie sind es, die JMAP-Clients angemeldet halten
Mailkonten           IMAP-/SMTP-Host, Port und Verschlüsselung, Benutzername,
                     Passwort, OAuth-Anbieter samt Access- und Refresh-Token,
                     dazu die Aliasse jedes Kontos
Integrationen        je Verbindung: Anbieter, Basis-URL, Benutzername, Secret oder
                     OAuth-Token, der Einstellungs-Bag
Filter               Bedingungen und Aktionen; die Label-, Konto- und
                     Integrations-IDs darin werden beim Import umgeschrieben
Labels               der ganze Baum: Namen, Rollen, Farben, Eltern, Reihenfolge
Kalender             Name, Farbe, Zeitzone, Rolle und von welchem Mailkonto oder
                     welcher Integration er eine Spiegelung ist
veröffentlichte      Kalender-Freigabelinks und Buchungsseiten, über ihren
Links                Token-Digest — ihn mitzunehmen ist das, was eine bereits
                     verschickte URL nach dem Umzug weiter funktionieren lässt
```

**Nicht mitgenommen, je Person**, jeweils aus genanntem Grund: **vertrauenswürdige Geräte** und
**Push-Abonnements**, die Freigaben an einen Browser oder ein Telefon sind — beim vertrauenswürdigen
Gerät ist es ein *übersprungener zweiter Faktor*, dessen Wiederherstellung das Konto schwächen statt
umziehen würde; **Label-Bindungen**, also die Identität eines Labels beim Anbieter, die die erste
Synchronisierung neu herleitet; **Synchronisierungsstand** — Cursor, History-IDs,
Watch-Registrierungen, Kalender-Push-Kanäle, Backfill-Zähler —, der zu dem Host gehört, der
synchronisiert hat; und **Avatare und Hintergrundbilder**, die Dateinamen in einem Storage-Volume
sind, das diese Datei nicht mitführt. Soft-gelöschte Benutzer werden nicht exportiert: `deletedAt`
ist eine Entscheidung, und eine Wiederherstellung respektiert sie.

E-Mails, Kalendereinträge, Kontakte und Logs sind überhaupt nicht dabei. Diese Grenze hat sich nicht
verschoben.

**Diese werden entschlüsselt exportiert.** In der Datenbank liegen sie in
`encrypted_string`-Spalten, lesbar nur mit dem `APP_ENCRYPTION_KEY`, der sie geschrieben hat — und
der ganze Sinn einer Konfigurationssicherung ist, dass sie anderswo geöffnet wird, von einer
Installation mit einem anderen Schlüssel. Chiffrat wäre totes Gewicht. Das Passwort des Umschlags ist
der Schutz; die Spaltenverschlüsselung ist ein anderer Schutz gegen eine andere Bedrohung, und beides
übereinanderzulegen ergäbe eine Datei, die sicher und nutzlos ist. Das gilt für die TOTP-Geheimnisse,
Postfach-Passwörter, OAuth-Token und Integrations-Secrets im Benutzerabschnitt genauso wie für den
Firebase-Schlüssel.

Passwort-Hashes, Wiederherstellungscodes und App-Passwort-Hashes reisen so, wie sie gespeichert sind,
denn sie sind bereits Einwegwerte. Weniger heikel sind sie deshalb nicht: Ein Hash ist ein Ziel zum
Offline-Raten, und diese Datei enthält jeden einzelnen der Installation.

All das steckt innerhalb des verschlüsselten Umschlags. Ohne Passwort lesbar ist nur dessen eigener
Kopf — Formatname, Version, KDF-Parameter, Salt und Nonce. Es gibt kein Klartext-Verzeichnis, also
ist vor dem Öffnen nichts über irgendeinen Benutzer sichtbar.

Daraus folgt: **Die Datei enthält jedes Geheimnis dieser Installation, und das Passwort, das du
eintippst, ist das Einzige, was sie schützt.** Behandle sie genau so, wie du `APP_ENCRYPTION_KEY`
behandelst.

## Das Dateiformat

Hier dokumentiert, damit die Datei nie davon abhängt, dass plMail läuft. Sie ist ein einziges
JSON-Objekt:

```json
{
  "format": "plmail-config-backup",
  "version": 1,
  "kdf": {
    "name": "argon2id",
    "opslimit": 3,
    "memlimit": 67108864,
    "salt": "<base64, 16 Bytes>"
  },
  "cipher": {
    "name": "xsalsa20poly1305",
    "nonce": "<base64, 24 Bytes>"
  },
  "ciphertext": "<base64 von crypto_secretbox(dokument, nonce, key)>"
}
```

- **`key` = `crypto_pwhash(32, passwort, salt, opslimit, memlimit, ALG_ARGON2ID13)`** — libsodiums
  Argon2id, mit den Parametern aus der Datei statt aus Annahmen, damit ein späteres Anheben alte
  Sicherungen weiterhin öffenbar lässt.
- **`ciphertext` = `crypto_secretbox(klartext, nonce, key)`** — XSalsa20-Poly1305. Der
  Poly1305-Tag ist der Grund, warum eine manipulierte Datei sich nicht öffnen lässt, statt zu Müll zu
  entschlüsseln — und warum ein falsches Passwort und eine veränderte Datei als dasselbe gemeldet
  werden: Sie sind nicht unterscheidbar.
- **`opslimit` 3 mit `memlimit` 64 MiB** ist libsodiums MODERATE-Iterationszahl mit dessen
  INTERACTIVE-Speicher. Die Referenzinstallation von plMail ist ein Raspberry Pi, auf dem eine
  Allokation von 256 MiB ein Viertel der Maschine ist und aus einer langsamen Seite einen vom
  OOM-Killer erledigten Worker macht; Iterationen kosten nur Wanduhrzeit, also wird diese Hälfte
  angehoben, um einen Teil dessen zurückzukaufen, was die Speicherhälfte aufgibt.

Der Klartext darin ist ein zweites JSON-Objekt und trägt sein eigenes `format` und `version` — die
Version des Umschlags beschreibt, wie die Bytes verschlüsselt sind, die des Dokuments beschreibt, was
die Felder bedeuten, und eine künftige plMail-Version kann jede für sich anheben:

```json
{
  "format": "plmail-config-backup",
  "version": 2,
  "exportedAt": "2026-08-06T12:00:00+00:00",
  "instance": "https://mail.example.com",
  "env": { "APP_SECRET": "…" },
  "files": { "jwt/private.pem": "<base64>" },
  "database": { "fcmConfig": { "serviceAccountJson": "…" } },
  "users": { "anna@example.com": { "password": "$2y$…", "accounts": [] } }
}
```

**Dokumentversion 2 hat `users` hinzugefügt; sonst hat sich nichts verschoben.** Eine Datei mit
Version 1 — also alles, was vor der Aufnahme der Benutzer exportiert wurde — hat gar keinen
`users`-Schlüssel und wird genau so importiert wie eh und je: Ein fehlender Abschnitt wird als leerer
gelesen. Eine Datei mit Version 2 wird von einem älteren plMail vollständig abgelehnt statt halb
eingespielt, und das ist richtig so — jener Stand würde die Konfiguration übernehmen und jedes Konto
in der Datei stillschweigend fallen lassen. Die Version des Umschlags bleibt 1: Wie die Bytes
verschlüsselt werden, hat sich nicht geändert, und genau dafür gibt es die beiden getrennten
Versionsnummern.

### Eine Sicherung ohne plMail öffnen

Jede libsodium-Anbindung genügt. Mit dem PHP, das ohnehin im Container steckt:

```sh
php -r '
$e = json_decode(file_get_contents($argv[1]), true);
$k = sodium_crypto_pwhash(
    SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $argv[2],
    base64_decode($e["kdf"]["salt"]),
    $e["kdf"]["opslimit"], $e["kdf"]["memlimit"],
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
echo sodium_crypto_secretbox_open(
    base64_decode($e["ciphertext"]),
    base64_decode($e["cipher"]["nonce"]), $k), "\n";
' plmail-config-2026-08-06.backup 'dein passwort'
```

Schick es durch `jq`, wenn du es lesbar willst. Beachte, dass das jede Zugangsdatei der Installation
in dein Terminal schreibt und, je nach Shell, samt Passwort in dessen Verlauf — mach es in einem
Verzeichnis, das du gleich verlässt.

## Importieren

**Administration → Sicherung → Konfiguration importieren.** Datei auswählen, Passwort eintippen,
**Sicherung prüfen** drücken.

**Die Prüfung schreibt nichts.** Sie liest die Datei und zeigt, was passieren würde: was plMail
selbst schreibt — und das ist fast alles —, dann alles, was noch für dich übrig bleibt, dann alles,
was lediglich wissenswert ist. Jede Zeile sagt, ob der Wert hier neu ist, ob er etwas anderes ersetzt
oder ob er bereits übereinstimmt. Dieser mittlere Zustand ist der, bei dem es sich innezuhalten
lohnt, denn eine Wiederherstellung auf einer laufenden Installation ersetzt lebende Zugangsdaten
durch andere lebende Zugangsdaten.

**Diese Sicherung anwenden** fragt das Passwort noch einmal ab und führt dann genau die gezeigte
Liste aus. Die Datenbankschreibvorgänge laufen in einer Transaktion, sodass ein Dokument mit
kaputtem Firebase-Schlüssel nicht drei Anbieterregistrierungen aus einer fremden Installation
zurücklässt. Die erzeugten Geheimnisse werden danach geschrieben, in einem Durchgang unter derselben
Sperre, die auch `generate-secrets.sh` nimmt: Namen, die die Datei kennt, werden an Ort und Stelle
aktualisiert, unbekannte angehängt, und **alles, wozu die Sicherung nichts sagt, bleibt genau so, wie
es war.**

Die hochgeladene Datei wird nie gespeichert — weder in einer temporären Datei noch in der Session.
Zwischen Prüfung und Anwendung reist sie als genau das Chiffrat, das du hochgeladen hast, durch die
Seite zurück; deshalb muss das Passwort ein zweites Mal getippt werden.

### Was die Prüfung zu jedem Wert sagt

Sechs Begriffe, und jeder steht für ein anderes Schicksal. Die Prüfseite versieht jede Zeile mit
einem davon.

| Begriff | Bedeutet | Kommt bei dir an als |
|---|---|---|
| **angewendet** | Geschrieben und ab sofort wirksam | Nichts zu tun |
| **wirkt nach dem nächsten Neustart** | In `var/secrets/generated.env` geschrieben oder über eine Datei daneben, und beim nächsten Start des Stacks gelesen | Ein Neustarthinweis für die ganze Liste |
| **von Compose überdeckt** | Geschrieben — und ein nichtleerer Wert desselben Namens in der Prozessumgebung gewinnt beim nächsten Start trotzdem darüber | Die Zeile, zum Ändern oder Entfernen in deiner Compose-Datei (oder der `.env` daneben) |
| **extern** | Nicht geschrieben, weil die andere Hälfte der Änderung in einem System liegt, dessen Client plMail nur ist | Die Zeile, plus was sonst noch geändert werden muss |
| **bewusst behalten** | Nicht geschrieben, und das ist das richtige Ergebnis. `APP_ENCRYPTION_KEY` — und jeder Benutzer, den diese Installation schon hat | Ein Hinweis, und die Zeile für den einen Fall, der sie braucht |
| **nicht schreibbar** | Der Pfad hat den Schreibvorgang verweigert — schreibgeschütztes Secrets-Volume, falsche UID, volle Platte | Die Zeile oder der Pfad, wie bisher |

Abschnitt für Abschnitt:

| Abschnitt | Was passiert |
|---|---|
| `database` (Firebase, Mail-OAuth, Integrationen) | **angewendet**, neu verschlüsselt mit dem `APP_ENCRYPTION_KEY` *dieser* Installation. plMail besitzt diese Zeilen vollständig |
| `files` → `jwt/private.pem`, `jwt/public.pem` | **wirkt nach dem nächsten Neustart**, dort wo der Prozess sie schreiben kann. Zur Prüfzeit mit `is_writable` gemessen, nicht angenommen — ein schreibgeschützt eingebundenes Secrets-Volume ist ein unterstützter Betrieb. Die Bytes landen sofort; lexik liest den Schlüssel einmal pro Prozess, die Tokens *dieses* Containers werden also bis zum Neustart weiter mit dem alten signiert |
| `env` → `APP_ENCRYPTION_KEY` | **bewusst behalten.** Siehe [Speziell zu `APP_ENCRYPTION_KEY`](#speziell-zu-app_encryption_key) |
| `env` → alles Übrige | **wirkt nach dem nächsten Neustart**, oder **von Compose überdeckt**, wo etwas denselben Namen festlegt |
| `users` → jemand, den diese Installation nicht hat | **angewendet**, mit allem, was die Person eingerichtet hatte, neu verschlüsselt mit dem Schlüssel *dieser* Installation |
| `users` → jemand, den sie hat | **bewusst behalten.** An der Person wird nichts angerührt. Siehe [Benutzer beim Import](#benutzer-beim-import) |

**Werte, die bereits übereinstimmen, werden nicht als Arbeit aufgeführt.** Ist ein wiederhergestellter
Wert Byte für Byte das, was diese Umgebung ohnehin hat, dann ist er nichts, was du zu tun hättest —
gleich, wer nominell dafür zuständig ist. Er fällt also aus *Das musst du selbst erledigen* heraus
und wird stattdessen in einer einzigen gedämpften Zeile gezählt. In der Bestandsaufnahme der Prüfung
steht er weiterhin, unter dem, was plMail schreibt; herausgefiltert wird die Aufgabenliste, und eine
bereits erledigte Aufgabe ist keine. Bleibt gar nichts übrig, sagt die Seite das und stellt den
Abschluss nach vorn, statt eine leere Liste als Arbeit zu rahmen.

<a id="benutzer-beim-import"></a>
#### Benutzer: angelegt oder vollständig in Ruhe gelassen

Zugeordnet über die E-Mail-Adresse. Es gibt genau zwei Ausgänge und keinen dritten.

- **Die Adresse gibt es hier nicht** → die Person wird angelegt, mit Passwort-Hash, zweitem Faktor,
  Wiederherstellungscodes, App-Passwörtern, Postfächern, Aliassen, Integrationen, Filtern, Labels,
  Kalendern und veröffentlichten Links. Sie kann sich sofort mit dem Passwort anmelden, das sie schon
  kennt, ihre Authenticator-App funktioniert weiter, und ihre bestehenden App-Passwörter halten ihre
  JMAP-Clients verbunden.
- **Die Adresse gibt es hier** → es passiert nichts. Nicht das Passwort, nicht der zweite Faktor,
  nicht die Postfächer, keine einzige Einstellung. Die Prüfung führt sie unter *Schon vorhanden —
  unverändert gelassen* auf und sagt dazu, ob die Datei mit dem lebenden Konto übereinstimmt oder
  davon abweicht.

**Das Überspringen ist Absicht, und es gilt ganz oder gar nicht.** Eine Sicherung ist die
Momentaufnahme eines vergangenen Zeitpunkts. Eine drei Monate alte auf ein lebendes Konto anzuwenden
würde das heutige Passwort auf das vom Februar zurücksetzen, jedes seither angelegte App-Passwort
entwerten und ein TOTP-Geheimnis zurückspielen, dessen Besitzerin sich im März neu registriert hat —
still, mit der Person ausgesperrt aus ihrer eigenen Mail und ohne ein Wort darüber auf irgendeiner
Seite. Es gibt kein Zurück, denn den Klartext eines Passworts gibt es nirgends.

Zusammenzuführen wäre schlimmer als beide Extreme, weshalb es „nur die Postfächer, die sie noch nicht
hat“ nicht gibt: Der Teilbaum in der Datei ist in sich stimmig — Filter zeigen auf Labels, Kalender
auf Integrationen, Links auf Kalender —, und seine Hälfte auf die Hälfte eines lebenden Benutzers
gepfropft ergibt eine Form, die keine der beiden Installationen je hatte.

Ein **soft-gelöschter** Benutzer zählt als schon vorhanden. Er belegt die Adresse gegenüber einem
Unique-Index, und `deletedAt` ist die Entscheidung von jemandem.

Willst du die alte Konfiguration eines Benutzers wirklich auf einer laufenden Installation
zurückhaben, lösche oder benenne das lebende Konto zuerst um und importiere erneut — oder, deutlich
besser, hol dir die eine Sache, die du brauchst, von Hand aus dem entschlüsselten Dokument (siehe
[Eine Sicherung ohne plMail öffnen](#eine-sicherung-ohne-plmail-öffnen)).

**Die IDs innerhalb eines Filters werden umgeschrieben.** Eine Regel, die „wende Label 41 an“ sagt,
meint Zeile 41 der *Quell*-Datenbank. Beim Import wird jeder Verweis — das Konto, auf das eine Regel
eingegrenzt ist, die Bedingungen `hasLabel` und `notLabel`, `labelId` und `integrationId` in den
Aktionen, der Kalender, in den eine Buchungsseite schreibt — auf die Zeile gezeigt, die an ihre
Stelle getreten ist. Ein Verweis auf etwas, das die Datei nicht mitgebracht hat, wird verworfen statt
geraten: Ein Filter, der eine Sache weniger prüft, ist ein viel kleineres Übel als einer, der das
Label von jemand anderem anwendet.

<a id="warum-die-dsns-des-betriebs-nicht-in-der-sicherung-sind"></a>
#### Warum die DSNs des Betriebs nicht in der Sicherung sind

`MAILER_DSN` und `MESSENGER_TRANSPORT_DSN` sind maschinenlokale Betriebsentscheidungen, dieselbe
Kategorie wie `DATABASE_URL` weiter unten. Die `compose.yaml` von plMail liefert für beide einen
Vorgabewert, jede Installation hat also einen — ob ihn nun jemand gewählt hat oder nicht. Und der des
Ziels ist derjenige, der zu den Containern passt, die tatsächlich daneben laufen: sein Relay, seine
Queue. Den der Quelle mitzunehmen bedeutete, dass auf einem Standard-Stack jeder Plan mit zwei Zeilen
begann, deren einzige ehrliche Anweisung „ändere das in der Compose-Datei, die dir ohnehin gehört“
lautete. Das ist keine Aufgabe, und zwei Nicht-Aufgaben am Kopf einer Liste bringen einer Leserin
bei, dass die Liste überspringbar ist.

Eine alte Sicherung, die sie noch enthält, wird problemlos importiert: Sie werden als **extern**
eingestuft und in Ruhe gelassen — und stimmt ihr Wert mit dem überein, was diese Umgebung ohnehin hat
(auf einem Standard-Stack ist das so), werden sie nicht einmal aufgeführt. Willst du die
Konfiguration eines Relays zwischen Installationen mitnehmen, gehört sie in die Compose-Datei, die du
mitkopierst, und nicht in diese Datei.

#### Warum die Datenbank-Zugangsdaten gar nicht erst in der Sicherung sind

`POSTGRES_PASSWORD`, die Datei `postgres_password` und `DATABASE_URL` sind maschinenlokale
Infrastruktur: erzeugt, bevor der erste Benutzer existiert, vom Postgres-Image bei **initdb**
gelesen — wenn das Datenverzeichnis angelegt wird, und nie wieder — und zusammengesetzt aus dem
Passwort und dem Host der *Quelle*. Die Datenbank des Ziels hat längst eigene, funktionierende
Zugangsdaten; mit den alten könnte ein Betreiber nichts anfangen, außer die neue Installation damit
zu beschädigen. Frühere Versionen haben sie trotzdem exportiert, und jede Prüfansicht trug zwei
„extern“-Zeilen, mit denen niemand etwas tun konnte; jetzt sind sie schlicht kein Teil der
Sicherung. Eine alte Sicherung, die sie noch enthält, lässt sich weiter einspielen — sie werden als
extern eingestuft und nicht angefasst.

Das eine Szenario, dem der alte Export theoretisch diente — Secrets-Volume verloren,
Datenbank-Volume überlebt — löst Postgres, nicht plMail: das Rollenpasswort als
Datenbank-Superuser mit `ALTER ROLE app PASSWORD …` neu setzen und denselben Wert in die
`generated.env` schreiben.

#### Die eine Überdeckung, die die Prüfung nicht sehen kann

Die Erkennung vergleicht den lebenden Wert mit dem, was in `generated.env` steht, denn der Entrypoint
exportiert den Inhalt dieser Datei in die Umgebung, bevor er den Server startet — „steht es in
`getenv`“ beantwortet also beides mit ja und unterscheidet nichts. Ein lebender Wert, der von dem der
Datei abweicht, oder ein Name, den die Datei nie hatte, ist eine Festlegung; ein lebender Wert, der
dem der Datei gleicht, ist der Export des Entrypoints selbst.

Die Lücke: Hast du einen Namen in Compose auf *genau die Zeichenkette* festgelegt, die die erzeugte
Datei ohnehin schon enthält, sind beide aus der Anwendung heraus nicht zu unterscheiden, und der
wiederhergestellte Wert würde ohne Warnung überdeckt. Dazu muss man ein erzeugtes Geheimnis von Hand
in die Compose-Datei kopiert haben. Wenn du das getan hast, sind die selbst verwalteten Werte die,
die du nach einer Wiederherstellung noch einmal prüfen solltest.

## Auf eine neue Installation zurückspielen

Datei hochladen, Passwort eintippen. Das ist die Arbeit.

1. **Den Stack leer hochfahren.** Er erzeugt beim ersten Start seine eigenen `APP_SECRET`,
   `APP_ENCRYPTION_KEY`, `POSTGRES_PASSWORD` und `MERCURE_JWT_SECRET` in `var/secrets/generated.env`.
2. **`/install` öffnen.** Unter dem Kontoformular steht *Stelle zuerst eine Konfigurationssicherung
   wieder her* — Datei hochladen, Passwort eintippen, prüfen, anwenden. Alles, was die Sicherung
   enthält, landet in der eigenen Geheimnisdatei und der Datenbank dieser Instanz.
3. **Anmelden.** Hat die Sicherung Benutzer mitgebracht — ab v0.0.21 tut das jede —, ist die
   Installation am Ende von Schritt 2 fertig, und die Seite bietet einen Anmeldelink an und nennt den
   wiederhergestellten Administrator. Nimm das Passwort, das du schon hattest; es hat sich nicht
   geändert. *Stammt die Datei aus der Zeit davor oder enthielt sie keine Benutzer, führt die Seite
   stattdessen zum Kontoformular: Dort legst du den Administrator an, wie bisher.*
4. **Den Stack einmal neu starten.** Danach kommt die Instanz als die hoch, von der die Sicherung
   stammt.

Die Wiederherstellung muss Schritt 2 sein und nicht etwas Späteres, weil `/install` und die
Wiederherstellungsseite beide nur offen sind, solange die Installation keine Benutzer hat — und die
Wiederherstellung ist inzwischen meist das, was diesen Zustand beendet. Beide Türen antworten ab der
nächsten Anfrage mit 404; von da an ist die Seite **Administration → Sicherung**.

Es zahlt sich auch später aus: Die beiden Administratorschritte des Einrichtungsassistenten
entscheiden anhand von „ist schon etwas konfiguriert?“, ob sie zutreffen; eine wiederhergestellte
Installation führt ihren Administrator also direkt an beiden vorbei, statt nach Zugangsdaten zu
fragen, welche die Datei längst mitgebracht hat.

Schritt 4 ist nötig, weil der erste Start bereits stattgefunden hat: Der Entrypoint hat die eigenen
Geheimnisse dieser Instanz erzeugt und in die laufenden Prozesse geladen, bevor du überhaupt
`/install` erreicht hast, und diese Prozesse lesen ihre Umgebung genau einmal. Die
wiederhergestellten Werte liegen ab dem Druck auf „Anwenden“ auf der Platte; der Neustart setzt sie
in Kraft. Er kann bis nach dem Konto warten, und die Prüfseite sagt das auch.

Falls die Prüfung etwas unter *Das musst du selbst erledigen* aufgeführt hat, ist das deine restliche
Arbeit, und jede Zeile sagt warum. Auf einem unveränderten Stack sollte diese Überschrift gar nicht
erst erscheinen: `MERCURE_PUBLIC_URL` ist der eine Name, den `compose.yaml` noch festlegt und den
eine Sicherung mitführt, und stimmen beide überein, wird er gezählt statt aufgeführt. Sagt die Seite,
es sei nichts mehr zu tun, dann ist nichts mehr zu tun.

### Speziell zu `APP_ENCRYPTION_KEY`

Du hast zwei Möglichkeiten, und sie sind nicht gleichwertig.

- **Den Schlüssel der neuen Installation behalten** (der Normalfall, wenn du nichts tust). Die
  Zugangsdaten aus der Sicherung werden beim Schreiben damit neu verschlüsselt. Das ist der Fall, für
  den der gesamte Entwurf mit entschlüsseltem Umschlag existiert, und der, der einfach funktioniert.
- **Den alten Schlüssel mitnehmen**, indem du den exportierten `APP_ENCRYPTION_KEY` in
  `var/secrets/generated.env` (oder deine Compose-Datei) einträgst, **bevor irgendetwas gespeichert
  wurde**, und neu startest. Tu das nur, wenn du auch die alte *Datenbank* wiederherstellst, deren
  Zeilen damit verschlüsselt sind — siehe [Sichern und Wiederherstellen](backup-restore.md). Die
  Prüfung führt die Zeile genau für diesen Fall unter *Gut zu wissen* auf; es ist der eine Wert, den
  der Import nie für dich schreibt, weil die Zugangsdaten, die er gerade geschrieben hat, mit dem
  aktuell geltenden Schlüssel verschlüsselt sind und ein Austausch darunter sie unlesbar machen
  würde.

Das Zweite *nach* einem Import zu tun, der bereits Zugangsdaten geschrieben hat, hinterlässt Zeilen
unter dem einen Schlüssel und einen Prozess mit dem anderen — was `app:secrets:init` beim nächsten
Start erkennt und woraufhin es den Start verweigert.

## Fallstricke

**Die Sicherung ist keine Datensicherung und gibt auch nicht vor, eine zu sein.** Keine E-Mails,
keine Kalendereinträge, keine Kontakte, keine Anhänge. Die Personen und alles, was sie eingerichtet
haben, bringt sie allerdings mit: Spielst du eine auf einen frischen Host zurück und hörst dort auf,
hast du eine funktionierende Installation, bei der sich alle anmelden können — mit leeren Postfächern,
bis die erste Synchronisierung ihre Mail wieder von den Servern holt, deren Zugangsdaten die Datei
wiederhergestellt hat. `app:backup` ist nach wie vor die andere Hälfte; beide Seiten sind aus gutem
Grund im Index verlinkt.

**Eine Wiederherstellung rührt einen bereits vorhandenen Benutzer nicht an.** Weder sein Passwort
noch seinen zweiten Faktor noch irgendeine Einstellung. Das ist das richtige Verhalten und steht
vollständig unter [Benutzer](#benutzer-beim-import) — es heißt aber auch, dass eine
Konfigurationssicherung kein Weg ist, die Einstellungen einer Person zurückzurollen.

**Verlierst du das Passwort, ist die Datei weg.** Es gibt keine Wiederherstellung, keinen Hinweis und
kein Zurücksetzen. Argon2id macht Raten teuer; es macht es nicht möglich. Bewahre das Passwort dort
auf, wo du die Datei aufbewahrst — oder dort, wo du in einem Jahr noch hinkommst.

**Ein falsches Passwort und eine beschädigte Datei sind derselbe Fehler.** Poly1305 authentifiziert
das gesamte Chiffrat, plMail kann dir also wirklich nicht sagen, was von beidem es war. Wenn du dir
beim Passwort sicher bist, verdächtige die Übertragung, aus der die Datei stammt.

**Eine Wiederherstellung auf einer *laufenden* Installation ersetzt Zugangsdaten — die des
Betreibers, nicht die der Benutzer.** Die Prüfung markiert diese Zeilen mit „ersetzt einen anderen
Wert“ statt mit „hier noch nicht gesetzt“. Lies diese Spalte: Für die Umgebung, die Secrets-Dateien
und die Anbieter-Registrierungen ist ein Import keine Zusammenführung, und der überschriebene
Firebase-Schlüssel ist derjenige, gegen den die Geräte in den Hosentaschen der Leute registriert
sind. Benutzer sind die Ausnahme, und zwar die einzige: Ein vorhandenes Konto wird nie überschrieben.

**Anwenden startet nichts neu.** Die Datenbankhälfte wirkt sofort. Alles, was im Secrets-Volume
liegt, steht sofort auf der Platte und ist erst nach einem Neustart *in Kraft* — und bis du neu
startest, läuft die Installation weiter auf den erzeugten Geheimnissen der neuen Maschine, was ein
funktionierender Zustand ist, der wie eine fertige Wiederherstellung aussieht.

**Exportieren ist nicht folgenlos, und es wiegt jetzt schwerer.** Die entstehende Datei ist eine
vollständige Offline-Kopie jedes Geheimnisses der Installation, mit unbegrenzt vielen Rateversuchen —
und dazu gehören nun der Passwort-Hash, das TOTP-Geheimnis und das Postfach-Passwort jedes Benutzers,
nicht nur die des Administrators. Wer eine solche Datei exportiert, hält die Zugangsdaten aller in
einer einzigen Datei in der Hand. Eine Sicherung, die im Download-Ordner liegen bleibt, ist eine
schlimmere Preisgabe als alles, wogegen diese Funktion schützt.
