<!-- translated-from: features/integrations.md sha1:7f9932fddb5e543555f7c6a0aa6f89ba179cc80d -->

# Dateien und Integrationen

plMail kann eine Datei aus einem Dienst holen, den du ohnehin benutzt, und sie an eine
Nachricht hängen — und einen Anhang in die andere Richtung in diesen Dienst schieben.
Unterstützt werden sieben Dienste: **Nextcloud**, **Immich**, **Paperless-ngx**, **Google
Drive**, **Google Photos**, **OneDrive** und **Dropbox**.

Verbindungen gehören einer Person und lassen sich einzeln widerrufen. Welche Dienste überhaupt
angeboten werden, entscheidet die Administration — siehe [Administration](admin.md).

## Einen Dienst verbinden

**Einstellungen → Integrationen**. Dienste, die deine Administration freigeschaltet hat,
erscheinen unter **Dienst verbinden**; bei allem, was nicht freigeschaltet ist, steht *Deine
Administration hat diesen Dienst nicht freigeschaltet.*, und alles, was plMail noch nicht
ansprechen kann, steht getrennt unter **Hier nicht verfügbar**, wobei die Einrichtungshinweise
weiterhin lesbar bleiben.

Wie du dich verbindest, hängt vom Dienst ab:

| Dienst | Wie er authentifiziert | Was du angibst |
|---|---|---|
| Nextcloud | App-Passwort | Serveradresse, Benutzername, App-Passwort |
| Immich | API-Schlüssel | Serveradresse, API-Schlüssel |
| Paperless-ngx | API-Token | Serveradresse, API-Token |
| Google Drive | Anmeldung | Nichts — die Zustimmungsseite |
| Google Photos | Anmeldung | Nichts — die Zustimmungsseite |
| OneDrive | Anmeldung | Nichts — die Zustimmungsseite |
| Dropbox | Anmeldung | Nichts — die Zustimmungsseite |

Die Dienste mit App-Passwort nehmen zusätzlich einen **Namen**, der nur dir angezeigt wird und
dafür da ist, zwei Verbindungen auseinanderzuhalten. Das ist keine Zierde: Du kannst
berechtigterweise zwei Nextclouds haben, privat und dienstlich, und genau deshalb bleibt der
Knopf nach der ersten Verbindung erreichbar.

Hat deine Administration für einen selbst gehosteten Dienst eine Serveradresse festgelegt,
fehlt das Adressfeld ganz, statt deaktiviert zu sein, und ein trotzdem abgeschickter Wert wird
ignoriert.

Lege bei den selbst gehosteten Diensten Zugangsdaten auf dem Dienst an, statt dein
Anmeldepasswort zu verwenden. Nextcloud führt App-Passwörter unter **Einstellungen → Sicherheit
→ Geräte & Sitzungen**; Immich führt API-Schlüssel unter **Account Settings → API Keys**;
Paperless-ngx zeigt ein API-Token unter **My Profile → API Auth Token**. Ein App-Passwort oder
Token lässt sich einzeln widerrufen und funktioniert neben der Zwei-Faktor-Authentifizierung;
ein Anmeldepasswort kann beides nicht.

Beim Speichern wird die Verbindung immer sofort geprüft. Eine Verbindung, die sauber abgelegt
wird und keinen Ordner auflisten kann, ist schlimmer als ein sichtbarer Fehler, denn du fändest
es erst mitten beim Schreiben einer Nachricht heraus. Das Ergebnis bleibt an der Verbindung
vermerkt, sodass die Liste sagen kann, dass eine Verbindung schal geworden ist, bevor du sie
das nächste Mal brauchst.

## Eine Verbindung verwalten

Jeder verbundene Dienst bietet **Testen**, **Pausieren** / **Fortsetzen**, **Bearbeiten** und
**Trennen**.

**Testen** prüft auf Zuruf erneut, für den Fall, dass der Dienst ausgefallen war oder am
anderen Ende Zugangsdaten gewechselt wurden. **Pausieren** behält die Verbindung, nimmt sie
aber aus jedem Menü. **Trennen** entfernt sie; bereits an Mail gehängte Dateien bleiben
unberührt.

Im Bearbeitungsformular ist das Feld für die Zugangsdaten schreibgeschützt in dem Sinne, dass
nur hineingeschrieben werden kann. Lässt du es leer, bleibt das Gespeicherte erhalten, eine
Verbindung umzubenennen heißt also nicht, ein App-Passwort neu einzufügen, das du womöglich gar
nicht mehr hast.

## Was jeder Dienst kann

Nicht jeder Dienst kann alles, und die Oberfläche richtet sich nach der Fähigkeit und nicht
nach dem Namen des Dienstes — ein Dienst, der eine Fähigkeit gewinnt oder verliert, ändert sich
also an genau einer Stelle.

