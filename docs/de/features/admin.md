<!-- translated-from: features/admin.md sha1:9754c799bab994f83af0846421b46b232d2f0b28 -->

# Administration

Als Administrator angemeldet, öffnet **Administration** im Benutzermenü ein Panel, das sagt,
was die Instanz gerade tut. Es sind sechs Abschnitte: **System**, **Datenbank**, **Protokolle**,
**Integrationen**, **Benutzer** und **Zurücksetzen**.

![Das Admin-Dashboard](../screenshots/admin.png)

Nichts hier liest die Mail irgendeiner Person. Administrator zu sein gewährt das Panel, die
Benutzerliste und die Anbieterkonfiguration und sonst nichts — keine Route in diesem Bereich
fasst ein Konto oder eine Nachricht an.

## Der Versions-Chip

Der Kopf des Panels trägt den Build: das Release, aus dem er gebaut wurde, und daneben den
kurzen Commit. Er sitzt im Kopf und nicht in einem Panel, weil die Frage, die er beantwortet —
*ist das der Build, für den ich ihn halte?* —, gestellt wird, während man auf etwas anderes
schaut.

Er wird beim Bauen eingebacken und nicht zur Laufzeit ausgelesen; ein Container hat kein `.git`,
das man fragen könnte. Ein Checkout, der nie aus einem Tag gebaut wurde, fällt auf `git
describe` zurück, und wo niemand die Antwort kennt, fehlt der Chip ganz, statt neben jeder Seite
„development“ zu lesen.

Zwei Images können sich beide `main` nennen, und darum steht der Commit neben dem Namen und
nicht an seiner Stelle.

## System

Lebende Panels, alle zehn Sekunden aktualisiert. Jedes lässt sich einklappen, und eine
eingeklappte Karte zeigt in ihrem Kopf eine einzeilige Zusammenfassung — *3 in Ordnung*, *2
laufend · 41 wartend* — kurz genug, um sie im Vorbeigehen zu lesen, und genau genug, um das
Aufklappen wert zu sein. Welche Karten du eingeklappt hast, merkt sich der Server, die Seite
zeigt also nie kurz alle Panels offen, bevor sie sie zusammenfaltet.

| Karte | Was sie zeigt |
|---|---|
| **Prozesse** | Jeder langlaufende Prozess, der ein Lebenszeichen gemeldet hat, mit Typ, Instanz, PID und letztem Schlag |
| **Wartung** | Die Verben — Neustarts, einmalige Aufgaben, Aufräumen |
| **Gmail-Webhooks** | Je Gmail-Konto: Watch-Zustand, Ablauf, History-Id, letzter Push und der Zustellweg |
| **Zustand der OAuth-Token** | Erfasste Erneuerungen und Token, die dem Ablauf nahe sind |
| **Messenger-Warteschlangen** | Was ein Worker gerade hält, über dem Rückstau |
| **Fehlgeschlagene Nachrichten** | Der Failure-Transport, mit Wiederholen und Löschen |
| **Konten** | Je Konto: Konversationen, Nachrichten, letzte Aktivität |
| **Tabellengrößen** | Die größten Tabellen der Datenbank |

### Das Warteschlangen-Panel

Das, was zu lesen ist, wenn keine Mail mehr ankommt. **Läuft gerade** benennt die Nachrichten,
die ein Worker in diesem Augenblick hält — den Handler, seine Nutzdaten und wie lange er sie
schon hält —, über einer durchsuchbaren Liste alles übrigen Wartenden. Eine hängende
Warteschlange sieht deshalb anders aus als eine leere, und genau diese Unterscheidung ist die,
auf die es ankommt.

Der Rückstau lädt fünfundzwanzig auf einmal und holt beim Scrollen nach, und der Filter läuft
über die ganze Warteschlange und nicht über die Seite auf dem Bildschirm. Er hat einen eigenen
Endpunkt, eine Suche zeichnet also nicht bei jedem Tastendruck alle anderen Panels neu.

### Wartung

