# Working in Ben's plMail fork

This is Ben's fork of [karatektus/pl_mail](https://github.com/karatektus/pl_mail), the code his self-hosted plMail runs. Upstream's CONTRIBUTING.md, CODESTYLE.md and DESIGN.md govern how code is written, tested and documented here.

## Branches

- Branch from `main` and open pull requests into `main` of `benwmerritt/pl_mail`, never into upstream.
- A merge into `main` publishes `ghcr.io/benwmerritt/pl_mail:main`, which the staging plMail follows. Prove a change there; `ops/plmail/README.md` in the homelab repository (`benwmerritt/homelab`) says where staging is.
- A change goes live through a pull request from `main` into `prod`. Merging it publishes `:prod`, and the deploy script in homelab's `ops/plmail/` then pins the live plMail to that image's digest.
- An upstream release comes in as a pull request into `main` that merges its `vX.Y.Z` tag from the `upstream` remote. Keep fork changes small and few files wide so those merges stay clean.
- Merge release and promotion pull requests (upstream into `main`, `main` into `prod`) with a merge commit. A squash drops the merged history, so the next merge replays changes the branch already has and conflicts on them.

## Working on an issue

Issues live in Linear team BMAIL (`.linear.toml`), worked through `bwm-linear`; they are not synced to GitHub. Start the branch with `linear issue start <BMAIL-N> --from-ref origin/main`.

## Look at your work

1. CI runs PHPStan, PHPUnit and the whole Playwright suite on every pull request into `main` (`.github/workflows/e2e.yml`). Leave those to it, and read its result before merging.
2. The review pass checks the diff against CODESTYLE.md and DESIGN.md, alongside the issue it implements.
3. Prove the change in a browser with `verify-plmail` (`.agents/skills/verify-plmail/SKILL.md`), driving every entry point its feature map lists for what you changed. For a change someone would see, run it with `--base origin/main` and put its desktop and phone screenshots on the pull request as before/after pairs, uploaded the way `bwm-prs` says, with the evidence folder and SHA named. For a logic change, drive the behaviour end to end. Instruction- and docs-only changes skip this.
4. The fork is public. Take PR screenshots only from `verify-plmail`, whose stack holds nothing but upstream's fictional demo mailbox. Staging and the live plMail hold Ben's real mail.
5. A change that adds, moves or renames something a person reaches updates the matching file in `.agents/skills/verify-plmail/features/` in the same pull request; pstack's `maintain-verification-skill` keeps the map honest.
