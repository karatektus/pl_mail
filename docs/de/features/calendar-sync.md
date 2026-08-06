<!-- translated-from: features/calendar-sync.md sha1:2035fa53b2757d4eef5706a37a0d6fab6f70ef92 -->

# Verbundene Kalender

plMail kann Kalender spiegeln, die du anderswo führst — Google, Outlook, einen CalDAV-Server oder
alles, was unter einer Adresse als `.ics` veröffentlicht ist — und für die ersten drei deine
Änderungen zurücksenden. Außerdem kann es einen Kalender als Datei herein- und hinausbewegen.

Alles auf dieser Seite steht unter **Einstellungen → Kalender**, das aus zwei Hälften besteht: der
Liste deiner Kalender und **Woher Kalender kommen** darunter.

Die anbieterspezifische Einrichtung — welche Konsole, welche Client-ID, welche Scopes — hat eigene
Seiten: [Google](../providers/google.md), [Microsoft](../providers/microsoft.md),
[CalDAV](../providers/caldav.md), [ICS-Feeds](../providers/ics-feeds.md).

## Woher Kalender kommen

Zwei Populationen, die auf dem Bildschirm wie eine aussehen und sich darunter in nichts gleichen.

**E-Mail-Konten, die ohnehin Kalender führen.** Ein Gmail- oder Outlook-Konto, das du für Mail
hinzugefügt hast, sieht dort auch deine Kalender, weil plMail den Kalenderzugriff mit derselben
Zustimmung erfragt wie den Postfachzugriff. Es gibt keine zweite Anmeldung, keine zweite Anwendung zu
registrieren und nichts Zweites einzurichten — das Konto zeigt eine Schaltfläche **Kalender auf …
suchen**, und das ist alles.

**Verbindungen, die es nur für Kalender gibt.** Ein **CalDAV-Server** — Nextcloud, Radicale, Baïkal,
Fastmail, iCloud, eine Kiste im Schrank — oder eine **Kalenderadresse**, also jede veröffentlichte
`.ics`.

Sagt der Abschnitt *Noch kein Konto und keine Verbindung hier führt Kalender.*, hast du IMAP-Konten
und sonst nichts; verbinde einen CalDAV-Server oder abonniere eine Adresse.

### Auswählen, welche Kalender gespiegelt werden

**Kalender auf … suchen** listet alles auf, was das Konto oder die Verbindung sehen kann, und bittet
dich, die anzuhaken, die mitgeführt werden sollen. Einen anzuhaken legt hier einen Kalender an und
füllt ihn binnen einer Minute; der bereits mit *Haupt* gekennzeichnete ist der Hauptkalender des
Anbieters.

**Den Haken wegzunehmen löscht die hier gehaltene Kopie.** Der Bildschirm sagt das und ergänzt die
wichtige Hälfte: Was in diesem Kalender aus deiner Mail stammt und nicht vom Kalender, zieht vorher in
deinen Standardkalender um. Diese Population ist real — du darfst die aus der Mail eines Kontos
ausgelesenen Termine auf einen gespiegelten Kalender richten, und von da an landet jede Buchung aus
der Mail dieses Kontos dort als einzige Kopie, die es gibt. Alles, was die Gegenstelle tatsächlich
geliefert hat, wird gelöscht, denn das sind Kopien, und die Originale hält weiterhin der Anbieter;
hak den Kalender wieder an, und sie sind binnen einer Minute zurück.

Dein Standardkalender lässt sich nicht abwählen, solange er der Standard ist: *Hier landen neue
Termine, deshalb bleibt dieser. Mach zuerst einen anderen Kalender zum Standard.*

Nichts, was der Browser abschickt, beschreibt einen Kalender. Gelesen werden nur die angehakten IDs;
der Name, die Farbe, die Zeitzone und — vor allem — ob der Kalender Schreibzugriffe annimmt, werden
beim Abschließen des Abonnements erneut von der Gegenstelle gelesen.

### Einen CalDAV-Server verbinden

**CalDAV-Server verbinden** fragt nach einem Namen, einer Serveradresse, einem Benutzernamen und
einem App-Passwort.

Die Adresse darf die vollständige CalDAV-URL sein, die dein Server anzeigt, oder auch nur dessen
Domain — plMail fragt die Domain, wo ihre Kalender liegen, bevor es aufgibt. Das Formular öffnet mit
der Domain deines eigenen Postfachs vorbelegt, und das ist ein Vorschlag und nichts weiter.