**Worker neu starten** bittet jeden langlaufenden Prozess, sich zu beenden; Composes
Neustartregel bringt sie zurück, und laufende Arbeit wird vorher fertig. Der Grund, warum es
das gibt: Ein Worker hält Doctrines Metadaten über seine gesamte Lebensdauer im Cache, kann
also nach einer Migration weiter Spalten abfragen, die es nicht mehr gibt, bis ihn etwas neu
startet.

**Jetzt neu starten** ist die andere Hälfte — der Container, der die Seite ausliefert, auf der
du stehst. Der Worker-Neustart erreicht ihn nicht, denn dessen Mechanismus ist ein Zeitstempel,
den eine Worker-Schleife wieder prüft, und der Web-Prozess hat keine solche Schleife. Der
übliche Grund, ihn zu wollen, ist ein erneuertes Geheimnis, das ein bereits gestarteter Kernel
nicht mehr nachlesen kann. Er kostet etwa zwei Sekunden Ausfall, und die Seite kommt von selbst
zurück.

**Wartungsaufgabe jetzt ausführen** bietet vier Knöpfe: **Mail synchronisieren**, **Push-Abos
erneuern**, **Monitoring-Daten aufräumen** und **Blobs aufräumen**. Jeder wird für einen Worker
eingereiht, statt im Request ausgeführt zu werden. Diese vier und keine anderen, denn es sind
die, die der Scheduler ohnehin unbeaufsichtigt ausführt, und genau das beweist, dass sie sich
gefahrlos als Knopf anbieten lassen.

**Alte Heartbeats entfernen** räumt Zeilen weg, die Prozesse hinterlassen haben, die ohne
sauberes Herunterfahren gestorben sind — die, die sonst für immer rot in der Karte Prozesse
sitzen würden.

Die Karte für fehlgeschlagene Nachrichten ergänzt **Alle wiederholen** und **Alle verwerfen**,
beide mit Rückfrage.

Keine davon antwortet mit einer Meldung. Jede leitet zurück, und die eigene Aktualisierung des
Panels zeigt das Ergebnis: Die Warteschlange wird tiefer, fehlgeschlagene Zeilen verschwinden,
Lebenszeichen kommen zurück. Die Ausnahme ist der Worker-Neustart, der keine sichtbare Wirkung
hat — deshalb zeichnet das Panel ein Band, das sagt, vor wie langer Zeit er angefordert wurde:
Ein Neustart räumt Heartbeat-Zeilen weg, statt sie rot werden zu lassen, und verschwindende
Zeilen sind hier zu erwarten und kein Ausfall.

## Datenbank

Verbindungszahlen, Cache-Trefferquote, Deadlocks und Rollback-Quote, dazu die langsamsten
Anweisungen nach mittlerer Zeit (alles ab durchschnittlich 5 ms) und die aufwendigsten nach
Gesamtzeit, sowie das, was gerade läuft.

Die Auswertung braucht PostgreSQLs `pg_stat_statements`, das `app:db:migrate` beim Start
aktiviert. Durfte die Datenbankrolle die Erweiterung nicht anlegen, sagt das Panel es und bietet
**Jetzt aktivieren** an. Die Statistik beginnt in dem Moment, in dem die Erweiterung angelegt
wird, Abfragen von davor erscheinen also nie. **Statistik zurücksetzen** leert die gesammelten
Zahlen.

## Protokolle

Ein filterbarer Browser über das, was plMail in die Datenbank geschrieben hat: eine
Mindeststufe — info, notice, **warning** (die Vorgabe), error oder critical — und ein Kanal,
hundert Einträge pro Seite, mit einem Kopierknopf je Eintrag.

Wie viel überhaupt in die Datenbank gelangt, entscheidet `APP_DB_LOG_LEVEL`, standardmäßig
`warning`. Es auf `info` zu senken ist zum Beispiel das, was erfolgreiche Gmail-Push-Zustellungen
sichtbar macht; sie werden auf Info-Stufe protokolliert und sonst nicht gespeichert.

**Leeren** löscht die Einträge, auf die der gerade eingestellte Filter passt — was verschwindet,
ist das, was du angesehen hast.

