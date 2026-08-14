<!-- translated-from: README.md sha1:9cebb8a0e8d6a5b6d68c807a928548fcfd74903b -->

# plMail-Dokumentation

Das Handbuch. Die [README](../README.md) sagt, was plMail ist, und bringt dich zu einer
laufenden Instanz; hier steht alles, was danach kommt — jede Funktion und ihre Bedienung, die
Installation auf dem, was du zur Verfügung hast, die Registrierung der Google- und
Microsoft-Anwendungen, mit denen plMail spricht, und wie die Teile darunter arbeiten.

Gespiegelt ins [GitHub-Wiki](https://github.com/karatektus/pl_mail/wiki), das aus diesen
Dateien erzeugt wird. Bearbeite sie hier; im Wiki-Browser vorgenommene Änderungen überschreibt
die nächste Spiegelung.

| Wenn du … willst | Fang an bei |
|---|---|
| eine Funktion benutzen | [plMail verwenden](#plmail-verwenden) |
| es installieren oder betreiben | [Installieren und betreiben](#installieren-und-betreiben) |
| Gmail, Outlook oder einen CalDAV-Server anbinden | [Anbieter](#anbieter) |
| die Interna verstehen oder erweitern | [Wie es funktioniert](#wie-es-funktioniert) |

---

## plMail verwenden

Eine Seite pro Bereich. Jede endet mit Verweisen nach [Wie es funktioniert](#wie-es-funktioniert),
wo der Mechanismus dahinter steht.

| Seite | Worum es geht |
|---|---|
| [Mail](features/mail.md) | Lesen, Konversationen, Labels, Suche und ihre Operatoren, Zurückstellen, Anhänge, Verfassen, Signaturen, Emoji und eingebettete Bilder, später senden, Lesebestätigungen, Entwürfe, Senden rückgängig machen |
| [Konten und Aliase](features/accounts.md) | Gmail-, Outlook- und IMAP-Konten hinzufügen, Absendeadressen, Einstellungen pro Konto |
| [Zustand der Konten](features/health.md) | Was kaputt ist und was es behebt, ein Konto neu verbinden, ohne seine Post zu verlieren, die zwei Arten, wie Push kaputtgeht |
| [Filter](features/filters.md) | Bedingungsbäume, Aktionen, die Rückübersetzung in einen Satz, eine Regel auf bereits eingetroffene Mail anwenden |
| [Kalender](features/calendar.md) | Die vier Ansichten und das Zeitraster, Termine anlegen und bearbeiten, Wiederholungen, eine einzelne Termininstanz bearbeiten, der angedockte Bereich |
| [Einladungen und Termine aus E-Mails](features/calendar-invitations.md) | Zu- und Absagen, Termine aus Einladungen und aus gewöhnlichem Fließtext, Vorschläge, „Demnächst“ |
| [Erinnerungen](features/calendar-alerts.md) | Erinnerungen setzen, wie sie zugestellt werden, was eine frische Installation braucht, damit sie ankommen |
| [Verbundene Kalender](features/calendar-sync.md) | Google-, Microsoft- und CalDAV-Kalender abonnieren, Abgleich in beide Richtungen, ICS-Import, -Export und Feed-Abonnements, doppelte Termine |
| [Teilen und Buchen](features/calendar-sharing.md) | Freigabelinks und was jeder preisgibt, Buchungsseiten, wie eine Buchung ankommt |
| [Dateien und Integrationen](features/integrations.md) | Anhängen aus und Speichern in Drive, Photos, OneDrive, Dropbox, Nextcloud und Immich |
| [Sicherheit](features/security.md) | Zwei-Faktor-Authentifizierung, Wiederherstellungscodes, gemerkte Geräte, App-Passwörter, Sitzungen |
| [Andere Clients](features/clients.md) | Einen JMAP-Client verbinden, Passwörter pro App, die PWA und Browser-Benachrichtigungen |
| [Darstellung](features/appearance.md) | Themes, eigene Farben und Hintergrund, die Live-Vorschau, was eine Listenzeile zeigt, Schriftart und Textgröße, Dichte je Bereich, Import und Export, Sprache |
| [Administration](features/admin.md) | Benutzer und Rollen, Integrationen freischalten, Monitoring, Warteschlangen und der Versions-Chip |

## Installieren und betreiben

| Seite | Worum es geht |
|---|---|
| [Docker Compose](install/docker.md) | Der unterstützte Weg, von Anfang bis Ende |
| [Hinweise zu den Plattformen](install/platforms.md) | Linux, Windows über WSL2, macOS und NAS-Geräte |
| [Hinter einem Reverse Proxy](install/reverse-proxy.md) | TLS, `APP_PUBLIC_URL`, `TRUSTED_PROXIES` — und was ohne sie kaputtgeht |
| [Konfigurationsreferenz](install/configuration.md) | Jede Umgebungsvariable, was sie tut und was passiert, wenn sie falsch steht |
| [Sichern und Wiederherstellen](install/backup-restore.md) | Was zu sichern ist, der Verschlüsselungsschlüssel und die Wiederherstellung auf einem neuen Host |
| [Konfigurationssicherung](install/config-backup.md) | Einstellungen und Zugangsdaten einer Installation als eine verschlüsselte Datei zu einer anderen tragen |
| [Aktualisieren](install/upgrading.md) | Migrationen laufen beim Start, was das bedeutet, und wie du erkennst, welcher Build läuft |
| [Fehlersuche](install/troubleshooting.md) | Health-Checks, die Warteschlange, Protokolle und die Fehler, die tatsächlich vorgekommen sind |

## Anbieter

Jede Seite nennt die genaue Konsole, die genauen Häkchen und die genauen Redirect-URIs.

| Seite | Worum es geht |
|---|---|
| [Google](providers/google.md) | Cloud-Projekt, OAuth-Client, Scopes für Mail und Kalender, Pub/Sub für Gmail-Push, Watch-Kanäle für den Kalender und Domain-Verifizierung |
| [Microsoft](providers/microsoft.md) | App-Registrierung in Azure, Redirect-URIs, delegierte Berechtigungen, Wahl des Tenants, Graph-Abonnements |
| [IMAP und SMTP](providers/imap-smtp.md) | Gewöhnliche Postfächer, IDLE, und die Einstellungen, über die sich die Server uneinig sind |
| [CalDAV](providers/caldav.md) | Discovery, App-Passwörter, und die Server, die getestet wurden |
| [ICS-Feeds](providers/ics-feeds.md) | Einen veröffentlichten Kalender per URL abonnieren, und warum manche Adressen abgelehnt werden |

## Wie es funktioniert

Tiefer als die Notizen in [CONTRIBUTING](../CONTRIBUTING.md), und gedacht für jemanden, der
plMail prüft oder erweitert, statt es zu betreiben.

| Seite | Worum es geht |
|---|---|
| [Architektur](internals/architecture.md) | Die Schichten, was wo liegt, und die Regeln, die das so halten |
| [Mail-Ingest](internals/mail-ingest.md) | Der Weg vom Anbieter in die Datenbank, Zuordnung zu Konversationen, Kategorisierung |
| [Das Kalendermodell](internals/calendar-model.md) | JSCalendar in jsonb, projizierte Spalten, Termininstanzen, Wiederholungen und Ausnahmen |
| [Die Sync-Engine](internals/calendar-sync-engine.md) | Der Treibervertrag, den jeder Anbieter erfüllt, Push-Kanäle, Deduplizierung |
| [Terminerkennung](internals/event-extraction.md) | Wie aus einer Einladung und wie aus einem Satz ein Kalendereintrag wird |
| [JMAP](internals/jmap.md) | Was umgesetzt ist, was bewusst nicht, und die Id-Räume |
| [Sicherheitsmodell](internals/security-model.md) | Verschlüsselung im Ruhezustand, die Geheimnisdatei, Token, und was ein öffentlicher Link erreicht |

Wer einen Client schreibt, sollte zusätzlich
[Client development](CLIENT_DEVELOPMENT.md) lesen — die Referenz auf Protokollebene.

---

## Konventionen auf diesen Seiten

- **Befehle** stehen so, wie du sie gegen eine Compose-Installation ausführen würdest:
  `docker compose exec php php bin/console <command>`. Lass das Präfix weg, wenn du plMail
  direkt betreibst.
- **Pfade in den Einstellungen** werden als `Einstellungen → Kalender → Verbundene Kalender`
  geschrieben.
- **Ein Abschnitt „Fallstricke“** am Ende einer Seite sammelt die Fallen — den Fehler, dessen
  Ursache sich aus dem Symptom nicht erschließt. Sie stehen dort, weil sie passiert sind.
