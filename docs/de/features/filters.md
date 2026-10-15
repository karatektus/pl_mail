<!-- translated-from: features/filters.md sha1:446ac513f7e197133acae2b0794ff92ec911c1d4 -->

# Filter

Ein Filter sortiert Mail für dich, während sie eintrifft: Er vergibt ein Label, hält sie aus
dem Posteingang heraus, markiert sie, wirft sie weg oder schiebt ihre Anhänge in einen
verbundenen Dienst. Filter wohnen unter **Einstellungen → Filter**.

![Filter](../screenshots/filters.png)

## Woraus ein Filter besteht

Aus vier Dingen: einem **Namen**, wahlweise einem **Konto**, auf das er beschränkt ist, einem
Baum aus **Bedingungen** und einer Liste von **Aktionen**. Der Editor öffnet sich neben der
Liste in einem eigenen Rahmen, damit du beim Bauen deine Stelle nicht verlierst.

Die Kontoauswahl steht standardmäßig auf **Alle Konten**. Einen Filter auf ein Konto zu
beschränken ist der übliche Wunsch, und es wiegt schwerer, als es aussieht — siehe unten den
Fallstrick über Regeln ohne Bedingungen.

## Bedingungen

Bedingungen sind ein Baum. Jede Gruppe legt fest, ob **Alle**, **Beliebige** oder **Keine** der
folgenden zutreffen müssen, und Gruppen verschachteln sich so tief, wie die Regel es braucht.
**Bedingung hinzufügen** fügt der aktuellen Gruppe eine Prüfung hinzu; **Gruppe hinzufügen**
verschachtelt eine weitere Gruppe darin.

| Bedingung | Was sie prüft |
|---|---|
| **Von enthält** | Absenderadresse oder Anzeigename |
| **Von ist genau** | Die ganze Adresse des Absenders, und sonst nichts — kein Teil einer Adresse, und nicht der Anzeigename |
| **Absender-Domain ist** | Alles hinter dem `@` der Absenderadresse, vollständig. Ohne das `@` eingeben. Eine Subdomain ist eine andere Domain |
| **An enthält**, **Cc enthält**, **Bcc enthält** | Die jeweilige Adressliste |
| **Betreff enthält** | Die Betreffzeile |
| **Text enthält** | Den Nachrichtentext |
| **Irgendwo in der Nachricht** | Volltext, mit Stammformen — keine Suche nach Teilzeichenketten |
| **Anhang heißt** | Dateinamen von Anhängen |
| **Mailingliste ist** | Der kanonisierte `List-Id`-Header |
| **Hat Label** / **Hat Label nicht** | Ein Label, das dir gehört |
| **Größer als (Bytes)** / **Kleiner als (Bytes)** | Nachrichtengröße |
| **Empfangen nach** / **Empfangen vor** | Ein Datum |
| **Hat einen Anhang** | Ja oder nein |
| **Ist** / **Ist nicht** | Eines von: gelesen, markiert, ein Entwurf, beantwortet |

**Irgendwo in der Nachricht** ist die eine, die es herauszuheben lohnt. Sie läuft gegen
dieselbe generierte Volltextspalte, die auch die Suche benutzt, und bildet deshalb korrekt
Stammformen — darum erledigt Postgres den Abgleich und nicht PHP. Es gab eine Zeit lang einen
zweiten Abgleich im Arbeitsspeicher, damit eine Nachricht ohne Umweg geprüft werden konnte;
zwei Umsetzungen von „was dieser Filter bedeutet“ sind eine stehende Einladung zum
Auseinanderdriften, und das Symptom davon ist hier stillschweigend falsch einsortierte Mail.

**Ein Filter ganz ohne Bedingungen ist erlaubt** und meint jede Nachricht, auf die sein
Geltungsbereich reicht. So schreibst du „vergib ein Label an alles, was in diesem Konto
eintrifft“.

## Aktionen

