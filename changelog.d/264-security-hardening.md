**Security hardening, from the reports filed under
[#264](https://github.com/karatektus/pl_mail/issues/264) and after.**
- STARTTLS is required for SMTP accounts set to STARTTLS: a server, or someone in between, that does
  not offer it now fails the send instead of receiving the password in clear text.
- The connection test and the account form only reuse a stored password for the servers it was
  saved for. Changing a host, port or encryption asks for the password again.
- "Single sign-on only" can no longer be switched off from the web, by unticking it, clearing the
  provider or restoring a backup. `bin/console app:sso:allow-password-login` is the way, as
  documented.
- Exporting or applying a configuration backup needs a sign-in in this session, not a remembered
  one, and each is logged.
- A sender-written `Authentication-Results` header no longer silences the "name does not match the
  address" warning; only the account's receiving server's results count.
- The web server's and hub's state directories are no longer left readable by every user on the
  host when bind-mounted.
