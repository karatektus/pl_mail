**Mail from a social network is filed under Social, not Promotions.** On accounts sorted by the
header rules (every non-Gmail account, and Gmail ones with the provider's categories overridden), a
like, a follow or a "has given you kudos" from LinkedIn, Facebook or Strava went to Promotions: the
sender's mail platform adds `List-Unsubscribe` to everything, and that rule ran before the social
domain list, so the list never matched anything that carried it. The domain now wins, after Forums
and before Promotions, and `strava.com` is on the list. Run `app:backfill category` and answer yes
to re-file existing mail. A reader who lets the assistant decide is unaffected, as before. No
schema change. [#488](https://github.com/karatektus/pl_mail/issues/488), by @phdelodder.
