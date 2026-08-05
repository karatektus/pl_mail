<!-- translated-from: providers/caldav.md sha1:ccfc56f41acd4b8b77775fff1716870993c07d8e -->

# CalDAV

CalDAV ist das Standardprotokoll für Kalender auf einem Server, und plMail spricht es mit jedem
Server, der RFC 4791 umsetzt: Nextcloud, Radicale, Baïkal, Fastmail, iCloud, eine Synology-Kiste im
Schrank. Es gibt nirgends etwas zu registrieren und keinen Administrationsschritt — eine Person
verbindet einen Server aus den eigenen Einstellungen heraus, mit einer Adresse, einem Benutzernamen
und einem Passwort.

**Gmail- und Outlook-Kalender kommen nicht auf diesem Weg herein.** Sie kommen mit dem Mail-Konto,
auf der Berechtigung, die es ohnehin schon hält, und brauchen nur das Kalenderrecht in der
App-Registrierung — siehe [Google](google.md) und [Microsoft](microsoft.md). plMails eigener
Verbindungsbildschirm sagt das, weil das Erste, was jemand versucht, das Zeigen auf Googles
CalDAV-Endpunkt ist.

Was nach dem Verbinden eines Servers passiert — anhaken, welche Kalender gespiegelt werden,
Zwei-Wege-Synchronisation, schreibgeschützte Kalender —, steht in
[Verbundene Kalender](../features/calendar-sync.md).

## Einen Server verbinden

**Einstellungen → Kalender → CalDAV-Server verbinden.** Vier Felder, in der Reihenfolge, in der die
Fragen tatsächlich auftauchen:

**Serveradresse.** Die CalDAV-Adresse, die dein Server dir zeigt, oder einfach dessen Domain. Beides
funktioniert, und der Unterschied liegt nur darin, wie viele Anfragen die Suche braucht. Nextcloud
zeigt die Adresse unter **Kalender → Einstellungen → „Primäre CalDAV-Adresse kopieren“**, und sie
endet meist auf `/remote.php/dav`. Es ist nachdrücklich *nicht* die Adresse der Weboberfläche, und
für diesen Fehler ist die Fehlermeldung geschrieben: *„Enter the CalDAV address your server shows
you — often something like `…/remote.php/dav` — rather than the address of its web interface."*

**Name.** Wie diese Verbindung in deinen Einstellungen heißen soll. Nur du siehst ihn.

**Benutzername.** Der Kontoname, unter dem der Server dich kennt. Auf manchen Servern ist das deine
E-Mail-Adresse und auf anderen ein kurzer Anmeldename; CalDAV hat dazu keine Meinung, das ist also
das, was die Dokumentation des jeweiligen Servers sagt.

**App-Passwort.** Leg eines auf deinem Server an, statt dein Anmeldepasswort zu verwenden.
App-Passwörter lassen sich einzeln widerrufen und funktionieren neben einer
Zwei-Faktor-Authentifizierung, und iCloud und Fastmail akzeptieren nichts anderes. Wo du eines
anlegst:

| Server | Wo |
|---|---|
| Nextcloud | Einstellungen → Sicherheit → Geräte & Sitzungen |
| iCloud | appleid.apple.com |
| Fastmail | Settings → Password & Security |

Es gibt ein fünftes Bedienelement, **„Das Passwort eines meiner E-Mail-Konten verwenden“**, und es
ist standardmäßig aus und bleibt aus, solange du es nicht bewusst anhakst. Es existiert für den Fall,
dass der CalDAV-Server und ein Postfach dasselbe Konto sind — ein Fastmail-Konto oder eine selbst
gehostete Installation —, und es ist aus einem Grund nicht die Voreinstellung, den zu lesen sich vor
dem Anhaken lohnt: Die Adresse oben hast du eingetippt und niemand hat sie gegen irgendwas geprüft,
ein Passwort dorthin zu schicken, das du plMail für dein Postfach gegeben hast, ist also eine
Entscheidung und keine Selbstverständlichkeit. Die meisten Server wollen ohnehin ein
anwendungsspezifisches Passwort.

Das Speichern verbindet sofort und bietet dann die gefundenen Kalender an, eine falsche Adresse oder
ein abgelehntes Passwort wird also am Formular gemeldet statt später entdeckt.

## Die Suche, und der `.well-known`-Tanz

plMail findet deine Kalender so, wie RFC 6764 es vorschreibt, mit einer bewussten Abweichung.

Die Adresse, die du eingefügt hast, wird **zuerst** versucht, genau so, wie sie dasteht, sobald sie
etwas Spezifischeres benennt als einen bloßen Host. Das ist die Abweichung, und sie ist wichtig, weil
das, was Menschen einfügen, meistens keine Domain ist: Jeder Kalender-Client zeigt irgendwo eine
„CalDAV-URL", und je nach Client ist das die Serverwurzel, das Principal, das Kalender-Zuhause oder
ein einzelner Kalender. Alle vier tauchen hier auf, und `.well-known` zuerst zu versuchen hieße, eine
korrekte, spezifische URL durch das zu ersetzen, wohin die Startseite des Servers umleitet — was bei
Shared Hosting die Marketingseite von irgendjemandem ist.

