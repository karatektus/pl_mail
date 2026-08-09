<!-- translated-from: providers/imap-smtp.md sha1:9c6e34825ac15a5d17b1754a85fb81a4335d4c27 -->

# IMAP und SMTP

Ein gewöhnliches Postfach braucht nirgends eine Registrierung und keine Administration: Du gibst
plMail die Serveradressen und ein Passwort, und es verbindet sich. Das ist der Weg für alles, was
kein Gmail- und kein Microsoft-Konto ist — dein eigener Mailserver, das Postfach eines Hosters, GMX,
Fastmail, ein Kasten am Ende einer Domain, die dir gehört.

Was plMail mit dem Postfach macht, sobald es verbunden ist — Aliase, die Optionen pro Konto —,
steht in [Konten und Aliase](../features/accounts.md). Auf dieser Seite geht es
darum, die Verbindung zum Laufen zu bringen, und um die Handvoll Einstellungen, über die sich
Anbieter tatsächlich uneins sind.

## Ein Postfach hinzufügen

**Einstellungen → E-Mail-Konten → Konto hinzufügen**, im Reiter **IMAP / SMTP**.

1. Wähl deinen Anbieter aus der Auswahlliste **Anbieter**, wenn er dort steht. plMail bringt eine
   Liste gängiger Anbieter mit und füllt daraus Server, Port und Verschlüsselung für beide Richtungen
   aus; bei etlichen Einträgen steht zusätzlich ein kurzer Hinweis auf etwas, das dieser Anbieter
   verlangt. Die Liste wird gegen die Domain der eingetippten Adresse abgeglichen, für die meisten
   Postfächer sind die Einstellungen also da, bevor du die Auswahlliste überhaupt berührt hast.
2. **E-Mail-Adresse** ist der Name, unter dem das Konto in plMails eigener Oberfläche erscheint.
   **Benutzername** ist das, als was der Server dich kennt, und ist auch das, was die
   Anbietererkennung liest. Auf den meisten Servern sind beide dieselbe Zeichenfolge; auf manchen
   nicht, und dafür gibt es die zwei Felder.
3. **Passwort** ist das Postfachpasswort oder — bei den meisten großen Anbietern — ein
   anwendungsspezifisches Passwort. Siehe unten.
4. **Eingehend — IMAP** nimmt Server, Port und Verschlüsselungsmodus. Server und Port sind Pflicht;
   ein leeres Formular startet bei 993 mit SSL/TLS, und das ist es, was nahezu jeder Anbieter will.
5. **Ausgehend — SMTP** nimmt dieselben drei, und alles davon ist optional. Ein Postfach ohne
   SMTP-Einstellungen ist ein Postfach, das du lesen und aus dem du nicht senden kannst, und das ist
   für ein Archiv ein legitimer Wunsch.
6. **Verbindung testen** prüft IMAP und SMTP getrennt und meldet beides einzeln, ein funktionierendes
   Postfach mit falschem SMTP-Port sagt das also, statt beim ersten Senden zu scheitern.

Speichern, und die erste Synchronisation beginnt sofort.

Die Verschlüsselungsoptionen sind **SSL / TLS**, **STARTTLS** und **None**, in beiden Richtungen. Sie
bedeuten, was sie sagen: SSL/TLS öffnet ab dem ersten Byte eine verschlüsselte Verbindung, STARTTLS
öffnet eine unverschlüsselte und rüstet sie hoch, und None ist Klartext. Ports und Modi gehören
zusammen — siehe die Tabelle weiter unten.

Aus einem Terminal ist dieselbe Prüfung:

```bash
docker compose exec php php bin/console app:mail:test-connection
docker compose exec php php bin/console app:imap:test --account=ID
```

## Wie Mail danach eintrifft

plMail hält zu jedem Postfach eine **IMAP-IDLE**-Verbindung offen, und das ist die Art des
Protokolls selbst zu sagen „melde dich, wenn sich etwas ändert", statt alle paar Minuten
nachzufragen. Eine Nachricht, die auf dem Server eintrifft, erreicht plMail in Sekunden.

