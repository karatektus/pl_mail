<!-- translated-from: features/appearance.md sha1:da1d4ddbb3e6aa66c1179ff6a9c65287ffe37d06 -->

# Darstellung

**Einstellungen → Darstellung** entscheidet, wie plMail für dich aussieht und für sonst
niemanden. Jedes Bedienelement wirkt, sobald du es anfasst, und speichert sich selbst — einen
Speichern-Knopf gibt es auf dieser Seite nicht.

![Dunkler Modus](../screenshots/inbox-dark.png)

## Die zwei Achsen

Das Theme wählt die Palette; das Layout wählt, wie sie aufgetragen wird. Sie sind voneinander
unabhängig, und deshalb sind es zwei Bedienelemente und nicht eine lange Liste.

**Theme** bietet **System**, **Hell**, **Papier**, **Dunkel**, **Nord**, **Dämmerung** und
**Solar**, jedes als Farbtupfer aus seinen eigenen Farben. Ein neues Konto startet auf
**Papier** und nicht auf System: „folge dem Betriebssystem“ löst sich zu schlichtem Weiß oder
schlichtem Dunkel auf, je nachdem, was der Rechner gerade bevorzugt, und das sind hier die
beiden am wenigsten durchdachten Paletten. System bleibt für alle da, die das Betriebssystem
entscheiden lassen wollen, und es wird im Browser aufgelöst, damit die Seite beim Laden nie
kurz das falsche zeigt.

**Layout** ist **Flach** oder **Kacheln**. Flach setzt Kopf- und Seitenleiste direkt auf den
Hintergrund und lässt nur den Hauptbereich als Karte stehen; Kacheln lässt jede Fläche als
eigene Karte schweben. Eines davon zu wählen setzt die Regler darunter auf die Zahlen dieses
Layouts, damit die beiden nie einen Zustand beschreiben, den keines der Layouts hervorbringen
würde. Flach ist die Vorgabe.

## Die übrigen Bedienelemente

| Abschnitt | Was er einstellt |
|---|---|
| **Hauptbereich** | Ein Hintergrundfarbton und eine Deckkraft für den Inhaltsbereich allein, oder **An Glas-Deckkraft angleichen**, um den Flächen zu folgen |
| **Glas** | **Deckkraft**, **Weichzeichnen**, **Eckenradius** und **Hintergrundabdunklung** — wie viel vom Hintergrund durch deine Flächen scheint |
| **Text** | Textfarbe sowie **Gedämpft** und **Sehr blass**, oder **Automatisch ableiten**, damit diese beiden aus der Hauptfarbe errechnet werden |
| **Akzentfarbe** | Die eine Signalfarbe, als Hexwert |
| **Dichte** | **Komfortabel**, **Gemütlich** oder **Kompakt** — Zeilenhöhe und Abstände |
| **Hintergrund** | **Theme-Standard**, eines von acht mitgelieferten Bildern, oder **Bild hochladen** |

Jedes Zahlen-Bedienelement ist begrenzt, ein von außen getippter oder importierter Wert wird
also gekappt statt übernommen: Deckkraft zwischen 0,15 und 1, Weichzeichnen zwischen 0 und 60
Pixeln, Eckenradius zwischen 0 und 2 rem, Hintergrundabdunklung zwischen 0 und 0,7.

Die Akzentfarbe muss ein sechsstelliger Hexwert mit führendem `#` sein. Alles andere fällt auf
die Vorgabe zurück, statt gespeichert zu werden, und dasselbe gilt für die drei Textfarben und
den Farbton des Hauptbereichs, die dann „nicht gesetzt“ sind.

## Hintergründe

**Bild hochladen** nimmt JPEG, PNG und WebP. Die Datei wird je Benutzer gespeichert, und ein
neues Bild hochzuladen löscht das vorherige — eine Galerie früherer Hintergründe gibt es nicht.

Ein Foto oder ein mitgeliefertes Bild zu wählen hebt die Untergrenze der Flächendeckkraft auf
0,45, was der Regler auch sagt, sowohl bei den Flächen als auch im Hauptbereich. Darunter hört
Text auf einem Foto auf, lesbar zu sein, und eine lesbare Oberfläche ist mehr wert als der
letzte Rest Transparenz.

