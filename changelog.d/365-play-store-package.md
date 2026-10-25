**Push notifications for the Android app from the Play Store.** The app on Google Play is
`de.pluetzner.plmail`. When the Firebase project's `google-services.json` registers it, that is now
the app plMail publishes to phones, ahead of `de.plmail.google`, the package the APKs on GitHub were
built as; a project that registered only the GitHub one keeps working. The administration handbook
now says which package to register. No schema change.
[#365](https://github.com/karatektus/pl_mail/issues/365).
