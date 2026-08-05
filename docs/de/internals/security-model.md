<!-- translated-from: internals/security-model.md sha1:d734e08ef70b3dfe29172a2a6cf416e9326b8e41 -->
# Sicherheitsmodell

Verschlüsselung ruhender Daten und die Prüfung, die den Start ohne brauchbaren Schlüssel
verweigert, die Datei mit den generierten Geheimnissen, die Ablage von Passwörtern und Token,
Zwei-Faktor und gemerkte Geräte, App-Passwörter, der SSRF-Schutz, und was genau ein öffentlicher
Freigabelink oder eine Buchungsseite erreichen kann. Die Seite für Anwender ist
[Sicherheit](../features/security.md); die betriebliche Wiederherstellung steht in
[Sicherung und Wiederherstellung](../install/backup-restore.md).

Die Regel, die sich durch all das zieht: **Mach die Invariante strukturell, wo du kannst, und
dokumentiere sie, wo du es nicht kannst.** Ein Unique Constraint schlägt einen Kommentar, der vor
doppelten Einträgen warnt, ein DTO ohne Titel schlägt es, in einem Template an eine Flag-Prüfung
zu denken, und eine Prüfung, die den Start verweigert, schlägt eine Logzeile, die niemand liest.

## Verschlüsselung ruhender Daten

`App\Infrastructure\Encryption\Encryptor` ist libsodium-secretbox — XSalsa20-Poly1305, also
Vertraulichkeit *plus* Integrität: Ein manipulierter Geheimtext lässt sich nicht öffnen, statt zu
Müll zu entschlüsseln. Je Verschlüsselung wird eine frische Zufalls-Nonce erzeugt und
vorangestellt, weshalb dasselbe zweimal verschlüsselt unterschiedliche Ausgaben ergibt — korrekt
so, und zugleich der Grund, warum **diese Spalten sich nicht durchsuchen und nicht dem Wert nach
vergleichen lassen**.

Das gespeicherte Format ist selbstbeschreibend:

```
enc:v1:<base64(nonce || ciphertext)>
```

Das `Encryptor::PREFIX` gibt es, damit `EncryptedStringType` einen verschlüsselten Wert von einem
alten Klartextwert unterscheiden kann und damit ein künftiger Algorithmuswechsel die Version
eindeutig hochzählen kann.

Der Schlüssel wird bei der **ersten Verwendung geprüft, nicht im Konstruktor**. Ein Image wird
gebaut, bevor die Installation, auf der es laufen wird, einen Schlüssel hat, und die Erzeugung
passiert beim Containerstart — die Prüfung im Konstruktor ließ `cache:clear` während des
Docker-Builds an einem Schlüssel scheitern, den es zu diesem Zeitpunkt gar nicht geben sollte.
Nichts wird dadurch aufgeweicht: Sowohl `encrypt()` als auch `decrypt()` gehen durch denselben
Zugriff, ein fehlender oder fehlgeformter Schlüssel scheitert also weiterhin laut in dem Moment,
auf den es ankommt, und es gibt keinen Pfad, der stattdessen Klartext schriebe.

`App\Infrastructure\Doctrine\Type\EncryptedStringType` ist der Doctrine-Typ
(`type: EncryptedStringType::NAME`, also `encrypted_string`). Die Spalte bleibt `TEXT`, seine
Einführung braucht also keine Schema-Migration — nur die Werte ändern ihre Form. Doctrine
instanziiert Typen über eine statische Registry ohne Konstruktor-Injektion, weshalb der Container
den `Encryptor` beim Start aus `Kernel::boot()` heraus übergibt — und genau deshalb ist dieser
eine Service in `config/services.yaml` als `public` deklariert.

**Alter Klartext wird beim Lesen durchgereicht, statt eine Exception zu werfen**, damit eine
Instanz aus der Zeit vor der Verschlüsselung benutzbar bleibt — du kannst die Kontenseite
weiterhin öffnen und die betroffenen Konten löschen oder neu eintragen. Nichts wird nachträglich
befüllt, und der Docblock sagt warum: Die alten Werte sind per Definition lesbar, und sie
stillschweigend neu zu schreiben suggerierte eine Zusicherung, die die Sicherungen und
WAL-Segmente, in denen der Klartext weiterhin steht, nicht decken.

Ein fehlgeschlagenes Entschlüsseln zeigt sich als Doctrine-`ConversionException`, die die Spalte
benennt — was zählt, wenn die Ursache ein geänderter `APP_ENCRYPTION_KEY` ist und schlagartig
jedes Anmeldegeheimnis scheitert.

Was durch diesen Typ geht: Postfachpasswörter, OAuth-Refresh-Token und `User::$totpSecret`.

### Die Schlüsselprüfung

`App\Infrastructure\Setup\EncryptionKeyProbe` läuft einmal je Containerstart und prüft, ob der
geltende Schlüssel die bereits in der Datenbank liegenden Anmeldedaten öffnen kann. Die
Hydration *ist* die Prüfung: Sie lädt ein Konto mit gespeicherten Anmeldedaten, und
`EncryptedStringType` entschlüsselt beim Lesen.

Sie erkennt die zwei Arten, auf die eine Einrichtung mit generierten Geheimnissen schiefgeht, die
beide sonst lautlos wären:

1. Einem Service fehlt das Volume, auf dem die generierten Geheimnisse liegen, er prägt sich also
   einen eigenen Schlüssel und steht damit im Widerspruch zu den Services, die die Daten
   geschrieben haben.
2. `APP_ENCRYPTION_KEY` war in der Umgebung gesetzt, diese Einstellung fiel weg, und darüber
   wurde ein frischer Schlüssel generiert.