Alles ab Warnstufe, das keine Administratorin und kein Administrator gelesen hat, umrandet auf
**jeder** Seite das Benutzermenü, bernsteinfarben bei Warnungen und rot bei Fehlern, mit einer
Zahl. Den Protokollbrowser zu öffnen ist das, was sie als gesehen markiert, und die Marke wird
auf den Moment des Öffnens gesetzt und nicht auf den neuesten Eintrag auf dem Bildschirm — was
protokolliert wird, während du liest, bleibt also tatsächlich ungelesen.

Die Umrandung sehen nur Administratorinnen und Administratoren. Für alle anderen wäre sie ein
Alarm über etwas, das sie gar nicht ansehen dürfen.

Anders als die System-Panels aktualisiert sich dieser Abschnitt nicht von selbst — beim Lesen
eines Stacktrace soll einem nicht mitten im Scrollen alles weggerissen werden.

## Integrationen

Zwei Dinge wohnen hier: welche Dateidienste diese Installation anbietet, und die
OAuth-Anwendungen, über die Menschen sich bei ihrer Mail anmelden.

**Mail-Anmeldung** hält Client-ID und Client-Secret von Google und Microsoft, den
Microsoft-Tenant und für Gmail das Pub/Sub-Topic und das Push-Verifizierungstoken. Lässt du
eines davon leer, greift die passende Umgebungsvariable — `GOOGLE_OAUTH_*`,
`MICROSOFT_OAUTH_*`, `GMAIL_PUBSUB_*` —, eine so konfigurierte Installation läuft also
unverändert weiter.

Für den Kalenderzugriff ist hier nichts weiter nötig. Er reitet auf derselben Anmeldung mit:
Aktiviere die Google Calendar API und ergänze den Kalender-Scope im Zustimmungsbildschirm, oder
füge der Entra-Registrierung die delegierte Berechtigung `Calendars.ReadWrite` hinzu. Ohne das
funktioniert Mail weiter, und die Kalender erscheinen einfach nicht.

**Services** listet jeden Dateianbieter auf, gleich ob plMail ihn schon ansprechen kann. Jeder
hat:

- **Diesen Dienst anbieten** — aus heißt, dass sich niemand damit verbinden kann und er aus den
  Menüs zum Verfassen und zum Speichern verschwindet.
- Client-ID und Client-Secret, bei den OAuth-Diensten, mit der genauen **Redirect-URI** zum
  Einfügen in die Konsole des Anbieters.
- **Serveradresse**, bei den selbst gehosteten. Lass sie leer, und jede Person trägt ihre eigene
  ein; setze sie, und alle sind an diesen Server gebunden.

**Zugangsdaten von … übernehmen** kopiert für Google wie für Microsoft eine Client-ID und ein
Secret serverseitig hinüber, ohne das Secret je anzuzeigen. Ein Google-Cloud-Projekt deckt auch
Drive und Photos ab; eine Entra-Registrierung deckt auch OneDrive ab. Die Zugangsdaten zu
kopieren erteilt **nicht** die zusätzliche Berechtigung, die diese Dienste brauchen — das
bleibt eine Änderung beim Anbieter.

Secrets sind durchweg nur schreibbar: Das Formular zeigt, ob eines hinterlegt ist, nie welches,
und ein leer abgeschicktes Feld behält das gespeicherte. Genau das erlaubt es einer
Administratorin, eine Basis-URL zu ändern, ohne ein Secret neu einzufügen, das sie nicht mehr
hat. Eines zu löschen ist ein ausdrückliches Häkchen, das nur erscheint, wenn es etwas zu
löschen gibt.

Anbieter, die plMail noch nicht ansprechen kann, stehen trotzdem da, ausgegraut, mit lesbaren
Einrichtungshinweisen. Die Zugangsdaten lassen sich jetzt hinterlegen und greifen, sobald die
Unterstützung erscheint.

Die Einrichtungsschritte je Anbieter stehen auf [Google](../providers/google.md),
[Microsoft](../providers/microsoft.md) und, für die Kalenderseite,
[CalDAV](../providers/caldav.md).

## Push

Ein Bildschirm, und er dreht sich nur um Firebase. Web Push braucht hier nichts: Seine
VAPID-Schlüssel sind Umgebungsvariablen, einmalig von `app:push:generate-vapid-keys` geprägt, und
sie bedienen Browser, die installierte PWA und UnifiedPush-Distributoren gleichermaßen. Eine
Einstellungsseite, die sie schreibgeschützt neben einem editierbaren Firebase-Schlüssel zeigte,
würde nahelegen, dass sie von hier aus änderbar sind.

