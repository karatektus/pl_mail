<!-- translated-from: features/accounts.md sha1:88b8b4500baf1b04477417b36b2e95dd58cb1158 -->

# Konten und Aliase

Ein plMail-Konto ist ein Postfach, das du anderswo hast — auf einem IMAP-Server, bei Gmail oder
bei Outlook —, an dem plMail sich anmeldet und das es synchronisiert. Du kannst so viele
verbinden, wie du magst; sie synchronisieren unabhängig voneinander und lesen sich als ein
einziger Posteingang.

Alles auf dieser Seite liegt unter **Einstellungen → E-Mail-Konten**, sofern nicht anders
angegeben.

![Einstellungen](../screenshots/settings.png)

## Ein IMAP-Postfach hinzufügen

**Einstellungen → E-Mail-Konten → Konto hinzufügen**, auf dem Reiter **IMAP / SMTP**. Trage
Adresse und Passwort ein. Die Auswahl **Anbieter** trägt die Einstellungen einer langen Liste
gängiger Anbieter und wird gegen die Domain abgeglichen, die du getippt hast — für die meisten
Postfächer füllen sich Server, Port und Verschlüsselung also von selbst; einige Einträge tragen
zusätzlich einen Hinweis auf etwas, das der Anbieter verlangt, etwa dass IMAP erst in dessen
Weboberfläche eingeschaltet werden muss. Steht deiner nicht auf der Liste, nimm die IMAP- und
SMTP-Daten aus der Dokumentation deines Anbieters.

Ein leeres Formular startet eingehend mit Port 993 und SSL und ausgehend mit Port 587 und
STARTTLS, und das ist es, was nahezu jeder Anbieter erwartet.

**Verbindung testen** prüft IMAP und SMTP getrennt und meldet beides. Das Speichern hängt nicht
daran, aber ein Konto, das sauber abgelegt wird und sich nicht anmelden kann, ist genau der
Fehler, den man lieber jetzt als bei der ersten Synchronisierung findet — deshalb läuft die
Prüfung beim Speichern noch einmal, und ihr Ergebnis bleibt am Konto vermerkt.

Die erste Synchronisierung startet sofort. Danach hält plMail eine IMAP-IDLE-Verbindung zu
jedem Postfach und synchronisiert in dem Moment, in dem sich etwas ändert, mit einem geplanten
Durchlauf alle fünfzehn Minuten dahinter.

Siehe [IMAP und SMTP](../providers/imap-smtp.md) für die Einstellungen, über die sich die
Server uneinig sind.

## Gmail oder Outlook hinzufügen

Beide laufen über OAuth, eine Administratorin oder ein Administrator muss also eine Anwendung
beim Anbieter registrieren, bevor der Knopf überhaupt etwas tut. Ist das erledigt, bietet
**Konto hinzufügen** auf dem Reiter **OAuth** die Punkte **Weiter mit Google** und **Weiter mit
Microsoft** an, und der gesamte Ablauf ist die Zustimmungsseite des Anbieters selbst — hier
wird überhaupt kein Passwort gespeichert.

Microsoft-Konten laufen über Graph statt über IMAP. Das ist Absicht: Exchange Online behandelt
IMAP als Altlast-Authentifizierung und blockiert es in jedem Tenant, der die Security Defaults
aktiv hat.

Die Registrierung selbst — welche Konsole, welche Redirect-URI, welche Scopes — steht auf
[Google](../providers/google.md) und [Microsoft](../providers/microsoft.md). Wohin die
Zugangsdaten kommen, ist eine Wahl: Als Administrator kannst du sie unter **Administration →
Integrationen** bei **Mail-Anmeldung** eintragen oder sie als `GOOGLE_OAUTH_*` und
`MICROSOFT_OAUTH_*` in der Umgebung lassen, was eine ältere Installation ohnehin schon tut.
Siehe [Administration](admin.md).

Ein OAuth-Konto hat hier kein bearbeitbares Formular. Es gibt nichts zu bearbeiten — kein
Passwort, keinen Server —, es wird also über Verbinden und Trennen verwaltet und nicht über
eine Einstellungsseite.

## Die Kontenliste

