<!-- translated-from: install/reverse-proxy.md sha1:6a493660819c759ccbe2a6bbda4d7909a2537be9 -->
# Hinter einem Reverse-Proxy

Alles, was von außerhalb des eigenen Netzes erreichbar ist, will ein echtes Zertifikat davor, und
plMail ist unter der Annahme geschrieben, dass die TLS-Terminierung anderswo der Normalfall ist.
Drei Einstellungen entscheiden, ob das funktioniert: `APP_PUBLIC_URL`, `TRUSTED_PROXIES` und
`MERCURE_PUBLIC_URL`. Jede von ihnen scheitert lautlos, wenn sie falsch ist — und genau dafür gibt
es diese Seite.

Vorgabewerte und Vorrangregeln für alles hier Genannte stehen in der
[Konfigurationsreferenz](configuration.md).

## Der grundsätzliche Aufbau

Dein Proxy hält das Zertifikat und leitet über schlichtes HTTP an den `php`-Container weiter:

```yaml
services:
  php:
    environment:
      SERVER_NAME: ":80"        # FrankenPHP serves plain HTTP; no certificate here
```

`SERVER_NAME` steht standardmäßig auf `localhost, php:80`, und Caddy entscheidet im Container anhand
dieses Namens, ob es TLS selbst terminiert. `:80` sagt ihm, dass es das nicht tun soll. Veröffentliche
dann nur den HTTP-Port — `HTTP_PORT` bildet den Host-Port auf den Container-Port 80 ab — und lass
den Proxy ihn erreichen. `truenas.compose.yaml` macht genau das und veröffentlicht `30080`.

Die andere Hälfte besteht darin, der Anwendung mitzuteilen, wer sie ist:

```
APP_PUBLIC_URL=https://mail.example.com
TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
MERCURE_PUBLIC_URL=https://mail.example.com/.well-known/mercure
```

**Der typische Fehlerfall ist, `SERVER_NAME` hinter einem Proxy auf dem Vorgabewert zu belassen.**
Caddy versucht dann, `localhost` auszuliefern, während der Proxy es über Containernamen oder
IP-Adresse anspricht — und die Anfrage passt auf keinen Site-Block.

## `APP_PUBLIC_URL`

Die Adresse, unter der plMail von außen erreicht wird. Das ist keine kosmetische Einstellung: Es ist
die Adresse, die Google und Microsoft als Rückrufziel genannt bekommen.

Sie hat keinen Vorgabewert und lässt sich in dem Moment, in dem sie gebraucht wird, nicht ableiten,
denn der Prozess, der ein Push-Abonnement registriert, ist ein dauerhaft laufender Worker oder ein
geplanter Konsolenbefehl — keiner von beiden hat eine Anfrage, aus der er einen Hostnamen lesen
könnte. Also fragt der Einrichtungsbildschirm danach und schreibt den Wert nach
`var/secrets/generated.env`, die einzige Stelle, an die ein laufender Container schreiben kann und
die jeder andere Dienst liest. Alles, was über die Umgebung mitgegeben wird, gewinnt gegen diesen
gespeicherten Wert.

Der Wert wird **bei jedem Aufruf** aufgelöst statt einmalig injiziert; genau das lässt einen Worker,
der vor der Einrichtung gestartet ist, die Adresse sehen, die danach jemand mit Administratorrechten
gespeichert hat. Das Speichern signalisiert zudem jedem Worker, sich neu zu starten, damit er sie
sofort übernimmt und nicht erst beim nächsten stündlichen Recycling.

Zwei harte Anforderungen kommen von den Anbietern; sie werden lokal geprüft, bevor eine
Registrierung überhaupt versucht wird, damit im Log steht, welche Einstellung fehlt, statt einen
entfernten Validierungsfehler zu wiederholen:

- sie muss mit `https://` beginnen
- ihr Host darf nicht `localhost`, `127.0.0.1` oder `::1` sein

Scheitert eines von beidem, registrieren sich Kalender-Push und Microsoft-Graph-Mail-Push nicht.
**Das ist kein Fehlerzustand** — eine Installation ohne öffentlich erreichbare Adresse pollt
schlicht, und am Polling ändert nichts davon etwas: `app:mail:sync` läuft alle 15 Minuten und
`app:calendar:sync --stale` alle 15 Minuten, versetzt gegen die Viertelstunde. Push sorgt nur dafür,
dass Mail- und Kalenderänderungen sofort statt verspätet ankommen.

**Der typische Fehlerfall ist eine öffentliche URL mit angehängtem Pfad oder Schrägstrich am Ende.**
Beim Speichern wird ein abschließendes `/` entfernt, und Webhook-URLs entstehen, indem ein
generierter Routenpfad angehängt wird — alles andere darin landet also mitten in einer
Rückrufadresse, die der Anbieter bereitwillig registriert und nie erfolgreich aufruft.

## `TRUSTED_PROXIES`

Symfony vertraut `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` und `X-Forwarded-Port`,
aber nur von den hier aufgeführten Adressen. Der eingecheckte Vorgabewert deckt Loopback und die
drei RFC1918-Bereiche ab, was für einen Proxy im selben Docker-Netz oder im selben LAN richtig ist.

