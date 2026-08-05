<!-- translated-from: features/security.md sha1:6275fce35b7c54658526ced8c515cc815b533a37 -->

# Sicherheit

Dein Postfach ist das Konto, das am meisten Schutz verdient: Wer es erreicht, kann bei allem
anderen, was dir gehört, das Passwort zurücksetzen, denn dort landen die Links dafür. plMail
sagt das auf der Seite selbst, und das hier ist, was es anbietet.

Alles hier liegt unter **Einstellungen → Sicherheit**, außer den App-Passwörtern, die einen
eigenen Abschnitt haben.

## Zwei-Faktor-Authentifizierung

Freiwillig, pro Benutzer, TOTP — der sechsstellige Code, den jede Authenticator-App erzeugt.
Der Einrichtungsassistent bietet ihn als Schritt an; **Zwei-Faktor-Authentifizierung
einrichten** in den Einstellungen tut später dasselbe.

Die Einrichtung sind drei Schritte, und die Seite führt hindurch:

1. Installiere eine Authenticator-App, falls du noch keine hast. Google Authenticator, Aegis,
   1Password und Bitwarden gehen alle.
2. Scanne den QR-Code. **Geht das Scannen nicht? Schlüssel eingeben** gibt dir den Schlüssel
   zum Abtippen.
3. Gib den sechsstelligen Code ein, den die App gerade anzeigt, und drücke **Bestätigen**.

Das Geheimnis ist nicht aktiv, bevor ein daraus erzeugter Code geprüft wurde. Eine auf halbem
Weg abgebrochene Einrichtung hinterlässt ein Konto, das sich weiterhin mit seinem Passwort
öffnet, und keines, in das niemand mehr hineinkommt. Ein abgelehnter Code erzeugt auch kein
neues Geheimnis — der QR-Code, den du eben gescannt hast, bleibt gültig, ein zweiter Versuch
geht also gegen denselben Eintrag.

plMail toleriert fünfzehn Sekunden Uhrabweichung in beide Richtungen. Es läuft auf Heimservern
und NAS-Geräten, wo eine abgedriftete Uhr ein ganz gewöhnlicher Dienstag ist, und der Fehler,
den sie verursacht — „mein Code ist immer falsch“ —, ist von außen nahezu nicht zu
diagnostizieren.

Der Eintrag erscheint in deiner Authenticator-App unter **plMail**, beschriftet mit deiner
Adresse.

Das Codeformular hat sein eigenes Limit: fünf Versuche je fünfzehn Minuten, gebunden an das
Konto. Das ist bewusst enger als beim Passwortformular. Wenn das Codeformular erreichbar ist,
wurde das Passwort bereits angenommen; wer also ein gestohlenes Passwort hat, steht mit sechs
Ziffern vor dem Postfach, und eine Million ist gegen ein ungedrosseltes Formular keine große
Zahl.

## Wiederherstellungscodes

Die bestätigte Einrichtung erzeugt **acht** Wiederherstellungscodes, jeder aus vier durch
Bindestriche getrennten Hex-Gruppen, jeder einmal verwendbar. Sie werden genau einmal angezeigt
— gespeichert werden nur ihre Prüfsummen, es gibt also keine Ansicht, die sie erneut zeigen
könnte. Bewahre sie dort auf, wo du ohne dein Telefon herankommst: in einem Passwortmanager
oder auf Papier in einer Schublade.

Der Abschnitt Sicherheit zeigt, wie viele unbenutzt sind. **Neue Codes erzeugen** ersetzt den
ganzen Satz, alles, was du vorher notiert hattest, funktioniert dann nicht mehr.

Ein Wiederherstellungscode weist den Besitz genauso nach wie ein lebender Code, er genügt also,
um 2FA auszuschalten oder den Satz neu zu erzeugen. Er wird dabei verbraucht, ein
durchgesickerter lässt sich also nicht für eine zweite Handlung wiederverwenden.

## Ausschalten

**Ausschalten** verlangt einen aktuellen Code oder einen unbenutzten Wiederherstellungscode.
Wer an einer unversperrten Sitzung sitzt, soll den zweiten Faktor nicht vom Konto reißen
können, ohne den zweiten Faktor zu haben.

