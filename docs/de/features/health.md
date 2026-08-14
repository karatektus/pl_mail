<!-- translated-from: features/health.md sha1:eabf9f7963587de2a004234659b32c18424ddf68 -->

# Zustand der Konten

**Einstellungen → Zustand der Konten** ist die Seite, die die Frage „warum kommt keine Post mehr?“
beantwortet — und, wo sie kann, den Knopf anbietet, der es behebt.

Alles auf dieser Seite war ohnehin bekannt. plMail hält fest, wenn ein Aktualisierungs-Token nicht
mehr funktioniert, wenn die Synchronisierung eines Kalenders immer wieder scheitert, wenn eine
Push-Registrierung verstummt ist, wenn die Verbindung zu einem Dateidienst abgelaufen ist; es hat
dir davon nur nie etwas gezeigt, sodass eine tote Anmeldung wie ein von selbst still gewordenes
Postfach aussah. Diese Seite liest den gespeicherten Zustand zurück. Sie fragt nie nach: Nichts hier
schickt eine Anfrage an einen Anbieter, um herauszufinden, wie es steht — die Seite zu öffnen kostet
also nichts, und ihre Antworten sind so frisch wie der letzte Versuch.

## Der Eintrag taucht auf, wenn es etwas zu sagen gibt

**Zustand der Konten ist kein dauerhafter Eintrag in der Einstellungsliste.** Er ist da, wenn etwas
Aufmerksamkeit braucht, und fehlt, wenn nichts es tut — dass der Eintrag auftaucht, ist selbst das
Signal, statt dass eine Zeile einen Platz in einer langen Liste damit verbringt zu sagen, dass alles
in Ordnung ist.

Das ist eine Änderung. Wer eine ältere Fassung dieser Seite gelesen oder sich gemerkt hat, wo der
Eintrag sitzt, wird an einem guten Tag danach suchen und ihn nicht finden. Es ist nichts kaputt und
nichts entfernt worden.

Die Seite selbst ist immer erreichbar. Die Adresse funktioniert weiter, sie ist der Ort, an den dich
das Neuverbinden eines Kontos zurückbringt, und die Anzeige in der Kopfleiste zeigt auf sie — ein
Lesezeichen oder ein Punkt, dem du nach einer Reparatur gefolgt bist, landet also auf der Seite,
statt woandershin umgeleitet zu werden. Sie zu besuchen, während alles gesund ist, zeigt die
Entwarnung: *Alles funktioniert*, mit einem Satz dazu, dass der Eintrag in die Einstellungsliste
zurückkehrt, falls je keine Post mehr ankommt. Keine Wand aus grünen Haken und keine leere Seite.
Solange du auf der Seite stehst, wird der Eintrag mit angezeigt, damit die Liste etwas hat, das sie
als aktuell markieren kann.

## Was sie meldet

Sechs Dinge können auftauchen, und jedes sagt, was es für deine Post bedeutet, statt wie der Anbieter
es genannt hat:

| Was | Was es bedeutet | Reparatur |
|---|---|---|
| **Ein Konto braucht eine erneute Anmeldung** | Die gespeicherte Anmeldung funktioniert nicht mehr, also läuft an diesem Konto nichts mehr — keine neue Post, keine Kalendersynchronisierung, keine Filter beim Eintreffen, kein Senden. Bereits geladene Post bleibt unangetastet. | **Konto neu verbinden** |
| **Ein Kalender synchronisiert nicht mehr** | Dieser Kalender zeigt, was er beim letzten funktionierenden Lauf wusste. Änderungen von anderswo kommen nicht an, und deine Änderungen gehen nicht hinaus. | **Jetzt synchronisieren** |
| **Die Sofortzustellung ist abgelaufen** | Die Registrierung ist abgelaufen und niemand hat sie erneuert, es wird also gar nichts mehr gepusht. | **Push neu registrieren** |
| **Die Sofortzustellung ist registriert, kommt aber nicht an** | Die Registrierung lebt und ist nicht abgelaufen, und es ist Post eingegangen, die sie nie angekündigt hat. Die Benachrichtigungen gehen auf dem Weg hierher verloren. | **Push neu registrieren** |
| **Eine Verbindung muss erneuert werden** | Anhänge in einem Dateidienst zu speichern und Dateien daraus anzuhängen funktioniert nicht. Deine Post ist davon unberührt. | **Neu verbinden** |
| **Hintergrundaufgaben wurden aufgegeben** | Arbeit, die wiederholt gescheitert ist und beiseitegelegt wurde, damit sie aufhört, es zu versuchen. Meist die Folge eines der anderen Punkte und kein eigener Fehler. | **Wieder einreihen** oder **Verwerfen** |