Nimm ein auf dem Server angelegtes **App-Passwort** statt deines Anmeldepassworts; iCloud und
Fastmail akzeptieren nichts anderes. Es gibt ein Ankreuzfeld, das anbietet, das Passwort eines deiner
E-Mail-Konten wiederzuverwenden; es ist voreingestellt aus, und es lohnt sich, es aus zu lassen — die
meisten Server wollen ein anwendungsspezifisches Passwort, und die Adresse im Feld darüber ist eine,
die du eingetippt hast und die nichts geprüft hat; ein Haken dort sendet also ein Passwort, das du
plMail für dein Postfach gegeben hast, an genau den Host, der in diesem Feld steht.

Kalender von Gmail und Outlook kommen nicht auf diesem Weg herein. Sie kommen mit dem E-Mail-Konto.

**Trennen** entfernt jeden von diesem Server gespiegelten Kalender und zieht alles, was aus deiner
Mail stammt, vorher in deinen Standardkalender um, genau wie das Abwählen es tut.

### Eine veröffentlichte Adresse abonnieren

**Adresse abonnieren** nimmt ein einziges Feld: die Adresse, unter der ein Kalender veröffentlicht
ist, endend auf `.ics`. Ein `webcal://`-Link geht ebenso — das ist dieselbe Adresse unter anderem
Namen, und plMail schreibt das Schema für dich um. `webcals://` genauso.

So folgst du Feiertagen, einem Spielplan, der veröffentlichten Verfügbarkeit einer Kollegin oder der
„geheimen Adresse im iCal-Format“, die Google und Outlook für einen Kalender herausgeben, zu dem sie
keinen API-Zugriff gewähren. Lässt du den Namen leer, benennt sich der Kalender selbst.

Zwei Dinge, die das Formular sagt und auch so meint:

- **Ein abonnierter Kalender ist schreibgeschützt.** Er ist die Kopie einer veröffentlichten Datei; es
  gibt keine Methode, die hineinschreiben würde, und keinen Server, der eine annähme.
- **Wer die Adresse hat, kann den Kalender lesen**, behandle eine geheime also wie ein Passwort.
  Nichts zeigt die Adresse wieder an, sobald sie gespeichert ist.

Der Feed wird genau einmal abgerufen, während du wartest, und deshalb hinterlässt ein
fehlgeschlagenes Abonnement nichts — es gibt keine Zugangsdaten zu korrigieren und kein zweites Feld
zu ändern, eine kaputte Zeile, die du bemerken und löschen müsstest, wäre also nur im Weg. Der
Fehlschlag, mit dem zu rechnen ist: Eine Schaltfläche **Abonnieren** kopiert etwa genauso oft einen
Link auf eine *Webseite* wie einen Link auf eine Kalenderdatei, und die beiden sind nicht zu
unterscheiden, bis etwas versucht, eine davon zu lesen.

## Was der Abgleich in beide Richtungen trägt

Für Google, Microsoft und CalDAV wandern Änderungen in beide Richtungen. Was hinübergeht, ist die
Besprechung selbst: Titel, Beginn und Ende, Zeitzone, Ganztägigkeit, Ort, Beschreibung, Status, die
Wiederholungsregel samt jeder gegen sie abgelegten Änderung einzelner Termininstanzen, Teilnehmende
und Erinnerungen. Eine Buchung, die über eine deiner Buchungsseiten hereinkam, und ein Termin, den du
in zwei Kalendern zugleich angelegt hast, wandern wie jeder andere Termin.

Was nicht hinübergeht:

- **Alles darüber, wie plMail ihn zeichnet.** Die Farbe eines Kalenders, seine Sichtbarkeit, seine
  Position in deiner Liste, ob er dein Standard ist — das gehört dir.
- **Woher ein Termin kam.** plMail hält fest, ob ein Termin getippt, aus einer Nachricht gelesen,
  gespiegelt oder gebucht wurde; ein Anbieter hat dafür kein Feld, und nichts trägt es zurück.
- **Anhänge an einem Termin.**

