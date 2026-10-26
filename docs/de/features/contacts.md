<!-- translated-from: features/contacts.md sha1:713dc966d588067fd0d5ad0abafcfad4a6e76e2e -->

# Kontakte

plMail führt ein Adressbuch: die Menschen, denen du schreibst, mit ihren Adressen, Telefonnummern,
Postanschriften, Geburtstagen, einem Foto und Notizen. Es ist eine Liste, egal wo ein Kontakt liegt. Manche
gehören nur plMail und gibt es nur hier. Andere kommen aus einem Adressbuch auf einem Server, und
Änderungen gehen in beide Richtungen:

- ein **Google**- oder **Microsoft**-Mailkonto bringt seine Kontakte mit, auf derselben Anmeldung
  wie seine Mail und Kalender — nichts zu verbinden (siehe [Google](../providers/google.md) und
  [Microsoft](../providers/microsoft.md));
- deine [Nextcloud](../providers/carddav.md#einen-server-verbinden) oder ein anderer CardDAV-Server
  wird unter **Einstellungen → Kontakte** verbunden.

Ein Konto, das verbunden wurde, bevor plMail Kontakte synchronisiert hat, braucht eine weitere
Anmeldung, um sie zu erlauben: **Einstellungen → Kontakte** und **Einstellungen → Zustand der Konten** bieten sie
beide an.

Du öffnest es mit dem Adressbuch-Symbol neben dem Kalender-Knopf. Wie der Kalender-Knopf schaltet
es durch drei Stellungen: das Adressbuch neben der Mail, dann in voller Breite mit deiner Mail
dahinter, dann wieder zu. **Einstellungen → Kontakte → Verhalten** macht daraus zwei Stellungen
(**Nur volle Breite** oder **Nur neben der Mail**), wie beim Kalender. Auf dem Handy öffnet
**Kontakte** im Menü die ganze Seite.

Das angedockte Adressbuch ist das ganze Adressbuch: Du suchst, öffnest, bearbeitest, legst an und
führst Kontakte dort zusammen, während deine Mail bleibt, wo sie war. Zieh an seinem Rand, um es
breiter oder schmaler zu machen (oder nimm die Pfeiltasten am Rand; ein Doppelklick stellt es
zurück). Ziehst du es über seine größte Breite hinaus und lässt los, geht es auf volle Breite; über
seine kleinste hinaus, schließt es sich. plMail merkt sich Breite und Stellung. Die Pfeile in seiner
Kopfzeile schalten auf volle Breite, und das × schließt es. Öffnest du bei voller Breite eine
Nachricht aus einem Kontakt, kommt die Mail daneben zurück.

Auf einem breiten Monitor (ab 1920 Pixeln Breite) können Adressbuch und Kalender nebeneinander
angedockt sein. Auf einem kleineren Bildschirm, etwa dem eines Laptops, ist neben der Mail Platz für
eines von beiden, also räumt das eine das andere weg. Unter 1024 Pixeln öffnet das Symbol die ganze
Seite.

## Die Liste

Jeder Kontakt als Karte: Foto oder Initialen, Name, Position und Firma und die wichtigste
E-Mail-Adresse. Die Suche findet einen Kontakt über jeden seiner Namen, seine Firma oder jede seiner
Adressen. Schlagwörter (du vergibst sie an einem Kontakt) stehen als Chips über der Liste und
grenzen sie auf ein Schlagwort ein.

Sobald du ein synchronisiertes Adressbuch hast, wählt eine zweite Reihe Chips, wo du suchst: **Alle
Bücher**, **Nicht synchronisiert** (die Kontakte, die nur in plMail liegen) oder ein Adressbuch. Ein
synchronisierter Kontakt trägt eine kleine Wolke, oder ein Schloss, wenn sein Adressbuch
schreibgeschützt ist. Eine Uhr heißt, dass eine hier gemachte Änderung darauf wartet, an den Server
zu gehen.

## Ein Kontakt

Die Seite eines Kontakts zeigt seine Angaben, seine Notizen, verwandte Kontakte und
**Referenz-E-Mails**: jede nicht gelöschte Nachricht an ihn oder von ihm, in jedem Ordner. Ein
Klick öffnet sie in der Mail neben dem Kontakt, der bleibt, wie er ist; auf der ganzen Seite schiebt
sich die Mail von links herein.

**Neuer Kontakt** öffnet die **Schnellerfassung**: ein Feld, in das du irgendetwas über die Person
tippst oder einfügst — einen Namen, eine E-Mail-Signatur, eine Zeile wie
`Mia Hoffmann <mia@example.net>`, eine Adresse oder eine ganze vCard. Es wird beim Tippen gelesen,
und was gefunden wird, erscheint darunter als Zeilen: Jede kannst du abwählen, neu tippen oder mit
einem Klick einem anderen Typ zuordnen (eine Arbeitsnummer, die eine private ist, eine Zeile, die als
Position erkannt wurde und eigentlich die Firma ist). Eine Zeile, die nicht zugeordnet werden konnte,
wird erfragt statt geraten, eine Grußformel über einer Signatur wird weggelassen, und eine Adresse,
die schon bei einem deiner Kontakte steht, wird angezeigt. Eine vCard wird Feld für Feld gelesen,
nichts wird geraten. **Alle Felder** öffnet den vollständigen Editor mit allem, was gefunden wurde.

Der **Editor** zeigt den Namen als ein Feld; seine Teile (Anrede/Titel, Vor-, zweiter Vor- und
Nachname, Namenszusatz) füllen sich beim Tippen und öffnen sich unter **Teile bearbeiten**. Bearbeitest
du einen Teil von Hand, bleibt er so, und die Seite sagt, wenn Name und Teile nicht mehr zusammenpassen.
E-Mail und Telefon enden in einer gestrichelten Zeile — tipp hinein, und sie wird zu einer Zeile,
als privat oder geschäftlich nach der Adresse oder als Handy nach der Nummer eingeordnet, bis du
selbst einen Typ wählst. Der Stern markiert die bevorzugte. Jede andere Art von Angabe erscheint nur,
wenn der Kontakt eine hat; die Chips unter **Hinzufügen** holen den Rest. Sobald sich etwas geändert
hat, sagt eine Leiste unten, wie viel, und bietet **Speichern** und **Verwerfen** an.

**Details einfügen** im Editor liest eingefügten Text genauso und vergleicht ihn mit dem Kontakt,
bevor sich etwas ändert: Was schon da ist, ist ausgegraut, was neu ist, ist angehakt, und was vom
Gespeicherten abweicht („Ersetzt …“ oder ein anderer Typ für dieselbe Nummer), hakst du selbst an.
**Übernehmen** trägt das Angehakte ins Formular ein und markiert es; gespeichert wird erst, wenn du
speicherst.

Hat deine Administration die **Kontakthilfe** eingeschaltet (Admin → KI), haben beide Seiten
**Mit KI verbessern**. In der Schnellerfassung liest es den Text ein zweites Mal — die Firma, die die
Regeln nicht von einem Slogan unterscheiden konnten, eine Faxnummer, die als `Fax -99` dasteht — und
markiert, was es geändert hat; **KI-Änderungen rückgängig** geht zurück. Im Editor schickt es den
Kontakt und die Signatur der neuesten Mail, die der Kontakt dir geschickt hat, und zeigt seine
Vorschläge, jeden mit Begründung, in derselben Liste wie eingefügte Details. Für dich selbst schaltest
du es unter Einstellungen → Assistent ab.

Ein Kontakt hat:

- seinen Namen in Teilen (Anrede/Titel, Vor-, zweiter Vor- und Nachname, Namenszusatz), einen
  Spitznamen und wie die Namen gesprochen werden (**Phonetische Schreibweise**, für Namen, die ein
  Handy anders sortieren oder vorlesen soll);
- Firma, Abteilung, Position und Büro;
- E-Mail-Adressen, Telefonnummern und Postanschriften, jede mit Bezeichnung und je eine als primär
  markiert;
- einen Geburtstag und weitere Daten (etwa einen Jahrestag) — das Jahr darf fehlen;
- **Personen**: die Namen von Ehepartner, Kindern, Vorgesetzten, Assistenz und so weiter;
- Websites, Chat-Adressen (SIP, XMPP, Skype, Teams, Matrix und andere) und **eigene Felder** mit
  selbst gewählten Namen;
- Notizen, Schlagwörter und ein Foto.

Eine Bezeichnung, die vom Server kommt und keine von plMails eigenen ist (eine auf dem Handy
getippte „Patentante“), wird so gezeigt, wie sie ist, und bleibt beim Speichern erhalten.

Hast du ein synchronisiertes Adressbuch, wählst du unter **Speichern in**, wohin ein neuer Kontakt
kommt: ein Knopf pro Adressbuch, wenn du bis zu vier hast, ein Menü, wenn es mehr sind. **plMail**
behält ihn nur in plMail. Sonst geht er an den Server dieses Adressbuchs und von dort auf jedes
Gerät, das es synchronisiert; die Zeile unter den Knöpfen sagt, wohin. Ein schreibgeschütztes
Adressbuch wird nie angeboten.

Bearbeiten geht bei beiden Arten gleich. Bei einem synchronisierten Kontakt wird die Änderung hier
sofort gespeichert und gleich danach an den Server geschickt, und von dort auf dein Handy. Was
plMail nicht als Feld hat, etwa die Eigenschaften, mit denen ein Handy einen Kontakt an eine App
bindet, bleibt auf der Kopie des Servers unberührt. Google und Outlook haben für manche Felder
keinen Platz; siehe **Fallstricke**. Wie
Änderungen und Konflikte behandelt werden, steht unter
[CardDAV](../providers/carddav.md#synchronisation).

Einen Kontakt aus einem **schreibgeschützten** Adressbuch (einem geteilten Buch, einem
Firmenverzeichnis) kannst du lesen und anschreiben, aber hier nicht ändern oder löschen. Seine Seite
sagt, woher er kommt.

**Fotos** sind JPG, PNG oder WebP bis 5 MB und werden mit höchstens 512 Pixeln an der längeren
Seite gespeichert. Sie gehen in beide Richtungen: Ein Foto auf dem Server wird hier angezeigt, und
ein Foto, das du hier hochlädst oder entfernst, geht an den Server. Im Editor lädt die Kamera am
Foto eines hoch, und **Foto entfernen** unten entfernt es.

## Löschen

**Löschen** legt einen Kontakt in den Papierkorb. Einen synchronisierten Kontakt zu löschen löscht
auch seine Karte auf dem Server, und der Kontakt bleibt im Papierkorb als einer, der nur in plMail
liegt.

## Duplikate

**Duplikate** listet Paare, die nach derselben Person aussehen, mit der Sicherheit des Treffers und
dem Grund (dieselbe E-Mail-Adresse, dieselbe Telefonnummer, ein ähnlicher Name). Wähle den, der
bleiben soll, und **Zusammenführen**. Adressen, Nummern und Schlagwörter des anderen wandern zum
behaltenen Kontakt, und seine Notizen werden unter die des behaltenen gehängt. Eine Zusammenführung
lässt sich 90 Tage lang rückgängig machen.

Synchronisierte Kontakte werden zusammengeführt wie alle anderen. Die Karte des behaltenen wird
aktualisiert, und die des zusammengeführten wird auf seinem Server gelöscht, sodass ein Rückgängig
den zusammengeführten Kontakt als einen zurückbringt, der nur in plMail liegt. Kontakte aus einem
schreibgeschützten Adressbuch lassen sich nicht zusammenführen.

## Import und Export

**Import** nimmt eine `.vcf`-Datei (vCard, wie jedes Handy und jedes Adressbuch sie exportiert) oder
eine `.csv`-Datei. Du siehst zuerst eine Vorschau. Importierte Kontakte liegen nur in plMail, und
danach landest du auf der Duplikate-Seite, um zusammenzuführen, was du schon hattest.

**Export** lädt alle Kontakte als eine `.vcf`-Datei herunter, mit Fotos. Ein synchronisierter
Kontakt wird als Karte seines Servers exportiert, mit deinen Änderungen darin, sodass die Datei
behält, wofür plMail kein Feld hat. Der CSV-Export ist eine Übersicht für eine Tabelle — eine
E-Mail-Adresse, eine Nummer und eine Website pro Kontakt — und lässt sich wieder importieren; um
Kontakte vollständig umzuziehen, nimm vCard.

## Beim Lesen und Schreiben von E-Mails

Beim Absender einer Nachricht und bei jeder Adresse in ihren Details (An, Cc, Antwort an) siehst du,
ob sie schon in deinen Kontakten steht, mit einem Knopf, um sie hinzuzufügen, wenn nicht. Der Knopf
fragt, wer das ist:

- **Neuer Kontakt**: Prüf den Namen aus der E-Mail und speichere. *Weitere Angaben* öffnet den
  vollen Editor, Name und Adresse sind schon eingetragen.
- **Zu einem bestehenden Kontakt hinzufügen**: Such beim Tippen in deinen Kontakten und wähl einen
  aus. Die Adresse kommt zu diesem Kontakt dazu, so bleibt jemand, der von einer zweiten Adresse
  schreibt, eine Person. Die Suche beginnt mit dem Namen des Absenders. Kontakte in
  schreibgeschützten Adressbüchern werden nicht angeboten.

Nach dem Speichern öffnet sich der Kontakt im angedockten Adressbuch neben der Mail. War es zu,
geht es auf, war es offen, springt es zu diesem Kontakt. Auch *Weitere Angaben* öffnet den Editor
dort. Auf dem Handy wird nichts angedockt: *Weitere Angaben* öffnet den Editor als Seite, und nach
dem Speichern wird der Knopf zu einem Link auf den Kontakt.

Adressierst du eine Nachricht, werden dir deine Kontakte
vorgeschlagen, zusammen mit den Adressen, die plMail aus deinen E-Mails kennt. Die Reihenfolge steht
unter [Vorschläge beim Schreiben](../providers/carddav.md#vorschläge-beim-schreiben).

## Auf dem Handy

Die plMail-Apps lesen und bearbeiten dieselben Kontakte über JMAP (`Contact/get`, `Contact/query`,
`Contact/set`). Jeder Kontakt sagt, in welchem Adressbuch er liegt und ob er sich ändern lässt. Eine
App kann einen Kontakt aus einem schreibgeschützten Buch nicht ändern, und ihre Änderungen an einem
synchronisierten Kontakt gehen an den Server weiter wie Änderungen im Web.

## Fallstricke

**Der Papierkorb hat noch keine Seite.** Ein gelöschter Kontakt wird aufbewahrt, aber im Web holt
ihn nichts zurück. Führe Duplikate lieber zusammen, statt sie zu löschen: Eine Zusammenführung lässt
sich rückgängig machen.

**Ein Import legt Kontakte an, die nur in plMail liegen.** Willst du eine `.vcf`-Datei in ein
synchronisiertes Adressbuch bringen, importiere sie auf dem Server (in der Nextcloud unter
Kontakte), und die Kontakte kommen mit der nächsten Synchronisierung hierher.

**Ein Kontakt kann nicht zwischen Adressbüchern wandern**, auch nicht aus plMail in ein
synchronisiertes. Lege ihn am anderen Ort an und lösche den alten.

**Google und Outlook fassen nicht alles.** Google behält jedes Feld oben. Outlook hat feste Plätze:
drei E-Mail-Adressen, zwei geschäftliche und zwei private Nummern und eine Mobilnummer, je eine
Anschrift jeder Art, je einen Ehepartner, Vorgesetzten und eine Assistenz und beliebig viele Kinder,
eine Website, drei Chat-Adressen und einen Geburtstag. Weitere Daten, einen phonetischen zweiten
Vornamen und eigene Felder kennt es nicht. Was nicht passt, wird nicht gesendet und bleibt nur in
plMail, bis sich der Kontakt das nächste Mal in Outlook ändert (siehe
[Microsoft](../providers/microsoft.md#kontakte-hängen-ebenfalls-an-derselben-anmeldung)).

**Outlook behält ein hier entferntes Foto.** Microsofts Schnittstelle kann das Foto eines
Outlook-Kontakts ersetzen, aber nicht entfernen; ein in plMail entferntes Foto kommt also zurück,
sobald sich der Kontakt in Outlook ändert. Entferne es in Outlook.

**Eigene Felder und das Büro gehören auf einem CardDAV-Server plMail.** vCard hat für beides keine
Eigenschaft, also stehen sie unter plMails eigenen Namen auf der Karte (`X-PLMAIL-FIELD`,
`X-PLMAIL-OFFICE`). Sie überstehen den Hin- und Rückweg, aber ein Handy zeigt sie nicht.