## Export und Import

**Theme exportieren** lädt `plmail-theme.json` herunter. **Theme importieren** nimmt eine
solche Datei zurück. **Auf Standard zurücksetzen** stellt alles dorthin, wo ein neues Konto
beginnt.

Der Export trägt die Version, das Theme, das Layout, den Akzent, alle vier Glas-Zahlen, die
Dichte, die Hintergrundwahl, die Textfarben und die Einstellungen des Hauptbereichs. Er trägt
bewusst **nicht** dein hochgeladenes Hintergrundbild: Ein Dateiname bedeutet auf der
Installation von jemand anderem nichts, ein eigener Hintergrund wird also als **Theme-Standard**
exportiert.

Der Import prüft die Version und lehnt eine Datei ab, die nicht Version 1 ist. Alles in den
Daten, was plMail nicht als gültigen Wert kennt, wird ignoriert statt gespeichert, und ein
Layout in der Datei wird vor den einzelnen Zahlen angewendet, damit ein Export, der geschrieben
wurde, bevor es ein bestimmtes Bedienelement gab, trotzdem irgendwo Sinnvollem landet.

## Sprache

**Einstellungen → Allgemein → Sprache** legt fest, in welcher Sprache die Oberfläche angezeigt
wird. Deine Mail bleibt genau so, wie sie geschrieben wurde — es wird nichts übersetzt und
nichts umgeschrieben.

plMail liefert **English**, **Deutsch** und **Pirate English** mit. Eine Änderung lädt die
Seite neu, statt sie nachzubessern, denn jede Zeichenkette auf dem Bildschirm muss neu
gerendert werden.

Derselbe Abschnitt trägt die **Zeitzone**, die über die Uhrzeiten und Daten entscheidet, die
dir angezeigt werden. Auch sie schreibt nichts um — derselbe Zeitpunkt wird schlicht auf deiner
Uhr gelesen — und sie darf auf dem Server-Standard stehen bleiben.

## Wo du weiterliest

- [Mail](mail.md) — die Listen und Flächen, die diese Einstellungen anmalen.
- [Andere Clients](clients.md) — eine App eines Drittanbieters hat ihre eigene Darstellung;
  hier geht es allein um die Weboberfläche.
- [Client development](../CLIENT_DEVELOPMENT.md) — das Zwei-Achsen-Modell, seine semantischen
  Farbtoken und wie man sie nachbaut, für alle, die einen Client schreiben, der wie plMail
  aussehen soll.

## Fallstricke

**Ein eigener Hintergrund überlebt einen Export nicht.** Er wird absichtlich ausgelassen, denn
die Datei liegt auf dieser Installation, und ein Dateiname würde sich nirgendwo sonst auflösen.
Der Import deines eigenen Exports lässt dich deshalb auf dem Theme-Standard, bis du das Bild
erneut hochlädst.

**Der Deckkraftregler hört unter einem Foto auf, etwas zu bedeuten.** Alles unter 0,45 wird
stillschweigend angehoben, solange ein Hintergrund verwendet wird, der nicht vom Theme kommt.
Der Regler bewegt sich weiterhin; die Darstellung folgt ihm nicht bis nach unten.

**Ein Layout zu wählen überschreibt die Glas-Regler.** Genau das *ist* das Layout-Bedienelement
— eine Voreinstellung für diese Zahlen. Setze erst das Layout, dann die Zahlen, nicht
andersherum.

**Ein Hexwert ohne sechs Stellen ist kein Fehler, sondern ein Rückfall.** Der Akzent kehrt
stillschweigend zur Vorgabe zurück und die drei Textfarben werden stillschweigend „nicht
gesetzt“ — ein Tippfehler sieht also aus, als hätte das Bedienelement keine Wirkung.

**Der Import lehnt alles ab, was nicht Version 1 ist.** Es gibt keine Migration älterer oder
neuerer Dateien; die Antwort ist eine Ablehnung und kein teilweises Anwenden.

**Die Darstellung gilt je Benutzer, nicht je Gerät.** Es gibt keinen Weg, auf dem Telefon das
dunkle und auf dem Schreibtisch das helle Theme zu haben, außer **System** zu wählen und jedes
Gerät selbst entscheiden zu lassen.
