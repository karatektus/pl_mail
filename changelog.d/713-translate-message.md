**A message can be translated into your language.** Where an administrator has switched translation
on in Admin → AI, **Translate** in a message's ⋮ menu has the writing model translate it into the
language plMail is in, or the one chosen under Settings → Assistant → **Translate into**, which lists
far more languages than plMail has an interface in. The translation takes the place of the message
on a sheet tagged **AI translation**, in the assistant's violet, and a switch under the sender reads
**Original · German** and **English · AI**; one click on either half shows that one. The model names
the message's language as it translates, and a message already in the reader's language is left as
it is, without a translation. It is kept, so the message opens
translated next time at no cost, and the sheet says when the message has changed since. Only the
message's own text is sent, without the earlier mail it quotes, and replies and forwards quote the
original. Off by default, and nothing is translated until somebody asks.

**Migration:** `ai_settings` gains `translation_enabled` (off) and `prompt_translation`, the user
table `ai_translation_off` and `ai_translate_into`, and a `message_translation` table keeps the
translations and the language each message was written in. [#713](https://github.com/karatektus/pl_mail/issues/713), by @swell-d.
