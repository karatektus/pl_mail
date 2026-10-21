<!-- translated-from: features/contacts.md sha1:49c4d9dde9ba2c50954b3d7f30351d7a4a1f60fb -->

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

Du öffnest es über **Kontakte** in der Seitenleiste als ganze Seite, oder über das Adressbuch-Symbol
neben dem Kalender-Knopf, das es neben der Mail andockt, so wie den Kalender. Das angedockte
Adressbuch ist das ganze Adressbuch: Du suchst, öffnest, bearbeitest, legst an und führst Kontakte
dort zusammen, während deine Mail bleibt, wo sie war. Zieh an seinem Rand, um es breiter oder
schmaler zu machen (oder nimm die Pfeiltasten am Rand; ein Doppelklick stellt es zurück), und plMail
merkt sich die Breite und ob es offen war. Die Pfeile in seiner Kopfzeile öffnen dieselbe Seite in
voller Größe, und das × schließt es.

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

**Neuer Kontakt** fragt nach einem Namen; alles andere ist freiwillig. Ein Kontakt hat:

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

Hast du ein
synchronisiertes Adressbuch, wählst du unter **Speichern in**, wohin der Kontakt kommt. **Nicht
synchronisiert** behält ihn nur in plMail. Sonst geht er an den Server dieses Adressbuchs und von
dort auf jedes Gerät, das es synchronisiert.

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
ein Foto, das du hier hochlädst oder entfernst, geht an den Server. Das Papierkorb-Symbol neben
**Foto hochladen** entfernt es.

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

Beim Absender einer Nachricht siehst du, ob er schon in deinen Kontakten steht, mit einem Knopf, um
ihn hinzuzufügen, wenn nicht. Adressierst du eine Nachricht, werden dir deine Kontakte
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