Firebase Cloud Messaging ist der Weg, auf dem eine **native Android-App** im Hintergrund
Benachrichtigungen empfängt, denn Android hat keinen anderen Push-Dienst, und eine gewöhnliche
Android-App kann kein Web Push sprechen. Es ist im vollen Sinne optional — wer einen
UnifiedPush-Distributor betreibt, braucht nichts davon, und die Browser-App rührt es nie an.

Zwei Dateien, und keine ist allein zu gebrauchen:

- **Der Dienstkonto-Schlüssel**, aus Firebase-Konsole → Projekteinstellungen → Dienstkonten → Neuen
  privaten Schlüssel erzeugen. So sendet der Server. Er wird verschlüsselt gespeichert wie alles
  andere, was diese Installation vorhält, und nie wieder angezeigt.
- **`google-services.json`**, aus Projekteinstellungen → Deine Apps → die Android-App. Damit
  initialisiert die *App* Firebase. Die plMail-Android-App ist ein Build, der an jede Installation
  ausgeliefert wird, während jede Installation ihr eigenes Firebase-Projekt hat — sie lässt sich
  also nicht einkompilieren. Die Werte werden stattdessen in der JMAP-Session veröffentlicht, und
  die App baut ihre `FirebaseOptions` zur Laufzeit. Sie stecken im APK jeder Firebase-App und sind
  ihrer Natur nach öffentlich, werden also im Klartext gespeichert.

**Ein Paar aus zwei verschiedenen Projekten wird abgelehnt, unter Nennung beider.** Nichts weiter
unten kann diesen Fehler erkennen: Die App registriert sich fröhlich gegen das eine Projekt, der
Server sendet fröhlich an das andere, jede Nachricht wird in eine Protokolldatei hinein abgelehnt,
und das Symptom der Nutzerin ist, dass Benachrichtigungen nicht funktionieren. Dieser Bildschirm
ist die einzige Stelle, an der beide Hälften in einer Hand liegen.

Dasselbe gilt für die falsche Datei. Die Firebase-Konsole bietet vier Downloads an, die allesamt
gültiges JSON sind, also benennt eine Ablehnung die Schlüssel, die der Datei fehlen, statt sie für
ungültig zu erklären.

Der Schalter ist von den Zugangsdaten getrennt, damit FCM abzuschalten nicht dasselbe ist wie den
Schlüssel zu verlieren. Er lässt sich erst umlegen, wenn beide Dateien vorliegen — zu früh
einzuschalten hieße, FCM jedem Client anzukündigen und dann jede Registrierung abzulehnen, was ein
Client nicht von einem Fehler auf seiner eigenen Seite unterscheiden kann. Genau dafür unterscheidet
die Plakette neben der Überschrift **Aktiv**, **Eingerichtet, abgeschaltet**, **Halb eingerichtet**
und **Nicht eingerichtet**.

Nichts davon braucht einen Neustart. Das ist der ganze Grund, warum hier eine Datenbankzeile steht
und keine Umgebungsvariable.

### Letzte Zustellungen

Unter dem Firebase-Formular, und es umfasst **beide** Transportwege: jeden Versuch, ein Gerät zu
wecken, neueste zuerst, filterbar nach Benutzerin, Transportweg und Ergebnis. Es gibt das, weil
Push das Einzige war, was dieser Server tat, ohne eine Spur zu hinterlassen — eine Benachrichtigung,
die nie ankam, sah genauso aus wie eine, die die Nutzerin nur nicht bemerkt hat, und der einzige
Beleg war eine Log-Zeile, die nur bei einem Fehler geschrieben wurde.

Jede Zeile ist ein Versuch: wann, welche Benutzerin, welches Gerät (die ID, die sich der Client
selbst gegeben hat), der Transportweg, was transportiert wurde, wie lange es gedauert hat und was
die Gegenstelle gesagt hat.

