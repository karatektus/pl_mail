<!-- translated-from: features/clients.md sha1:5c4e7621822946ff0662da3a649ed21aef65c292 -->

# Andere Clients

plMail spricht JMAP, eine Mail-App eines Drittanbieters kann also darüber lesen und senden,
ohne den Zugangsdaten deines Anbieters nahezukommen. Es ist außerdem eine Progressive Web App,
der Browser, den du ohnehin benutzt, kann sich also wie ein installierter Client verhalten und
dich über neue Mail benachrichtigen, während der Tab geschlossen ist.

## Einen JMAP-Client verbinden

Zwei Dinge: ein App-Passwort und eine URL.

1. **Einstellungen → App-Passwörter → Erzeugen**, benannt nach der App, die du verbindest. Das
   Geheimnis wird einmal angezeigt und sieht aus wie `plmail_` gefolgt von 64 Hex-Zeichen.
2. Gib der App deine **E-Mail-Adresse** als Benutzernamen und das **App-Passwort** als
   Passwort.
3. Fragt sie nach einem Server oder einer JMAP-URL, gib ihr die Session-Adresse, die die
   Einstellungsseite für dich ausdruckt:

   ```
   https://your-domain/jmap/session
   ```

Gib ihr die **Session**-Adresse, nicht `/jmap/api`. Der API-Endpunkt nimmt nur POST an, ein
Client, den man darauf gerichtet hat, scheitert also an seinem ersten Request auf eine Weise,
die nach einem kaputten Server aussieht. Alles andere — die API-URL, die Upload- und
Download-URLs, die Event-Source-URL — wird aus dem Session-Objekt ermittelt, und genau so soll
ein JMAP-Client sie finden.

`https://your-domain/.well-known/jmap` antwortet dasselbe, für Clients, die dort nachsehen.

Zugangsdaten gehen über `Authorization`, entweder als Bearer-Token oder als HTTP Basic. Bei
Basic wird der Benutzername tatsächlich gegen den Besitzer des Tokens geprüft — eine falsche
Adresse wird abgelehnt, statt stillschweigend als Besitzer des Tokens zu arbeiten.

Die JMAP-Firewall ist zustandslos und hält keine Sitzung, und die
Zwei-Faktor-Authentifizierung gilt dort nicht. Genau dafür gibt es App-Passwörter: Eine
Mail-App hat keine Möglichkeit, einen sechsstelligen Code vorzuzeigen. Der Zugang wird entzogen,
indem du das App-Passwort widerrufst, und nicht von der Seite Sicherheit aus.

Ein App-Passwort gilt **für dich als Benutzer**. Ein einziges Zugangsdatum zählt jedes
Mail-Konto auf, das du verbunden hast, passend zum JMAP-Session-Objekt — deshalb zeigt ein
Client sie alle auf einmal.

## Ein Gerät koppeln

**Einstellungen → App-Passwörter → Gerät koppeln** zeigt einen QR-Code, den eine plMail-App
scannen kann. Die App tauscht ihn gegen ein eigenes App-Passwort, das dann unter dem Namen in
der Liste auftaucht, den das Gerät angegeben hat — wer vier Telefone hat, weiß also, welches er
widerrufen muss.

Der Code funktioniert **einmal**, läuft nach **zwei Minuten** ab und trägt selbst keine
Zugangsdaten. Er wird aus deiner Sitzung heraus ausgestellt; der Tausch nicht, denn ein Gerät,
das sich bereits authentifizieren könnte, müsste sich nicht koppeln.

## Was ein Client erwarten darf

`Mailbox`, `Email`, `Thread`, `EmailSubmission`, `Identity` und `PushSubscription` sind
umgesetzt, dazu Such-Schnipsel und eine Kalendererweiterung von plMail selbst. Die vollständige,
aktuelle Liste — einschließlich dessen, was bewusst fehlt, wie die Id-Räume funktionieren und
welche vier Objektzuordnungen für Überraschungen sorgen — steht in **[Client
development](../CLIENT_DEVELOPMENT.md)**, der Referenz auf Protokollebene und dem, was zu lesen
ist, bevor man irgendetwas gegen diesen Server schreibt. Arbeite dafür nicht mit dieser Seite,
sondern mit jener.

Zwei Hinweise, die eher hierher gehören als dorthin:

- Der Server wird aktiv weiterentwickelt, und der Eröffnungsabschnitt des Client-Leitfadens
  sagt, was das für deine App bedeutet. Lies ihn zuerst — einschließlich seiner stehenden
  Einladung, nach einer Methode zu fragen, statt um ihr Fehlen herumzukonstruieren.
