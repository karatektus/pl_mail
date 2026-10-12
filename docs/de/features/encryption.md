<!-- translated-from: features/encryption.md sha1:c12805c61e21916d34811f9531ae1b6fb1f3a640 -->

# Verschlüsselung

plMail baut Ende-zu-Ende-Verschlüsselung mit OpenPGP: Mails, die nur du und die Menschen lesen
können, denen du schreibst — nicht der plMail-Server und nicht dein Mail-Anbieter. Diese Seite
beschreibt, was es heute schon gibt: deine Schlüssel, wie sie aufbewahrt werden und wie du sie
zurückbekommst. Verschlüsselte Mails lesen und senden folgt in den nächsten Versionen.

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

## Du meldest dich einmal an

Die Anmeldung entsperrt deine Schlüssel für die ganze Sitzung, in jedem Tab, ohne erneute Frage.
Endet eine Sitzung — du meldest dich ab, oder sie läuft aus —, sind sie wieder gesperrt. Eine
Sitzung, die **Angemeldet bleiben** geöffnet hat statt eines getippten Passworts, beginnt gesperrt:
Die Einstellungsseite fragt dann einmal nach deinem Passwort.

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

## Fallstricke

**Ohne Recovery-Phrase oder bekanntes Gerät verliert ein vergessenes Passwort deine Schlüssel.**
Dabei kann niemand helfen — keine Administratorin, kein Server, kein Backup —, weil außer dir nie
jemand hatte, was sie öffnet. Erstelle die Phrase.

**Die erste Anmeldung nach dem Update erstellt deine Schlüssel in diesem Browser.** Hast du einen
Schlüssel, den du weiter benutzen willst, importiere ihn dann: plMail verwirft seinen eigenen
Schlüssel für diese Adresse nur, solange ihn niemand gesehen hat.

**Eine Sitzung, die „Angemeldet bleiben“ wieder geöffnet hat, beginnt gesperrt.** Deine Mails sind
alle da; nur deine Schlüssel warten einmal auf dein Passwort.

**Verschlüsselung braucht HTTPS.** Unter einer reinen `http://`-Adresse — etwa einer IP im LAN —
bietet der Browser keine eigene Kryptografie an, also werden dort keine Schlüssel erstellt oder
geöffnet, und Einstellungen → Verschlüsselung sagt das. Anmelden funktioniert trotzdem. Öffne
plMail über seine `https://`-Adresse (oder `localhost`).

**Dein Passwort in den Einstellungen zu ändern, nimmt deine Schlüssel mit.** Jede andere Änderung —
durch eine Administratorin, mit `app:user:password` — sperrt sie, und die Recovery-Phrase oder ein
bekanntes Gerät öffnet sie wieder.
