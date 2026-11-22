<!-- translated-from: features/tasks.md sha1:6c74b43f3ce8f0f72a601867c1c5820cffec5928 -->

# Aufgaben

plMail führt deine Aufgaben direkt neben deiner Mail. Jede Person bekommt zu Beginn eine eigene Liste,
und eine Liste kann sich außerdem in beide Richtungen mit jedem CalDAV-Server abgleichen, der Aufgaben
führt, zum Beispiel Nextcloud Tasks.

Du öffnest sie mit dem Checklisten-Symbol neben dem Adressbuch in der oberen Leiste. Die Aufgaben docken
neben der Mail an, und der Kalender muss dafür nicht offen sein. Zieh am Rand des Bereichs, um ihn breiter
oder schmaler zu machen (oder nutze die Pfeiltasten am Rand; ein Doppelklick stellt ihn zurück). Auf dem
Telefon öffnet **Aufgaben** im Menü sie in voller Breite.

Die Aufgaben wechseln sich mit dem Adressbuch ab. Auf einem breiten Monitor (ab 1920 Pixel Breite) können
sie neben dem Kalender stehen; auf einem kleineren Bildschirm passt ein Bereich neben die Mail, also
schließt das Öffnen der Aufgaben den Kalender und umgekehrt.

## Hinzufügen und erledigen

Tippe in das Feld oben im Bereich und drücke die Eingabetaste. Sobald du in das Feld klickst, erscheinen
Schaltflächen für Fälligkeit, Erinnerung und Wiederholung, die du gleich mit setzen kannst. Ohne Datum ist
eine Aufgabe, die du in **Mein Tag** hinzufügst, heute fällig, damit sie dort bleibt, wo du sie getippt hast;
eine Aufgabe in einer Liste hat dann keines.

Hake den Kreis ab, um eine Aufgabe zu erledigen. Ein Klick irgendwo sonst auf die Zeile öffnet sie. Dort
änderst du Titel, Notizen, Fälligkeit, Erinnerung und Wiederholung, arbeitest ihre Schritte ab
und fügst Dateien hinzu. Eine Adresse in einem Titel oder in den Notizen (`https://…` oder `www.…`) wird
zum Link.

## Mein Tag

**Mein Tag** füllt sich von selbst. Er zeigt, was heute fällig ist, beginnt oder dich erinnert, darüber als
eigene Gruppe die überfälligen Aufgaben. Eine Aufgabe, die du heute erledigst, bleibt durchgestrichen bis
Mitternacht dort. Heute heißt heute auf deiner Uhr: Die Zeitzone legt **Einstellungen → Erscheinungsbild**
fest, nicht der Server.

Von Hand lässt sich keine Aufgabe an Mein Tag anheften. Gib ihr stattdessen ein Datum.

## Listen und Filter

**Alle** zeigt alle Listen zusammen, und jede Liste hat daneben ihre eigene Schaltfläche. Das Zahnrad oben im
Bereich öffnet **Listen**: Dort legst du eine Liste an, benennst eine um, färbst sie um oder löschst sie. Ihr Stern
zeigt, wo eine Aufgabe landet, wenn nichts anderes gewählt ist (in **Mein Tag**, in **Alle**); drücke den Stern einer
anderen Liste, um neue Aufgaben dorthin zu schicken. Die Liste einer Aufgabe änderst du in ihrem Dialog unter
**Liste**; verschiebst du eine von oder auf eine synchronisierte Liste, wird sie dort gelöscht, wo sie war, und dort
neu angelegt, wo sie hinkommt, mit ihren Schritten. Wenn du
eine Liste löschst, sind ihre Aufgaben mit weg. Die erste Liste, in der eine Aufgabe landet, wenn nichts anderes
gewählt wurde, lässt sich nicht löschen, eine synchronisierte auch nicht.

Eine Liste zeigt alle ihre Aufgaben auf einmal, gruppiert nach überfällig, heute, demnächst, ohne Datum und
erledigt. Jede Gruppe lässt sich einklappen, damit eine lange die anderen nicht aus dem Blick schiebt.

## Schritte

Eine Aufgabe kann Schritte haben: eine kurze Checkliste darin, eine Ebene tief. Sobald du in das Feld tippst,
erscheint darunter ein weiteres; ein leeres Feld wird nicht gespeichert, ein ausgefülltes ist ein Schritt, sobald
du den Dialog speicherst. Hakst du einen Schritt schon beim Tippen ab, wird er als erledigt gespeichert, und
sein × nimmt ihn wieder weg. Die Schritte, die eine Aufgabe schon hat, sind im Dialog ebenfalls Felder: ändern
und speichern. Gelöscht wird ein Schritt mit dem × daneben; leerst du sein Feld, bleibt der Titel, wie er war.