- Kalender werden unter einer **Hersteller-Capability** angekündigt,
  `urn:plmail:params:jmap:calendars`, und nicht unter der IETF-Kalender-URN. „JMAP for
  Calendars“ ist ein nicht verabschiedeter Entwurf, dessen Objektform noch in Bewegung ist —
  eine Hersteller-URN sagt also die Wahrheit: Das ist plMails Kalenderfläche, und nur etwas,
  das für plMail geschrieben wurde, sollte sie benutzen.

## Die Web-App

plMail liefert ein Web-App-Manifest mit, jeder Browser, der das Installieren unterstützt, kann
es also auf einen Startbildschirm oder ins Dock legen. Es öffnet eigenständig, im Posteingang.

Auf iPhone und iPad ist das nicht optional, wenn du Benachrichtigungen willst: Safari bietet sie
erst an, wenn die App zum Home-Bildschirm hinzugefügt wurde. Öffne plMail in Safari, tippe auf
Teilen, dann auf **Zum Home-Bildschirm**, und aktiviere die Benachrichtigungen von dort aus.

Der Service Worker cacht bewusst **nichts**. Ein Mail-Client, der veraltete Mail aus dem Cache
zeigt, ist schlimmer als einer, der sagt, er sei offline, und Cache-Invalidierung in einer App
mit Anmeldung ist ein guter Weg, die Mail eines Kontos in die Sitzung eines anderen zu tragen.

## Browser-Benachrichtigungen

**Einstellungen → Benachrichtigungen** schaltet sie für das Gerät ein, an dem du gerade sitzt.
Jedes Gerät zählt für sich — der Schalter meint **Dieses Gerät** und sagt das auch.

Die Zustände, die er meldet:

| Zustand | Bedeutet |
|---|---|
| **An — dieses Gerät wird über neue E-Mails benachrichtigt.** | Registriert und bestätigt |
| **Warten auf die Bestätigung dieses Geräts…** | Der Bestätigungs-Handshake ist noch nicht zurück |
| **Aus — dieses Gerät wird nicht benachrichtigt.** | Nicht registriert |
| **In deinen Browser-Einstellungen blockiert.** | Erlaube Benachrichtigungen für diese Seite und versuche es erneut |
| **Dieser Browser kann keine Benachrichtigungen empfangen.** | Auf iPhone und iPad plMail zuerst zum Home-Bildschirm hinzufügen |
| **Push ist auf diesem Server nicht konfiguriert.** | Keine VAPID-Schlüssel — siehe unten |

Die Registrierung ist ein echter Handshake und keine bloße Behauptung. plMail schickt einen
Bestätigungscode an den Endpunkt, den der Browser genannt hat, der Service Worker schickt den
Code zurück, und erst dann gilt das Gerät als belieferbar. Ob erste oder dritte Partei — die
Zusage ist dieselbe, die ein fremder Client bekommt: Der Endpunkt erreicht nachweislich das
Gerät dieses Benutzers.

Sich aus demselben Browser erneut anzumelden ersetzt dessen Registrierung, statt tote Endpunkte
anzuhäufen — Browser rotieren die Endpunkt-URL, und plMail schlüsselt nach einer Id, die der
Browser behält.

**Registrierte Geräte** listet unter dem Schalter jedes Gerät des Kontos auf, nicht nur dieses:
die Handy-App, das Tablet, den anderen Laptop. Jede Zeile sagt, welchen Transportweg das Gerät
benutzt (Web Push oder Firebase), ob der Bestätigungs-Handshake abgeschlossen ist, und was beim
letzten Mal passiert ist, als etwas dorthin geschickt wurde — zugestellt, fehlgeschlagen oder noch
nichts gesendet. Der letzte Teil ist der nützliche, wenn ein Telefon verstummt: „Wir haben es
versucht und die Adresse ist tot" und „es wurde nie etwas geschickt" sind entgegengesetzte
Probleme und waren von außen bisher nicht zu unterscheiden.

Ein Gerät, dessen Adresse dauerhaft tot ist, wird automatisch entfernt und verschwindet aus der
Liste; die Benachrichtigungen auf diesem Gerät wieder einzuschalten registriert es erneut.

Jede Zeile hat außerdem ein **Entfernen**, und das ist das einzige Mittel gegen das umgekehrte
Problem: eine Registrierung, die genau wie vorgesehen funktioniert und trotzdem nicht existieren
sollte. Der übliche Fall ist ein alter App-Stand, der noch unter einer Geräte-ID registriert ist,
die niemand mehr benutzt — ein Telefon bekommt dann jede Benachrichtigung doppelt. An dieser
Registrierung schlägt nichts fehl, also räumt sie auch nichts von selbst weg.

