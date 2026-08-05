<!-- translated-from: providers/google.md sha1:67eda74928bbad8506ee3daf2fe3f25a49fb4573 -->

# Google

Gmail und Google Kalender erreichen plMail beide über eine einzige OAuth-Anwendung, die du in der
Google Cloud Console registrierst. Google nimmt für Gmail keine gewöhnlichen Passwörter mehr an,
diese Registrierung ist also nicht optional, wenn du ein Gmail-Konto in plMail führen willst — und
weil es *dein* Cloud-Projekt ist und keines, das diese Software betreibt, ist alles auf dieser Seite
etwas, das du einmal erledigst, als Administrator deiner eigenen Installation.

Drei Dinge kommen aus diesem Projekt, und es lohnt sich, sie vor dem Anfangen auseinanderzuhalten,
denn regelmäßig wird das erste eingerichtet und dann gerätselt, warum das dritte nicht funktioniert:

| Was | Bringt dir | Nötig? |
|---|---|---|
| Ein OAuth-Client | Anmeldung, Lesen und Senden von Mail, und Kalender | Ja, für jedes Gmail-Konto |
| Ein Cloud-Pub/Sub-Topic samt Abonnement | Gmail trifft in dem Moment ein, in dem sie gesendet wird | Optional; ohne das wird Gmail alle fünfzehn Minuten abgerufen |
| Eine verifizierte Domain | Kalenderänderungen treffen ein, sobald sie geschehen | Optional; ohne das werden Kalender alle fünfzehn Minuten gelesen |

Alles unter **Einstellungen**, was dadurch möglich wird, beschreiben
[Konten und Aliase](../features/accounts.md) und [Verbundene Kalender](../features/calendar-sync.md).

## Das Cloud-Projekt und die APIs darauf