Beides funktioniert bis genau zu dem Moment, in dem ein Sync-Worker versucht, sich an einem
Postfach anzumelden — und ein Konto unter dem falschen Schlüssel neu zu speichern überschriebe
Daten, die der richtige Schlüssel noch hätte lesen können. Beim Start zu scheitern kostet eine
unleserliche Fehlermeldung; nicht zu scheitern kostet die Anmeldedaten.

**Fatal ist sie nur beim Start des Servers.** Ein Konsolenaufruf warnt und läuft weiter, denn eine
Verweigerung blockierte genau das Kommando, das die Lage repariert — `app:reset --full`. Diese
Asymmetrie ist das deutlichste Beispiel für die Regel dieser Codebasis zum lauten Verweigern:
Fail-fast gilt dort, wo der Fehlschlag schlimmer ist als der Stillstand, und nicht pauschal.

Eine `DbalException` — noch keine `account`-Tabelle — wird geschluckt: Eine Datenbank, die nicht
migriert wurde, hat nichts zu schützen.

## Die Geheimnisdatei

`App\Infrastructure\Setup\GeneratedSecretsFile` ist die eine Datei je Installation, die enthält,
was niemand von Hand konfiguriert hat, unter `APP_SECRETS_FILE` (standardmäßig
`var/secrets/generated.env`, auf einem Volume, das **jeder** Service einhängt).

`frankenphp/generate-secrets.sh` legt sie an und schreibt `APP_SECRET` und `APP_ENCRYPTION_KEY`,
noch bevor PHP überhaupt startet, denn der Kernel selbst braucht diese beiden. Alles, was warten
*kann*, bis PHP läuft, ergänzt `app:secrets:init`, damit es weiterhin nur einen Ort zum Sichern
gibt. `config/bootstrap_generated_secrets.php`, geladen über Composers `autoload.files`, macht
die Werte auch für ein anders gestartetes PHP sichtbar — `docker compose exec … bin/console`
umgeht den Entrypoint vollständig.

Schreibvorgänge nehmen ein `flock`, und `ensure()` **liest unter dem Lock erneut**, weil ein
anderer Service den Wert zwischen dem ersten Lesen und dem Erwerb des Locks geschrieben haben
könnte. Vier Services starten gleichzeitig aus demselben Image, und dass zwei davon dasselbe
Geheimnis unabhängig voneinander erzeugen, ist ein Fehlschlag, der sich viel später zeigt: als
Daten, die ein Container lesen kann und ein anderer nicht.

**Alles, was ausdrücklich gesetzt ist, gewinnt.** Über einen vorgegebenen Wert wird nie etwas
generiert — weshalb `APP_SECRET`, `APP_ENCRYPTION_KEY`, `DATABASE_URL` und `MERCURE_JWT_SECRET`
in der eingecheckten `.env` leer sind: `docker compose` löst `${VAR}` aus dieser Datei auf, ein
Wert dort wird also in jedem Container zu einer echten Umgebungsvariablen und schaltet die
Generierung dafür ab.

`App\Infrastructure\Setup\DefaultSecretsGuard` deckt den Fall ab, den die Generierung nicht
abdecken kann: eine Installation, die eine alte compose-Datei oder die dokumentierten Platzhalter
mitschleppt. Er hält die wörtlich ausgelieferten Werte für `APP_SECRET`, `APP_ENCRYPTION_KEY` und
`MERCURE_JWT_SECRET` sowie `SHIPPED_DB_PASSWORD` (`!ChangeMe!`), das *innerhalb* von
`DATABASE_URL` auftaucht, statt der ganze Wert zu sein. Diese Werte funktionieren tadellos, und
genau das ist das Problem — nichts scheitert, und die Installation bleibt für jeden lesbar, der
das Repository hat. Geprüft wird nur in `prod`; von einer Entwicklungsumgebung wird erwartet, dass
sie auf den eingecheckten Werten läuft, denn dafür sind sie da.

**Der Schlüssel lässt sich unter einem laufenden Stack nicht rotieren.** Die anderen Services
halten den alten Schlüssel im Prozessspeicher, bis sie neu starten; eine Zeit lang kann also die
Hälfte von ihnen nicht lesen, was die andere Hälfte schreibt. Deshalb lässt `app:reset --full`
die Geheimnisse standardmäßig in Ruhe und `--rotate-secrets` ist ein eigener, laut gewarnter
Schalter. `POSTGRES_PASSWORD` wird überhaupt nie rotiert: Postgres wurde damit initialisiert und
führt seine eigene Kopie.

## Was gehasht wird, und womit

Drei verschiedene Antworten, und die Wahl ist jedes Mal bewusst.

| Geheimnis | Ablage | Warum |
|---|---|---|
| Nutzerpasswort | Symfonys `auto`-Hasher (Argon2/bcrypt) | wenig Entropie, von einem Menschen gewählt — Key Stretching ist der ganze Sinn |
| App-Passwort (`ApiToken`) | SHA-256 des Geheimnisses | 32 Byte aus dem CSPRNG; nichts zum Durchprobieren |
| Cookie für vertrauenswürdige Geräte | SHA-256 | 32 Byte aus dem CSPRNG |
| Token für Freigabelink / Buchungsseite | SHA-256 | 32 Byte aus dem CSPRNG |
| Geräte-Kopplungscode | SHA-256, als Cache-Schlüssel | 32 Byte aus dem CSPRNG, nach zwei Minuten tot |
| TOTP-Wiederherstellungscodes | SHA-256-Digests in einer jsonb-Liste | je 64 Bit aus dem CSPRNG |
| TOTP-Geheimnis | `encrypted_string` — umkehrbar | der Server muss es *verwenden* können |

