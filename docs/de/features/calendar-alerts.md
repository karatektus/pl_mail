<!-- translated-from: features/calendar-alerts.md sha1:704b01f6cce85b0aebc4e2983d3ba565aa2a6057 -->

# Erinnerungen

Eine Erinnerung geht vor einem Termin los und sagt dir Bescheid — als Benachrichtigung auf den
Geräten, die du dafür freigegeben hast, oder als E-Mail an dich selbst. plMail nennt sie im Editor
*Erinnerungen* und im Protokoll und auf der Konsole *alerts*; es ist dasselbe.

Erinnerungen werden am Termin selbst gespeichert, in dem Vokabular, das jeder Kalenderserver
versteht, sodass eine in plMail gesetzte in Outlook, Google Kalender oder einem CalDAV-Client
ankommt — und eine dort gesetzte hier. Es gibt kein plMail-eigenes Erinnerungsfeld, das eine
Abbildung erst lernen müsste.

## Eine setzen

Erinnerungen stehen im Termin-Editor unter **Erinnerungen**. Sechs Zeitabstände sind je ein Klick, in
dieser Reihenfolge:

- **Zum Beginn des Termins**
- **5 Minuten vorher**
- **10 Minuten vorher**
- **30 Minuten vorher**
- **1 Stunde vorher**
- **1 Tag vorher**

Hak an, so viele du willst; ein Termin darf insgesamt zehn tragen.

Darunter steht **Etwas anderes, in Minuten**, ein schlichtes Zahlenfeld, und daneben **Wie die
zusätzliche Erinnerung ankommt** — **Benachrichtigung** oder **E-Mail**. Das freie Feld *fügt* immer
nur eine Erinnerung hinzu; es ändert nichts an den sechs darüber, die stets Benachrichtigungen sind.

Das freie Feld nimmt alles von einer Minute bis zu **44.640 Minuten — einunddreißig Tagen**. Diese
Obergrenze ist keine Höflichkeit: Sie ist genau so weit voraus, wie eine Erinnerung eingelöst werden
kann, eine größere Zahl würde also gespeichert, würde hin und zurück übertragen und ginge nie los.
Null, negative Werte und alles jenseits der Obergrenze werden übergangen statt abgelehnt, und ein
leeres Feld ist der Normalfall.

### Erinnerungen, die von woanders stammen

Die Liste zeigt außerdem jede Erinnerung am Termin, die keine der sechs ist — ein Alarm, der in einem
anderen Client gesetzt, aus einer `.ics` importiert oder aus einem verbundenen Kalender gespiegelt
wurde. Diese erscheinen in Worten beschrieben statt als Auswahl, die du hättest treffen können:
*2 Stunden vor dem Beginn*, *15 Minuten nach dem Ende*, *Am 2026-08-12 09:00* für eine, die an einem
festen Zeitpunkt hängt. Eine Erinnerung per E-Mail ist mit *· E-Mail* gekennzeichnet;
Benachrichtigungen bleiben ungekennzeichnet, denn „· Benachrichtigung“ auf fünf von sechs Zeilen zu
schreiben, machte die eine, die E-Mail sagt, schwerer zu finden statt leichter. Ein Auslöser, den
dieser Stand gar nicht lesen kann, sagt *Erinnerung anderswo gesetzt*, sodass er sich immer noch
abhaken lässt, statt als leere Zeile dazustehen.

Du kannst bei jeder von ihnen den Haken wegnehmen. Anlegen kannst du hier keine dieser Formen — der
Editor hat bewusst keine Möglichkeit, einen absoluten Auslöser auszudrücken —, und das ist der
Handel, der eine Google-Erinnerung, einen CalDAV-Alarm und plMails eigene sechs in einer Liste
nebeneinander sitzen lässt.

### Erinnerungen gehören zum ganzen Termin

Bei einem Serientermin sagt der Editor das an genau der Stelle, an der du sonst annehmen würdest,
dass die Auswahl des Geltungsbereichs greift:

> Erinnerungen gehören zum ganzen Termin, die Auswahl darunter gilt also nicht für sie.

Ein Speichern mit **Diesen Termin** schreibt Zeiten und Titel dieser Termininstanz und lässt die
Erinnerungen an der Serie genau so, wie sie waren. Es gibt keine Möglichkeit, einer einzelnen
Termininstanz eine eigene Erinnerung zu geben.

Die andere Hälfte davon ist nützlich: Ein Serientermin mit einer Erinnerung erzeugt automatisch
**eine Erinnerung je Termininstanz**. An eine Instanz, die du auf Donnerstag gezogen hast, wirst du
am Donnerstag erinnert, und an eine abgesagte gar nicht — die Erinnerung liest dieselben
Instanzzeilen, die auch die Kalenderansichten lesen, und erbt so jede Verschiebung und jede Absage
umsonst.

## Wie eine Erinnerung zugestellt wird

