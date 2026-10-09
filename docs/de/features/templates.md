<!-- translated-from: features/templates.md sha1:a5777ed6509de0a4db4bf395c64599bfbbd69989 -->

# Vorlagen

Eine Vorlage ist eine Nachricht, die du einmal schreibst und beim Verfassen einfügst: die
Erinnerung, das „Danke, ist angekommen“, die Antwort, die du schon vierzigmal getippt hast. Du
findest sie unter **Einstellungen → Vorlagen**, und im Verfassen-Fenster gibt es einen Knopf, der
eine einfügt.

## Der Baum

Links im Abschnitt steht ein Baum mit allem, was du hast.

- **Ganz oben** die Vorlagen, die in keinem Ordner liegen. Sie werden dir angeboten, egal von
  welcher Adresse du schreibst.
- **Ein Ordner pro E-Mail-Konto.** Die sind immer da, ob leer oder nicht, und du kannst sie weder
  umbenennen noch löschen: Es sind deine Konten, als Ordner gezeichnet. Fügst du ein Konto hinzu,
  erscheint sein Ordner; einrichten musst du nichts.
- **Ordner, die du selbst anlegst**, in einem Konto, ineinander oder neben den Konten auf der
  obersten Ebene.

**Neue Vorlage** und **Neuer Ordner** über dem Baum legen auf der obersten Ebene an. Fährst du über
einen Ordner, erscheinen dieselben zwei Knöpfe, um *in* ihm anzulegen, und bei einem eigenen Ordner
dazu Umbenennen und Löschen.

Eine Vorlage unter einem Konto abzulegen sagt, wo du sie meistens brauchst, nicht, wo sie erlaubt
ist. Jede Vorlage lässt sich von jeder Adresse aus einfügen; die des Kontos stehen zuerst.

Einen eigenen Ordner kannst du umbenennen und löschen, aber nicht verschieben. Um seinen Inhalt zu
verschieben, änderst du im Editor bei jeder Vorlage den **Ordner**.

## Eine Vorlage schreiben

Wählst du eine Vorlage aus oder legst eine an, öffnet sich neben dem Baum der Editor.

| Feld | |
|---|---|
| **Name** | So heißt sie im Baum und in der Liste im Verfassen-Fenster. Wird nie gesendet. |
| **Ordner** | Wo sie abgelegt ist. **Kein Ordner** ist die oberste Ebene. |
| **Betreff** | Optional. Beim Einfügen füllt die Vorlage die Betreffzeile **nur, wenn sie noch leer ist**. Eine Vorlage in einer Antwort benennt die Unterhaltung also nicht um. |
| **Nachricht** | Der Text, mit derselben Formatierungsleiste wie im Signatur-Editor. |

**Speichern** legt sie ab und schließt den Editor; im Baum siehst du, dass es geklappt hat.
**Duplizieren** legt eine zweite Kopie daneben, mit dem Namen „Kopie von …“. **Vorschau** zeigt,
was die Vorlage auf dem Bildschirm heute für einen Beispielempfänger einfügen würde, ohne sie zu
speichern.

## Variablen

Eine Variable ist ein Teil der Nachricht, der beim Einfügen der Vorlage ausgefüllt wird.
**Variable einfügen** setzt eine an die Stelle der Schreibmarke, im Betreff oder in der Nachricht,
und sie erscheint als Plakette.

| Variable | Wird zu |
|---|---|
| **Vorname** | Vorname des ersten Empfängers |
| **Voller Name** | Name des ersten Empfängers |
| **Adresse des Empfängers** | E-Mail-Adresse des ersten Empfängers |
| **Dein Name** | Name des Kontos, von dem du schreibst |
| **Deine Adresse** | Genau die Adresse in **Von**, auch ein Alias |
| **Signatur** | Eine Signatur — siehe unten |
| **Datum** | Ein Datum relativ zu dem Tag, an dem du die Vorlage einfügst — siehe unten |

„Der erste Empfänger“ ist die erste Adresse in **An**. Cc und Bcc werden nicht herangezogen.

### Datum

Klick auf eine Datums-Plakette, um sie einzustellen. Ein Datum hat einen **Abstand** — eine Zahl
von Tagen, Wochen oder Monaten vor oder nach dem Tag, an dem die Vorlage eingefügt wird — und ein
**Format**:

| Format | Auf Englisch | Auf Deutsch |
|---|---|---|
| **Kurz** | 10/16/26 | 16.10.26 |
| **Mittel** | Oct 16, 2026 | 16.10.2026 |
| **Lang** | October 16, 2026 | 16. Oktober 2026 |
| **Ausführlich** | Friday, October 16, 2026 | Freitag, 16. Oktober 2026 |
| **Wochentag** | Friday | Freitag |
| **ISO** | 2026-10-16 | 2026-10-16 |
| **Eigenes Muster** | was das Muster sagt | |

Die vier benannten Stile folgen der Sprache, auf die plMail eingestellt ist, eine Vorlage liest
sich also in beiden richtig. Ein eigenes Muster schreibst du mit `d` für den Tag, `M` für den
Monat, `y` für das Jahr und `E` für den Wochentag: `dd.MM.yyyy` ergibt 16.10.2026,
`EEEE d MMMM` ergibt Freitag 16 Oktober. Die Zeile unter den Feldern schreibt das heutige
Ergebnis aus, während du sie änderst.

Jede Plakette hat ihre eigenen Einstellungen, eine Vorlage kann also „die Rechnung vom
25. September“ und „fällig bis 2026-10-16“ nebeneinander sagen. „Heute“ ist heute in deiner eigenen
Zeitzone.

### Signatur

