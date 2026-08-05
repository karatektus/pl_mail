<!-- translated-from: providers/ics-feeds.md sha1:00166017b769d17d8a4327da6b26213522c6414a -->

# ICS-Feeds

Ein ICS-Feed ist ein Kalender, der als Datei unter einer Adresse veröffentlicht ist: die Feiertage
eines Landes, der Spielplan einer Liga, die Verfügbarkeit einer Kollegin oder die „geheime Adresse im
iCal-Format", die Google und Outlook für einen Kalender herausgeben, für den sie keinen API-Zugriff
gewähren. plMail kann einem solchen Feed folgen, ihn im Hintergrund neu einlesen und ihn neben deinen
eigenen Kalendern zeigen.

Das ist die billigste Kalenderverbindung, die es gibt. Es gibt nichts zu registrieren, keinen
Administrationsschritt, keine Zugangsdaten und keinen Zustimmungsbildschirm — eine Adresse ist die
gesamte Konfiguration.

## Abonnieren

**Einstellungen → Kalender → Adresse abonnieren**, oder derselbe Knopf aus dem Kalender selbst
heraus.

**Kalenderadresse** ist das einzige Feld, auf das es ankommt: die Adresse, unter der der Kalender
veröffentlicht ist, meist endend auf `.ics`. Ein `webcal://`-Link funktioniert ebenfalls — es ist
dieselbe Adresse unter anderem Namen, und plMail schreibt sie um, bevor irgendetwas anderes sie zu
sehen bekommt.

**Name** ist optional. Bleibt er leer, benennt sich der Kalender nach dem Titel des Feeds und, wenn
es den nicht gibt, nach dem Dateinamen in der Adresse. Ein bereits vergebener Name bekommt eine
Nummer, statt abgelehnt zu werden, denn zwei Feeds, die du nicht benannt hast, sind kein Konflikt,
den du auflösen können musst.

Es gibt keinen Schritt „welche Kalender?", denn ein Feed *ist* ein Kalender. Das Abonnieren zeigt eine
einzige Zeile, und das ist das ehrliche Bild dessen, was eine veröffentlichte Adresse anbietet.

Der Feed wird in diesem Moment einmal abgerufen. Stellt sich die Adresse als kein Kalender heraus,
bleibt nichts zurück — die Verbindung wird wieder entfernt, statt als dauerhaft kaputte Zeile in
deinen Einstellungen zu sitzen, an der es außer der Adresse selbst nichts zu korrigieren gibt. Und
das ist mit einigem Abstand der wahrscheinlichste Fehlschlag: Ein **Subscribe**-Knopf kopiert ungefähr
genauso oft einen Link auf eine *Webseite* wie einen Link auf eine `.ics`, und die beiden sind nicht
zu unterscheiden, bis etwas versucht, eine davon zu parsen.

## `webcal://` ist https unter anderem Namen

Apple hat das Schema registriert; kein Client hat je ein Protokoll dieses Namens gesprochen. Eine
`webcal://`-URL wird abgerufen, indem das Schema ersetzt und ein gewöhnliches GET gemacht wird, und
plMail bildet sie auf **https** ab, nicht auf das http, das die ursprüngliche Notiz nahelegte. Zwei
Gründe, in dieser Reihenfolge: Jeder Anbieter, der heute noch webcal anbietet, liefert denselben Pfad
auch über TLS aus, und eine Abbildung auf http liefe in die Klartext-Ablehnung weiter unten — eine
Person, die genau den Link einfügt, den ihr Kalender ihr gegeben hat, bekäme also gesagt, sie solle
ihre Administration um Erlaubnis fragen. `webcals://` kommt ebenfalls vor und bedeutet dasselbe.

Umgeschrieben wird nur das Schema, nicht der Rest der URL. Feed-Adressen tragen regelmäßig eine Query
mit einem eigenen `://` innerhalb eines prozentkodierten Parameters, und ein Umschreiben, das überall
zuschlüge, würde stattdessen genau das verstümmeln.

## Immer schreibgeschützt

Ein abonnierter Kalender lässt sich hier nicht bearbeiten, und das ist eine Tatsache darüber, was ein
ICS-Feed ist, und keine Einstellung, die jemand ändern könnte. Am anderen Ende steht eine statische
Datei: Es gibt keine Methode, die darauf schreiben würde, und keinen Server, der eine annähme. Jeder
andere Kalender, mit dem plMail sich verbindet, wird gefragt, ob er Schreibzugriffe annimmt — CalDAV
liest die Rechte der Sammlung, Google liest die Zugriffsrolle —, weil diese Gegenstellen eine Meinung
dazu haben. Eine Datei unter einer URL hat keine.

