<!-- translated-from: providers/microsoft.md sha1:593c30a706038d59877536d7f264562631d8d6fc -->

# Microsoft

Postfächer von Outlook.com, Hotmail und Microsoft 365 erreichen plMail über **Microsoft Graph** und
eine Anwendung, die du im Azure-Portal registrierst. Einen IMAP-Weg gibt es für sie nicht, und das
ist Absicht: Exchange Online stuft IMAP, POP und SMTP im Conditional Access als
Legacy-Authentifizierung ein, IMAP mit OAuth ist damit in jedem Tenant mit Security Defaults rundweg
blockiert — und das ist die Voreinstellung für neue Tenants. Graph ist der einzige Weg, der überall
funktioniert, und der einzige, in den Microsoft noch investiert.

Die Registrierung ist einmalig, für die gesamte Installation, und sie deckt Mail *und* Kalender ab.
Sobald sie existiert, fügen Nutzerinnen und Nutzer ihre Konten unter **Einstellungen →
E-Mail-Konten** hinzu, ohne weitere Administration — siehe [Konten und Aliase](../features/accounts.md)
und [Verbundene Kalender](../features/calendar-sync.md).

## Die Anwendung registrieren

1. Öffne [portal.azure.com](https://portal.azure.com) und geh zu **Microsoft Entra ID → App
   registrations → New registration** (App-Registrierungen → Neue Registrierung). Gib ihr einen
   Namen; dieser Name ist das, was Nutzerinnen und Nutzer auf dem Zustimmungsbildschirm und in der
   Liste der verbundenen Anwendungen ihres Microsoft-Kontos sehen.
2. **Wähl, wer sich anmelden darf.** Das ist die Entscheidung, die zur Tenant-Einstellung in plMail
   passen muss, und der Abschnitt weiter unten sagt genau, welcher Wert zu welchem gehört.
   *„Accounts in any organizational directory and personal Microsoft accounts"* (Konten in einem
   beliebigen Organisationsverzeichnis und persönliche Microsoft-Konten) passt zur Voreinstellung
   `common`.
3. Setz die Plattform der **Redirect URI** (Umleitungs-URI) auf **Web** und füg die Adresse ein, die
   plMail dir zeigt:

   ```
   https://your-domain/oauth/microsoft/callback
   ```

   Das ist die Route `/oauth/{provider}/callback` mit `microsoft` als Provider.
   **Admin → Integrationen → Mail-Anmeldung → Microsoft-Mail-Anmeldung** zeigt die exakte URI für
   deine Installation samt Kopierknopf, und die Einrichtung zeigt dieselbe. Füg sie ein, statt sie zu
   tippen: Microsoft vergleicht sie exakt, und sie lässt sich später nicht mehr ändern, ohne jede
   bereits dagegen autorisierte Verbindung zu zerbrechen.
4. Kopier nach dem Registrieren die **Application (client) ID** (Anwendungs-ID (Client)) von der
   Übersichtsseite.
5. Erstell unter **Certificates & secrets** (Zertifikate & Geheimnisse) ein Client-Secret und kopier
   sofort dessen **Value** (Wert) — nicht dessen ID. Azure zeigt den Wert einmal und nie wieder.
6. Füg unter **API permissions** (API-Berechtigungen) die unten aufgeführten delegierten
   Microsoft-Graph-Berechtigungen hinzu.

Client-ID und Secret gehören entweder unter **Admin → Integrationen → Mail-Anmeldung →
Microsoft-Mail-Anmeldung**, was sie in der Datenbank speichert, oder in `MICROSOFT_OAUTH_CLIENT_ID`
und `MICROSOFT_OAUTH_CLIENT_SECRET` in der Umgebung. Ein gespeicherter Wert gewinnt gegen die
Umgebung, und nur dann, wenn er wirklich gesetzt ist, eine bereits über die Umgebung konfigurierte
Installation läuft also unangetastet weiter. Siehe die
[Konfigurationsreferenz](../install/configuration.md).

## Die delegierten Berechtigungen, und wofür jede da ist

Alle diese sind **delegierte** Microsoft-Graph-Berechtigungen — plMail handelt als die angemeldete
Person und nie als die Anwendung. plMail fragt sie bei der Zustimmung als Satz an, und Microsoft
stimmt dem Satz als Ganzem zu; anders als bei Google gibt es keine Teilerteilung zu behandeln.