Klick auf eine Signatur-Plakette, um auszuwählen, für welche Signatur sie steht.

- **Automatisch** ist die Signatur der Adresse in **Von**, dieselbe, die das Verfassen-Fenster
  genommen hätte. Sie folgt **Von** auch dann noch, wenn die Vorlage schon eingefügt ist.
- **Eine ausgewählte Signatur** — die eines deiner Konten oder einer Adresse, die eine eigene hat —
  wird genommen, egal von welcher Adresse du schreibst, und bleibt, wenn du **Von** änderst.

So oder so hat die Nachricht am Ende eine Signatur: Bringt eine Vorlage eine mit, ersetzt sie die,
mit der das Fenster aufging, statt eine zweite anzuhängen. Eine Vorlage ohne Signatur-Variable
lässt die Signatur des Fensters in Ruhe.

## Eine Vorlage einfügen

In der Werkzeugleiste des Verfassen-Fensters steht **Vorlage einfügen**, neben **Signatur
einfügen**. Der Knopf öffnet eine Liste: die Vorlagen des Kontos, von dem du schreibst, dann die
der obersten Ebene, dann alles andere. Tipp ins Feld, um sie nach Namen einzugrenzen. Zeigst du auf
eine Vorlage, stehen rechts ihr Betreff und ihr Text, die Variablen noch als Plaketten.

Wählst du eine aus, landet sie in der Nachricht an der Schreibmarke. Eine leere Zeile wird durch
die Vorlage ersetzt; steht in der Zeile schon Text, kommt die Vorlage darunter. Daten, dein Name
und deine Adresse und die Signatur werden in diesem Moment ausgefüllt und sind von da an normaler
Text — ändere sie, wie du willst.

### Wenn es noch keinen Empfänger gibt

Oft sucht man die Vorlage aus, bevor jemand adressiert ist. Die Empfänger-Variablen bleiben dann
als Platzhalter in der Nachricht, als Plaketten mit **Vorname**, **Voller Name** oder **Adresse des
Empfängers**, und werden ausgefüllt, sobald in **An** ein Empfänger steht. Einmal ausgefüllt sind
sie Text und ändern sich nicht mehr, wenn du den Empfänger tauschst.

Ein Platzhalter bleibt offen, wenn der Empfänger ihn nicht beantworten kann. Meistens ist das der
Vorname: Eine getippte Adresse hat keinen Namen, und plMail rät keinen aus dem Teil vor dem `@`.
Wähl die Person aus den Vorschlägen oder tipp den Namen über den Platzhalter.

**Senden fragt vorher nach**, wenn noch ein Platzhalter offen ist, genauso wie bei einem fehlenden
Betreff.

### Eine Nachricht als Vorlage speichern

**Diese Nachricht als Vorlage speichern**, am Fuß der Liste, behält, was du geschrieben hast. Sie
wird nach ihrem Betreff benannt und unter dem Konto abgelegt, von dem du schreibst; umbenennen oder
verschieben kannst du sie in den Einstellungen. Das zitierte Original unter einer Antwort bleibt
draußen, und die Signatur wird als **Signatur**-Variable gespeichert, nicht als die heutige
Signatur in festem Text.

## Wo du weiterliest

- [Mail](mail.md) — das Verfassen-Fenster, Signaturen und was Senden prüft, bevor es sendet.
- [Konten und Aliasse](accounts.md) — die Konten, die der Baum spiegelt, und Absenderadressen.
- [Erscheinungsbild](appearance.md) — die Spracheinstellung, der die Datumsformate folgen.

## Fallstricke

**Ein E-Mail-Konto zu entfernen behält seine Vorlagen.** Der Ordner des Kontos verschwindet und mit
ihm die Ordner, die du darin angelegt hast; die Vorlagen darin rücken auf die oberste Ebene. Einen
Ordner zu löschen macht dasselbe eine Ebene höher: Seine Vorlagen rücken dorthin, wo der Ordner
war.

**Eine getippte Adresse hat keinen Vornamen.** Steht `dana@example.org` getippt in **An**, wird
**Adresse des Empfängers** ausgefüllt und **Vorname** nicht. Das ist Absicht — „Hallo
dana.whitfield,“ ist schlimmer als ein Platzhalter — und Senden fragt nach, bevor die Nachricht
rausgeht.

**Sendest du trotzdem, werden die Worte des Platzhalters gesendet.** Eine Nachricht, die mit
offenem **Vorname** rausgeht, lautet „Hallo Vorname,“. Die Frage vor dem Senden ist das Einzige,
was zwischen dem und dem Empfänger steht.

**Der Betreff füllt nur eine leere Betreffzeile.** Fügst du eine zweite Vorlage in dieselbe
Nachricht ein, ersetzt sie den Betreff der ersten nicht, und in einer Antwort auch nicht.

**Eine ausgewählte Signatur wird gelesen, wenn die Vorlage eingefügt wird, nicht wenn sie
geschrieben wird.** Änderst du die Signatur unter Einstellungen → Signaturen, nimmt die Vorlage die
neue. Entfernst du das Konto, zu dem sie gehört, fällt die Vorlage auf **Automatisch** zurück.

**Geschweifte Klammern, die keine Variable sind, bleiben stehen.** Variablen werden als Text wie
`{{recipient.first_name}}` gespeichert. Tippst du das von Hand in eine Vorlage, wird es eine
Variable; `{{irgendetwas anderes}}` ist einfach Text und wird so eingefügt, wie er dasteht.

**Vorlagen gibt es vorerst nur im Web.** JMAP-Clients bekommen sie nicht angeboten, die Android-
und die iOS-App führen sie also nicht auf.
