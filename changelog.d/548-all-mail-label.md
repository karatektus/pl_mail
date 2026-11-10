**On a Proton Mail account, All Mail in the sidebar lists all your mail instead of an old handful.**
Since v0.4.1 plMail no longer fetches Proton's "All Mail" folder, which repeats every message, but
the label that folder had filled stayed in the sidebar with the few messages no other folder held.
It looked like the place to see everything and showed nothing recent, so mail seemed to be
missing. That label and the one from Proton's "Starred" folder now leave the sidebar (they stay in
Settings → Labels), and plMail's own All Mail, which lists every conversation from every folder,
is switched on in their place. No schema change; a data migration has the next folder sync of each
account do this, and folders you switched back on are left alone.
[#548](https://github.com/karatektus/pl_mail/issues/548).