| Berechtigung | Wofür sie da ist |
|---|---|
| `offline_access` | Liefert ein Refresh-Token. Ohne sie funktioniert die Verbindung bis zum Ablauf des ersten Access-Tokens und hört dann auf. |
| `openid`, `email`, `profile` | Identifizieren das angemeldete Konto, wonach plMail das Konto benennt. |
| `User.Read` | Liest `/me`. Es ist das Erste, was `app:graph:diagnose` prüft, denn eine funktionierende Identität bei ansonsten scheiterndem Rest lokalisiert das Problem beim Postfach statt bei den Zugangsdaten. |
| `Mail.ReadWrite` | Ordner, Nachrichten, Anhänge, Markierungen, Verschieben und Löschen — die Mail-Synchronisation selbst. |
| `Mail.Send` | Senden. In Graphs Modell vom Obigen getrennt, muss also getrennt hinzugefügt werden. |
| `MailboxSettings.ReadWrite` | Master-Kategorien, unter `/me/outlook/masterCategories`. Diese sind **nicht** von `Mail.ReadWrite` abgedeckt: Sie liegen unter der Outlook-Ressource für Benutzereinstellungen und liefern ohne diese Berechtigung `ErrorAccessDenied`. ReadWrite statt Read, weil plMail Kategoriedefinitionen anlegt, wenn ein Label gespiegelt wird. |
| `Calendars.ReadWrite` | Kalender auf demselben Konto, lesend und schreibend. |

Zwei davon lohnen ein genaueres Verständnis, bevor du dich entscheidest, eine wegzulassen.

**Ohne `MailboxSettings.ReadWrite` ist Mail vollkommen gesund und Kategorien sind es nicht.** Ordner,
Nachrichten, Anhänge und das Senden funktionieren alle; Kategorien hören in beiden Richtungen auf
sich abzugleichen, Labels ohne Ordner dahinter tauchen also nie in Outlook auf und Outlooks
Kategorien nie in plMail. `app:graph:diagnose` meldet genau das als Warnung statt als Fehler, weil es
ein realer und überlebensfähiger Zustand ist.

**Ohne `Calendars.ReadWrite` funktioniert Mail weiter, und die Kalender erscheinen einfach nicht.**
Das sind die gesamten Kosten, und das sagt auch der Admin-Bildschirm: *„Hier ist nichts einzurichten.
Ergänze die delegierte Berechtigung Calendars.ReadWrite in dieser App-Registrierung — die Kalender
kommen dann mit derselben Anmeldung wie die E-Mails."*

### Kalender hängen an derselben Anmeldung wie Mail

Es gibt keine getrennte Kalenderverbindung zu aktivieren, keine zweite Anwendung und keinen zweiten
Zustimmungsbildschirm. `Calendars.ReadWrite` wird auf derselben Berechtigung angefragt wie die
Mail-Berechtigungen, du setzt als Administrator also ein weiteres Häkchen in dieser App-Registrierung
und hast sonst nirgends etwas einzurichten. Lesend und schreibend statt nur lesend, weil ein in
plMail bearbeiteter Termin nach Outlook zurückgeschrieben wird, und das heißt Anlegen, Ändern und
Löschen auf Microsofts Seite.

Erscheinen für eine Person keine Kalender, sagt plMail unter **Einstellungen → Kalender**, welche der
möglichen Ursachen es ist: Die Berechtigung kann in der App-Registrierung fehlen, oder das Konto hat
schlicht keine Kalender.

## Der Tenant-Wert

`MICROSOFT_OAUTH_TENANT` — oder das Feld **Tenant** unter **Admin → Integrationen → Mail-Anmeldung** —
entscheidet, welche Konten sich anmelden dürfen. Er muss zu den unterstützten Kontotypen passen, die
du beim Registrieren der Anwendung gewählt hast.

| Wert | Wer sich anmelden darf | Gehört zu |
|---|---|---|
| `common` | Geschäfts- oder Schulkonten **und** persönliche Microsoft-Konten | *Accounts in any organizational directory and personal Microsoft accounts* |
| `organizations` | Nur Geschäfts- oder Schulkonten | *Accounts in any organizational directory* |
| `consumers` | Nur persönliche Microsoft-Konten | *Personal Microsoft accounts only* |
| eine Tenant-GUID | Eine Organisation | *Accounts in this organizational directory only* |