Die praktische Folge: Du siehst die Termine, sie erscheinen in deinen Ansichten, und der Kalender ist
in der Oberfläche als schreibgeschützt gekennzeichnet. Termine können ihm nicht hinzugefügt werden,
und nichts, was du hier tust, kann je den Anbieter erreichen.

**Die Adresse ist die Zugangsberechtigung.** Bei einem veröffentlichten Kalender gibt es niemanden,
als den man sich anmelden könnte, wer die Adresse hat, kann den Kalender also lesen — und genau
deshalb nennen Google und Outlook ihre eine *geheime* Adresse. plMail warnt davor im
Abonnier-Formular und zeigt die gespeicherte Adresse danach nicht mehr an. Behandle sie wie ein
Passwort und erzeug sie beim Anbieter neu, wenn sie abhandenkommt.

## Wie oft er neu gelesen wird

Feeds werden vom geplanten Kalender-Durchlauf neu gelesen, der alle fünfzehn Minuten läuft und jeden
Kalender aufgreift, der in den letzten vierzehn nicht abgeglichen wurde. Es gibt keinen Push und
nichts zu konfigurieren: Ein ICS-Feed hat keinen Benachrichtigungsmechanismus, für den man sich
registrieren könnte, nichts an einer öffentlichen Adresse, einem Reverse Proxy oder einem Webhook ist
hier also von Belang.

Einen ganzen Kalender alle fünfzehn Minuten neu zu lesen klingt teuer und ist es nicht, denn ein Feed
hat keinen Delta-Mechanismus und HTTP hat Validatoren. plMail speichert `ETag` und `Last-Modified`
des letzten erfolgreichen Abrufs und schickt sie als `If-None-Match` und `If-Modified-Since` zurück,
und ein unveränderter Kalender antwortet `304` ohne Rumpf. Das ist die Antwort auf fast jede Abfrage
eines Feiertagskalenders.

Hat der Feed sich *doch* geändert, liest plMail ihn von Grund auf neu — zwei Downloads für eine
tatsächliche Änderung. Das ist Absicht. Ein Feed nennt, was existiert, und sagt nichts darüber, was
entfernt wurde, ihn als Delta anzuwenden hieße also, jedes abgesagte Spiel für immer zu behalten; das
vollständige Neulesen ist das, was eine Löschung ankommen lässt.

Um einen sofort zu holen, statt zu warten:

```bash
docker compose exec php php bin/console app:calendar:sync CALENDAR_ID
```

## Warum manche Adressen abgelehnt werden

plMail ruft diese Adresse serverseitig ab, nach Zeitplan, aus einem Container heraus, der die eigene
Datenbank, den eigenen Message Broker und die eigenen Worker erreichen kann. Eine von Nutzerinnen und
Nutzern eingetippte Adresse wird deshalb geprüft, bevor irgendetwas sie anfragt — mit demselben
Wächter, der jede andere angegebene Serveradresse schützt, und aus demselben Grund.

Rundweg abgelehnt:

| | |
|---|---|
| Ein Schema, das nicht `http` oder `https` ist | `ftp://`, oder ein blankes `example.com/feed.ics` ganz ohne Schema |
| `http://` | sofern `INTEGRATIONS_ALLOW_HTTP` nicht an ist |
| Ein Benutzername oder ein Passwort in der URL | es landete überall dort im Log, wo die URL landet |
| `localhost` oder jeder Host, der auf `.localhost` endet | |
| Loopback, Link-local und private Bereiche | `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `0.0.0.0/8`, und die IPv6-Entsprechungen `::1`, `fc00::/7`, `fe80::/10` |

Die Sperre privater Bereiche ist die, die Selbsthostende erwischt, und sie ist die Prüfung, die
jemanden davon abhält, plMail auf den eigenen Datenbank-Container oder auf einen
Cloud-Metadaten-Endpunkt unter `169.254.169.254` zu richten. **Ein Feed in deinem eigenen LAN wird
abgelehnt, bis du ihn erlaubst**, indem du den Host in `INTEGRATIONS_ALLOWED_HOSTS` benennst — etwa
`nextcloud.lan` oder `10.0.0.5` — und, wenn der Server kein Zertifikat hat, zusätzlich
`INTEGRATIONS_ALLOW_HTTP` setzt. Beide stehen in der
[Konfigurationsreferenz](../install/configuration.md).

Mach dir klar, was jedes davon kostet. `INTEGRATIONS_ALLOWED_HOSTS` nimmt genau die von dir benannten
Hosts aus und sonst nichts, was eng ist und das richtige Werkzeug für einen Feed auf einer internen
Maschine. `INTEGRATIONS_ALLOW_HTTP` gilt global: Es befähigt jede Integration und jeden Feed der
Installation zum Klartext, eine Zugangsberechtigung kann also im Klartext durch dein Netz gehen. Es
ist standardmäßig aus, damit das Versenden einer solchen eine bewusste Entscheidung bleibt und kein
Versehen.

**Weiterleitungen werden erneut geprüft, bei jedem Sprung.** plMail folgt bis zu drei, von Hand, und
schickt jeden davon vor dem Abruf durch dieselbe Prüfung. Den HTTP-Client hinterherjagen zu lassen
hieße, dass ein vollkommen öffentlicher Feed-Host, der mit `302 Location:
http://169.254.169.254/…` antwortet, die geplante Abfrage in einen Abruf des
Cloud-Metadaten-Endpunkts verwandelt. Die Begrenzung auf drei Sprünge selbst deckt die Formen ab, die
tatsächlich vorkommen — http nach https, eine Apex-Domain auf einen `www`-Host, ein Kurzlink.