Entfernen hält die Zustellungen an dieses Gerät ab der nächsten Benachrichtigung an. Auf dem Gerät
selbst wird dadurch nichts deinstalliert und nichts abgeschaltet — eine App, die weiterhin
Benachrichtigungen möchte, registriert sich beim nächsten Start erneut und taucht wieder in der
Liste auf. Was das Entfernen *nicht* mitnimmt, ist die Vorgeschichte: Das Zustellprotokoll hängt an
der Geräte-ID und nicht an der Registrierung, die Zeilen zu einem entfernten Gerät bleiben also im
Protokoll unter `/admin/push`, bis sie nach Alter weggeräumt werden.

Was die
Zeile nie sagt, ist, *was* gepusht wurde — festgehalten wird nur, ob es eine Zustandsänderung der
Mail oder eine Bestätigung war, nie etwas über die Mail selbst.

Eine Benachrichtigung über Mail trägt **keinen Mailinhalt**. Sie ist ein
JMAP-`StateChange`-Objekt, das sagt, dass sich etwas bewegt hat, und nichts darüber, was — die
App holt sich die Einzelheiten, sobald sie geöffnet wird. Kalender-Erinnerungen sind die
Ausnahme: Sie tragen ihren eigenen Text, denn eine Erinnerung, für die man die App öffnen muss,
um zu erfahren, worum es geht, ist keine Erinnerung. Diese Nutzlast ist Ende zu Ende unter dem
eigenen Schlüssel des Abonnements verschlüsselt, der Push-Dienst sieht also Geheimtext.

Hat der Server keine VAPID-Schlüssel, funktioniert hier nichts, und die Seite sagt das. Sie zu
erzeugen ist ein Befehl:

```
docker compose exec php php bin/console app:push:generate-vapid-keys
```

## Wo du weiterliest

- [Client development](../CLIENT_DEVELOPMENT.md) — die Protokollreferenz: Methoden, Filter,
  Id-Räume, Push, und das Verhalten, das von einem Client erwartet wird.
- [JMAP](../internals/jmap.md) — was umgesetzt ist, was bewusst nicht, und warum.
- [Sicherheit](security.md) — App-Passwörter, Widerruf, und was die
  Zwei-Faktor-Authentifizierung nicht abdeckt.
- [Konfigurationsreferenz](../install/configuration.md) — `APP_PUBLIC_URL` und die
  VAPID-Schlüssel.

## Fallstricke

**Einen Client auf `/jmap/api` statt auf `/jmap/session` zu richten scheitert auf verwirrende
Weise.** Der API-Endpunkt nimmt nur POST an. Die Einstellungsseite druckt die zu verwendende
Adresse aus; kopiere sie von dort.

**Die Zwei-Faktor-Authentifizierung schützt `/jmap` nicht, und sie kann es nicht.** Ein
App-Passwort zu widerrufen ist der einzige Weg, einem Client den Zugang zu entziehen.

**Ein App-Passwort erreicht jedes Konto, das du verbunden hast.** Es gilt für dich und nicht
für ein Postfach, ein Widerruf trennt die App also von allen auf einmal — und es gibt keine
Möglichkeit, einem Client nur ein Konto zu geben.

**Das Geheimnis wird einmal angezeigt, und eine zweite Gelegenheit gibt es nicht.** Nicht aus
einer Ansicht, nicht aus der Datenbank. Widerrufe es und erzeuge ein neues.

**Benachrichtigungen brauchen den Service Worker registriert, bevor du dich anmeldest, nicht
danach.** Der Bestätigungscode kommt als Push-Nachricht an, es muss also etwas da sein, das ihn
empfängt — deshalb ist die Reihenfolge festgelegt, und deshalb kann ein Abonnement in „warten
auf Bestätigung“ hängen bleiben, wenn der Worker nie hochkam.

**Ein Gerät, das nie bestätigt, bleibt unbelieferbar.** Der Zustand ist darüber ehrlich, statt
Erfolg zu behaupten, aber von allein wird nichts wiederholt; die Benachrichtigungen aus- und
wieder einzuschalten stößt den Handshake erneut an. In der Geräteliste steht es als **Nicht
bestätigt** mit einer Zustellung daneben — die Bestätigung wurde geschickt und das Gerät hat nie
geantwortet.

**„Noch nichts gesendet" neben einem bestätigten Gerät ist normal.** Push feuert nur, wenn sich
tatsächlich etwas ändert, ein ruhiges Postfach erzeugt also gar keine Zustellungen. Als Problem zu
lesen ist es nur neben einem unbestätigten Gerät, wo es heißt, dass der Handshake nicht einmal
losgeschickt wurde.

**Push-Benachrichtigungen brauchen eine öffentliche HTTPS-Adresse.** Ohne sie kann der
Push-Dienst des Browsers diesen Server nicht erreichen, was der Schalter lokal auch sagen mag.