Setzt du ihn zu eng — die Adresse des Proxys steht nicht in der Liste —, dann glaubt Symfony, es
liefere schlichtes HTTP an die Adresse aus, von der der Proxy sich verbindet. Vier Dinge folgen
daraus, und keines davon meldet sich:

- **OAuth funktioniert nicht mehr.** Die an Google und Microsoft übergebene Redirect-URI wird als
  absolute URL aus der aktuellen Anfrage erzeugt, kommt also als `http://…/oauth/google/callback`
  heraus und passt nicht zu dem, was du registriert hast. Der Anbieter lehnt mit einer
  Redirect-URI-Abweichung ab.
- **Die Anmeldedrosselung zählt alle als einen Client.** Die Firewall begrenzt auf fünf Versuche je
  15 Minuten, geschlüsselt über die probierten Zugangsdaten und die Client-Adresse; wenn jede
  Anfrage vom Proxy zu kommen scheint, verbraucht eine einzige Person beim Passwortraten ein
  Kontingent, das die gesamte Installation teilt.
- **Cookies verlieren ihr `secure`-Flag.** Remember-me ist mit `secure: auto` konfiguriert, also
  "sicher, wenn die Anfrage es ist", und das Mercure-Subscriber-Cookie wird auf demselben Weg aus
  der Anfrage erzeugt. Über eine Verbindung, die Symfony für schlichtes HTTP hält, wird keines von
  beiden als sicher markiert.
- **Erzeugte absolute URLs sind falsch**, einschließlich der Freigabelinks und Buchungsseiten-Links
  unter Einstellungen → Teilen, die mit `ABSOLUTE_URL` aus der Anfrage gebaut werden.

Setzt du ihn zu weit — alles wird als vertrauenswürdig behandelt —, dann kann ein Client
`X-Forwarded-For` fälschen und sich als beliebige Adresse ausgeben, was die Ratenbegrenzung pro
Adresse aushebelt und einen frei wählbaren Wert in deine Logs schreibt.

**Der typische Fehlerfall ist, das als OAuth-Problem zu diagnostizieren.** Eine
Redirect-URI-Abweichung nennt die URI, und das `http://` darin ist der ganze Hinweis.

## Mercures öffentliche URL

Der Hub wird auf der Origin der Anwendung selbst durchgereicht: `frankenphp/Caddyfile` leitet
`/.well-known/mercure*` an den `mercure`-Container, sodass der Browser den Hub auf derselben Origin
erreicht, das Subscriber-Cookie ein First-Party-Cookie ist und es nirgends eine CORS-Konfiguration
gibt.

Zwei Variablen, und sie zeigen in entgegengesetzte Richtungen:

- `MERCURE_URL` ist die Adresse, an die die **Anwendung** innerhalb des Docker-Netzes veröffentlicht:
  `http://mercure/.well-known/mercure`. Sie ändert sich selten.
- `MERCURE_PUBLIC_URL` ist die Adresse, die der **Browser** abonniert. Sie muss deine öffentliche
  Adresse plus `/.well-known/mercure` sein.

`config/bootstrap_generated_secrets.php` leitet die zweite aus `APP_PUBLIC_URL` ab, damit es eine
Sache weniger auszufüllen gibt — aber **nur, wenn `MERCURE_PUBLIC_URL` nicht gesetzt oder leer
ist**, und die Standard-`compose.yaml` setzt sie bedingungslos auf
`https://localhost/.well-known/mercure`. Auf einer Installation hinter einem Proxy setze sie also
ausdrücklich. `truenas.compose.yaml` lässt den eigenen Wert genau deshalb leer, damit die Ableitung
stattfinden kann.

Nicht nur der Host zählt, sondern auch das Schema: Symfony leitet das `secure`-Flag des
Subscriber-Cookies aus dieser URL ab, ein `https`-Wert auf einer Installation mit schlichtem HTTP
erzeugt also ein Cookie, das der Browser nicht zurücksendet.

Liegt dein Hub tatsächlich auf einer anderen Domain, kann das Subscriber-Cookie überhaupt nicht
ausgestellt werden — `MercureCookieSubscriber` protokolliert das einmalig auf Info-Ebene, und die
Seite wird trotzdem gerendert. Live-Aktualisierungen sind dann schlicht nicht verfügbar, was das
ehrliche Ergebnis ist statt eines 500ers.

**Der typische Fehlerfall ist eine Anwendung, die tadellos funktioniert, außer dass sich nie etwas
von selbst aktualisiert.** Es wird kein Fehler angezeigt, weil die Seite einwandfrei gerendert
wurde; nur der Stream öffnet sich nie. Die Verbindungsanzeige in der oberen Leiste ist die Stelle,
an der das sichtbar wird.

## Die Proxy-Konfiguration selbst

Caddy setzt die Forwarded-Header automatisch:

```caddy
mail.example.com {
	reverse_proxy plmail:80
}
```

