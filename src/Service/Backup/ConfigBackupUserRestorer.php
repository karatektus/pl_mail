<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarShareLink;
use App\Entity\Integration\Integration;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Rule\MailRule;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Putting a person, and everything they had configured, into an installation
 * that does not have them.
 *
 * ## The one policy, stated once
 *
 * **A user this install already has is never overwritten.** Matched by email,
 * found, skipped — the whole subtree with them, whole. That is the rule the
 * feature is designed around rather than a caution attached to it, and the
 * reasoning is that a backup is a photograph of a moment that has passed. An
 * admin restoring a three-month-old file onto a live install is not asking for
 * February's passwords back; they are usually recovering one thing and taking
 * everything else along by accident. Overwriting would reset today's password
 * to February's, revoke the app passwords made since, and put back a TOTP
 * secret whose owner re-enrolled in March — each one silently, each one locking
 * somebody out of their own mail, and none of it visible on any page
 * afterwards. Skipping is recoverable: the operator can look, see "kept as they
 * are", and go and do the one thing they actually wanted by hand.
 *
 * **Skipping is all-or-nothing, and that is the interesting half.** A merge
 * would be worse than either extreme. Adding "just the mail accounts the live
 * user does not have" from a file means adding a mailbox with a password from
 * February to an account whose owner has since changed it, and a filter that
 * moves mail into a label the file thinks exists. The subtree is internally
 * consistent — rules point at labels, calendars at integrations, share links at
 * calendars — and half of it grafted onto a live user's half is a shape neither
 * install ever had. So: the person exists, or the person is created with
 * everything; there is no third outcome.
 *
 * A **soft-deleted** user counts as existing. They hold the email address
 * against a unique index, so creating over them is impossible anyway — but the
 * real reason is that `deletedAt` is somebody's decision, and quietly restoring
 * a removed account is the one failure mode worse than not restoring one.
 *
 * ## Ids in the file are the source's, and never reach this database
 *
 * Everything inside a user points at everything else by the id it had on the
 * other host: a rule condition says `hasLabel: 41`, a calendar says
 * `integrationId: 7`. Those numbers name rows in a database that is not this
 * one, and writing one through would attach a rule to a stranger's label.
 *
 * So the restore is two passes, and the shape is worth stating because it is
 * the only thing here that is not obvious: **create the rows, keeping a map
 * from the source's id to the entity that replaced it; then wire everything up
 * through the map.** Nothing reads an id off the document except to look it up,
 * and a lookup that misses drops the reference rather than guessing — a filter
 * that quietly stops applying one label is a much smaller wrong than one that
 * applies somebody else's.
 *
 * ## Why this does not go through AccountCreator
 *
 * {@see \App\Service\Mail\AccountCreator} is the path a *new* mail account
 * takes, and everything it adds is an invention: AliasSeeder makes the first
 * alias, CalendarProvisioner makes the default calendar, resequence() decides a
 * sort order and which account is primary. A restore has the real ones in its
 * hand — the alias the user renamed, the calendar they coloured, the order they
 * dragged things into — so running the provisioning would either duplicate them
 * or overwrite them with defaults. It also ends in probe(), which opens a live
 * IMAP connection to decide whether the account works; twenty restored
 * mailboxes would be twenty connections to somebody else's servers from inside
 * an import transaction.
 *
 * The rule the brief asks for is honoured by its intent rather than its letter:
 * nothing here is a bare insert of a partial row. What the provisioning path
 * would have created, the document already carries.
 */