Die Zeile zeigt, wie weit du bist, etwa **2 von 5**. Der Pfeil an ihrem Ende klappt die Schritte auf, sodass du sie
abhaken kannst, ohne die Aufgabe zu öffnen, und die Zeile bleibt in diesem Browser-Tab aufgeklappt, bis du die
Aufgabe erledigst. Ein Haken erscheint sofort; die Bestätigung kommt, sobald er gespeichert ist, und scheitert das
Speichern, verschwindet der Haken wieder und du bekommst eine Meldung. Der letzte Schritt erledigt die Aufgabe
nicht; das bleibt deine Entscheidung.

## Wiederkehrende Aufgaben

Wähle **Wiederholen**, wenn du eine Aufgabe öffnest. Abhaken erledigt eine wiederkehrende Aufgabe nicht:
Sie springt auf ihren nächsten Termin in der Zukunft, und ihre Schritte öffnen sich wieder. Eine wöchentliche
Aufgabe, die du drei Wochen zu spät abhakst, springt auf nächste Woche, nicht auf ein Datum, das schon
vorbei ist.

Einen Rhythmus aus einer anderen App, für den plMail kein Wort hat, etwa montags und mittwochs, zeigt plMail
als **Bisherigen Rhythmus beibehalten** und lässt ihn, wie er ist. Ein Rhythmus mit einem Ende, nach Datum
oder Anzahl, endet auch: Das letzte Abhaken erledigt die Aufgabe.

Jedes Abhaken hinterlässt einen erledigten Eintrag für diesen Termin: unter **Erledigt** und bis Mitternacht in
**Mein Tag**. Entfernst du den Haken beim neuesten Eintrag, springt die Aufgabe auf diesen Termin zurück; bei einem
älteren verschwindet nur der Eintrag. Die Einträge bleiben in plMail. Eine synchronisierte Liste bekommt sie nicht,
andere Apps sehen also nur, dass die Aufgabe weiterspringt, und wenn du die Aufgabe löschst, sind ihre Einträge mit weg.

## Erinnerungen

Eine Aufgabe kann eine Erinnerung haben, zu einem Datum und einer Uhrzeit deiner Wahl. Sie kommt als
Benachrichtigung im Browser oder auf dem Telefon, wie eine Kalender-Erinnerung, und braucht deshalb
eingeschaltete Benachrichtigungen: Was dazu nötig ist, steht unter [Erinnerungen](calendar-alerts.md). Eine
Erinnerung, die fällig wird, während der Server nicht läuft, geht verspätet raus, wenn sie weniger als eine
Stunde zu spät ist, danach gar nicht mehr.

Die Erinnerung einer wiederkehrenden Aufgabe wandert mit und hält ihren Abstand zur Fälligkeit.

## Dateien

Öffne eine Aufgabe, um Dateien bis 25 MB anzuhängen. Sie bleiben in plMail und lassen sich nur von dir
herunterladen.

Bei einer synchronisierten Liste sehen andere Apps einen Link zurück zur Datei, der eine Anmeldung bei plMail
verlangt. Entfernt so eine App den Link, bleibt die Datei hier, und die nächste Änderung setzt den Link wieder.

Dateien, auf die eine andere App verlinkt, erscheinen ebenfalls und öffnen sich dort, wo sie liegen. plMail
behält sie an der Aufgabe, hält aber keine Kopie.

## Mit einem Server abgleichen

Verbinde den Server zuerst unter **Einstellungen → Kalender**, wie bei einem
[verbundenen Kalender](calendar-sync.md): Dieselbe Verbindung dient beidem. Danach bietet das Zahnrad oben im Bereich
**Listen von einem Server synchronisieren** an. Wähle dort die Verbindung, hake die Listen an, die synchron bleiben
sollen, und wähle daneben jeweils ihre Farbe.
Kalender eines Servers, die nur Termine führen, werden nicht angeboten.

Deine Änderungen gehen innerhalb von Augenblicken an den Server. Änderungen von anderswo kommen etwa alle
fünfzehn Minuten an; für Aufgaben gibt es keinen Push.

Was plMail zeigt und ändert: Titel, Notizen, Fälligkeit, Status, Wiederholung, die erste Erinnerung
und Schritte. Außerdem liest es Beginn und Priorität und nutzt den Beginn für Mein Tag, hat aber für beide kein
Feld. Alles andere, was eine andere App an eine Aufgabe gehängt hat, etwa Kategorien, bleibt erhalten, wenn du sie hier bearbeitest.

Haben der Server und plMail dieselbe Aufgabe geändert, gewinnt die Fassung des Servers. Eine Liste, die du auf
dem Server nur lesen darfst, trägt ein Schloss, und nichts darauf lässt sich hier ändern.

Wenn du das Häkchen bei einer Liste entfernst, verschwinden sie und ihre Aufgaben aus plMail. Auf dem Server
bleiben sie erhalten.

### Microsoft To Do