Zwei weitere Grenzen sind anbieterspezifisch und stehen unter [Fallstricke](#fallstricke): Alle
Erinnerungen zu löschen löscht die von Google nicht, und ein abonnierter `.ics`-Feed ist
bauartbedingt eine Einbahnstraße.

### Welche Seite gewinnt

Die Regeln sind kurz, und es lohnt sich, sie zu kennen, denn „Änderungen werden zurückgespielt“ ist
nur etwas wert, wenn man sich darauf verlassen kann.

1. **Deine Änderungen gehen immer zuerst hinaus.** Jede offene lokale Änderung wird geschoben, bevor
   irgendetwas geholt wird. Der anschließende Abruf fragt also eine Gegenstelle „hat sich dort auch
   etwas geändert?“, der bereits Bescheid gesagt wurde — und der Normalfall, dass du hier etwas
   geändert und sonst niemand es angefasst hat, ist überhaupt kein Konflikt.
2. **Eine unveränderte Kopie der Gegenstelle wird nicht angewendet.** Stimmt die Versionsmarke der
   Gegenstelle mit der gespeicherten überein, wird gar nichts geschrieben. Das ist es, was den Abruf
   direkt nach dem Schieben davon abhält, das Echo deiner eigenen Änderung über etwas zu legen, das
   du inzwischen getippt hast.
3. **Eine veränderte Kopie der Gegenstelle gewinnt.** Nicht „die letzte Schreibung gewinnt“ — die
   beiden Seiten teilen keine Uhr, Zeitstempel zu vergleichen hieße also, zwei Vermutungen zu
   vergleichen. Die Gegenstelle gewinnt, weil ihre Kopie diejenige ist, die auch andere Leute sehen:
   Eine Änderung zu verlieren, die du auf dem Telefon gemacht hast, ist behebbar, indem du sie noch
   einmal machst; von dem abzuweichen, worauf eine Organisation und vier Eingeladene schauen, ist es
   nicht.
4. **Haben sich beide Seiten geändert, wird die lokale Änderung verworfen — und zwar laut.** Wegen
   Regel 1 passiert das nur, wenn das Schieben für diesen Termin fehlgeschlagen ist. Die verworfene
   Fassung wird vollständig ins Protokoll geschrieben, bevor sie überschrieben wird, damit es hinterher
   etwas anzusehen gibt.
5. **In einen schreibgeschützten Kalender wird nie geschoben.** Deine Änderung bleibt lokal, und der
   Lauf sagt es einmal.

Die Zeile eines Kalenders in den Einstellungen zeigt, wann er zuletzt abgeglichen wurde, oder *Noch
nicht abgeglichen*, und trägt den letzten Fehler, wenn es einen gab.

## Push, und warum eine selbstgehostete Installation meistens abfragt

Verbundene Kalender bekommen alle fünfzehn Minuten einen Durchlauf. Google und Microsoft können auch
*pushen* — plMail in dem Moment sagen, in dem sich etwas ändert —, und beide Benachrichtigungen sagen
nur „in diesem Kalender hat sich etwas geändert“, ein Webhook tut also genau eines: einen Abgleich
für den Kalender anfordern, den er nennt.

Push braucht eine öffentlich erreichbare **HTTPS**-Adresse, denn dorthin ruft der Anbieter zurück.
plMail prüft das selbst, statt es die Ablehnung des Anbieters werden zu lassen: Die eingestellte
öffentliche URL muss mit `https://` beginnen und darf nicht `localhost`, `127.0.0.1` oder `::1` sein.
Sehr viele selbstgehostete Installationen scheitern daran mit Absicht, und das ist in Ordnung —
**Push ist nie tragend.** Ein Kalender, der keinen Kanal registrieren kann, ist ein funktionierender
Kalender fünfzehn Minuten hinterher, und es gibt keine Konstellation, in der es besser wäre, den
Abgleich zu verweigern, weil sich Push nicht registrieren ließ.

Google verlangt zusätzlich, dass der Rückruf-Host **im Cloud-Projekt bestätigt ist, dem der
OAuth-Client gehört** — bestätige ihn in der Search Console und trag ihn dann unter
Domain-Verifizierung in der Cloud-Konsole ein. Bis das erledigt ist, wird jede Registrierung
abgelehnt. Immerhin ist das sichtbar: eine Warnung im Protokoll zum Zeitpunkt der Registrierung statt
eines Kanals, der stillschweigend nie etwas liefert. Microsoft hat keinen entsprechenden Schritt.
Siehe [Google](../providers/google.md) und [Microsoft](../providers/microsoft.md).

CalDAV-Server und `.ics`-Feeds haben überhaupt kein Push und werden immer abgefragt. Beides ist kein
Problem.

Kanäle werden von einem stündlichen Durchlauf registriert und erneuert und nicht in dem Moment, in
dem du einen Kalender anhakst. Das ist Absicht: Eine Registrierung scheitert aus Gründen, die mit
deinem Klick nichts zu tun haben — noch keine öffentliche Adresse, eine noch offene
Domain-Verifizierung —, und allein an den Abonnement-Bildschirm gebunden bekämen diese Kalender nie
Push, bis jemand auf den Gedanken käme, sie abzubestellen und neu zu abonnieren. Aus einem Durchlauf
getrieben, fängt dieselbe Installation binnen einer Stunde nach Behebung des eigentlichen Problems an
zu pushen, ohne dass jemand etwas anfassen müsste. Einen Kalender anzuhaken fragt einmal sofort nach
und gibt still auf, und genau das nimmt die erste Stunde weg.

Manche Google-Kalender lassen sich nie beobachten — die Feiertage eines Landes, Geburtstage aus den
Kontakten, Kalenderwochen. Google lehnt diese dauerhaft ab, plMail merkt sich die Ablehnung, und der
Kalender liest sich schlicht als abgefragt.

### Von Hand abgleichen

Jede Kalenderzeile hat eine Schaltfläche **Jetzt abgleichen**, die mit *Wird abgeglichen — dieser
Kalender ist gleich aktuell.* antwortet. Vom Terminal aus:

```bash
# jeder verbundene Kalender
docker compose exec php php bin/console app:calendar:sync

# einer davon
docker compose exec php php bin/console app:calendar:sync 12

# nur die fälligen — das ist es, was der Scheduler alle fünfzehn Minuten ausführt
docker compose exec php php bin/console app:calendar:sync --stale
```

Und für Push-Kanäle:

```bash
# registrieren und erneuern, was es braucht — das führt der Scheduler stündlich aus
docker compose exec php php bin/console app:calendar:push

# alles neu registrieren, unabhängig vom Ablauf
docker compose exec php php bin/console app:calendar:push --force

# die Kanäle abbauen und zum Abfragen zurückkehren
docker compose exec php php bin/console app:calendar:push --stop
```

`app:calendar:sync` gibt die Arbeit an einen Hintergrundprozess weiter, statt sie im Konsolenprozess
zu erledigen, ein von Hand gestarteter Abgleich verhält sich also genau wie ein eingeplanter — samt
seiner Wiederholungen. `app:calendar:push` meldet „bleibt beim Abfragen“, statt zu scheitern, aus dem
oben genannten Grund.

## Kalender als Dateien

### Export

Jede Kalenderzeile hat eine Schaltfläche **Als .ics herunterladen**, und jeder Termin hat **.ics
herunterladen** in seinem Editor. Ein exportierter Termin ist die ganze Serie und nicht die eine
Termininstanz, die du angeklickt hast.

Der Download eines ganzen Kalenders wird Besprechung für Besprechung gestreamt, ein Jahrzehnt eines
vollen Kalenders kostet also eine Besprechung Arbeitsspeicher statt zehn Jahren — mit der sichtbaren
Folge, dass der Browser einen unbestimmten Fortschrittsbalken zeigt, weil die Größe erst feststeht,
wenn der letzte Termin geschrieben ist.

Die Datei ist ein Kalenderobjekt und keine Einladung: Es wird bewusst keine `METHOD` geschrieben,
damit das Herunterladen des eigenen Kalenders manche Clients nicht dazu bringt, in deinem Namen
vierhundert Besprechungsanfragen zu verschicken.

### Import

**Kalenderdatei importieren** auf der Einstellungsseite liest eine `.ics` — einen Export aus einem
anderen Kalender oder eine zugesandte Einladung — in einen Kalender deiner Wahl ein. Dateien bis zu
**4 MB**.

Angeboten werden nur Kalender, die neue Termine annehmen. Hast du nichts als gespiegelte Kalender,
sagt die Auswahl das: *Keiner deiner Kalender nimmt neue Termine an. Ein gespiegelter Kalender ist eine
schreibgeschützte Kopie; leg zuerst einen eigenen an.*

Der Import hat eine Regel, die nicht auf der Hand liegt, und das Formular nennt sie:

> Ein Termin, der schon in diesem Kalender steht, wird aktualisiert statt doppelt angelegt; einer, der
> bereits in einem anderen deiner Kalender steht — aus einer Einladung oder aus einem gespiegelten
> Kalender — bleibt, wo er ist.

Also drei Ausgänge, und der mittlere ist der überraschende. Im Zielkalender unter derselben Identität:
aktualisiert, und genau das macht Exportieren-und-wieder-Importieren zu einem Hin und Zurück statt zu
einer Verdopplung. In einem *anderen* deiner Kalender: übersprungen und gezählt, denn du hast diesen
Kalender gewählt, und eine Zeile in einem anderen umzuschreiben ist nicht das, worum du gebeten
hast — am allerwenigsten, wenn der andere eine Spiegelung ist, wo das Umschreiben zum Anbieter
hinausgeschoben würde. Noch nirgends: angelegt.

Das Ergebnis wird als *N hinzugefügt, N aktualisiert, N übersprungen* gemeldet, oder, wenn es nichts
zu tun gab, als *Nichts hinzuzufügen — jeder Termin aus dieser Datei steht schon in deinen Kalendern.*
Eine Datei, die plMail nicht als Kalender lesen kann, sagt das, statt stillschweigend null Termine zu
importieren.

Zwei Dinge übergeht ein Import mit Absicht: die Versionsnummer in der Datei und alles, was du
verworfen hast. Du hast diese Datei gewählt und eine Schaltfläche gedrückt — die Hälfte davon
abzulehnen, weil das exportierende Programm eine niedrigere Versionsnummer geschrieben hat als die
ursprüngliche Einladung, oder ausgerechnet den einen Termin wegzulassen, den du suchtest, weil du ihn
einmal verworfen hast, hieße beides, dass der Import genau dort stillschweigend nichts tut, wo es am
meisten zählt.

## Dieselbe Besprechung, zweimal angekommen

Eine Besprechung kann plMail auf zwei ehrlichen Wegen zugleich erreichen: aus ihrer Einladung
ausgelesen in deinen Standardkalender, und vom Anbieter gespiegelt in einen verbundenen
Kalender. Beide Zeilen sind richtig, und plMail löscht keine von beiden. Die Doppelung wird
stattdessen auf dem Bildschirm beantwortet.

Zwei Einträge gelten als dieselbe Besprechung, wenn sie eine Identität **und** einen Beginn teilen.
Die Identität ist die UID, die die Organisation vergeben hat — und nicht Titel und Zeit, denn eine
Übereinstimmung darin ließe ein wöchentliches Vieraugengespräch, das zur selben Stunde mit zwei
verschiedenen Personen stattfindet, zu einem einzigen Eintrag zusammenfallen, und eine Besprechung,
die stillschweigend aus einem Kalender verschwindet, ist die schlimmste Gestalt, die ein Kalenderfehler
annehmen kann.

Sie werden **nur so lange als ein Eintrag gezeichnet, wie sie sich einig sind** über die fünf Dinge,
die man sehen kann: Beginn, Ende, Titel, Ganztägigkeit und ob abgesagt wurde. Sind sie sich einig,
bekommst du einen Eintrag, der die Farben beider Kalender zeigt, *In Arbeit, Privat* sagt und einen
Editor mit beiden angehakt öffnet. Sind sie sich uneinig, zerfällt die Gruppe und du bekommst je
einen Eintrag — mit Absicht, denn ein zusammengefasster Eintrag, der stillschweigend einen Sieger
kürte, versteckte eine echte Uneinigkeit (eine Aktualisierung, die den einen Weg erreicht hat und den
anderen nicht) hinter einem aufgeräumteren Bildschirm.

Die Wiederholung gehört nicht zu den fünfen. Zwei Kopien, von denen sich die eine wiederholt und die
andere nicht, sind sich über die eine Termininstanz einig, die sie teilen, und über nichts sonst —
diese Instanz wird also zusammengefasst, und die sich wiederholende Kopie zeichnet an jedem späteren
Tag ihre eigenen Einträge, was selbst das sichtbare Zeichen dafür ist, dass die beiden sich
unterscheiden.

## Fallstricke

**Den Haken bei einem Kalender wegzunehmen, löscht die hier gehaltene Kopie.** Es heißt nicht „hör auf
abzugleichen und behalte, was ich habe“. Termine, die die Gegenstelle geliefert hat, gehen; Termine,
die aus deiner Mail stammen, ziehen vorher in deinen Standardkalender um. Ein erneutes Anhaken holt
die Termine der Gegenstelle zurück.

**Eine lokale Änderung, die noch nicht hinausgegangen ist, ist beim Abbestellen verloren.** Ein
Termin, der sowohl eine Fern-ID als auch eine noch nicht hinausgegangene Änderung trägt, wird mit dem
Rest gelöscht, diese Änderung erreicht den Anbieter also nie. Das Fenster ist ein Durchlauf — fünfzehn
Minuten — und es wird protokolliert. Warte einen Abgleich ab, bevor du abbestellst, wenn du gerade
etwas bearbeitet hast.

**Auf Googles Zustimmungsseite lässt sich der Kalenderzugriff abwählen, während Mail erlaubt wird.**
Das Konto synchronisiert danach die Mail einwandfrei und beantwortet jeden Kalenderaufruf mit einer
Ablehnung. Der Bildschirm **Kalender suchen** sagt das, statt eine leere Liste zu zeigen: *Auf Googles
Zustimmungsseite lässt sich der Kalenderzugriff abwählen, während E-Mail erlaubt wird. Verbinde das
Konto neu und lass das Kalenderhäkchen gesetzt.*

**Bei Microsoft kann die Kalenderberechtigung in der App-Registrierung fehlen.** Mail funktioniert
auch ohne sie weiter; Kalender nicht. Derselbe Bildschirm sagt es, und es ist Sache der
Administration — siehe [Microsoft](../providers/microsoft.md).

**Das erste Lesen eines Google-Kalenders reicht ein Jahr zurück, nicht ewig.** Ein Kalender, der seit
einem Jahrzehnt benutzt wird, enthält Zehntausende Termine, und ein unbegrenztes erstes Lesen holte
jeden einzelnen davon, um eine lokale Tabelle mit Besprechungen aus 2016 zu füllen, die keine Ansicht
je zeigen wird. Nach vorn ist es unbegrenzt.

**Ein Microsoft-Kalender wird über ein Fenster abgeglichen und nicht vollständig.** Graph bietet die
Änderungsverfolgung auf genau einer Oberfläche an, und die ist begrenzt: ein Jahr zurück und zwei
Jahre voraus, passend zu dem Bereich, in den plMail Termininstanzen zeichnet. Termine außerhalb dieses
Fensters werden nicht gespiegelt.

**Alle Erinnerungen zu entfernen, löscht die Erinnerungen bei Google nicht.** Siehe
[Erinnerungen](calendar-alerts.md#fallstricke).

**Ein `.ics`-Abonnement lädt die ganze Datei erneut, sobald sie sich geändert hat.** Für eine
veröffentlichte Datei gibt es keinen Änderungs-Feed, und eine Datei sagt, was existiert, und nicht,
was entfernt wurde — ein geänderter Feed wird also von vorn gelesen, und genau das hält abgesagte
Spieltermine davon ab, sich für immer anzusammeln. Unverändert kostet er eine bedingte Anfrage und
überhaupt keinen Rumpf, und deshalb ist es umsonst, einen Feiertags-Feed alle fünfzehn Minuten
abzufragen. Feeds werden bis 8 MB gelesen.

**Ein Import in den falschen Kalender lässt sich hinterher nicht umlenken.** Termine, die schon in
einem anderen deiner Kalender stehen, werden übersprungen statt verschoben, dieselbe Datei in einen
zweiten Kalender zu importieren fügt dort also nichts hinzu.

**Zwei Einträge für eine Besprechung heißen, dass die beiden Kopien sich uneinig sind**, und nicht,
dass die Doppelerkennung versagt hat. Schau auf Beginn, Ende, Titel, die Ganztags-Markierung und
darauf, ob eine von beiden abgesagt ist — eines davon weicht ab. Öffne eine von beiden und speichere
sie mit beiden angehakten Kalendern, um sie wieder zusammenzuführen.

---

**Verwandt:** [Kalender](calendar.md) · [Einladungen und Termine aus E-Mails](calendar-invitations.md) ·
[Erinnerungen](calendar-alerts.md) · [Teilen und Buchen](calendar-sharing.md)

**Einen Anbieter einrichten:** [Google](../providers/google.md) ·
[Microsoft](../providers/microsoft.md) · [CalDAV](../providers/caldav.md) ·
[ICS-Feeds](../providers/ics-feeds.md)

**Wie es funktioniert:** [Die Abgleich-Maschinerie](../internals/calendar-sync-engine.md) — der
Treibervertrag, den jeder Anbieter erfüllt, Push-Kanäle und wie Doppelungen aufgelöst werden.
