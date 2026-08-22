# Code Review — Sicherheit & UX

**Projekt:** plMail · **Stand:** `132c1bf` (v0.1.9) · **Datum:** 2026-08-22
**Fokus:** Sicherheit und UX · **Umfang:** 143.503 LOC PHP in `src/`, Templates, Stimulus-Controller, Deployment-Konfiguration

---

## 1. Gesamteindruck

Das ist eine überdurchschnittlich sorgfältig gebaute Codebase. Die sicherheitskritischen Kernentscheidungen sind nicht nur richtig getroffen, sondern im Code auch begründet — der Image-Proxy (`ImageProxyFetcher`) gehört zum Besten, was man an SSRF-Abwehr in einer PHP-Anwendung sieht (DNS-Pinning gegen Rebinding, manuelle Redirect-Behandlung mit Revalidierung pro Hop, Port- und Scheme-Pinning). Die Reading-Frame-Isolierung (`MessageRenderer`) ist ein Lehrbuchbeispiel: opaker Origin, Nonce-CSP, `img-src` auf den eigenen Origin beschränkt, damit Blocker **und** CSP beide versagen müssten, bevor ein Tracking-Pixel feuert.

Genau deshalb fällt der zentrale Fund umso stärker auf: **die sorgfältig gebaute Sandbox wird beim Antworten auf eine Mail umgangen.** Der Lesepfad ist gehärtet, der Antwortpfad nicht — und beide arbeiten mit demselben, vom Angreifer kontrollierten HTML.

**Ergebnis in Zahlen:** 1 kritischer, 2 hohe, 2 mittlere, 5 niedrige Sicherheitsbefunde; 4 kleinere UX-/Accessibility-Befunde. Autorisierung (`OwnershipVoter`), Credential-Verschlüsselung (`Encryptor`), 2FA-Throttling, CSRF und SQL-Parametrisierung wurden geprüft und sind sauber.

### Methodik und Grenzen dieses Reviews

Geprüft wurde statisch (Code, Templates, JS, Deployment-Config) plus die anonym erreichbaren Antworten der Live-Instanz `https://mail.vpn.cpy-pst.de/`.

Zwei Einschränkungen, die für die Bewertung der UX relevant sind:

- **Claude for Chrome stand in dieser Session nicht zur Verfügung.** Es waren keine Browser-Tools geladen, nur `WebFetch`. Der Frontend-Teil beruht daher auf Templates, Stimulus-Controllern und der anonym ausgelieferten Login-Seite — nicht auf tatsächlicher Bedienung.
- **Kein Login möglich** (keine Zugangsdaten). Alles hinter der Anmeldung — Inbox, Compose, Kalender, Admin — ist ausschließlich aus dem Code beurteilt. Aussagen zu Laufzeitverhalten, Ladezeiten und tatsächlicher Tastaturbedienung fehlen deshalb bewusst.

Die Sicherheitsbefunde sind davon nicht betroffen: alle sind am Code vollständig nachvollzogen und mit Datei- und Zeilenangaben belegt.

---

## 1a. Bearbeitungsstand

Gepflegt beim Abarbeiten — eine Zeile pro Befund, mit dem Commit, der ihn
schließt. Ein Befund gilt erst als erledigt, wenn ein Test ihn festhält; „behoben"
ohne Test heißt hier „behoben, bis es jemand versehentlich zurückdreht".

| ID | Stand | Commit | Anmerkung |
|---|---|---|---|
| **S-01** | ✅ behoben | `016d9f3` | Alle drei empfohlenen Stellen, plus ein Sanitizer-Profil fürs Verfassen, das der Review nicht vorgesehen hatte — siehe unten. |
| **S-02** | ⬜ offen | | |
| **S-03** | ⬜ offen | | |
| **S-04** | ⬜ offen | | |
| **S-05** | ⬜ offen | | |
| **S-06** | ⬜ offen | | |
| **S-07** | ⬜ offen | | |
| **S-08** | ⬜ offen | | |
| **S-09** | ⬜ offen | | |
| **S-10** | ⬜ offen | | |
| **U-01** | ⬜ offen | | |
| **U-02** | ⬜ offen | | Sicherheitshälfte ist mit S-01 Punkt 2 erledigt; offen ist die UX-Hälfte (Paste-Normalisierung). |
| **U-03** | ⬜ offen | | |
| **U-04** | ⬜ offen | | |

### Nachtrag zu S-01 — zwei Fallen, die die empfohlene Behebung so nicht überlebt hätte

Die Analyse des Reviews stimmt vollständig; die vorgeschlagene Korrektur hätte
in Punkt 2 zwei Dinge kaputt gemacht, die beide von vorhandenen Tests gehalten
wurden.

**Die Allow-Liste verwirft jedes `data-`-Attribut.** Das ist für fremdes HTML
richtig und für einen selbst verfassten Text zerstörerisch: `data-quoted` ist,
woran das Zitat gefunden wird — zum Einklappen, und um es aus dem Snippet zu
schneiden —, `data-pl-signature` ist, was ein Absenderwechsel ersetzt, und
`data-cid` ist die gesamte Brücke zwischen einem Inline-Bild im Editor und der
`cid:`-Referenz, die auf die Leitung geht. `sanitizeFragment()` auf `bodyHtml`
in `markAsDraft()` hätte den Body sicher und den Composer kaputt hinterlassen.
Deshalb ein eigenes Profil (`sanitizeComposedBody()`) mit genau diesen fünf
Markern — geschlossene Liste, nicht `data-*` pauschal.