Das Ausschalten löscht deine Wiederherstellungscodes und beendet das Vertrauen in jedes
gemerkte Gerät.

## Gemerkte Geräte

Das Codeformular bietet **Auf diesem Gerät nicht mehr fragen** an. Ein Häkchen dort überspringt
die Abfrage auf diesem Gerät für **30 Tage**, jeweils bei Benutzung erneuert.

Jedes gemerkte Gerät steht in den Einstellungen mit einer Bezeichnung, die plMail aus dem
Browser ableitet — „Firefox unter macOS“ —, dazu wann es zuletzt benutzt wurde, von welcher
Adresse aus, und wann das Vertrauen abläuft. **Widerrufen** wirft eines heraus; **Alle gemerkten
Geräte widerrufen** wirft alle heraus, dieses eingeschlossen.

Ein Widerruf greift beim **allernächsten Request** dieses Geräts und nicht dann, wenn ein
Cookie zufällig abläuft. Das ist der Grund, warum die Erlaubnis eine Datenbankzeile ist und
kein signiertes Cookie: Der übliche Ansatz packt die ganze Erlaubnis in ein JWT, das zustandslos
und schnell ist und sich **nicht** zurücknehmen lässt — ein gestohlenes Cookie bleibt seine
volle Lebensdauer gültig, und der einzige Widerruf, den es gibt, ist, sämtliche Geräte des
Benutzers auf einmal zu entwerten. Hier ist das Cookie ein undurchsichtiges 32-Byte-Geheimnis,
gespeichert wird nur ein SHA-256 davon, und ein Gerät zu widerrufen ist eine Zeile.

Das Gerät zu widerrufen, an dem du gerade sitzt, löscht auch dessen Cookie, damit der Browser
aufhört, ein Geheimnis vorzuzeigen, das nie wieder anerkannt wird.

Eine **neue** Einrichtung zu bestätigen widerruft ebenfalls jedes gemerkte Gerät, und zwar mit
der Begründung, dass ein unter dem alten Geheimnis vertrautes Gerät die Abfrage sonst weiterhin
überspringen würde.

## Anmelden

Das Anmeldeformular drosselt bei fünf Versuchen je fünfzehn Minuten und Adresse, mit einer
lockereren Absicherung je Herkunftsadresse für jemanden, der ein Passwort über viele Konten
streut — lockerer, damit ein Haushalt hinter einer NAT-Adresse sich nicht selbst aussperrt.

**Angemeldet bleiben** stellt ein Cookie mit 60 Tagen Lebensdauer aus. Es beruht auf einer
Signatur und ist nicht gespeichert, was bedeutet, dass eine Passwortänderung jedes ausgestellte
entwertet. Es geht **nicht** am zweiten Faktor vorbei: Eine gemerkte Sitzung wird trotzdem nach
einem Code gefragt.

Es gibt keine Sitzungsliste und keinen Knopf „überall abmelden“. Was plMail zurücknehmen kann,
ist ein gemerktes Gerät, ein App-Passwort oder — durch eine Passwortänderung — jedes
Angemeldet-bleiben-Cookie auf einmal.

**Es gibt keinen Ablauf zum Zurücksetzen des Passworts.** Das Anmeldeformular sagt das, statt
einen Link anzubieten, der ins Leere führt: Die Wiederherstellung läuft über die
Server-Konsole, und dort kann eine Administratorin oder ein Administrator ein neues Passwort
setzen.

## App-Passwörter

Eine Mail-App eines Drittanbieters kann keinen sechsstelligen Code vorzeigen, also bekommt sie
eigene Zugangsdaten. **Einstellungen → App-Passwörter → Erzeugen** erstellt eines, benannt nach
dem, was du gerade verbindest — der Platzhalter schlägt *iPhone — Sterna* vor.

Das Geheimnis sieht aus wie `plmail_` gefolgt von 64 Hex-Zeichen und wird **einmal** angezeigt,
in der Antwort, die es erzeugt hat. Gespeichert wird nur eine SHA-256-Prüfsumme, dazu die
ersten sechs Zeichen, damit die Liste zeigen kann, welches welches ist. Wenn du es verpasst,
widerrufe es und erzeuge ein neues; es gibt nichts wiederherzustellen.