**Ein Feed über 8 MB wird bereits während des Herunterladens abgelehnt**, nicht danach. Das liegt weit
jenseits des Punktes, an dem ein Feed plausibel ein Kalender ist — ein nationaler Feiertags-Feed liegt
unter 60 KB, ein fünfzehnjähriger betrieblicher Raumkalender bei ein paar hundert —, und es existiert,
damit eine von jemandem eingetippte Adresse keinen Worker mit einer unbegrenzten Antwort umbringen
kann.

Ist ein Feed erst einmal erreichbar, kann die Gegenstelle immer noch nein sagen, und plMail
unterscheidet, was einen erneuten Versuch wert ist:

| Antwort | Was plMail tut |
|---|---|
| `401` | Gibt endgültig auf. Ein Feed hat keine Anmeldung; nimm stattdessen die geheime oder die öffentliche Adresse. |
| `404`, `410` | Gibt endgültig auf. Dort ist kein Kalender mehr. |
| `429`, `503` | Wartet ab und versucht es erneut, und beachtet `Retry-After`, wenn es in Sekunden angegeben ist. |
| `403` | Versucht es erneut. Anders als bei einem CalDAV-Server gibt es hier keine Zugangsdaten, die abgelehnt worden sein könnten, und ein CDN vor einem Feed antwortet auf ein Ratenlimit, eine Geo-Regel und einen Bot-Filter gleichermaßen mit 403. |
| Ein toter Host, ein TLS-Fehler, eine Zeitüberschreitung | Versucht es erneut. Ein Anbieter, der ausgefallen ist, kommt zurück. |

## Fallstricke

**Der Link, den dir der Subscribe-Knopf gegeben hat, kann eine Webseite sein.** Anbieter legen eine
menschenlesbare Kalenderseite und eine `.ics` hinter Knöpfe, die gleich aussehen. Scheitert das
Abonnieren mit einer Meldung, dass unter der Adresse kein Kalender liegt, öffne sie im Browser:
Bekommst du eine Seite, liegt die `.ics` irgendwo darauf.

**Ein Feed in deinem eigenen Netz wird abgelehnt, und das ist der SSRF-Wächter und kein Fehler.**
Benenn den Host in `INTEGRATIONS_ALLOWED_HOSTS`. Die Sperre privater Bereiche ganz abzuschalten ist
mit Absicht keine Option, und `INTEGRATIONS_ALLOW_HTTP` ist ein größerer Hammer, als die meisten
brauchen.

**Die Adresse ist die Zugangsberechtigung und wird nicht wieder angezeigt.** Wer sie hat, kann den
Kalender lesen. Kommt eine geheime Adresse abhanden, erzeug sie beim Anbieter neu — auf dieser Seite
gibt es nichts zu widerrufen.

**Ein abonnierter Kalender lässt sich nicht bearbeiten, und keine Berechtigung ändert das.** Wenn du
in einen Google- oder Outlook-Kalender schreiben musst, verbinde das Konto selbst statt seiner
veröffentlichten Adresse; siehe [Google](google.md) und [Microsoft](microsoft.md).

**Nichts pusht.** Eine Änderung beim Anbieter erscheint hier binnen fünfzehn Minuten, und keine
Reverse-Proxy-Konfiguration macht das schneller. Das ist die eine Kalenderquelle, bei der das kein
eingeschränkter Zustand ist.

**Ein Feed ohne `X-WR-CALNAME` benennt sich nach seiner Datei.** `feiertage-deutschland` in deiner
Seitenleiste ist genau das, und beim Abonnieren einen Namen einzutippen ist der Weg, es zu vermeiden.

**Zwei Downloads pro Änderung sind zu erwarten.** Eine Abfrage, die den Feed als geändert vorfindet,
liest ihn von Grund auf neu, denn das ist die einzige Art, wie eine Löschung in einer
veröffentlichten Datei überhaupt erkannt werden kann.