| Ergebnis | Bedeutet |
|---|---|
| **Angenommen** | Der Transportweg hat es genommen. Kein Beleg dafür, dass es angezeigt wurde — ein Beleg dafür, dass es übergeben wurde |
| **Fehlgeschlagen** | Abgelehnt oder nicht erreichbar, das Gerät bleibt bestehen. In der Detailspalte steht der Status oder der FCM-Fehlername |
| **Gerät entfernt** | Die Adresse hat sich als dauerhaft tot erwiesen (ein 410 oder `UNREGISTERED`) und die Subscription wurde gelöscht. Diese Zeile ist die einzige Erklärung dafür, warum das Gerät aus der Liste der Nutzerin verschwunden ist |
| **Übersprungen** | Es wurde nichts gesendet: Der Transportweg ist nicht eingerichtet, oder die Zeile kann ihn nicht adressieren. Kein Fehler — eine Installation, die noch nicht fertig eingerichtet ist |

Die Unterscheidung **Übersprungen** ist die, die man genau lesen sollte. Eine Installation ohne
VAPID-Schlüssel oder mit abgeschaltetem Firebase erzeugt pro Gerät und Zustandsänderung einen
übersprungenen Versuch, und das ist eine Antwort über die Einrichtung, nicht über ein kaputtes
Gerät.

**Was gepusht wurde, wird bewusst nicht aufgezeichnet.** Das Protokoll hält den *Typ* der Nutzlast
fest — `StateChange` oder `PushVerification` — und sonst nichts von ihr. Eine `StateChange` benennt
die Konten und Zustandsmarken, die sich bewegt haben; sie aufzubewahren würde diese Tabelle in ein
aufbewahrtes, für Administratorinnen lesbares Verzeichnis verwandeln, wann bei wem Mail ankommt —
und das ist eine größere Sache als die Frage, bei der es helfen würde.

Nutzerinnen sehen ihre eigene Hälfte davon, ohne die anderen, unter **Einstellungen →
Benachrichtigungen**: jedes registrierte Gerät mit Transportweg, ob der Verifikations-Handshake
abgeschlossen ist, und seiner letzten Zustellung.

Aufbewahrt wird 30 Tage, nächtlich weggeräumt von `app:monitoring:prune`; `--push-days=N` ändert das.

## Benutzer

Eine durchsuchbare, seitenweise Liste aller, die sich anmelden können, mit dem Zeitpunkt der
letzten Anmeldung und der Angabe, ob die Zwei-Faktor-Authentifizierung an ist.

**Benutzer hinzufügen** nimmt eine E-Mail-Adresse, einen Namen, ein erstes Passwort von
mindestens zwölf Zeichen und ein Häkchen **Administrator**. Die Untergrenze für die Länge ist
absichtlich höher, als du sie für dich selbst wählen würdest: Wer dieses Passwort wählt, ist
nicht die Person, die es benutzen wird, die Länge ist also das einzige verfügbare Mittel.

Drei Dinge kann eine Administratorin bewusst nicht tun, alle aus einem Grund — eine
Admin-Sitzung darf kein zweiter Weg in jedes Postfach dieser Installation werden:

- **Das Passwort eines bestehenden Benutzers ändern.** Das Feld gibt es beim Anlegen und nicht
  beim Bearbeiten. Wer sich noch nie angemeldet hat, hat keine Mail, sein erstes Passwort zu
  setzen gibt also nichts preis; es danach zu ändern schon.
- **Jemandem den zweiten Faktor abnehmen.** Das ist `app:user:2fa-disable` auf der Konsole, und
  nur dort.
- **Die Mail von irgendjemandem lesen.** Nichts in diesem Bereich fasst ein Konto oder eine
  Nachricht an.

**Benutzer entfernen** ist ein weiches Löschen. Die Adresse und der Anzeigename werden
freigegeben — die Adresse ist eindeutig, sie stehen zu lassen würde also verhindern, dieselbe
Person je wieder hinzuzufügen — und die Zeile kann sich nicht mehr anmelden, aber die Konten,
Nachrichten, Labels und App-Passwörter daran bleiben, wo sie sind. Eine Kaskade aus einem
Fehlklick in einem Admin-Panel ist kein behebbarer Fehler.

