# Working in Ben's plMail fork

This is Ben's fork of [karatektus/pl_mail](https://github.com/karatektus/pl_mail), the code his self-hosted plMail runs. Upstream's CONTRIBUTING.md, CODESTYLE.md and DESIGN.md govern how code is written, tested and documented here.

## Branches

- Branch from `main` and open pull requests into `main` of `benwmerritt/pl_mail`, never into upstream.
- A merge into `main` publishes `ghcr.io/benwmerritt/pl_mail:main`, which the staging plMail follows. Prove a change there; `ops/plmail/README.md` in the homelab repository (`benwmerritt/homelab`) says where staging is.
- A change goes live through a pull request from `main` into `prod`. Merging it publishes `:prod`, and the deploy script in homelab's `ops/plmail/` then pins the live plMail to that image's digest.
- An upstream release comes in as a pull request into `main` that merges its `vX.Y.Z` tag from the `upstream` remote. Keep fork changes small and few files wide so those merges stay clean.
- Merge release and promotion pull requests (upstream into `main`, `main` into `prod`) with a merge commit. A squash drops the merged history, so the next merge replays changes the branch already has and conflicts on them.