`App\Service\Calendar\Sharing\PublicLinkToken` legt die Begründung für die CSPRNG-Fälle am
vollständigsten dar: Argon2 existiert, um ein Geheimnis mit wenig Entropie teuer zu erraten zu
machen, dieses Geheimnis hat 256 Bit Entropie und es wird nichts geraten, und **gebraucht wird
ein Lookup auf einer indizierten Spalte, den ein absichtlich langsamer Hash nicht leisten kann**
— der Digest ist die `WHERE`-Klausel, jede öffentliche Anfrage bezahlte also den Arbeitsfaktor,
bevor sie die Zeile überhaupt finden könnte.

Das TOTP-Geheimnis ist die Ausnahme, die die Form bestätigt: Es ist verschlüsselt statt gehasht,
weil der Server daraus Codes erzeugen muss, es gehört also in dieselbe Schublade wie ein
Postfachpasswort.

Zwei kleinere Details, die Nachahmung verdienen. `ApiToken::PREFIX` ist `plmail_`, damit der
Authenticator ein App-Passwort schon durch Hinsehen von einem JWT unterscheiden kann und ein
geleaktes greppbar ist; und die ersten paar Zeichen des Tokens werden als `$hint` im Klartext
behalten, damit die Einstellungsliste zeigen kann, welches Anmeldegeheimnis welches ist, ohne es
rekonstruieren zu können.

`User::$backupCodes` wird bei jedem Schreibvorgang neu indiziert —
`set (array $codes) => array_values($codes)` —, denn `array_filter()` hinterlässt Lücken, und ein
lückenhaftes PHP-Array kodiert als `{"1":"…"}` statt als Liste und kommt damit in der falschen
Form zurück.

## Firewalls

`config/packages/security.yaml` deklariert drei: `dev`, `jmap` und `main`.

**`jmap` ist zustandslos** und teilt sich zwei Arten von Anmeldegeheimnissen: langlebige
App-Passwörter und kurzlebige JWTs für eine künftige eigene App.
`App\Security\ApiTokenAuthenticator::supports()` muss genau darüber Bescheid wissen, welche
Anfragen die eigenen sind — **Basic ist es immer** (JWT hat keine Basic-Form) und **Bearer nur
dann, wenn das Anmeldegeheimnis das Präfix `plmail_` trägt**, denn ein JWT ist base64url und
beginnt mit `ey`, die beiden lassen sich also nie verwechseln.
`App\Security\JwtBearerTokenExtractor` dekoriert den Header-Extractor von lexik, damit der
JWT-Authenticator ein App-Passwort nicht für sich beansprucht (und dann daran scheitert).

Beide Formen, die reale Clients senden, werden akzeptiert:

```
Authorization: Bearer plmail_xxx…                 (ltt.rs)
Authorization: Basic base64(email:plmail_xxx…)    (Sterna, and most IMAP-era clients)
```

Der Basic-Benutzername wird **gegen die Eigentümerin des Tokens geprüft** statt ignoriert — einem
Client, der die falsche Adresse schickt, wird das gesagt, statt dass er stillschweigend als
diejenige agiert, der das Token gehört. `lastUsedAt` wird höchstens alle
`LAST_USED_TTL_SECONDS` (300) neu geschrieben, denn Clients fragen ständig nach, und jeder
JMAP-Aufruf löste sonst einen Schreibvorgang aus.

**`main` bringt eine Anmeldedrosselung mit**, 5 Versuche je 15 Minuten. Die Grenze je Benutzername
ist die, auf die es ankommt; die globale IP-Grenze ist die Absicherung gegen das Durchprobieren
eines Passworts über viele Adressen und ist bewusst lockerer, damit ein Haushalt hinter einer
NAT-Adresse sich nicht selbst aussperren kann. Remember-me arbeitet signaturbasiert mit 60 Tagen
Lebensdauer: keine Speicherung, und ein Passwortwechsel entwertet jedes dafür ausgegebene Cookie.

### Zugriffssteuerung, und die Endpunkte, die keine Session halten können

Die `access_control`-Liste in `security.yaml` ist regelweise kommentiert, denn jeder
`PUBLIC_ACCESS`-Eintrag ist eine Entscheidung:

| Pfad | Warum öffentlich | Was den Aufrufer nachweist |
|---|---|---|
| `/healthz` | Docker-Healthchecks und Uptime-Monitore halten keine Session | nichts — es meldet nur Urteile |
| `/gmail/push` | Google Pub/Sub ist der Aufrufer | gemeinsames Geheimnis |
| `/webhook/graph…` | Microsoft ist der Aufrufer | je Subscription geprägtes `clientState` |
| `/webhook/google/calendar` | Google ist der Aufrufer | Kanal-Token in `X-Goog-Channel-Token` |
| `/device/pair` | ein Gerät, das sich authentifizieren könnte, bräuchte keine Kopplung | der Kopplungscode selbst |
| `/share/…` | die Empfängerin hat per Definition kein Konto | das Token im Pfad |
| `/book/…` | ebenso | das Token im Pfad, plus ein Rate Limiter auf dem POST |
| `/install` | es legt die erste Administratorin an, kann also keine voraussetzen | `InstallGuard` |
| `/2fa` | das Passwort ist da, der zweite Faktor nicht | `IS_AUTHENTICATED_2FA_IN_PROGRESS` |

`/healthz` antwortet mit Urteilen und sonst nichts — keine Zahlen, keine Adressen, keine Version
—, denn wer den Port erreichen kann, kann es lesen. Es liefert nur dann 503, wenn die Datenbank
ausgefallen ist, denn das ist der eine Fehlschlag, bei dem Ausliefern unmöglich ist; eine
aufgestaute Warteschlange bleibt 200, denn Mail ist dann verspätet und nicht verloren, und den
Container neu zu starten hülfe nicht. `HealthTest` prüft diese Form, sodass eine Ergänzung, die
etwas preisgibt, die Suite scheitern lässt.