Zwei Entfernungen werden rundheraus abgelehnt: dein eigenes Konto und die letzte verbliebene
Administratorin. Die zweite ist die, die im Moment gut aussieht — jemand entfernt eine Kollegin,
und niemand merkt es, bis das nächste Mal jemand das Panel braucht. Dich selbst oder die letzte
Administratorin herabzustufen wird aus demselben Grund abgelehnt.

## Zurücksetzen

`app:reset`, als Knöpfe. Sechs Stufen, von denen jede alles löscht, was die darüber löscht, und
mehr:

| Stufe | Löscht |
|---|---|
| **Synchronisierte Mail** | Nachrichten, Konversationen, Nachrichtenteile und alles Eingereihte. Konten, Ordner und Labels bleiben; die Sync-Marker werden zurückgesetzt |
| **Mail und Postfachstruktur** | Das Obige, dazu Ordner und Labels. Die nächste Synchronisierung baut beides neu auf |
| **Mail, Struktur und Kontakte** | Das Obige, dazu die gesammelten Kontakte. Die Adress-Vervollständigung startet leer |
| **Mail, Struktur, Kontakte und Konten** | Das Obige, dazu die Konten und ihre Aliase. Jedes Postfach-Passwort und jede OAuth-Verbindung muss neu eingerichtet werden. Deine eigene Anmeldung bleibt unberührt |
| **Vollständig zurücksetzen** | Jeden Benutzer, dich eingeschlossen, jedes gespeicherte Passwort und die Dateien auf der Platte |
| **Vollständig zurücksetzen und Geheimnisse erneuern** | Das Obige, dazu die generierten Geheimnisse |

Die oberen vier werden mit einem Dialog bestätigt: Der schlimmste Fall ist eine erneute
Synchronisierung, die Stunden kostet und keine Information. Die unteren zwei verlangen, dass der
**Instanzname** — der Host, unter dem plMail antwortet — ins Formular getippt wird, und das
wird auf dem Server geprüft und nicht in JavaScript. Ein Klick allein genügt nicht für eine
Operation, die nichts zurückbringt.

Ein vollständiges Zurücksetzen leitet nirgendwohin weiter. Es kann nicht: Die Person, die es
ausgeführt hat, gibt es nicht mehr, es liegt also keine Seite mehr hinter der Firewall, und die
Antwort selbst ist die einzige Gelegenheit zu sagen, was passiert ist und was noch zu tun
bleibt.

Monitoring-Daten bleiben auf jeder Stufe erhalten. Die Protokolle leerst du unter
**Protokolle**, veraltete Heartbeats unter **Wartung**.

## Wo du weiterliest

- [Dateien und Integrationen](integrations.md) — was eine Person sieht, sobald du einen Dienst
  freischaltest.
- [Konten und Aliase](accounts.md) — die Anmeldung, die du konfigurierst, vom anderen Ende her.
- [Fehlersuche](../install/troubleshooting.md) — die Fehler, die tatsächlich vorgekommen sind.
- [Konfigurationsreferenz](../install/configuration.md) — `APP_DB_LOG_LEVEL`, die
  OAuth-Variablen, die für Pub/Sub.
- [Architektur](../internals/architecture.md) — was die Worker, der Scheduler und der Supervisor
  sind.

## Fallstricke

**Die Geheimnisse zu erneuern, ohne den ganzen Stack neu zu starten, macht die Hälfte davon
kaputt.** Jeder andere Dienst hält den alten `APP_ENCRYPTION_KEY` im Speicher, bis er neu
startet, alles, was in der Zwischenzeit gespeichert wird, wird für die Dienste unlesbar, die es
nicht getan haben. Das Panel sagt das; der Neustart ist nicht optional.

**`POSTGRES_PASSWORD` wird bewusst nie erneuert.** Postgres wurde damit initialisiert und hält
eine eigene Kopie, ein neues würde plMail also die Anmeldung an genau der Datenbank verwehren,
die es gerade zurückgesetzt hat. Das zu ändern heißt, das Datenbank-Volume zu löschen, und das
kann das Panel nicht.

**Die Wartungsknöpfe reihen Arbeit ein, sie erledigen sie nicht.** Es passiert nichts, wenn kein
Worker konsumiert — die Container für Scheduler und Worker müssen laufen. Ohne sie feuert auch
keiner der geplanten Durchläufe.