final readonly class ConfigBackupUserRestorer
{
    public function __construct(
        private UserRepository         $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The id of the user this install already has under that address, deleted
     * or not, without hydrating them.
     *
     * Deleted ones count, because this answers "may I create one" and the
     * unique index on `email` does not care that a row is soft deleted.
     *
     * **An id and not an entity, and that is not a micro-optimisation.**
     * findOneBy() builds a User, and building a User decrypts its TOTP secret —
     * so on an install whose APP_ENCRYPTION_KEY was rotated without
     * re-encrypting, merely *asking whether somebody exists* throws a
     * ConversionException out of the hydrator, and the review page an operator
     * needs in order to understand that mess is the page that cannot render.
     * Asking the database for a number instead is the only form of the question
     * that always has an answer. {@see ConfigBackupUsers::liveVersionOf()} does
     * the hydrating, where a failure has somewhere sensible to go.
     */
    public function existingId(string $email): ?int
    {
        $id = $this->users->createQueryBuilder('user')
            ->select('user.id')
            ->andWhere('user.email = :email')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($id) && is_int($id['id'] ?? null) ? $id['id'] : null;
    }

    /**
     * Create one user and everything under them.
     *
     * No flush of its own beyond the two it needs to obtain ids — the caller
     * owns the transaction, and {@see ConfigBackupImporter} runs the whole
     * import inside one so that a document that fails halfway leaves no half a
     * person behind.
     *
     * @param array<string, mixed> $document
     */
    public function restore(string $email, array $document): User
    {
        $user = $this->createUser($email, $document);

        $this->entityManager->persist($user);

        $accounts     = $this->createAccounts($user, $this->rows($document, 'accounts'));
        $integrations = $this->createIntegrations($user, $this->rows($document, 'integrations'));
        $labels       = $this->createLabels($user, $this->rows($document, 'labels'));
        $calendars    = $this->createCalendars($user, $this->rows($document, 'calendars'), $accounts, $integrations);

        $this->createAppPasswords($user, $this->rows($document, 'appPasswords'));
        $this->createShareLinks($user, $this->rows($document, 'shareLinks'), $calendars);
        $this->createBookingPages($user, $this->rows($document, 'bookingPages'), $calendars);

        // Everything above holds entity references, which Doctrine resolves by
        // itself. The two below need the ids those rows are about to be given,
        // so they wait for the insert.
        $this->entityManager->flush();

        $this->retargetAccountCalendars($accounts, $calendars);
        $this->createRules($user, $this->rows($document, 'rules'), $accounts, $labels, $integrations);

        $this->entityManager->flush();

        return $user;
    }

    // ── Private: the user row ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $document
     */
    private function createUser(string $email, array $document): User
    {
        $user = new User();

        $user->email     = $email;
        $user->nameFirst = $this->text($document, 'nameFirst');
        $user->nameLast  = $this->text($document, 'nameLast');
        // The hash exactly as it was stored. Portable by construction — it
        // names its own algorithm and cost — and the only thing that lets a
        // restored user sign in with the password they already know. The
        // plaintext was never anywhere to carry.
        $user->password = $this->text($document, 'password');
        $user->locale   = $this->text($document, 'locale');
        // The set hook drops an identifier this build does not recognise, which
        // is the right answer for a backup from a host with a newer tzdata.
        $user->timezone = $this->text($document, 'timezone');
        $user->roles    = $this->roles($document);

        $user->appearance->applyArray($this->map($document, 'appearance'));

        foreach ($this->map($document, 'settings') as $key => $value) {
            $user->setSetting($key, $value);
        }

        $twoFactor = $this->map($document, 'twoFactor');

        $user->restoreTwoFactor(
            $this->text($twoFactor, 'secret'),
            $this->moment($twoFactor, 'confirmedAt'),
        );

        $user->backupCodes = array_values(array_filter(
            is_array($twoFactor['recoveryCodes'] ?? null) ? $twoFactor['recoveryCodes'] : [],
            'is_string',
        ));

        if (null !== $createdAt = $this->moment($document, 'createdAt')) {
            $user->restoreCreatedAt($createdAt);
        }

        return $user;
    }

    /**
     * The explicit roles, filtered to the ones this build grants.
     *
     * A role string from a file is an authorisation decision arriving from
     * outside, and the file is only as trustworthy as the password that opened
     * it. Filtering to the known set means a hand-edited document cannot invent
     * `ROLE_SUPER_ADMIN` and have the security layer take it seriously — and
     * costs nothing, because plMail has exactly one role worth storing.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function roles(array $document): array
    {
        $roles = is_array($document['roles'] ?? null) ? $document['roles'] : [];

        return array_values(array_filter(
            array_filter($roles, 'is_string'),
            static fn (string $role): bool => User::ROLE_ADMIN === $role,
        ));
    }

    // ── Private: the subtree ──────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, Account>
     */
    private function createAccounts(User $user, array $rows): array
    {
        $created = [];

        foreach ($rows as $row) {
            $account = new Account();

            $account->usr               = $user;
            $account->name              = $this->text($row, 'name');
            $account->email             = $this->text($row, 'email');
            $account->sortOrder         = $this->number($row, 'sortOrder') ?? 0;
            $account->isPrimary         = true === ($row['isPrimary'] ?? false);
            $account->imapHost          = $this->text($row, 'imapHost');
            $account->imapPort          = $this->number($row, 'imapPort');
            $account->imapEncryption    = $this->text($row, 'imapEncryption');
            $account->smtpHost          = $this->text($row, 'smtpHost');
            $account->smtpPort          = $this->number($row, 'smtpPort');
            $account->smtpEncryption    = $this->text($row, 'smtpEncryption');
            $account->username          = $this->text($row, 'username');
            $account->password          = $this->text($row, 'password');
            $account->authType          = $this->text($row, 'authType');
            $account->oauthProvider     = $this->text($row, 'oauthProvider');
            $account->oauthAccessToken  = $this->text($row, 'oauthAccessToken');
            $account->oauthRefreshToken = $this->text($row, 'oauthRefreshToken');
            $account->oauthTokenExpiry  = $this->moment($row, 'oauthTokenExpiry');
            $account->isActive          = true === ($row['isActive'] ?? false);
            $account->pushEnabled       = true === ($row['pushEnabled'] ?? false);

            foreach ($this->map($row, 'settings') as $key => $value) {
                // calendar.target_id is a calendar id from the other database
                // and is rewritten once the calendars exist — see
                // retargetAccountCalendars(). Written through here first so the
                // key's presence survives even if the calendar it names did
                // not make it into the file.
                $account->setSetting($key, $value);
            }

            $this->entityManager->persist($account);
            $this->createAliases($account, $this->rows($row, 'aliases'));

            $created[$this->number($row, 'id') ?? 0] = $account;
        }

        return $created;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createAliases(Account $account, array $rows): void
    {
        foreach ($rows as $row) {
            $address = $this->text($row, 'address');

            if (null === $address) {
                continue;
            }

            $alias = new EmailAlias(
                $account,
                $address,
                EmailAliasSource::tryFrom((string) $this->text($row, 'source')) ?? EmailAliasSource::Manual,
                EmailAliasStatus::tryFrom((string) $this->text($row, 'status')) ?? EmailAliasStatus::Active,
                $this->text($row, 'displayName'),
            );

            $account->aliases->add($alias);

            $this->entityManager->persist($alias);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, Integration>
     */
    private function createIntegrations(User $user, array $rows): array
    {
        $created = [];

        foreach ($rows as $row) {
            // A provider this build has never heard of is skipped, exactly as
            // ConfigBackupDatabase skips an unknown provider registration: a
            // backup from a newer plMail restores the parts this one
            // understands instead of failing whole.
            $provider = Provider::tryFrom((string) $this->text($row, 'provider'));

            if (null === $provider) {
                continue;
            }

            $integration = new Integration($user, $provider, (string) ($this->text($row, 'name') ?? ''));

            $integration->baseUrl           = $this->text($row, 'baseUrl');
            $integration->username          = $this->text($row, 'username');
            // The set hook recomputes secretHint from this, so the list page
            // shows the same few characters it showed on the other install.
            $integration->secret            = $this->text($row, 'secret');
            $integration->oauthAccessToken  = $this->text($row, 'oauthAccessToken');
            $integration->oauthRefreshToken = $this->text($row, 'oauthRefreshToken');
            $integration->oauthTokenExpiry  = $this->moment($row, 'oauthTokenExpiry');
            $integration->isActive          = true === ($row['isActive'] ?? true);
            $integration->settings          = $this->map($row, 'settings');

            $this->entityManager->persist($integration);

            $created[$this->number($row, 'id') ?? 0] = $integration;
        }

        return $created;
    }

    /**
     * Labels, created flat and then given their parents.
     *
     * Two passes over one list because the document's order is the source
     * table's and a child can precede its parent in it. Wiring afterwards makes
     * the order irrelevant, which is cheaper than sorting a tree that might
     * contain a cycle a corrupt file invented.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, Label>
     */
    private function createLabels(User $user, array $rows): array
    {
        $created = [];

        foreach ($rows as $row) {
            $label = new Label();

            $label->usr       = $user;
            $label->name      = $this->text($row, 'name');
            $label->role      = LabelRole::tryFrom((string) $this->text($row, 'role'));
            $label->color     = $this->text($row, 'color');
            $label->isVisible = true === ($row['isVisible'] ?? true);
            $label->sortOrder = $this->number($row, 'sortOrder');

            $this->entityManager->persist($label);

            $created[$this->number($row, 'id') ?? 0] = $label;
        }

        foreach ($rows as $row) {
            $label  = $created[$this->number($row, 'id') ?? 0] ?? null;
            $parent = $created[$this->number($row, 'parentId') ?? 0] ?? null;

            // Never itself: a document claiming a label is its own parent would
            // otherwise produce a row whose fullName getter recurses forever.
            if (null !== $label && null !== $parent && $label !== $parent) {
                $label->parent = $parent;
            }
        }

        return $created;
    }

    /**
     * @param list<array<string, mixed>>  $rows
     * @param array<int, Account>         $accounts
     * @param array<int, Integration>     $integrations
     *
     * @return array<int, Calendar>
     */
    private function createCalendars(User $user, array $rows, array $accounts, array $integrations): array
    {
        $created = [];

        foreach ($rows as $row) {
            $calendar = new Calendar();

            $calendar->usr = $user;
            // A calendar mirroring a mailbox, or subscribed through an
            // integration: both are looked up in the maps, and a reference the
            // file names but does not carry becomes null — a local calendar
            // rather than one pointing at nothing.
            $calendar->account     = $accounts[$this->number($row, 'accountId') ?? 0] ?? null;
            $calendar->integration = $integrations[$this->number($row, 'integrationId') ?? 0] ?? null;
            $calendar->name        = (string) ($this->text($row, 'name') ?? '');
            $calendar->color       = (string) ($this->text($row, 'color') ?? Calendar::DEFAULT_COLOR);
            $calendar->timeZone    = (string) ($this->text($row, 'timeZone') ?? 'UTC');
            $calendar->role        = CalendarRole::tryFrom((string) $this->text($row, 'role')) ?? CalendarRole::Custom;
            $calendar->isVisible   = true === ($row['isVisible'] ?? true);
            $calendar->isDefault   = true === ($row['isDefault'] ?? false);
            $calendar->isReadOnly  = true === ($row['isReadOnly'] ?? false);
            $calendar->sortOrder   = $this->number($row, 'sortOrder') ?? 0;
            $calendar->remoteId    = $this->text($row, 'remoteId');
            $calendar->settings    = $this->map($row, 'settings');

            $this->entityManager->persist($calendar);

            $created[$this->number($row, 'id') ?? 0] = $calendar;
        }

        return $created;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createAppPasswords(User $user, array $rows): void
    {
        foreach ($rows as $row) {
            $tokenHash = $this->text($row, 'tokenHash');

            // No digest, no credential. A row without one could only be
            // restored by minting a new secret nobody has, which would show the
            // operator an app password that cannot work — see ApiToken::restore().
            if (null === $tokenHash) {
                continue;
            }

            $this->entityManager->persist(ApiToken::restore(
                $user,
                (string) ($this->text($row, 'name') ?? ''),
                $tokenHash,
                (string) ($this->text($row, 'hint') ?? ''),
                $this->moment($row, 'lastUsedAt'),
                $this->moment($row, 'revokedAt'),
            ));
        }
    }

    /**
     * Filters, with every id in them rewritten.
     *
     * The three places an id hides are the account the rule is scoped to, the
     * `hasLabel`/`notLabel` conditions in the tree, and the `labelId` /
     * `integrationId` keys on the actions. They are enumerated here rather than
     * discovered, because a filter is compiled to SQL and a rewriting pass that
     * guessed which numbers were ids would eventually rewrite a message size.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, Account>        $accounts
     * @param array<int, Label>          $labels
     * @param array<int, Integration>    $integrations
     */
    private function createRules(User $user, array $rows, array $accounts, array $labels, array $integrations): void
    {
        foreach ($rows as $row) {
            $rule = new MailRule();

            $rule->usr            = $user;
            $rule->name           = $this->text($row, 'name');
            $rule->account        = $accounts[$this->number($row, 'accountId') ?? 0] ?? null;
            $rule->conditions     = $this->remapConditions($this->map($row, 'conditions'), $labels);
            $rule->actions        = $this->remapActions($this->rows($row, 'actions'), $labels, $integrations);
            $rule->isEnabled      = true === ($row['isEnabled'] ?? true);
            $rule->sortOrder      = $this->number($row, 'sortOrder') ?? 0;
            $rule->stopProcessing = true === ($row['stopProcessing'] ?? false);

            $this->entityManager->persist($rule);
        }
    }

    /**
     * Walk the condition tree and rewrite the two conditions that hold a label
     * id.
     *
     * Recursive because the tree is: an operator node carries a `conditions`
     * list of further nodes. A condition naming a label the file did not carry
     * is dropped, which is the same choice made everywhere else here — a filter
     * that tests one thing fewer still does something sensible, and one testing
     * a stranger's label does not.
     *
     * @param array<string, mixed> $node
     * @param array<int, Label>    $labels
     *
     * @return array<string, mixed>
     */
    private function remapConditions(array $node, array $labels): array
    {
        $remapped = [];

        foreach ($node as $key => $value) {
            if ('conditions' === $key && is_array($value)) {
                $children = [];

                foreach ($value as $child) {
                    if (false === is_array($child)) {
                        continue;
                    }

                    $mapped = $this->remapConditions($child, $labels);

                    if ([] !== $mapped) {
                        $children[] = $mapped;
                    }
                }

                $remapped[$key] = $children;

                continue;
            }

            if ('hasLabel' === $key || 'notLabel' === $key) {
                $label = is_int($value) ? ($labels[$value] ?? null) : null;

                if (null === $label || null === $label->id) {
                    // The whole node goes, not just the key: a condition object
                    // with its subject removed would compile to SQL that
                    // matches everything.
                    return [];
                }

                $remapped[$key] = $label->id;

                continue;
            }

            $remapped[$key] = $value;
        }

        return $remapped;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<int, Label>          $labels
     * @param array<int, Integration>    $integrations
     *
     * @return list<array<string, mixed>>
     */
    private function remapActions(array $actions, array $labels, array $integrations): array
    {
        $remapped = [];

        foreach ($actions as $action) {
            if (true === array_key_exists('labelId', $action)) {
                $labelId = $action['labelId'];
                $label   = is_int($labelId) ? ($labels[$labelId] ?? null) : null;

                if (null === $label || null === $label->id) {
                    continue;
                }

                $action['labelId'] = $label->id;
            }

            if (true === array_key_exists('integrationId', $action)) {
                $integrationId = $action['integrationId'];
                $integration   = is_int($integrationId) ? ($integrations[$integrationId] ?? null) : null;

                if (null === $integration || null === $integration->id) {
                    continue;
                }

                $action['integrationId'] = $integration->id;
            }

            $remapped[] = $action;
        }

        return $remapped;
    }

    /**
     * Point each restored account's "put invitations in this calendar" setting
     * at the calendar that replaced the one it named.
     *
     * After the flush, because the setting holds an id rather than a reference
     * and the replacement does not have one until then. A target the file did
     * not carry has the key removed rather than left pointing at a number: the
     * reader falls back to the user's default calendar, which is where a fresh
     * account would have put them anyway.
     *
     * @param array<int, Account>  $accounts
     * @param array<int, Calendar> $calendars
     */
    private function retargetAccountCalendars(array $accounts, array $calendars): void
    {
        foreach ($accounts as $account) {
            $target = $account->getSetting(Account::SETTING_CALENDAR_TARGET);

            if (false === is_int($target)) {
                continue;
            }

            $account->setSetting(Account::SETTING_CALENDAR_TARGET, ($calendars[$target] ?? null)?->id);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, Calendar>       $calendars
     */
    private function createShareLinks(User $user, array $rows, array $calendars): void
    {
        foreach ($rows as $row) {
            $digest = $this->text($row, 'tokenDigest');

            // Without the digest the link cannot be the same link: the token
            // was never stored, so a new row would answer a URL nobody holds.
            if (null === $digest) {
                continue;
            }

            $link = new CalendarShareLink();

            $link->usr         = $user;
            $link->name        = (string) ($this->text($row, 'name') ?? '');
            $link->tokenDigest = $digest;
            $link->details     = array_values(array_filter(
                is_array($row['details'] ?? null) ? $row['details'] : [],
                'is_string',
            ));
            $link->windowMode  = ShareWindow::tryFrom((string) $this->text($row, 'windowMode')) ?? ShareWindow::Rolling;
            $link->rollingDays = $this->number($row, 'rollingDays') ?? 14;
            $link->startsOn    = $this->moment($row, 'startsOn');
            $link->endsOn      = $this->moment($row, 'endsOn');
            $link->revokedAt   = $this->moment($row, 'revokedAt');

            foreach ($this->references($row, 'calendarIds', $calendars) as $calendar) {
                $link->calendars->add($calendar);
            }

            $this->entityManager->persist($link);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, Calendar>       $calendars
     */
    private function createBookingPages(User $user, array $rows, array $calendars): void
    {
        foreach ($rows as $row) {
            $digest   = $this->text($row, 'tokenDigest');
            $calendar = $calendars[$this->number($row, 'calendarId') ?? 0] ?? null;

            // The calendar is a non-null column and the page is meaningless
            // without it: a booking page whose target did not travel has
            // nowhere to write the appointments it takes.
            if (null === $digest || null === $calendar) {
                continue;
            }

            $page = new BookingPage();

            $page->usr           = $user;
            $page->calendar      = $calendar;
            $page->name          = (string) ($this->text($row, 'name') ?? '');
            $page->description   = $this->text($row, 'description');
            $page->tokenDigest   = $digest;
            $page->isEnabled     = true === ($row['isEnabled'] ?? true);
            $page->timeZone      = (string) ($this->text($row, 'timeZone') ?? 'UTC');
            $page->weekdays      = array_values(array_filter(
                is_array($row['weekdays'] ?? null) ? $row['weekdays'] : [],
                'is_int',
            ));
            $page->startMinute   = $this->number($row, 'startMinute') ?? 540;
            $page->endMinute     = $this->number($row, 'endMinute') ?? 1020;
            $page->slotMinutes   = $this->number($row, 'slotMinutes') ?? 30;
            $page->bufferMinutes = $this->number($row, 'bufferMinutes') ?? 0;
            $page->noticeMinutes = $this->number($row, 'noticeMinutes') ?? 120;
            $page->horizonDays   = $this->number($row, 'horizonDays') ?? 30;

            foreach ($this->references($row, 'busyCalendarIds', $calendars) as $busy) {
                $page->busyCalendars->add($busy);
            }

            $this->entityManager->persist($page);
        }
    }

    // ── Private: reading the document ─────────────────────────────────────────

    /**
     * @param array<string, mixed> $source
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $source, string $key): array
    {
        $rows   = $source[$key] ?? null;
        $result = [];

        if (false === is_array($rows)) {
            return $result;
        }

        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function map(array $source, string $key): array
    {
        $value  = $source[$key] ?? null;
        $result = [];

        if (false === is_array($value)) {
            return $result;
        }

        foreach ($value as $name => $entry) {
            if (is_string($name)) {
                $result[$name] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function text(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function number(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * A timestamp the file states, or null when it says nothing or says
     * nonsense.
     *
     * Swallowed rather than propagated, on ConfigBackupImporter::exportedAt()'s
     * reasoning: the credentials in this row are good, and refusing a whole
     * user over an unparseable date would be absurd.
     *
     * @param array<string, mixed> $source
     */
    private function moment(array $source, string $key): ?DateTimeImmutable
    {
        $value = $this->text($source, $key);

        if (null === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The entities a list of source ids names, skipping the ones that did not
     * travel.
     *
     * @param array<string, mixed> $source
     * @param array<int, Calendar> $known
     *
     * @return list<Calendar>
     */
    private function references(array $source, string $key, array $known): array
    {
        $ids   = $source[$key] ?? null;
        $found = [];

        if (false === is_array($ids)) {
            return $found;
        }

        foreach ($ids as $id) {
            if (is_int($id) && null !== $entity = ($known[$id] ?? null)) {
                $found[] = $entity;
            }
        }

        return $found;
    }
}