nginx braucht sie ausgeschrieben, und es braucht noch etwas — der Mercure-Stream besteht aus
Server-Sent Events, und ein Proxy, der Antworten puffert, hält die Ereignisse fest, statt sie
durchzureichen:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Port $server_port;
}

location /.well-known/mercure {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 24h;
}
```

Anhänge laufen über denselben Weg, eine Größenbegrenzung für Anfragekörper im Proxy muss also das
zulassen, was PHP selbst akzeptiert: `upload_max_filesize` ist `25M` und `post_max_size` ist `60M`
im Image.

**Der typische Fehlerfall ist eine Proxy-Größenbegrenzung, die kleiner ist als die von PHP.** Der
Upload wird abgewiesen, bevor Symfony ihn sieht, die Meldung, die der Benutzer bekommt, stammt also
vom Proxy und sagt nichts über Anhänge.

## Webhooks, die dich erreichen müssen

Wenn Push der Grund ist, plMail hinter einen Proxy zu stellen, sind das die Pfade, die ankommen
müssen:

| Pfad | Wer ihn aufruft | Womit er sich ausweist |
|---|---|---|
| `POST /gmail/push` | Google Cloud Pub/Sub | `?token=`, passend zu `GMAIL_PUBSUB_VERIFICATION_TOKEN`, verglichen mit `hash_equals`; **ohne konfiguriertes Token wird alles abgewiesen** |
| `POST /webhook/graph/notify` | Microsoft Graph, Mail | `clientState`, je Abonnement erzeugt |
| `POST /webhook/graph/lifecycle` | Microsoft Graph | wie oben |
| `POST /webhook/graph/calendar` | Microsoft Graph, Termine | wie oben |
| `POST /webhook/google/calendar` | Google-Calendar-Watch-Kanal | Kanal-Token in `X-Goog-Channel-Token` |

Alle fünf sind in der Firewall `PUBLIC_ACCESS`, weil der Aufrufer ein Anbieter ohne Sitzung ist. Ihre
Adressen werden aus `APP_PUBLIC_URL` gebildet, nie aus der eingehenden Anfrage — siehe
[Google](../providers/google.md) und [Microsoft](../providers/microsoft.md) für die Konsolenseite
und das [Sicherheitsmodell](../internals/security-model.md) dafür, was jedes Token leistet.

Google stellt Kalenderbenachrichtigungen zusätzlich nicht an eine **unverifizierte Domain** zu: Der
Rückruf-Host muss in dem Cloud-Projekt verifiziert sein, dem der OAuth-Client gehört. Bis dahin wird
jedes `events.watch` abgelehnt, was immerhin sichtbar ist — als Warnung im Log zum Zeitpunkt der
Registrierung.

**Der typische Fehlerfall ist ein Proxy, der nur `GET` weiterleitet.** Jeder dieser Aufrufe ist ein
`POST`, und eine für eine reine Leseseite geschriebene Regel verwirft sie mit einem Status, den der
Anbieter als Zustellfehler wertet und mit Backoff wiederholt.

## Fallstricke

**`MERCURE_PUBLIC_URL` wird bei der Standard-Compose-Datei nicht abgeleitet.** Die Ableitung aus
`APP_PUBLIC_URL` findet nur statt, wenn die Variable nicht gesetzt oder leer ist, und `compose.yaml`
setzt sie auf `https://localhost/.well-known/mercure`. Das ist der mit Abstand wahrscheinlichste
Grund dafür, dass Live-Aktualisierungen in der Entwicklung funktionieren und in der Produktion
nicht.

**Eine fehlgeschlagene Push-Registrierung ist kein Fehlerzustand.** Die Registrierung wird stündlich
von `app:calendar:push` wiederholt, statt an den Klick gebunden zu sein, mit dem ein Kalender
verbunden wurde. Eine Installation, deren Adresse oder Domain-Verifizierung in Ordnung gebracht
wird, beginnt also innerhalb einer Stunde zu pushen, ohne dass jemand irgendetwas neu abonnieren
müsste.

**Ein in der Umgebung gesetztes `APP_PUBLIC_URL` schlägt den Einrichtungsbildschirm dauerhaft.** Für
eine Installation, die ihre eigene Konfiguration verwaltet, ist das das gewünschte Verhalten — es
bedeutet aber auch: Änderst du die Adresse in der Compose-Datei und erwartest, dass der Wert aus dem
Einrichtungsbildschirm zählt, bearbeitest du die falsche Stelle, und umgekehrt.

**Ein falsches `TRUSTED_PROXIES` sieht aus wie vier voneinander unabhängige Fehler.**
OAuth-Abweichungen, ein von allen geteiltes Kontingent an Anmeldeversuchen, Cookies ohne `secure`
und Freigabelinks mit falschem Schema sind ein und dieselbe Einstellung.

**Nichts hiervon entscheidet darüber, ob Mail ankommt.** Polling ist die Rückfallebene und läuft
bedingungslos: 15 Minuten für Mail, 15 für verbundene Kalender, jede Minute für das Aufwachen
zurückgestellter Konversationen und für Erinnerungen. Eine Installation ganz ohne öffentliche
Adresse ist eine unterstützte Installation; sie erfährt es nur nie als Erste.