Jede Reparatur sagt *vor* dem Drücken, was sie tun wird, und jede sagt, was sie in Ruhe lässt. Wird
eine gedrückt, ändert sich der Knopf, solange sie läuft — eine Reparatur, die untätig aussieht, wird
zweimal gedrückt.

**Folgen stehen unter ihrer Ursache.** Eine einzige tote Anmeldung kann drei Kalender und
fünfhundert wartende Aufgaben mitreißen. Das ist eine Sache, die zu erledigen ist, und nicht neun —
also stehen die Kalender unter dem Konto, das sie erklärt, und die Zahl in der Kopfleiste zählt
Ursachen statt Symptomen.

**Die Dringlichkeit richtet sich nach der Folge, nicht danach, wie alarmierend der Fehler klingt.**
Dass die Sofortzustellung auf den Zeitplan zurückfällt, ist ein Hinweis und lässt die Anzeige in der
Kopfleiste bewusst *nicht* aufleuchten: Deine Post kommt an. Eine Seite, die „leicht verzögert“ so
rot malt wie „angehalten“, ist eine Seite, die man zu schließen lernt — und dann bleibt auch das Rot
ungelesen, auf das es ankam.

Der Fehlertext des Anbieters bleibt erhalten, hinter **Technische Einzelheiten**. Er ist da, wenn du
ihn brauchst, und aus dem Weg, wenn nicht.

## Ein Konto an Ort und Stelle neu verbinden

Das ist der Punkt, den man kennen sollte, bevor man ihn braucht.

Stirbt eine Anmeldung — ein geändertes Passwort, eingeschaltete Zwei-Faktor-Authentifizierung, ein
auf der Sicherheitsseite des Anbieters entzogener Zugriff —, ist der Reflex, das Konto zu löschen und
neu anzulegen. Das funktioniert, und es kostet dich alles: jede synchronisierte Nachricht, jede
Konversation, jedes Label, jede Regel, die darauf zeigte.

**Konto neu verbinden** ruft die Zustimmungsseite des Anbieters erneut auf und hinterlegt die frische
Anmeldung an genau dem Konto, das du schon hast. Post, Konversationen, Labels, Filter, Aliase,
Kalender und Einstellungen bleiben exakt so, wie sie sind. Es wird nichts erneut geladen und nichts
gelöscht; die Synchronisierung macht schlicht dort weiter, wo sie stehen geblieben ist, und holt in
den nächsten Minuten auf.

**Es ist über die Identität abgesichert.** Du musst dich als dieselbe Adresse beim selben Anbieter
anmelden. Sich als ein anderes Google-Konto anzumelden — das zweite in der Kontoauswahl, ein Fehler,
den jeder macht — wird **rundheraus abgelehnt**, und es wird nichts verändert. plMail nennt dir, als
welche Adresse du dich tatsächlich angemeldet hast und welche es erwartet hat. Die Adresse
auszutauschen hieße, fremde Post in diese Konversationen einzumischen, ohne Weg zurück.

## Die zwei Arten, wie die Sofortzustellung kaputtgeht

Push kann auf zwei ganz verschiedene Weisen scheitern, und die Seite benennt, welche davon
eingetreten ist — denn sie schicken dich an zwei völlig verschiedene Orte zum Nachsehen.