Die Voreinstellung ist `common`. `organizations` ist eine Überlegung wert, wenn du keine
outlook.com-Adressen brauchst, denn es umgeht die Sonderfälle privater Konten vollständig —
persönliche Konten haben keine unveränderlichen IDs und eine eingeschränkte `$filter`-Unterstützung,
und beides zeigt sich als seltsames Verhalten statt als Fehler.

**Eine Abweichung scheitert bei der Zustimmung, nicht bei der Einrichtung.** `common` gegen eine auf
einen einzigen Tenant beschränkte App-Registrierung ergibt `AADSTS50194`, und das kommt als
fehlgeschlagene Anmeldung bei der betroffenen Person an, lange nachdem die Administration fertig ist
und weitergezogen. plMail übersetzt die häufigen Microsoft-Fehlercodes in Sätze, statt den rohen Code
zu zeigen — der für diesen Fall sagt, dass sich der Kontotyp mit der aktuellen Konfiguration nicht
anmelden kann, und benennt die zu prüfende Einstellung.

## Push, und was es braucht

Sofortige Zustellung funktioniert hier ohne weiteres Zutun, ohne zusätzliche Cloud-Ressourcen — die
einzige Voraussetzung ist, dass deine öffentliche Adresse HTTPS und aus dem Internet tatsächlich
erreichbar ist, damit Microsoft zurückrufen kann. Siehe
[Hinter einem Reverse Proxy](../install/reverse-proxy.md).

Graph weist die Echtheit eines Abonnements nach, indem es **ein `validationToken` an deine
Benachrichtigungs-URL POSTet und das rohe Token binnen zehn Sekunden als `text/plain` zurück
erwartet**, synchron, innerhalb des Anlegeaufrufs. Das hat eine angenehme Folge: Eine Installation,
die nicht wirklich erreichbar ist, scheitert bei der Registrierung, laut und harmlos, statt ein
Abonnement anzulegen, das dann still nie zustellt. Das Konto oder der Kalender bleibt beim Abrufen.

Benachrichtigungs-URLs werden aus deiner konfigurierten öffentlichen Adresse gebaut, nie aus der
eingehenden Anfrage. Reverse Proxies sind der Normalfall, und eine aus einer Anfrage abgeleitete URL
trägt nach der TLS-Terminierung einen internen Hostnamen oder `http://` — was Graph mit einem
Validierungsfehler ablehnt, der wirklich unangenehm zu diagnostizieren ist.

| | Mail | Kalender |
|---|---|---|
| Ressource | `/me/messages` | `me/calendars/{id}/events` |
| Änderungsarten | `created,updated,deleted` | `created,updated,deleted` |
| Endpunkt | `POST /webhook/graph/notify` | `POST /webhook/graph/calendar` |
| Lifecycle-Endpunkt | `POST /webhook/graph/lifecycle` | — |
| Echtheitsnachweis | `clientState` im Rumpf | `clientState` im Rumpf |
| Laufzeit | knapp unter drei Tagen | knapp unter drei Tagen |
| Erneuerung | `PATCH` auf den Ablauf | `PATCH` auf den Ablauf |
| Registriert | pro Konto, beim Einschalten der sofortigen Zustellung | pro Kalender, stündlicher Durchlauf |

Mail-Push wird pro Konto aktiv gewählt: **Einstellungen → E-Mail-Konten → Sofortige Zustellung**.
Scheitert die Registrierung, wird das Häkchen zurückgenommen, die Oberfläche behauptet also nie, Push
sei an, während nichts zugestellt wird. Die Erneuerung läuft nächtlich, mit einer Schwelle von zwölf
Stunden gegen eine Laufzeit von drei Tagen.

Kalender-Push gilt pro Kalender, denn Graph abonniert eine Ressource, und sechs gespiegelte Kalender
sind sechs Ressourcen mit sechs Geheimnissen und sechs Ablaufzeitpunkten. Nichts registriert einen
Kalenderkanal in dem Moment, in dem du einen Kalender zum Spiegeln anhakst — eine Registrierung wird
neben der Anfrage versucht und dann, falls sie nicht griff, vom stündlichen Durchlauf
`app:calendar:push` wiederholt. Das ist Absicht: Eine Registrierung scheitert aus Betriebsgründen,
die mit dem Klick nichts zu tun haben, und allein an den Abo-Ablauf gebunden bekämen diese Kalender
nie Push, bis jemand sie ab- und wieder anwählt.

