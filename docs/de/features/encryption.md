<!-- translated-from: features/encryption.md sha1:e1720855395005a32c3b26fbcbb035996756ebfd -->

# Verschlüsselung

plMail baut Ende-zu-Ende-Verschlüsselung mit OpenPGP und S/MIME: Mails, die nur du und die Menschen
lesen können, denen du schreibst — nicht der plMail-Server und nicht dein Mail-Anbieter. Diese Seite
beschreibt, was es heute schon gibt: deine Schlüssel, wie sie aufbewahrt werden und wie du sie
zurückbekommst, das Lesen verschlüsselter und signierter Mails, die Schlüssel der Menschen, denen du
schreibst, das verschlüsselte Schreiben und S/MIME-Zertifikate.

Alles hier liegt unter **Einstellungen → Verschlüsselung**.

## Deine Schlüssel werden für dich erstellt

Bei der ersten Anmeldung nach dem Update erstellt dein Browser deine Schlüssel von selbst: einen
OpenPGP-Schlüssel pro Adresse, von der du sendest, und einen Schlüssel darüber, der sie
zusammenhält. Es gibt nichts einzurichten und nichts über Schlüsselbunde, Fingerprints oder
Vertrauen zu verstehen. Ein neuer Alias bekommt seinen Schlüssel beim nächsten Seitenaufruf.

