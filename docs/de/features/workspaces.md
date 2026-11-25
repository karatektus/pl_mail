<!-- translated-from: features/workspaces.md sha1:c739241758061d4f87ca9c836b272640cb384d33 -->

# Arbeitsbereiche

Die Fläche neben der Seitenleiste ordnest du selbst an. Sie fasst bis zu fünf Bereiche — die
**Mailliste**, die **Nachricht**, den **Kalender**, die **Kontakte** und die **Aufgaben** —
nebeneinander, über- und untereinander, in beliebiger Reihenfolge. Eine Anordnung ist ein
**Arbeitsbereich**, und du kannst bis zu neun davon behalten, zum Beispiel einen zum Abarbeiten neuer
Mail und einen zum Planen der Woche.

Jeder Bereich kommt in einem Arbeitsbereich höchstens einmal vor. Zwei Maillisten nebeneinander (der
Posteingang neben Gesendet) gehen noch nicht.

## Das Bereichsmenü

Jeder Bereich hat am Ende seiner Kopfzeile eine Schaltfläche **⋯**. Ihr Menü erledigt alles für
diesen Bereich:

- **Rechts teilen** und **Darunter teilen** fügen einen Bereich daneben oder darunter hinzu. Das
  Untermenü bietet die Bereiche an, die der Arbeitsbereich noch nicht zeigt. Ist der Bereich zu klein,
  um beide in brauchbarer Größe zu fassen, sagt plMail das, statt sie zu quetschen; mach ihn zuerst
  größer oder schließ einen anderen Bereich.
- **Stattdessen zeigen** setzt einen anderen Bereich an seine Stelle. Wählst du einen, der schon an
  anderer Stelle im Arbeitsbereich ist, tauschen die beiden die Plätze.
- **Verschieben** nach links, oben, rechts oder unten tauscht ihn mit dem Bereich daneben auf dieser
  Seite. Gibt es in dieser Richtung keinen Bereich mehr, verlässt er seine Gruppe und wird zu einer
  Spalte über die ganze Höhe (links, rechts) oder einer Zeile über die ganze Breite (oben, unten) an
  diesem Rand des Fensters.
- **Volle Breite** gibt dem Bereich die ganze Fläche; **Zurück zum Layout** (auch die Schaltfläche ↙
  in seiner Kopfzeile) stellt ihn wieder zurück.
- **Bereich schließen** nimmt ihn aus dem Arbeitsbereich. Den letzten Bereich kannst du nicht
  schließen; nimm **Stattdessen zeigen**.

Zieh die Lücke zwischen zwei Bereichen, um ihre Größe zu ändern, oder setz den Fokus darauf und nimm
die Pfeiltasten. Ein Doppelklick darauf macht beide Seiten gleich groß.

### Tastatur

| Tasten | Was sie tun |
| --- | --- |
| ⌥ + ← ↑ → ↓ (Alt unter Windows und Linux) | Den nächsten Bereich in diese Richtung fokussieren |
| ⌥ ⇧ + ← ↑ → ↓ | Den fokussierten Bereich in diese Richtung verschieben |
| ⌥ \\ und ⌥ - | Den fokussierten Bereich rechts oder darunter teilen |
| ⌥ ↩ | Volle Breite, und zurück |
| ⌥ W | Den fokussierten Bereich schließen |
| ⌥ 1 … ⌥ 9 | Zu Arbeitsbereich 1 bis 9 wechseln |
| ⌥ E | Den Arbeitsbereich bearbeiten, und speichern |
| ⌥ L | Das Layout-Menü öffnen |

Keine davon wirkt, während du in einem Feld tippst.

## Ohne Bereich Nachricht

Ein Arbeitsbereich ohne Bereich **Nachricht** öffnet eine Unterhaltung an der Stelle der Liste, mit
einem Zurück-Pfeil zur Liste, wie plMail es auf dem Telefon immer gemacht hat. Mit ihm öffnet sich die
Unterhaltung neben der Liste, und die Liste bleibt, wo sie ist.

## Arbeitsbereiche

Deine Arbeitsbereiche sind die Reiter in der oberen Leiste; klick auf einen oder drück ⌥ 1 bis ⌥ 9.
Die Schaltfläche **Layout** daneben öffnet das Layout-Menü:

- **Arbeitsbereiche** listet sie mit einem Umriss von jedem auf.
- **Mit einer Form beginnen** bietet acht Anordnungen: ein Bereich, nebeneinander, drei Spalten,
  über- und untereinander, Liste + Stapel, oben + zwei, Liste/Stapel/Seite und vier. Wähl eine, leg
  fest, was in jeden Platz kommt, und nimm dann **Für diesen Arbeitsbereich nutzen** oder **Als neuen
  Arbeitsbereich speichern**.
- **Diesen Arbeitsbereich bearbeiten** öffnet den Bearbeitungsmodus, und **Neuer Arbeitsbereich**
  beginnt einen als Kopie des aktuellen, im Bearbeitungsmodus.

### Bearbeitungsmodus

