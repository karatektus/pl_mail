<!-- translated-from: providers/carddav.md sha1:bc3ce68500077b393156115361373684803ffdbc -->
# CardDAV

CardDAV ist das Standardprotokoll für Adressbücher auf einem Server, und plMail spricht es mit jedem
Server, der RFC 6352 umsetzt: Nextcloud, Radicale, Baïkal, Fastmail, iCloud. Meist ist es derselbe
Server wie bei [CalDAV](caldav.md), mit demselben App-Passwort. Du verbindest ihn in deinen eigenen
Einstellungen; ein Schritt für den Administrator ist nicht nötig.

Die Kontakte eines gespiegelten Adressbuchs kommen zu deinen [Kontakten](../features/contacts.md),
neben denen, die nur in plMail liegen. Dort listest, durchsuchst, bearbeitest, führst zusammen und
löschst du sie wie jeden anderen, und jede Änderung dort wird an den Server zurückgeschrieben.
Außerdem werden sie dir vorgeschlagen, wenn du eine Nachricht adressierst.

## Einen Server verbinden

**Einstellungen → Kontakte → CardDAV-Server verbinden.** Dasselbe Formular wie bei den Kalendern,
mit denselben vier Feldern in derselben Reihenfolge — Serveradresse, Name, Benutzername,
App-Passwort — und derselben bewusst ausgeschalteten Option, das gespeicherte Passwort eines
E-Mail-Kontos wiederzuverwenden. Was jedes Feld erwartet, steht unter
[Einen Server verbinden](caldav.md#einen-server-verbinden) auf der CalDAV-Seite; alles dort gilt
auch hier.

**Nextcloud braucht keine CardDAV-Verbindung.** Eine Nextcloud, die unter **Einstellungen →
Integrationen** für Dateien verbunden ist, steht auch unter Einstellungen → Kontakte, mit einer
eigenen Schaltfläche **Adressbücher wählen**, mit demselben App-Passwort. Statt einer
Schaltfläche zum Trennen steht dort *Unter Integrationen eingerichtet*, weil die Verbindung auch
deine Dateien und Kalender hält; trennst du sie dort, gehen ihre Adressbücher mit, und die
Rückfrage sagt, wie viele. Ihre Adressbücher werden unter `/remote.php/dav` gesucht, wie
[ihre Kalender](caldav.md#nextcloud-keine-zweite-verbindung). Eine CardDAV-Verbindung, die du von
Hand auf eine Nextcloud gerichtet hast, funktioniert weiter, und deren Kontakte-App zeigt unter
**Kontakte → Einstellungen** eine Adresse pro Buch, falls du nur auf ein einzelnes Buch zeigen
willst. Ein Adressbuch, das schon über eine der beiden gespiegelt wird, steht in der Liste der
anderen als *Wird bereits über … gespiegelt* und lässt sich dort nicht ankreuzen, damit seine
Kontakte nicht doppelt erscheinen.

Speichern verbindet sofort und listet dann die Adressbücher, die der Server anbietet, mit
Kästchen zum Anhaken. Angehakte Bücher werden gespiegelt; ihre erste Synchronisierung wird sofort
eingereiht, sodass die Kontakte innerhalb einer Minute erscheinen und nicht erst beim nächsten
Durchlauf.

**Für jeden anderen Server ist es eine eigene Verbindung, getrennt von CalDAV**, auch auf
demselben Server. Manche Server bieten nur eines von beiden, und manche Menschen haben Kontakte und
Kalender an verschiedenen Orten. Ein Radicale oder Baïkal für beides zu verbinden heißt, das
App-Passwort zweimal einzugeben.

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

**Was du hier änderst, wird sofort gespeichert und danach gesendet.** Der Kontakt wird als
ausstehend markiert (die Kontaktliste zeigt eine kleine Uhr an ihm), und ein paar Sekunden später
schickt ihn der Worker über die Warteschlange `export`, zusammen mit der Mail, die plMail verlässt.
Beim Senden wird der Server nicht gelesen: Änderungen von anderswo kommen mit der nächsten
Synchronisierung. Ist der Server nicht erreichbar, bleibt die Änderung ausstehend, und diese
Synchronisierung sendet sie zuerst. Jede Synchronisierung sendet ausstehende Änderungen, *bevor* sie
den Server liest, damit die ältere Kopie des Servers eine Änderung nicht auf dem Weg hinaus
überschreibt.

**Jeder Schreibzugriff ist bedingt.** Ein neuer Kontakt wird mit `If-None-Match: *` unter einem aus
seiner UID abgeleiteten Namen per `PUT` angelegt; eine Änderung oder Löschung trägt `If-Match` mit
dem zuletzt gesehenen ETag. Hat sich die Karte zwischendurch auf dem Server geändert, gewinnt die
Version des Servers: Das Adressbuch wird neu gelesen, und die hier gemachte Änderung wird verworfen,
mit einer Warnung im Log, die den Kontakt nennt. Zwei Versionen einer vCard Feld für Feld
zusammenzuführen ist eine eigene Funktion, und wer sie falsch macht, beschädigt ein Adressbuch.

Eine Karte, die der Server rundweg ablehnt (`403`, meist aus einem Adressbuch, das nicht gesagt hat,
dass es schreibgeschützt ist), wird genauso behandelt: Die Kopie des Servers bleibt, die abgelehnte
Änderung landet im Log, und eine einzelne Karte kann den Rest des Adressbuchs nicht am
Synchronisieren hindern. Jeder andere Fehler — der Server nicht erreichbar, sein Speicher voll oder
das Passwort abgelehnt — lässt die Änderung ausstehend, und sie wird bei einer späteren
Synchronisierung erneut gesendet.

**Was plMail nicht anzeigt, rührt es nicht an.** Ein Kontakt hat hier jedes Feld, das unter
[Kontakte](../features/contacts.md#ein-kontakt) steht, und eine Karte kann mehr tragen, etwa
Eigenschaften, mit denen ein Handy sie an eine App bindet. All das bleibt erhalten. plMail
behält die vCard, die der Server geschickt hat, und wendet eine Änderung auf sie an, wobei nur die
geänderten Eigenschaften angefasst werden. Es baut die Karte nicht aus seinen eigenen Feldern neu.
Eine Adresse oder Nummer, die auf der Karte bleibt, behält ihren Typ (Arbeit, privat), ihre
Gruppierung und die Schreibweise des Servers.

Die Felder werden in jeder Schreibweise gelesen, die gängige Programme schreiben — Apples gruppierte
`X-ABDATE`, `X-ABRELATEDNAMES` und `X-ABLabel`, vCard 4s `ANNIVERSARY` und `RELATED`, `IMPP` und die
ältere `X-JABBER`-Familie, Apples 1604 für einen Geburtstag ohne Jahr —, und eine hier geänderte
Liste wird so geschrieben, wie die meisten sie lesen. Das Foto geht in beide Richtungen: Ein hier
hochgeladenes oder entferntes ersetzt das der Karte, und eines, das hier nicht geändert wurde, bleibt
genau so auf der Karte.

**Einen Kontakt zu löschen löscht seine Karte.** Ein Kontakt, den du hier in den Papierkorb legst,
wird bei der nächsten Synchronisierung auf dem Server gelöscht und bleibt im Papierkorb als einer,
der nur in plMail liegt. Führst du Duplikate zusammen, wird die Karte des behaltenen Kontakts
aktualisiert, und die Karten der zusammengeführten werden gelöscht.

Schreibgeschützte Bücher, etwa ein geteiltes Buch oder das globale Adressbuch eines Servers, werden
gespiegelt und vorgeschlagen, lassen sich hier aber nicht bearbeiten, löschen oder zusammenführen.
Ob ein Buch Schreibzugriffe annimmt, wird beim Server erfragt (`current-user-privilege-set`), nicht
angenommen.

## Vorschläge beim Schreiben

Der Editor und JMAP `Contact/autocomplete` lesen eine gereihte Liste, in dieser Reihenfolge:

1. wie oft die Adresse in deinen E-Mails vorkam;
2. dann, ob sie in deinen Kontakten steht (nur in plMail oder in einem synchronisierten Buch);
3. dann, wie kürzlich sie gesehen wurde, dann der Name.

Eine Adresse, der du oft schreibst, kommt also weiterhin zuerst, und ein Kontakt, dem du nie
geschrieben hast, kommt vor einer Adresse, die du einmal in einen Entwurf getippt hast. Eine
Adresse, die an beiden Stellen steht, wird einmal vorgeschlagen, unter dem Namen aus deinen
Kontakten. Tippst du einen Teil eines Namens, bekommst du jede Adresse dieses Kontakts angeboten;
tippst du einen Teil einer Adresse, nur diese Adresse.

## Fallstricke

**Kalender- und Kontakte-Verbindung sind getrennt.** Eine unter Kalender verbundene Nextcloud
bringt ihre Kontakte nicht mit; verbinde sie unter Kontakte noch einmal.

**Eine hier gemachte Änderung kann an eine anderswo gemachte verloren gehen.** Wurde derselbe
Kontakt zwischen zwei Synchronisierungen auf einem Handy geändert, gewinnt die Version des Handys,
und die Änderung hier wird verworfen (und geloggt). Das Fenster ist die Zeit zwischen dem Speichern
und dem nächsten Lauf des Workers, meist Sekunden.

**Ein Adressbuch abzuwählen oder den Server zu trennen verwirft noch nicht gesendete Änderungen.**
Die Kopien hier verschwinden, mit jedem Foto, das du hier zu ihnen hochgeladen hast; der Server
behält seine eigenen. Zeigt ein Kontakt die Uhr, warte, bis sie verschwindet.

**Ein Server im LAN wird standardmäßig abgelehnt**, wie bei CalDAV. Trag den Host in
`INTEGRATIONS_ALLOWED_HOSTS` ein.

**Einen Kontakt zwischen Adressbüchern zu verschieben wird nicht angeboten.** Auf dem Server ist
das eine Löschung in einer Sammlung und eine Neuanlage in einer anderen; mach es in der Oberfläche
des Servers selbst.
