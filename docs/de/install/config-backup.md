<!-- translated-from: install/config-backup.md sha1:f60f806bd4e9a94ed5b982886afad388ac348ea5 -->
# Konfigurationssicherung

Die *Konfiguration* einer Installation ist nicht dasselbe wie ihre *Daten*, und beide gehen auf
unterschiedliche Weise verloren. Die Daten zu verlieren ist eine Katastrophe; die Konfiguration zu
verlieren ist ein Dienstagnachmittag, an dem du herausfindest, wo der Firebase-Schlüssel geblieben
ist, zu welchem Google-Projekt der OAuth-Client gehörte und wie `APP_PUBLIC_URL` früher lautete.
[Sichern und Wiederherstellen](backup-restore.md) behandelt die Daten. Diese Seite behandelt das
andere: **Administration → Sicherung**, wo jede Einstellung und jede Zugangsdatei dieser Installation
in einer einzigen passwortverschlüsselten Datei landet — und wieder zurückkommt.

Keine einzige Nachricht, kein Kontakt, kein Kalender und kein Benutzerkonto steckt darin. Das ist
Absicht: Die Datei ist wenige Kilobyte groß, passt also in einen Passwortmanager, und sie auf eine
frische Installation zurückzuspielen ist etwas Ungefährliches statt etwas, das man einmal macht und
nie wieder.

## Wo Konfiguration tatsächlich liegt

An drei Orten, und zu wissen, welcher welcher ist, ist der größte Teil davon zu verstehen, was ein
Import tut und wann er wirkt.

| Wo | Was dort liegt | Kann plMail das schreiben? |
|---|---|---|
| **Die Datei mit den erzeugten Geheimnissen** | `APP_SECRET`, `APP_ENCRYPTION_KEY`, `MERCURE_JWT_SECRET`, die VAPID-Schlüssel, die OAuth-Zugangsdaten, `APP_PUBLIC_URL` — `var/secrets/generated.env`, beim ersten Start erzeugt und vom Entrypoint geladen, bevor sonst irgendetwas läuft | **Ja**, und die Werte wirken ab dem nächsten Containerstart |
| **Das Secrets-Volume** | `jwt/private.pem`, `jwt/public.pem`, `postgres_password` — Dateien daneben, auf dem Volume `app_secrets`, das jeder Dienst einbindet | **Das Schlüsselpaar ja.** Pro Datei und pro Installation gemessen |
| **Die Datenbank** | Das Firebase-Projekt, die Mail-OAuth-Registrierungen, die Einstellungen der Integrationsanbieter — alles, was ein Administrator in ein Formular getippt hat statt in eine Datei | **Ja**, sofort |

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
mitgelieferten `compose.yaml` sind das drei Namen, nicht mehr: `MAILER_DSN`,
`MESSENGER_TRANSPORT_DSN` und `MERCURE_PUBLIC_URL` — die einzigen gesicherten Variablen, die sie auf
einen nichtleeren Vorgabewert festlegt. Alles Übrige reicht sie als `${NAME:-}` durch.

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
APP_ENCRYPTION_KEY  APP_SECRET  DATABASE_URL  POSTGRES_PASSWORD
MERCURE_JWT_SECRET  MERCURE_PUBLIC_URL  JWT_PASSPHRASE
MAILER_DSN  MESSENGER_TRANSPORT_DSN  APP_PUBLIC_URL
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
`JWT_PUBLIC_KEY`, `APP_STORAGE_DIR`, `APP_SHARE_DIR` und `MERCURE_URL`. Der *Inhalt* der
JWT-Schlüssel reist mit; die Pfade, unter denen sie liegen, gehören der jeweils lesenden Installation,
und `MERCURE_URL` ist die netzinterne Adresse eines Nachbarcontainers. Jede davon ist in der
[Konfigurationsreferenz](configuration.md) beschrieben.

**Dateien**, adressiert über einen logischen Namen statt über einen Pfad, damit das Ziel sie dorthin
legt, wo seine eigene Konfiguration sie erwartet:

```
jwt/private.pem  jwt/public.pem  postgres_password
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

**Diese werden entschlüsselt exportiert.** In der Datenbank liegen sie in
`encrypted_string`-Spalten, lesbar nur mit dem `APP_ENCRYPTION_KEY`, der sie geschrieben hat — und
der ganze Sinn einer Konfigurationssicherung ist, dass sie anderswo geöffnet wird, von einer
Installation mit einem anderen Schlüssel. Chiffrat wäre totes Gewicht. Das Passwort des Umschlags ist
der Schutz; die Spaltenverschlüsselung ist ein anderer Schutz gegen eine andere Bedrohung, und beides
übereinanderzulegen ergäbe eine Datei, die sicher und nutzlos ist.

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
  "version": 1,
  "exportedAt": "2026-08-06T12:00:00+00:00",
  "instance": "https://mail.example.com",
  "env": { "APP_SECRET": "…" },
  "files": { "jwt/private.pem": "<base64>" },
  "database": { "fcmConfig": { "serviceAccountJson": "…" } }
}
```

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
| **bewusst behalten** | Nicht geschrieben, und das ist das richtige Ergebnis. Nur `APP_ENCRYPTION_KEY` | Ein Hinweis, und die Zeile für den einen Fall, der sie braucht |
| **nicht schreibbar** | Der Pfad hat den Schreibvorgang verweigert — schreibgeschütztes Secrets-Volume, falsche UID, volle Platte | Die Zeile oder der Pfad, wie bisher |

Abschnitt für Abschnitt:

| Abschnitt | Was passiert |
|---|---|
| `database` (Firebase, Mail-OAuth, Integrationen) | **angewendet**, neu verschlüsselt mit dem `APP_ENCRYPTION_KEY` *dieser* Installation. plMail besitzt diese Zeilen vollständig |
| `files` → `jwt/private.pem`, `jwt/public.pem` | **wirkt nach dem nächsten Neustart**, dort wo der Prozess sie schreiben kann. Zur Prüfzeit mit `is_writable` gemessen, nicht angenommen — ein schreibgeschützt eingebundenes Secrets-Volume ist ein unterstützter Betrieb. Die Bytes landen sofort; lexik liest den Schlüssel einmal pro Prozess, die Tokens *dieses* Containers werden also bis zum Neustart weiter mit dem alten signiert |
| `files` → `postgres_password` | **extern**, immer. Siehe unten |
| `env` → `POSTGRES_PASSWORD`, `DATABASE_URL` | **extern**, immer. Siehe unten |
| `env` → `APP_ENCRYPTION_KEY` | **bewusst behalten.** Siehe [Speziell zu `APP_ENCRYPTION_KEY`](#speziell-zu-app_encryption_key) |
| `env` → alles Übrige | **wirkt nach dem nächsten Neustart**, oder **von Compose überdeckt**, wo etwas denselben Namen festlegt |

#### Warum `POSTGRES_PASSWORD` der eine bleibt, der Handarbeit erfordert

Weil der Entrypoint die *Datei* bei jedem Start abgleicht, Postgres sie aber nur *einmal* liest.

`generate-secrets.sh` schreibt `var/secrets/postgres_password` bei jedem einzelnen Lauf aus der Zeile
`POSTGRES_PASSWORD=` in `generated.env` neu, diese beiden laufen also nie auseinander. Aber
`compose.yaml` reicht diese Datei als `POSTGRES_PASSWORD_FILE` an die Datenbank weiter, und das
offizielle Postgres-Image liest sie bei **initdb** — wenn das Datenverzeichnis angelegt wird, und nie
wieder. Auf einer bereits vorhandenen Datenbank behält die Rolle das Passwort, mit dem sie angelegt
wurde.

Das `POSTGRES_PASSWORD` des alten Hosts in einen laufenden Stack zurückzuspielen ergäbe also eine
`generated.env` und eine Datei `postgres_password`, die miteinander übereinstimmen und sonst mit
nichts, eine zusammengesetzte `DATABASE_URL` mit einem Passwort, das die Rolle nicht hat, und eine
Anwendung, die beim nächsten Start ihre eigene Datenbank nicht erreicht. plMail ist Client dieser
Datenbank, nicht ihr Administrator; es kann die andere Hälfte nicht erledigen, also tut es keines von
beidem und sagt warum. `DATABASE_URL` folgt aus demselben Grund um die Ecke — jede Sicherung enthält
eine, zusammengesetzt aus dem Passwort und dem Host der *Quelle*, und eine DSN mit Passwort
unterdrückt die eigene Zusammensetzung des Ziels.

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
3. **Das Administratorkonto anlegen.** Die Wiederherstellung tut das nicht: Eine
   Konfigurationssicherung enthält Konfiguration, niemals Personen. Die Seite führt anschließend
   hierher zurück.
4. **Den Stack einmal neu starten.** Danach kommt die Instanz als die hoch, von der die Sicherung
   stammt.

Schritt 2 kommt vor Schritt 3, weil `/install` endgültig schließt, sobald das erste Konto existiert —
und weil die beiden Administratorschritte des Einrichtungsassistenten anhand von „ist schon etwas
konfiguriert?“ entscheiden, ob sie zutreffen; eine zuvor durchgeführte Wiederherstellung führt den
neuen Administrator also direkt an ihnen vorbei.

Schritt 4 ist nötig, weil der erste Start bereits stattgefunden hat: Der Entrypoint hat die eigenen
Geheimnisse dieser Instanz erzeugt und in die laufenden Prozesse geladen, bevor du überhaupt
`/install` erreicht hast, und diese Prozesse lesen ihre Umgebung genau einmal. Die
wiederhergestellten Werte liegen ab dem Druck auf „Anwenden“ auf der Platte; der Neustart setzt sie
in Kraft. Er kann bis nach dem Konto warten, und die Prüfseite sagt das auch.

Falls die Prüfung etwas unter *Das musst du selbst erledigen* aufgeführt hat, ist das deine restliche
Arbeit, und jede Zeile sagt warum. Auf einem unveränderten Stack sind das höchstens die zwei oder
drei Namen, die `compose.yaml` festlegt, plus das Datenbankpasswort.

Der Einstiegspunkt für die Wiederherstellung ist durch genau dasselbe geschützt wie `/install`:
dadurch, dass die Installation keine Benutzer hat. Sobald einer existiert, antwortet er mit 404, und
von da an ist die Seite **Administration → Sicherung**.

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

**Die Sicherung ist keine Datensicherung und gibt auch nicht vor, eine zu sein.** Keine E-Mails, keine
Kalender, keine Benutzer, keine Anhänge. Wenn du eine auf einen frischen Host zurückspielst und dort
aufhörst, hast du ein korrekt konfiguriertes plMail ohne Inhalt. `app:backup` ist die andere Hälfte;
beide Seiten sind aus gutem Grund im Index verlinkt.

**Verlierst du das Passwort, ist die Datei weg.** Es gibt keine Wiederherstellung, keinen Hinweis und
kein Zurücksetzen. Argon2id macht Raten teuer; es macht es nicht möglich. Bewahre das Passwort dort
auf, wo du die Datei aufbewahrst — oder dort, wo du in einem Jahr noch hinkommst.

**Ein falsches Passwort und eine beschädigte Datei sind derselbe Fehler.** Poly1305 authentifiziert
das gesamte Chiffrat, plMail kann dir also wirklich nicht sagen, was von beidem es war. Wenn du dir
beim Passwort sicher bist, verdächtige die Übertragung, aus der die Datei stammt.

**Eine Wiederherstellung auf einer *laufenden* Installation ersetzt Zugangsdaten.** Die Prüfung
markiert diese Zeilen mit „ersetzt einen anderen Wert“ statt mit „hier noch nicht gesetzt“. Lies
diese Spalte: Ein Import ist keine Zusammenführung, und der überschriebene Firebase-Schlüssel ist
derjenige, gegen den die Geräte in den Hosentaschen der Leute registriert sind.

**Anwenden startet nichts neu.** Die Datenbankhälfte wirkt sofort. Alles, was im Secrets-Volume
liegt, steht sofort auf der Platte und ist erst nach einem Neustart *in Kraft* — und bis du neu
startest, läuft die Installation weiter auf den erzeugten Geheimnissen der neuen Maschine, was ein
funktionierender Zustand ist, der wie eine fertige Wiederherstellung aussieht.

**Das `postgres_password` in der Sicherung ist nicht das Passwort der Datenbank, in die du
wiederherstellst.** Es ist das vom alten Host, und plMail weigert sich aus dem
[oben](#warum-postgres_password-der-eine-bleibt-der-handarbeit-erfordert) dargelegten Grund, es zu
schreiben. Es wird mitgeführt, weil eine Wiederherstellung des alten *Volumes* es byteweise braucht;
es in einen neuen Stack einzutragen, dessen Postgres mit einem anderen initialisiert wurde, bringt
dir beim nächsten Start „password authentication failed“ und sonst nichts.

**Exportieren ist nicht folgenlos.** Die entstehende Datei ist eine vollständige Offline-Kopie jedes
Geheimnisses der Installation, mit unbegrenzt vielen Rateversuchen. Eine Sicherung, die im
Download-Ordner liegen bleibt, ist eine schlimmere Preisgabe als alles, wogegen diese Funktion
schützt.
