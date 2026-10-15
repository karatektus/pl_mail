<!-- translated-from: providers/oidc.md sha1:bfed4daca2f0fb6cf002748994c3a5b99a1cd55e -->

# Single Sign-on (OpenID Connect)

plMail kann Menschen über einen Identitätsanbieter anmelden, den du ohnehin betreibst — Authentik,
Keycloak, Authelia, Zitadel oder alles andere, das OpenID Connect spricht. Die Login-Seite zeigt
dann einen Button **Mit … anmelden** über dem Passwortformular; das Passwortformular bleibt.

Hier geht es um die Anmeldung bei plMail selbst. Ein Gmail- oder Outlook-*Postfach* zu verbinden ist
ein anderer OAuth-Ablauf, beschrieben unter [Google](google.md) und [Microsoft](microsoft.md).

## Was es tut

- Der Authorization-Code-Flow, mit PKCE, einem `state` und einer `nonce`. Die Endpunkte des
  Anbieters kommen aus seinem Discovery-Dokument, also ist der Issuer die einzige Adresse, die du
  einträgst.
- Ein Konto wird der Identität beim Anbieter über **Issuer und Subject** (`sub`) zugeordnet; beides
  wird am Konto gespeichert, wenn sich die Person zum ersten Mal anmeldet. Ändert sich danach die
  Adresse beim Anbieter, ändert das hier nichts.
- Die erste Anmeldung findet das Konto über die **E-Mail-Adresse** — und nur, wenn der Anbieter
  sagt, dass er diese Adresse bestätigt hat (`email_verified: true`). Siehe **Nur eine Adresse
  verknüpfen, die der Anbieter bestätigt hat** weiter unten.
- Ist **Konto für Personen anlegen, die plMail noch nicht kennt** eingeschaltet, bekommt jemand, den
  der Anbieter durchlässt und den plMail noch nie gesehen hat, ein neues, gewöhnliches Konto (kein
  Admin-Konto), benannt nach `given_name` und `family_name` des Anbieters. Ohne das legt die
  Administration das Konto vorher unter **Administration → Benutzer** an, mit derselben Adresse.
- Alles, was für eine Anmeldung mit Passwort gilt, gilt auch hier: Ein abgeschaltetes Konto wird
  abgelehnt, die Anmeldedrosselung zählt die Versuche, und ein Konto mit eingeschalteter
  Zwei-Faktor-Authentifizierung wird nach seinem Code gefragt.

## Einrichtung in Authentik

1. **Applications → Providers → Create → OAuth2/OpenID Provider.**
   - *Client type*: **Confidential**.
   - *Redirect URIs*: **Strict**, `https://<deine plMail-Adresse>/login/oidc/callback` — die
     Adresse, die man eintippt, um plMail zu erreichen, also die aus `APP_PUBLIC_URL`.
   - *Scopes*: Lass die vorgegebenen Zuordnungen für `openid`, `email` und `profile` ausgewählt.
   - Alles andere kann auf seiner Vorgabe bleiben.
2. **Applications → Applications → Create**, wähl den Provider aus, den du gerade angelegt hast, und
   gib ihm einen Slug — etwa `plmail`. Binde eine Gruppe oder eine Policy daran, wenn nicht alle aus
   Authentik hineindürfen; Authentik weist die anderen dann ab, bevor sie plMail überhaupt erreichen.
3. Kopier von der Seite des Providers die **Client ID**, das **Client Secret** und den **OpenID
   Configuration Issuer** — etwas wie `https://auth.example.com/application/o/plmail/`.
4. Öffne in plMail **Administration → SSO**, füg die drei ein, gib dem Button den Namen des
   Anbieters und drück **Speichern**. Es gilt sofort — ohne Neustart —, und der Chip auf der Seite
   zeigt **An**.

Keycloak und die übrigen sind dieselben vier Angaben unter anderen Namen: ein vertraulicher Client,
die Redirect-URI von oben und die Issuer-URL des Realms.

## Die Einstellungen

Alles steht unter **Administration → SSO**. Dieselbe Seite zeigt die Redirect-URI, die du beim
Anbieter einträgst, mit einem Button zum Kopieren.