Ein Microsoft-Konto bringt seine To-Do-Listen mit derselben Anmeldung mit, sobald sein Schalter **Aufgaben** an ist.
Hake **Aufgaben** beim Verbinden des Kontos an, oder schalte es später unter **Einstellungen → Konten** ein und erlaube
es bei Microsoft. **Listen von einem Server synchronisieren** hinter dem Zahnrad bietet das Konto dann neben jeder CalDAV-Verbindung an, beim ersten Mal mit
der Standardliste **Aufgaben** angehakt. Ausgeschaltet bleiben seine Listen, wie sie sind, und nichts wird
abgeglichen, bis es wieder an ist.
Die Liste **Gekennzeichnete E-Mail** wird nicht angeboten, weil gekennzeichnete Mail hier schon gekennzeichnet ist.
Ein Konto, das verbunden wurde, bevor plMail nach To Do gefragt hat, bekommt unter **Einstellungen → Zustand der
Konten** einen Hinweis, dessen Knopf dich noch einmal anmeldet und den Zugriff ergänzt; vorher trägt der
Administrator `Tasks.ReadWrite` in der App-Registrierung nach (siehe [Microsoft](../providers/microsoft.md)).

Microsoft To Do funktioniert ein wenig anders, und plMail folgt ihm:

- **Eine Fälligkeit ist ein Tag.** To Do speichert keine Uhrzeit, deshalb ist das Uhrzeitfeld bei seinen Listen
  ausgegraut. Die Erinnerung behält ihre Uhrzeit.
- **Microsoft schaltet eine wiederkehrende Aufgabe weiter.** Hakst du eine ab, geht der Haken an To Do; To Do
  schiebt die Aufgabe dann auf ihren nächsten Termin und behält eine erledigte Kopie, und beides kommt mit dem
  nächsten Abgleich an.
- **Schritte sind die Schritte von To Do** und reisen mit ihrer Aufgabe.
- **Dateien bleiben in plMail.** Microsoft hat jede Datei abgelehnt, die plMail anhängen wollte. Eine hier
  hinzugefügte Datei bleibt also hier, und Dateien aus To Do bleiben dort.
- **Kategorien, Wichtigkeit, Beginn und formatiert geschriebene Notizen** bleiben in To Do, wie sie sind, wenn du
  eine Aufgabe hier bearbeitest, solange du die Notizen nicht änderst.

Google Tasks sowie plMails eigene CalDAV- und JMAP-Server führen noch keine Aufgaben. Listen, die nur in plMail
liegen, erreichen dein Telefon also nicht.

## Befehle

`app:tasks:sync` stellt einen Abgleich der verbundenen Listen in die Warteschlange, und `app:tasks:alerts` versendet
die fälligen Erinnerungen. Der Zeitplaner führt beide aus.

```bash
docker compose exec php php bin/console app:tasks:sync
```

```bash
docker compose exec php php bin/console app:tasks:alerts --dry-run
```

## Fallstricke

- **Ein Link zu einer Datei braucht eine plMail-Anmeldung und eine Adresse.** Andere Apps können ihn ohne
  Anmeldung nicht öffnen. Eine Installation, die ihre öffentliche Adresse noch nicht kennt, verschickt gar keinen
  Link, bis sie sie kennt.
- **In eine Aufgabe eingebettete Dateien anderer Apps werden nicht aufgeführt.** Eine Datei, die in der Aufgabe
  selbst steckt statt verlinkt zu sein, bleibt auf dem Server und übersteht deine Änderungen, aber plMail zeigt
  sie nicht an.
- **Wie andere Apps mit Schritten, Wiederholungen und Links umgehen, ist nicht für jede App ausprobiert.** plMail
  folgt dem Standard, aber Nextcloud Tasks, tasks.org und Apple Erinnerungen sind noch nicht damit getestet. Probiere
  es zuerst mit einer Liste, auf die du verzichten kannst.
- **Eine abgehakte wiederkehrende Aufgabe auf einer To-Do-Liste steht bis zum nächsten Abgleich als erledigt da.**
  Microsoft schiebt sie auf ihren nächsten Termin, sobald der Haken ankommt, und plMail erfährt das beim nächsten
  Abgleich, der bei laufendem Worker gleich darauf folgt.
- **Ausprobiert ist nur ein privates Microsoft-Konto.** Ein Geschäfts- oder Schulkonto sollte sich genauso
  verhalten, ist aber nicht getestet.
- **Zwei Apps können dich beide erinnern.** Eine Erinnerung an einer synchronisierten Aufgabe kann hier und in einer
  anderen App auslösen, die dieselbe Aufgabe liest.
- **Eine Aufgabe ohne eigene Schritte kann anderswo ein Schritt sein.** Ein Schritt, der in einer anderen App mehr als
  eine Ebene tief steckt, erscheint hier unter seiner obersten Aufgabe. Ein Schritt, dessen übergeordnete Aufgabe nicht
  auf der Liste ist, erscheint als eigene Aufgabe.