Im Bearbeitungsmodus wird die obere Leiste zur Bearbeitungsleiste, mit dem Namen des Arbeitsbereichs
und **Arbeitsbereich löschen**, **Abbrechen** und **Arbeitsbereich speichern**. Jeder Bereich zeigt eine
Karte mit dem, was er zeigt, einem Griff, einer Schaltfläche zum Entfernen und dem Anteil, den er an
der Fläche hat. Änderungen siehst du sofort:

- Klick auf einen Bereich, um ihn auszuwählen. **+ Rechts** und **+ Darunter** am ausgewählten Bereich
  fügen einen daneben oder darunter hinzu.
- Zieh einen Bereich an seinem Griff auf einen anderen: In der Nähe eines Rands kommt er auf diese
  Seite daneben, in der Mitte tauschen die beiden.
- Die Leiste unten enthält die Bereiche, die der Arbeitsbereich nicht zeigt. Zieh einen davon genauso
  auf einen Bereich, oder klick ihn an, um ihn neben dem ausgewählten Bereich einzufügen.
- Wähl in der Liste einer Karte einen anderen Bereich, um ihn dort stattdessen zu zeigen.

**Arbeitsbereich speichern** behält die Anordnung. Ein Bereich, der schmaler geworden ist, als er sich
benutzen lässt, bekommt beim Speichern Platz von seinen Nachbarn. **Abbrechen** stellt wieder her, was
beim Start des Bearbeitungsmodus da war.

## Was wo gespeichert wird

Deine Arbeitsbereiche — ihre Namen, welche Bereiche sie zeigen und wie sie angeordnet sind — gehören
zu deinem Konto und sind in jedem Browser gleich, in dem du dich anmeldest. Sie sind Teil einer
[Konfigurationssicherung](../install/config-backup.md).

Wohin du die Trenner gezogen hast und welcher Bereich in voller Breite ist, gehört zum Gerät: Ein
Laptop und ein großer Monitor können eigene Größen für denselben Arbeitsbereich behalten. Ein neues
Gerät startet mit den Anteilen, mit denen der Arbeitsbereich gespeichert wurde.

## Auf dem Telefon und in einem kleinen Fenster

Unter 1024 Pixeln Breite wechseln sich die Bereiche ab: Eine Reihe von Reitern darüber nennt die
Bereiche des Arbeitsbereichs, und einer wird nach dem anderen gezeigt. Die Nachricht ist kein Reiter;
sie öffnet sich an der Stelle der Liste. Bearbeitungsmodus und Layout-Menü sind für ein breiteres
Fenster gedacht.

## Der erste Arbeitsbereich

Wenn du plMail nach dem Update zum ersten Mal öffnest, ist dein erster Arbeitsbereich das Layout, das
du hattest: die Mail, die Nachricht daneben, wenn der Lesebereich eingeschaltet war, und Kalender,
Kontakte und Aufgaben, wenn sie offen waren. Ein neues Konto startet mit der Mail und dem Kalender
daneben.

Die Kalender-, Kontakte- und Aufgaben-Schaltflächen in der oberen Leiste, ihre Einstellungen
**Kalenderverhalten** und **Kontakteverhalten** und die Einstellung **Lesebereich** sind weggefallen:
Das Bereichsmenü und deine Arbeitsbereiche übernehmen, was sie getan haben. **Kontakte** und
**Aufgaben** stehen auf dem Telefon weiter im Menü, und ihre Seiten (`/contacts`, `/tasks`) zeigen den
Bereich in voller Breite.

## Fallstricke

- **Ein Arbeitsbereich ohne Mailliste hat trotzdem Mail.** Wählst du einen Ordner in der
  Seitenleiste, erscheint die Liste in voller Breite; **Zurück zum Layout** bringt dich zum
  Arbeitsbereich, wie du ihn angeordnet hast.
- **Auf `/contacts` und `/tasks` führt das Verlassen der vollen Breite zum Posteingang.** Diese
  Seiten zeichnen einen Bereich und keine Liste dahinter, also gibt es kein Layout, zu dem es an Ort
  und Stelle zurückgehen könnte.
- **Die Größen stehen in einem Cookie dieses Browsers.** Löschst du die Cookies der Seite, oder in
  einem privaten Fenster, beginnst du mit den Anteilen, mit denen der Arbeitsbereich gespeichert
  wurde. Die Arbeitsbereiche selbst sind davon nicht betroffen.
- **Im Bearbeitungsmodus kann ein Bereich schmaler sein, als er sich benutzen lässt,** weil das
  Umstellen durch solche Zustände führt. Beim Speichern bekommt jeder solche Bereich Platz von seinen
  Nachbarn, wo die welchen übrig haben; in einem kleinen Fenster reicht das vielleicht nicht, und ein
  Bereich bleibt eng, bis du einen schließt.
- **⌥-Kürzel tun nichts, während ein Feld den Fokus hat,** wo ⌥ wortweise springt oder
  Sonderzeichen tippt. Klick zuerst in die Liste oder einen Bereich.