| Einstellung | Was sie tut | Vorgabe |
|---|---|---|
| **Issuer** | Die Issuer-URL des Anbieters, genau so, wie sein Discovery-Dokument sie angibt. Muss `https://` sein. | leer |
| **Client-ID** | Die Client-ID, die der Anbieter ausgestellt hat. | leer |
| **Client-Secret** | Das Client-Secret. Nur schreibbar: Die Seite zeigt ein gespeichertes nie an, und ein leeres Feld behält es. | leer |
| **Name auf dem Button** | Der Name des Anbieters auf dem Login-Button — „Mit Authentik anmelden“. Leer heißt es „Single Sign-on“. | leer |
| **Scopes** | Scopes, durch Leerzeichen getrennt. `openid` wird immer ergänzt. | `openid email profile` |
| **Konto für Personen anlegen, die plMail noch nicht kennt** | Ob jemand, den der Anbieter durchlässt und den plMail noch nie gesehen hat, ein neues gewöhnliches Konto bekommt. | aus |
| **Nur eine Adresse verknüpfen, die der Anbieter bestätigt hat** | Ein bestehendes Konto nur dann über die Adresse verknüpfen, wenn der Anbieter für die Adresse bürgt. | an |

Issuer, Client-ID und Secret werden zusammen gespeichert oder gar nicht: Das Formular lehnt zwei von
dreien ab, weil ein halber Anbieter hier eingerichtet aussähe, während sich niemand anmelden könnte.

### Stattdessen aus der Umgebung

Eine Installation, die ihre Konfiguration in einer Compose-Datei pflegt, kann dasselbe dort setzen;
es gilt immer dann, wenn unter Administration → SSO kein vollständiger Anbieter gespeichert ist.
`compose.yaml` reicht die Variablen aus deiner Umgebung durch, und `truenas.compose.yaml` hat die
ersten vier in ihrem `x-config`-Block.

| Variable | Einstellung | Vorgabe |
|---|---|---|
| `OIDC_ISSUER` | Issuer | leer (aus) |
| `OIDC_CLIENT_ID` | Client-ID | leer (aus) |
| `OIDC_CLIENT_SECRET` | Client-Secret | leer (aus) |
| `OIDC_BUTTON_LABEL` | Name auf dem Button | leer |
| `OIDC_SCOPES` | Scopes | `openid email profile` |
| `OIDC_AUTO_CREATE` | Konto für Personen anlegen, die plMail noch nicht kennt | `false` |
| `OIDC_REQUIRE_VERIFIED_EMAIL` | Nur eine Adresse verknüpfen, die der Anbieter bestätigt hat. Nur `false`, `0`, `no` oder `off` schalten es aus; leer oder alles andere lässt es an. | `true` |

**Was unter Administration → SSO gespeichert ist, hat Vorrang**, und zwar im Ganzen: Ein
gespeicherter Anbieter nutzt auch seine eigenen Optionen und liest nichts aus diesen Variablen. Der
Chip auf der Seite sagt, was gilt — **An**, **An, aus der Umgebung** oder **Aus**. Um die
Entscheidung an die Umgebung zurückzugeben, leere den Issuer und die Client-ID und setz den Haken
bei **Gespeichertes Client-Secret vergessen**.

## Fallstricke

**„Dein Anmeldeanbieter hat deine E-Mail-Adresse nicht bestätigt.“** Authentiks vorgegebene
Scope-Zuordnung für `email` hat je nach Version nicht immer `email_verified: true` gemeldet. Gib dem
Anbieter entweder eine eigene Scope-Zuordnung, die für Adressen, die deine Administration setzt,
`"email_verified": True` zurückgibt, oder — wenn man die eigene Adresse in Authentik nicht selbst
ändern kann — schalt **Nur eine Adresse verknüpfen, die der Anbieter bestätigt hat** aus. Schalt es
nicht bei einem Anbieter aus, bei dem jede Person ihre eigene Adresse ändern kann: Diese Person
könnte dann jedes plMail-Konto übernehmen, das sich noch nicht über den Anbieter angemeldet hat,
Admin-Konten eingeschlossen.

**Der Anbieter meldet, die Redirect-URI passe nicht.** plMail baut die Callback-Adresse aus der
Anfrage, die es gerade beantwortet. Hinter einem Reverse-Proxy, der `X-Forwarded-Proto` nicht
weitergibt, oder mit einem `TRUSTED_PROXIES`, das zu eng ist, um ihm zu glauben, beginnt diese
Adresse mit `http://`, und der Anbieter lehnt sie ab. Siehe
[Hinter einem Reverse-Proxy](../install/reverse-proxy.md).

**Der Issuer muss `https://` sein.** Dem ID-Token wird vertraut, weil es über TLS direkt vom
Token-Endpunkt des Anbieters kommt; einen Anbieter auf unverschlüsseltem HTTP könnte also jeder auf
dem Weg dazwischen nachahmen. plMail lehnt das ab, der Button meldet Single Sign-on als nicht
verfügbar, und das Log sagt, warum. Aus demselben Grund folgt plMail keinen Weiterleitungen des
Anbieters: Ein Discovery-Dokument oder ein Token-Endpunkt, der mit einer antwortet, gilt als nicht
verfügbar — trag als Issuer also die Adresse ein, unter der der Anbieter tatsächlich ausliefert.

