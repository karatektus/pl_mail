<!-- translated-from: providers/jmap.md sha1:b6f564e93a149130ffe1f56231dfc32acac25296 -->

# JMAP

JMAP ist das Mailprotokoll, das IMAP wäre, wenn man es heute entwürfe: JSON über HTTPS, mit Mail,
Kalendern und Kontakten hinter einer einzigen Anmeldung. plMail liest über diese eine Verbindung
die Mail, die Kalender und die Kontakte eines JMAP-Kontos, schreibt deine Änderungen zurück auf den
Server, sendet über die eigene Submission des Servers und erfährt von Änderungen, sobald sie
passieren. Stalwart, Fastmail und Cyrus sprechen es alle.

Was plMail mit dem Konto macht, sobald es verbunden ist — Aliase, Einstellungen pro Konto —, steht
unter [Konten und Aliase](../features/accounts.md). Diese Seite handelt vom Verbinden und davon, was
dich erwartet.

## Ein JMAP-Konto hinzufügen

**Einstellungen → E-Mail-Konten → Konto hinzufügen**, auf dem Reiter **IMAP / SMTP**, und unter
**Protokoll für eingehende Mail** **JMAP** wählen.

1. Bei Fastmail wähl es aus der Auswahlliste **Anbieter**: Das trägt seinen JMAP-Server, seine
   Session-URL und den Haken für das API-Token ein. Sonst **E-Mail-Adresse** und **Benutzername** wie
   bei IMAP. Bei Stalwart ist der Benutzername meist die Adresse.
2. **Passwort** ist ein App-Passwort. Bei Fastmail ist es stattdessen ein API-Token: Setz den Haken
   bei **Das Passwort ist ein API-Token**, dann wird der Benutzername nicht mitgeschickt.
3. **Eingehend — JMAP** nimmt den Hostnamen des Servers. Der Port startet bei 443, und eine
   Verschlüsselung gibt es nicht zu wählen: JMAP ist HTTPS. plMail sucht die Session unter
   `https://host/.well-known/jmap`, wo Server sie veröffentlichen; nur wenn deine woanders liegt —
   etwa hinter einem Reverse Proxy, der nur `/jmap` weiterreicht —, holt **Die JMAP-Session meines
   Servers liegt woanders** das Feld **Session-URL** hervor.
4. Unter **Ausgehend — JMAP** ist nichts auszufüllen. Ein Server, der JMAP-Submission anbietet,
   versendet deine Mail selbst; der Verbindungstest sagt dann **Gesendet wird über den JMAP-Server**.
   Nur für einen Server, der das nicht kann, holt **Mein Server versendet nicht über JMAP — SMTP
   verwenden** die SMTP-Felder zurück — und der behält selbst eine Kopie dessen, was du sendest.
5. **Verbindung testen** holt die Session, zählt die Postfächer und prüft auf Submission.

Speichern, und die erste Synchronisation beginnt. Zuerst kommt der Posteingang, die neueste Mail
zuerst, danach alles andere; Mail, die in der Zwischenzeit eintrifft, wird davon nicht aufgehalten.

## Was synchronisiert wird

**Mail.** Ein JMAP-Postfach ist in plMail ein Label, und eine Nachricht kann in mehreren zugleich
liegen, wie bei Gmail. Posteingang, Gesendet, Entwürfe, Papierkorb, Spam und Archiv sind die
System-Labels; jedes andere Postfach ist ein Label unter seinem Pfad. Lesen, Markieren, Ablegen,
Archivieren und Löschen hier wird auf den Server zurückgeschrieben, und dasselbe in einem anderen
Programm erreicht plMail bei der nächsten Änderung. Labels, die du in plMail anlegst, umbenennst oder
löschst, sind Postfächer, die auf dem Server angelegt, umbenannt oder gelöscht werden. Jede Nachricht
wird aus der Kopie des Originals gespeichert, die der Server hat, und sieht deshalb genauso aus wie
über IMAP.

