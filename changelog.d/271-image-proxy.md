**Pictures in mail show up again where they were missing.** A BAUHAUS review request showed
neither its logo nor its product photos, even with images allowed, for three reasons. Pictures
linked as `http://` were never asked for, because plMail only fetches over an encrypted
connection; they are now fetched as `https://`. Everything inside a form was removed along with the
form, here the whole first product with its photo, name and stars; the form's content now stays and
only its text fields go. And a card a mail hides by default and switches on with its stylesheet
stayed hidden; plMail now follows the stylesheet as a browser does. Mail already in the mailbox is
repaired on request: `php bin/console app:backfill safe-html-hidden-cards` writes the shown copy of
every message with a form or such a stylesheet again from the sender's original. The `http://` fix
needs nothing. When plMail refuses a picture's address, the log now says why. No schema change.
[#271](https://github.com/karatektus/pl_mail/issues/271).