`App\Service\Setup\InstallGuard` ist ein einziges Prädikat — „gibt es null Nutzer?" — und es ist
die gesamte Sicherheitsgeschichte von `/install`. Er wirft eine `NotFoundHttpException`, statt
auf die Anmeldeseite umzuleiten, denn **eine Umleitung bestätigt, dass der Endpunkt existiert und
lediglich geschlossen ist**.

## Zwei-Faktor-Authentifizierung

TOTP über `scheb/2fa`, nur auf der `main`-Firewall. `/jmap` ist bewusst nicht abgedeckt:
App-Passwörter gibt es gerade deshalb, weil ein IMAP- oder JMAP-Client keinen sechsstelligen Code
vorzeigen kann, und der Weg, dort Zugriff zu entziehen, ist die Liste der App-Passwörter.

`RememberMeToken` steht in den `security_tokens` von `scheb_2fa.yaml`, und diese Zeile ist
tragend: Remember-me ist ein Geheimnis, das der Browser speichert, für sich genommen also
weiterhin ein Faktor — ohne diese Zeile spaziert das 60-Tage-Cookie am zweiten Faktor vorbei und
hebt die Funktion stillschweigend auf.

Die TOTP-Parameter sind fest an `User` verdrahtet — `TOTP_ALGORITHM` SHA-1, `TOTP_PERIOD` 30,
`TOTP_DIGITS` 6 —, weil Google Authenticator die Parameter `algorithm` und `digits` im
`otpauth://`-URI ignoriert und ungeachtet dessen genau diese annimmt. Machst du es falsch, wird
bei der Einrichtung sauber gescannt und danach jeder Code abgelehnt, ohne dass eine der beiden
Seiten sagte, warum; `TwoFactorEnrolmentTest` erzeugt mit otphp echte Codes, um zu belegen, dass
die in den QR-Code geschriebene Konfiguration und die, gegen die geprüft wird, nicht
auseinandergelaufen sind.

`leeway: 15` toleriert Uhrenabweichungen, die auf einem Heimserver oder einem NAS ein ganz
gewöhnlicher Dienstag sind. Der Wert **muss unter der Periode bleiben**: otphp wirft für alles
`>= 30` ein `The leeway must be lower than the TOTP period` — kein engeres Fenster, sondern eine
Exception bei jeder Prüfung.

`$totpSecret` und `$totpConfirmedAt` sind mit Absicht getrennt und beide `private(set)`. Die
Einrichtung schreibt zuerst das Geheimnis, damit der QR-Code gescannt werden kann, und ein nie
bestätigtes Geheimnis darf niemanden aussperren — `isTotpAuthenticationEnabled()` verlangt
beides. Das `private(set)` ist es, was verhindert, dass ein Geheimnis ausgetauscht wird, ohne den
Bestätigungszustand daneben zurückzusetzen.

`App\Security\TwoFactor\TwoFactorThrottle` ist die Drosselung, die die Firewall nicht liefert.
Deren `login_throttling` endet beim Passwortformular; alles danach — der sechsstellige Code und
die Wiederherstellungscodes — ist das, was eine Angreiferin mit einem gestohlenen oder
abgephishten Passwort tatsächlich erreicht. Sechs Ziffern sind 10⁶ innerhalb eines Fensters, das
otphp auf etwa eine Minute verbreitert, was ungedrosselt ein paar Stunden Anfragen bedeutet.
`config/packages/rate_limiter.yaml` setzt `two_factor_code` auf 5 je 15 Minuten, gleitendes
Fenster, **je Nutzerin geschlüsselt**: Das erratene Geheimnis gehört zu einem Konto, und ein
IP-Schlüssel erlaubte es jedem, der sich eine Adresse teilt, alle anderen aus ihren Konten
auszusperren. Nur Fehlschläge verbrauchen Token, ein Erfolg setzt den Zähler zurück, und die
Verweigerung passiert **bevor** der Code geprüft wird.

`app:user:2fa-disable` gibt es nur auf der Konsole und ist bewusst nicht über die
Web-Oberfläche für Administratorinnen zugänglich. Eine Admin-Person, die einer anderen Nutzerin
den zweiten Faktor aus dem Browser heraus nehmen könnte, wäre ein zweiter Weg in jedes Postfach
der Installation, erreichbar mit nichts als einer gestohlenen Admin-Session.

### Gemerkte Geräte sind Zeilen, keine Cookies

scheb bringt vertrauenswürdige Geräte bereits mit, und plMail ersetzt dessen Manager durch
`App\Security\TwoFactor\DatabaseTrustedDeviceManager`, verdrahtet über `trusted_device.manager`.

Der Originalmanager legt die gesamte Erlaubnis ins Cookie: ein JWT mit einem Benutzernamen und
einer Version, signiert mit `APP_SECRET`. Zustandslos und schnell, und **nicht zurücknehmbar** —
ein gestohlenes Cookie bleibt seine volle Lebensdauer gültig, und die einzige angebotene
Widerrufsmöglichkeit ist, die Version hochzuzählen, was alle Geräte der Nutzerin auf einmal
hinauswirft.

Hier ist das Cookie ein opakes 32-Byte-Geheimnis und die Erlaubnis eine Zeile in
`trusted_device`; die Einstellungsseite kann also auflisten, was vertraut ist, und ein Widerruf
wirkt schon bei der allernächsten Anfrage dieses Geräts. Der Preis ist ein indizierter Lookup je
Anfrage auf einem Konto mit aktivem 2FA.