Es gibt nichts einzuschalten. Wenn plMail die Ordner eines Kontos entdeckt, markiert es die, die der
Server als Posteingang und als Spam-Ordner kennzeichnet, als IDLE-fähig, und der Dienst
`imap-supervisor` betreibt eine Verbindung pro solchem Ordner und startet abgebrochene mit einem
kurzen Backoff neu. Andere Ordner werden synchronisiert, aber nicht beobachtet, und das ist eine
bewusste Sparsamkeit: Eine IDLE-Verbindung ist ein gehaltener TCP-Socket, und dreißig davon pro Konto
zu halten, um von Mail zu erfahren, die in einen Archivordner wandert, kostet mehr, als es wert ist.

Dahinter synchronisiert ein geplanter Durchlauf ohnehin alle fünfzehn Minuten jedes Konto. IDLE sorgt
dafür, dass Mail sofort eintrifft; der Durchlauf sorgt dafür, dass sie überhaupt eintrifft, wenn eine
Verbindung von einer Firewall gekappt wurde oder ein Anbieter beschlossen hat, IDLE eine Weile nicht
mehr zu beantworten.

Wenn Mail nicht von selbst eintrifft, ist das Erste zu prüfen, ob der Container `imap-supervisor`
läuft. Ohne ihn hält nichts eine IDLE-Verbindung, und Mail trifft nur im Viertelstundentakt ein.

## App-Passwörter bei den großen Anbietern

Etliche Anbieter nehmen dein Kontopasswort aus einem Mail-Client nicht mehr an, und etliche weitere
nehmen es nur so lange, bis du die Zwei-Faktor-Authentifizierung einschaltest. Die Antwort ist bei
allen ein anwendungsspezifisches Passwort: eine erzeugte Zeichenfolge, die diesen einen Client
kennzeichnet, neben einem zweiten Faktor funktioniert und für sich allein widerrufen werden kann,
ohne dein eigentliches Passwort zu ändern.

Wo plMail eine Voreinstellung mitbringt, trägt sie auch den Vorbehalt des jeweiligen Anbieters:

| Anbieter | Was er verlangt |
|---|---|
| Gmail | Ein App-Passwort — und der OAuth-Reiter ist der bessere Weg. Siehe [Google](google.md). |
| Outlook.com / Hotmail | Microsoft hat die Basisauthentifizierung für die meisten Tenants abgeschaltet. Nimm den OAuth-Reiter; siehe [Microsoft](microsoft.md). |
| iCloud Mail | Ein anwendungsspezifisches Passwort. Sonst wird nichts akzeptiert. |
| Yahoo Mail | Ein App-Passwort. |
| Fastmail | Ein App-Passwort. |
| Telekom / T-Online | Ein eigenes E-Mail-Passwort, im Telekom-Konto gesetzt — nicht das Telekom-Login selbst. |
| GMX | IMAP muss erst eingeschaltet werden, in der GMX-Weboberfläche unter Einstellungen. |
| WEB.DE | Dasselbe: IMAP muss in der Weboberfläche aktiviert werden, bevor sich irgendein Client verbinden kann. |
| Proton Mail | Funktioniert nur über die Proton Mail Bridge, die auf demselben Host läuft. |

Zieh bei Gmail und Outlook OAuth einem App-Passwort vor. Es ist nicht nur die angenehmere Anmeldung:
Ein App-Passwort gegen Gmail ist voller Postfachzugriff ohne Scope-Grenzen und ohne Widerrufsspur
jenseits des Passworts selbst, und Microsoft blockiert den IMAP-Weg in jedem Tenant mit Security
Defaults rundweg — und das ist die Voreinstellung für neue Tenants.

## Die Einstellungen, über die Server uneins sind

**Der Submission-Port.** Sowohl 587 mit STARTTLS als auch 465 mit implizitem SSL/TLS sind weit
verbreitet, und plMails Voreinstellungen verteilen sich ungefähr gleichmäßig darauf. Keiner von
beiden ist in der Praxis sicherer als der andere; worauf es ankommt, ist, dass Port und Modus
zusammenpassen. 587 mit SSL/TLS und 465 mit STARTTLS scheitern beide, meist mit einer
Zeitüberschreitung statt mit einem Fehler, weil jede Seite darauf wartet, dass die andere zuerst
spricht.