Jede Minute macht plMail einen Durchlauf über die fällig gewordenen Erinnerungen und sendet sie. Eine
Minute ist die Einheit, in der Menschen Erinnerungen setzen, und sie ist die Schranke dafür, wie spät
eine kommen kann — eine Fünf-Minuten-Erinnerung, die im Fünf-Minuten-Takt zugestellt würde, könnte
irgendwo zwischen pünktlich und nach Beginn der Besprechung eintreffen.

Zwei Kanäle, gewählt danach, was die Erinnerung von sich sagt:

**Benachrichtigung.** Wird als Web-Push-Benachrichtigung an jedes Gerät zugestellt, das ein Abonnement
für dein Konto bestätigt hat. Es ist derselbe Push-Weg, den plMail schon für neue Mail nutzt,
Benachrichtigungen einmal einzuschalten schaltet also beides ein. Die Nutzlast trägt den Titel und
die Zeit — eine Benachrichtigung, die „irgendetwas passiert“ sagt und dich zwingt, die App zu öffnen,
um zu erfahren was, ist keine Erinnerung — und sie ist Ende zu Ende unter dem Schlüssel des Geräts
verschlüsselt, der Push-Dienst sieht also Geheimtext. Ein Druck darauf bringt dich zum Tag der
Termininstanz.

**E-Mail.** Geht an die Adresse deines ersten E-Mail-Kontos, von derselben Adresse, über den eigenen
Sendeweg dieses Kontos — ein Gmail-Konto über die Gmail-API, ein IMAP-Konto über sein eigenes SMTP.
Von und An sind mit Absicht dieselbe Adresse: Das hier ist eine Erinnerung und kein Schriftwechsel,
sie muss in dem Postfach landen, das du tatsächlich liest, und sie von irgendwo anders zu senden
brächte sie auf jedem Host, der die Übereinstimmung prüft, in den Spam. Es wird keine Kopie in
Gesendet abgelegt.

**Zwischen beiden gibt es keinen Ersatzweg.** Eine Erinnerung per Benachrichtigung wird auf einer
Installation ohne Push nicht stillschweigend zu einer E-Mail. Du hast um eine Benachrichtigung
gebeten, und ein Dienst, der dir stattdessen schreibt, ist einer, dem du deine Adresse nicht mehr
anvertraust.

### Was eine frische Installation zuerst braucht

Auf einer brandneuen Installation kann **keiner der beiden Kanäle irgendetwas zustellen**, und nichts
am Editor sagt dir das — eine Erinnerung, die an einem Termin angehakt ist und nirgendwohin kann,
sieht genau aus wie eine, die funktioniert.

Für Benachrichtigungen ist die Serverseite bereits erledigt: Ein VAPID-Schlüsselpaar wird beim ersten
Start zusammen mit den übrigen Geheimnissen erzeugt. Was fehlt, ist ein angemeldetes Gerät. Geh auf
**Einstellungen → Benachrichtigungen** und schalte **Benachrichtigungen aktivieren** ein, auf jedem
Gerät, das benachrichtigt werden soll. Die Seite meldet, wie der Zustand dieses Geräts tatsächlich
ist — an, aus, im Browser blockiert, auf Bestätigung wartend oder nicht unterstützt. Auf iPhone und
iPad funktioniert es erst, wenn plMail zum Home-Bildschirm hinzugefügt wurde, was die Seite ebenfalls
sagt.

Hat der Server wirklich keine Schlüssel — eine Installation, auf der sie von Hand geleert wurden —,
sagt die Seite *Push ist auf diesem Server nicht konfiguriert.*, und

```bash
docker compose exec php php bin/console app:push:generate-vapid-keys
```

gibt die Zeilen aus, die zu setzen sind.

Für Erinnerungen per E-Mail brauchst du mindestens ein E-Mail-Konto, das senden kann. Ein Benutzer
ganz ohne E-Mail-Konto ist ein völlig gewöhnlicher Zustand — du darfst dein letztes Konto löschen und
einen Kalender behalten — und wird nicht als Fehler behandelt.

So oder so wird eine Erinnerung, die nirgendwohin kann, als losgegangen vermerkt, und es wird eine
Warnung protokolliert. Sie wird nicht erneut versucht; siehe unten.

### Von Hand ausführen

Der Durchlauf ist `app:calendar:alerts`, jede Minute eingeplant. Er lässt sich gefahrlos von Hand
starten und gefahrlos zweimal starten — idempotent macht ihn der Vermerk, den er schreibt, und nicht
der Zeitplan.

```bash
docker compose exec php php bin/console app:calendar:alerts --dry-run
```

listet auf, was gerade fällig ist, ohne etwas zu senden und ohne etwas zu vermerken. Ohne `--dry-run`
stellt er zu und räumt außerdem die Zustellvermerke auf, die älter als eine Woche sind.

## Fallstricke