**„Das Konto mit deiner E-Mail-Adresse ist bereits mit jemand anderem verknüpft.“** Das Konto ist
mit einem Subject verknüpft, nicht mit einer Adresse, und wer beim Anbieter gelöscht und neu
angelegt wurde, kommt mit einem neuen Subject zurück — Authentik leitet es aus seiner eigenen
Benutzer-ID ab. plMail sieht dann eine zweite Person, die ein verknüpftes Konto beansprucht, und
lehnt ab, was die richtige Antwort ist, wenn es wirklich eine zweite Person ist. Ist es das nicht,
vergisst du die alte Verknüpfung auf der Konsole, und die nächste Anmeldung verknüpft das Konto neu:

```bash
docker compose exec php php bin/console app:user:oidc-unlink you@example.com
```

**Wer Administration → SSO öffnen kann, entscheidet, wer sich anmelden kann.** Eine Administratorin
kann plMail auf einen eigenen Anbieter zeigen lassen, und ein Anbieter sagt, wer jemand ist: Mit
geändertem Issuer wird jedes Konto bei der nächsten Anmeldung neu über die Adresse verknüpft, mit
dem, was dieser Anbieter behauptet. Eine Administratorin — oder wer auch immer eine Admin-Sitzung in
die Hand bekommt — kann sich also als jedes Konto anmelden, das keinen eigenen zweiten Faktor hat.
Zwei-Faktor-Authentifizierung an einem Konto hält das weiterhin auf, weil der Code abgefragt wird,
nachdem der Anbieter geantwortet hat. Das ist der Preis dafür, Single Sign-on im Browser
einzurichten; gib Admin-Konten einen zweiten Faktor, und lies das
[Sicherheitsmodell](../internals/security-model.md).

**Eine Administratorin erreicht ein Konto, das noch nicht verknüpft ist.** Administration →
Benutzer kann die Adresse eines Kontos ändern, und die erste Anmeldung über den Anbieter findet ein
Konto über seine Adresse. Wer also ein Admin-Konto hat und dazu einen zweiten Login beim Anbieter,
kann das unverknüpfte Konto einer anderen Person auf die Adresse dieses Logins zeigen lassen und
sich dort anmelden — die eine Stelle, an der Single Sign-on einer Admin-Sitzung im Browser einen Weg
in fremde Mail gibt. Es braucht einen Anbieter, der diesen zweiten Login zulässt, und es ändert die
Adresse, mit der sich die betroffene Person anmeldet, bleibt also nicht unbemerkt. Sobald sich ein
Konto einmal über den Anbieter angemeldet hat, ist es verknüpft, und die Adresse spielt keine Rolle
mehr. Wo die Administration nicht dieselben Leute sind, die den Anbieter betreiben, lass den
Anbieter seine Benutzer anlegen, statt dass sie sich selbst registrieren.

**Abmelden bei plMail meldet dich nicht beim Anbieter ab.** Ein erneuter Klick auf den Button meldet
dich ohne Rückfrage sofort wieder an, weil der Anbieter dich noch kennt.

**Der zweite Faktor wird zweimal abgefragt**, wenn ein Konto ihn hier und beim Anbieter eingeschaltet
hat. Schalt ihn unter **Einstellungen → Sicherheit** aus, wenn der des Anbieters reicht.

**Ein vom Anbieter angelegtes Konto hat kein Passwort.** Es meldet sich nur über den Anbieter an, und
[App-Passwörter](../features/security.md#app-passwörter) für JMAP- und CalDAV-Clients funktionieren
wie bei allen anderen. Unter **Einstellungen → Sicherheit** kann es sich auch keins setzen, weil das
Formular nach dem aktuellen fragt; wer eins will, bekommt es mit `app:user:password` auf der Konsole.

**Ein Wechsel zu einem anderen Anbieter verknüpft über die Adresse neu.** Die Verknüpfung merkt sich,
mit welchem Issuer sie entstanden ist, also passt ein neuer Issuer nie zu einem alten Subject; die
nächste Anmeldung jeder Person verknüpft ihr Konto erneut über die (bestätigte) Adresse. Eine
[Konfigurationssicherung](../install/config-backup.md) enthält die Verknüpfungen ebenfalls nicht,
und sie kommen auf demselben Weg zurück.

**Discovery wird eine Stunde lang zwischengespeichert.** Geänderte Endpunkte eines Anbieters werden
innerhalb der Stunde übernommen, oder sofort nach `bin/console cache:pool:clear cache.app`.