| Aktion | Wirkung |
|---|---|
| **Label vergeben** | Fügt eines deiner Labels hinzu und legt dessen Bindung auf dem Konto der Nachricht an |
| **Label entfernen** | Nimmt eines weg |
| **Als gelesen markieren** | |
| **Markieren** | |
| **Posteingang überspringen** | Entfernt das Posteingangs-Label — mit anderen Worten: archivieren |
| **In den Papierkorb** | |
| **Als Spam markieren** | |
| **Anhänge speichern in** | Lädt die Anhänge der Nachricht in einen verbundenen Dienst hoch |
| **In Kategorie ablegen** | Legt die Mail in einem deiner eigenen Posteingangs-Tabs ab. Wird erst angeboten, sobald du einen angelegt hast — siehe [In eigene Tabs ablegen](#in-eigene-tabs-ablegen) |

Mindestens eine Aktion ist Pflicht; ein Filter ohne Aktion täte nichts, und der Editor sagt das
lieber, als ihn zu speichern.

Posteingang überspringen, In den Papierkorb und Als Spam markieren heißen alle drei „raus aus
dem Posteingang“ und unterscheiden sich nur darin, wo die Nachricht danach landet. Jede wird
dem Anbieter als eine Operation übergeben und nicht als Abfolge von Label-Änderungen, denn ein
Label-Tausch bei Gmail und ein Ordnerwechsel bei IMAP tragen ihre je eigene Bedeutung.

**Anhänge speichern in** bietet nur Dienste an, in die plMail hochladen kann, und auch nur
solche, die du verbunden hast — siehe [Dateien und Integrationen](integrations.md). Der Upload
selbst wird eingereiht statt in der Synchronisierungsschleife ausgeführt, damit ein Dienst, der
gerade nicht erreichbar ist, nichts aufhält, und Nachrichten ohne Anhänge werden übersprungen,
bevor überhaupt etwas eingereiht wird.

## Die Rückübersetzung in einen Satz

Während du einen Filter baust, zeigt der Editor ihn dir als Satz zurück: *Wenn Betreff enthält
Rechnung → Label vergeben Belege*. Darunter steht eine laufend aktualisierte Zahl, wie viel von
der Mail, die du bereits hast, zutreffen würde.

Einen Filter in Worten zurückzulesen ist die Art, wie du ein **Alle** entdeckst, das ein
**Beliebige** hätte sein sollen — der Baum sieht so wie so gleich richtig aus. Der Satz wird
auf dem Server gebaut und nicht im Browser, damit es genau eine Umsetzung von „was diese Regel
sagt“ gibt und damit er ordentlich übersetzt ist.

Der Satz nennt Labels und Dienste beim Namen statt bei ihrer Id und schreibt **(gelöschtes
Label)** oder **(getrennter Dienst)** dorthin, wo unter der Regel etwas abhandengekommen ist.

Die Zahl ist auf dasselbe Konto beschränkt wie die Regel, damit Zahl und Satz nicht
Verschiedenes beschreiben können. Sie ist bei 500 gedeckelt — darüber liest sie sich **Trifft
auf 500+ vorhandene Nachrichten zu** —, weil die Frage, die beantwortet wird, „stimmt dieser
Filter ungefähr“ lautet und eine exakte Zahl über ein großes Postfach einen vollen Durchlauf
kostet, um dir etwas zu sagen, das du nicht brauchst.

`Keines von` wird ausgeschrieben statt als NICHT notiert, denn ein NICHT über mehrere
Bedingungen bedeutet „keine davon“ und liest sich für die meisten Menschen als „nicht alle
davon“.

## Reihenfolge, und das Aufhören

Filter laufen in der Reihenfolge, in der sie aufgeführt sind, und lassen sich per Ziehen
umsortieren. **Nach diesem Filter aufhören** macht einen Treffer endgültig: Spätere Filter
überspringen jede Nachricht, die dieser für sich beansprucht hat.

Die Reihenfolge ist hier nicht kosmetisch, wie sie es bei Konten ist — zusammen mit dem
Aufhören entscheidet sie, welche Regel gewinnt.

Jede Zeile hat außerdem **Filter aktivieren** / **Filter deaktivieren**, damit eine Regel
geparkt werden kann, ohne gelöscht zu werden, und **Filter löschen**. Einen Filter zu löschen
lässt die Mail, die er bereits sortiert hat, genau dort, wo sie ist.

## Wann Filter laufen

Auf Mail, während sie eintrifft, einmal je Stapel neu synchronisierter Nachrichten, nach der
Zuordnung zu Konversationen. Alles, worauf eine Bedingung schaut, ist bis dahin bereits
geschrieben.

Zwei Wege lösen bewusst nie einen Filter aus: der „Gmailify-Claim“-Zweig bei IMAP und Gmails
Anreicherung von Nachrichten, die es bereits hat. Beide zeigen vorhandene Zeilen neu an, statt
neue Mail zu importieren, und eine Regel, die dort feuert, würde Mail neu einsortieren, die du
bereits von Hand sortiert hattest.

Ein kaputter Filter bringt nie eine Synchronisierung zu Fall. Eine Regel, deren Bedingungen
sich nicht mehr übersetzen lassen, wird mit einer Warnung übersprungen, und eine Aktion, die
eine Ausnahme wirft, wird protokolliert und die Nachricht in Ruhe gelassen.

## Einen Filter auf bereits eingetroffene Mail anwenden

**Auf vorhandene Mail anwenden** bei einem gespeicherten Filter geht dein gesamtes Postfach
durch und wendet ihn auf alles an, worauf er zutrifft. Es wird vorher bestätigt, denn es kann
sehr viel Mail auf einmal verschieben und neu beschriften.

Der Lauf wird eingereiht statt im Request ausgeführt — er muss jede zutreffende Nachricht
erreichen, und das ist über ein echtes Postfach weit mehr, als ein Web-Request versuchen
sollte. Der Fortschritt wird nach jedem Stapel von 200 an den Filter geschrieben, die Zeile
liest sich also **Wird angewendet… bisher 1.400** und liest sich auch nach einem Neuladen, auf
einem anderen Gerät oder nachdem du den Tab geschlossen hast, weiterhin so. Sie endet als **Auf
N Nachrichten angewendet** oder als **Lauf vorzeitig gestoppt — N Nachrichten waren erledigt**,
wenn etwas schiefging.

Ein bereits laufender Filter verweigert einen zweiten Start: Mitten im Lauf erneut zu starten
würde den Fortschritt doppelt zählen und mit den Schreibvorgängen des Handlers ins Rennen
gehen.

## In eigene Tabs ablegen

Ein Filter kann Mail in einen Posteingangs-Tab ablegen, den du selbst angelegt hast. Leg den Tab
zuerst an, unter **Einstellungen → Allgemein → Deine eigenen Tabs**; dann füge einen Filter hinzu,
dessen Aktion **In Kategorie ablegen** ist, und wähle ihn aus. Jede Bedingung funktioniert. Für
„alles von meinen Paketdiensten“ ist es **Absender-Domain ist**, eine Zeile je Paketdienst, in einer
**Beliebige**-Gruppe:

```
Alle der folgenden
  Beliebige der folgenden
    Absender-Domain ist mondialrelay.fr
    Absender-Domain ist chronopost.fr
    Absender-Domain ist dhl.de
  Keine der folgenden
    Absender-Domain ist e-mail.mondialrelay.fr
    Betreff enthält satisfaction
→ In Kategorie ablegen Sendungen
```

**Werbung draußen zu halten ist die zweite Gruppe.** Die Newsletter eines Paketdienstes kommen oft
von einer anderen Adresse als seine Zustellbenachrichtigungen, aber nichts in den Headern
unterscheidet die beiden — eine echte Zustellbenachrichtigung kann einen Abmeldelink tragen, genau
wie ein Newsletter —, also sagst du es im Filter. Mail, die die **Keine**-Gruppe ausschließt, wird
von diesem Filter schlicht nicht abgelegt. Sie wird so einsortiert, wie sie es ohnehin würde; der
Newsletter bleibt also in Werbung. Die Live-Zahl der passenden Mail und der Satz unter dem Editor
lesen dir das Ganze vor dem Speichern vor — ein falsches **Keine** sieht man dort leicht und
hinterher kaum.

Was die Aktion ablegt und was nicht:

- **Nur Mail, deren Absender die Authentifizierung besteht.** Der empfangende Mailserver hält
  fest, ob die Nachricht wirklich von der Domain in ihrer Absenderzeile kam, und plMail liest dieses
  Urteil (dasselbe, das entscheidet, ob eine Kalendereinladung ohne Nachfrage übernommen werden
  darf). Ein gefälschtes „Ihr Paket wartet“ von der Domain eines Paketdienstes wird deshalb von
  diesem Filter nicht abgelegt — es bleibt, wo die gewöhnlichen Regeln es hinlegen, statt neben den
  echten in deinem Sendungen-Tab zu sitzen. Mail, die die Prüfung nicht besteht, wird nicht
  abgelegt, und Mail, die plMail nicht prüfen kann, ebenso wenig; siehe „Fallstricke“ dafür, was das
  zusichert und was nicht.
- **Auch bei Gmail-Konten.** Googles Kategorien haben Vorrang vor plMails eigener Sortierung,
  solange du nichts anderes sagst, aber ein Filter ist eine Anweisung, die du geschrieben hast, und
  hat Vorrang vor beiden.
- **Nichts außer einem Tab.** Die Aktion fasst keine Labels an, archiviert nicht, verschiebt nichts
  in Spam und markiert nichts als gelesen. Die Android-App zeigt deine Tabs; andere Mail-Apps, die
  sich per JMAP verbinden, sehen weiterhin die fünf eingebauten Kategorien.

Eine Konversation steckt in **einem** Tab. Sie steckt in deinem, wenn ihre neueste Nachricht dort
abgelegt wurde; eine neuere Nachricht, die kein Filter beansprucht, nimmt sie also wieder heraus,
so wie eine neuere Nachricht aus Werbung eine Konversation aus Benachrichtigungen herausnimmt. Eine Konversation,
die du per Drag auf einen Tab gezogen hast, bleibt dort, was auch immer ein Filter sagt.

Würden zwei Filter dieselbe Nachricht ablegen, entscheidet der, der **zuletzt** läuft; **Nach
diesem Filter aufhören** beim früheren ist der Weg, seine Wahl zu behalten.

Einen Tab zu löschen legt seine Mail in den Tab zurück, in dem sie sonst läge. Es wird keine Mail
gelöscht, und ein Filter, der dort ablegte, behält seinen Platz und liest **(gelöschte Kategorie)**,
bis du ihn auf etwas anderes zeigen lässt.

## Wo du weiterliest

- [Mail](mail.md) — Labels, Suche und die Operationen, die diese Aktionen ausführen.
- [Eigene Tabs](mail.md#eigene-tabs) — sie anlegen, Konversationen hineinziehen, und was der
  Nachrichten-Bereich über sie sagt.
- [Dateien und Integrationen](integrations.md) — die Dienste verbinden, die **Anhänge speichern
  in** anbietet.
- [Mail-Ingest](../internals/mail-ingest.md) — wo im Ablauf die Filter sitzen.
- [JMAP](../internals/jmap.md) — das Filter-Vokabular, wie ein Client es sieht.

## Fallstricke

**Ein Filter ohne Bedingungen und ohne Konto meint jede Nachricht in jedem Konto.** Er ist ein
zulässiger Filter und ein nützlicher, solange er beschränkt ist — auf Alle Konten ist er aber
eine Regel, die alles einfängt, was dir gehört, und **Auf vorhandene Mail anwenden** reicht
dann in das gesamte Postfach. Die Rückübersetzung sagt das mit ebenso vielen Worten; lies sie,
bevor du speicherst.

**Die Trefferzahl ist bei 500 gedeckelt, der Lauf nicht.** „Trifft auf 500+ zu“ ist keine
Schätzung dessen, was **Auf vorhandene Mail anwenden** anfassen wird. Diese Zahl kann beliebig
groß sein.

**Filter sehen nur Mail, die ab jetzt eintrifft.** Einen zu speichern tut bereits
synchronisierter Mail nichts an, bis du **Auf vorhandene Mail anwenden** drückst.

**Eine fehlerhafte Aktion wird stillschweigend verworfen, eine fehlerhafte Bedingung laut
abgelehnt.** Diese Asymmetrie ist Absicht — eine verworfene Bedingung *erweitert* eine Regel
stillschweigend, und das ist die gefährliche Richtung, während eine verworfene Aktion immer nur
ein Client-Fehler sein kann und sie zu verwerfen der vorsichtige Ausgang ist.

**Ein Label zu löschen oder einen Dienst zu trennen löscht nicht die Regeln, die sich darauf
beziehen.** Sie laufen weiter, wobei diese Aktion nichts tut, und die Rückübersetzung markiert
die Lücke als **(gelöschtes Label)** oder **(getrennter Dienst)**.

**Das Aufhören gilt nur innerhalb eines Laufs.** Es entscheidet, welche späteren Filter eine
Nachricht im selben Durchgang überspringen; es ist keine dauerhafte Markierung an der
Nachricht.

**„Anhänge speichern in“ für einen Dienst anzuhaken, aus dem sich nur lesen lässt, ist
konstruktionsbedingt unmöglich** — die Liste bietet ausschließlich Verbindungen an, die
hochladen können. Fehlt die gewünschte, ist das der Grund.

**„In Kategorie ablegen“ legt in einem Konto nichts ab, dessen empfangenden Server plMail nicht
erkennen kann.** Es vertraut nur dem Authentifizierungs-Urteil, das ein Server in die Nachricht
geschrieben hat, von dem es weiß, dass er deiner ist — bei einem IMAP-Konto einer, dessen Name auf
derselben Domain liegt wie der IMAP-Host des Kontos. Bei einem Postfach, wo sich das nicht sagen
lässt (ein Host, der nichts einträgt oder unter anderem Namen einträgt), trifft ein Filter, der in
einen Tab ablegt, zu, läuft und legt nichts ab. Das ist der Preis für einen Tab, der sich nicht
mit Fälschungen füllen lässt, und er ist gewählt, kein Fehler.

**Die Prüfung betrifft die Nachricht, und sie hilft nur, wenn der Filter den Absender nennt.** Ein
Filter, dessen Bedingungen den Absender nicht festlegen — etwa „Betreff enthält Paket“ —, legt jeden
Absender ab, dessen eigene Domain die Authentifizierung besteht, auch eine ähnlich klingende Domain:
Authentifizierung belegt, dass eine Nachricht von der Domain kam, die sie angibt, nicht dass es die
Domain ist, die du meintest. Lege den Absender mit **Absender-Domain ist** fest. Und ein Tab enthält
Konversationen, nicht Nachrichten: Eine gefälschte Nachricht, die in eine echte Konversation
antwortet (dieselbe Absenderadresse, ein „Re:“-Betreff), kann ihr beitreten, und eine Konversation,
die du in einen Tab gezogen hast, bleibt dort, was auch immer ihr danach beitritt.

**Ein Ablegen wird gemerkt, und den Filter zu ändern oder zu löschen macht es nicht rückgängig.**
Wie jede andere Aktion wirkt sie auf Mail und lässt das Ergebnis stehen: Was ein Filter abgelegt
hat, bleibt abgelegt. Um neu abzulegen, ändere den Filter und drücke **Auf vorhandene Mail
anwenden**; um einen Tab zu leeren, zieh seine Konversationen heraus oder lösche den Tab.

**Der letzte Filter, der eine Nachricht ablegt, gewinnt** — das Gegenteil dessen, was die
Reihenfolge einer Regelliste sonst nahelegt. Setze einen breiten Filter über die spezifischen, oder
gib einem spezifischen **Nach diesem Filter aufhören**.