Beide Benachrichtigungen sind inhaltsleer — sie sagen „hier hat sich etwas geändert" und nichts
darüber, was —, der Webhook tut also genau eine Sache, nämlich eine Synchronisation für die benannte
Ressource einzureihen, und jede Entscheidung bleibt in der Sync-Engine.

**Push trägt nie die Last.** Ein Kalender, der sich nicht registrieren konnte, wird alle fünfzehn
Minuten abgerufen, und ein Konto ohne Push wird nach demselben Zeitplan synchronisiert. Um die
Registrierung sofort anzustoßen:

```bash
docker compose exec php php bin/console app:calendar:push
```

## OneDrive ist eine eigene Registrierung

Dateien aus OneDrive anzuhängen und Anhänge dorthin zu speichern läuft über dieselbe API, braucht
aber Berechtigungen, die die Mail-Registrierung nicht mitbringt — `Files.ReadWrite` und
`offline_access` — sowie eine eigene Redirect-URI unter `/integrations/oauth/oneDrive/callback`. Der
Admin-Bildschirm bietet an, die Mail-Zugangsdaten dafür mitzubenutzen, was Client-ID und Secret
serverseitig hinüberkopiert; das Kopieren erteilt die zusätzliche Berechtigung **nicht**, die muss
also weiterhin in der App-Registrierung hinzugefügt werden. Die Einrichtungsschritte stehen unter
**Admin → Integrationen**, beim Eintrag zu OneDrive.

## Fallstricke

**Der Tenant-Wert und die unterstützten Kontotypen müssen zusammenpassen**, sonst scheitert die
Zustimmung mit `AADSTS50194` — bei der ersten Anmeldung der betroffenen Person, nicht bei der
Einrichtung. Das ist hier die mit Abstand häufigste Microsoft-Fehlkonfiguration.

**Kopier den Value des Secrets, nicht dessen ID.** Azure zeigt beide in derselben Tabelle, und nur
der Value ist brauchbar; er wird einmal angezeigt und lässt sich danach nicht mehr abrufen, nur
ersetzen.

**Eine Berechtigung hinzuzufügen wertet bestehende Tokens nicht auf.** Wer sich verbunden hat, bevor
du `Calendars.ReadWrite` oder `MailboxSettings.ReadWrite` ergänzt hast, hält ein Token, das sie nicht
trägt, und daran ändert kein Abwarten etwas. Das Konto muss getrennt und neu verbunden werden, damit
eine frische Zustimmung ein neues Token ausstellt — `app:graph:diagnose` sagt das ausdrücklich, wenn
es die Lücke findet.

**Eine funktionierende Identität bei durchweg scheiternden Mail-Endpunkten ist kein Problem der
Zugangsdaten.** Meistens heißt es, dass für das Microsoft-Konto überhaupt kein Outlook-Postfach
angelegt wurde, was bei Konten vorkommt, die aus einer externen Adresse entstanden sind. Prüf es auf
outlook.live.com: Auf einer Einrichtungsaufforderung statt in einem Posteingang zu landen ist das
Anzeichen, und keine Konfiguration auf dieser Seite behebt es.

**Master-Kategorien sind von `Mail.ReadWrite` nicht abgedeckt.** Sie sind eine eigene Ressource und
brauchen `MailboxSettings.ReadWrite`. Das Symptom ist ein vollkommen gesundes Konto, dessen Labels
nie in Outlook ankommen.

**Ein abgelaufenes Graph-Abonnement lässt sich nicht wiederbeleben.** Microsoft verlängert keines,
das bereits abgelaufen ist; nur ein frisches Abonnement funktioniert, und genau das legt der
Erneuerungsdurchlauf an. Deshalb liegt die Erneuerungsschwelle bei zwölf Stunden gegen eine Laufzeit
von drei Tagen und nicht enger.

**Benachrichtigungs-URLs mit `http://` oder `localhost` werden abgelehnt**, und plMail prüft das
lokal, bevor es Graph aufruft, damit du eine Logzeile mit dem Namen der fehlenden Einstellung
bekommst statt wiederholter entfernter Validierungsfehler.

**Die Redirect-URI lässt sich nicht mehr ändern, nachdem Menschen sich verbunden haben.** Die
öffentliche Adresse vor der ersten Anmeldung richtig zu haben ist billiger, als hinterher alle
umzuziehen.
