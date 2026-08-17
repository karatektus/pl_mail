import { defineConfig, devices } from "@playwright/test";
import { BASE_URL } from "./tests/e2e/support/config";

/**
 * plMail end-to-end configuration.
 *
 * The suite runs against the compose.test.yaml stack — the app's own FrankenPHP
 * image, which is what production runs and what CI runs:
 *
 *   npm run test:e2e:docker  — brings that stack up, then runs the suite
 *   npm run test:e2e         — runs the suite against a stack already up
 *
 * There is no second serving path any more. This config used to be able to
 * start a `php -S` dev server for CI, through a router script in
 * tests/e2e/support/, and every accommodation that server needed — a router to
 * stop index.php answering asset requests, a copy of the environment into
 * $_SERVER because the cli-server SAPI does not provide one, a php.ini the
 * runner had to be given by hand, and finally a restart loop because the thing
 * segfaulted mid-suite — was an accommodation for a server no user will ever
 * meet. CI now boots the same stack this file points at, so the suite proves
 * something about the app as shipped. See .github/workflows/e2e.yml.
 *
 * Every worker owns a dedicated user and signs in once for itself, through a
 * worker-scoped fixture in tests/e2e/support/test.ts. That is why specs import
 * `test` from there rather than from @playwright/test, and why there is no
 * `setup` project any more — one shared storage-state file cannot serve
 * workers that are logged in as different people.
 */

// How to reach the stack, resolved once, here.
//
// Some specs have to reach past the browser: seeding fixtures through the
// console, and — in mercure.spec.ts — stopping the hub to prove the stream
// comes back. Both go through these, so pointing the suite at a different
// stack is one variable rather than a hunt through the specs.
//
// The `-p` case is why this is shared rather than repeated. compose.test.yaml
// pins `name: pl_mail_test`, so a hardcoded `docker compose -f
// compose.test.yaml` is right exactly until somebody runs a second stack under
// another project name — a worktree, a second port — at which point it talks
// to the wrong project or to none at all. `stop` against a project that does
// not exist exits 0, so mercure.spec.ts spent that case silently not stopping
// the hub and then failing because the stream never dropped, and
// admin-queue.spec.ts seeded a database the browser was not looking at.
process.env.E2E_COMPOSE ??= "docker compose -f compose.test.yaml";
process.env.E2E_CONSOLE ??= `${process.env.E2E_COMPOSE} exec -T app php bin/console`;

// Storage state is per worker now and lives in tests/e2e/support/test.ts —
// there is no single path for the config to name.

export default defineConfig({
  testDir: "./tests/e2e",
  // Parallel across FILES, serial within one. Deliberately not fullyParallel:
  // several specs depend on the order of tests inside their own file — the
  // integration describes build on each other, twofactor enrols before it
  // signs in — and file granularity preserves that for free, without any
  // spec having to declare serial mode. It also means one file sees one
  // worker, so the per-worker user (tests/e2e/support/config.ts) is stable
  // for the whole file.
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // Was 1 everywhere the specs owned the database, because they all shared one
  // user and `seed-mail` wipes the mailbox it seeds. Each worker now owns a
  // separate user, so they no longer collide. CI gets 2 (ubuntu-latest is 2
  // vCPU on private repos); locally 4, above which the single app container
  // rather than the runner becomes the limit.
  workers: process.env.CI ? 2 : 4,
  reporter: process.env.CI
    ? [["github"], ["html", { open: "never" }]]
    : [["list"], ["html", { open: "never" }]],

  // A locator that will never match should fail in seconds, not stall for the
  // 30s test budget. Costs a green run nothing; makes a red one far quicker to
  // read.
  expect: { timeout: 5_000 },

  use: {
    baseURL: BASE_URL,
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    // Off, not "retain-on-failure": that still screencasts every test and then
    // throws the recording away on the ~97 that pass. The trace above is the
    // more useful artifact and costs nothing unless a test is retried. For a
    // local failure, re-run the one spec with `--video on`.
    video: "off",
  },

  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
      // integrations.spec.ts and mercure.spec.ts are handled separately below.
      testIgnore: /(integrations|mercure)\.spec\.ts/,
      // No `storageState` here, and no `setup` project: signing in is now a
      // worker-scoped fixture in tests/e2e/support/test.ts, because the path
      // has to differ per worker and project config is static.
    },
    {
      // The specs per-worker users cannot isolate.
      //
      // integrations: IntegrationProviderConfig and MailProviderConfig are both
      // unique on `provider` with no user column — genuinely install-wide
      // state. Two workers touching it would race, and it reaches further than
      // it looks: whether the onboarding wizard offers its integrations step
      // depends on what an admin has configured globally, so this spec can
      // change what onboarding.spec.ts sees.
      //
      // mercure: it stops and restarts the hub container to prove the stream
      // recovers on its own. There is one hub for the whole stack, so running
      // that alongside anything else takes live updates away from specs that
      // did not ask for it — which is exactly how it first showed up, as an
      // integrations failure that passed on its own.
      //
      // One worker, and `dependencies` so it starts only once everything else
      // has finished.
      name: "chromium-exclusive",
      use: { ...devices["Desktop Chrome"] },
      testMatch: /(integrations|mercure)\.spec\.ts/,
      dependencies: ["chromium"],
    },
  ],

  // No `webServer`. The app is the compose stack, and this config never starts
  // it — `npm run test:e2e:docker` does, and CI does, with the same command.
  //
  // Deliberate, and the reason the old one is worth remembering: a server this
  // config started was a server nothing else in the project used, so it needed
  // its own front controller, its own copy of the environment, its own php.ini
  // and eventually its own crash-restart loop, and a green suite said nothing
  // about the FrankenPHP the app actually ships on. Booting the stack outside
  // Playwright also means one running app for the whole suite rather than a
  // second one racing the first onto the same fixtures.
});