| | Durchsehen | Herunterladen | Hochladen | Freigabelink | Vorschau | Suche | Zeitleiste |
|---|---|---|---|---|---|---|---|
| Nextcloud | ja | ja | ja | ja | ja | ja | — |
| Google Drive | ja | ja | ja | ja | ja | ja | — |
| OneDrive | ja | ja | ja | ja | ja | ja | — |
| Dropbox | ja | ja | ja | ja | ja | ja | — |
| Paperless-ngx | ja | ja | ja | — | ja | ja | — |
| Immich | ja | ja | ja | — | ja | ja | ja |
| Google Photos | ja | ja | ja | — | ja | — | — |

Die praktischen Folgen: Ein Dienst ohne **Hochladen** erscheint nie unter **Speichern in** oder
in der Filteraktion `saveToIntegration`; ein Dienst ohne **Freigabelink** kann nur eine Kopie
anhängen; ein Dienst ohne **Suche** bekommt in der Dateiauswahl kein Suchfeld. Immich ist der
einzige, der seine Bibliothek als Daten zusammenfassen kann, und genau das ist sein Schieber.

Google Photos hat keine Textsuche, weil seine Library-API keine anbietet — nur Album- und
Datumsfilter.

Paperless-ngx hat aus einem anderen Grund keinen Freigabelink: Es kann ein Dokument teilen, aber
nur über eine eigene, rechtegesteuerte Funktion mit eigenem Ablaufdatum, und ein abgelegtes
Dokument zu veröffentlichen ist ein viel größerer Schritt, als es an eine Nachricht zu hängen.

## Paperless-ngx, das keine Ordner hat

Paperless hat Ordner bewusst durch Schlagwörter ersetzt, also benutzt plMail seine
**Schlagwörter** als Ordnerebene. Die Dateiauswahl öffnet mit allen Schlagwörtern als Ordnern und
den neuesten Dokumenten darunter; ein Schlagwort zu öffnen listet dessen Dokumente. **Speichern
in** legt einen Anhang unter einem Schlagwort ab, und ein leeres Ziel legt ihn unter keinem ab —
im Posteingang von Paperless selbst, wo ein unsortiertes Dokument hingehört.

Die Suche läuft über den Volltextindex von Paperless, der auch die OCR-Ebene liest, nicht nur
Titel und Metadaten. Eine gescannte Quittung ist damit über eine Zeile auffindbar, die nie jemand
getippt hat, und genau darum geht es: Es gibt keinen Grund, erst Paperless zu öffnen, ein
Dokument zu suchen und herunterzuladen, bevor du es anhängst. Eine Suche aus einem Schlagwort
heraus bleibt in diesem Schlagwort.

Angehängt wird die **archivierte** Fassung — das durchsuchbare PDF, das Paperless beim Ablegen
erzeugt hat — und ersatzweise das Original, wo keine erzeugt werden konnte.

## Aus einem Dienst anhängen

Im Schreibfenster öffnet **Aus einem Dienst anhängen** die Dateiauswahl in einem Dialog. Sie
bietet jeden verbundenen Dienst an, aus dem plMail **herunterladen** kann, denn ein Dienst, den
man auflisten, aber nicht abrufen kann, öffnete eine Auswahl, die nichts anhängen könnte.

Ordner sind schlichte Links innerhalb des Dialogs, es gibt also kein Routing im Browser, und der
Zurück-Knopf verhält sich anständig. Fotobibliotheken erscheinen als Raster aus Vorschaubildern
und Dateispeicher als Liste von Namen — niemand erkennt ein Foto daran, `IMG_4821.jpg` zu
lesen, und niemand sucht eine Tabelle aus einer Wand von Miniaturbildern heraus.

Vorschaubilder laufen über plMail und werden nicht vom Browser geholt, denn die Dienste legen
Vorschauen hinter dieselben Zugangsdaten wie die Originale. Etwas ganz ohne Vorschau — ein Zip,
ein nie erzeugter Gesichtsausschnitt — bekommt einen neutralen Platzhalter statt eines
gescheiterten Requests.

Jede ausgewählte Datei wird als **Kopie** angehängt oder, wo der Dienst es unterstützt, als
**Link**. Eine Kopie zieht die Bytes in den Entwurf und zählt gegen die Anhangsgrenze von **25
MB je Datei**; ein Link fragt den Dienst nach einer öffentlichen URL und bewegt nichts.

Ist ein Dienst ausgefallen, sagt die Dateiauswahl das dort, wo die Dateien gestanden hätten.
Sie antwortet nicht mit einer Fehlerseite — ein ausgefallener Dienst ist eine Tatsache über die
Verbindung, an ihr vermerkt, damit die Einstellungsliste sie zeigen kann, und kein Grund, mit
dem Zeichnen aufzuhören.

## Einen Anhang hinausspeichern

**Speichern in** auf einem Anhang in einer Nachricht lädt ihn in einen verbundenen Dienst hoch,
der Uploads unterstützt. Wo er landet, ist die Vorgabe des Dienstes selbst — die Wurzel der
Dateien oder kein Album —, sofern an der Verbindung kein Ordner eingestellt ist.