`TrustedDevice::$firewall` wird gespeichert, weil eine Erlaubnis, die für die sessiongestützte
Web-Anmeldung ausgegeben wurde, nicht von irgendetwas anderem honoriert werden darf, das dieser
Tabelle später vertraut. `$label` — „Firefox auf macOS" — wird bei der Erstellung aus dem User
Agent abgeleitet und **gespeichert**, statt bei jedem Rendern neu geparst zu werden: Der Sinn ist,
das Gerät so zu beschreiben, wie es beim Vertrauensbeschluss war, und ein inzwischen
aktualisierter Browser sollte eine Zeile nicht stillschweigend umbenennen.

`App\Security\TwoFactor\TrustedDeviceCookieJar` ist mit `kernel.reset` getaggt, denn das
anstehende Cookie darf nicht in die nächste Anfrage hinüberleben — unter einer Worker-Laufzeit
würde es für wen auch immer als Nächstes anfragt geschrieben.

## App-Passwörter und Kopplung

`App\Entity\User\ApiToken` ist auf die **Nutzerin** bezogen und nicht auf ein Konto, passend zum
JMAP-Session-Objekt: Ein Anmeldegeheimnis zählt jedes verbundene Mail-Konto auf. Das Geheimnis
wird bei der Erstellung genau einmal angezeigt, und gespeichert wird nur sein Digest, wobei
`uniq_api_token_hash` den Lookup zu einem indizierten Gleichheitstest macht.

`App\Service\User\DevicePairingService` gibt es, weil die Alternative 71 Zeichen Base16 sind, die
von einem Bildschirm auf eine Telefontastatur abgetippt werden — der schlimmste Moment beim
Einrichten und derjenige, bei dem am ehesten etwas schiefgeht.

**Der QR-Code enthält niemals das App-Passwort.** Er trägt einen kurzlebigen, einmalig nutzbaren
Kopplungscode, den die App gegen ein frisch geprägtes eintauscht. Das zählt, weil ein QR-Code auf
einem Laptopbildschirm etwas ist, das Menschen fotografieren, per Bildschirmfreigabe zeigen und
dann stehen lassen: Ein Code, der nach `TTL_SECONDS` (120) tot ist und nach Gebrauch sofort, kann
niemandem einen dauerhaften Schlüssel zu einem Postfach in die Hand geben. Das eingebettete
Passwort täte es.

Die Codes liegen im Cache statt in einer Tabelle, geschlüsselt über `hash('sha256', $code)` und
nicht über den Code — es gibt einen Test namens `testTheCacheKeyIsADigestNotTheCodeItself`. Ein
einmalig nutzbares Zwei-Minuten-Geheimnis hat keinen Grund, einen Neustart zu überleben: Sie beim
Deployment zu verlieren kostet einen erneuten Scan, während eine Tabelle eine Migration, einen
Index und einen Aufräumer bräuchte, für Daten, deren gesamte Lebensdauer kürzer ist als ein
Deployment.

`CODE_BYTES` ist 32, und deshalb braucht `/device/pair` keine eigene Sperre: Es gibt nichts
durchzuprobieren.

## Der SSRF-Schutz

Selbstgehostete Integrationen lassen Nutzer ihren eigenen Server benennen, was heißt, dass eine
authentifizierte Person den ausgehenden HTTP-Client richten kann, wohin sie will — hier eine echte
SSRF-Angriffsfläche, denn die Anwendung läuft in einem Container-Netz neben Postgres, Mercure und
den Workern.

`App\Service\Integration\IntegrationUrlValidator` hat drei Abwehrlinien, nach Stärke geordnet:

1. **Eine Admin-Person, die `baseUrl` in der Provider-Konfiguration festnagelt, nimmt die
   Angriffsfläche vollständig weg.** `resolve()` ignoriert den Wert der Nutzerin, sobald einer
   festgenagelt ist, sodass auch eine alte Zeile von vor dem Festnageln nicht weiter anderswohin
   greifen kann.
2. **`http://` wird abgelehnt, sofern `INTEGRATIONS_ALLOW_HTTP` nicht an ist.** Selbsthosting im
   LAN ist bei Nextcloud und Immich der Normalfall, dieser Schalter wird also oft gesetzt sein —
   der Punkt ist, dass Klartext-Anmeldedaten auf der Leitung damit zu einer bewussten
   Admin-Entscheidung werden statt zu einer stillen Voreinstellung.
3. **Loopback-, Link-Local- und private Bereiche werden abgelehnt**, sofern der Host nicht in
   `INTEGRATIONS_ALLOWED_HOSTS` steht. `BLOCKED_RANGES` deckt `127.0.0.0/8`, die drei
   RFC1918-Bereiche, `169.254.0.0/16` (Link-Local, einschließlich des
   Cloud-Metadaten-Endpunkts unter 169.254.169.254), `100.64.0.0/10` und `0.0.0.0/8` ab; IPv6
   wird gesondert auf `::1`, `fc00::/7` und `fe80::/10` geprüft, auch in eckigen Klammern.

Anmeldedaten in der URL werden rundheraus abgelehnt, denn sie landeten überall dort im Log, wo
die URL landet, und überschrieben stillschweigend die an der Verbindung hinterlegten.

**Es ist bewusst keine vollständige Abwehr gegen DNS-Rebinding**, und der Docblock sagt das auch:
Ein Hostname, der zum Verbindungszeitpunkt auf eine private Adresse auflöst, kommt weiterhin
durch. Das zu schließen erforderte, die aufgelöste IP im HTTP-Client festzunageln, was Symfonys
Client nicht anbietet. Den Hostnamen hier aufzulösen kaufte nur eine Prüfung, die eine
Angreiferin zwischen jetzt und der Anfrage entwerten kann. Die Positivliste ist die ehrliche
Abschwächung, und Admins, die `baseUrl` festnageln, umgehen die Frage.