**Abgelaufen** heißt, dass die Registrierung ihr eigenes Ablaufdatum überschritten hat. Das wird von
einem gespeicherten Datum abgelesen und nicht erschlossen, und es stimmt zu jeder Tageszeit. Die
Erneuerung läuft einmal täglich von selbst, eine abgelaufene Registrierung ist also eine Erneuerung,
die nicht gelaufen ist — und ein Dienst für geplante Aufgaben, der schlicht stehen geblieben ist,
schreibt gar nichts ins Protokoll, sonst würde es also nirgends jemand sagen. Passiert das immer
wieder, ist der Zeitplaner das, was repariert werden muss, und nicht das Konto.

**Registriert, kommt aber nicht an** heißt das Gegenteil: Die Registrierung lebt und ist nicht
abgelaufen, und es ist Post aufgetaucht, die Push nie angekündigt hat. Das ist ein Beleg und kein
Zeitablauf — ein Postfach, das sich verändert hat, ohne dass eine Benachrichtigung das erklärt. Die
Benachrichtigungen gehen zwischen dem Anbieter und dieser Installation verloren. Bei Gmail deutet das
auf die Cloud-Pub/Sub-Strecke: Das Push-Abonnement, das dein Topic an diese Adresse weiterleitet,
fehlt womöglich oder zeigt woandershin. Dieser Teil des Weges liegt außerhalb von plMail — genau
deshalb kann er scheitern, während hier alles richtig aussieht.

Ein Postfach, das sich nie ändert, erzeugt diesen Beleg nie und meldet das nie, zu keiner Nachtstunde.

Jede Karte trägt die drei Daten, nach denen geurteilt wurde — **Registrierung läuft ab**, **Letzte
Benachrichtigung erhalten** und **Erneuerung lief zuletzt** —, sodass du einen stehen gebliebenen
Zeitplaner von einem nie funktionierenden Zustellweg unterscheiden kannst, ohne ein Protokoll zu
lesen. Jedes davon kann *nie*, *es ist nie etwas angekommen* oder *kein Lauf verzeichnet* lauten, und
bei einer Registrierung, die nie etwas zugestellt hat, ist genau das der Befund und keine Lücke.

Beide sind **Warnungen** und beide lassen die Anzeige in der Kopfleiste angehen. Push absichtlich
**aus** zu haben ist etwas ganz anderes und bleibt ein leiser Hinweis — siehe die Fallstricke unten.

Die Reparatur ist für beide dieselbe: **Push neu registrieren**, und das darf man mehr als einmal
drücken.

## Kalender, die dauerhaft scheitern

Ein Kalender, dessen Synchronisierung jedes Mal dieselbe Antwort gibt, **wartet jetzt ab**, statt es
ewig weiter zu versuchen: eine Viertelstunde, dann jeweils das Doppelte, gedeckelt auf einen Tag.
Zwei Folgen sind es wert, gewusst zu werden:

- Ein kaputter Kalender überschwemmt die Protokolle nicht mehr und blockiert nicht mehr den
  Durchlauf, hinter dem andere Kalender warten. Vorher wurde ein Kalender, der nicht synchronisieren
  konnte, bei jedem Durchlauf erneut versucht, solange er kaputt blieb.
- Nichts wird stummgeschaltet. Das erste Scheitern meldet sich immer, ein Scheitern, das sich
  *ändert*, meldet sich immer, und weil die Wartezeit auf einen Tag gedeckelt ist, wird ein Zustand,
  der von selbst heilt, binnen eines Tages wieder aufgegriffen.

**Jetzt synchronisieren** löscht die Wartezeit und bittet den Kalender sofort um einen Lauf. Das
stellt die Arbeit nur in die Warteschlange, statt sie zu erledigen — die Karte sagt daher, die
Synchronisierung sei **gestartet**, und sagt es weiter, bis eine Antwort da ist, auch über ein
Neuladen hinweg und nicht bloß als Einblendung, die man verpassen kann. Kommt die Antwort, sagt die
Karte, ob es geklappt hat, und ein erneutes Scheitern sagt das deutlich, statt wie ein nie gelaufener
Versuch auszusehen.

## Wo du weiterliest

- [Konten und Aliase](accounts.md) — Postfächer verbinden und trennen, sofortige Zustellung.
- [Verbundene Kalender](calendar-sync.md) — was eine Kalendersynchronisierung tut und mit welchen
  Anbietern.