1. Öffne [console.cloud.google.com](https://console.cloud.google.com) und lege ein Projekt an, oder
   nimm ein vorhandenes. Das Projekt ist die Einheit, zu der alles Weitere hier gehört: der
   OAuth-Client, der Zustimmungsbildschirm, das Pub/Sub-Topic und die Domainbestätigung liegen alle
   darin, und zwei Projekte für zwei Hälften dieser Einrichtung zu verwenden ist der häufigste Weg zu
   einem Zustimmungsbildschirm, der einen Scope gewährt, für den die API nie aktiviert wurde.
2. Aktiviere unter **APIs & Services → Library** (APIs und Dienste → Bibliothek) die **Gmail API**.
   Ohne sie wird jeder Aufruf, den plMail nach einer erfolgreichen Anmeldung macht, abgelehnt — was
   sich als Konto zeigt, das sich verbindet und dann nie synchronisiert.
3. Aktiviere im selben Projekt die **Google Calendar API**, wenn du Kalender willst. Das ist der
   Schritt, den der Admin-Bildschirm meint, wenn er sagt, auf der plMail-Seite sei nichts
   einzurichten — der Kalenderzugriff wird vollständig in dieser Konsole erworben.

Notier dir gleich deine **project ID** (Projekt-ID). Sie ist kleingeschrieben und kann von dem
Anzeigenamen abweichen, den du eingetippt hast; das Pub/Sub-Topic weiter unten wird mit der ID
benannt, nicht mit dem Namen.

## Der OAuth-Zustimmungsbildschirm

Wähle unter **OAuth consent screen** (OAuth-Zustimmungsbildschirm) die Option **Internal** (Intern),
wenn du eine Google-Workspace-Organisation hast und alle, die sich anmelden, dazu gehören, sonst
**External** (Extern). Trag Anwendungsnamen und Support-E-Mail-Adresse ein. Das ist der Bildschirm,
den deine Nutzerinnen und Nutzer lesen, bevor sie plMail Zugriff auf ihr Postfach geben, der Name,
den du eintippst, ist also der Name, den sie sehen; auch auf einer Ein-Personen-Installation lohnt es
sich, ihn wiedererkennbar zu machen, denn er erscheint danach ebenso in den
Google-Sicherheitseinstellungen des Kontos.

Füg die Scopes aus dem nächsten Abschnitt hinzu. Ein **External**-Projekt startet im Status
**Testing** (Test), in dem nur die Konten überhaupt zustimmen dürfen, die du als Testnutzer einträgst
— was für eine selbst gehostete Installation mit einer Handvoll Personen der normale und richtige
Zustand ist. Trag dich selbst als Testnutzer ein, und alle anderen, die ein Postfach verbinden
werden.

## Der OAuth-Client und seine Redirect-URI

Leg unter **Credentials** (Anmeldedaten) eine **OAuth client ID** (OAuth-Client-ID) vom Typ
**Web application** (Webanwendung) an und trag unter *Authorised redirect URIs* Folgendes ein:

```
https://your-domain/oauth/google/callback
```

Dieser Pfad ist eine Route in plMail (`/oauth/{provider}/callback` mit `google` als Provider), und
Google vergleicht ihn Zeichen für Zeichen mit der Adresse, von der der Browser geschickt wurde — ein
abschließender Schrägstrich, `http` statt `https` oder der falsche Hostname scheitern allesamt mit
`redirect_uri_mismatch`, bevor plMail die Anfrage überhaupt sieht. Du musst ihn nicht abschreiben:
**Admin → Integrationen → Mail-Anmeldung → Gmail-Anmeldung** zeigt die exakte URI für deine
Installation mit einem Kopierknopf daneben, aufgebaut aus deiner öffentlichen Adresse, und die
Einrichtung zeigt dasselbe. Die Warnung dieses Bildschirms sei wiederholt — die URI lässt sich später
nicht mehr ändern, ohne jede bereits dagegen autorisierte Verbindung zu zerbrechen.

Kopier Client-ID und Client-Secret. Beide können an zwei Stellen liegen:

- **Admin → Integrationen → Mail-Anmeldung → Gmail-Anmeldung**, was sie in der Datenbank speichert.
- `GOOGLE_OAUTH_CLIENT_ID` und `GOOGLE_OAUTH_CLIENT_SECRET` in der Umgebung, was eine ältere
  Installation ohnehin schon tut. Siehe die
  [Konfigurationsreferenz](../install/configuration.md).

Ein gespeicherter Wert gewinnt gegen die Umgebung, und nur dann, wenn er wirklich gesetzt ist — eine
halb ausgefüllte Zeile kann keine funktionierende Umgebungsvariable verdecken. Das Formular
verweigert eine Client-ID ohne zugehöriges Secret, weil diese Kombination konfiguriert aussieht und
am Zustimmungsbildschirm scheitert.

## Die Scopes, und wofür jeder da ist

plMail fragt bei der Zustimmung diese vier ab, für jedes Gmail-Konto:

| Scope | Wofür er da ist |
|---|---|
| `https://mail.google.com/` | Das Postfach selbst: Nachrichten lesen, senden, Labels vergeben und entfernen, verschieben und löschen. Das ist voller Postfachzugriff, und genau das ist ein Mail-Client. |
| `https://www.googleapis.com/auth/calendar` | Kalender auf demselben Konto, lesend **und** schreibend. |
| `openid` | Identifiziert das Konto, zu dem das Token gehört. |
| `email` | Liefert die Adresse, nach der plMail das Konto benennt. |

Der Kalender-Scope ist mit Absicht lesend und schreibend statt nur lesend. Einen Kalender zu
abonnieren ist nur die halbe Funktion: Ein Termin, den du in plMail bearbeitest, wird zurück
geschrieben, und das heißt Anlegen, Ändern und Löschen auf Googles Seite — nichts davon ist mit einer
reinen Leseberechtigung möglich.

Die Autorisierungsanfrage trägt außerdem `prompt=consent` und einen Offline-Zugriffstyp. Das sind
keine Scopes; sie sind das, was Google dazu bringt, ein **Refresh-Token** zurückzugeben. Ohne eines
funktioniert die Verbindung bis zum Ablauf des ersten Access-Tokens und hört dann auf, und die
einzige Reparatur ist, das Konto neu zu verbinden.

### Kalender hängen an derselben Anmeldung wie Mail

Es gibt keine zweite Verbindung, keine zweite OAuth-Anwendung und keinen zweiten
Zustimmungsbildschirm für Kalender. Der Kalender-Scope wird zusammen mit den Mail-Scopes auf einer
einzigen Berechtigung angefragt, du setzt als Administrator also ein zusätzliches Häkchen in dieser
Konsole und hast sonst nichts einzurichten — genau das sagt dir der Admin-Bildschirm: *„Hier ist
nichts einzurichten. Aktiviere die Google Calendar API für dieses Projekt und ergänze den Scope
`.../auth/calendar` im Zustimmungsbildschirm — die Kalender kommen dann mit derselben Anmeldung wie
die E-Mails. Ohne das funktioniert E-Mail weiter, die Kalender erscheinen einfach nicht."*

Der Preis dafür, beides auf eine Berechtigung zu legen, ist, dass der Zustimmungsbildschirm vorab
mehr verlangt — und Googles Zustimmungsbildschirm lässt zu, dass eine einzelne Berechtigung
abgewählt wird. Ein Token kann daher mit Mailzugriff und ohne Kalenderzugriff zurückkommen. plMail
versucht gar nicht erst, das bei der Erteilung zu erkennen; es merkt es, wenn ein Kalenderaufruf
abgelehnt wird, und sagt es in den Kalendereinstellungen — *„Auf Googles Zustimmungsseite lässt sich
der Kalenderzugriff abwählen, während E-Mail erlaubt wird. Verbinde das Konto neu und lass das
Kalenderhäkchen gesetzt."*

### Was die Überprüfung „eingeschränkter Scope“ hier bedeutet

`https://mail.google.com/` gewährt Zugriff auf das gesamte Postfach, und Google behandelt Scopes
dieser Reichweite als eingeschränkt: Eine Anwendung, die einen davon anfragt und **veröffentlicht**
ist, durchläuft Googles Überprüfung, und dasselbe gilt für den vollständigen `drive`-Scope, den
plMails Google-Drive-Integration verwendet. Die Einrichtungshinweise der App zu Drive sagen es klar —
Google verlangt eine App-Überprüfung, bevor Personen außerhalb deiner Testliste zustimmen können.

Für eine selbst gehostete Installation heißt das meistens, dass du gar nicht veröffentlichst. Lass
das Projekt in **Testing**, trag die Handvoll Adressen als Testnutzer ein, die sich verbinden werden,
und die Zustimmung funktioniert sofort, mit einer Zwischenseite zur „nicht überprüften App", durch
die du hindurchklickst. Der Handel dabei: Google lässt Refresh-Tokens, die eine App im Status Testing
ausgestellt hat, nach etwa einer Woche verfallen, ein so verbundenes Konto muss also regelmäßig neu
verbunden werden; das Veröffentlichen samt Überprüfung ist das, was diesen Punkt beseitigt, und lohnt
sich nur für eine Installation mit echten Nutzerinnen und Nutzern, die nicht du selbst bist.

## Sofortige Gmail-Zustellung, über Cloud Pub/Sub

Gmail ruft keinen Webhook direkt auf. Es veröffentlicht in ein **Cloud Pub/Sub**-Topic, und Pub/Sub
schiebt es von dort zu plMail — die sofortige Zustellung braucht also zwei Cloud-Ressourcen und ein
gemeinsames Geheimnis. Das ist eine einmalige Einrichtung für die gesamte Installation, nicht pro
Konto.

Leg das Topic an:

```bash
gcloud pubsub topics create gmail-push --project=YOUR_PROJECT_ID
```

Erlaube Googles Maildienst, darin zu veröffentlichen. Diese Berechtigung ist das, was Gmail überhaupt
erst in die Lage versetzt, deinem Topic irgendetwas mitzuteilen:

```bash
gcloud pubsub topics add-iam-policy-binding gmail-push \
  --project=YOUR_PROJECT_ID \
  --member=serviceAccount:gmail-api-push@system.gserviceaccount.com \
  --role=roles/pubsub.publisher
```

Wähl ein eigenes Geheimnis und leg dann das Push-Abonnement an, das an plMail zustellt:

```bash
gcloud pubsub subscriptions create gmail-push-sub \
  --project=YOUR_PROJECT_ID \
  --topic=gmail-push \
  --push-endpoint="https://your-domain/gmail/push?token=YOUR_SECRET"
```

`POST /gmail/push` ist die Route, die diese Nachrichten entgegennimmt. Das Token in der Query ist das
Einzige, was den Endpunkt absichert — jede und jeder im Internet kann dorthin POSTen —, plMail
vergleicht es deshalb in konstanter Zeit und antwortet allem, was es nicht mitbringt, mit `403`. Es
scheitert nach innen: Ist kein Token konfiguriert, wird jede Benachrichtigung abgewiesen.

Teile plMail zuletzt dieselben zwei Werte mit, entweder unter
**Admin → Integrationen → Mail-Anmeldung → Gmail-Anmeldung** (Felder **Pub/Sub-Topic** und
**Push-Verifizierungstoken**) oder in der Umgebung:

```ini
GMAIL_PUBSUB_TOPIC=projects/YOUR_PROJECT_ID/topics/gmail-push
GMAIL_PUBSUB_VERIFICATION_TOKEN=YOUR_SECRET
```

Die gespeicherten Werte gewinnen und fallen auf die Umgebung zurück, genau wie die
OAuth-Zugangsdaten.

Schalte danach für das Konto unter **Einstellungen → E-Mail-Konten** die **Sofortige Zustellung** ein.
Sie gilt pro Konto und muss aktiv gewählt werden: Ohne konfiguriertes Topic bleibt das Konto beim
Viertelstundentakt und sagt das auch, statt zu scheitern.

Ein Gmail-Watch hält höchstens sieben Tage. plMail erneuert jeden Watch, der innerhalb des nächsten
Tages abläuft, aus einem nächtlichen Durchlauf heraus, es gibt also nichts von Hand zu pflegen.
**Admin → Gmail-Webhooks** ist der Ort zum Nachsehen, wenn Mail nicht sofort ankommt: Dort stehen das
Topic, der exakte Endpunkt, an den das Abonnement POSTen muss — Token inklusive —, der Ablauf des
Watch, der letzte empfangene Push und der Grund, warum eine Benachrichtigung abgewiesen wurde.

## Kalender-Push: Watch-Kanäle, nicht Pub/Sub

Kalender-Push ist ein völlig anderer Mechanismus als der obige, und keine der
`GMAIL_PUBSUB_*`-Konfiguration gilt dafür. plMail öffnet einen **Watch-Kanal** auf die Termine des
Kalenders, und das ist ein schlichter Webhook: Google POSTet an eine Adresse, die dir gehört, wann
immer sich in diesem Kalender etwas ändert. Eine Installation ganz ohne Pub/Sub kann Kalender-Push
haben, und eine Installation mit einwandfrei funktionierendem Pub/Sub kann ohne Kalender-Push
dastehen.

| | Gmail-Push | Google-Kalender-Push |
|---|---|---|
| Mechanismus | `users.watch`, veröffentlicht in ein Pub/Sub-Topic | `events.watch`-Kanal, ein schlichter Webhook |
| Endpunkt | `POST /gmail/push?token=…` | `POST /webhook/google/calendar` |
| Echtheitsnachweis | das Token in der URL | das Kanaltoken in `X-Goog-Channel-Token` |
| Cloud-Ressourcen | ein Topic, eine Publisher-Berechtigung, ein Abonnement | keine |
| Zusätzliche Voraussetzung | — | die Callback-Domain muss verifiziert sein |
| Laufzeit | sieben Tage | eine Woche, je nachdem, was Google gewährt |

Zweierlei muss zutreffen, bevor ein Kanal geöffnet werden kann:

**Ein öffentlich erreichbarer HTTPS-Callback.** Die Adresse wird aus deiner konfigurierten
öffentlichen URL gebaut, nie aus der eingehenden Anfrage, denn der Prozess, der Kanäle registriert,
ist ein geplantes Kommando ohne Anfrage, aus der sich ein Hostname ableiten ließe. Eine Adresse, die
nicht `https://` ist oder die auf `localhost` auflöst, wird lokal abgelehnt, bevor auch nur ein
einziger API-Aufruf passiert. Siehe [Hinter einem Reverse Proxy](../install/reverse-proxy.md).

**Domainbestätigung in dem Cloud-Projekt, dem der OAuth-Client gehört.** Verifiziere die Domain in
der Search Console und trag sie dann in der Cloud Console unter **Domain verification**
(Domainbestätigung) ein. Bis dahin wird jedes `events.watch` abgelehnt. Das ist das eine an dieser
Funktion, das du von innerhalb plMails nicht herausfinden kannst, weshalb der Admin-Bildschirm es
ausspricht: *„Google öffnet einen Watch-Kanal nur, wenn die Callback-Domain in dem Cloud-Projekt
verifiziert ist, dem der OAuth-Client gehört … Bei Microsoft entfällt dieser Schritt."*

**Keines von beiden macht etwas kaputt.** Ein Kalender, der keinen Kanal registrieren kann, bleibt
beim Viertelstunden-Durchlauf, und das ist ein funktionierender Kalender, der höchstens fünfzehn
Minuten hinterherhinkt. Der Admin-Bildschirm sagt dasselbe: *„Ohne öffentlich erreichbare
HTTPS-Adresse bleiben verbundene Kalender einfach beim Viertelstunden-Abruf."* Fehlgeschlagene
Registrierungen werden als Warnungen protokolliert, nicht als Fehler, und der geplante Durchlauf
`app:calendar:push` versucht es stündlich erneut — eine Installation, die ihre Adresse repariert oder
ihre Domainbestätigung abschließt, beginnt also innerhalb einer Stunde zu pushen, ohne dass jemand
irgendwo klickt.

Du kannst auch sofort darum bitten:

```bash
docker compose exec php php bin/console app:calendar:push
```

### Kalender, die Google nicht beobachten lässt

Manche Kalender, die Google ausliefert, sind erzeugt statt gespeichert — die Feiertage eines Landes,
die Geburtstage aus den Kontakten, Kalenderwochen — und `events.watch` weist jeden davon mit
`pushNotSupportedForRequestedResource` ab. Das ist eine dauerhafte Eigenschaft des Kalenders, plMail
vermerkt es deshalb am Kalender, protokolliert es einmal auf Info-Stufe und fragt nicht mehr nach.
Der Kalender wird von da an abgerufen und liest sich in der Oberfläche als abgerufen, weil er genau
das ist.

## Fallstricke

**Die project ID ist nicht der Projektname.** Sie ist kleingeschrieben, kann eine Zahl angehängt
haben und gehört in `GMAIL_PUBSUB_TOPIC`. Ein Topic, das nach dem Anzeigenamen benannt ist, löst auf
nichts auf, und die Watch-Registrierung scheitert mit einer Meldung über ein Topic, das es nicht
gibt.

**Die Redirect-URI muss exakt übereinstimmen und lässt sich danach nicht mehr ändern.** Kopier sie
aus **Admin → Integrationen**, statt sie zu tippen. Eine spätere Änderung zerbricht jede bereits
dagegen autorisierte Verbindung, und das heißt: alle verbinden ihr Konto neu.

**Ein Zustimmungsbildschirm ohne `prompt=consent` liefert das Refresh-Token nur ein einziges Mal.**
plMail sendet es immer mit, dieser Fallstrick beißt also andersherum: Wenn du beim Debuggen deine
eigene Autorisierungs-URL baust und es weglässt, verbindet sich das Konto, funktioniert eine Stunde
und kann danach nicht mehr erneuern.

**Der Kalenderzugriff kann auf einem Konto fehlen, dessen Mail einwandfrei läuft**, weil Googles
Zustimmungsbildschirm das Abwählen erlaubt. Das Symptom ist ein Konto, das unter *Woher Kalender
kommen* auftaucht, ohne Kalender daran. Verbinde es neu und lass das Häkchen gesetzt.

**Die Calendar API zu aktivieren ist nicht dasselbe wie den Kalender-Scope hinzuzufügen**, und das
eine ohne das andere scheitert auf zwei verschiedene Arten: der Scope ohne die API ist ein 403 bei
jedem Kalenderaufruf, und die API ohne den Scope ist ein Token, dem der Kalenderzugriff nie erteilt
wurde.

**Die Domainbestätigung gilt pro Cloud-Projekt, nicht pro Google-Konto.** Die Domain in der Search
Console zu verifizieren ist nur die erste Hälfte; sie muss zusätzlich unter **Domain verification**
in demselben Cloud-Projekt eingetragen werden, dem der OAuth-Client gehört, sonst wird `events.watch`
weiter abgelehnt, obwohl die Domain scheinbar verifiziert ist.

**Die erste Benachrichtigung auf einem neuen Kanal ist ein Handschlag, keine Änderung.** Google
sendet `X-Goog-Resource-State: sync` in dem Moment, in dem ein Kanal aufgeht, und das heißt nur „der
Kanal ist offen". plMail ignoriert das absichtlich — darauf zu reagieren würde für jede Registrierung
und jede wöchentliche Erneuerung in der Installation einen vollständigen Kalenderabruf einreihen.

**Eine App, die in Testing bleibt, lässt ihre Refresh-Tokens nach etwa einer Woche verfallen.** Das
Konto funktioniert bis dahin und hört danach ohne Vorwarnung auf; ein erneutes Verbinden repariert
es. Das ist Googles Regel für unveröffentlichte Apps, die Scopes dieser Reichweite anfragen, und
nichts, was plMail umgehen könnte.

**Das Pub/Sub-Token muss Zeichen für Zeichen passen.** plMail weist Benachrichtigungen ab, die es
nicht mitbringen, und die Abweisung wird protokolliert statt verschwiegen — **Admin → Gmail-Webhooks**
zeigt abgewiesene Benachrichtigungen, und das ist der Unterschied zwischen „Pub/Sub erreicht uns
nicht" und „Pub/Sub erreicht uns und wird abgewiesen".