Dieselbe Form findet sich in `App\Service\Calendar\Sync\IcsUrl\IcsUrlNormaliser` für abonnierte
Feeds — siehe [ICS-Feeds](../providers/ics-feeds.md) dazu, welche Adressen abgelehnt werden und
warum — und in `App\Service\Calendar\Push\PushCallbackUrl` in der Gegenrichtung, wo verweigert
wird, Google oder Microsoft eine Callback-Adresse zu geben, die nicht HTTPS ist oder einen
Loopback-Host benennt.

## Was ein öffentlicher Link erreichen kann

Zwei Funktionen teilen sich einen Mechanismus: Ein **Freigabelink** zeigt einen Teil eines
Kalenders jemandem ohne Konto, und eine **Buchungsseite** lässt diese Person eine Stunde daraus
nehmen. Beide sind allein durch ein Token in der URL abgesichert und sonst durch nichts. Siehe
[Freigeben und Buchen](../features/calendar-sharing.md).

### Das Token ist ein Anmeldegeheimnis und wird nicht gespeichert

`calendar_share_link.token_digest` und `booking_page.token_digest` halten ein SHA-256, niemals das
Token. `PublicLinkToken::mint()` erzeugt URL-sicheres base64 statt Hex, ein Token ist damit 43
Zeichen lang statt 64 und übersteht es, in ein Chatfenster, einen Mail-Client, der lange Zeilen
umbricht, und einen QR-Code eingefügt zu werden. `ROUTE_PATTERN` ist an derselben Klasse
deklariert, statt in zwei Route-Attribute geschrieben zu werden — es ist das Alphabet, das
`mint()` hervorbringt, und eine Anforderung, die dazu im Widerspruch stünde, ergäbe für einen
frisch geprägten Link einen 404, statt irgendwo zu scheitern, wo ein Test hinsähe. Seine
Längenbegrenzung ist es, die einen mehrere Kilobyte langen Pfad vom Hash fernhält.

`digest()` nimmt **jede** Zeichenkette entgegen, auch eine feindselige, denn genau das reichen ihm
die öffentlichen Routen. Hashen statt validieren ist es, was das sicher macht: Ein von einer
Angreiferin gelieferter Pfad wird zu 64 Hex-Zeichen, bevor er eine Abfrage erreicht; es gibt also
keine Eingabeform, die nicht bereits ein Digest wäre, wenn verglichen wird.

**Der Preis ist, dass die Adresse genau einmal angezeigt wird.** Kein Bildschirm kann sie erneut
zeigen, denn nichts Gespeichertes kann sie rekonstruieren. Die Abhilfe heißt „neu erzeugen", was
ein neues Token prägt und die alte URL zu einem 404 macht — das Richtige für eine URL, die
ohnehin abhandengekommen ist. Das ist das Erste, was jemand „reparieren" wollen wird; das Zweite
ist ein Kopierknopf in der Zeile, der nicht funktionieren kann.

### Die Schwärzung ist ein DTO

`App\Service\Calendar\Sharing\ShareLinkReader` erledigt zwei Aufgaben, die nicht getrennt werden
dürfen, denn sie zu trennen ist die Art, wie ein Leck geschrieben wird: den Link auflösen und die
geschwärzte Ansicht bauen. **Nichts sonst in der Anwendung darf einem öffentlichen Template
einen `CalendarEvent` reichen**, und durchgesetzt wird das dadurch, dass diese Klasse der einzige
Weg von einem Token zu irgendetwas Darstellbarem ist und das Zurückgegebene überhaupt keine
Termine enthält.

`App\Domain\DTO\Calendar\SharedOccurrence` **ist** die Schwärzung. Die naheliegende Alternative —
dem Template die Termininstanz zu reichen und neben jedem Feld `link.reveals(...)` zu prüfen —
ist diejenige, die leckt: Ein Template, das eine Prüfung vergisst, leckt; ein Partial, das für
einen Tooltip ein `title`-Attribut bekommt, leckt; eine für einen Stimulus-Controller
zusammengebaute JSON-Nutzlast leckt; eine aus dem Termin gebaute `.ics` leckt. Nichts davon kann
passieren, weil die konkreten Daten nicht in dem Objekt stehen, das der Renderer erreichen kann.
`SharedCalendarLeakTest` prüft das über den gesamten Antwortkörper.

`$uid` an diesem DTO ist **synthetisch und nicht die des Termins**. Eine echte UID identifiziert
eine Besprechung über jeden Kalender und jedes Postfach hinweg, das sie hält — genau darauf baut
der `EventCopyResolver` —, sie zu veröffentlichen ließe also jede Person mit einem
Belegt/Frei-Link den Terminkalender der Eigentümerin mit einer Einladung abgleichen, die sie
ohnehin schon erhalten hat.

Ein widerrufener Link, ein unbekanntes Token und ein fehlgeformtes antworten alle mit `null`, und
der Controller macht aus allen dreien einen 404. Sie zu unterscheiden bestätigte, welche Token
einmal echt waren.

### Die Privatsphäre ist die Decke, die Haken sind der Boden

`App\Domain\Enum\Calendar\ShareDetail` ist die Liste dessen, was ein Link über Belegt/Frei hinaus
ergänzen darf — `Title`, `Location`, `Description`, `Participants` —, gespeichert als jsonb-Liste,
damit ein Link nachträglich eingeengt werden kann, ohne neu verschickt werden zu müssen. Es gibt
bewusst **keinen `BusyFree`-Fall**: Er ist die Abwesenheit jedes Falls, und ein eigener Fall dafür
machte die leere Menge zweimal ausdrückbar. Ein unbekannter Wert, der aus einer älteren oder
neueren Installation zurückgelesen wird, wird von `tryFrom()` verworfen, und das ist die sichere
Richtung — ein Detail, das plMail nicht kennt, bleibt verborgen.