**Eine Erinnerung, die nicht gesendet werden kann, ist verloren und wird nicht wiederholt.** Das ist
Absicht und folgt daraus, dass sie genau einmal losgeht. Der Vermerk „diese Erinnerung ist
losgegangen“ wird geschrieben, *bevor* irgendetwas gesendet wird, in einem einzigen Insert, der
entweder gelingt oder offenlegt, dass ein anderer Durchlauf sie schon für sich beansprucht hat. Beide
Alternativen verlieren: erst senden und danach vermerken heißt, dass ein dazwischen abgeschossener
Container eine Minute später erneut sendet, und erst prüfen heißt, dass zwei überlappende Durchläufe
beide beschließen zu senden. Also kostet eine schlechte halbe Minute beim Push-Dienst diese
Erinnerung. „Deine Besprechung beginnt in zehn Minuten“ ist fünfzehn Minuten später keine wahre
Aussage mehr, und deshalb ist eine verlorene Erinnerung der bessere Fehlschlag.

**Erinnerungen einzuschalten stellt kein Jahr Vergangenheit zu.** Eine Erinnerung, deren Zeitpunkt
mehr als **eine Stunde** her ist, ist nicht fällig und wird es auch nie, was die Vermerke auch sagen.
Dieser Boden ist es, der einen ersten Durchlauf nach einem Upgrade oder nach dem Import von zwölf
Monaten Flügen davon abhält, jede Erinnerung aus deinem Archiv auf einmal zuzustellen. Er ist zugleich
die Grenze dafür, wie viel Ausfallzeit der Durchlauf nachholen kann: Eine Stunde Luft deckt einen
Neustart, ein Deployment oder eine lange Migration ab und deckt kein Wochenende ab.

**Eine Erinnerung, die mehr als einunddreißig Tage vor einem Termin liegt, geht nie los.** Sie wird
gespeichert, sie wird zu deinen anderen Clients hin und zurück übertragen, und der Durchlauf schaut
nicht so weit voraus — die Alternative wäre eine Abfrage ohne obere Schranke, die jede Minute die
ganze Tabelle durchsucht. Das freie Feld im Editor setzt dieselbe Obergrenze durch, das hier trifft
also nur Erinnerungen, die von anderswo kamen.

**Eine Erinnerung, die vom *Ende* eines Termins aus gemessen wird, der länger als einen Tag dauert,
geht ebenfalls nie los.** Der Durchlauf greift für Auslöser relativ zum Ende einen Tag hinter den
Beginn einer Termininstanz zurück.

**Eine Erinnerung auf einen festen Zeitpunkt wird bei einem Serientermin übergangen.** Ein Zeitpunkt
kann nicht jede von hundert Termininstanzen meinen, und eine davon auszuwählen hieße, eine Antwort zu
erfinden. Bei einem einmaligen Termin wird sie ganz normal eingelöst.

**Termininstanzen werden nur für ein begrenztes Fenster ausgeschrieben** — grob ein Jahr zurück und
zwei Jahre voraus ab dem letzten Speichern des Termins —, und eine Erinnerung braucht eine
Termininstanz, an der sie hängen kann. Eine Erinnerung an etwas weit genug in der Zukunft hat noch
nichts, wogegen sie losgehen könnte.

**Alle Erinnerungen von einem Termin zu entfernen, löscht die Erinnerungen bei Google nicht.** Google
kann „dieser Termin hat keine Erinnerungen“ nicht so von „dieser Termin nutzt die Voreinstellungen
des Kalenders“ unterscheiden, dass es sich hin und zurück übertragen ließe, also schreibt plMail
Erinnerungen nur dann, wenn es mindestens eine zu schreiben gibt. Outlook hat diese Mehrdeutigkeit
nicht und löscht sie sehr wohl.

**Ein zusammengefasster Termin verliert die Erinnerungen, die die angeklickte Kopie nicht hat.** Wenn
eine Besprechung in zwei Kalendern steht und als ein einziger Eintrag gezeichnet wird, schreibt das
Speichern die Erinnerungen, die du siehst, in jede angehakte Kopie — ein Alarm, den es nur in der
Kopie gab, die du nicht angesehen hast, geht damit verloren. Der andere Fehler wäre schlimmer: Eine
abgehakte Erinnerung bliebe stillschweigend in Kopien stehen, die du nicht sehen kannst.

**Benachrichtigungen einzuschalten gilt pro Gerät, nicht pro Konto.** Jeder Browser, jedes Telefon und
jeder Rechner muss einzeln unter **Einstellungen → Benachrichtigungen** eingeschaltet werden, und ein
Browser, der die Berechtigung später entzieht, empfängt nichts mehr, ohne dass es hier stünde.

---

**Verwandt:** [Kalender](calendar.md) · [Verbundene Kalender](calendar-sync.md) ·
[Einladungen und Termine aus E-Mails](calendar-invitations.md) · [Andere Clients](clients.md)

**Wie es funktioniert:** [Das Kalendermodell](../internals/calendar-model.md) — wo eine Erinnerung an
einem Termin gespeichert wird und wie Termininstanzen ausgeschrieben werden.