**`HtmlSanitizerConfig` ist unveränderlich.** Jede `allow*`-Methode liefert
einen Klon. Ein `$config->allowAttribute(...)` als Anweisung geschrieben
konfiguriert eine Kopie, die anschließend verfällt — es sieht aus wie eine
funktionierende Allow-Liste und entfernt alles, was sie erlauben sollte.

**Und der Sanitizer maskiert `@` zu `&#64;`.** In einem Attributwert ist das
korrektes HTML und im Browser folgenlos. Für eine `cid:`-Referenz ist es fatal:
`Symfony\Component\Mime\Email::prepareParts()` verbindet einen eingebetteten
Teil mit dem Body über einen Vergleich der *Zeichenkette* `cid:<name>`, und
`InlineAttachmentDetector` entscheidet beim Ingest genauso. Keiner von beiden
parst HTML. Ohne Gegenmaßnahme wäre jede gesendete Mail mit Inline-Bild mit
einem kaputten Bild rausgegangen — der Befund wäre geschlossen und ein
Sendefehler eingebaut gewesen. `restoreCidReferences()` stellt diese Referenzen
wieder her, und nur dort, wo die dekodierte Fassung noch wie eine Referenz
aussieht: einen Attributwert allgemein zu dekodieren wäre ein Weg aus ihm
heraus.

---

## 2. Befundübersicht

| ID | Schwere | Titel | Ort |
|---|---|---|---|
| **S-01** | 🔴 **Kritisch** | Stored XSS: Antworten/Weiterleiten zitiert **unsanitisiertes** `bodyHtml` in den App-Origin | `ReplyDraftBuilder.php:213` |
| **S-02** | 🟠 **Hoch** | Stored XSS: SVG-Anhang wird inline ausgeliefert, per `cid:`-Link aus der Mail erreichbar | `AttachmentController.php:68` |
| **S-03** | 🟠 **Hoch** | Keine CSP auf dem Anwendungsdokument — Verstärker für S-01/S-02 | global |
| **S-04** | 🟡 **Mittel** | File-Picker-Preview liefert fremden MIME-Typ ohne `Content-Disposition` inline aus | `FilePickerController.php:134` |
| **S-05** | 🟡 **Mittel** | Anwendung liefert keinerlei Security-Header aus; Schutz hängt am Proxy des Betreibers | `frankenphp/Caddyfile` |
| **S-06** | 🟢 Niedrig | Mercure-Default `subscribe: '*'` als latente Fehlerquelle | `config/packages/mercure.yaml:9` |
| **S-07** | 🟢 Niedrig | Signierte Image-Proxy-URLs laufen nie ab | `ImageProxySigner.php:43` |
| **S-08** | 🟢 Niedrig | Gleiches Inline-Muster wie S-02 im JMAP-Download (latent) | `JmapDownloadController.php:82` |
| **S-09** | 🟢 Niedrig | `/install` — Trust-on-first-use-Fenster beim Erststart | `InstallGuard.php:27` |
| **S-10** | ℹ️ Info | Kein Rate-Limit auf der JMAP-Authentifizierung | `security.yaml` (Firewall `jmap`) |
| **U-01** | 🟡 UX | 4 Accessibility-Lücken (Icon-Buttons ohne Namen, `<img>` ohne `alt`) | Templates |
| **U-02** | 🟢 UX | Eingefügtes HTML im Composer wird nicht bereinigt — geht so auf die Leitung | `compose_controller.js:3077` |
| **U-03** | 🟢 UX | 9 unübersetzte deutsche Strings | `translations/messages.de.yaml` |
| **U-04** | ℹ️ UX | `en_PI` fehlen 218 Keys (Fallback auf Englisch) | `translations/messages.en_PI.yaml` |

---

## 3. Sicherheitsbefunde im Detail

### 🔴 S-01 — Stored XSS über zitierten Mail-Inhalt beim Antworten

**Ort:** [`src/Service/Mail/ReplyDraftBuilder.php:213`](src/Service/Mail/ReplyDraftBuilder.php:213), ausgegeben in [`templates/compose/_window.html.twig:487`](templates/compose/_window.html.twig:487)

#### Das Problem

`Message` trägt zwei Body-Felder, und der Unterschied ist die gesamte Sicherheitsgrenze der Anwendung:

- `bodyHtml` — das **rohe** HTML des Absenders, unverändert wie empfangen
- `bodyHtmlSafe` — die von `MailBodySanitizer` bereinigte Fassung

Der Lesepfad benutzt korrekt `bodyHtmlSafe` und rendert es zusätzlich in einem sandboxed iframe. `ReplyDraftBuilder::quote()` greift jedoch auf das rohe Feld zu:

```php
// src/Service/Mail/ReplyDraftBuilder.php:213
$html = trim($original->bodyHtml ?? '');   // ← roh, nicht bodyHtmlSafe
$text = trim($original->bodyText ?? '');
$body = '' !== $html ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
```

