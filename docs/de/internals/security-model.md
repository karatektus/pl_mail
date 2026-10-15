<!-- translated-from: internals/security-model.md sha1:69fe449c13ec15cb176cada76f59f4d986f25c5a -->
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
| Nutzerpasswort | im Browser gestreckt (PBKDF2-SHA256), dann Symfonys `auto`-Hasher (Argon2/bcrypt) über das Ergebnis | wenig Entropie, von einem Menschen gewählt — Key Stretching ist der ganze Sinn; warum es zweimal geschieht, steht im nächsten Abschnitt |
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

## Passwörter werden im Browser gestreckt

Der Server bekommt das Passwort einer Person nie zu sehen. `assets/password_kdf.js` leitet im
Browser ab

```
masterKey = PBKDF2-SHA256(passwort, kdfSalt, kdfIterations)   // verlässt den Browser nie
authHash  = PBKDF2-SHA256(masterKey, passwort, 1)             // wird gesendet
```

— die Konstruktion von Bitwarden — und der Server speichert den Hash des `auto`-Hashers über
`authHash`, genau so, wie er es mit einem Passwort täte. Es geht dabei nicht um die Anmeldung: Die
Ende-zu-Ende-Verschlüsselung aus Issue #28 wird den Schlüssel, der die privaten Schlüssel einer
Person entsperrt, aus `masterKey` ableiten, und ein Server, der das Passwort nie sieht, kann ihn
nicht ableiten. `App\Security\PasswordKdf` ist die Hälfte des Servers und bringt ein `derive()` in
PHP mit, das Testsuite und Konsole statt eines Browsers benutzen; `PasswordKdfTest` heftet es an
einen unabhängig berechneten Vektor, und `tests/e2e/auth.spec.ts` meldet einen echten Browser an
einem Konto an, dessen Hash PHP erzeugt hat — so können die beiden nicht auseinanderlaufen.

**Salt und Rundenzahl gelten pro Person** (`User::$kdfSalt`, `$kdfIterations`) und werden vor einer
Anmeldung von `POST /login/prelogin` ausgegeben. Die Rundenzahl wird gespeichert statt aus der
Konfiguration gelesen, damit ein späteres Anheben von `app.password_kdf_iterations` nur Passwörter
betrifft, die danach gesetzt werden. Eine unbekannte Adresse wird beantwortet, nicht abgewiesen,
mit einem HMAC der Adresse unter dem Kernel-Secret als Salt — deterministisch, damit zweimaliges
Fragen sie nicht als unbekannt verrät.

**Konten werden bei ihrer nächsten Anmeldung umgestellt.** Ein `kdfSalt` von null bedeutet, dass der
gespeicherte Hash vom Passwort selbst stammt: jedes Konto von vor dieser Änderung und jedes, dessen
Passwort zuletzt etwas ohne Browser gesetzt hat — der Installationsassistent, das Anlegeformular der
Administration, `app:user:password`, die Seeds. Für diese antwortet der Prelogin mit
`migrate: true`, das Formular schickt das Passwort ein letztes Mal neben einem Auth-Hash, der mit dem
Platzhalter-Salt gestreckt wurde, und `PasswordKdfUpgradeListener` stellt den Hash um, nachdem das
Passwort geprüft ist. Er läuft auf `CheckPassportEvent`, nicht auf `LoginSuccessEvent`, weil das
Angemeldet-bleiben-Cookie während des letzteren über den Hash signiert wird.

**Was ein umgestelltes Konto ablehnt**, ist der Sinn des Ganzen: Sein gespeicherter Hash stammt von
einem Auth-Hash, also meldet das Passwort selbst es nicht mehr an (`PasswordKdfLoginTest` hält diese
Linie). Das Formular nimmt ein nacktes `password` weiterhin für ein noch nicht umgestelltes Konto an
— ein Browser ohne JavaScript oder eine über das Update hinweg zwischengespeicherte Anmeldeseite —
und stellt es auf diesem Weg nie um.

**Was der Server nicht mehr prüfen kann**, ist das neue Passwort: Länge und Wiederholung prüft der
Browser (`password_form_controller.js`). Wer das umgeht, kann sich nur selbst ein schwaches Passwort
geben. Die Rundenzahl *wird* geprüft, denn eine niedrigere schwächt die Tabelle für den, der sie
stiehlt, und das ist nicht die Person, die wählt.

**Ein Passwort, das jemand anderes gewählt hat, gilt einmal.** `User::$passwordChangeRequired` wird
vom Anlegeformular der Administration und von `app:user:password` gesetzt (außer mit
`--no-change-required`), und `PasswordChangeRequiredListener` hält die Person auf
`/password/required`, bis sie ein eigenes gewählt hat — nur auf der Firewall `main`, damit
App-Passwörter weiter synchronisieren.

## Der Schlüsselbund

Phase 2 von Issue #28. Alles Kryptografische passiert im Browser (`assets/crypto/keyring.js`); der
Server speichert Blobs, die er nicht öffnen kann, und hält die Regeln, die ein veralteter Tab
brechen könnte (`App\Service\Crypto\Keyring`).

```
wrapKey         = HKDF-SHA256(masterKey, "plmail/user-key-wrap")      // siehe Abschnitt oben
User-Key        = 32 Zufallsbytes, per AES-256-GCM unter wrapKey verpackt   // UserKey
Adress-Key      = OpenPGP-Schlüssel, verschlossen mit einem Zufalls-Token  // AddressKey
Token           = per AES-256-GCM unter dem User-Key verpackt
Phrase-Kopie    = User-Key unter HKDF(BIP39-Entropie) verpackt         // UserKeyCopy, Art phrase
Geräte-Kopie    = User-Key unter einem nicht exportierbaren IndexedDB-Schlüssel  // Art device
```

Jede Verpackung hat die Form `v1.<iv>.<ciphertext>`, ihr Zweck ist als Associated Data eingebunden
(`App\Security\Crypto\Wrapped` prüft die Form). Der User-Key ist symmetrisch, wo das Konzept einen
OpenPGP-Schlüssel skizziert hatte: Er wird nie herausgegeben und verpackt nur Tokens, was AES-GCM
samt Integrität erledigt.

**Die Sitzung bleibt entsperrt**, indem sie geteilt wird: Bei der Anmeldung verpackt der Browser
`wrapKey` unter einem zufälligen Entsperr-Schlüssel, legt das Ergebnis in localStorage ab und sendet
nur den Entsperr-Schlüssel (`unlock_key`), den `SessionUnlock` in der Sitzung hält. Eine spätere
Seite holt ihn über `GET /keyring/unlock` zurück. Keine Hälfte öffnet allein etwas, und die Hälfte
des Browsers ist wertlos, sobald die Sitzung endet. Eine Sitzung, die nicht das Anmeldeformular
geöffnet hat — Angemeldet bleiben, der Installationsassistent —, ist gesperrt, bis `POST
/keyring/unlock` das Passwort wie eine Anmeldung beweist (gedrosselt durch `keyring_unlock`).

**Regeln, die der Server hält:** ein Schlüsselbund je Person, solange einer aktiv ist; Schlüssel nur
für Adressen, die der Person gehören; ein primärer Schlüssel je Adresse; ein erzeugter Schlüssel,
den niemand gesehen hat (`firstUsedAt` null), wird gelöscht statt behalten, wenn ein anderer primär
wird, außer sein User-Key ist gesperrt; ein Passwortwechsel muss jeden aktiven User-Key neu verpackt
mitbringen (`PasswordChanger` verweigert ihn sonst, im selben Flush); ein Passwort, das hier niemand
gewählt hat — `app:user:password`, der erzwungene Wechsel —, sperrt stattdessen jeden Schlüssel. Wer
entfernt wird, verliert seinen Schlüsselbund.

**Im Haupt-Thread, nicht in einem Worker.** Modul-Worker sehen die Import-Map der Seite nicht, und
nichts in dieser Phase ist langsam genug, um die Seite zu blockieren. Große Mails zu entschlüsseln
ist der Punkt, an dem sich ein Worker lohnt.

### Zwei Wurzeln

Phase 6e. `wrapKey` wird aus einem Geheimnis abgeleitet, der **Wurzel** des Schlüsselbunds
(`User::$keyringRoot`, `KeyringRoot`):

```
password    masterKey = PBKDF2-SHA256(password,   kdfSalt,        kdfIterations)
passphrase  masterKey = PBKDF2-SHA256(passphrase, passphraseSalt, passphraseIterations)
            wrapKey   = HKDF-SHA256(masterKey, "plmail/user-key-wrap")   // in beiden Fällen
```