Konten stehen in deiner eigenen Reihenfolge und lassen sich per Ziehen umsortieren. Die
Reihenfolge ist nicht kosmetisch: Das **erste** Konto ist das primäre, und von diesem geht ein
neues Schreibfenster aus. Ein Konto zu entfernen nummeriert den Rest um, die oberste Zeile ist
also immer die primäre.

Jede Zeile bietet:

| Bedienelement | Wirkung |
|---|---|
| **Konto deaktivieren** / **Konto aktivieren** | Hält die Synchronisierung an oder nimmt sie wieder auf, ohne etwas zu löschen |
| **Konto bearbeiten** | Servereinstellungen und Passwort — nur bei Konten mit Passwort |
| **Konto entfernen** | Löscht das Konto und jede daraus synchronisierte Nachricht |

Ein Konto zu entfernen löscht dessen synchronisierte Mail aus plMails Datenbank und beim
Anbieter nichts. Außerdem wird versucht, jede Push-Registrierung abzubauen, die es hatte, damit
nichts zurückbleibt, das auf ein Konto zeigt, das es nicht mehr gibt.

Lässt du im Bearbeitungsformular das Passwortfeld leer, bleibt das gespeicherte erhalten.

## Einstellungen pro Konto

Unter jeder Kontozeile sitzen drei Bedienelemente. Jedes zeichnet sich für sich neu, statt die
ganze Liste neu zu laden.

### Sofortige Zustellung

**Sofortige Zustellung** schaltet ein Konto zwischen Push und geplantem Abrufen um. Sie
erscheint nur bei Gmail und Microsoft — ein einfaches IMAP-Postfach hat keine Push-Verwaltung,
und IDLE erzielt dort bereits dieselbe Wirkung.

Das Einschalten registriert auf der Stelle beim Anbieter. Scheitert die Registrierung, springt
der Schalter zurück, damit die Oberfläche nie behauptet, Push funktioniere, während nichts
zugestellt wird, und der Grund wird ausgeschrieben:

- **Gmail** — Google hat die Watch-Registrierung abgelehnt. Prüfe, ob `GMAIL_PUBSUB_TOPIC` ein
  vorhandenes Topic benennt und ob dieses Topic `gmail-api@system.gserviceaccount.com` die
  Rolle „Pub/Sub Publisher“ gewährt.
- **Microsoft** — Microsoft konnte diesen Server nicht über HTTPS erreichen. Prüfe den Reverse
  Proxy und `APP_PUBLIC_URL`.

So oder so synchronisiert das Konto weiter nach Zeitplan. Push ist eine Optimierung, nie der
einzige Weg.

Das Abzeichen daneben liest sich **Push**, wenn die Registrierungen gesund sind, **Push (liefert
nicht)**, wenn eine registriert ist, in letzter Zeit aber nichts angekommen ist, und **Nach
Zeitplan**, wenn es aus ist. Der mittlere Zustand bedeutet bei Gmail meist, dass das
Pub/Sub-Push-Abonnement fehlt oder woandershin zeigt, bei Microsoft ein abgelaufenes Abonnement;
**Push neu registrieren** ist der Knopf dafür.

Wo Push auf diesem Server überhaupt nicht zur Verfügung steht, sagt das Bedienelement das,
statt einen Schalter anzubieten, der nicht funktionieren kann.

### Labels zum Anbieter synchronisieren

Standardmäßig aus. Ist es an, werden Labels, die du anlegst, umbenennst oder löschst, zu diesem
Anbieter gespiegelt — gleich, ob die Änderung aus der Weboberfläche oder aus einem JMAP-Client
kam.

Nur Gmail und Microsoft bieten das an. Bei einfachem IMAP ist ein Label ein physischer Ordner;
Labels anzulegen und zu löschen würde also echte Mail auf dem Server verschieben — eine andere
und riskantere Operation, als der Schalter verspricht.

Es wirkt sich immer nur auf Änderungen ab jetzt aus. Bereits vorhandene Labels werden nicht
rückwirkend übertragen, denn das hieße, deinen gesamten lokalen Baum mit einem Klick beim
Anbieter anzulegen.