Deine Schlüssel werden mit deinem Passwort geöffnet, in deinem Browser, und der Server sieht keins
von beiden. Was der Server aufbewahrt, sind deine Schlüssel in verschlossener Form: verpackt unter
einem Schlüssel, den dein Browser aus deinem Passwort ableitet und den der Server nicht ableiten
kann, weil er das Passwort nie sieht (siehe [Sicherheit](security.md#anmelden)).

Meldest du dich nur über Single Sign-on an, hast du kein plMail-Passwort, und es wird nichts
erstellt, bis du eine Verschlüsselungs-Passphrase wählst — siehe unten.

## Du entsperrst einmal

Die Anmeldung mit deinem Passwort entsperrt deine Schlüssel für die ganze Sitzung, in jedem Tab,
ohne erneute Frage. Endet eine Sitzung — du meldest dich ab, oder sie läuft aus —, sind sie wieder
gesperrt.

Eine Sitzung, die anders geöffnet wurde — durch **Angemeldet bleiben** oder über Single Sign-on —,
beginnt mit gesperrten Schlüsseln, und bei der Anmeldung fragt dich nichts. Sobald etwas sie zum
ersten Mal braucht, fragt ein Dialog nach deinem Passwort (oder deiner Verschlüsselungs-Passphrase,
siehe unten):

- eine verschlüsselte Mail öffnen oder auf sie antworten;
- ein Entwurf, der gleich verschlüsselt wird, oder ein verschlüsselter Entwurf, den du öffnest;
- **Einstellungen → Verschlüsselung**;
- ein Gerät koppeln, damit deine Schlüssel mitkommen.

Einmal entsperrt, bleiben die Schlüssel für den Rest der Sitzung offen, in jedem Tab. **Nicht jetzt**
lässt sie gesperrt: Die Mail sagt es und bietet an, noch einmal zu fragen, und ein Entwurf, der
verschlüsselt werden soll, wird erst gespeichert, wenn sie offen sind. Mails, die du entschlüsselt
auf diesem Server behältst, werden im Hintergrund nachgeholt, sobald deine Schlüssel offen sind —
ohne von sich aus zu fragen.

## Mit Single Sign-on anmelden

Ein Konto, das sich nur über den Anmeldedienst deiner Organisation anmeldet, hat kein
plMail-Passwort — und das Passwort des Anmeldedienstes erreicht plMail nie, kann hier also auch
nichts öffnen. Deine Schlüssel hängen stattdessen an einer eigenen **Verschlüsselungs-Passphrase**.

**Einstellungen → Verschlüsselung** bietet **Verschlüsselung einrichten** an, bis du es getan hast.
Tippe eine Passphrase zweimal — mindestens so lang, wie ein Passwort sein muss —, und deine Schlüssel
werden erstellt, genau wie bei allen anderen, und für diese Sitzung entsperrt. Vorher wird nichts
erzeugt, und nichts erinnert dich daran.

Ab dann fragt der Entsperr-Dialog nach der Passphrase, einmal pro Sitzung. Wie ein Passwort wird sie
in deinem Browser gestreckt und nie an den Server geschickt; ob sie stimmt, zeigt sich nur daran, ob
sich deine Schlüssel öffnen. **Ändern** kannst du sie unter *Verschlüsselungs-Passphrase* auf
derselben Seite: Deine Schlüssel werden in deinem Browser unter der neuen neu verpackt, und deine
Anmeldung bleibt unberührt.

Erstelle gleich nach dem Einrichten eine [Recovery-Phrase](#die-recovery-phrase). plMail kann eine
Verschlüsselungs-Passphrase nicht zurücksetzen: Vergessen, wird sie so wiederhergestellt wie ein
vergessenes Passwort — mit der Recovery-Phrase oder auf einem [bekannten Gerät](#bekannte-geräte).

Bekommst du später zusätzlich ein plMail-Passwort, ändert sich für deine Schlüssel nichts: Die
Passphrase öffnet sie weiterhin, und das Passwort meldet dich nur an.

## Die Recovery-Phrase

Zwölf Wörter, die deine Schlüssel öffnen, wenn dein Passwort es nicht kann — weil du es vergessen
hast und eine Administratorin ein neues gesetzt hat. **Einstellungen → Verschlüsselung →
Recovery-Phrase** erstellt sie, zeigt sie einmal an und bietet sie als Datei zum Herunterladen an.
Die Datei enthält auch das Widerrufszertifikat jedes Schlüssels: das Einzige, was deinen Kontakten
noch sagen kann, einen Schlüssel nicht mehr zu benutzen, wenn Passwort und Phrase beide verloren
sind.

Erstelle eine neue Phrase, sobald die alte gesehen worden sein könnte. Die neue ersetzt sie.

## Bekannte Geräte

Jeder Browser, in dem du dich anmeldest, kann ein kleines eigenes Geheimnis aufbewahren, das deine
Schlüssel nach einem Passwort-Reset ohne Recovery-Phrase wieder öffnet — der bequeme Weg zurück. Die
Liste zeigt jeden Browser, wann er hinzukam und wann er zuletzt geholfen hat. Entferne einen, den
du nicht mehr benutzt. Ein Browser, der Website-Speicher blockiert, kann das Geheimnis nicht
aufbewahren; die Phrase hilft auch dort.

## Nach einem Passwort-Reset

Wenn eine Administratorin dir ein neues Passwort setzt (`app:user:password`), werden deine
Schlüssel gesperrt — niemand kennt das alte Passwort, unter dem sie verpackt waren. Meldest du dich
danach an:

- auf einem bekannten Gerät öffnen sich deine Schlüssel von selbst wieder;
- anderswo bekommst du sofort einen frischen Satz Schlüssel, damit du weiterarbeiten kannst, und
  die alten warten. Gib unter **Einstellungen → Verschlüsselung** deine Recovery-Phrase ein, um sie
  zu öffnen; Mails, die an sie verschlüsselt sind, werden wieder lesbar.

## Einen eigenen Schlüssel mitbringen

Du hast schon einen OpenPGP-Schlüssel, den deine Kontakte kennen? **Importiere** ihn — den aus
GnuPG, Thunderbird oder Proton exportierten Privatschlüssel und einmal seine Passphrase. Er wird
zum Schlüssel jeder Adresse, die er nennt, und deine Kontakte verschlüsseln weiter an den Schlüssel,
den sie schon haben. Nach der Passphrase wird nie wieder gefragt: Ab dann öffnet sich der Schlüssel
mit deinem plMail-Passwort. Den Schlüssel, den plMail für diese Adresse erstellt hatte, verwirft
plMail, solange ihn noch niemand gesehen hat.

Schlüssel, deren geheime Teile auf einer Smartcard oder einem Security-Key liegen (YubiKey,
OpenPGP-Karte), lassen sich nicht importieren: Ein Browser kann sie nicht benutzen.

## Deine Schlüssel mitnehmen

**Exportieren** schreibt jeden Privatschlüssel — auch alte und ersetzte, denn die öffnen alte
Mails — in eine `.asc`-Datei, verschlossen mit einer Passphrase, die du wählst. GnuPG,
Thunderbird, Proton und jedes andere plMail lesen sie.

Für den Umzug einer ganzen Installation braucht es das nicht: Die Konfigurationssicherung nimmt
deine Schlüssel in verschlossener Form zusammen mit deinem Passwort mit, und sie öffnen sich auf
dem neuen Server genau wie vorher (siehe [Konfigurationssicherung](../install/config-backup.md)).

## Schlüssel erneuern und ersetzen

Von plMail erstellte Schlüssel laufen nicht ab — wie bei Proton. Unter **Schlüssel anzeigen**:

- **Erneuern** erstellt einen neuen Schlüssel für dieselben Adressen. Der alte bleibt, als
  *ersetzt* markiert, um Mails zu öffnen, die an ihn gingen.
- **Als kompromittiert markieren** ist für einen Schlüssel, den vermutlich jemand anderes hat. Er
  wird nicht mehr benutzt, und seine Adressen bekommen einen neuen.
- **Primär machen** wählt aus, welchen von mehreren Schlüsseln eine Adresse benutzt.

## Verschlüsselte und signierte Mails lesen

**Verschlüsselte Mails** zeigen ein grünes Schloss und *Ende-zu-Ende verschlüsselt* darüber. plMail
bewahrt auf dem Server keine lesbare Kopie davon auf: Dein Browser holt die Nachricht so, wie sie
angekommen ist, entschlüsselt sie mit deinen Schlüsseln und zeigt sie wie jede andere Mail an —
samt Anhängen, die direkt aus dem Browser heruntergeladen werden. In der Liste steht *Verschlüsselte
Nachricht*. Das gilt für OpenPGP, egal ob als PGP/MIME oder als Block im reinen Text, und für
S/MIME, sobald du dein Zertifikat importiert hast (siehe [S/MIME](#smime) unten).

**Signierte Mails** zeigen, was die Prüfung der Signatur ergeben hat:

- *Signiert, und die Signatur stimmt* — unverändert, und der Schlüssel oder das Zertifikat ist eines,
  für das plMail bürgen kann: ein Zertifikat einer bekannten Stelle, dein eigener Schlüssel oder der
  Schlüssel eines Kontakts, den du als verifiziert markiert hast.
- *Die Nachricht ist unverändert, aber nichts bestätigt, wer signiert hat* — ein selbst
  ausgestelltes Zertifikat oder der Schlüssel eines Kontakts, den noch niemand bestätigt hat. Das
  Banner bietet **Diesem Schlüssel vertrauen** an: Vergleiche vorher den angezeigten Fingerprint mit
  dem Absender.
- *Mit einem Schlüssel signiert, den plMail noch nicht kennt* — der Fingerprint wird angezeigt, und
  das Banner bietet **Schlüssel suchen** in der Domain des Absenders an (siehe unten).
- *Mit einem anderen Schlüssel signiert als dem, den plMail für diesen Absender kennt* — dein Kontakt
  hat vielleicht einen neuen Schlüssel, oder jemand gibt sich als er aus. Das Banner bietet **Neuen
  Schlüssel verwenden** an; tu das erst, wenn der Absender dir auf anderem Weg bestätigt hat, dass
  der Fingerprint seiner ist.
- *Die Signatur passt nicht* — die Nachricht wurde nach dem Signieren verändert, oder jemand anderes
  als der Absender hat sie signiert. Behandle sie wie jede Nachricht, die gefälscht sein könnte.

Dasselbe gilt für eine Signatur in einer verschlüsselten Nachricht, die dein Browser nach dem
Entschlüsseln prüft.

Die Signaturdatei selbst (`signature.asc`, `smime.p7s`) erscheint nicht mehr als Anhang.

## Schlüssel deiner Kontakte

**Einstellungen → Verschlüsselung → Schlüssel deiner Kontakte** listet die öffentlichen Schlüssel,
die plMail für die Menschen hat, denen du schreibst: die Adresse, den Fingerprint in Vierergruppen,
woher der Schlüssel kam, und ein Häkchen bei denen, die du verifiziert hast. plMail prüft damit die
Signaturen dieser Menschen und verschlüsselt an sie, sobald es verschlüsselt senden kann.

Sie kommen aus drei Quellen, und für die ersten beiden musst du nichts tun:

- **Mails, die du bekommst.** Ein Schlüssel im `Autocrypt:`-Header einer Nachricht oder als
  angehängte Schlüsseldatei wird für den Absender gespeichert — nur für den Absender, und nur ein
  Schlüssel, der dessen Adresse nennt.
- **Die Domain des Absenders**, über ihr Web Key Directory (WKD). **Schlüssel suchen** fragt nach
  einer Adresse; das Banner einer Signatur mit unbekanntem Schlüssel fragt nach der des Absenders.
  Die Anfrage geht vom plMail-Server aus, nicht von deinem Browser.
- **Ein Import** — füge einen öffentlichen Schlüsselblock ein oder wähle eine Datei. Der Schlüssel
  wird für jede Adresse gespeichert, die er nennt.

plMail vertraut dem **ersten** Schlüssel, den es für eine Adresse findet. Taucht später ein
**anderer** auf — in einer Nachricht, über WKD oder in einem Import —, steht er mit einer Warnung
unter dem bekannten, und plMail verwendet weiter den bekannten Schlüssel, bis du entscheidest. Ein
neuer Schlüssel eines Kontakts, der den alten verloren hat, und der Schlüssel von jemandem, der sich
als dieser ausgibt, sehen genau gleich aus; nur der Kontakt kann dir sagen, was es ist. Frag ihn nach
dem Fingerprint, am Telefon oder persönlich, und wähle dann **Diesen Schlüssel verwenden** — oder
entferne ihn.

**Als verifiziert markieren** heißt, dass du den Fingerprint mit dem Kontakt verglichen hast. Erst
das macht aus seinen Signaturen statt *unverändert, aber nichts bestätigt, wer signiert hat* ein
*die Signatur stimmt*, und es zeigt ein Häkchen. Niemand sonst kann das bezeugen: Es gibt kein Web of
Trust.

Widerrufene oder abgelaufene Schlüssel und solche, die die Adresse nicht nennen, werden nicht
gespeichert. Jeder Schlüssel hat außerdem **Einstellungen für diesen Empfänger** — verschlüsseln,
signieren, PGP/MIME oder Inline —, nach denen sich das Schreibfenster richtet.

## Verschlüsselt schreiben

Du musst nichts einschalten. Während du die Empfänger eintippst, bekommt jeder, für den plMail einen
Schlüssel hat, ein kleines Schloss — nachgeschlagen bei seiner Domain, falls plMail ihn noch nicht
kannte. Haben **alle** Empfänger einen, wird die Nachricht verschlüsselt: Im Kopf steht
*Verschlüsselt*, und das Schloss in der Werkzeugleiste ist grün. Haben nur manche einen, sagt ein
Hinweis unter den Empfängern, wer nicht verschlüsselt erreichbar ist, und die Nachricht geht
unverschlüsselt an alle — außer du hast unter *Versand* **Nicht senden, wenn nicht alle
verschlüsselt erreichbar sind** eingeschaltet. Eine Antwort auf eine verschlüsselte Nachricht ist
von Anfang an verschlüsselt, mit dem Original so zitiert, wie du es gelesen hast.

Das Schloss in der Werkzeugleiste und **Verschlüsseln** und **Signieren** unter *Weitere Optionen*
entscheiden stattdessen für diese eine Nachricht.

Eine verschlüsselte Nachricht kommt nie lesbar beim Server an, auch nicht als Entwurf: Dein Browser
verschlüsselt den Entwurf vor jedem Speichern mit deinem eigenen Schlüssel, verschlüsselt Anhänge,
bevor sie hochgeladen werden, und baut und verschlüsselt die ganze Nachricht — an alle Empfänger
und an dich, damit die Kopie in *Gesendet* lesbar bleibt —, wenn du auf Senden drückst. Rückgängig,
*Später senden* und *Gesendet* funktionieren wie immer.

**Signieren.** Verschlüsselte Mails werden immer signiert. Unverschlüsselte Mails werden signiert,
wenn sie an jemanden gehen, dessen Schlüssel plMail kennt, oder immer, wenn du **Unverschlüsselte
Mails signieren** einschaltest.

**Versand** (Einstellungen → Verschlüsselung) hat außerdem **Deinen Schlüssel mit jeder Mail
teilen**, standardmäßig an: Ein unsichtbarer `Autocrypt`-Header trägt deinen öffentlichen Schlüssel,
damit Thunderbird, Delta Chat und andere Programme verschlüsselt antworten können.
**Öffentlichen Schlüssel anhängen** hängt ihn zusätzlich als Datei an, für Programme, die Autocrypt
nicht lesen.

## S/MIME

S/MIME ist der andere Weg, Mails zu verschlüsseln und zu signieren: mit **Zertifikaten**, die eine
Zertifizierungsstelle oder deine Organisation ausstellt und die Outlook, Apple Mail und Thunderbird
ohne Zusatzsoftware verstehen. plMail liest, schreibt und signiert S/MIME neben OpenPGP.

**Dein Zertifikat.** Wähle unter **Einstellungen → Verschlüsselung → S/MIME-Zertifikate** die
`.p12`- oder `.pfx`-Datei, die du von deiner Zertifizierungsstelle oder deiner IT bekommen hast, und
gib ihr Passwort ein. Die Datei wird in deinem Browser geöffnet; ihr privater Schlüssel wird unter
deinen Schlüsseln verschlossen, bevor er zum Server geht — genauso wie deine OpenPGP-Schlüssel —, und
weder die Datei noch ihr Passwort verlassen je den Browser. Das Zertifikat muss für eine deiner
Adressen ausgestellt sein und einen RSA-Schlüssel haben. **Als .p12 exportieren** gibt dir Zertifikat
und Schlüssel als neue Datei zurück, verschlossen mit einem Passwort, das du dort eingibst;
**Entfernen** löscht beides. Importierst du ein neueres Zertifikat für dieselben Adressen — sie
werden alle ein, zwei Jahre erneuert —, bleibt das alte als ersetzt markiert erhalten, um Mails zu
öffnen, die damit verschlüsselt wurden.

**Lesen.** S/MIME-verschlüsselte Mails öffnen sich wie verschlüsselte OpenPGP-Mails, mit *Mit S/MIME
verschlüsselt* darüber, sobald du das Zertifikat importiert hast, mit dem sie verschlüsselt wurden.
Ohne eines sagt die Nachricht das. Eine Signatur darin wird ebenfalls geprüft: Mit deinem eigenen
Zertifikat oder einem, für das eine bekannte Zertifizierungsstelle bürgt, *stimmt* sie; mit jedem
anderen ist sie intakt, aber unbestätigt.

**Zertifikate deiner Kontakte.** plMail behält das Zertifikat jeder S/MIME-signierten Mail, deren
Signatur stimmt, für den Absender — aus signierten Mails und aus Signaturen in verschlüsselten Mails.
Sie stehen unter *Schlüssel deiner Kontakte*, mit **Bestätigt von …** bei denen, für die eine
Zertifizierungsstelle bürgt. Ein neueres Zertifikat derselben Stelle ersetzt das alte von selbst;
alles andere — ein selbst signiertes Zertifikat oder ein Wechsel von der einen Art zur anderen — gilt
beim ersten Mal, wie ein OpenPGP-Schlüssel: Das erste wird genutzt, ein anderes wartet, bis du **Diesen
Schlüssel verwenden** wählst.

**Schreiben.** Welche Verschlüsselung eine Nachricht bekommt, wird pro Nachricht entschieden und nie
gemischt:

1. **OpenPGP**, wenn jeder Empfänger einen OpenPGP-Schlüssel hat.
2. Sonst **S/MIME**, wenn jeder Empfänger ein Zertifikat hat, das plMail nutzen kann (aktuell und
   nicht abgelaufen), du eines für die Absenderadresse hast und es keine Bcc-Empfänger gibt.
3. Sonst wird die Nachricht nicht verschlüsselt, und der Hinweis unter den Empfängern sagt wie bisher,
   wem ein Schlüssel fehlt.

Eine S/MIME-Nachricht wird mit deinem Zertifikat signiert und mit AES-256 an das Zertifikat jedes
Empfängers und an deines verschlüsselt — so, wie Outlook und Thunderbird es lesen. Unverschlüsselte
Mails werden mit S/MIME statt OpenPGP signiert, wenn du ein Zertifikat für die Absenderadresse hast
und keiner der anderen Empfänger einen OpenPGP-Schlüssel: Jedes Mailprogramm prüft eine
S/MIME-Signatur, während eine OpenPGP-Signatur für die meisten nur eine Datei namens `signature.asc`
ist. Wann überhaupt signiert wird, regelt weiterhin das oben Gesagte.

## Deinen Schlüssel veröffentlichen (Web Key Directory)

Thunderbird, Proton, GnuPG (`gpg --locate-keys`) und andere plMail-Installationen suchen einen
Schlüssel bei der Domain der Adresse selbst, über ihr Web Key Directory (WKD) — dieselbe Suche, die
plMail für die Schlüssel deiner Kontakte macht. plMail beantwortet diese Suche für die eigenen
Domains der Installation, damit Menschen, die dir schreiben, deinen Schlüssel finden, ohne dass du
ihn erst schicken musst.

**Einstellungen → Verschlüsselung → Web Key Directory** listet deine Adressen auf diesen Domains:

- **Veröffentlicht** heißt, dein aktueller Schlüssel für die Adresse wird ausgeliefert: der aktive —
  bei mehreren der primäre —, also der, mit dem du signierst. Ein erneuerter Schlüssel wird ab dem
  Moment ausgeliefert, in dem er entsteht; ein ersetzter, widerrufener oder abgelaufener nicht mehr.
  Er geht nur mit der Benutzer-ID dieser Adresse hinaus, auch wenn der Schlüssel noch andere Adressen
  nennt.
- **An / Aus** — standardmäßig an, sobald ein Admin die Domain eingeschaltet hat. Aus nimmt den
  Schlüssel sofort heraus; wer dann fragt, bekommt dasselbe „hier ist nichts“ wie für eine Adresse,
  die es nicht gibt.
- Die beiden URLs, die ein Mailprogramm abfragt, und **Von außen prüfen**: Das sucht deine Adresse
  so, wie es alle anderen tun würden — über den Server, wie die Schlüsselsuche für Kontakte — und
  sagt, ob der gefundene Schlüssel dein aktueller ist.

Veröffentlicht wird nur die Adresse, **mit der du dich anmeldest**. Deine anderen Adressen — Aliase,
weitere Konten — stehen mit diesem Grund in der Liste: Sie sind, was eingetippt wurde, und plMail
kann nicht dafür bürgen, dass sie dir gehören. Die Anmeldeadresse ist die, die dir ein Admin gegeben
hat.

### Einrichten

Für Admins:

1. Trag die Domains kommagetrennt in `APP_WKD_DOMAINS` ein (`example.org,example.net`) und starte
   neu. Leer, der Standard, veröffentlicht nichts. Trag nur die eigenen Domains der Installation ein
   — nie eine wie `gmail.com`, weil jemand dort eine Adresse hat.
2. Mach jede Domain auf einem von zwei Wegen für plMail erreichbar:
   - **Erweiterte Methode**, bevorzugt: ein DNS-Eintrag für `openpgpkey.<domain>`, der auf den Host
     von plMail zeigt, und ein TLS-Zertifikat für diesen Namen auf dem plMail-Server. Mit dem
     mitgelieferten Caddy nimmst du den Namen in `SERVER_NAME` auf
     (`mail.example.org, openpgpkey.example.org`), dann holt er das Zertifikat. Clients fragen
     `https://openpgpkey.<domain>/.well-known/openpgpkey/<domain>/hu/…`.
   - **Direkte Methode**: Die eigene Website der Domain leitet alles unter
     `https://<domain>/.well-known/openpgpkey/` an plMail weiter — ein Reverse-Proxy, der den
     `Host`-Header durchreicht. Ist nur eine Domain eingetragen, funktioniert auch eine Weiterleitung,
     die den Host nicht durchreicht.
3. Nutze **Von außen prüfen** mit deiner eigenen Adresse. Admins sehen diese Schritte auch auf der
   Karte, ausgefüllt mit den Domains und dem Host der Installation.

## Auf dem Handy

Wenn du die plMail-App koppelst (Einstellungen → App-Passwörter → **Gerät koppeln**), kommen deine
Schlüssel mit, sofern sie in diesem Browser offen sind — die Zeile unter dem QR-Code sagt es. Sie
reisen versiegelt: Der Schlüssel, der sie öffnet, steckt im QR-Code selbst, den dein Browser zeichnet
und nie an den Server schickt. Verschlüsselte Mails öffnen sich dann in der App ohne Passwort. Ein
Gerät, das ohne sie gekoppelt wurde, bekommt deine Schlüssel einmal mit deinem plMail-Passwort —
oder, wenn du die Verschlüsselung mit einer Passphrase eingerichtet hast, mit dieser Passphrase. Ein
Browser, dessen Schlüssel gesperrt sind, fragt beim Koppeln zuerst nach ihnen.

## Entschlüsselte Mails auf diesem Server behalten

Verschlüsselte Mails werden nicht durchsucht, und Pakete, Termine und Regeln sehen sie nicht — der
Server hat ihren Text nie. Wenn du vor allem deinen **Mail-Anbieter** heraushalten willst und nicht
deinen eigenen Server — eine Gmail- oder Outlook-Adresse etwa, auf einem plMail, das du selbst
betreibst —, kannst du dich pro Konto anders entscheiden, unter **Einstellungen → Verschlüsselung →
Entschlüsselte Mails auf diesem Server**.

Ist es an, entschlüsselt jeder Browser von dir, in dem deine Schlüssel offen sind, die
verschlüsselten Mails dieses Kontos im Hintergrund und gibt sie an den Server, der sie dann wie jede
andere Mail behandelt: Suche, Pakete, Rechnungen, Termine, `.ics`-Einladungen und deine Regeln,
solange die Nachricht noch ungelesen ist. Nachrichten, die du öffnest, werden sofort übergeben. Der
Banner sagt *Verschlüsselt empfangen · auf diesem Server lesbar gespeichert*. Dein Anbieter sieht
weiterhin nur Chiffrat.

**Auch die Modelle lesen lassen** — Einordnen in Kategorien, Suche nach Bedeutung, Zusammenfassungen,
der Schreibassistent — ist ein zweiter Schalter darunter. Steht der Modell-Host im eigenen Netz der
Installation (ein Ollama daneben), ist das alles; steht er woanders, wirst du gebeten, diesem Host
namentlich zuzustimmen, und ein anderer Host braucht später erneut deine Zustimmung.

**Ausschalten löscht die entschlüsselten Kopien**: den Text, die Anhänge, die daraus kamen, den
Suchindex, was die Modelle daraus gemacht haben, gefundene Pakete und Termine. Die verschlüsselten
Originale bleiben, ebenso Termine, die schon in deinem Kalender stehen. Nur die Modelle
auszuschalten löscht, was sie daraus gemacht haben.

Eine Administratorin kann die ganze Option für die Installation verbieten
(`APP_E2E_SERVER_PLAINTEXT`).

## Fallstricke

**Ohne Recovery-Phrase oder bekanntes Gerät verliert ein vergessenes Passwort deine Schlüssel.**
Dabei kann niemand helfen — keine Administratorin, kein Server, kein Backup —, weil außer dir nie
jemand hatte, was sie öffnet. Erstelle die Phrase.

**Die erste Anmeldung nach dem Update erstellt deine Schlüssel in diesem Browser.** Hast du einen
Schlüssel, den du weiter benutzen willst, importiere ihn dann: plMail verwirft seinen eigenen
Schlüssel für diese Adresse nur, solange ihn niemand gesehen hat.

**Eine Sitzung, die „Angemeldet bleiben“ wieder geöffnet hat oder die über Single Sign-on geöffnet
wurde, beginnt gesperrt.** Deine Mails sind alle da; die erste verschlüsselte Mail fragt einmal pro
Sitzung nach deinem Passwort oder deiner Passphrase. Abbrechen lässt sie gesperrt, sonst nichts.

**Eine vergessene Verschlüsselungs-Passphrase ohne Recovery-Phrase kostet deine verschlüsselten
Mails.** Niemand kann sie zurücksetzen — keine Administratorin, nicht dein Anmeldedienst —, weil
niemand außer dir sie je kannte. Ein bekanntes Gerät öffnet deine Schlüssel noch; sonst erstelle die
Phrase an dem Tag, an dem du die Verschlüsselung einrichtest.

**Die Verschlüsselungs-Passphrase ist nicht das Passwort deines Anmeldedienstes.** Das eine zu ändern
ändert das andere nicht, und plMail fragt den Anmeldedienst nie danach. Ein Passwortmanager bietet
im Dialog vielleicht dein Anmeldepasswort an; es öffnet deine Schlüssel nicht.

**Verschlüsselung braucht HTTPS.** Unter einer reinen `http://`-Adresse — etwa einer IP im LAN —
bietet der Browser keine eigene Kryptografie an, also werden dort keine Schlüssel erstellt oder
geöffnet, und Einstellungen → Verschlüsselung sagt das. Anmelden funktioniert trotzdem. Öffne
plMail über seine `https://`-Adresse (oder `localhost`).

**Verschlüsselte Mails werden nicht durchsucht.** plMail bewahrt ihren Text nie auf, also sehen
Suche, Filter auf den Nachrichtentext, Zusammenfassungen und der Assistent nur Absender und Betreff.
Genau darum geht es.

**Externe Bilder in verschlüsselten Mails bleiben gesperrt, ohne Knopf zum Anzeigen.** Sie
anzuzeigen hieße, dem Server die Adressen mitzuteilen, die der Absender verschlüsselt hat. Bilder,
die in der Nachricht selbst stecken, werden angezeigt.

**S/MIME-verschlüsselte Mails öffnen sich nur mit dem Zertifikat, an das sie verschlüsselt wurden.**
Importiere jedes Zertifikat, für das du noch Mails hast, auch die alten; ein entferntes Zertifikat
nimmt seine Mails mit.

**Bei einem S/MIME-Zertifikat wird geprüft, ob eine bekannte Stelle es ausgestellt hat, nicht, ob
es widerrufen ist.** plMail fragt weder Sperrlisten (CRL) noch OCSP ab. Ein Zertifikat, das vor
seinem Ablauf widerrufen wurde, gilt weiter, bis es abläuft.

**Nur `.p12`-Dateien mit AES lassen sich öffnen.** Alte Dateien mit RC2 oder 3DES werden abgelehnt:
Exportiere sie erneut mit AES (OpenSSL 3 tut das von sich aus, Windows mit der Option
*AES256-SHA256*). Zertifikate mit einem Schlüssel auf elliptischen Kurven lassen sich nicht nutzen.

**S/MIME wird nicht genutzt, wenn es Bcc-Empfänger gibt.** Eine S/MIME-Nachricht nennt das
Zertifikat jedes Empfängers außen, alle würden also sehen, wer sie sonst noch bekommen hat. Eine
solche Nachricht geht als OpenPGP hinaus, wenn alle einen Schlüssel haben, sonst unverschlüsselt.

**Mails an plMail-Menschen bleiben OpenPGP.** Alle auf plMail haben einen OpenPGP-Schlüssel, S/MIME
kommt also nur zum Zug, wenn mindestens ein Empfänger keinen OpenPGP-Schlüssel hat, aber ein
Zertifikat.

**Zertifikate deiner Kontakte kommen nur mit signierten Mails.** Es gibt nichts nachzuschlagen: Bitte
den Kontakt, dir zuerst eine signierte Nachricht zu schicken.

**Ein Zertifikat einer eigenen Zertifizierungsstelle gilt als selbst signiert,** es sei denn, die
Administration fügt diese Stelle hinzu (`SMIME_CA_FILE`, siehe die Konfigurationsreferenz).

**Einen Schlüssel zu veröffentlichen braucht die eingetragene Domain und DNS oder eine
Weiterleitung.** Ohne `APP_WKD_DOMAINS` wird nichts veröffentlicht, und die Karte sagt das. Mit der
Variable, aber ohne `openpgpkey.<domain>` (mit gültigem TLS-Zertifikat für diesen Namen) oder eine
Weiterleitung von der Website finden Mailprogramme einfach nichts; **Von außen prüfen** meldet *Kein
Schlüssel gefunden*.

**Die Prüfung läuft vom plMail-Server aus.** Eine Domain, die dort auf eine private Adresse auflöst —
Split-Horizon-DNS, eine Installation im LAN —, gilt als nicht gefunden, auch wenn sie von außen
funktioniert, denn plMail fragt nie private Adressen.

**Eine veröffentlichte Adresse lässt sich als existierend bestätigen.** Wer eine Adresse errät oder
kennt, kann das Verzeichnis fragen und erfährt, dass sie einen Schlüssel hat. Ausgeschaltete und
nicht existierende Adressen bekommen dieselbe Antwort, und massenhaftes Raten wird gebremst — aber
wenn eine Adresse unbekannt bleiben muss, schalte sie aus.

**Nur die Anmeldeadresse wird veröffentlicht.** Ein Alias oder die Adresse eines zweiten Kontos
nicht, auch nicht auf einer veröffentlichten Domain, weil plMail nicht erkennen kann, ob sie wirklich
dir gehört.

**Mailprogramme und Caches behalten einen Schlüssel eine Weile.** Das Verzeichnis liefert einen
erneuerten Schlüssel sofort aus, aber das Programm eines Kontakts nutzt vielleicht weiter den, den es
vorher geholt hat, und ein Cache unterwegs kann ihn bis zu zehn Minuten halten.

**Mails, die vor diesem Update synchronisiert wurden, werden nicht erneut angesehen.** Eine
verschlüsselte oder signierte Nachricht, die früher ankam, zeigt weiter, was sie damals zeigte, bis
sie neu synchronisiert wird.

**Eine verschlüsselte Nachricht zu drucken, druckt keinen Text.** Die Druckansicht zeichnet der
Server, und der hat keinen.

**Ein geänderter Schlüssel wird nie verwendet, bevor du es sagst — auch keiner, den du selbst
importiert hast.** Die Warnung ist der Sinn der Sache: Das ist der Moment, den Kontakt zu fragen,
nicht sie wegzuklicken.

**Wer einen geänderten Schlüssel akzeptiert, entfernt den alten.** Mit dem alten Schlüssel
erstellte Signaturen gelten dann als mit *einem anderen Schlüssel* signiert. Ältere Mails behalten
ihr Ergebnis, außer den letzten zweihundert signierten Nachrichten dieses Absenders, die neu geprüft
werden.

**Mails von vor diesem Update haben keine Schlüssel mitgebracht.** Schlüssel werden aus Mails
übernommen, wenn sie ankommen. Für einen Kontakt, dessen Schlüssel du jetzt brauchst, such ihn oder
importiere ihn.

**Eine Suche verrät der Domain des Kontakts, dass jemand gefragt hat.** Nicht, wer: Die Anfrage kommt
vom plMail-Server. Antworten werden einen Tag lang gemerkt (eine Stunde, wenn es keine gab), und jede
Person kann dreißig Suchen in zehn Minuten stellen.

**keys.openpgp.org wird nicht gefragt.** Nur die eigene Domain des Kontakts, über WKD.

**Ein Entwurf wird versiegelt, sobald er verschlüsselt werden soll, nicht vorher.** Was davor
gespeichert wurde — Text, getippt bevor der letzte Empfänger mit Schlüssel dazukam —, lag so vor, wie
du ihn getippt hast, bis das nächste Speichern ihn ersetzte. Trag zuerst die Empfänger ein oder drück
das Schloss, bevor du schreibst.

**Solange deine Schlüssel in diesem Browser gesperrt sind, wird eine Nachricht, die verschlüsselt
werden soll, weder gespeichert noch gesendet.** Gespeichert läge sie lesbar auf dem Server. Das
Schreibfenster bittet beim ersten Mal, wenn es sie braucht, ums Entsperren; nach **Nicht jetzt** sagt
es das und fragt nicht bei jedem Speichern wieder, beim Senden aber schon. Oder schalte die
Verschlüsselung für diese Nachricht aus.

**Der Betreff wird verschlüsselt; Absender, Empfänger und Datum nicht.** Die braucht eine Mail außen,
um zugestellt zu werden. Der Betreff einer verschlüsselten Nachricht reist innerhalb der
Verschlüsselung, außen steht „...“ — so macht es auch Thunderbird (RFC 9788). Ein Empfänger, dessen
Mailprogramm ihn nicht lesen kann, sieht „...“. Ausschalten lässt es sich unter *Versand* →
**Betreff verschlüsseln**. Dein eigener plMail-Server kennt die Betreffs, die du schreibst, weiterhin,
wie er jeden Entwurf kennt.

**Ein vom Absender verschlüsselter Betreff steht in der Liste als „...“.** Ganz zu sehen ist er, wenn
du die Nachricht öffnest, und in einer Antwort. Mit *Entschlüsselte Mails auf diesem Server* zeigt ihn
auch die Liste.

**Ein verschlüsselter Entwurf öffnet sich nur in einem Browser mit deinen Schlüsseln.** Die
Android-App und andere Mailprogramme sehen ihn ohne Text.

**Wer eine verschlüsselte Nachricht weiterleitet, schickt ihren Text mit, nicht ihre Anhänge.** Häng
sie noch einmal an, wenn sie mitgehen sollen.

**Die Schreibhilfe wird für verschlüsselte Entwürfe nicht angeboten.** Sie würde den Entwurf auf dem
Server lesen.

**Eine geplante verschlüsselte Nachricht wird beim Versand noch einmal geprüft.** Wurde der Schlüssel
eines Empfängers inzwischen ersetzt oder widerrufen, oder haben sich die Empfänger geändert, wird sie
nicht gesendet, und du erfährst es; öffne sie und sende sie noch einmal.

**Microsoft-Konten senden höchstens 4 MB, Verschlüsselung eingerechnet.** Verschlüsselung macht eine
Nachricht etwa ein Drittel größer.

**Mit Bcc-Empfängern wird außen kein Schlüssel genannt.** Sonst könnte jeder sehen, wer die Nachricht
noch bekommen hat. Manche älteren Mailprogramme können eine solche Nachricht nicht entschlüsseln.

**Namen und Größen der Anhänge eines verschlüsselten Entwurfs sieht der Server,** bis die Nachricht
gesendet ist; ihren Inhalt nicht.

**Entschlüsselte Mails auf dem Server stehen in dessen Backups.** Die Option auszuschalten löscht sie
vom Server, nicht aus Backups, die gemacht wurden, solange sie an war.

**Regeln sehen eine entschlüsselte Nachricht nur, solange sie ungelesen ist.** Eine, die du gelesen
hast, bevor ein Browser sie entschlüsselt, verschiebt keine Regel mehr: Das hieße, sie dir unter der
Hand wegzuziehen.

**Mit *Entschlüsselte Mails auf diesem Server* werden auch Entwürfe auf dem Server nicht
verschlüsselt,** und verschlüsselt gesendete Mails behalten dort ihren Text. Genau dafür ist die
Option da.

**Solange kein Browser mit deinen Schlüsseln offen ist, wird nichts entschlüsselt.** Das Entschlüsseln
im Hintergrund geht weiter, sobald wieder einer offen ist.

**Dein Passwort in den Einstellungen zu ändern, nimmt deine Schlüssel mit.** Jede andere Änderung —
durch eine Administratorin, mit `app:user:password` — sperrt sie, und die Recovery-Phrase oder ein
bekanntes Gerät öffnet sie wieder.