`App\Domain\Enum\Calendar\EventPrivacy` ist die Decke, und beide Hälften werden im Reader
abgefragt statt in einem Template, denn ein Template, das etwas vergisst, ist ein Template, das
leckt, und es gibt keinen Test, dem ein fehlendes `if` auffiele:

- `isShareable()` — `Secret` antwortet **false** und taucht überhaupt nicht auf, nicht einmal als
  anonymer Belegt-Block. Die Existenz ist das Detail: Ein Block an einem Dienstagnachmittag ist
  das, worauf jemand, der den Link liest, reagieren würde. Der Preis wird in Kauf genommen und
  ausgesprochen — ein Kalender mit geheimen Terminen behauptet, die Eigentümerin sei zu Stunden
  frei, zu denen sie es nicht ist, der Link lässt sich also nutzen, um darüber hinweg zu buchen.
  Das ist der Handel, um den das Wort „geheim" bittet.
- `mayRevealDetail()` — `Private` zeigt sich als Belegt-Block, was auch immer die Häkchen des
  Links sagen. Der Link ist eine Entscheidung über ein Publikum und die Privatsphäre eine
  Entscheidung über eine einzelne Besprechung, das Engere gewinnt also; sonst wäre das Weitere ein
  Weg, das Engere in großem Stil auszuhebeln.

Beide sind erschöpfende `match`-Ausdrücke ohne `default`, sodass eine vierte Privatsphärenstufe
nicht die Antwort erbt, die zufällig zuletzt stand.

Das Zeitfenster wird immer in der Zone der **Eigentümerin** aufgelöst, nie in der des Lesers —
vierzehn rollende Tage heißt vierzehn Tage der Eigentümerin, und es in der Zone der Besucherin
aufzulösen ließe denselben Link je nach Öffnungsort verschiedene Tage abdecken. Die rollende
Anzahl wird begrenzt statt geglaubt: Das Formular begrenzt sie auf dem Hinweg, aber dies ist die
Leseseite und sie läuft Tage ab, eine Zeile, die etwas anderes als dieses Formular bearbeitet
hat, darf also aus einem öffentlichen GET keine Datumsrechnerei über ein Jahrzehnt machen.
`MAX_ENTRIES` (2000) begrenzt die Abfrage und nicht die Schleife, sodass auch der Speicher
begrenzt ist, und die Seite sagt es, wenn die Grenze erreicht wurde, statt stillschweigend früher
aufzuhören.

Abgesagte Termininstanzen fallen aus einem anderen Grund heraus, der eine eigene Erwähnung
verdient: Eine abgesagte Besprechung ist kein Anspruch auf die Zeit der Eigentümerin, sie stehen
zu lassen ließe einen freigegebenen Kalender also „belegt" sagen, wo die Eigentümerin frei ist.

### Buchen

**Doppelbuchungen verhindert `uniq_calendar_booking_page_start`, und sonst nichts.** Zwei
Personen, die im selben Augenblick für dieselbe halbe Stunde auf Buchen drücken, lesen beide den
Platz als frei, denn beide lesen, bevor eine von beiden schreibt; dieses Zeitfenster zu verengen
macht den Fehler seltener, und das ist die schlechteste Eigenschaft, die ein Fehler dieser Art
haben kann. Die Datenbank ist die einzige Beteiligte, die beide Anfragen sieht.

Das Constraint liegt auf `(booking_page_id, starts_at)` — einem **denormalisierten Beginn an der
Buchungszeile**, nicht einem Join auf den Termin. Genau das macht es überhaupt durchsetzbar, und
deshalb kann das Verschieben einer gebuchten Besprechung auf einen anderen Tag nicht die Stunde
einer anderen Person freigeben: Der Anspruch lebt an der Buchung, den Termin zu verschieben gibt
den Platz also nicht frei. Die Seiten-ID steht vorn, weil jeder Lesevorgang auf eine Seite
eingegrenzt ist und weil zwei Seiten, die dieselbe Stunde anbieten, etwas ist, das die
Eigentümerin legitimerweise tun darf — was das verhindert, ist ihr Terminkalender über
`BookingPage::calendarsToCheck()` und nicht dieser Index. `starts_at` steht an zweiter Stelle,
damit der Index auch „welche Plätze in diesem Fenster sind vergeben" bedient, die Abfrage, die der
Verfügbarkeits-Reader bei jedem öffentlichen GET stellt. `uniq_calendar_booking_event` ist die
Gegenrichtung: eine Buchung je Termin, denn der Termin wird von der Buchung erzeugt, eine zweite
Buchung, die darauf zeigte, wäre also ein Fehler hier und kein Wettlauf zwischen Fremden.

`App\Service\Calendar\Booking\BookingService` ist um die Verweigerung herum geschrieben und nicht
um eine Prüfung. **Termin und Buchung gehen in einem einzigen Flush hinaus**, sodass die Ablehnung
durch das Constraint den Termin mitnimmt und die ganze Buchung entweder passiert oder gar nicht —
den Platz zuerst zu beanspruchen hinterließe eine beanspruchte Stunde ohne Besprechung darin, und
die Besprechung zuerst zu schreiben hinterließe die Besprechung der Verliererin im Kalender der
Eigentümerin, ohne dass irgendetwas darauf zeigte. Doctrine schließt bei einem fehlgeschlagenen
Flush den EntityManager, weshalb hier **geworfen** statt ein Urteil zurückgegeben wird: Eine
Exception ist das eine Signal, an dem ein Controller nicht versehentlich vorbeilaufen kann. Der
Controller antwortet mit einer Umleitung, sodass die erneut angebotene Platzliste von einer
frischen Anfrage mit einem frischen Manager gebaut wird.

`BookingPage::calendarsToCheck()` enthält immer den Zielkalender, angehakt oder nicht, und das
lebt an der Entität statt im Reader, weil es eine Invariante der Seite ist: Eine Seite, deren Ziel
nicht in ihrer eigenen Belegt-Menge stünde, würde sich bei der zweiten Anfrage selbst doppelt
belegen.