**Der Benutzername.** Manche Server wollen die vollständige E-Mail-Adresse, manche den lokalen Teil
und manche etwas ganz anderes — eine Kundennummer oder einen vom Hoster vergebenen Postfachnamen. Am
häufigsten beißt das bei Shared Hosting: plMails Voreinstellung für Hetzners Mail-Hosting vermerkt,
dass der Benutzername die vollständige Adresse ist, und die für domainFACTORY vermerkt, dass der
Server ein gemeinsamer Mail-Pool ist, für den dasselbe gilt.

**Ein Server oder zwei.** Reichlich Anbieter liefern IMAP und SMTP über denselben Hostnamen aus, und
plMails Voreinstellungen zeigen mehrere davon — ein einzelner `mail.`-Server für beides ist normal
und kein Zeichen dafür, dass etwas falsch ausgefüllt wurde.

**Ob IMAP überhaupt an ist.** GMX und WEB.DE kommen mit abgeschaltetem IMAP und verlangen, dass du es
zuerst in ihrer Weboberfläche einschaltest. Der Fehlschlag ist ein Authentifizierungsfehler, der sich
wie ein falsches Passwort liest und keines ist.

**Ordnernamen.** IMAP-Server sind sich uneins darüber, wie die Ordner für Gesendetes, Entwürfe, Spam
und Papierkorb heißen und wie sie sich ankündigen. plMail liest die Special-Use-Flags, die der Server
schickt, statt auf Namen zu vergleichen, und deshalb kommen die Ordner auf einem deutschsprachigen
Server genauso richtig heraus wie auf einem englischsprachigen — aber ein Server, der nichts
ankündigt, lässt plMail jeden Ordner als gewöhnlichen behandeln.

## Fallstricke

**Port und Verschlüsselungsmodus müssen zusammenpassen.** Das ist der mit Abstand häufigste
Fehlschlag auf dieser Seite, und er zeigt sich als Hängenbleiben statt als Ablehnung. 993/SSL und
143/STARTTLS für IMAP; 465/SSL und 587/STARTTLS für SMTP.

**`127.0.0.1` meint den Container, nicht deinen Rechner.** plMail läuft in Docker, ein Mailserver auf
der Loopback-Adresse des Hosts — die Proton Mail Bridge ist der übliche Fall — ist von innen also
nicht unter `127.0.0.1` erreichbar. Richte das Konto auf eine Adresse, die der Container tatsächlich
auflösen kann, oder stell die Bridge dorthin, wo der Container sie sieht.

**Ein App-Passwort ist nicht dein Kontopasswort**, und Anbieter, die eines verlangen, weisen das
echte meist mit derselben allgemeinen Fehlermeldung ab, die sie für einen Tippfehler verwenden. Wenn
ein Passwort, dessen du dir sicher bist, bei iCloud, Yahoo oder Fastmail abgelehnt wird, ist das der
Grund.

**Das Passwort beim Anbieter zu ändern teilt sich plMail nicht mit.** Das Konto scheitert weiter beim
Verbinden, bis du es hier aktualisierst; die Kontozeile hält den Fehlschlag fest, die Kontenliste ist
also der Ort zum Nachsehen, wenn ein Postfach still stehengeblieben ist.

**Nur Posteingang und Spam-Ordner werden mit IDLE beobachtet.** Alles andere trifft im
Viertelstunden-Durchlauf ein. Eine Nachricht, die vier Minuten nachdem eine Regel sie auf dem Server
in einen Unterordner einsortiert hat in plMail auftaucht, ist genau das und funktioniert wie
vorgesehen.

**Ohne den Dienst `imap-supervisor` hält nichts eine IDLE-Verbindung.** Mail trifft weiterhin ein,
nach Zeitplan, was das zu einem sehr leisen Fehlschlag macht — das Symptom ist „Mail ist immer ein
paar Minuten spät" und nichts, was kaputt aussieht.

**Markierungen wandern nur nach außen.** Eine Nachricht in plMail als gelesen oder markiert zu
kennzeichnen wird zum Server geschoben, der umgekehrte Weg ist aber noch nicht umgesetzt — eine
Nachricht in einem anderen Client zu lesen markiert sie hier derzeit nicht als gelesen.