Das Passwort für jedes Konto, das eins hat. Ein Konto ohne nutzbares Passwort — der leere Hash, den
`OidcUserResolver` für ein per Single Sign-on angelegtes Konto schreibt — hat nichts, woran ein
Schlüsselbund hängen könnte: Das Passwort des Anbieters erreicht plMail nie, und ein Schlüsselbund,
der am Wort des Anbieters hinge, wäre einer, den der Anbieter öffnen könnte. Seine Wurzel ist eine
**Verschlüsselungs-Passphrase**, gewählt unter Einstellungen → Verschlüsselung und genau wie ein
Passwort gestreckt, mit eigenem Salt und eigener Rundenzahl (`passphrase_salt`,
`passphrase_iterations`). Ein eigenes Salt, damit eine Passphrase, die einem späteren Passwort
gleicht, nie zu den Bytes gestreckt wird, deren Auth-Hash der Server sieht. Aus der Passphrase wird
nie ein Auth-Hash abgeleitet, der Server hat also nichts, woran er sie prüfen könnte; ob sie stimmt,
zeigt sich nur daran, ob sich ein User-Key öffnet (AES-GCM). Alles unter `wrapKey` — Adress-
Schlüssel, Kopien, S/MIME, Kopplung, versiegelte Entwürfe — ist bei beiden gleich.

`User::$keyringRootInEffect` ist die Passphrase, sobald sie eingerichtet ist, und auch schon vorher
bei einem Konto ohne nutzbares Passwort; `Keyring::create()` schreibt die Wurzel im selben Flush wie
den ersten User-Key, verlangt auf dieser Wurzel die Parameter der Passphrase und verweigert sie auf
dem Passwort. Ihre Rundenzahl ist mindestens die Passwort-Rundenzahl der Installation, damit ein
Browser dem Server keine billigere Wurzel unterschieben kann.

**Die Wurzeln kreuzen sich nie.** Alles, was mit der Anmeldung geschieht, gilt nur für einen
Schlüsselbund am Passwort: `applyPasswordChange()` und `lockAll()` tun an der Passphrase nichts, ein
Passwortwechsel verpackt dort also weder neu noch verweigert er sich ihretwegen, und einem
Single-Sign-on-Konto ein Passwort zu geben (`app:user:password`, danach der erzwungene Wechsel),
sperrt nichts. Die Passphrase wechselt über `POST /keyring/passphrase`
(`Keyring::changePassphrase()`), mit jedem aktiven User-Key neu verpackt und derselben
Alles-oder-nichts-Regel. Einen bestehenden Schlüsselbund von einer Wurzel auf die andere
umzustellen, wird nicht angeboten.

### Entsperren, wenn es gebraucht wird

Nur das Anmeldeformular öffnet den Schlüsselbund gleich mit. Jede andere Sitzung — Angemeldet
bleiben, Single Sign-on (immer gemerkt, siehe `OidcAuthenticator`), ein Konto ohne Passwort —
beginnt verschlossen und wird bei der Anmeldung nichts gefragt. `Keyring.requireOpen()` fragt über
einen gemeinsamen Dialog (`crypto/_unlock_dialog.html.twig`, `assets/crypto/unlock_dialog.js`),
wenn etwas den offenen Schlüsselbund braucht: eine verschlüsselte Mail oder ein Zitat daraus, ein
Entwurf, der gleich versiegelt oder gebaut wird, ein versiegelter Entwurf, Einstellungen →
Verschlüsselung, die Kopplung. Ein Dialog, egal wie viele gleichzeitig fragen; danach wird der
offene Schlüsselbund angekündigt (`crypto:keyring`), damit der Hintergrund-Entschlüssler — der nie
selbst fragt — und Mails, die sich als gesperrt gezeigt haben, weitermachen. Die geteilte
Entsperrung hält dann wie bisher für die Sitzung.

**Wie die Sitzungshälfte gehalten wird, hängt von der Wurzel ab.** Am Passwort prüft `POST
/keyring/unlock` das gestreckte Passwort wie eine Anmeldung, gedrosselt durch `keyring_unlock`. An
der Passphrase hält `POST /keyring/unlock/session` den zufälligen Entsperr-Schlüssel des Browsers
**ohne Beweis**, sobald ein Schlüsselbund existiert (`UnlockController::session()`). Das ist sicher,
weil die Hälfte allein nichts öffnet: Sie verpackt den aus der Passphrase abgeleiteten Schlüssel im
localStorage dieses Browsers, und der Server sieht nie, was sie verpackt. Eine Sitzung, die eine
eigene Hälfte registriert, bekommt einen Schlüssel, der nichts entpackt, was sie nicht schon hatte;
an `wrapKey` kommt man weiterhin nur mit der Passphrase, geprüft dadurch, dass sich der User-Key im
Browser öffnet. Die verpackten User-Keys bekommt jede Sitzung über `GET /keyring` ohnehin, ein
Offline-Rateversuch an der Passphrase ist also genauso möglich wie an einem Passwort — der Beweis am
Passwort ist der Server, der ein Geheimnis prüft, dessen Hash er hat, und dafür gibt es hier kein
Gegenstück. Ein Schlüsselbund am Passwort kann den beweisfreien Weg nicht nehmen: Er würde die
Drosselung umgehen.

## Verschlüsselte und signierte Mails lesen

Phase 3 von Issue #28. `MessageCryptoDetector` ordnet jede Nachricht im Builder des jeweiligen
Anbieters ein, **bevor die Zeile zum ersten Mal geschrieben wird** — `search_vector` wird beim
INSERT aus `body_text` erzeugt, ein später geleerter Body wäre also schon im Index. Er liest den
obersten Content-Type mit seinen Parametern `protocol=` / `smime-type=` (von Hand geparst: die
Erweiterungsattribute von webklex nimmt der erste Header, der sie setzt, und sie machen aus `-` ein
`_`), weicht nur für Graph auf die Anhangsliste aus und auf Armor am Zeilenanfang im Body.
Verschlüsselte Arten bekommen einen leeren Body; Teile, die nur Hülle sind (`signature.asc`,
`smime.p7s`, das Chiffrat), werden nicht als Anhänge gespeichert.

Signierte Mails werden nach dem Commit auf dem Transport `enrich` geprüft (`VerifySignatureMessage`):
S/MIME mit OpenSSL, zuerst gegen die Zertifizierungsstellen des Systems (Valid), dann mit
`PKCS7_NOVERIFY` (Untrusted); PGP mit dem Programm `gpg` in einem GnuPG-Verzeichnis, das für eine
Prüfung angelegt und danach gelöscht wird (`GnupgHome`), ausgewertet über seine `--status-fd`-Zeilen,
gegen die eigenen Adress-Keys der Person (Valid),
ihre Schlüssel für den Absender (siehe unten) und einen Autocrypt-Schlüssel in der Nachricht, dessen
`addr=` gleich `From` ist. Eine Signatur von einer anderen Adresse als `From`
ist in beiden Fällen Invalid. `PgpMimeSplitter` holt die signierten Bytes von Hand aus der
Rohquelle, weil jeder MIME-Parser etwas Dekodiertes zurückgibt.

Verschlüsselte PGP-Mails werden im Browser geöffnet (`assets/crypto/message.js`): die byte-genaue
Rohquelle von `GET /mail/message/{id}/raw`, openpgp.js mit den Adress-Keys des Schlüsselbunds,
postal-mime für den inneren Teil, DOMPurify für das HTML. Das Ergebnis kommt in denselben
Sandbox-Frame, gebaut aus demselben Dokument (`mail/_frame_document.html.twig`, gerendert mit dem
Platzhalter `<!--plmail:body-->`), sodass CSP und per Hash erlaubtes Frame-Skript Byte für Byte die
des Servers sind. Externe Bilder werden ersetzt, nicht über den Proxy geladen: der Proxy würde die
URLs erfahren, die der Absender verschlüsselt hat.

## Schlüssel der Kontakte und Schlüsselsuche

Phase 4 von Issue #28. `ContactKey` ist eine Zeile pro Person, Adresse und Schlüssel; `ContactKeys`
schreibt als Einziges hinein und hält die Vertrauensregel, **Trust on First Use**:

- Der erste verwendbare Schlüssel für eine Adresse ist `current`. Jeder andere danach ist `changed`
  und wird für nichts verwendet, bis die Person ihn akzeptiert — nicht, wenn er per Mail kommt, nicht
  über WKD, nicht aus einem Import. Ein partieller Unique-Index (`uniq_contact_key_current`,
  `WHERE status = 'current'`) ist der Schutz, wenn zwei Worker gleichzeitig Schlüssel übernehmen; das
  Lesen-dann-Schreiben in PHP ist es nicht.
