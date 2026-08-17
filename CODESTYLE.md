# Code style and practice

The conventions this codebase actually follows, written down so another project can adopt them
without reverse-engineering them from the source. Everything here is extracted from working code —
the examples are real, and where a rule exists because something went wrong, the guide says what.

Drop this file into a new project, delete the sections whose stack it does not use, and keep the
first three. Those are the ones that make code look like this; the rest is Symfony-shaped detail.

**Contents**

1. [The governing idea](#1-the-governing-idea)
2. [Comments](#2-comments)
3. [Naming](#3-naming)
4. [PHP: language level and syntax](#4-php-language-level-and-syntax)
5. [Architecture and layering](#5-architecture-and-layering)
6. [Doctrine, entities and migrations](#6-doctrine-entities-and-migrations)
7. [Failure, errors and logging](#7-failure-errors-and-logging)
8. [Templates, JavaScript and CSS](#8-templates-javascript-and-css)
9. [Tests](#9-tests)
10. [Static analysis and tooling](#10-static-analysis-and-tooling)
11. [Documentation, changelog and commits](#11-documentation-changelog-and-commits)
12. [The condensed rules](#12-the-condensed-rules)

---

## 1. The governing idea

**Code records decisions, not just behaviour.** Anyone can read a function and learn what it does.
What they cannot recover is why it does that rather than the obvious alternative — and the obvious
alternative is usually what someone tries next, six months later, when they "simplify" it.

Three consequences run through everything below:

- **Write down the road not taken.** Not "this sets the label", but "through the service, not the
  column, because snoozing has to move the Inbox label off and propagate that outward".
- **Prefer one rule over a set of exceptions.** Every entity carries `updated_at`, including the
  tables written once — "one rule for every entity is worth more than the bytes an exception saves,
  and nothing has to decide which kind of entity it is looking at."
- **Make the invariant structural where you can, and documented where you cannot.** A unique
  constraint beats a comment saying not to insert duplicates. When the guard has to live in prose,
  the prose says what breaks without it.

Everything else here is downstream of that.

---

## 2. Comments

This is the most visible thing about the style, so it comes before syntax.

### 2.1 The class docblock states the subject, in a sentence

Open with what the thing *is*, in plain language, then the reasoning that is not visible from the
signatures.

```php
/**
 * Snoozing a conversation, and bringing it back.
 *
 * Snooze is archive-with-a-timer: the thread leaves the Inbox now and returns
 * to it later. Expressed in labels like everything else — Inbox off, Snoozed
 * on — so the "a message carries at least one label" invariant holds without a
 * second mechanism, and so the snoozed pile is reachable by every path that
 * already works on labels: the sidebar, a query, the unified feed.
 *
 * One service rather than one implementation per caller. The JMAP method and
 * the web UI both go through here, because a snooze that meant something
 * different depending on which client did it is worse than no snooze.
 */
final class ThreadSnoozeService
```

Note the shape: **what it is** (one line), **how it is modelled and why that model** , **what was
rejected**. A class whose docblock is `/** Service for snoozing threads. */` has not earned one.

### 2.2 Comments answer "why", never "what"

Bad, and not written here:

```php
// Loop over the messages and remove the inbox label
foreach ($messages as $message) {
```

Written here:

```php
// Before the labels move, so an IMAP job still sees the source folder.
// Archiving is what snoozing does to the provider; the difference is
// only that plMail remembers to undo it.
$this->propagator->archive($messages);
```

### 2.3 Name the bug the code prevents

When a line exists because of a defect, the comment says which one, concretely enough that a reader
can tell whether their change re-introduces it.

```php
{# `latest` comes from |last, which yields FALSE (not null) on an empty
   collection — so `is not null` passes and .isDraft blows up on a bool.
   A thread can legitimately end up with no messages (every message
   expunged provider-side), and this row is rendered for the whole inbox,
   so one such thread would 500 the entire list. #}
```

```js
/**
 * Absent, this clears the snooze. That used to be the *only* behaviour:
 * the signature took `until = null` as a second argument, and Stimulus
 * calls actions with the event alone, so the row's snooze button silently
 * unsnoozed instead of snoozing.
 */
```

### 2.4 Document deliberate absences

The strongest comments are the ones on things that are *not* there — an empty config block, a
missing feature, a check that was removed.

```neon
# ignoreErrors is deliberately empty.
#
# The rule usually wanted here — silencing "Property …::$id (int|null) is
# never assigned int" on Doctrine identifiers — is not needed: the
# phpstan-doctrine extension included above already understands that the
# ORM assigns identifiers by reflection, so the error never fires.
```

Without that, the next person adds the ignore, PHPStan fails the build on an unmatched pattern, and
half an hour goes somewhere.

### 2.5 Justify shape decisions in place

When something stays a method rather than becoming a property, or stays private rather than public,
say so where it lives:

```php
/**
 * Stays a method: it takes an argument and asks the label list about it, so
 * there is no single piece of state here to expose as a property.
 */
public function hasGmailLabel(string $label): bool
```

```php
/**
 * The only field here that is not public, and deliberately: Postgres writes
 * it, PHP never does, and no caller reads it either — the searches that use
 * it match on the column in SQL. It exists so the generated column is part
 * of the mapping and stays in the schema.
 */
private ?string $searchVector = null;
```

### 2.6 Config files carry essays

`phpstan.dist.neon`, `phpunit.dist.xml`, `compose.yaml` and `.env` are code. They get the same
treatment — every non-obvious value explains itself:

```xml
<!-- A file of its own: InstallEmptyInstallTest writes APP_PUBLIC_URL
     through the real service, and the generated secrets the test
     environment actually runs on must not be the thing it overwrites. -->
<server name="APP_SECRETS_FILE" value="var/secrets/phpunit.env" force="true" />
```

### 2.7 Section separators inside long classes

Two forms, both used sparingly and only in files long enough to need navigation:

```php
    // ---------------------------------------------------------------- helpers
```

```js
    // ── Private ───────────────────────────────────────────────────────────
```

### 2.8 Voice

Prose, in complete sentences, with an em dash where a clause needs it. No "TODO: fix this", no
"HACK", no jokes, no first person. Emphasis is `**bold**` in docblocks and used for the one clause
that carries the paragraph. Uppercase is reserved for the rare rule that must not be missed:

```php
/**
 * CREATION DEFAULT for Label::$isVisible — used only when the label
 * row is first created (LabelResolver::systemLabel). After creation,
 * visibility belongs to the user via the label settings and this
 * method must not be consulted again.
 */
```

---

## 3. Naming

### 3.1 Classes

Named for what they *are*, not for the pattern they implement. `ThreadSnoozeService`,
`MessageCategorizer`, `SenderResolver`, `LabelChangePropagator`, `EncryptionKeyProbe`,
`RawMessageResolver`. Suffixes carry meaning and are consistent:

| Suffix | Means |
|---|---|
| `…Repository` | Every query against one entity. Nothing else. |
| `…Service` | Owns what an operation *means*; shared by every caller. |
| `…Resolver` | Turns an ambiguous input into the one right object. |
| `…Builder` | Assembles a structure from parts. |
| `…Factory` | Constructs a configured object. |
| `…Propagator` | Pushes a local change outward to a provider. |
| `…Handler` | Messenger handler; pairs 1:1 with a `…Message`. |
| `…Message` | Messenger payload; immutable, ids only. |
| `…Command` | Console command. |
| `…Helper` | Stateless functions over a value type. |
| `…Probe` | Checks a precondition at boot and refuses to continue. |
| `…Model` | Behaviour base class an entity extends. |

Interfaces end in `Interface` (`AccountSyncerInterface`, `MailSenderInterface`), traits in `Trait`
(`TimestampableTrait`), enums are bare nouns (`LabelRole`, `MessageFlag`, `ThreadingMethod`).

### 3.2 Methods

Verb phrases that read as instructions: `snooze()`, `wake()`, `attachLabel()`, `matchingIds()`,
`extractionCandidates()`, `hasProviderFolder()`. Predicates start `is`/`has`/`can`. A method that
answers a question about a collaborator keeps the question shape: `accountOf($message)`.

Private helpers at the bottom, in call order, after the public surface.

### 3.3 Variables

Full words. `$messages`, `$account`, `$inboxMailbox`, `$snoozed`, `$until`. Not `$msg`, `$acc`,
`$i` (outside a genuine index loop), `$data`, `$result`, `$tmp`. A variable named `$body` holds a
body; if it holds a decoded JSON body, it is `$body` and the line above says what shape.

### 3.4 Files and directories

One class per file, PSR-4, path mirrors namespace. Directories are grouped by *domain area first,
then kind* under each top-level layer: `src/Service/Mail/`, `src/Service/Graph/`,
`src/Repository/Label/`, `src/Entity/Monitoring/`, `src/Domain/Enum/Mail/`. This scales: a new
provider adds `src/Service/Fastmail/` rather than eleven files into one flat directory.

Twig partials are prefixed with an underscore (`_thread_row.html.twig`) and full pages are not
(`inbox.html.twig`). Turbo Stream templates carry `.stream.html.twig`.

---

## 4. PHP: language level and syntax

Targets PHP 8.4+ and uses what that buys. 460 of 505 source files declare strict types; the
exceptions are legacy and being closed.

### 4.1 File preamble

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\MessageThread;
use Doctrine\ORM\EntityManagerInterface;
```

`declare(strict_types=1);` on every new file. Imports for everything including `DateTimeImmutable`,
alphabetical, no grouping by vendor, no trailing blank line games. Fully-qualified inline names
(`\LogicException`, `\Exception`) appear only for throwaway catches.

### 4.2 `final` by default, `readonly` where it fits

349 of 505 source files declare a `final` class. The default is final; non-final is a decision you
make when a subclass exists, and it is usually an entity extending its `…Model` base.

Value objects and Messenger payloads are `final readonly`:

```php
final readonly class IngestedMessage
{
    public function __construct(
        public Message  $message,
        public Account  $account,
        public ?string  $rawSource = null,
    ) {
    }
}
```

`final` has a real payoff here and the tests lean on it: when every collaborator is final, mocking
is impossible, so tests are written against the real container instead. See §9.3.

### 4.3 Constructor injection, promoted, aligned, `readonly`

```php
public function __construct(
    private readonly MessageRepository       $messageRepository,
    private readonly MessageThreadRepository $threadRepository,
    private readonly LabelRepository         $labelRepository,
    private readonly ThreadStatusUpdater     $status,
    private readonly ThreadSnoozeService     $snoozeService,
) {}
```

Always promoted, always `private readonly` for services and `public` for DTO fields. One parameter
per line with a trailing comma. **Variable names aligned in a column** when there are three or
more — the same alignment rule applies to array literals and to consecutive assignments:

```php
return [
    'extAfterId'       => $afterId,
    'extCalendarTypes' => self::CALENDAR_TYPES,
    'extMeetingHeader' => GraphMessageBuilder::MEETING_TYPE_HEADER,
    'extJsonLd'        => '%application/ld+json%',
];
```

```php
$io        = new SymfonyStyle($input, $output);
$accountId = $input->getArgument('account-id');
```

Empty constructor bodies are `) {}` on one line (86 files do this). A constructor that calls
`parent::__construct()` obviously gets a real body.

### 4.4 Public properties, asymmetric visibility, no getters

Getters and setters are not written. There are eight `public function get…` methods in the entire
`src/Entity` tree, and each is doing something a property cannot.

State is public. State the outside must not write uses PHP 8.4's asymmetric visibility (88 sites):

```php
#[ORM\Id]
#[ORM\GeneratedValue]
#[ORM\Column]
public private(set) ?int $id = null;

#[ORM\Column]
public private(set) DateTimeImmutable $createdAt;

/** @var Collection<int, Label> */
public private(set) Collection $labels;
```

Mutation that has to stay coherent goes through a named method (`addLabel()`, `removeFlag()`), not
through a setter. The rule for whether something is a property or a method:

> A method that takes an argument and asks the state a question stays a method. A method that
> returns one piece of state and takes nothing becomes a property.

### 4.5 Explicit, Yoda-side comparisons

Comparison against a literal puts the literal first, and comparisons are always identity-strict and
always spelled out. 540 `null === …` against 16 the other way; 511 `true === …`.

```php
if (null === $label || $label->usr !== $this->getUser()) {
if (true === $label->isSystem) {
if (false === $this->labels->contains($label)) {
if ([] === $messages) {
if (0 === count($ids)) {
$attach = (true === array_key_exists('attach', $body) && true === $body['attach']);
```

`if ($label->isSystem)` is not written even though the property is a `bool`. The point is not the
type — it is that every condition reads as an explicit claim about a specific value, and a truthiness
bug (`"0"`, `[]`, `0.0`) can never hide in one.

### 4.6 Typed constants

Every constant declares its type — 47 `private const array`, 112 `private const int`, 62
`private const string`, and the public counterparts.

```php
/**
 * Content types that mean "there is an invite in here".
 *
 * @var list<string>
 */
private const array CALENDAR_TYPES = ['text/calendar', 'application/ics'];
```

Magic numbers and repeated literals become named constants at the top of the class, with a docblock
saying what the value means, not what it is.

### 4.7 Enums carry behaviour

An enum is not a list of strings; it is where the per-case rules live, as `match` tables (69 in the
codebase). Sorting, display names, capability predicates and mappings from other enums all belong on
the enum rather than in a `switch` scattered through services.

```php
enum LabelRole: string
{
    case Inbox = 'inbox';
    // …

    public function hasProviderFolder(): bool
    {
        return self::Snoozed !== $this;
    }

    public static function fromSpecialUse(MailboxSpecialUse $specialUse): self
    {
        return match ($specialUse) {
            MailboxSpecialUse::INBOX  => self::Inbox,
            // …
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Inbox => 0,
            self::Sent  => 10,
            // After Archive, before custom labels: it is a system place, but
            // the least-visited one.
            self::Snoozed => 60,
        };
    }
}
```

`match ($this)` over every case with no `default`, so adding a case is a compile-time-ish error
rather than a silent fallthrough.

Individual cases get their own docblock when they are exceptional — `Snoozed` above has one
explaining that it is the only role with no provider counterpart.

### 4.8 Strings and formatting

`sprintf()` for anything with a placeholder (332 uses), not interpolation:

```php
$io->error(sprintf('Account %s not found.', $accountId));
$io->text(sprintf('→ dispatched sync for %s (#%d)', $account->email, $account->id));
```

Nowdoc (`<<<'SQL'`) for embedded SQL, indented to the code and closed at the same indent. Never
built by editing a finished query:

> Built by concatenation rather than by editing a finished query — the first version pulled the
> LIMIT off with `str_replace` and silently stopped matching the moment the heredoc's indentation
> changed.

### 4.9 Control flow

Early return over nesting. A guard clause, a blank line, then the work:

```php
public function wake(MessageThread $thread): void
{
    $messages = $thread->messages->toArray();

    if ([] === $messages) {
        $thread->snoozedUntil = null;

        return;
    }

    // …
}
```

A `return` is preceded by a blank line whenever it follows a statement. Blank lines separate
paragraphs of logic — setup, the loop, the finish — and each paragraph usually has a comment or is
short enough not to need one.

### 4.10 Docblocks for types PHP cannot express

PHPDoc is for generics and array shapes, never to restate the signature:

```php
/** @return list<Message> */
/** @param iterable<Message> $messages */
/** @var array<string,string|list<string>>|null */
/** @var Collection<int, MessagePart> */
```

`list<T>` when the array is a packed list — it is, and the code keeps it so with `array_values()`.

### 4.11 Formatting mechanics

From `.editorconfig`, which is authoritative:

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[{compose.yaml,compose.*.yaml}]
indent_size = 2

[*.md]
trim_trailing_whitespace = false
```

Four spaces everywhere except YAML compose files. Prose in comments and Markdown wraps at ~80–100
columns; code does not get hard-wrapped mid-expression to satisfy a limit.

---

## 5. Architecture and layering

### 5.1 The tree

```
src/
  Command/        Console commands, grouped by area (Mail/, Imap/, Push/, Maintenance/…)
  Controller/     HTTP actions, grouped by area (Mail/, Admin/, Settings/, Webhook/…)
  Domain/         The vocabulary: Enum/, DTO/, Interface/, Exception/, Model/, Trait/, Helper/, Filter/
  Entity/         Doctrine entities, grouped by area (Mail/, Label/, User/, Calendar/, Monitoring/)
  Form/           Symfony form types
  Infrastructure/ Framework-facing wiring: Doctrine/, Messaging/, Event/, Scheduler/, Setup/, Encryption/
  Jmap/           A protocol implementation, self-contained, with its own Method/, Mapper/, Protocol/
  Repository/     One per entity, mirroring Entity/'s grouping
  Security/       Authenticators, user provider, two-factor
  Service/        Everything that decides something. The largest layer, by design.
  Twig/           Extensions and runtime helpers (Vendor/ for lifted upstream code)
```

`Domain/` holds no framework types. `Infrastructure/` holds the framework glue. `Service/` is where
meaning lives and is deliberately the biggest directory — 151 of 505 files.

### 5.2 Controllers hold actions and little else

A controller resolves input, authorises, delegates, and renders. It does not decide what an
operation means. The commit that established this is literally titled *"Leave controllers with their
actions and little else"*.

The pattern, in full:

```php
#[Route('/status/{type}/{id}', name: 'app_status_', requirements: ['type' => 'message|thread', 'id' => '\d+'])]
#[IsGranted('IS_AUTHENTICATED')]
class ThreadStatusController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    #[Route('/archive', name: 'archive', methods: ['POST'])]
    public function archive(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);

        $this->status->archive($messages);

        return $this->renderTurboStream('thread/status/_archive.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->thread,
        ]);
    }
```

- Route attributes on the class for the shared prefix and name prefix, on the method for the rest.
- `methods:` always declared, and `requirements:` for anything a route segment must never be — an
  unconstrained `{type}` is a `default` arm nobody wrote.
- `#[IsGranted]` at class level for the coarse question (is this a signed-in user, an admin);
  per-action overrides only when they differ.
- **Ownership is a voter, never a comparison in the controller.** `OwnershipVoter` answers
  `own` for every entity a user owns, and controllers ask it — `$this->denyAccessUnlessGranted(
  OwnershipVoter::OWN, $subject)` where refusing is the answer, `$this->isGranted(...)` where the
  action skips or returns instead. This replaced nineteen private helpers across eighteen files,
  under six different names; all of them were correct, and that was the problem — each was a fresh
  derivation of one sentence, so the twentieth was a fresh chance to get it wrong.
- Where several actions share a resolver, the guards go *in the resolver*, not in each action —
  that part of the old pattern was right and is kept. `resolveMessages()` above checks CSRF and
  ownership for all six of its callers, so an action cannot forget either.
- Rules that are not ownership stay in the controller, because they are a different question:
  LabelController still refuses a *system* label, ComposeController still refuses an *already sent*
  draft. Those are about what the thing is, not whose it is.
- CSRF as `ChecksCsrf::assertCsrf()`, one copy, taking the token from `_token` or `X-CSRF-Token`.
- Shared response mechanics as one private method (`renderTurboStream()`).

### 5.3 Every query lives in a repository

No `createQueryBuilder` outside `src/Repository`, no `EntityManager::getRepository()->findBy()`
inline in a service when the query means something. If a query is used twice, it is a named
repository method; if it is used once but has a reason, it is a named repository method with a
docblock explaining the reason.

Raw DBAL is allowed, and when it appears it says why it had to be raw:

```php
/**
 * Raw DBAL because none of the three is expressible otherwise: jsonb key
 * existence has no DQL operator and no registered function, and the rest
 * is an EXISTS correlated to a second entity. Written as jsonb_exists()
 * rather than the `?` operator that means the same thing — DBAL reads a
 * bare `?` as a positional placeholder and refuses the query …
 */
```

Shared SQL fragments become class constants so two call sites cannot drift:

> This is the WHERE both candidate queries share, so a change to what counts as a candidate cannot
> land in one and not the other.

### 5.4 One service per concept, shared by every caller

When two entry points (a web controller and an API method, a command and a message handler) do the
same thing, they call the same service. This is stated as a rule and enforced by comments at both
ends:

```php
// Through the service, not $thread->snoozedUntil: snoozing has to move the
// Inbox label off and propagate that outward. Writing the column here
// is what this endpoint used to do, and it left the conversation
// sitting in the inbox … Thread/set goes through the same service for the
// same reason. The one deliberate difference is above: a form post gets the
// "in 1 day" fallback on an unparseable date, where the JMAP method
// refuses it — see ThreadSetMethod::snoozeDate().
```

Where the callers genuinely must differ, the difference is named and both sides point at each other.

### 5.5 Interfaces for the axis that varies

`src/Domain/Interface/` holds one interface per pluggable axis — `AccountSyncerInterface`,
`MailSenderInterface`, `IntegrationDriverInterface`, `PostIngestStepInterface`,
`EventExtractorInterface`. Implementations live under the provider's own directory
(`Service/Gmail/`, `Service/Graph/`, `Service/Imap/`), and a registry resolves them
(`MailSenderRegistry`). Adding a provider means adding a directory, not editing a `switch`.

Pipelines are built from a tagged-interface list (`PostIngestPipeline` over `PostIngestStepInterface`)
so a step is a class, not a branch.

### 5.6 DTOs cross boundaries

Anything passing between layers with more than two fields is a `final readonly` DTO under
`Domain/DTO/`, not an array. The docblock explains what it carries that its members do not obviously
imply:

```php
/**
 * Carries the owning account rather than letting the pipeline read it off the
 * message, because the two are not always the same one: under Gmailify a Gmail
 * account fetches mail addressed to a sibling …
 */
```

The commit for this is *"Send the compose context whole instead of unpacking it four times"*.

### 5.7 Async work

Message and handler pair 1:1 by name — `SyncAccountMessage` / `SyncAccountMessageHandler`. Messages
are `readonly`, carry **ids and scalars only** (never entities), and have a docblock saying who
dispatches them and what the handler resolves:

```php
/**
 * General/scheduled/push-driven sync of an entire account.
 * The handler resolves the right AccountSyncerInterface for the provider.
 */
readonly class SyncAccountMessage
{
    public function __construct(
        public int $accountId,
    ) {}
}
```

Recurring work is declared in one place (`Infrastructure/Scheduler/MaintenanceSchedule`), and the
commands it fires are also runnable by hand.

### 5.8 Console commands

```php
#[AsCommand(
    name: 'app:mail:sync',
    description: 'Dispatch an account-level sync (IMAP or Gmail) for one or all active accounts',
)]
final class MailSyncCommand extends Command
```

- Names are `app:<area>:<verb>`, colon-separated, consistently grouped.
- `SymfonyStyle` for all output; `$io->error()`, `$io->text()`, `$io->success()`.
- Return `Command::SUCCESS` / `Command::FAILURE`, never a bare int.
- Arguments optional where a sensible "do all of them" default exists.
- Every command is listed in `CONTRIBUTING.md` with a one-line description. That table is part of
  the definition of done.

---

## 6. Doctrine, entities and migrations

### 6.1 Entities

Attributes only, no YAML/XML mapping. Column types via `Types::` constants, enums via `enumType:`.
Relations declare `onDelete` at the join column so the database enforces what the code assumes.

```php
#[ORM\ManyToOne(inversedBy: 'messages')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
public Account $account;

#[ORM\Column(nullable: true, enumType: MessageCategory::class)]
public ?MessageCategory $category = null;
```

Collections are initialised in the constructor and exposed `public private(set)`, with add/remove
methods keeping both sides of the relation in step:

```php
public function addMessagePart(MessagePart $messagePart): static
{
    if (!$this->messageParts->contains($messagePart)) {
        $this->messageParts->add($messagePart);
        $messagePart->message = $this;
    }

    return $this;
}
```

Mutators return `static` so they chain.

### 6.2 Indexes and constraints are commented

Every index and unique constraint carries a comment saying what it protects and **why the columns
are in that order**:

```php
// Remote-id uniqueness per account: one row per Gmail/Graph message. The
// batch handlers dedup in PHP before inserting, but that check is a read on
// stale data — this is the guard that actually holds when batches overlap
// across runs or retries. Provider id leads the column list so the indexes
// also serve the id-only lookups (findOneBy(['gmailId'|'graphId'])).
#[ORM\UniqueConstraint(name: 'uniq_message_gmail_id_account', columns: ['gmail_id', 'account_id'])]
```

Constraint names are `uniq_<table>_<columns>` and `idx_<table>_<columns>`, snake_case, spelled out.

### 6.3 Timestamps are a trait, and there are no exceptions

```php
trait TimestampableTrait
{
    #[ORM\Column]
    public private(set) DateTimeImmutable $createdAt;

    #[ORM\Column]
    public private(set) DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now = new DateTimeImmutable();

        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function bumpUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
```

Three things worth copying wholesale:

- **Non-nullable, no default.** "A row that exists has both, and a reader should not be made to
  check for a null the database cannot contain. Reading one before the entity is persisted throws,
  which is the right answer to a genuine mistake."
- **`??=` on `createdAt`**, so a backfill or a test can supply its own instant — and the docblock
  explains that `??=` reads through `isset()`, which answers false for an uninitialised typed
  property rather than throwing.
- **A test enforces the requirement the trait cannot.** The trait needs
  `#[ORM\HasLifecycleCallbacks]` on the class; without it Doctrine silently does nothing, so
  `TimestampableTest` checks every adopting entity for the attribute.

Dates are `DateTimeImmutable`, always. Never `DateTime`.

### 6.4 Migrations

Generated, then edited to carry a docblock that is a paragraph of reasoning, plus a one-line
`getDescription()` written in the same imperative voice as a commit subject:

```php
/**
 * The last five entities that tracked their own timestamps take the shared
 * trait, which means the five tables that only had created_at gain updated_at.
 *
 * Added nullable, backfilled from created_at, then made NOT NULL. Adding a NOT
 * NULL column with no default fails outright on a table that already has rows,
 * and log_entry and jmap_change_log always do — jmap_change_log is the busiest
 * table in the schema, because every mail mutation writes one.
 */
final class Version20260803113000 extends AbstractMigration
{
    private const array TABLES = [/* … */];

    public function getDescription(): string
    {
        return 'Give the remaining five timestamped tables an updated_at';
    }
```

Migrations run automatically on boot, so **anything irreversible is called out in the changelog**.
Backfills follow the nullable → populate → NOT NULL sequence rather than assuming empty tables.

---

## 7. Failure, errors and logging

### 7.1 Exceptions model the recovery, not the cause

The exception hierarchy is shaped by what the caller should *do*:

```
GmailApiException
  ├── GmailPermanentException   — stop, this will never work
  └── GmailThrottledException   — back off and retry
GraphApiException
  ├── GraphResyncRequiredException
  └── GraphThrottledException
```

A caller catching `GmailThrottledException` knows to retry; a caller catching
`GmailPermanentException` knows not to. That distinction is worth two classes.

### 7.2 Refuse loudly where refusing is cheap, warn where it is not

The clearest instance in the codebase, and a pattern worth stealing: `EncryptionKeyProbe` runs at
container start and **refuses to boot the web server** when the encryption key does not match stored
data — rather than writing data half the fleet cannot read. But the same probe only *warns* under
the console:

> The probe is deliberately fatal only when starting the server — a console invocation warns and
> continues, because refusing it would block the very command that repairs the situation.

Fail-fast is not a blanket rule. It is applied where the failure is worse than the stop, and the
exception is documented where it lives.

### 7.3 Health endpoints report verdicts, not data

```
GET /healthz — unauthenticated, because Docker healthchecks and uptime monitors hold no session.
```

- 503 only for the failure where serving is genuinely impossible (database down). A backed-up queue
  stays 200: "mail is late, not gone, and restarting the container would not help."
- **No counts, no addresses, no version.** Anyone who can reach the port can read it. Numbers live
  behind the admin role.
- A test asserts that shape, so an addition that leaks something fails the suite.

### 7.4 Logging

A constructor-injected `LoggerInterface $logger`, never a static call or a facade. Messages are
prefixed with the subsystem and written as statements of what happened:

```php
$this->logger->warning('GmailPush: rejected notification with bad or missing token');
$this->logger->warning('GmailPush: no emailAddress in payload');
$this->logger->warning('GmailPush: unparseable envelope');
```

Levels mean something: `warning` for "this request could not be honoured and here is why",
`error` for "something is broken". A log line that fires per-event on a hot path gets rate-limited
rather than deleted — an unactionable message repeated several times a second buries everything
else.

---

## 8. Templates, JavaScript and CSS

### 8.1 Twig

**Header comment first**, describing what the template renders and what variables it accepts:

```twig
{#
Renders one row in the message list.

Accepts either a MessageThread (`thread`) or a Message (`message`);
everything below normalises onto the same set of variables so the markup
is shared.

Draft handling: a row opens the compose dock instead of the reading pane
only when the row IS the draft …
#}
```

**Normalise, then render.** A partial that accepts more than one shape resolves everything into a
common set of `{% set %}` variables at the top, then has exactly one block of markup. Branching in
the markup is the thing this avoids.

Other rules:

- Defaults declared explicitly at the top: `{% set draft_scope = draft_scope|default(false) %}`.
- All user-facing text through `|trans` with dotted keys (`'thread_row.no_subject'|trans`). No
  literal English in a template.
- Comments inside the logic block explain the same class of thing PHP comments do — see §2.3, whose
  example is from this file.
- Partials underscore-prefixed; Turbo Stream responses in their own `.stream.html.twig`.

### 8.2 Translations

Two domains, and the distinction has bitten:

```yaml
# Constraint messages. Symfony translates validation output in the `validators`
# domain, not `messages` — a key put in messages.*.yaml renders as the raw key,
# which is how "setup.install.password_too_short" ended up on screen.
```

`messages.<locale>.yaml` is nested by area; `validators.<locale>.yaml` is flat with dotted keys.
Message text is a complete sentence with an em dash where it explains itself:

> `Enter the client secret too — a client ID on its own cannot sign anyone in.`

### 8.3 Stimulus controllers

```js
import { Controller } from "@hotwired/stimulus";

/**
 * Handles per-row status actions in the message list.
 *
 * Values:
 *   id       — entity ID (thread or message)
 *   type     — "thread" (default) | "message"
 *
 * Routes used:
 *   thread  → /thread/{id}/status/{action}
 *   message → /message/{id}/status/{action}
 */
export default class extends Controller {
    static values = {
        id:   Number,
        type: { type: String, default: "thread" },
    };
```

- Anonymous `export default class extends Controller` — the filename is the identity.
- Docblock lists **values, targets and routes** as an aligned table. This is the controller's
  contract with the template.
- Action parameters read via destructuring with defaults:
  `const { snoozeUrl, until = null } = event.params;`
- Private methods use real `#private` syntax and live below a `// ── Private ──` separator.
- 4-space indent, double quotes, semicolons, trailing commas — same as the PHP side where the
  language allows.

### 8.4 CSS

Design tokens, not literal colours, and the token file explains its own mechanics:

```css
/* ═══ Palette channels + runtime knobs ══════════════════════════════════════
   Unlayered on purpose: these are variable definitions, not utilities, and
   unlayered declarations outrank Tailwind's cascade layers.
   Channels are stored as space-separated RGB triplets so alpha stays
   composable via rgb(var(--x) / <alpha>).
   ═══════════════════════════════════════════════════════════════════════════ */
:root {
    --rgb-surface:      255 255 255;
    --rgb-ink:           39  39  42;
    --rgb-accent:        37  99 235;
```

- Values aligned in columns, numerically right-aligned.
- Semantic names (`--rgb-ink-muted`, `--rgb-danger`), never `--gray-3`.
- A token that is unusual gets a paragraph — the mail-body sheet colour has one explaining that
  senders authored their HTML against a light background, so rendering it dark misrepresents what
  was sent.
- Dark mode as an explicit variant (`@variant dark (&:where(.dark, .dark *))`), tokens re-declared
  rather than colours overridden per component.

---

## 9. Tests

### 9.1 Layout and naming

PHPUnit mirrors `src/` exactly: `src/Service/Mail/ThreadSnoozeService.php` →
`tests/Service/Mail/ThreadSnoozeServiceTest.php`. Namespace `App\Tests\…`. Classes are `final`.

**Test method names are sentences about behaviour**, not about the method under test:

```php
public function testSnoozeMovesTheThreadOutOfTheInbox(): void
public function testAbsentWorkersAreUnknownRatherThanUnhealthy(): void
public function testUnreadFollowsSeenAtRatherThanTheImapSeenFlag(): void
public function testAnEmptyTransportReportsNothingRatherThanZero(): void
public function testATokenlessPostDoesNotWrite(string $path, array $payload): void
public function testItLeaksNothingAboutTheInstance(): void
```

The `X rather than Y` form is used deliberately: it names the wrong behaviour the test exists to
prevent. Assertions carry messages where the failure would otherwise be cryptic:

```php
self::assertFalse($message->labels->contains($inbox), 'Inbox label should be gone');
```

### 9.2 The test class docblock states the claim

```php
/**
 * Snooze is a label move, not a column write.
 *
 * That distinction is the whole subject here. The web endpoint used to set
 * snoozedUntil directly and nothing else, which left the conversation sitting
 * in the Inbox — locally and at the provider — while the row disappeared from
 * the list, until the sweep "woke" a thread that had never left.
 *
 * Against a real container and a real database rather than doubles, for two
 * reasons. Every collaborator this service takes is `final`, so none of them
 * can be doubled anyway; and the behaviour worth pinning is the one that
 * emerges from them together — labels moved, an outward propagation queued —
 * which a set of mocks would assert into existence rather than observe.
 */
```

One claim, the bug it guards, and the reason for the testing strategy.

### 9.3 Real container over mocks

The default is `KernelTestCase` with the real services and a real database, wrapped in a transaction
that is never committed:

```php
protected function setUp(): void
{
    self::bootKernel();

    $container = self::getContainer();
    $this->em              = $container->get(EntityManagerInterface::class);
    $this->service         = $container->get(ThreadSnoozeService::class);

    // Never committed, so the suite leaves nothing behind and can be run
    // repeatedly against the same database.
    $this->connection->beginTransaction();
}

protected function tearDown(): void
{
    if (true === $this->connection->isTransactionActive()) {
        $this->connection->rollBack();
    }

    parent::tearDown();
}
```

Seeding is done by private helpers on the test class (`seedAccount()`, `inboxThread(2)`), named for
what they produce.

Data providers via `#[DataProvider('someCases')]`, used for table-driven cases (header parsing,
address parsing, charset handling, endpoint lists).

### 9.4 A test must be able to fail

Stated as a rule and practised:

> Each of these was mutation-checked: the fix reverted, the suite run, the failure confirmed. A test
> that passes against the bug it names is worse than no test, because it is also a claim.

Coverage is chosen by risk, not by line count — the criterion used here is "a bug in this is
invisible until somebody notices mail is in the wrong place."

### 9.5 Suite configuration is strict

```xml
failOnDeprecation="true"
failOnNotice="true"
failOnWarning="true"
```

Plus `restrictNotices` / `restrictWarnings` on the source block. A warning is a failure.

### 9.6 Browser tests

Separate directory (`tests/e2e/`), excluded from the PHPUnit suite explicitly rather than by relying
on file extensions. The conventions that matter:

- **Specs import `test` from a local support module**, not from `@playwright/test`, and that module
  re-exports everything else so a spec has one import line. The wrapper exists to give each parallel
  worker its own user and its own signed-in session.
- **Per-worker fixtures over a global setup file**, because a single storage-state file only works
  with one worker.
- **Locators by role and accessible name**
  (`getByRole("button", { name: "Create label" })`), scoped to a container locator. Not CSS
  selectors, except for structural ids that are part of the contract (`#modal-backdrop`).
- **Each spec reseeds its own fixtures** in `beforeEach`, so tests are independent and re-runnable.
- **Regression guards are labelled as such**, with the bug they hold:

  ```js
  // Regression guard: the form must actually render inside the modal
  // frame. Without the <turbo-frame id="modal"> wrapper Turbo finds
  // nothing to swap and the dialog stays on the spinner.
  ```
- Anything that makes the suite slow is measured before it is accepted, and the reasoning is written
  down — the CONTRIBUTING section on this names the change that took the run from 255s to 138s and
  why it is easy to undo by accident.

---

## 10. Static analysis and tooling

### 10.1 PHPStan: a level you can hold, plus a shrinking baseline

```neon
parameters:
    level: 5
    paths:
        - ./
```

The config's own comment is the policy:

> Level 5 with everything already failing captured in a baseline. The alternative — level 0 with no
> baseline — reports nothing interesting, and level 9 buys a fortnight of triage before it finds its
> first real bug. […] **The baseline is a debt ledger, not a licence.** It shrinks; raise the level
> when it is small enough that doing so is a day's work rather than a fortnight's.

Further rules that generalise:

- **Analyse the whole tree**, not just `src/`. `bin/`, `config/` and `public/` hold real code that
  breaks the same way. Exclusions are enumerated with a reason each.
- Paths that may legitimately be absent are marked `(?)`, because PHPStan treats an
  excludePath matching nothing as a config error.
- **`ignoreErrors` stays empty.** Suppression is a decision that needs a paragraph; if it has one,
  it usually turns out the fix was cheaper.
- `treatPhpDocTypesAsCertain: false`, because docblocks here are prose as much as types and a stale
  `@var` otherwise produces a stream of false "always false" reports.

### 10.2 Composer scripts are documented

```json
"scripts-descriptions": {
    "test": "Run the PHPUnit suite (unit tests). Browser end-to-end tests live in tests/e2e and run via `npm run test:e2e`.",
    "stan": "Run PHPStan at level 5 against src/ and tests/, ignoring what phpstan-baseline.neon already records."
}
```

Every task a contributor might run has a named script and a description, and both suites are listed
in `CONTRIBUTING.md` with the exact commands.

### 10.3 Version pinning

Framework packages pinned to one minor (`8.1.*`) via `extra.symfony.require`; the Node version lives
in `.nvmrc` and CI reads *that file* rather than repeating the number. One place per fact.

---

## 11. Documentation, changelog and commits

### 11.1 The document split

| File | Audience | Contains |
|---|---|---|
| `README.md` | Someone deciding whether to run it | What it is, what it can do, how to install |
| `CONTRIBUTING.md` | Someone changing it | Setup, tests, architecture notes, command reference, roadmap |
| `CHANGELOG.md` | Someone upgrading | What changed per release, and what it costs them |
| `CODESTYLE.md` | Someone writing in it | This file |

`README` never explains internals; `CONTRIBUTING` never explains what the product is.

### 11.2 Documentation explains failure modes, not just steps

The distinguishing feature of `CONTRIBUTING.md` here is that each section ends with what goes wrong.
Not "run this command", but:

> They drifted apart once before and it cost real data: the `.dist` was missing the
> `app_attachments`, `app_raw` and `app_uploads` mounts, so anyone starting from a fresh clone got
> attachment downloads that 404'd and blob data that vanished on container recreate.

Sections literally titled **"Things that bite"** collect the traps. A roadmap item that is done gets
ticked, with an explicit note that under-reporting is worse than no roadmap.

### 11.3 Changelog entries lead with the user's symptom

Bold the symptom in the user's words, then explain the cause and what changed:

```markdown
### Fixed

- **An Outlook account could stop syncing entirely.** `meetingMessageType` is
  declared on Graph's event-message type, not on the base message, and naming it
  unqualified does not get ignored — the whole `$select` is rejected, every
  message in the batch answers 400, and nothing arrives. It is asked for through
  the cast now, and a mailbox that refuses even that gets one retry without it
  and is remembered, rather than never syncing again.
```

Each release states its migration and deployment impact up front —
`No schema change, no deployment change.` — because migrations run automatically on boot.

### 11.4 Commit messages

**Subject: an imperative sentence describing the change's effect, no prefix, no scope, no ticket.**

```
Make a Graph folder move replace the location, not add to it
Stop believing a charset label the bytes disprove
Say why a message is in the tab it is in
Leave controllers with their actions and little else
Put every query in a repository, and make the rest say why they exist
Cover the decisions that fail without saying anything
```

Not `fix(graph): folder move`. Not `Fixed bug`. The subject is what the codebase can now do, or
stop doing, written as an instruction to it. `Stop …`, `Make …`, `Say …`, `Put …`, `Give …`,
`Leave …` are the recurring openers.

**Body: the reasoning, and what fell out.** Wrapped at ~72 columns, prose, with an aligned table when
the change touches a set of files:

```
Ninety-two tests over five classes, chosen on one criterion: a bug here
is invisible until somebody notices mail is in the wrong place.

  MessageCategorizer   the cascade, and the orderings that matter — a
                       mailing list carrying List-Unsubscribe is a forum,
                       a known correspondent beats every bulk header.
  SearchQueryParser    what happens to operators that cannot be honoured,
                       since every way of getting that wrong silently
                       widens the search to the whole mailbox.

Writing them turned up one more bug. A word ending in a colon — "Re:" —
was parsed as a half-typed operator and dropped …
```

Incidental discoveries go in the body — "Two bugs fell out" — rather than being silently included or
split into commits nobody can review.

Release commits are `Record vX.Y.Z`.

---

## 12. The condensed rules

Paste this into `CLAUDE.md`, `AGENTS.md`, or a PR template.

**Comments**
1. Every class gets a docblock: what it is, how it is modelled, what was rejected.
2. Comments say *why*. If a comment restates the code, delete it.
3. When code exists because of a bug, name the bug.
4. Document deliberate absences — the empty config, the check that is not there.
5. No TODO/HACK/FIXME. Either fix it or write down why it stays.

**Structure**
6. Controllers resolve, authorise, delegate, render. Nothing else.
7. Every query lives in a repository. Raw SQL says why it had to be raw.
8. One service per concept; every caller goes through it. Differences between callers are named at
   both ends.
9. Interfaces for the axis that varies; implementations under the provider's own directory.
10. DTOs (`final readonly`) cross boundaries, not arrays.
11. Messenger payloads are readonly and carry ids, never entities.

**PHP**
12. `declare(strict_types=1);` on every file. PSR-4, one class per file.
13. `final` by default; `final readonly` for value objects.
14. Constructor injection, promoted, `private readonly`, one per line, aligned, trailing comma.
15. Public properties with `public private(set)` where the outside must not write. No getters or
    setters.
16. Comparisons are explicit and literal-first: `null === $x`, `true === $flag`, `[] === $list`.
17. Constants are typed (`private const array`) and named for meaning.
18. Enums own their per-case rules as exhaustive `match` tables with no `default`.
19. `sprintf()` over interpolation. `DateTimeImmutable` over `DateTime`.
20. Guard clauses and early returns; blank line before every `return` that follows a statement.
21. Docblocks only for what the signature cannot say: `list<T>`, `array<K,V>`, `Collection<int,T>`.
22. Four spaces, LF, UTF-8, final newline, no trailing whitespace.

**Data**
23. Timestamps come from one trait, on every entity, with no exceptions.
24. Every index and unique constraint is commented, including why the columns are in that order.
25. Migrations carry a reasoning docblock and a one-line imperative description; backfills go
    nullable → populate → NOT NULL.

**Failure**
26. Exception classes model what the caller should do (permanent vs. throttled), not what went wrong.
27. Refuse loudly where refusing is cheaper than the corruption; warn where refusing would block the
    repair.
28. Unauthenticated endpoints report verdicts, never data. A test asserts the shape.

**Frontend**
29. Twig partials open with a header comment listing their inputs, normalise to common variables,
    then render one block of markup.
30. All user-facing text goes through translation keys. Validation messages live in the `validators`
    domain.
31. Stimulus controllers document values, targets and routes in the class docblock; private methods
    use `#`.
32. Colours are semantic tokens, declared once; components never carry literals.

**Tests**
33. `tests/` mirrors `src/`. Test classes are `final`, methods are sentences —
    `testUnreadFollowsSeenAtRatherThanTheImapSeenFlag`.
34. The class docblock states the claim and the bug it guards.
35. Real container and real database over mocks; roll back in `tearDown`.
36. Every test must be shown to fail against the bug it names.
37. Warnings, notices and deprecations fail the suite.
38. Browser specs import `test` from the local support module, reseed their own fixtures, and locate
    by role and accessible name.

**Tooling and docs**
39. Static analysis runs over the whole tree with a baseline that shrinks. `ignoreErrors` stays empty.
40. Every fact lives in one place — the Node version in `.nvmrc`, the framework minor in one
    constraint.
41. Commit subjects are imperative sentences about effect, with no prefix; bodies explain the
    reasoning and disclose what fell out.
42. Changelog entries lead with the user's symptom in bold, then the cause, then the fix. Every
    release states its schema and deployment impact.
43. A change to what a user does, an operator sets, or a provider needs, updates the handbook in the
    same commit. The inventory — commands, environment variables, links, pages — is asserted by a
    test, so the review is spent on whether the prose is still true.
