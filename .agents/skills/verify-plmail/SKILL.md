---
name: verify-plmail
description: Use when proving a plMail change works in a real browser, taking before/after screenshots for a PR, or reproducing a bug a person sees in plMail. Runs the commit on a disposable stack seeded with upstream's fictional demo mailbox, never Ben's real mail.
---

# Verify plMail

The surface is plMail in a person's browser, on a 1280×800 desktop and a 390×844 phone. One command proves a change:

```bash
V=.agents/skills/verify-plmail/verify
$V --base origin/main /mail/inbox           # before/after shots of a page
```

`verify` checks a commit, so commit first. It sends the commit through devops' `qa-exec` (`~/devops/docs/qa-exec.md`) to omarchybox, the core agent host with Docker, queued behind `qa-slots` so runs there go one at a time. On omarchybox, `drive.sh` brings up `compose.test.yaml` under a Docker project of its own on a free port in 8100-8199, seeds the demo user, drives it with Playwright from the commit's own `node_modules`, and tears the stack down. `qa-exec` fetches the evidence and removes its checkouts. A run takes about a minute and a half per side once the image and Composer caches are warm. Run it in the background.

## Data: demo only

The fork is public, so every shot must be safe to publish. The stack starts empty and is seeded with upstream's demo mailbox (`app:test:seed-demo`, `src/Service/Demo/`): fictional senders at `example.*` addresses, signed in as `demo@plmail.test` with the account `you@example.com`. Staging and the live plMail both hold Ben's real mail; keep them out of PR evidence. A shot that shows anything outside the seeded data stays out of the PR.

## Doctor

```bash
$V --doctor
```

Read-only. Every `ok` line means this checkout and omarchybox can take a run: `qa-exec` present, a committed tree, the checkout `qa-exec` pushes to on omarchybox (`~/dev/forks/pl_mail`), `qa-slots`, Docker (direct or through `sudo -n`), compose, node and `ss`. Run it first, and again whenever a run fails before driving.

Each side of a run also checks its own instance before driving and writes `doctor.txt`: the app container is running, `/login` answers 200, and the seed log names the demo user. A `FAIL` there stops that side before any drive.

## Drive

```bash
$V /mail/inbox /calendar/week                           # land on pages
$V --spec .agents/skills/verify-plmail/scratch/archive.spec.ts   # an interaction
$V --e2e 'tests/e2e/snooze-menu.spec.ts'                # the repository's own suite
$V --base origin/main --spec .agents/skills/verify-plmail/scratch/archive.spec.ts
```

Flags combine and repeat. `--base REV` serves REV on a second stack first and drives it the same way, for before shots; it drives the base with this commit's specs.

- **Pages**: each path gets a viewport shot, a full-page shot and the page's ARIA snapshot, at both widths, signed in as the demo user (`pages.spec.ts`).
- **A scratch spec** lives in `scratch/` beside this file, which Git ignores, and ends in `.spec.ts`. `verify` sends it along with the run. Import from `../support` and from `tests/e2e/support/`, which already name every control. Press, type and click as a person does:

```ts
import { mailRow } from "../../../../tests/e2e/support/config";
import { rowAction } from "../../../../tests/e2e/support/rows";
import { expect, settle, shot, test } from "../support";

const SUBJECT = "Bookshelf dimensions";

test("Open a conversation, archive it, find it in Archive", async ({ page, isMobile }) => {
  test.skip(isMobile, "row actions appear on hover; the phone archives by swipe (features/mail.md)");

  await page.goto("/mail/inbox");
  const row = mailRow(page, SUBJECT).first();
  await expect(row).toBeVisible();
  await settle(page);
  await shot(page, "inbox");

  await row.click();
  await expect(page.getByText("alcove is 182cm").first()).toBeVisible();
  await settle(page);
  await shot(page, "conversation open");

  await page.goto("/mail/inbox");
  await rowAction(mailRow(page, SUBJECT).first(), "Archive");
  await expect(mailRow(page, SUBJECT)).toHaveCount(0);
  await settle(page);
  await shot(page, "inbox after archive");

  await page.reload();
  await expect(mailRow(page, SUBJECT), "still gone after a reload").toHaveCount(0);

  await page.goto("/mail/archive");
  await expect(mailRow(page, SUBJECT).first()).toBeVisible();
  await settle(page);
  await shot(page, "archive");
});
```