- Eine Adresse mit irgendwelchen Schlüsseln — etwa einem entfernten `current` neben einem wartenden
  `changed` — bekommt durch Ankunft keinen neuen `current`. Die Entscheidung bleibt bei der Person.
- `pinned` ist das Wort der Person, dass sie den Fingerprint verglichen hat. Es gibt kein Web of
  Trust: Beglaubigungen auf einem Schlüssel zählen nichts.
- Was ein verwendbarer Schlüssel ist, entscheidet gpg (`PublicKeyReader`, in einem Wegwerf-
  Verzeichnis): eine User-ID für die Adresse mit gültiger Selbstsignatur, nicht widerrufen, nicht
  abgelaufen. Privates Schlüsselmaterial wird abgewiesen, bevor gpg es sieht.

`SignatureVerifier` importiert die eigenen Schlüssel der Person, jeden Schlüssel, den sie für `From`
hat, und den Autocrypt-Schlüssel der Nachricht. Eigener Schlüssel oder gepinnter `current`: Valid.
Ungepinnter `current`: Untrusted. Ein `changed`-Schlüssel, ein Autocrypt-Schlüssel, während die
Adresse schon Schlüssel hat, oder ein fehlender Schlüssel, während sie einen `current` hat:
**KeyChanged**. Der Browser wendet dieselbe Tabelle auf eine Signatur in verschlüsselter Mail an
(`assets/crypto/message.js`, mit den Schlüsseln von `GET /contact-keys/sender`). Jede Änderung an den
Schlüsseln eines Absenders reiht `VerifySignatureMessage` für seine neuesten zweihundert
PGP-signierten Nachrichten ein (`SignatureRecheck`); die Aktionen im Banner prüfen die geöffnete
Nachricht sofort.

**Aus eingehender Mail übernommen** von `CollectContactKeysStep` → `CollectContactKeysMessage` auf
`enrich`: der Autocrypt-Header nur, wenn `addr=` gleich `From` ist (und nur, wenn er der einzige
gültige Header ist, ohne unbekanntes kritisches Attribut), angehängte Schlüssel aus der Rohquelle
nur mit einer User-ID für `From`, nie für Mail von den eigenen Adressen der Person. Eine Nachricht
kann jedermanns Schlüssel tragen; dass der Absender für seinen eigenen bürgt, ist die einzige
Aussage, die sie machen kann.

**WKD über den Server** (`WkdLookup`), weil die Seite nur mit ihrem eigenen Ursprung sprechen darf.
Das ist ein Endpunkt, der URLs abruft, die aus Benutzereingaben abgeleitet sind, also:

- Die URL wird gebaut, nie übernommen: `https://openpgpkey.<domain>/.well-known/openpgpkey/<domain>/hu/<z-base-32 des SHA-1 des kleingeschriebenen lokalen Teils>?l=<lokaler Teil>`,
  danach `https://<domain>/.well-known/openpgpkey/hu/…`. Die Domain muss ein IDNA-kodierter DNS-Name
  mit mindestens zwei Labels sein — kein IP-Literal, kein Port, kein Pfad, kein nackter
  Container-Name.
- Der Host wird zuerst aufgelöst; ist unter seinen A- und AAAA-Einträgen eine private oder
  reservierte Adresse, wird nichts gefragt. Die geprüfte Adresse wird festgehalten (`resolve`), und
  `NoPrivateNetworkHttpClient` prüft die Adresse, mit der tatsächlich verbunden wird.
- Keine Weiterleitungen (`max_redirects: 0`), 4 Sekunden Timeout und 8 Sekunden Obergrenze, und ein
  Limit von 256 KiB, das schon während des Empfangs greift.
- Antworten werden in `cache.app` gemerkt, einen Tag bei einem Treffer und eine Stunde ohne, und nur
  Suchen, die hinausgehen, zählen gegen den Limiter `wkd_lookup` pro Person (30 in 10 Minuten).
- Nur Schlüssel mit einer User-ID für die gefragte Adresse werden behalten.

keys.openpgp.org (VKS) wird nicht gefragt; das Konzept lässt das als optionale Instanz-Einstellung
offen.

## Verschlüsselte Mails schreiben und senden

Phase 5 von Issue #28. Der Server baut, signiert und verschlüsselt nichts: Er hat keinen privaten
Schlüssel. Das Schreibfenster entscheidet (`assets/controllers/compose/encryption_controller.js`,
nach den Schlüsseln, die `POST /contact-keys/recipients` nennt), und sobald ein Entwurf verschlüsselt
werden soll, wird er **versiegelt**:

- jedes automatische Speichern verschlüsselt das Getippte an die aktiven eigenen Schlüssel und
  schickt es als `sealed[draft]`, die Body-Felder leer. `SealedDrafts` legt es auf der Platte ab
  (`SealedMessageStorage`, `<storage>/sealed/`), setzt `Message::$sealedDraft` und leert ab dann den
  Body jedes Speicherns dieser Zeile, egal was es mitbringt — einmal versiegelt, immer versiegelt;
- Anhänge werden vor dem Hochladen im Browser verschlüsselt (`MessagePart::$sealed`): kein
  Vorschaubild, keine Vorschau, ausgeliefert nur als `application/octet-stream`;
- eingebettete Bilder bleiben als `data:`-URIs im versiegelten Entwurf;
- eine Antwort zitiert verschlüsselte Mails als Markierung `data-quote-sealed`, die der Browser aus
  der Rohquelle füllt.

Beim Senden baut der Browser die ganze MIME-Entität (`assets/crypto/mime.js`) und verschlüsselt sie
(PGP/MIME, innen signiert, an alle Empfänger und den Absender) oder signiert sie (multipart/signed)
oder lässt sie für einen versiegelten Entwurf, der unverschlüsselt rausgeht, unverändert, und schickt
sie als `sealed[entity]` mit Art, den Fingerprints, an die verschlüsselt wurde, und dem Signierenden.
`MessageSendService` legt sie über `SealedEntityPart` Byte für Byte auf die Leitung; die äußeren
Header — From, To, Subject, Date, Message-ID, Autocrypt — schreibt `Email` wie bei jeder Mail, also
funktionieren alle drei Sender, Rückgängig und geplanter Versand unverändert. Danach werden die
gesendeten Bytes zur Rohquelle der Zeile, die der Lesebereich wie jede verschlüsselte Mail
entschlüsselt, und die versiegelten Dateien werden gelöscht.

`SealGuard` wird gefragt, wenn die Entität ankommt, und noch einmal, wenn der Versandjob läuft: Die
Empfänger müssen noch dieselben sein, für die sie gebaut wurde, und jeder muss noch einen brauchbaren
Schlüssel haben (`PublicKeyReader`: nicht widerrufen, nicht abgelaufen, zum Verschlüsseln geeignet),
der unter den Fingerprints ist. Sonst scheitert der Versand, und die Person erfährt es; auf dem
Server wird nichts neu gebaut.

`Autocrypt:` steht auf jeder ausgehenden Nachricht, mit dem aktiven Schlüssel des Absenders für die
From-Adresse (`AutocryptHeader`), außer die Person hat es ausgeschaltet.

## Entschlüsselte Mails auf dem Server behalten

Phase 3b von Issue #28, aus, solange eine Person es nicht für ein Konto wählt
(`Account::$keepDecrypted`), und für die ganze Installation abgelehnt mit
`APP_E2E_SERVER_PLAINTEXT=forbid` (`DecryptedMailPolicy`).

Ein Browser mit offenem Schlüsselbund entschlüsselt und schickt die **innere MIME-Nachricht als
Ganzes** an `POST /mail/message/{id}/plaintext` — im Hintergrund (`crypto--decrypted-mail`, das
`GET /mail/decrypted/pending` abarbeitet) und beim Lesen (`mail--encrypted-message`). `DecryptedMail`
vertraut nichts davon: Es zerlegt die MIME mit dem Parser des Syncs, bereinigt das HTML selbst,
speichert Anhänge wie der Sync, legt die entschlüsselte Nachricht neben der Rohquelle ab
(`SealedMessageStorage::DECRYPTED`; die Rohquelle bleibt das Chiffrat) und markiert die Zeile mit
`plaintext_source = client`. Danach, was der Ingest getan hätte: der Suchvektor (aus `body_text`
erzeugt), Insights, Terminvorschläge, Termine aus `.ics`-Teilen und die Regeln — nur solange
ungelesen.