**Ein Worker mit veralteten Doctrine-Metadaten überlebt eine Migration und scheitert seltsam.**
Langlaufende Prozesse halten die Zuordnungen über ihre gesamte Lebensdauer im Cache, eine von
einer Migration hinzugefügte Spalte ist für sie also unsichtbar, bis **Worker neu starten**
gedrückt wird. Das ist das Erste, was zu versuchen ist, wenn eine Warteschlange direkt nach
einem Update zu scheitern beginnt.

**Protokolle zu leeren löscht, worauf der Filter passt, und nicht nur die Seite.** Die Zahl in
der Rückfrage ist die echte.

**Erfolgreiche Gmail-Pushes sind auf der voreingestellten Protokollstufe unsichtbar.** Sie
werden auf Info-Stufe protokolliert und nicht gespeichert, „keine Ereignisse“ im Webhook-Panel
heißt also nicht „es wurde nichts zugestellt“, solange nicht `APP_DB_LOG_LEVEL=info` gesetzt
ist.

**`pg_stat_statements` aus dem Panel heraus zu aktivieren beginnt die Sammlung in diesem
Moment.** Abfragen von davor erscheinen nie, ein unmittelbar danach leeres Panel ist also zu
erwarten.

**Zugangsdaten mit „Zugangsdaten von … übernehmen“ zu kopieren erteilt nicht die zusätzliche
Berechtigung.** Es kopiert eine Client-ID und ein Secret und sonst nichts; der Scope oder die
delegierte Berechtigung muss weiterhin beim Anbieter ergänzt werden, sonst scheitert das
Verbinden bei der Zustimmung.

**Die letzte Administratorin oder dich selbst kannst du nicht entfernen.** Beides wird abgelehnt
statt mit einer Warnung versehen, was gelegentlich wie ein kaputter Knopf aussieht.

**`APP_ENCRYPTION_KEY` zu wechseln nimmt den Firebase-Schlüssel mit.** Das Dienstkonto-JSON liegt
verschlüsselt wie jedes andere Geheimnis, ein geänderter Schlüssel macht es also unlesbar — Push
über FCM geht still aus, die Session sagt ab da `fcm: false`, und die Behebung ist, den Schlüssel
erneut einzufügen. Die google-services-Werte überleben, weil sie nie verschlüsselt waren.

**Nur eine der beiden Firebase-Dateien zu ersetzen wird abgelehnt, wenn die Projekte
auseinandergehen.** Das ist Absicht, und die Meldung ist zu lesen statt zu umgehen: Ein
Dienstkonto-Schlüssel aus dem einen Projekt mit einer `google-services.json` aus einem anderen
ergibt eine Installation, in der alles eingerichtet aussieht und nie etwas zugestellt wird.

**Ein leeres Zustellprotokoll heißt, dass nichts *versucht* wurde, nicht dass alles funktioniert
hat.** Push feuert nur, wenn sich tatsächlich ein Zustand ändert, und nur an Geräte, die den
Verifikations-Handshake abgeschlossen haben — eine frische Installation mit einem registrierten
Browser kann also stundenlang leer bleiben und völlig gesund sein. Genau deshalb unterscheidet das
Panel „Keine Zustellung passt zu diesem Filter" von „Es wurde noch nichts zugestellt": Die zweite
Zeile ist die, bei der man die Einrichtung darüber prüft.

**„Angenommen" heißt nicht „bei einem Menschen angekommen".** Es heißt, dass der Push-Dienst die
Nachricht genommen hat. Ein Telefon im Doze-Modus, ein Browser, dem das Betriebssystem die
Berechtigung entzogen hat, und eine weggewischte Benachrichtigung sehen von hier aus identisch aus;
das Nächste, was man prüft, ist das Gerät und nicht diese Seite.

**Das Zustellprotokoll wird nach 30 Tagen weggeräumt**, ein vor sechs Wochen entferntes Gerät hat
hier also keine Zeile, die es erklärt. Wenn jemand meldet, Benachrichtigungen hätten „vor einer
Weile" aufgehört, sieh nach, bevor du annimmst, es sei nie etwas versucht worden.
