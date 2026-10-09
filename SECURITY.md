# Security

## Reporting a vulnerability

Please report it privately first: on GitHub, open the repository's **Security** tab and choose
**Report a vulnerability**. That opens a private advisory only the maintainer can read, and it is
where the fix and the disclosure are coordinated.

If you would rather not use GitHub for it, open an ordinary issue that says only that you have a
security report and how to reach you — no details — and you will be contacted.

What helps most:

- which version or commit you looked at, and whether you ran it or read the code;
- what an attacker needs (an account, a place on the network, only the ability to send mail);
- what they get, in a sentence;
- the files and lines involved, if you know them.

You will get an answer within a few days. plMail is maintained by one person in spare time, so a
fix can take longer than that; you will be told what the plan is either way. Reporters are credited
in the changelog unless they ask not to be.

## What counts

plMail is self-hosted, so the things worth reporting are the ones that cross a boundary an
installation relies on:

- one user reading or changing another user's mail, calendar or settings;
- anything a **sender of mail** can make happen on the reader's side beyond showing them a
  message — running script in the app, changing the calendar, reaching the reader's session;
- anything an unauthenticated visitor can do beyond the pages meant for them (login, shared
  calendar links, booking pages, the demo door);
- secrets or tokens leaving the install.

How the app is meant to hold those lines is written down in
[docs/internals/security-model.md](docs/internals/security-model.md). A report that shows one of
its claims to be false is exactly what this file is for.

## Supported versions

Fixes go into `main` and the next release. There are no maintained older branches: the answer to a
fixed vulnerability is upgrading.