Die Modelle sind eine eigene Zustimmung (`Account::$keepDecryptedAi`). `DecryptedMailPolicy::allowsAi()`
fragen die vier Stellen, die Mailtext an ein Modell geben — `MessageEmbedder`, `ClassifyMailHandler`,
`ThreadTranscript` (Zusammenfassungen), `ReplyContextReader` (der Schreibassistent) —, und sagt für
einen vom Client gelieferten Body nur Ja, wenn der Modell-Host der Funktion vollständig in private
oder Loopback-Bereiche auflöst oder dem Host entspricht, dem die Person namentlich zugestimmt hat.

Ausschalten reiht `ForgetDecryptedMailMessage` auf `bulk` ein: Bodys, Anhänge, die entschlüsselte
Kopie, Embeddings, Thread-Zusammenfassungen, Insights und Vorschläge aller solcher Nachrichten
werden gelöscht. Schon angelegte Kalendertermine bleiben.

## Verschlüsselte Betreffs

Phase 6b von Issue #28, Header-Schutz nach RFC 9788. Das Schreibfenster legt `Subject`, `From`, `To`
und `Cc` in die verschlüsselte Entität, markiert sie mit `protected-headers="v1"`
(`assets/crypto/mime.js`) und schickt `sealed[protectedSubject]`; `MessageSendService` schreibt den
äußeren Betreff dann als `...`. Die Zeile behält den echten: Der Server kannte ihn schon aus dem
Entwurf. Beim Lesen liefert `openEncrypted()` den inneren Betreff, den der Banner und eine
Platzhalter-Überschrift zeigen und mit dem ein `Re: ...` einer Antwort vervollständigt wird;
`DecryptedMail` übernimmt ihn für die Zeile, wenn das Konto entschlüsselte Mails behält. Header-Schutz
gibt es nur für verschlüsselte Mails — das Innere einer signierten Nachricht ist so lesbar wie ihr
Äußeres.

## S/MIME

Phase 6c von Issue #28. Dieselbe Arbeitsteilung wie bei OpenPGP: Der Browser macht die Kryptografie
(`assets/crypto/smime.js`, PKI.js für die Strukturen), der Server bewahrt auf, was er nicht öffnen
kann, und hält die Regeln.

```
eigenes Zertifikat = X.509-PEM + Kette, aus einer PKCS#12-Datei, im Browser geöffnet  // SmimeCertificate
privater Schlüssel = PKCS#8, AES-256-GCM-verpackt unter dem User-Key, Zweck plmail/smime-key
Kontakt-Zertifikat = X.509-PEM aus einer gültigen Signatur, current oder changed       // ContactCertificate
```

**Import.** `POST /keyring/smime` (Schlüsselbund-Token) nimmt das Zertifikat, seine Kette und den
verpackten Schlüssel. `SmimeCertificates` liest das Zertifikat selbst mit OpenSSL — Fingerabdruck
(SHA-256 des DER), Adressen (subjectAltName, emailAddress), Daten —, statt dem Browser zu glauben,
und lehnt einen Nicht-RSA-Schlüssel, ein Zertifikat für keine der Adressen der Person und eine
Verpackung ab, die nicht `v1.…` ist. Ein neueres Zertifikat, das dieselben Adressen abdeckt, setzt das
ältere auf `retired` (es entschlüsselt, signiert aber nie und wird nie verschlüsselt). Der verpackte
Schlüssel hängt an einem User-Key: Eine Passwortänderung verpackt hier nichts neu, und wird der
User-Key oder die Person entfernt, geht er mit (`ON DELETE CASCADE`). Das PKCS#12-Passwort und die
Datei verlassen den Browser nie.

**Zertifikate der Kontakte.** Behalten werden sie nur aus einer Signatur, die stimmt und den Absender
nennt: `SignatureVerifier` (OpenSSL, signierte Mails) und `POST /contact-keys/message/{id}/smime-signer`
(der Browser, für eine Signatur in verschlüsselter Mail, die er mit PKI.js geprüft hat; der Server
beurteilt nur das Zertifikat, das er bekommt, für den Absender der eigenen Zeile, nach dem
Ownership-Voter). Vertrauen (`ContactCertificates`): Ein Zertifikat, das bis zu den Stellen des
Systems reicht (`CertificateReader`, `openssl_get_cert_locations()` plus `SMIME_CA_FILE`), ist
`verified`, und ein neueres bestätigtes ersetzt ein bestätigtes oder abgelaufenes aktuelles; alles
andere ist Vertrauen beim ersten Mal, mit demselben partiellen Unique-Index wie bei Kontakt-Schlüsseln
(`uniq_contact_certificate_current`). Es gibt keine Prüfung über CRL oder OCSP.

**Lesen.** `openEncrypted()` nimmt die EnvelopedData (`smime.p7m`), öffnet sie mit den
S/MIME-Schlüsseln des Schlüsselbunds und gibt die innere Entity auf denselben Weg wie bei OpenPGP:
postal-mime, DOMPurify, der Sandbox-Frame, die Übergabe entschlüsselter Mails. Der RSA-Schlüsseltransport
mit PKCS#1 v1.5 läuft über BigInt-Arithmetik, geblindet, weil WebCrypto keine
PKCS#1-v1.5-Verschlüsselung hat und Outlook und Thunderbird genau das schicken; RSA-OAEP läuft über
WebCrypto, AES-CBC (128/192/256) für den Inhalt über @noble/ciphers. 3DES und AuthEnvelopedData werden
nicht geöffnet.