Das funktioniert auch für Anhänge, die plMail nie lokal gespeichert hat: Ein Anhang aus Gmail
oder Microsoft Graph wird beim ersten Zugriff materialisiert und lädt genau so hoch wie ein
lokal gespeicherter.

Ein Filter kann dasselbe automatisch tun, mit der Aktion **Anhänge speichern in**; siehe
[Filter](filters.md).

## Wo du weiterliest

- [Administration](admin.md) — Dienste freischalten, OAuth-Anwendungen registrieren, eine
  Serveradresse festlegen.
- [Filter](filters.md) — Anhänge in einen Dienst speichern, ohne gefragt zu werden.
- [Mail](mail.md) — das Schreibfenster und die Anhangsgrenze.
- [Sicherheitsmodell](../internals/security-model.md) — wie gespeicherte Zugangsdaten
  verschlüsselt werden.

## Fallstricke

**Eine private Adresse oder eine Loopback-Adresse wird abgelehnt, solange sie nicht auf der
Erlaubnisliste steht.** Selbst gehostete Dienste liegen auf Adressen, auf die eine angemeldete
Person plMails ausgehenden HTTP-Client sonst richten könnte — darunter `localhost:5432` und der
Cloud-Metadaten-Endpunkt unter `169.254.169.254`. Loopback, Link-Local, RFC1918 und die Bereiche
für Carrier-Grade NAT sind alle gesperrt, solange der Host nicht in
`INTEGRATIONS_ALLOWED_HOSTS` steht. In einem Heimnetz ist das die Einstellung, die Nextcloud,
Immich und Paperless-ngx überhaupt erreichbar macht.

**`http://` wird abgelehnt, solange `INTEGRATIONS_ALLOW_HTTP` nicht an ist.** Selbst hosten im
LAN ohne TLS ist eine ganz gewöhnliche Lage, diese Option wird also oft gesetzt sein — der Punkt
ist, dass Zugangsdaten im Klartext zu verschicken eine bewusste Entscheidung wird und keine
stille Voreinstellung.

**Eine Datei über der Anhangsgrenze lässt sich aus Immich, Google Photos oder Paperless-ngx
überhaupt nicht anhängen.** Die Fotobibliotheken können für ein einzelnes Objekt keine
öffentliche URL erzeugen, ohne ein geteiltes Album anzulegen; Paperless kann ein Dokument teilen,
aber nur über eine rechtegesteuerte Funktion mit eigenem Ablaufdatum, und ein abgelegtes Dokument
zu veröffentlichen ist eine schwerere Nebenwirkung, als das Anhängen einer Datei haben sollte. Es
gibt deshalb bei keinem der drei einen Rückfall auf einen Link: über 25 MB lautet die Antwort
nein.

**Das Speichern nach Paperless-ngx meldet die Übergabe, nicht das abgelegte Dokument.** Der
Upload-Endpunkt arbeitet asynchron: Er übergibt die Datei an den Paperless-Consumer und antwortet
mit einer Task-ID, und das Dokument erscheint Sekunden später, sobald der Consumer es eingelesen
hat — oder gar nicht, wenn Paperless es als Dublette ablehnt. plMail meldet Erfolg, sobald
Paperless die Datei angenommen hat, und das ist der letzte Moment, von dem plMail etwas weiß.
Schau in Paperless nach, wenn ein Dokument nicht auftaucht.

**Eine frisch registrierte Google-Photos-Anwendung kann eine bestehende Bibliothek meist nicht
durchsehen.** Google hat die Photos-Lesescopes im März 2025 eingeschränkt; eine neue App bekommt
in der Regel nur `appcreateddata` und sieht damit nichts als Medien, die plMail selbst
hochgeladen hat. Anhänge nach Photos zu speichern funktioniert unabhängig davon — beim
Durchsehen kann eine leere Bibliothek erscheinen, bis der Lesescope bewilligt ist.

**Google Drive fragt nach dem vollen `drive`-Scope, und der braucht Googles Überprüfung.** Der
engere `drive.file` sieht immer nur Dateien, die die Anwendung selbst erzeugt hat oder die die
Person über Googles eigene Auswahl im Browser bestimmt hat — ein serverseitig gerenderter
Browser zeigte also ein leeres Drive, und ein Freigabelink braucht Schreibzugriff auf die Datei,
die geteilt wird.

**Eine festgelegte Serveradresse überschreibt bestehende Verbindungen rückwirkend.** Legt die
Administration eine fest, hören vor der Festlegung angelegte Verbindungen auf, ihre eigene
Adresse zu benutzen; eine alte Zeile darf nicht weiter woandershin greifen.

**Eine Verbindung wird neu geprüft, wenn ein Filter sie benutzt, und nicht aus der gespeicherten
Regel heraus geglaubt.** Ein getrennter, pausierter oder nicht mehr upload-fähiger Dienst macht
die Speicheraktion des Filters zu einem Nichtstun mit einer Warnung im Protokoll, statt zu einem
Fehler, den die Person zu sehen bekommt.