Die Angaben der buchenden Person leben an `CalendarBooking`, **nicht** am Termin, und bewusst
nicht als `participants` — einem Anbieter eine Teilnehmerliste zu pushen ist die Art, wie der
Anbieter beschließt, einer Fremden eine Terminanfrage zu mailen, um die sie nicht gebeten hat.
Der Termin trägt `EventSource::Booking`, und das ist die einzige Art Termin, die eine Person
außerhalb der Installation überhaupt entstehen lassen kann; „welche davon habe ich nicht selbst
hier hineingelegt?" ist eine Frage, die jemand beim ersten Missbrauch einer Seite stellen wird —
und eine Abfrage kann sie nur beantworten, wenn es damals aufgeschrieben wurde.
`EventSource::mayBeRewrittenByMail()` antwortet dafür `false`: Ein Abgleicher, der eine Buchung
umschreiben könnte, machte aus der Buchungsseite einen Weg, den Kalender der Eigentümerin
nachträglich per E-Mail zu bearbeiten.

**Der öffentliche POST ist ratenbegrenzt und der GET nicht.** `booking_attempt` sind 6 je Stunde,
festes Fenster, je IP geschlüsselt — das Gegenteil des Nutzerschlüssels beim Zwei-Faktor-Limiter,
und aus dem entgegengesetzten Grund: Hier wird kein Konto angegriffen, die Adresse der Aufruferin
ist also der einzige Griff an „wer auch immer diesen Kalender vollschreibt". Ein festes Fenster
statt eines gleitenden, weil der Zähler je Adresse auf einem öffentlichen Endpunkt geführt wird
und die gleitende Strategie einen Zeitstempel je Treffer aufbewahrt, wo diese eine Ganzzahl
aufbewahrt — was zählt, wenn das Ratenbegrenzte zugleich das ist, was auf den Cache gefeuert
werden kann. Der GET ist bewusst unbegrenzt: Eine Grenze dort ließe eine einzelne fremde Person
eine veröffentlichte Seite durch Neuladen aus dem Internet nehmen.

**Die öffentlichen Seiten haben ihr eigenes Layout, und das muss so bleiben.**
`templates/sharing/_layout.html.twig` erbt nicht vom App-Layout, denn jenes rendert
`csrf_token('ajax')` in ein Meta-Tag — was bei jedem Abruf einer öffentlichen URL eine Session
startet, für immer. Folglich gibt es am Buchungsformular kein CSRF-Token, und
`App\Controller\Sharing\BookingController` legt dar, warum das der richtige Handel ist.

## Fallstricke

**Einen Service ohne das `app_secrets`-Volume in compose aufzunehmen prägt ihm einen zweiten
Verschlüsselungsschlüssel.** Das funktioniert, bis es das nicht mehr tut, und die Prüfung ist es,
die daraus eine Verweigerung beim Start macht statt Anmeldedaten, die unter einem Schlüssel
geschrieben sind, den die halbe Flotte nicht lesen kann.

**`APP_ENCRYPTION_KEY` an einem laufenden Stack zu rotieren beschädigt Daten in beide
Richtungen.** Die bereits laufenden Prozesse behalten den alten Schlüssel im Speicher.
`--rotate-secrets` verlangt, unmittelbar danach alles neu zu starten, und aus einem verlorenen
Schlüssel gibt es keine Wiederherstellung außer, den ursprünglichen zurückzulegen.

**Eine `encrypted_string`-Spalte lässt sich nicht dem Wert nach abfragen.** Die Nonce gilt je
Verschlüsselung, zwei Verschlüsselungen desselben Klartexts unterscheiden sich also. Jede Funktion,
die etwas darin nachschlagen will, braucht eine Digest-Spalte daneben — und genau das tun
`ApiToken`, `TrustedDevice` und die Freigabe-Token alle.

**Eine neue öffentliche Route unter `/share/` oder `/book/` erbt `PUBLIC_ACCESS`.** Die Präfixe
werden über ein Muster abgeglichen, eine neue Aktion dort ist also anonym, ob beabsichtigt oder
nicht, und das Token im Pfad ist die einzige Schranke.

**Einem öffentlichen Template etwas anderes als eine `SharedOccurrence` zu reichen hebt die
Schwärzung auf.** Die Eigenschaft „ein Belegt/Frei-Link kann keinen Titel preisgeben" gilt, weil
das gerenderte Objekt keinen hat; eine Bequemlichkeit, die den Termin durchreicht, und sei es für
ein einziges Feld, öffnet alle Pfade auf einmal wieder.

**Ein Flag für abgesagte Buchungen plus ein einfacher Unique-Index ergibt einen Platz, den nie
wieder jemand buchen kann.** Die Entität sagt das ausdrücklich: `cancelled_at` später zu ergänzen
heißt, den Index partiell zu machen — `WHERE cancelled_at IS NULL` —, und das ist der Satz, den
du dir merken solltest.

**Die Anmeldedrosselung zu erweitern deckt das Codeformular nicht ab**, und die Codedrosselung zu
erweitern deckt das Passwortformular nicht ab. Es sind zwei Limiter mit zwei
Schlüsselstrategien für zwei verschiedene Angriffe, und der Docblock jedes einzelnen nennt den
anderen, damit keiner für vollständige Abdeckung gehalten wird.

**`InstallGuard` ist das Einzige zwischen `/install` und einer anonymen Administratorin.** Die
Route ist notwendigerweise `PUBLIC_ACCESS`, und `security.yaml` sagt mit ebenso vielen Worten,
dass du diese Klasse lesen sollst, bevor du die Zeile anfasst.