- [Dateien und Integrationen](integrations.md) — die Verbindungen, die ablaufen können.
- [Fehlersuche](../install/troubleshooting.md) — die Seite des Betreibers: die Warteschlange, die
  Protokolle und die Prüfungen, die ein Browser nicht sieht.
- [Administration](admin.md) — Warteschlangen, Worker und die Überwachung, die eine Administration
  bekommt.

## Fallstricke

**Das erneute Verbinden lehnt eine andere Adresse ab, und das ist die Funktion.** Meldet dich der
Anbieter als das falsche Konto an — eine zweite Google-Identität, die noch eingeloggt ist, eine
private Adresse dort, wo die dienstliche gemeint war —, bricht die Reparatur ab und verändert nichts.
Melde dich zuerst beim Anbieter von dieser Identität ab und versuche es dann erneut. Ein „trotzdem
verwenden“ gibt es nicht.

**Auch über Anbieter hinweg wird ein erneutes Verbinden abgelehnt.** Dieselbe Adresse bei einem
anderen Anbieter ist ein anderes Postfach, und geprüft wird beides.

**„Jetzt synchronisieren“ reiht die Synchronisierung ein; es führt sie nicht aus.** Die Seite kommt
mit der Aussage zurück, die Synchronisierung sei gestartet, denn das ist sie, und das Ergebnis landet
Augenblicke später. Sie in der Zwischenzeit noch einmal zu drücken bringt nichts — die Karte sagt
auch ungefragt, was passiert ist.

**Eine ausgeschaltete Sofortzustellung ist kein Fehler und lässt die Anzeige nicht aufleuchten.** Eine
selbst gehostete Installation ohne öffentlich erreichbare HTTPS-Adresse kann sich für Push überhaupt
nicht registrieren. Post kommt über den Durchlauf alle fünfzehn Minuten, und genau darum ist das ein
Hinweis und keine Warnung. Ein *kaputter* Push — abgelaufen oder registriert und nicht zustellend —
ist eine Warnung und lässt sie sehr wohl aufleuchten, denn du hast um Sofortzustellung gebeten und
bekommst sie nicht.

**Ein kaputter Push hält deine Post nicht auf.** Beide Push-Fehler fallen auf den geplanten Durchlauf
zurück, Post kommt also ein paar Minuten später an als sie sollte und nicht etwa gar nicht. Es eilt
nicht, und es geht nichts verloren, während du herausfindest, welche Strecke kaputt ist.

**„Kommt nicht an“ wird nur aus einem Beleg gemeldet.** plMail wartet darauf, dass sich ein Postfach
ändert, ohne dass eine Benachrichtigung das ankündigt, und nicht darauf, dass eine Stille lange genug
dauert. Ein wirklich stilles Postfach meldet also nichts, wie lange es auch still bleibt — das
Ausbleiben von Warnungen ist kein Beweis, dass Push funktioniert, sondern nur dafür, dass noch nichts
das Gegenteil bewiesen hat.

**Zustand der Konten verlässt die Einstellungsliste, wenn nichts kaputt ist.** An einem guten Tag
danach zu suchen und ihn nicht zu finden, ist das gewollte Verhalten und keine fehlende Seite. Die
Adresse und die Anzeige in der Kopfleiste führen beide weiterhin dorthin.

**Liegen gebliebene Aufgaben zu verwerfen lässt sich nicht rückgängig machen.** Was sie nicht
erledigt haben, bleibt unerledigt, und nichts wird erneut versucht. **Wieder einreihen** ist der
sichere Weg — was erneut scheitert, landet einfach wieder hier.

**Liegen gebliebene Aufgaben sind meist ein Symptom.** Hunderte davon bedeuten fast immer eine tote
Anmeldung weiter oben auf der Seite. Behebe zuerst die, reihe die Aufgaben danach wieder ein — sonst
scheitern sie aus demselben Grund und kommen zurück.

**Die Seite meldet, was zuletzt beobachtet wurde, nicht was in dieser Sekunde gilt.** Sie liest
gespeicherten Zustand und fragt nie nach, ein eben anderswo repariertes Konto bleibt also auf der
Liste, bis es das nächste Mal versucht wird.