**Kalender und Kontakte.** Ein Server, der JMAP-Kalender oder -Kontakte anbietet, bietet sie
derselben Anmeldung an, also gibt es nichts weiter zu verbinden: Unter **Einstellungen → Kalender**
und **Einstellungen → Kontakte** bietet das Konto seine Kalender und Adressbücher an wie ein Google-
oder Microsoft-Konto. Termine und Kontakte werden im eigenen Format des Servers gelesen und
zurückgeschrieben, sodass Teilnehmende, Erinnerungen und Fotos den Weg hin und zurück überstehen.

**Push.** **Sofortige Zustellung** ist bei einem neuen JMAP-Konto an. plMail hält eine Verbindung zum
Server offen, und der Server sagt Bescheid, sobald sich etwas ändert — Mail, Kalender oder Kontakte.
Hat plMail eine öffentliche HTTPS-Adresse (`APP_PUBLIC_URL`, siehe
[Konfiguration](../install/configuration.md)), bittet es den Server außerdem, es stattdessen
zurückzurufen, und sobald der Server bewiesen hat, dass er das kann, wird die offene Verbindung für
dieses Konto geschlossen. So oder so kommen Änderungen innerhalb von Sekunden an, mit dem
Viertelstunden-Abgleich dahinter.

## Von IMAP umstellen

Ein Server, der beides spricht — Stalwart tut es —, lässt sich im Bearbeiten-Formular des Kontos
von IMAP auf JMAP umstellen. Seine Mail bleibt: Die erste JMAP-Synchronisation erkennt jede
Nachricht, die plMail schon hat, an ihrer Message-ID und speichert sie nicht noch einmal. Zurück
geht es genauso.

## Ein anderes plMail

plMail ist selbst ein JMAP-Server, also kann ein plMail die Mail eines anderen als Konto führen. Lege
auf der anderen Instanz unter **Einstellungen → App-Passwörter** ein App-Passwort an; füge auf dieser
ein Konto hinzu und wähl **plMail** aus der Auswahlliste **Anbieter**, das stellt auf JMAP und
überlässt dir den Host. Gib deine Adresse dort als Benutzername an, das App-Passwort als Passwort und
den Hostnamen der anderen Instanz. Mail, Labels, Senden, Kalender, Kontakte und Push funktionieren wie
bei jedem JMAP-Server. Die eigenen Kontakte des anderen plMail erscheinen als Adressbuch **plMail
(deine Adresse dort)**, neben allen, die es von anderswo spiegelt.

## Fallstricke

**Ein JMAP-Server in deinem eigenen Netz wird abgewiesen, bis ein Admin ihn erlaubt.** JMAP ist
HTTP, und plMail lässt eine in ein Formular getippte Adresse nicht ins private Netz, solange der Host
nicht in `INTEGRATIONS_ALLOWED_HOSTS` steht — dieselbe Regel wie für einen CalDAV-Server im LAN,
siehe [den SSRF-Schutz](../install/configuration.md#der-ssrf-schutz). Der Verbindungstest sagt das
genau so. Für IMAP gibt es diese Regel nicht, weil sich über eine IMAP-Verbindung nichts anderes
abrufen lässt.

**Ohne den JMAP-Listener hält niemand die Push-Verbindung.** Das ist `app:jmap:listen`, einer
der Prozesse des Worker-Containers. Mail kommt weiterhin nach Zeitplan an, also lautet das Symptom „Änderungen
kommen immer ein paar Minuten zu spät“ und nicht etwas, das kaputt aussieht.

**Ein geteiltes Postfach, das dir ein anderes Konto zeigt, wird nicht synchronisiert.** plMail
synchronisiert das eigene Konto der Anmeldung auf dem Server, nicht andere Konten, auf die sie
Zugriff hat.

**Archiv wird angelegt, wenn der Server keins hat.** Archivieren nimmt eine Nachricht aus dem
Posteingang, und ein JMAP-Server lässt keine Nachricht in gar keinem Postfach liegen; die erste
Archivierung auf so einem Server legt deshalb ein Postfach namens Archive an. Gibt es schon einen
Ordner dieses Namens auf oberster Ebene, wird stattdessen er verwendet.

**Manche Server schicken die Kopfzeilen einer Nachricht noch einmal als Anfang ihres Textes.** Bei
Stalwart wurde das über IMAP beobachtet; plMail entfernt eine exakte Wiederholung des Kopfblocks,
egal über welches Protokoll die Nachricht kam.