`$body` wird unverändert in den Entwurf eingebettet (`$draft->bodyHtml = $lead . $this->quote(...)`, Zeile 142). Das Compose-Fenster gibt dieses Feld dann per `|raw` in ein `contenteditable`-Div aus — **im Hauptdokument der Anwendung**, nicht im iframe:

```twig
{# templates/compose/_window.html.twig:487 #}
empty:before:pointer-events-none">{{ form.bodyHtml.vars.value|raw ?: '' }}</div>
```

`DraftPersister::markAsDraft()` ruft zwar `$this->bodySanitizer->sanitize($message)` auf ([`DraftPersister.php:154`](src/Service/Mail/DraftPersister.php:154)) — aber `MailBodySanitizer::sanitize()` schreibt sein Ergebnis nach `bodyHtmlSafe` und lässt `bodyHtml` unangetastet ([`MailBodySanitizer.php:72`](src/Service/Mail/MailBodySanitizer.php:72)). Der Sanitizer läuft also, rettet aber genau das Feld nicht, das ausgegeben wird.

#### Angriffskette

1. Angreifer sendet eine Mail mit `<img src=x onerror="…">` im HTML-Body an eine plMail-Adresse.
2. Ingest speichert das rohe HTML in `bodyHtml` (`MessageSyncer.php:487`, `GmailMessageBuilder.php:159`, `GraphMessageBuilder.php:194`), die bereinigte Fassung in `bodyHtmlSafe`.
3. Das Opfer **liest** die Mail — sicher, die Sandbox greift.
4. Das Opfer klickt **Antworten** (`GET /compose/reply/{id}`, [`ComposeController.php:136`](src/Controller/Mail/ComposeController.php:136)).
5. Das rohe HTML landet über `quote()` im Entwurf und wird per `|raw` ins Hauptdokument geschrieben.
6. `onerror` feuert im Origin der Anwendung — mit Session, ohne CSP (siehe S-03).

Reply, Reply-All und Forward sind alle betroffen (`ComposeController.php:136`, `:152`, `:168`).

#### Auswirkung

Vollständige Übernahme der Sitzung im Kontext der Anwendung. Konkret erreichbar: sämtliche Mail lesen und exfiltrieren, ein **App-Passwort erzeugen** (`ApiToken`) und damit dauerhaften JMAP-Zugriff etablieren, der ein Passwort-Reset überlebt, sowie eine Weiterleitungsregel anlegen. Der Angriff braucht keinen zweiten Klick über „Antworten" hinaus — eine Handlung, die bei einer Mail, die eine Antwort provoziert, der Normalfall ist.

Das `HttpOnly`-Flag auf `PHPSESSID` verhindert das Auslesen des Cookies, nicht aber Aktionen im Namen des Nutzers.

#### Behebung

Drei Stellen, gestaffelt — die erste behebt den Befund, die anderen beiden verhindern die Wiederkehr:

**1. Zitat aus der bereinigten Fassung bauen** (`ReplyDraftBuilder`):

```php
public function __construct(
    private readonly SignatureProvider  $signatures,
    private readonly MailBodySanitizer  $sanitizer,   // neu
) {}

// in quote():
$html = $this->sanitizer->sanitizeFragment($original->bodyHtml);
```

`sanitizeFragment()` existiert bereits und wird für Signaturen genau zu diesem Zweck verwendet ([`MailBodySanitizer.php:95`](src/Service/Mail/MailBodySanitizer.php:95)). `bodyHtmlSafe` direkt zu nehmen wäre die Alternative, ist aber schlechter: dort sind `cid:`-Referenzen bereits in App-URLs umgeschrieben, was den Umweg über `InlineImageRewriter::toCid()` beim Speichern nötig macht.

**2. `bodyHtml` beim Persistieren bereinigen** (`DraftPersister::markAsDraft()`) — deckt zusätzlich U-02 ab, also alles, was über Paste oder einen manipulierten POST in den Composer gelangt, und stellt sicher, dass die **versendete** Mail kein Script trägt:

```php
$message->bodyHtml = $this->bodySanitizer->sanitizeFragment($message->bodyHtml);
$this->bodySanitizer->sanitize($message);
```

