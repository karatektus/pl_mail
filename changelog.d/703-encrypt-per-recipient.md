**Remember encryption on or off per recipient.** Clicking the lock in the compose window, or
*More options → Encrypt*, now also opens a small panel with On / Off / Auto for each recipient
plMail has a key for: whether mail to that address is encrypted from now on. Leaving it alone
changes nothing, and it closes on any click, focus or Escape elsewhere. Off for one of your own
addresses keeps mail to an account you also read in the provider's app (Gmail, say) readable there.
Own addresses keep the choice in your settings, carried by the config backup; contacts keep it on
their key, as before. Native apps can set it with the new JMAP method `RecipientPolicy/set`.
No schema change. [#703](https://github.com/karatektus/pl_mail/issues/703), requested by @wbilger.
