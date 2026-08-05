<!-- translated-from: features/filters.md sha1:5e4d8cbf54aca3e66fde1708f1c3a05af4fd6fee -->

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

## Wo du weiterliest

- [Mail](mail.md) — Labels, Suche und die Operationen, die diese Aktionen ausführen.
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