**3. Ausgabe absichern.** Das `|raw` in `_window.html.twig:487` sollte nicht auf einem Feld stehen, dessen Sicherheit von einem entfernten Aufrufer abhängt. Sauber wäre eine Twig-Funktion analog zu `message_render()` — der Kommentar in `MessageRender` beschreibt genau dieses Prinzip („Exists so that no Twig file has to ask a security question") und sollte auch hier gelten.

**Zusätzlich:** `JmapDraftWriter` baut Entwürfe für Nicht-Browser-Clients und ist laut eigenem Docblock eine bereits abgedriftete Kopie dieser Logik — bei der Korrektur mitprüfen.

---

### 🟠 S-02 — Stored XSS über SVG-Anhang

**Ort:** [`src/Controller/Mail/AttachmentController.php:68`](src/Controller/Mail/AttachmentController.php:68)

#### Das Problem

```php
$inlineAllowed = true === str_starts_with($contentType, 'image/');
```

`image/svg+xml` erfüllt dieses Prädikat. SVG ist aber kein passives Bildformat: als Top-Level-Dokument geladen führt es `<script>` aus. `X-Content-Type-Options: nosniff` hilft hier nicht — der deklarierte Typ *ist* `image/svg+xml`, es wird nichts fehlinterpretiert. `$contentType` stammt aus dem MIME-Header der eingehenden Mail und ist vollständig angreiferkontrolliert.

#### Warum das erreichbar ist

Die Templates verlinken Anhänge durchweg mit `?download=1` ([`_attachment_chip.html.twig:53`](templates/mail/_attachment_chip.html.twig:53)), was `DISPOSITION_ATTACHMENT` erzwingt. Der Inline-Pfad ist über die normale Oberfläche also nicht erreichbar — er ist es über die Mail selbst:

`MailBodySanitizer::resolveCids()` ersetzt **jedes** Vorkommen von `cid:` im gesamten Dokument, nicht nur in `img src`:

```php
// src/Service/Mail/MailBodySanitizer.php:131
return (string)preg_replace_callback('/cid:([^"\'\)\s>]+)/i', /* … */, $html);
```

Der Sanitizer erlaubt anschließend `<a href>` mit relativen Links (`allowRelativeLinks()`, Zeile 200) und setzt `target="_blank"`. Damit gilt:

1. Angreifer hängt ein SVG mit `<script>` an, Content-ID `logo`.
2. Im Body steht `<a href="cid:logo">Rechnung ansehen</a>`.
3. `resolveCids()` schreibt das in `<a href="/mail/attachment/4711" target="_blank">` um — der Angreifer muss die Part-ID nicht kennen, die Anwendung setzt sie selbst ein.
4. Klick öffnet einen neuen Tab, **ohne** `?download=1` → `DISPOSITION_INLINE`, `Content-Type: image/svg+xml`.
5. Das SVG läuft als Dokument im App-Origin.

Die Auswirkung entspricht S-01; der Unterschied ist ein Klick auf einen Link, dessen Text der Angreifer frei wählt.

#### Behebung

Eine Positivliste statt eines Präfix-Tests — und zusätzlich eine CSP auf der Antwort selbst, wie es `ImageProxyController.php:84` bereits vorbildlich macht:

```php
private const array INLINE_TYPES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
];

$inlineAllowed = in_array(strtolower(trim(explode(';', $contentType)[0])), self::INLINE_TYPES, true);

$response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
```

`image/svg+xml` fällt damit auf Download zurück, und die CSP entwertet den Pfad selbst dann, wenn die Liste künftig einmal zu großzügig wird. Das Abschneiden der MIME-Parameter (`; charset=…`) ist nötig, weil `image/svg+xml; charset=utf-8` sonst an einem exakten Vergleich vorbeiläuft.

---

### 🟠 S-03 — Keine Content-Security-Policy auf dem Anwendungsdokument

**Ort:** anwendungsweit — verifiziert an der Live-Instanz

```
$ curl -sS -D - -o /dev/null https://mail.vpn.cpy-pst.de/login
HTTP/1.1 200 OK
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
Set-Cookie: PHPSESSID=…; path=/; secure; httponly; samesite=lax
```

Kein `Content-Security-Policy`-Header. Der Reading-Frame trägt eine sorgfältig konstruierte CSP, der Image-Proxy ebenfalls — das Hauptdokument, in dem Composer, Einstellungen und Admin laufen, trägt keine.

Das ist der Grund, warum S-01 und S-02 von „XSS in einem `contenteditable`" zu „vollständige Sitzungsübernahme" eskalieren. Eine CSP mit `script-src` auf Nonce-Basis hätte beide Befunde zu Fehlfunktionen statt zu Sicherheitslücken gemacht.

#### Behebung

Ein `ResponseListener`, der eine Nonce-basierte CSP auf HTML-Antworten setzt. Die Anwendung nutzt AssetMapper/Importmap mit Stimulus, also gibt es Inline-Scripts (u. a. die Importmap selbst) — der Rollout braucht deshalb eine Nonce, die in `<script>`-Tags durchgereicht wird, und sollte über `Content-Security-Policy-Report-Only` eingefahren werden, bevor er scharf geschaltet wird.

Realistisches Ziel:

```
default-src 'self';
script-src 'self' 'nonce-{nonce}';
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
frame-src 'self';
object-src 'none';
base-uri 'none';
form-action 'self';
frame-ancestors 'self';
```

`style-src 'unsafe-inline'` ist wegen Tailwind und der Theme-Variablen vorerst schwer vermeidbar und ist nicht der Teil, der hier trägt — `script-src` ist es.

---

### 🟡 S-04 — File-Picker-Preview liefert fremde Inhalte inline aus

**Ort:** [`src/Controller/Integration/FilePickerController.php:134`](src/Controller/Integration/FilePickerController.php:134)

```php
[$body, $mime] = null === $preview
    ? [$this->placeholderSvg($fileId), 'image/svg+xml']
    : [$preview->contents, $preview->mime];

$response = new Response($body, Response::HTTP_OK, [
    'Content-Type'           => $mime,
    'X-Content-Type-Options' => 'nosniff',
]);
```

Hier fehlt jede `Content-Disposition` — die Antwort ist damit implizit inline — und `$mime` kommt vom angebundenen Fremdsystem (Nextcloud, Immich, Dropbox, OneDrive, Google Drive). Liefert eines davon `text/html` oder `image/svg+xml` zurück, rendert es im App-Origin.

Das ist deutlich schwerer auszunutzen als S-01/S-02: es braucht eine Datei, die der Angreifer in einen für das Opfer sichtbaren Ordner legen kann (geteilter Nextcloud-Ordner, geteiltes Dropbox-Verzeichnis) — ein realistisches Szenario in genau den Umgebungen, für die diese Integrationen gedacht sind.

Der selbst erzeugte Platzhalter-SVG ist unbedenklich, weil er serverseitig gebaut wird.

**Behebung:** dieselbe Positivliste wie in S-02, plus `Content-Security-Policy: default-src 'none'; sandbox` auf der Antwort. Ein Preview ist immer ein Bild; alles andere sollte gar nicht erst ausgeliefert werden.

---

### 🟡 S-05 — Die Anwendung liefert keine Security-Header aus

Eine Suche über das gesamte Repository findet `X-Frame-Options`, `Strict-Transport-Security`, `Referrer-Policy` und `Permissions-Policy` an **keiner** Stelle — weder in `frankenphp/Caddyfile`, noch in `config/`, noch in einem Event-Listener. Die Header, die die Live-Instanz zeigt, stammen aus dem Reverse-Proxy des Betreibers.

Das ist relevant, weil das README als Installationsweg `docker compose up -d` und „Open https://localhost" nennt. Eine so aufgesetzte Instanz läuft ohne `X-Frame-Options` (Clickjacking), ohne HSTS und ohne `Referrer-Policy` — und der Betreiber hat keinen Anhaltspunkt, dass er das selbst nachrüsten muss.

**Behebung:** ein `header`-Block in `frankenphp/Caddyfile`, damit das mitgelieferte Deployment von sich aus sicher ist:

```caddyfile
header {
    Strict-Transport-Security "max-age=31536000; includeSubDomains"
    X-Frame-Options "SAMEORIGIN"
    X-Content-Type-Options "nosniff"
    Referrer-Policy "strict-origin-when-cross-origin"
    Permissions-Policy "geolocation=(), microphone=(), camera=(), interest-cohort=()"
    -Server
}
```

`-Server` entfernt nebenbei die Versionskennung `FrankenPHP Caddy`.

---

### 🟢 S-06 — Mercure-Default `subscribe: '*'`

[`config/packages/mercure.yaml:9`](config/packages/mercure.yaml:9) setzt für das vom Bundle erzeugte JWT `publish: '*'` und `subscribe: '*'`.

**Aktuell nicht ausnutzbar:** beide Stellen, die ein Subscriber-Cookie ausstellen, übergeben explizite Topics — `MercureCookieSubscriber.php:72` und `MercureAuthController.php:42` verwenden je `['mail/user/'.$user->id]`. Die Topic-Isolierung greift also.

Der Default ist trotzdem die falsche Vorgabe: ein künftiger Aufruf von `createCookie($request)` ohne Topic-Argument würde stillschweigend ein Abonnement auf **alle** Topics ausstellen, also die Mail-Ereignisse aller Nutzer. Das ist ein Fehler, der beim Schreiben unauffällig aussieht und beim Review leicht durchrutscht.

**Behebung:** `subscribe: []` als Default. Die Anwendung publiziert nur, sie abonniert serverseitig nicht — der Publish-Anspruch bleibt, der Subscribe-Anspruch entfällt und muss dann pro Cookie explizit gesetzt werden, was ohnehin bereits geschieht.

---

### 🟢 S-07 — Signierte Image-Proxy-URLs sind unbegrenzt gültig

[`src/Service/Mail/ImageProxySigner.php:43`](src/Service/Mail/ImageProxySigner.php:43) signiert nur die URL, ohne Ablaufzeitpunkt:

```php
return substr(hash_hmac('sha256', $url, $this->secret), 0, self::SIGNATURE_LENGTH);
```

Die Route ist `PUBLIC_ACCESS`. Eine einmal geleakte signierte URL (Browser-History, Logs, Referrer eines Dritten) erlaubt dauerhaft, den Server diese eine URL abrufen zu lassen.

Die Auswirkung ist gering, weil die Zieladresse in der Signatur festgeschrieben ist und `ImageProxyFetcher` bei jedem Abruf sämtliche SSRF-Prüfungen erneut durchführt — es ist kein offener Proxy, sondern ein dauerhaft gültiger Abruf einer festen, öffentlichen HTTPS-Ressource. 128 Bit Signaturlänge sind ausreichend.

**Optional:** einen `exp`-Timestamp in die Signatur aufnehmen und mitprüfen. Angesichts der Restriktionen des Fetchers vertretbar, es so zu lassen — dann aber als bewusste Entscheidung dokumentieren, wie es im Rest der Codebase üblich ist.

---

### 🟢 S-08 — Gleiches Inline-Muster im JMAP-Download

[`src/Jmap/Controller/JmapDownloadController.php:82`](src/Jmap/Controller/JmapDownloadController.php:82) verwendet denselben Präfix-Test wie S-02:

```php
$inlineAllowed = true === str_starts_with($blob->contentType, 'image/');
```

Praktisch kaum ausnutzbar: die `jmap`-Firewall ist `stateless` und verlangt einen `Authorization`-Header, den eine Top-Level-Navigation im Browser nicht mitsendet — ein direkter Aufruf endet bei 401. Der Befund ist als Konsistenzproblem geführt: dieselbe Regel sollte an beiden Stellen gelten, sonst hängt die Sicherheit an einer Firewall-Eigenschaft, die anderswo dokumentiert ist. Bei der Korrektur von S-02 mitziehen.

Anzumerken: der Kommentar direkt darüber (Zeile 74) behandelt den verwandten Fall — `accept` wird bewusst ignoriert, damit ein Aufrufer HTML nicht als Bild deklarieren kann. Die Überlegung ist also da; sie greift nur eine Ebene zu kurz, weil der *gespeicherte* Typ ebenso wenig vertrauenswürdig ist.

---

### 🟢 S-09 — Trust-on-first-use beim Erststart

[`src/Service/Setup/InstallGuard.php:27`](src/Service/Setup/InstallGuard.php:27) gibt `/install` frei, solange kein Nutzer existiert. Wer eine frische Instanz vor dem Betreiber erreicht, wird deren Administrator.

Das ist für selbst gehostete Software ein verbreitetes und weitgehend akzeptiertes Muster, und der 404-statt-Redirect-Ansatz ist richtig gelöst. Erwähnenswert bleibt es, weil das README empfiehlt, hinter einen Reverse-Proxy zu gehen und Port `30080` (TrueNAS) zu exponieren — zwischen Container-Start und abgeschlossener Einrichtung existiert ein reales Fenster.

**Optional:** ein Einmal-Token aus `var/secrets/generated.env`, das die Setup-Seite abfragt, oder eine Bindung des Fensters an die ersten N Minuten Uptime. Für den Anwendungsfall vermutlich unverhältnismäßig — aber im Handbuch sollte stehen, dass die Ersteinrichtung ohne Verzögerung erfolgen sollte.

---

### ℹ️ S-10 — Kein Rate-Limit auf der JMAP-Authentifizierung

Die `main`-Firewall hat `login_throttling`, das 2FA-Formular hat einen eigenen Limiter (`TwoFactorThrottle`, sehr sauber gelöst), die `jmap`-Firewall hat keinen.

Ein Brute-Force auf App-Passwörter ist chancenlos — 32 Byte CSPRNG, als SHA-256 gespeichert und per indiziertem Gleichheitsvergleich nachgeschlagen (`ApiToken::create()`, `ApiTokenRepository::findActiveBySecret()`). Die Begründung für SHA-256 statt eines Password-Hashers steht im Docblock und ist korrekt.

Was fehlt, ist ein Schutz gegen Ressourcenverbrauch: jeder unauthentifizierte Request löst eine Datenbankabfrage aus. Ein Limiter pro IP auf fehlgeschlagene JMAP-Authentifizierungen wäre eine günstige Ergänzung, kein dringender Befund.

---

## 4. Was geprüft wurde und sauber ist

Damit die Befundliste im Verhältnis gelesen wird — folgende Bereiche wurden gezielt untersucht, ohne Beanstandung:

**Autorisierung.** `OwnershipVoter` behandelt alle zehn nutzergebundenen Entitäten über eine einzige Regel und schlägt konsequent fehl statt durchzulassen: `null`-Owner wird abgelehnt statt verglichen, nicht authentifizierte Tokens ebenso, und der Vergleich ist Identität statt ID-Gleichheit (was bei unpersistierten Entitäten mit beidseitig `null`-ID sonst durchginge). Die Begründung, warum die Message über `$account` und nicht über den Mailbox-Thread-Pfad erreicht wird, ist stichhaltig. Alle Controller-Routen, die eine Entität laden, rufen `denyAccessUnlessGranted` auf.

**Credential-Verschlüsselung.** `Encryptor` nutzt libsodium secretbox (XSalsa20-Poly1305) mit frischer Nonce pro Verschlüsselung, versionsbehaftetem Präfix und `#[SensitiveParameter]` an den richtigen Stellen. Die Verlagerung der Schlüsselvalidierung aus dem Konstruktor in `key()` ist begründet und schwächt nichts ab.

**SQL-Injection.** `EmailFilterCompiler` erzeugt ausschließlich generierte Parameternamen (`'f'.$index`) und bindet alle Werte; `FreeTextCompiler` reduziert Lexeme auf für den tsquery-Parser bedeutungslose Zeichen und übergibt das Ergebnis trotzdem gebunden; `likePattern()` escaped `\`, `%` und `_`. Die `sprintf`-Aufrufe in den Repositories interpolieren nur Spaltennamen und Parameternamen, keine Werte.

**CSRF.** Stateless Tokens sind durchgehend konfiguriert. Die Begründung für die Aufnahme von `compose` in `stateless_token_ids` (`config/packages/csrf.yaml`) ist die beste Dokumentation eines Session-Race-Conditions, die ich in einer Konfigurationsdatei gesehen habe. Admin-POSTs prüfen explizit (`SystemActionController.php:77`). Die CSRF-Freiheit der öffentlichen Booking-POST ist begründet und durch einen IP-basierten Fixed-Window-Limiter kompensiert.

**Webhooks.** Alle vier Endpunkte vergleichen ihr Geheimnis mit `hash_equals` — Gmail Pub/Sub, Graph Mail, Graph Calendar, Google Calendar. Pro Subscription gemintete 256-Bit-`clientState`/`pushSecret`.

**SSRF.** `ImageProxyFetcher` und `IcsFeedClient` implementieren beide: HTTPS-only, Port-Pinning, Auflösung und Prüfung aller Adressen gegen private/reservierte Bereiche, Verbindungs-Pinning per `resolve` gegen DNS-Rebinding, manuelle Redirect-Verfolgung mit Revalidierung jedes Hops, Größen- und Zeitlimits. Fehlerursachen gehen ins Log, nie in die Antwort.

**Path Traversal.** `AppearanceController::showBackground()` prüft dreifach ab: Route-Requirement per Regex, Abgleich gegen den auf dem Nutzer gespeicherten Dateinamen, `is_file()`. Dateinamen werden serverseitig als UUIDv7 vergeben. Über JMAP ist `backgroundFile` ausdrücklich nicht setzbar (`AppearanceSetMethod.php:182`).

**Session/Cookies.** Live verifiziert: `secure; httponly; samesite=lax`. `trusted_proxies` ist über Environment konfigurierbar.

**Frontend-DOM.** Der einzige Ort, an dem Stimulus-Controller Markup aus Daten statt aus Server-Antworten bauen, ist `search_controller.js` — und dort läuft jeder eingesetzte Wert durch `_escape()` (Zeilen 666, 704, 705). Auch die Kontakt-Labels, die aus Absendernamen stammen und damit angreiferkontrolliert sind.

**Modal-Accessibility.** `modal_controller.js` behandelt `inert`, Fokus-Rückgabe an das auslösende Element, Escape-Handling und die Race Condition zwischen Fade-out und einem gleichzeitig rendernden Turbo-Frame. Das ist deutlich gründlicher als üblich.

**Reading-Frame-Isolierung.** Bereits eingangs gewürdigt; ergänzend: dass Warnbanner und die „Bilder anzeigen"-Leiste bewusst **außerhalb** des Frames liegen, damit eine Mail sie nicht fälschen kann, und dass die Link-Vorschau im Frame *erkannt*, aber vom Parent *gezeichnet* wird — das sind die Details, an denen man sieht, dass die Trust-Grenze verstanden und nicht nur gesetzt wurde.

---

## 5. UX-Befunde

> Wie oben angemerkt: ohne Login und ohne Browser-Tooling beruht dieser Abschnitt auf Templates und Controllern. Er erhebt keinen Anspruch, eine Bedienungsprüfung zu ersetzen.

### 🟡 U-01 — Vier Accessibility-Lücken

Ein Scan aller 200+ Twig-Templates auf fehlende Accessible Names ergab nur vier Treffer — das ist ein sehr gutes Ergebnis, und alle vier sind schnell behoben:

| Ort | Problem |
|---|---|
| [`compose/_window.html.twig:262`](templates/compose/_window.html.twig:262) | Schließen-Button hat nur `title`, kein `aria-label`; Inhalt ist ein `<i>`-Icon |
| [`_partials/_avatar_picker.html.twig:60`](templates/_partials/_avatar_picker.html.twig:60) | Submit-Button mit nur einem Bild als Inhalt, Name nur über `title` |
| [`_partials/_thread_row.html.twig:77`](templates/_partials/_thread_row.html.twig:77) | `<img>` ohne `alt` |
| [`mail/_attachment_chip.html.twig:17`](templates/mail/_attachment_chip.html.twig:17) | Anhang-Thumbnail `<img>` ohne `alt` |

`title` ist kein verlässlicher Ersatz für einen Accessible Name — Screenreader behandeln es uneinheitlich, und auf Touch-Geräten ist es gar nicht erreichbar. Der Schließen-Button des Composers ist davon der wichtigste: das Fenster hat unterhalb `md` laut Kommentar einen alternativen Ausgang, oberhalb ist dieser Button der einzige.

Für die beiden Bilder genügt `alt=""`, sofern sie rein dekorativ neben einem Textlabel stehen — beim Anhang-Thumbnail ist das der Fall, beim Thread-Row-Avatar vermutlich auch. Explizit leer ist richtig; ganz fehlend lässt Screenreader den Dateinamen vorlesen.

### 🟢 U-02 — Eingefügtes HTML im Composer wird nicht bereinigt

[`compose_controller.js:3077`](assets/controllers/compose/compose_controller.js:3077) behandelt beim Paste ausschließlich Bilder (`event.clipboardData?.files`). HTML aus der Zwischenablage geht ungefiltert ins `contenteditable`.

Das hat zwei Seiten:

- **UX:** Eingefügter Text aus Word oder einer Webseite bringt dessen komplettes Markup mit — Schriftgrößen, Hintergrundfarben, verschachtelte Tabellen. Das ist der klassische „warum sieht meine Mail so aus"-Effekt, und Empfänger sehen ihn in voller Schönheit.
- **Sicherheit:** Das eingefügte Markup landet in `bodyHtml`, wird nicht bereinigt (siehe S-01, Punkt 2) und geht so **auf die Leitung**. Für den Nutzer selbst ist das Self-XSS und damit unkritisch; dass plMail aber unsanitisiertes HTML versendet, ist unabhängig davon unschön.

**Behebung:** ein `paste`-Handler, der `text/html` abfängt und durch dieselbe Allow-Liste schickt wie serverseitig — oder, pragmatischer und in vielen Clients so gelöst, auf `text/plain` normalisiert und Rich-Paste über eine explizite Aktion („Mit Formatierung einfügen") anbietet. Der Server-seitige Teil der Korrektur aus S-01 (`sanitizeFragment` in `DraftPersister`) deckt das Sicherheitsproblem in jedem Fall ab.

### 🟢 U-03 — Neun unübersetzte deutsche Strings

Deutsch ist mit 2390 von 2391 Keys praktisch vollständig — bemerkenswert für ein Projekt dieser Größe, und es fehlt kein einziger Key. Neun Werte sind allerdings identisch mit dem englischen Original:

```
admin.push.fcm.heading
admin.push.fcm.field.google_services
admin.push.fcm.application_id
admin.users.field.is_admin
calendar.share.window_label
compose.schedule.when
settings.accounts.type.oauth
settings.appearance.preview.sender3
settings.filters.summary.in_account
```

Einige sind legitim identisch (`oauth`, vermutlich `sender3` als Beispieldatensatz). `admin.users.field.is_admin`, `calendar.share.window_label`, `compose.schedule.when` und `settings.filters.summary.in_account` sind in der deutschen Oberfläche jedoch sichtbare englische Strings.

### ℹ️ U-04 — `en_PI` unvollständig

Der Pirate-Locale fehlen 218 Keys (2173 von 2391). Der Fallback auf Englisch funktioniert, das Ergebnis ist ein Sprachmix. Bei einem Novelty-Locale vermutlich Absicht und kein Handlungsbedarf — hier nur der Vollständigkeit halber vermerkt.

---

## 6. Empfohlene Reihenfolge

**Sofort — die Kette schließen:**

1. **S-01** — `ReplyDraftBuilder::quote()` auf `sanitizeFragment()` umstellen. Einzeiler plus Konstruktor-Injection, behebt den kritischen Befund.
2. **S-01, Teil 2** — `DraftPersister::markAsDraft()` bereinigt zusätzlich `bodyHtml`. Schließt Paste, manipulierte POSTs und ausgehende Mail mit ab (deckt die Sicherheitshälfte von U-02).
3. **S-02** — Positivliste statt Präfix-Test in `AttachmentController`, plus `Content-Security-Policy: default-src 'none'; sandbox` auf der Antwort. **S-08** und **S-04** in derselben Änderung mitziehen.

**Kurzfristig — Verteidigung in der Tiefe:**

4. **S-03** — CSP für das Anwendungsdokument, eingeführt über `Report-Only`. Das ist die Maßnahme, die die nächste Lücke dieser Art zu einer Fehlfunktion statt zu einem Vorfall macht.
5. **S-05** — Header-Block in den `Caddyfile`, damit das mitgelieferte Deployment nicht auf den Proxy des Betreibers angewiesen ist.
6. **U-01** — vier `aria-label`/`alt`-Attribute.

**Wenn Zeit ist:**

7. **S-06** — `subscribe: []` als Mercure-Default.
8. **U-02** — Paste-Normalisierung im Composer (UX-Hälfte).
9. **U-03** — neun deutsche Strings.
10. **S-07**, **S-09**, **S-10** — bewusst entscheiden und dokumentieren; jeweils vertretbar, so zu bleiben.

---

## 7. Schlussbemerkung

Die Sicherheitsarchitektur dieser Anwendung ist durchdacht, und das ist keine Höflichkeitsfloskel: SSRF-Abwehr, Reading-Frame-Isolierung, Ownership-Autorisierung, 2FA-Throttling und Token-Handling sind jeweils auf einem Niveau, das man in Projekten dieser Größe selten findet. Die Kommentare erklären durchgehend nicht nur *was*, sondern *warum* — inklusive der verworfenen Alternativen. Das ist der Grund, warum dieses Review so präzise werden konnte.

Der kritische Befund ist deshalb auch kein Versäumnis im Verständnis, sondern eine Lücke in der Abdeckung: `bodyHtml` und `bodyHtmlSafe` sind eine korrekte und klar dokumentierte Trennung, die im Lesepfad konsequent eingehalten wird. `ReplyDraftBuilder` greift auf das falsche der beiden Felder zu — an einer Stelle, die zwei Ebenen von der Sandbox entfernt liegt und deshalb nicht mehr wie eine Sicherheitsentscheidung aussah.

Genau dagegen hilft das Prinzip, das `MessageRender` bereits formuliert: *„Exists so that no Twig file has to ask a security question."* Das gilt für Services genauso. Solange `Message::bodyHtml` öffentlich lesbar ist und irgendwo ein `|raw` darauf zeigt, bleibt die Trennung eine Konvention statt einer Garantie. Der nachhaltigste Schritt nach den Sofortmaßnahmen wäre, den Zugriff auf das rohe Feld strukturell auf Sanitizer und Versandpfad zu beschränken.
