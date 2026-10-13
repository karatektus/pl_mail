<!-- translated-from: providers/carddav.md sha1:a06fc9c179ee639b2313645719eb019dbdeed91d -->
# CardDAV

CardDAV ist das Standardprotokoll für Adressbücher auf einem Server, und plMail spricht es mit jedem
Server, der RFC 6352 umsetzt: Nextcloud, Radicale, Baïkal, Fastmail, iCloud. Meist ist es derselbe
Server wie bei [CalDAV](caldav.md), mit demselben App-Passwort. Du verbindest ihn in deinen eigenen
Einstellungen; ein Schritt für den Administrator ist nicht nötig.

Kontakte aus einem gespiegelten Adressbuch werden dir vorgeschlagen, wenn du eine Nachricht
adressierst, neben den Adressen, die plMail aus deinen E-Mails geerntet hat. Auf der Seite
**Kontakte** kannst du sie auflisten, durchsuchen und bearbeiten, und eine Änderung dort wird an den
Server zurückgeschrieben.

## Einen Server verbinden

**Einstellungen → Kontakte → CardDAV-Server verbinden.** Dasselbe Formular wie bei den Kalendern,
mit denselben vier Feldern in derselben Reihenfolge — Serveradresse, Name, Benutzername,
App-Passwort — und derselben bewusst ausgeschalteten Option, das gespeicherte Passwort eines
E-Mail-Kontos wiederzuverwenden. Was jedes Feld erwartet, steht unter
[Einen Server verbinden](caldav.md#einen-server-verbinden) auf der CalDAV-Seite; alles dort gilt
auch hier.

Bei Nextcloud ist die CardDAV-Adresse dasselbe `…/remote.php/dav` wie die CalDAV-Adresse, und die
Kontakte-App zeigt unter **Kontakte → Einstellungen** eine Adresse pro Buch, falls du nur auf ein
einzelnes Buch zeigen willst.

Speichern verbindet sofort und listet dann die Adressbücher, die der Server anbietet, mit
Kästchen zum Anhaken. Angehakte Bücher werden gespiegelt; ihre erste Synchronisierung wird sofort
eingereiht, sodass die Kontakte innerhalb einer Minute erscheinen und nicht erst beim nächsten
Durchlauf.

**Es ist eine eigene Verbindung, getrennt von CalDAV**, auch auf demselben Server. Manche Server
bieten nur eines von beiden, und manche Menschen haben Kontakte und Kalender an verschiedenen
Orten. Eine Nextcloud für beides zu verbinden heißt, das App-Passwort zweimal einzugeben.

## Die Suche

Genau die CalDAV-Suche mit den Namen der Adressbücher: zuerst die eingefügte Adresse, dann
`/.well-known/carddav`, dann das `addressbook-home-set` des Principals, dann wird das Home
aufgelistet. Eine eingefügte Kalender-URL wird nicht für ein Adressbuch gehalten, und die Kalender
desselben Servers tauchen nie in der Liste der Adressbücher auf. Siehe
[Die Suche, und der `.well-known`-Tanz](caldav.md#die-suche-und-der-well-known-tanz).

Es gelten dieselben [abgelehnten Adressen](caldav.md#adressen-die-plmail-ablehnt): Ein Server in
deinem LAN braucht seinen Host in `INTEGRATIONS_ALLOWED_HOSTS`, und reines `http://` braucht
`INTEGRATIONS_ALLOW_HTTP`.

## Synchronisation

**Nur Polling.** CardDAV hat keinen Push, also liest `app:contacts:sync --stale` gespiegelte Bücher
alle fünfzehn Minuten (um :11, :26, :41 und :56, versetzt gegen die Mail- und Kalender-Durchläufe,
die sich den Worker teilen). Eine Änderung vom Handy erscheint hier innerhalb einer Viertelstunde;
unter **Einstellungen → Kontakte** gibt es pro Buch einen Knopf zum sofortigen Synchronisieren.

Änderungen werden gelesen wie bei Kalendern: `sync-collection` (RFC 6578), wo der Server es
anbietet, sonst `getctag` plus eine vollständige Liste, wenn sich das ctag bewegt. Nur eine
vollständige Liste löscht lokale Karten, die sie nicht erwähnt; ein Delta tut das nie.

**Was du hier änderst, wird sofort gespeichert und danach gesendet.** Die Karte wird als ausstehend
markiert — die Kontakte-Seite zeigt eine kleine Uhr daneben — und der Worker schickt sie bei der
nächsten Synchronisierung, die eine Änderung sofort einreiht. Jede Synchronisierung sendet
ausstehende Änderungen, *bevor* sie den Server liest, damit die ältere Kopie des Servers eine
Änderung nicht auf dem Weg hinaus überschreibt.

**Jeder Schreibzugriff ist bedingt.** Ein neuer Kontakt wird mit `If-None-Match: *` unter einem aus
seiner UID abgeleiteten Namen per `PUT` angelegt; eine Änderung oder Löschung trägt `If-Match` mit
dem zuletzt gesehenen ETag. Hat sich die Karte zwischendurch auf dem Server geändert, gewinnt die
Version des Servers: Das Adressbuch wird neu gelesen, und die hier gemachte Änderung wird verworfen,
mit einer Warnung im Log, die den Kontakt nennt. Zwei Versionen einer vCard Feld für Feld
zusammenzuführen ist eine eigene Funktion, und wer sie falsch macht, beschädigt ein Adressbuch.

Eine Karte, die der Server rundweg ablehnt (`403`, meist aus einem Adressbuch, das nicht gesagt hat,
dass es schreibgeschützt ist), wird genauso behandelt: Die Kopie des Servers bleibt, die abgelehnte
Änderung landet im Log, und eine einzelne Karte kann den Rest des Adressbuchs nicht am Synchronisieren
hindern. Jeder andere Fehler — der Server nicht erreichbar, sein Speicher voll oder das Passwort
abgelehnt — lässt die Änderung ausstehend, und sie wird bei einer späteren Synchronisierung erneut
gesendet.

**Was plMail nicht anzeigt, rührt es nicht an.** Die Kontakte-Seite bearbeitet Name,
E-Mail-Adressen, Telefonnummern, Organisation und Notiz. Eine Karte trägt viel mehr — ein Foto,
einen Geburtstag, Postanschriften, Eigenschaften, mit denen ein Handy sie an eine App bindet — und
all das bleibt erhalten: Die Änderung wird auf die vCard angewendet, die der Server geschickt hat,
nicht auf eine aus diesen fünf Feldern neu gebaute Karte. Eine Adresse, die auf der Karte bleibt,
behält ihren Typ (Arbeit, privat) und ihre Gruppierung.

Schreibgeschützte Bücher — ein geteiltes Buch, das globale Adressbuch eines Servers — werden
gespiegelt und vorgeschlagen, lassen sich hier aber nicht bearbeiten. Ob ein Buch Schreibzugriffe
annimmt, wird beim Server erfragt (`current-user-privilege-set`), nicht angenommen.

## Vorschläge beim Schreiben

Der Editor und JMAP `Contact/autocomplete` lesen eine gereihte Liste, in dieser Reihenfolge:

1. wie oft die Adresse in deinen E-Mails vorkam;
2. dann, ob sie in einem Adressbuch steht;
3. dann, wie kürzlich sie gesehen wurde, dann der Name.

Eine Adresse, der du oft schreibst, kommt also weiterhin zuerst, und ein Kontakt aus dem
Adressbuch, dem du nie geschrieben hast, kommt vor einer Adresse, die du einmal in einen Entwurf
getippt hast. Eine Adresse, die an beiden Stellen steht, wird einmal vorgeschlagen, unter dem Namen
aus deinem Adressbuch. Tippst du einen Teil eines Namens, bekommst du jede Adresse dieser Karte
angeboten; tippst du einen Teil einer Adresse, nur diese Adresse.

## Fallstricke

**Kalender- und Kontakte-Verbindung sind getrennt.** Eine unter Kalender verbundene Nextcloud
bringt ihre Kontakte nicht mit; verbinde sie unter Kontakte noch einmal.

**Eine hier gemachte Änderung kann an eine anderswo gemachte verloren gehen.** Wurde derselbe
Kontakt zwischen zwei Synchronisierungen auf einem Handy geändert, gewinnt die Version des Handys,
und die Änderung hier wird verworfen (und geloggt). Das Fenster ist die Zeit zwischen dem Speichern
und dem nächsten Lauf des Workers, meist Sekunden.

**Ein Adressbuch abzuwählen oder den Server zu trennen verwirft noch nicht gesendete Änderungen.**
Die Kopien hier verschwinden; der Server behält seine eigenen. Zeigt ein Kontakt die Uhr, warte,
bis sie verschwindet.

**Ein Server im LAN wird standardmäßig abgelehnt**, wie bei CalDAV. Trag den Host in
`INTEGRATIONS_ALLOWED_HOSTS` ein.

**Die Seite zeigt die ersten 300 Kontakte.** Die Suche grenzt ein; vor der Suche und den
Vorschlägen ist nichts verborgen.

**Einen Kontakt zwischen Adressbüchern zu verschieben wird nicht angeboten.** Auf dem Server ist
das eine Löschung in einer Sammlung und eine Neuanlage in einer anderen; mach es in der Oberfläche
des Servers selbst.