Jedes App-Passwort gilt für dich als Benutzer, nicht für ein Konto: Ein einziges Zugangsdatum
erreicht jedes Mail-Konto, das du verbunden hast. **Widerrufen** erledigt eines für sich, und
jede App, die es verwendet, wird sofort abgemeldet. Die Liste zeigt außerdem, wann jedes
zuletzt benutzt wurde, höchstens alle fünf Minuten aktualisiert — ein grobes Signal „kürzlich
aktiv“ und kein Prüfprotokoll.

**Gerät koppeln** im selben Abschnitt ist die Abkürzung: Es zeigt einen QR-Code, den eine
plMail-App scannt und gegen ein eigenes App-Passwort tauscht. Der Code funktioniert einmal,
läuft in zwei Minuten ab und enthält selbst keine Zugangsdaten.

Wie du mit einem App-Passwort einen Client verbindest, steht unter [Andere
Clients](clients.md).

## Ausgesperrt

Das Telefon und die Wiederherstellungscodes zu verlieren ist behebbar, denn plMail läuft auf
Hardware, die dir gehört:

```
docker compose exec php php bin/console app:user:2fa-disable you@example.com
```

Das ist Administratorinnen und Administratoren bewusst **nicht** über die Weboberfläche
zugänglich. Wer aus einem Browser heraus den zweiten Faktor einer fremden Person entfernen
könnte, wäre ein zweiter Weg in jedes Postfach dieser Installation, erreichbar mit nichts als
einer gestohlenen Admin-Sitzung.

## Wo du weiterliest

- [Andere Clients](clients.md) — wofür ein App-Passwort da ist.
- [Sicherheitsmodell](../internals/security-model.md) — Verschlüsselung im Ruhezustand, die
  Geheimnisdatei, Token, und was ein öffentlicher Link erreicht.
- [Administration](admin.md) — was eine Administratorin oder ein Administrator einem fremden
  Konto antun kann und was nicht.
- [Fehlersuche](../install/troubleshooting.md) — wenn etwas dich nicht hereinlässt.

## Fallstricke

**Die Zwei-Faktor-Authentifizierung deckt `/jmap` nicht ab.** Sie kann es nicht: App-Passwörter
gibt es gerade deshalb, weil ein IMAP- oder JMAP-Client keine Möglichkeit hat, einen Code
vorzuzeigen. Einem Client den Zugang zu entziehen ist die Sache der App-Passwort-Liste und
nicht dieser Seite.

**2FA aus- und wieder einzuschalten stellt kein Vertrauen wieder her.** Sowohl das Ausschalten
als auch das Bestätigen einer neuen Einrichtung widerrufen jedes gemerkte Gerät, jede Maschine
wird also erneut nach einem Code gefragt — und genau das ist der Sinn, denn ein unter dem alten
Geheimnis vertrautes Gerät würde die Abfrage sonst unter dem neuen überspringen.

**Ein Wiederherstellungscode ist verbraucht, gleich ob die Handlung so gelingt, wie du es
meintest.** Er wird verbraucht, sobald er angenommen ist, ein vertippter Folgeschritt kostet
dich also einen Code.

**Neue Wiederherstellungscodes zu erzeugen entwertet den alten Satz sofort.** Hattest du ihn
aufgeschrieben, wirf das Papier in derselben Bewegung weg.

**Ein App-Passwort lässt sich kein zweites Mal anzeigen.** Nicht durch die Administration,
nicht aus der Datenbank, durch keine Ansicht. Die Abhilfe ist widerrufen und ein neues
erzeugen.

**Ein Kopplungscode lässt sich von einem abgelaufenen oder verbrauchten nicht unterscheiden.**
Alle drei antworten gleich, denn sie zu unterscheiden würde bestätigen, welche Codes einmal
echt gewesen sind.

**Die Administration kann dein Passwort oder deinen zweiten Faktor nicht aus dem Panel heraus
zurücksetzen.** Beides sind Konsolenoperationen, und das ist eine Entwurfsentscheidung und
keine fehlende Funktion.