**Bei Microsoft sind die meisten Labels Outlook-Kategorien und keine Ordner**, und eine Kategorie
hat dort keine Identität außer ihrem Namen — der Name ist das, was an jeder Nachricht steht. Eine
Umbenennung tut deshalb zweierlei: Die Hauptkategorie wird umbenannt, und jede Nachricht, die den
alten Namen trägt, wird erneut übertragen, damit sie den neuen trägt. Diese zweite Hälfte ist
dieselbe Frage, die auch Outlooks eigener Umbenennen-Dialog stellt; hier lautet die Antwort ja,
denn sonst gäbe es das Label zweimal im Postfach — einmal als Kategorie und einmal als loser Text
an der Mail, die es früher hatte. Ein Label auf tausenden Nachrichten heißt damit tausende
Aktualisierungen, die im Hintergrund laufen.

Labels, die tatsächlich auf einem echten Exchange-Ordner beruhen — die, die von einem stammen —,
werden stattdessen als Ordner umbenannt, und es muss nichts neu etikettiert werden.

## Absendeadressen

**Einstellungen → Aliase** listet die Adressen auf, unter denen jedes Konto sendet und
empfängt. **Vom Anbieter aktualisieren** fragt das Konto, was es kennt; **Hinzufügen** nimmt
eine, die du selbst tippst.

Jede Adresse ist **Primär**, aktiv oder deaktiviert. Die primäre ist der Standardabsender für
dieses Konto und lässt sich weder deaktivieren noch entfernen — mach zuerst eine andere zur
primären, was sie herabstuft. Deaktivierte Adressen bleiben auf der Liste und werden im
Schreibfenster nicht mehr angeboten.

Die **From**-Auswahl im Schreibfenster listet Konten und Aliase gemeinsam auf, sodass das Senden
unter einer Aliasadresse eine Wahl ist und nicht zwei.

Einen Vorbehalt nennt plMail auf der Seite selbst: **Outlook versendet unabhängig von deiner
Wahl hier immer über den primären Alias des Kontos.** Das ist Microsofts Verhalten, geändert
wird es in deinen Microsoft-Kontoeinstellungen und nicht hier.

## Wo du weiterliest

- [IMAP und SMTP](../providers/imap-smtp.md), [Google](../providers/google.md),
  [Microsoft](../providers/microsoft.md) — die genaue Einrichtung auf Anbieterseite.
- [Mail-Ingest](../internals/mail-ingest.md) — was ein Synchronisierungslauf tatsächlich tut.
- [Konfigurationsreferenz](../install/configuration.md) — `APP_PUBLIC_URL`, die OAuth-Variablen
  und die für Pub/Sub.
- [Fehlersuche](../install/troubleshooting.md) — wenn keine Mail mehr ankommt.

## Fallstricke

**Das erste Konto in der Liste ist das primäre.** Per Ziehen umzusortieren ändert daher, aus
welchem Konto eine neue Nachricht verfasst wird. Es gibt keinen eigenen Knopf „als primär
festlegen“, und es kommt auch keiner — die Reihenfolge *ist* die Einstellung.

**Ein großes Postfach braucht seine Zeit, bis es vollständig da ist.** plMail synchronisiert
alles, was das Konto hält; es gibt keine Einstellung, die das begrenzt. Die neueste Mail kommt
zuerst, der Rest folgt über die nächsten Läufe — ein eben hinzugefügtes Konto ist also lange
brauchbar, bevor es vollständig ist.

**Die Verbindung eines bestehenden Kontos zu testen braucht ein Passwort im Feld.** Ein leeres
Passwort bedeutet im Bearbeitungsformular „behalte das gespeicherte“, und der Test kann das
erst auflösen, wenn ihn die Konto-Id erreicht hat — sonst sagt er das, statt mit nichts zu
testen.

**Ein Konto zu entfernen löscht dessen synchronisierte Mail aus plMail.** Beim Anbieter wird
nichts entfernt, und das Konto erneut hinzuzufügen synchronisiert es von vorn.

**Auf Googles Zustimmungsseite lässt sich der Kalenderzugriff abwählen, während Mail erlaubt
wird.** Das Konto verbindet sich, Mail funktioniert, und es erscheinen keine Kalender. Die
Lösung ist, erneut zu verbinden und das Häkchen gesetzt zu lassen.

**Ein fehlgeschlagener Push ist kein Fehlerzustand.** Eine selbst gehostete Installation hat
womöglich überhaupt keine öffentlich erreichbare HTTPS-Adresse. Eine gescheiterte Registrierung
heißt „bleib beim Abrufen“, und der Durchlauf alle fünfzehn Minuten bleibt davon unberührt.