**Senden.** Der Composer wählt OpenPGP, wenn jeder Empfänger einen Schlüssel hat, S/MIME, wenn jeder
Empfänger ein nutzbares Zertifikat hat, der Absender eines für die Absenderadresse hat und es kein Bcc
gibt. Er signiert (abgetrennte SignedData, RSA-SHA256, Zertifikat und Kette des Signierenden darin,
multipart/signed mit `micalg=sha-256`), verschlüsselt danach an jeden Empfänger und den Absender
(AES-256-CBC, RSA PKCS#1 v1.5, Empfänger über Aussteller und Seriennummer) und schickt die Entity mit
`sealed[scheme]=smime`. `SealedDrafts` lehnt eine Entity ab, deren Außenseite nicht S/MIME der
angegebenen Art ist (`MessageCryptoDetector`), und `SealGuard` prüft — beim Eintreffen und wenn der
Sendejob läuft —, dass das Zertifikat jedes Empfängers sein aktuelles und nicht abgelaufen ist und dass
der Signierende das aktive, nicht abgelaufene Zertifikat der Person für die Absenderadresse ist.
Entwürfe bleiben mit OpenPGP versiegelt: Nur die Person selbst öffnet sie je.

## Native Clients und der Schlüsselbund

Phase 6 von Issue #28: `urn:plmail:params:jmap:keys` gibt einem App-Passwort, was die Browser-Sitzung
hat — den Schlüsselbund weiterhin verpackt (`Keyring/get`, ohne die Recovery-Kopien), Kontaktschlüssel
und die Suche des Schreibfensters (`RecipientKeys`, mit dem Web geteilt), die Übergabe entschlüsselter
Mails (`DecryptedMail`, dieselben Regeln) —, und Email bekommt ihre Krypto-Felder. Nichts Lesbares
kommt dazu.

**`Keyring/get` nennt die Wurzel** (`root`: `password` | `passphrase`) und wie sie gestreckt wird
(`rootKdf`: `salt`, `iterations`; ein leeres Salt an der Passphrase heißt, sie ist noch nicht
eingerichtet), damit eine App bei einem per Single Sign-on angelegten Konto nach der Passphrase fragt
— nie nach einem Passwort, das es nicht hat.

**Die Kopplung übergibt die User-Keys, ohne dass der Server sie sieht.** Der Browser versiegelt sie
unter einem frischen 32-Byte-Übertragungsschlüssel (AES-GCM, Zweck `plmail/pairing-transfer`),
schickt nur die versiegelte Kopie und setzt den Übertragungsschlüssel ins Fragment der
Kopplungs-URI; diesen QR-Code zeichnet der Browser selbst, weil ein vom Server gezeichneter den
Schlüssel bräuchte. Die versiegelte Kopie liegt seine zwei Minuten lang neben dem Code im Cache und
geht beim Einlösen mit dem App-Passwort hinaus. Wer den QR-Code fotografiert, kann ohnehin koppeln;
die Schlüssel geben nichts, was eine Kopplung nicht schon gab.

**`Email/import` ist der Weg, eine im Client gebaute Nachricht zu senden.** Ein Import als `$draft`
behält seine Content-*-Header und seinen Body als ausgehende Entität — der Weg des Web-Schreibfensters
aus Phase 5 —, und ein verschlüsselter nennt die Fingerprints, an die er verschlüsselt ist, geprüft
von `SealGuard` beim Import und beim Senden.

## Schlüssel veröffentlichen: das Web Key Directory

Phase 6d von Issue #28. `WkdController` beantwortet die beiden Formen des Entwurfs —
`/.well-known/openpgpkey/<domain>/hu/<hash>` (erweitert) und `/.well-known/openpgpkey/hu/<hash>`
(direkt, Domain aus dem `Host`-Header) — und beide `policy`-Dateien. `WkdDirectory` entscheidet; es
ist die andere Seite von `WkdLookup` und teilt dessen Hash (`WkdLookup::hash()`) und Domain-Form
(`WkdLookup::domain()`), damit das Ausgelieferte unter dem Namen liegt, den jeder Client berechnet.

**Öffentlich, ohne Session.** Das Präfix hat eine eigene Firewall mit `security: false`, wie
`/branding/`: kein Token, keine Session, kein CSRF, keine Weiterleitung zur Anmeldung. Nichts, was ein
Aufrufer schickt, wird geschrieben; der eine Schreibvorgang ist `AddressKey::$firstUsedAt`, wenn ein
Schlüssel zum ersten Mal ausgeliefert wird, damit er nicht mehr als ungesehen gelöscht werden kann.

**Drei Ebenen entscheiden, jede kann nur Nein sagen:**

- **Die Domains der Installation** (`APP_WKD_DOMAINS`, standardmäßig leer). Nie „jede Domain, auf der
  jemand eine Adresse hat“: Wer hier ein Gmail-Konto hat, gibt plMail kein Mitspracherecht über
  `gmail.com`.
- **Nur die Anmeldeadresse.** `User::$email` ist eindeutig und von einem Admin gesetzt. Jede andere
  Adresse ist die Behauptung der Person selbst — ein manueller Alias, die eingetippte Adresse eines
  IMAP-Kontos und sogar dessen Server —, und sie zu veröffentlichen ließe jeden mit einem Konto einen
  Schlüssel unter die Adresse eines anderen legen.
- **Der Schalter der Person** (`User::$wkdUnpublished`, im Konfigurations-Backup).

**Der Schlüssel** ist der von `SendingKey`: aktiv, der primäre für die Adresse, nicht abgelaufen. Ein
ersetzter, widerrufener oder abgelaufener Schlüssel wird sofort nicht mehr ausgeliefert.
`WkdKeyExport` reduziert ihn mit dem gpg-Programm in einem Wegwerf-Home (`--export-options
export-minimal --export-filter keep-uid=uid =~ <adresse>`), liest das Ergebnis mit `PublicKeyReader`
zurück und liefert es nur aus, wenn gpg es für diese Adresse noch annimmt; das Ergebnis wird nach
Armor und Adresse gecacht.

**Keine Aufzählung über die eine Tatsache hinaus.** Ein unbekannter Hash, eine ausgeschaltete
Adresse, eine Adresse ohne Schlüssel, ein Alias, eine nicht bediente Domain, das ausgeschaltete
Verzeichnis: ein 404, dieselben Header, leerer Body. Der Hinweis `?l=` wird ignoriert — nur der Hash
findet eine Adresse, also holt ein Hinweis auf jemand anderen nichts von dieser Person. Nicht
verbergen lässt sich, dass eine veröffentlichte Adresse einen Schlüssel hat; der Limiter `wkd_serve`
(300 pro Stunde und IP) bremst das Abarbeiten einer Namensliste.

**Die Prüfung in den Einstellungen** (`POST /settings/encryption/wkd/check`) geht durch `WkdLookup`
mit allen seinen Regeln — nur HTTPS, keine Weiterleitungen, keine privaten Adressen, das Limit pro
Person — an seinem Cache vorbei. Beide Aktionen in den Einstellungen nehmen eine Adresse aus dem
Formular, und beide lehnen eine ab (404), die nicht die eigene der Person auf einer bedienten Domain
ist.

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

**`main` bringt eine Anmeldedrosselung mit**, drei Grenzen in `App\Security\LoginRateLimiter`:
5 je 15 Minuten je Benutzername und Adresse; 25 je Adresse, die Absicherung gegen einen Client,
der ein Passwort über viele Konten streut, lockerer, damit ein Haushalt hinter einer NAT-Adresse
sich nicht selbst aussperrt; und 20 je Benutzername, egal von welcher Adresse, damit auch über
viele Adressen verteilte Versuche an eine Decke stoßen. Symfonys Vorgabe kennt nur die ersten
beiden — diese Seite nannte das früher „je Benutzername“, was es nicht ist. Remember-me hält seine
Tokens auf dem Server (`rememberme_token`, Symfonys DoctrineTokenProvider), eine Zeile pro Gerät, mit
60 Tagen Lebensdauer. Abmelden löscht die Zeile dieses Geräts, also öffnet ein Cookie auf einer
Anfrage, die vor dem Abmelden losging und danach ankommt, nichts mehr — mit einem rein signierten
Cookie haben die eigenen Hintergrund-Anfragen der Seite die Person sofort wieder angemeldet. Ein
Passwortwechsel beendet weiterhin alle.

**Single Sign-on ist ein zweiter Authenticator auf `main`, keine zweite Firewall.**
`App\Security\OidcAuthenticator` endet im selben Token für denselben `User` wie das Passwortformular,
also gelten der User Checker, die Drosselung oben und die Zwei-Faktor-Abfrage dafür unverändert —
die Betreiberseite steht unter [Single Sign-on](../providers/oidc.md). Vier Entscheidungen solltest
du kennen:

- **Die Signatur des ID-Tokens wird nicht geprüft.** Das Token kommt ausschließlich vom
  Token-Endpunkt des Anbieters, über TLS, im Tausch gegen den Code und das Client-Secret, und OpenID
  Connect Core § 3.1.3.7 lässt für genau diesen Fall die TLS-Verbindung an die Stelle der Signatur
  treten. Deshalb müssen der Issuer und seine Endpunkte `https://` sein, und deshalb folgt keine
  Anfrage an den Anbieter einer Weiterleitung. Wer ein ID-Token von irgendwo anders annehmen will,
  muss zuerst die JWKS-Prüfung ergänzen; `IdTokenValidator` sagt das.
- **Ein Konto wird über Issuer und Subject verknüpft**, nach der ersten Anmeldung nie mehr über die
  Adresse gefunden. Die erste Verknüpfung läuft über die Adresse, und nur, wenn der Anbieter sie als
  bestätigt meldet — sonst könnte jede Person, die ihre eigene Adresse beim Anbieter ändern darf, ein
  Konto übernehmen.
- **Konfiguriert wird es unter Administration → SSO, mit der Umgebung als Rückfall** — ein
  gespeicherter Anbieter hat Vorrang, im Ganzen, und die `OIDC_*`-Variablen gelten, wenn keiner
  gespeichert ist (`OidcSettings`). Das ist eine bewusste Ausnahme von der Regel, dass eine
  Admin-Sitzung kein zweiter Weg in jedes Postfach werden darf: Diese Einstellung entscheidet, wer
  sich anmelden darf, und eine Administratorin, die sie auf einen eigenen Anbieter zeigen lässt,
  kann sich als jedes Konto ohne zweiten Faktor anmelden, weil ein neuer Issuer jedes Konto über
  die Adresse neu verknüpft. Die Zwei-Faktor-Authentifizierung hält weiterhin, weil der Code
  abgefragt wird, nachdem der Anbieter geantwortet hat. Die erste Fassung las aus genau diesem Grund
  nur die Umgebung; es ohne Neustart einrichten zu können, wurde als den Preis wert beurteilt.
- **Es verlagert Vertrauen zum Anbieter.** Wer den Anbieter betreibt, kann sich als jede Person
  anmelden, das ist der Sinn von Single Sign-on. Die weniger offensichtliche Hälfte: Administration →
  Benutzer kann die Adresse eines Kontos ändern, und ein unverknüpftes Konto wird über die Adresse
  gefunden, also erreicht eine Administratorin mit einem zweiten Login beim Anbieter ein Konto, das
  noch nicht verknüpft ist. `app:user:oidc-unlink` gibt es aus demselben Grund nur auf der Konsole.

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

Das Web Key Directory (`/.well-known/openpgpkey/`) steht nicht in der Liste: Wie `/branding/` hat es
eine eigene Firewall mit `security: false`, weil seine Antworten für alle gleich sind und keine Session
berühren dürfen. Was es antwortet, steht unter [Schlüssel veröffentlichen](#schlüssel-veröffentlichen-das-web-key-directory).

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
   LAN ist bei Nextcloud, Immich und Paperless-ngx der Normalfall, dieser Schalter wird also oft
   gesetzt sein —
   der Punkt ist, dass Klartext-Anmeldedaten auf der Leitung damit zu einer bewussten
   Admin-Entscheidung werden statt zu einer stillen Voreinstellung.
3. **Loopback-, Link-Local- und private Bereiche werden abgelehnt**, sofern der Host nicht in
   `INTEGRATIONS_ALLOWED_HOSTS` steht. Der Host wird **aufgelöst**, und jede Adresse, auf die er
   auflöst, wird gegen `IpUtils::PRIVATE_SUBNETS` geprüft — Loopback, RFC1918, Link-Local
   (einschließlich des Cloud-Metadaten-Endpunkts unter 169.254.169.254), Carrier-Grade-NAT, die
   reservierten Bereiche und IPv4-gemappte IPv6-Adressen wie `::ffff:127.0.0.1`. Eine
   Zeichenkettenprüfung sah weder Containernamen (`database`, `mercure`) noch Wildcard-DNS
   (`127.0.0.1.nip.io`) noch die IPv4-Schreibweisen, die libc akzeptiert (`127.1`, `2130706433`);
   der Resolver beantwortet sie alle.

Anmeldedaten in der URL werden rundheraus abgelehnt, denn sie landeten überall dort im Log, wo
die URL landet, und überschrieben stillschweigend die an der Verbindung hinterlegten.

**Der Validator ist nicht die Durchsetzung.** Er läuft beim Speichern einer Adresse, damit es eine
lesbare Fehlermeldung gibt. Die Anfragen laufen über `App\Infrastructure\Http\UserUrlHttpClient`,
der Symfonys `NoPrivateNetworkHttpClient` umhüllt: Er löst den Host selbst auf, nagelt die geprüfte
Adresse mit der Option `resolve` fest und prüft jeden Weiterleitungsschritt neu. Das schließt die
beiden Lücken, die der Validator allein offen ließ — DNS-Rebinding zwischen Prüfung und Verbindung,
und Weiterleitungen, denen die Treiber für Nextcloud, Paperless-ngx und Immich bisher zwanzig Stufen
tief ungeprüft gefolgt sind. Die Ausnahme kommt aus derselben Quelle wie beim Validator: Ein Host in
`INTEGRATIONS_ALLOWED_HOSTS` oder der Host einer von einer Admin-Person festgenagelten
Serveradresse darf für diese Anfrage auf seinen eigenen Adressen erreicht werden, sonst nichts
Privates.

Push-Endpunkte laufen über eine zweite Instanz desselben Clients mit eigener Ausnahmeliste,
`PUSH_ALLOWED_HOSTS`, und folgen nie einer Weiterleitung; `App\Jmap\Push\PushEndpointPolicy` lehnt
einen privaten Endpunkt schon bei der Registrierung ab, aus der Web-App wie über
`PushSubscription/set`.

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

## Fremdes HTML darstellen

Ein Nachrichtenkörper ist Markup, das eine fremde Person geschrieben hat, und plMail stellt es
dar. Dafür mussten drei Dinge wahr sein, und nur eines davon war es.

### Externe Bilder sind blockiert, und auch die Zustimmung entblößt die Lesende nicht

Ein `<img src="https://absender.example/open.gif">` in einer E-Mail ist eine Lesebestätigung.
Es feuert beim Öffnen und verrät der Absenderin Zeitpunkt, IP-Adresse, User-Agent und — bei
einer empfängerspezifischen URL — genau, welche Empfängerin es war. Ein 526×5-„Abstandshalter“
ist die übliche Tarnung.

`App\Service\Mail\RemoteContentBlocker` schreibt den Körper auf dem Weg zur Vorlage um.
Absolute `http(s)`- und protokollrelative `img`-Quellen sowie externe `url()`-Referenzen in
Inline-Styles — dort landet durch das CSS-Flatten in `MailBodySanitizer` jede `<style>`-Regel —
verlieren ihre Referenz an einen transparenten Platzhalter; die Proxy-URL wird für das spätere
Freigeben auf `data-plmail-src` geparkt. `cid:`-Inline-Bilder wurden beim Ingest auf unsere
eigene Anhang-Route aufgelöst und bleiben unangetastet, ebenso `data:`-URIs, die keine Anfrage
stellen.

**Das läuft zur Renderzeit, nicht beim Ingest**, aus zwei Gründen, die es wert sind, behalten
zu werden: Jede bereits gespeicherte Nachricht wurde sanitisiert, bevor es dies gab — eine
Blockade beim Ingest schützte also nur künftige Post; und die Antwort hängt davon ab, *wer
fragt*, denn „Bilder dieser Absenderin immer anzeigen“ gilt pro Benutzerin
(`trusted_image_sender`). Eine gespeicherte Form kann keine Antwort tragen, die je nach
Lesender anders ausfällt.

**Post, die die Lesende selbst gesendet hat, wird nicht blockiert.** Die Blockade verhindert,
dass eine Fremde erfährt, dass ihre Nachricht geöffnet wurde — bei der eigenen Post hieße das zu
fragen, ob man sich selbst vertraut. „Eigene“ wird am Label Gesendet abgelesen, das nur die
eigene Sitzung des Kontos an eine Nachricht hängt, und nie an der From-Zeile: Eine gefälschte
Mail im Posteingang trägt dort deine Adresse so leicht wie jede andere. Die Bilder laden
weiterhin über den Proxy, und Spam gewinnt weiterhin.

Stimmt die Lesende zu, laden die Bilder **über `/mail/image-proxy`** statt direkt — auch ein
zugestimmter Aufruf verrät so keine IP. Genau dafür gibt es einen Proxy und nicht bloß eine
Freigabe.

### Die Regeln des Bild-Proxys

`App\Service\Mail\ImageProxyFetcher` ist ein Dienst, der sich dorthin verbindet, wohin die
E-Mail einer fremden Person ihn schickt — aus dem Container-Netz heraus. Seine Regeln, in der
Reihenfolge der Anwendung:

1. **Nur https.** Ein http-Abruf ist eine Klartextanfrage, die unser Server zu einem Zeitpunkt
   stellt, den die Absenderin wählt.
2. **Nur Port 443.** Eine URL ist sonst ein bequemer Weg, einen Server sein eigenes Netz
   scannen zu lassen; den Port festzunageln entfernt die ganze Klasse, statt die interessanten
   Ports auf eine Sperrliste zu setzen.
3. **Jede Adresse, auf die der Host auflöst, muss öffentlich sein**, und die Verbindung wird
   anschließend über die Option `resolve` des Clients auf eine geprüfte Adresse
   **festgenagelt**. Prüfen und den Client danach erneut auflösen zu lassen ist die
   Rebinding-Lücke. Abgelehnt werden: RFC1918, Loopback, Link-Local (einschließlich
   169.254.169.254), IPv6-ULA und -Link-Local, `100.64.0.0/10` sowie IPv4-gemappte
   IPv6-Adressen wie `::ffff:127.0.0.1`. Namen werden schon vor dem Resolver abgelehnt bei
   `localhost`, `.internal`, `.local` und jedem **einzelnen Label ohne Punkt** — so heißen
   Dienste in einem Container-Netz.
4. **Weiterleitungen werden von Hand verfolgt**, höchstens drei, und jeder Sprung durchläuft
   die Regeln 1–3 erneut. `max_redirects` im Client folgt ihnen an jeder Prüfung vorbei, und
   ein öffentlicher Host, der auf den Metadaten-Endpunkt weiterleitet, ist die
   Standard-Exfiltration.
5. **Die Antwort muss ein Bild sein und bei 8 MB enden.**

Referer und Cookie werden nie gesendet, weil die Anfrage von Grund auf hier gebaut wird. Jeder
Fehlschlag antwortet mit demselben transparenten GIF: „abgelehnt“ von „Zeitüberschreitung“
unterscheidbar zu machen wäre gegenüber einer internen Adresse genau das Portscan-Ergebnis, das
Regel 2 verweigert.

Die signierte URL ist **nicht** der SSRF-Schutz, und der Docblock sagt das: Wer ein Konto hat,
kann sich selbst einen Link mailen und bekommt eine gültige Signatur dafür. Die Signatur
beweist, dass der Parameter nicht von Hand getippt wurde; die Adressprüfungen beschränken, wohin
wir uns verbinden.

Das ist bewusst **nicht** `IntegrationUrlValidator`. Der hat eine Positivliste und eine vom
Admin festgenagelte Basis-URL, weil dort eine Benutzerin ihre eigene Nextcloud im LAN benennt;
dessen Notausgänge hier zu übernehmen hieße, dass ein Admin, der einen LAN-Host für Immich
freigibt, damit auch die Post jeder Absenderin dorthin gelassen hätte.

### Der Körper wird in einem Sandbox-Frame dargestellt

Der sanitisierte Körper wurde bisher in das DOM der Anwendung selbst eingefügt. Der Sanitizer
hielt — aber „keine Lücken, für immer, gegen jede Absenderin“ ist eine Behauptung, die dauerhaft
wahr bleiben muss, und der Preis dafür, dass sie einmal falsch ist, war ein Skript auf derselben
Origin mit dem Sitzungs-Cookie.

`templates/mail/_message_body.html.twig` rendert ihn in ein `<iframe srcdoc>` mit
`sandbox="allow-popups allow-popups-to-escape-sandbox allow-scripts"`. Die Sicherheit kommt aus
dem, was **fehlt**: kein `allow-same-origin` (eine opake Origin — kein Sitzungs-Cookie, kein
Storage, kein Zugriff auf das Elterndokument), kein `allow-forms`, kein `allow-top-navigation`,
kein `allow-modals`, kein `allow-downloads`. `allow-scripts` ist da, weil die Höhe eine Tatsache
ist, die nur das eingebettete Dokument messen kann, und ein Frame ohne `allow-same-origin` von
außen nicht messbar ist; bezahlt wird das durch die eigene `<meta>`-CSP des Frames, die
`script-src` auf eine **Nonce pro Render** beschränkt, die unsere ~30 Zeilen Höhen- und
Hover-Meldung tragen und Markup aus einer E-Mail unmöglich tragen kann.

`img-src` der CSP benennt unsere eigene Origin und `data:` ausdrücklich — nicht `'self'`, was in
einer opaken Origin auf nichts passt. Ein externes Bild, das der Blocker übersehen hätte, wird
also auch vom Browser abgelehnt: Beide Schichten müssen versagen, damit ein Pixel feuert.

**Jede Warnung wird außerhalb des Frames gezeichnet.** Ein Banner innerhalb der Sandbox ist ein
Banner, das das Markup der Mail selbst malen kann, und ein „Bilder anzeigen“-Knopf, den eine
Nachricht fälschen kann, ist schlimmer als gar keiner. Dasselbe gilt für die Vorschau der
überfahrenen Links: erkannt im Frame, wo die Links sind, gezeichnet vom Elterndokument, damit
keine Nachricht eine Statusleiste malen kann, die ein anderes Ziel behauptet.

Die Druckansicht (`templates/mail/print.html.twig`) ist **nicht** eingerahmt, weil ein
Druckdialog auf einer Seite, deren Inhalt in einem Frame steckt, dessen Scrollbox paginiert.
Sie erkauft dieselben zwei Eigenschaften anders: dieselbe CSP, und kein Anwendungs-DOM auf der
Seite, mit dem das CSS der Mail kollidieren könnte. Externe Bilder blockiert sie nach derselben
Positivliste — Drucken ist Lesen.

### Die Phishing-Heuristik, und was sie genau auslöst

`App\Service\Mail\SenderIdentityChecker` — zwei Regeln, keine Punktzahl, kein Modell, kein
Schwellenwert.

- **Regel 1, DomainInName.** Der Anzeigename enthält etwas in der Form eines Hostnamens, dessen
  registrierbare Domain von der der From-Adresse abweicht.
- **Regel 2, BrandInName.** Läuft *nur*, wenn der Anzeigename eine Rechtsform als ganzes Wort
  trägt (GmbH, Inc, Ltd, AG, B.V., …) — das unterscheidet eine Organisation von einer Person.
  Dann: Wörter ab 4 Buchstaben, ohne die Rechtsform und ohne generisches Firmenvokabular
  (Online, Group, Services, Deutschland, …); bleibt mindestens eines übrig und kommt **keines**
  davon in der registrierbaren Domain der Absenderin vor (Satzzeichen entfernt), widersprechen
  sich die Namen.

Keine der beiden läuft, wenn Authentication-Results **DKIM als bestanden für die eigene
registrierbare Domain der Absenderin** ausweist — eine Domain, die die Nachricht signiert hat,
darf in ihren Anzeigenamen schreiben, was sie möchte.

Ein Anzeigename ohne Rechtsform wird nie beurteilt; das hält „Jane Cooper“
&lt;jane@gmail.com&gt; still. Die Teilstring-Prüfung ist bewusst großzügig: „PayPal Inc.“
&lt;service@paypal-secure.example&gt; ist ein **bekannter, getesteter Fehlschlag**, denn ihn zu
fangen hieße, Editierabstände gegen eine Markenliste zu bewerten, und jeder damit erkaufte
Fehlalarm ginge auf gewöhnliche Post. Eine Warnung, die bei gewöhnlicher Post feuert, trainiert
genau den Reflex, den sie unterbrechen soll.

Das Spam-Banner ist davon getrennt und braucht gar keine Heuristik: Die Nachricht trägt ein
Label mit der Rolle Spam. Eine vertraute Absenderin bekommt im Spam **keine** Bilder — die
Positivliste hält eine Überzeugung über eine Absenderin fest, und eine Nachricht im Spam ist der
Widerspruch der Anbieterin dagegen, dass dies wirklich jene Absenderin ist.

**Einstellungen → Mail → Bilder** kann die Positivliste auf alle Absender ausweiten
(`images.always_show` in den Einstellungen der Person, aus, bis es eingeschaltet wird, #124). Es
wird in `MessageRenderer` neben der Positivliste gelesen und endet, wo sie endet: Spam gewinnt
weiterhin, und Bilder laufen weiterhin über den Proxy, sodass eine Absenderin erfährt, dass eine
Nachricht geöffnet wurde, aber nicht die Adresse der Leserin. Es ist absichtlich von der
Positivliste getrennt, damit keine „Nicht mehr anzeigen“-Zeile für eine Absenderin erscheint, der
niemand vertraut hat, und damit Ausschalten die Einträge pro Absender so lässt, wie sie waren.

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

**Eine neue Stelle, die den Schlüsselbund braucht, muss über `requireOpen()` fragen, nicht über
`ensure()`.** `ensure()` öffnet nur, was die Sitzung still öffnen kann; in einer Angemeldet-bleiben-
oder Single-Sign-on-Sitzung ist das nichts, und die Funktion sähe genau für die kaputt aus, die nie
ein Passwort getippt haben. Der Hintergrund-Entschlüssler ist die eine bewusste Ausnahme: Ein Dialog,
um den niemand gebeten hat, ist schlimmer als eine Mail, die etwas später entschlüsselt wird.

**Der beweisfreie Sitzungsweg muss der Passphrase vorbehalten bleiben.** Registrierte ein
Schlüsselbund am Passwort seine Hälfte dort, umginge er die Drosselung, die `POST /keyring/unlock`
dem Raten des Passworts auferlegt.

**Alles, was auf „das Passwort“ reagiert, muss die Wurzel prüfen.** Ein Schlüssel, der gesperrt oder
neu verpackt wird, weil sich die Anmeldung geändert hat, an einem Schlüsselbund, der an der
Passphrase hängt, ist ein Mensch, der aus seinen Mails ausgesperrt wird, weil er ein Passwort bekam.

**Eine abgebrochene Anfrage ist keine verlorene Sitzung.** `session.js` löscht seine gespeicherte
Hälfte nur, wenn der Server für den Entsperr-Schlüssel 404 antwortet. Bei jedem Fehlschlag zu
löschen sperrte den Schlüsselbund aller, die weitergeklickt haben, bevor die erste Seite ihn
fertig geöffnet hatte — der Browser bricht die Anfrage dann ab.

**Entschlüsselung mit PKCS#1 v1.5 ist überall dort ein Padding-Orakel, wo sich ihre Fehlschläge
unterscheiden lassen.** In `smime.js` lassen sie sich von außen nicht unterscheiden: Eine Nachricht,
die sich nicht öffnet, sagt nur das, und nur der Person, die sie liest. Das muss so bleiben — kein
Umweg über den Server, keine eigene Fehlermeldung je Padding-Fehler.

**Ein Kontakt-Zertifikat wird nicht erneut gegen die Stellen geprüft.** `verified` ist, was beim
letzten Sehen galt. Eine Stelle, die später aus dem System verschwindet, macht es nicht unbestätigt.

**Eine Krypto-Art, die erst nach dem ersten Flush gesetzt wird, kommt zu spät.** `search_vector`
ist eine erzeugte Spalte, also hat das INSERT mit dem Chiffrat-Body es schon indexiert.
`MessageCryptoDetector::seal()` gehört in die Builder, vor `persist()`.

**Wer `_frame_document.html.twig` ändert, ändert beide Wege, und genau so soll es sein.** Das
Frame-Skript ist über den Hash seines genauen Texts erlaubt; eine zweite Kopie des Dokuments in
JavaScript liefe nicht mehr, sobald sich eine der beiden ändert.

**Nichts darf die Entität einer im Browser gebauten Nachricht neu kodieren.** Eine Signatur deckt
ihre genauen Bytes ab, und nichts auf dem Server könnte sie neu verschlüsseln. Sie bleibt in
`SealedEntityPart`; ein auf anderem Weg gesetzter Body ist eine Nachricht, die der Server neu gebaut
hat.

**Ein versiegelter Entwurf hat keinen Body, also sieht jede Server-Funktion, die Entwürfe liest,
keinen.** Genau darum geht es, und es hält nur, solange nichts einen Body zurückschreibt:
`SealedDrafts::accept()` leert ihn bei jedem Speichern einer versiegelten Zeile, nicht nur bei
denen, die `sealed[draft]` mitbrachten.

**`SealGuard` läuft zweimal, weil ein geplanter Versand Tage warten kann.** Nur beim Eintreffen der
Entität zu prüfen, schickte eine Nachricht, die an einen inzwischen ersetzten oder widerrufenen
Schlüssel verschlüsselt ist.

**Verschlüsseln an Bcc-Empfänger nutzt Platzhalter-Key-IDs.** Ein PKESK pro Empfänger nennt dessen
Schlüssel außen, und dann wüsste jeder, wer die Nachricht noch bekommen hat.

**Eine neue Stelle, die Mailtext an ein Modell gibt, muss `DecryptedMailPolicy::allowsAi()` fragen.**
Sonst hält nichts entschlüsselte Mails von ihr fern: Für jeden anderen Leser ist ein vom Client
gelieferter Body ein Body.

**Ob ein Host lokal ist, entscheidet DNS im Moment der Frage.** Ein Modell-Host, der heute privat und
morgen öffentlich auflöst, ist morgen extern; eine Zustimmung gilt einem Hostnamen, nicht einer
Adresse.

**Ein geänderter Schlüssel, der auf einem anderen Weg als `ContactKeys::accept()` zu `current` wird,
hebelt TOFU aus.** Der partielle Index verhindert zwei `current`-Zeilen, nicht einen Schreibvorgang,
der den bekannten Schlüssel vorher herabstuft. Jeder Schreiber geht über `ContactKeys`.

**Ein Autocrypt-Header wird nur gelesen, wenn `addr=` gleich `From` ist.** Phase 3 las jeden; ein
Header, der jemand anderen nennt, würde jetzt beim ersten Mal einen Schlüssel für diese Person
unterschieben.

**WKD-URLs werden aus einer geprüften Domain gebaut, nie aus der Eingabe.** Ein Such-Endpunkt, der
eine URL nähme, oder eine Domain mit Port oder Pfad darin, wäre eine SSRF-Lücke, egal wie gut der
Client darunter Adressen prüft.

**Ein WKD-Fehlschlag muss eine einzige Antwort bleiben.** Jeder neue Grund für „hier ist kein
Schlüssel“ geht über `WkdDirectory::find()`, das null liefert, und das eine 404 des Controllers —
nicht über eine Exception, eine Weiterleitung oder eine Meldung, die die Gründe unterscheidbar machen
würden.

**gpgs Filter-Eigenschaft `mail` trifft in `keep-uid` nichts** (gpg 2.4). `WkdKeyExport` filtert
stattdessen auf `uid`; zurückgeändert ginge jeder Schlüssel ohne Benutzer-ID hinaus, und die
Rückprüfung würde nichts veröffentlichen.

**`MockHttpClient` folgt keiner Weiterleitung, egal was man ihm sagt**, und
`NoPrivateNetworkHttpClient` folgt ihnen selbst, nie der Client darunter. Ein Test kann nicht zeigen,
dass `max_redirects: 0` wirkt; die Option in `WkdLookup::fetch()` ist der Schutz, und dort steht,
warum.

**Der Prelogin unterscheidet genau eines: ein Konto, das noch nicht umgestellt ist.** Sein
`migrate: true` ist der einzige Unterschied zwischen einer bekannten und einer unbekannten Adresse,
und er hält bis zur nächsten Anmeldung dieses Kontos. Der Limiter `login_prelogin` begrenzt das
Abklappern einer Adressliste; er liegt über `login_ip`, damit jemand, der sich vertippt, zuerst an
die Anmeldegrenze stößt, mit deren Meldung.

**Die Umstellung eines Kontos beendet seine anderen Sitzungen.** Symfony meldet eine Sitzung ab,
wenn sich der gespeicherte Hash ändert, mit dem sie geöffnet wurde, und die Signatur des
Angemeldet-bleiben-Cookies deckt ihn ebenfalls ab. Einmal je Konto, bei der ersten Anmeldung nach
dem Update — das Changelog sagt es.

**`crypto.subtle` gibt es unter reinem `http://` nicht.** `password_kdf.js` weicht dort auf
`@noble/hashes` aus; ohne das könnte sich auf einer per IP erreichten LAN-Installation niemand
anmelden. Der Ausweichweg ist mehrfach langsamer, etwa ein bis zwei Sekunden bei 600 000 Runden.

**`User::$password` direkt zu setzen ist nur fürs Neu-Hashen da.** Ein neues Passwort geht durch
`useServerHashedPassword()` oder `useClientDerivedPassword()`, die das Salt mitführen. Ein Salt, das
auf einer Zeile zurückbleibt, deren Hash vom Passwort selbst stammt, lässt den Browser ein Passwort
strecken, aus dem der Hash nie gemacht wurde, und das Konto weist seine Besitzerin ab.

**Die Anmeldedrosselung zu erweitern deckt das Codeformular nicht ab**, und die Codedrosselung zu
erweitern deckt das Passwortformular nicht ab. Es sind zwei Limiter mit zwei
Schlüsselstrategien für zwei verschiedene Angriffe, und der Docblock jedes einzelnen nennt den
anderen, damit keiner für vollständige Abdeckung gehalten wird.

**`InstallGuard` ist das Einzige zwischen `/install` und einer anonymen Administratorin.** Die
Route ist notwendigerweise `PUBLIC_ACCESS`, und `security.yaml` sagt mit ebenso vielen Worten,
dass du diese Klasse lesen sollst, bevor du die Zeile anfasst.

**`Dom\HTMLDocument::createFromString()` wirft bei einem Flag, das es nicht kennt.** Erlaubt sind
nur `LIBXML_NOERROR`, `LIBXML_COMPACT`, `LIBXML_HTML_NOIMPLIED` und `Dom\HTML_NO_DEFAULT_NS` —
`LIBXML_NOWARNING` daneben zu übergeben, eine Gewohnheit aus `DOMDocument`, ist ein
`ValueError`. `RemoteContentBlocker` scheitert nach innen sicher, das Symptom war also, dass
jede Nachricht als reiner Text erschien: vollkommen privat und vollkommen unlesbar. Ebenso nötig
ist die Kodierungsangabe `'UTF-8'`, denn der sanitisierte Körper hat kein `<meta charset>` —
`MailBodySanitizer` verwirft `<head>`.

**Ein Test, der prüft „hat der Browser die Absenderin kontaktiert“, muss auf den Hostnamen
passen, nicht auf die URL.** Der Proxy trägt die URL der Absenderin in seiner eigenen
Query-Zeichenkette, der naheliegende Playwright-Glob `**://absender.example/**` passt also auf
jeden *korrekt geproxyten* Ladevorgang und meldet ein Leck genau dann, wenn die Funktion
arbeitet. `tests/e2e/rendering-security.spec.ts` nutzt ein Prädikat
`(url) => url.hostname === …` und begründet das.

**Alles, was `bodyHtmlSafe` darstellt, muss über `message_render()` gehen.** Die Twig-Funktion
gibt es, damit keine Vorlage eine Sicherheitsfrage stellen muss — denn das Vergessen scheitert
lautlos: Die Mail wird perfekt dargestellt, und die Zählpixel feuern.