Nur wenn die eingefügte Adresse plMail nichts beibringt, läuft der übliche Bootstrap:

1. `PROPFIND` auf `/.well-known/caldav` am Origin der Adresse, und weiterverfolgen, wohin es zeigt.
2. `current-user-principal` aus der Antwort lesen und darauf ein `PROPFIND` nach `calendar-home-set`.
3. Das Zuhause auflisten.

Jede Sondierung fragt `resourcetype`, `displayname`, `current-user-principal` und `calendar-home-set`
gemeinsam ab, denn ein Server, der alle vier in einem Umlauf beantworten kann, spart zwei — und die
meisten können das. Wird auch `.well-known` nicht ausgeliefert, wird zuletzt der Origin selbst
versucht: Ein Server, der weder die Well-known-URI ausliefert noch einen brauchbaren Pfad bekommen
hat, kann den Bootstrap trotzdem an seiner Wurzel beantworten, und das ist eine Anfrage statt eines
Supporttickets.

Weiterleitungen werden von Hand verfolgt, bis zu drei Sprünge weit, und jeder Sprung wird erneut
geprüft, bevor er angefragt wird. Ein Weiterleitungsziel ist eine URL, die der *Server* gewählt hat,
und einer davon in eine private Adresse zu folgen ist der gesamte SSRF-Angriff, die Prüfung wird also
wiederholt statt darauf vertraut, dass sie von einem Host kam, der sie bestanden hat.

Ein `403`, `404`, `405` oder `501` an irgendeiner Stelle heißt „hier ist nichts, nächste Adresse
versuchen" — reichlich Server verweigern ein `PROPFIND` auf die Web-Wurzel und liefern CalDAV unter
`/dav` einwandfrei aus. Ein **401** wird anders und mit Absicht behandelt: Der ist wirklich deine
Zugangsdaten, und er zeigt sich als er selbst, statt drei Schritte später als „kein Kalenderdienst
gefunden" gemeldet zu werden.

## Adressen, die plMail ablehnt

Eine CalDAV-Adresse ist etwas, das eine Person eintippt, und plMail ruft sie serverseitig ab, aus
einem Container heraus, der im selben Netz sitzt wie die eigene Datenbank und die eigenen Worker. Die
Adresse geht deshalb durch denselben Wächter wie jede andere von Nutzerinnen und Nutzern angegebene
Serveradresse:

- Das Schema muss `http` oder `https` sein.
- `http://` wird abgelehnt, sofern `INTEGRATIONS_ALLOW_HTTP` nicht an ist.
- Ein in die URL eingebetteter Benutzername oder ein eingebettetes Passwort wird rundweg abgelehnt —
  es landete überall dort im Log, wo die URL landet, und würde stillschweigend die Zugangsdaten der
  Verbindung überschreiben.
- Alles, was auf Loopback, Link-local oder einen privaten Bereich auflöst, wird abgelehnt, sofern der
  Host nicht in `INTEGRATIONS_ALLOWED_HOSTS` steht.

Die letzte Regel ist die, der Selbsthostende begegnen. Ein Nextcloud in deinem LAN unter
`192.168.1.10` oder `nextcloud.lan` wird abgelehnt, bis du es erlaubst, und ein Nextcloud ohne
Zertifikat braucht zusätzlich das HTTP-Flag. Beide stehen in der
[Konfigurationsreferenz](../install/configuration.md), und beide kosten etwas: Die Sperre privater
Bereiche ist das, was jemanden davon abhält, plMail auf den eigenen Datenbank-Container oder auf
einen Cloud-Metadaten-Endpunkt zu richten, und einfaches HTTP zu erlauben heißt, dass ein
App-Passwort im Klartext durch dein Netz geht. Einen einzelnen Host in `INTEGRATIONS_ALLOWED_HOSTS`
zu benennen ist sehr viel enger, als die Sperre abzuschalten, und deshalb ist es eine Liste und kein
Schalter.

## Synchronisation, und was tatsächlich getestet ist

Für CalDAV gibt es keinen Push. Das Protokoll hat keinen Benachrichtigungsmechanismus, für den sich
plMail registrieren könnte, verbundene Kalender werden also vom geplanten Durchlauf alle fünfzehn
Minuten gelesen — was hier auch der einzige Mechanismus ist und kein Rückfall, und deshalb erwähnt
nichts auf dieser Seite eine öffentliche Adresse oder eine Callback-URL.

Änderungen werden auf eine von zwei Arten gelesen, und die Entscheidung fällt, indem der Server
gefragt wird, statt zu wissen, welcher Server es ist:

**`sync-collection` (RFC 6578), wo der Server es ankündigt.** Ein Report mit dem gespeicherten Token
kommt zurück mit dem, was sich geändert hat, und mit einem `404`-Status für alles Entfernte. Es ist
hier der einzige Mechanismus, der eine Löschung inkrementell ausdrücken kann, und wird deshalb
überall bevorzugt, wo es ihn gibt.

**`getctag` plus eine Kalenderabfrage, wo es ihn nicht gibt**, und das ist kein seltener Rückfall —
Radicale, ältere Baïkal-Versionen und etliche Appliance-Server kündigen überhaupt kein
`sync-collection` an. Das ctag ist ein Wert für die gesamte Sammlung: unverändert heißt, dass sich
nirgends etwas geändert hat, was die Antwort auf die meisten Abfragen ist und eine Anfrage kostet.
Hat es sich *doch* bewegt, liest plMail den Kalender von Grund auf neu, statt der Auflistung zu
trauen, denn eine Kalenderabfrage sagt, was existiert, und nichts darüber, was gelöscht wurde.

Ob ein Kalender Änderungen annimmt, wird ebenfalls gefragt, über `current-user-privilege-set`, statt
angenommen. Eine Sammlung, die du nur lesen kannst, wird schreibgeschützt gespiegelt und in der
Oberfläche als solche gekennzeichnet.

**Es gibt nirgends im CalDAV-Treiber Verzweigungen nach Hersteller.** Das ist das Entwurfsprinzip, und
das ist hier auch die Bedeutung von „getestet": Fähigkeiten werden sondiert, nicht nachgeschlagen,
ein Server, von dem niemand der Beteiligten je gehört hat, funktioniert also an dem Tag, an dem du
plMail auf ihn zeigst. Die im Code als beabsichtigte Ziele genannten Server sind Nextcloud, Radicale, Baïkal,
Fastmail, iCloud und Synology. Die automatisierte Testsuite deckt die Protokollverhalten ab statt der
Produkte — beide Mechanismen zum Lesen von Änderungen, die Formen der Suche einschließlich der
Radicale-typischen Sammlung ohne `displayname`, sowie seitenweise und abgeschnittene Antworten —, und
das ist die Ebene, auf der Server sich tatsächlich unterscheiden.

Jede Anfrage weist sich als `plMail-CalDAV/1.0` aus. Das ist keine Dekoration: Etliche Server,
Radicale darunter, verweigern Anfragen von einem Client, der sich nicht benennt, oder behandeln sie
falsch — und ein Supportverlauf über eine scheiternde Synchronisation beginnt beim Zugriffslog des
Servers.

## Fallstricke

**Die Adresse der Weboberfläche ist nicht die CalDAV-Adresse.** `https://cloud.example.com`
funktioniert zufällig, weil plMail von der Domain aus bootstrappen wird, aber die URL, die du aus
einem Browser-Tab kopiert hast, während du auf deinen Kalender geschaut hast, mit ziemlicher
Sicherheit nicht. Nimm die Adresse, die der Server zum Kopieren anbietet.

**Ein 401 sind deine Zugangsdaten und wird auch so gemeldet**, ein 403 auf die Web-Wurzel aber nicht —
plMail behandelt ihn als „hier nicht" und sucht weiter. „Kein Kalenderdienst hat geantwortet" kann
also einen tatsächlich falschen Pfad bedeuten *oder* einen Server, der CalDAV nur unter einem
Unterpfad ausliefert, den du ihm nicht gegeben hast.

**Ein Server im LAN wird standardmäßig abgelehnt.** Das ist der SSRF-Wächter bei der Arbeit und kein
Fehler. Trag den Host in `INTEGRATIONS_ALLOWED_HOSTS` ein, und setz `INTEGRATIONS_ALLOW_HTTP` nur
dann, wenn der Server wirklich kein Zertifikat hat.

**Das Passwort eines Mail-Kontos mitzubenutzen schickt es an eine Adresse, die du eingetippt hast.**
Aus diesem Grund ist das Häkchen standardmäßig aus. Wenn der Server ein App-Passwort akzeptieren
würde, nimm eines.

**iCloud und Fastmail weisen Anmeldepasswörter rundweg ab.** Ein anwendungsspezifisches Passwort ist
dort nicht optional, und die Ablehnung sieht genauso aus wie bei einem Tippfehler.

**Es gibt keinen Push, eine anderswo gemachte Änderung braucht also bis zu fünfzehn Minuten, bis sie
erscheint.** Das ist der Entwurf und kein eingeschränkter Zustand; nichts an einer öffentlichen
Adresse oder einem Reverse Proxy ändert daran etwas.

**Einen Server zu trennen entfernt jeden von dort gespiegelten Kalender.** Termine, die vom Server
kamen, sind Kopien und gehen mit ihm; Termine, die nie auf dem Server waren — alles, was plMail aus
deiner Mail auf einen dieser Kalender extrahiert hat —, ziehen vorher in deinen Standardkalender um,
statt gelöscht zu werden.