- **The repository's suite**: `--e2e ARGS` runs `playwright test` with the repository's own config and ARGS, one worker, against the same stack. Its specs seed their own users, and it shoots only on failure, so use it to prove logic, and pages or a scratch spec for what a person sees.

`support.ts`'s `test` fails on an uncaught page error, a console error or a 5xx from plMail, and `pages.spec.ts` also fails a page that scrolls sideways.

[`features/README.md`](features/README.md) maps plMail's features. Each file gives the entry points, the recipe and the end state that proves it. When a change touches a mapped feature, drive every entry point its file lists.

## Evidence

`verify` names the folder when it starts: `.agents/skills/verify-plmail/evidence/<sha10>-<time>-<pid>/`, which Git ignores. Inside:

- `summary.txt`: one line per check, its exit code, then the file or folder to read.
- `after/` is the commit and `before/` is `--base`. Each holds `build.log`, `composer.log`, `up.log` (with every container's log on a failed start), `seed.log`, `doctor.txt` and `compose.txt` (the compose command, for poking at a failure by hand on omarchybox), then one numbered folder per drive, `NN-<pages|spec-name|e2e>/`.
- Each drive folder holds `command.txt`, `output.log`, `exit-code` and `test-results/<test>-<desktop|phone>/` with the numbered shots `NN-<name>.png`, plus a failure screenshot and `trace.zip` when a test fails.
- `npm.log`, `playwright-install.log` and `qa-exec.log`, the whole run's output.

Attach the shots to the PR as before/after pairs, upload them the way `bwm-prs` says, and name the folder and SHA in the PR body.

The standard for proof:

- Drive the person's path: a click, a key, typed text, a link the page shows.
- Capture the action and its result: a shot before the press and after it.
- Confirm a write on a second view: reload, or open the folder it moved to.
- A skipped test is not a pass. Report it with the reason in `output.log`.

## Cleanup

Each run cleans up after itself. `drive.sh` hands every file the containers wrote back to the host user, then runs `docker compose down -v` on its own project, on exit and on INT, TERM or HUP. `qa-exec` removes the checkouts and transfer refs. The evidence folder is what survives.

A `verify` that dies mid-run (a lost session, a killed background task) can leave a stack running. Find and remove only the stacks this skill started, named `plmail-verify-*`:

```bash
ssh omarchybox 'sudo -n docker compose ls --all --filter name=plmail-verify'
ssh omarchybox 'sudo -n docker compose -p <name> down -v --remove-orphans'
```

`qa-exec` prints the command that removes its retained checkout when it could not clean up. Run that too.

## Gotchas

- Ben's user on omarchybox is not in the `docker` group, so `drive.sh` falls back to `sudo -n docker`. Files the containers write into the checkout (`vendor/`, `assets/vendor/`) are root's until the cleanup's `chown`; a run killed before it leaves a checkout `qa-exec` cannot remove without `sudo`.
- Every run builds `pl_mail_test-app`, the same tag `npm run test:env:up` uses. `qa-slots` keeps verify runs from racing over it; someone running the test suite by hand on omarchybox at the same time can still swap the image under a run.
- Other plMail stacks on omarchybox, such as `plmail-flt148-*`, belong to other work. Leave them.
- `qa-exec` refuses the host it runs on. Run `verify` from agentmini or agentbox, or set `QA_HOST` to another host with Docker.
- The test stack runs `APP_ENV=test` with an in-memory message bus: sending goes nowhere and sync never runs. That is the intended sandbox; `features/compose.md` says how to drive a send.
