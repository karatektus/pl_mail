<?php

declare(strict_types=1);

namespace App\Service\Backup;

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
use App\Repository\Calendar\BookingPageRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Calendar\CalendarShareLinkRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Rule\MailRuleRepository;
use App\Repository\User\ApiTokenRepository;
use App\Repository\User\UserRepository;
use DateTimeInterface;
use Throwable;

/**
 * The people half of an instance's configuration: who can sign in, and
 * everything each of them set up.
 *
 * **Why a backup has users at all.** It did not, and the sentence that ended a
 * restore said so: "the configuration is in place, this installation still has
 * no users." An operator who had just moved a whole install to a new host was
 * then asked to invent an administrator, and every mailbox, filter and calendar
 * that made the old install *theirs* was gone. A backup that cannot name its
 * own operator is a backup of a server, not of an installation.
 *
 * ## Identity and configuration, still never mail
 *
 * The line this class does not cross is the same one the rest of the feature
 * holds, drawn one level deeper. What travels is what a person *decided*: their
 * account, their credentials, the mailboxes they connected, the rules they
 * wrote, the labels and calendars they made, the links they published. What
 * does not travel is what the software *observed*: messages, threads,
 * mailboxes, calendar events, contacts harvested from mail, delivery logs, sync
 * cursors. `app:backup` is still the other half and still the bigger one.
 *
 * Three exclusions are worth naming because they look like configuration:
 *
 *   - **Trusted devices and push subscriptions** are grants to one browser or
 *     one phone, bound to a cookie or a vendor's device token. Restored onto
 *     another host they name devices that will never call back, and a trusted
 *     device is a *skipped second factor* — the one row here where carrying it
 *     would weaken an account rather than merely litter it.
 *   - **Label bindings** are a label's identity on a provider — a Gmail label
 *     id, a Graph folder id. They are re-derived by the first sync, and the
 *     ones in the file belong to mailboxes this install has not synced yet.
 *   - **Sync state** — cursors, history ids, watch registrations, push channels,
 *     backfill counters, `lastError`. A push channel in particular is a live
 *     registration pointing at the *old* instance's webhook URL; restoring it
 *     would have the new install believe it is subscribed to something that has
 *     never heard of it.
 *
 * Avatars and appearance background images are filenames in a storage volume
 * this file does not carry, so they are dropped rather than restored as links
 * to nothing — {@see \App\Entity\Embeddable\Appearance::toArray()} already made
 * that decision for the background and this class makes the same one for the
 * avatar.
 *
 * ## Credentials leave decrypted, exactly as the database section's do
 *
 * TOTP secrets, mailbox passwords, OAuth tokens and integration secrets all sit
 * in `encrypted_string` columns, readable only with the APP_ENCRYPTION_KEY that
 * wrote them — which is never the key of the install a backup is opened on. So
 * they leave through the entity, in the clear, and go back through the entity,
 * re-encrypted with the target's key. The envelope's password is the
 * protection. {@see ConfigBackupDatabase} states the full argument; it applies
 * here unchanged and matters more, because this section is where the passwords
 * to other people's mail servers are.
 *
 * Password hashes, recovery codes and app-password hashes are already
 * one-way and travel as they are stored. They are no less sensitive for it: a
 * hash is an offline guessing target, and this file is the whole set.
 *
 * ## Keyed by email, because that is what an import matches on
 *
 * `users` is an object whose keys are email addresses rather than a list, for
 * the reason `mailProviders` is: the key is the identity the importer looks up,
 * so the document cannot contain two entries that disagree about one person,
 * and the review can name a row without inventing a label for it.
 *
 * Inside one user, everything that points at something else points by the
 * **source's** row id, kept in `id` fields that mean nothing on the target.
 * {@see ConfigBackupUserRestorer} builds a map from them as it creates rows and
 * never lets one leak into the database.
 */
final readonly class ConfigBackupUsers
{
    /**
     * The keys of {@see User::$settings} a backup carries.
     *
     * Curated rather than copied wholesale, and the two that are missing are
     * the point of curating: `admin.logs_seen_at` is a read marker against log
     * rows that do not travel, and `sidebar.expanded_account` holds an account
     * **id** from the source database, which on the target is either nothing or
     * somebody else's mailbox. The onboarding keys very much do travel — a
     * restored user who is walked back through the setup wizard has been told
     * their restore did not work.
     *
     * @var list<string>
     */
    private const array USER_SETTINGS = [
        User::SETTING_CLOCK,
        User::SETTING_CALENDAR_PANE_OPEN,
        User::SETTING_CALENDAR_PANE_WIDTH,
        User::SETTING_CALENDAR_PANE_MODE,
        User::SETTING_SEARCH_SORT,
        User::SETTING_ADMIN_COLLAPSED_PANELS,
        // Travels, unlike its `sidebar.expanded_account` neighbour, and the
        // difference is what these hold: this is section names and label FULL
        // NAMES, which the restorer recreates verbatim, not a row id that means
        // somebody else on the target.
        User::SETTING_SIDEBAR_COLLAPSED,
        User::SETTING_ONBOARDING_COMPLETED_AT,
        User::SETTING_ONBOARDING_STEP,
        User::SETTING_ONBOARDING_SKIPPED,
        User::SETTING_ONBOARDING_DONE_STEPS,
    ];

    /**
     * The keys of {@see Account::$settings} a backup carries.
     *
     * `sync.backfill_ran_at` and `sync.backfill_attempts` are counters about a
     * sync that happened on the other host, and the connection-error key
     * AccountCreator writes is a verdict about a network reachable from there.
     * What is left is the choices the user actually made — and one of them,
     * `calendar.target_id`, is a calendar id that has to be remapped on the
     * way in.
     *
     * An allowlist rather than a denylist, which is what makes the retired
     * `sync.message_limit` a non-event: a backup written before the sync cap
     * was removed still carries the key, and the restorer writes whatever the
     * file holds into the bag, where nothing now reads it. Dropping it here
     * stops new backups carrying it without making old ones invalid.
     *
     * @var list<string>
     */
    private const array ACCOUNT_SETTINGS = [
        Account::SETTING_BACKFILL_TARGET,
        Account::SETTING_CALENDAR_TARGET,
    ];

    public function __construct(
        private UserRepository              $users,
        private AccountRepository           $accounts,
        private ApiTokenRepository          $apiTokens,
        private IntegrationRepository       $integrations,
        private LabelRepository             $labels,
        private MailRuleRepository          $rules,
        private CalendarRepository          $calendars,
        private CalendarShareLinkRepository $shareLinks,
        private BookingPageRepository       $bookingPages,
    ) {
    }

    /**
     * Every user this install has, with everything they configured.
     *
     * Soft-deleted users are not here. They cannot sign in, nothing of theirs
     * is reachable, and restoring one would recreate a person somebody removed
     * — while still occupying their email address against the unique index, so
     * that recreating them properly would then fail. `deletedAt` is a decision,
     * and a restore honours decisions.
     *
     * @return array<string, array<string, mixed>>
     */
    public function export(): array
    {
        $exported = [];

        foreach ($this->users->createUndeletedQueryBuilder()->getQuery()->getResult() as $user) {
            if (false === $user instanceof User || null === $user->email) {
                continue;
            }

            $exported[$user->email] = $this->exportUser($user);
        }

        return $exported;
    }

    /**
     * One live user in the same shape the export produces, for the review to
     * compare against — or null when this install has no such person, or has
     * one whose rows it cannot read.
     *
     * **Per email rather than a `current()` that mirrors export().** The
     * database section can afford to read its whole self back for the
     * comparison, because "its whole self" is three rows. This one's is every
     * user with every mailbox, label, filter and calendar they own, and the
     * review only ever asks about the handful of addresses the document names.
     * A plan() that walked the entire installation to answer six questions
     * would make opening a backup on a large install slower than applying it.
     *
     * **A user whose credentials will not decrypt is null, not an exception.**
     * The same judgement ConfigBackupEnvironment::stored() makes about an
     * unreadable secrets file: a review page that 500s tells an operator
     * nothing, and this is a state a real install can be in — an
     * APP_ENCRYPTION_KEY rotated without re-encrypting leaves rows nobody can
     * read. The caller turns null into "differs", which is both true and the
     * safe direction: it cannot be shown as `Unchanged`, and the disposition
     * beside it says the live user is being left alone regardless.
     *
     * Takes an id rather than a User because hydrating one is itself the risky
     * step — see ConfigBackupUserRestorer::existingId(). The find() belongs
     * inside the try for exactly that reason.
     *
     * @return array<string, mixed>|null
     */
    public function liveVersionOf(int $userId): ?array
    {
        try {
            $user = $this->users->find($userId);

            if (false === $user instanceof User || true === $user->isDeleted()) {
                return null;
            }

            return $this->exportUser($user);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function exportUser(User $user): array
    {
        return [
            'nameFirst' => $user->nameFirst,
            'nameLast'  => $user->nameLast,
            // The explicit list, not getRoles(): ROLE_USER is implied on the
            // way out of the entity and re-implied on the way in, and storing
            // the implication would make a restored user's row differ from an
            // identical one created by hand.
            'roles'      => array_values($user->roles),
            'password'   => $user->password,
            'locale'     => $user->locale,
            'timezone'   => $user->timezone,
            'appearance' => $user->appearance->toArray(),
            // Beside appearance and for the same reason: it is a fixed,
            // validated set with its own toArray(), so a field added to it
            // travels without a line being added here. That is exactly what
            // USER_SETTINGS' curated allowlist below does not give — it omits
            // several bag keys today with nothing catching it.
            'aiPreferences' => $user->aiPreferences->toArray(),
            'settings'   => $this->settingsOf($user),
            'createdAt'  => $user->createdAt->format(DateTimeInterface::ATOM),
            'twoFactor'  => [
                // Decrypted here and re-encrypted on the way in; see the class
                // docblock. Anyone holding this can mint valid codes forever,
                // which is precisely why it is a credential and not a setting.
                'secret'        => $user->totpSecret,
                'confirmedAt'   => $user->totpConfirmedAt?->format(DateTimeInterface::ATOM),
                // SHA-256 digests already. Portable as they stand, and there is
                // nothing to re-encrypt.
                'recoveryCodes' => array_values($user->backupCodes),
            ],
            'appPasswords' => $this->exportAppPasswords($user),
            'accounts'     => $this->exportAccounts($user),
            'integrations' => $this->exportIntegrations($user),
            'labels'       => $this->exportLabels($user),
            'rules'        => $this->exportRules($user),
            'calendars'    => $this->exportCalendars($user),
            'shareLinks'   => $this->exportShareLinks($user),
            'bookingPages' => $this->exportBookingPages($user),
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The app passwords, as hashes.
     *
     * These are what keeps JMAP clients working after a restore, and they are
     * the one credential here that could not be reissued even in principle: the
     * plaintext was shown once, at creation, and nothing has held it since. A
     * restore that dropped them would silently sign out every phone the
     * installation had.
     *
     * Revoked ones travel too, carrying their `revokedAt`. A revocation is a
     * decision, and a row that came back unrevoked would be a working
     * credential somebody had deliberately killed.
     *
     * @return list<array<string, mixed>>
     */
    private function exportAppPasswords(User $user): array
    {
        $exported = [];

        foreach ($this->apiTokens->findForUser($user) as $token) {
            if (false === $token instanceof ApiToken) {
                continue;
            }

            $exported[] = [
                'name'       => $token->name,
                'tokenHash'  => $token->tokenHash,
                'hint'       => $token->hint,
                'lastUsedAt' => $token->lastUsedAt?->format(DateTimeInterface::ATOM),
                'revokedAt'  => $token->revokedAt?->format(DateTimeInterface::ATOM),
                'createdAt'  => $token->createdAt->format(DateTimeInterface::ATOM),
            ];
        }

        return $exported;
    }

    /**
     * The mailboxes, with the credentials that open them and nothing about what
     * was ever in them.
     *
     * Everything below `isActive` on the entity is sync state and is absent by
     * design — see the class docblock. What is here is exactly what the
     * "Add mail account" form asks for, plus the OAuth tokens a provider handed
     * back, which are worth more than the form's answers: a restored refresh
     * token means the new install is connected the moment it starts, and a lost
     * one means every user re-authorising with Google by hand.
     *
     * @return list<array<string, mixed>>
     */
    private function exportAccounts(User $user): array
    {
        $exported = [];

        foreach ($this->accounts->findForUserOrdered($user) as $account) {
            if (false === $account instanceof Account) {
                continue;
            }

            $exported[] = [
                'id'                => $account->id,
                'name'              => $account->name,
                'email'             => $account->email,
                'sortOrder'         => $account->sortOrder,
                'isPrimary'         => $account->isPrimary,
                // The account's dot colour, which is assigned at creation and
                // is not recoverable from anything else in this file — restore
                // it and every account keeps the mark the user recognises it
                // by; leave it out and they all come back sharing colour 0.
                'colorIndex'        => $account->colorIndex,
                'imapHost'          => $account->imapHost,
                'imapPort'          => $account->imapPort,
                'imapEncryption'    => $account->imapEncryption,
                'smtpHost'          => $account->smtpHost,
                'smtpPort'          => $account->smtpPort,
                'smtpEncryption'    => $account->smtpEncryption,
                'username'          => $account->username,
                'password'          => $account->password,
                'authType'          => $account->authType,
                'oauthProvider'     => $account->oauthProvider,
                'oauthAccessToken'  => $account->oauthAccessToken,
                'oauthRefreshToken' => $account->oauthRefreshToken,
                'oauthTokenExpiry'  => $account->oauthTokenExpiry?->format(DateTimeInterface::ATOM),
                'isActive'          => $account->isActive,
                'pushEnabled'       => $account->pushEnabled,
                'settings'          => $this->settingsOfAccount($account),
                'aliases'           => $this->exportAliases($account),
            ];
        }

        return $exported;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportAliases(Account $account): array
    {
        $exported = [];

        foreach ($account->aliases as $alias) {
            if (false === $alias instanceof EmailAlias) {
                continue;
            }

            $exported[] = [
                'address'     => $alias->address,
                'displayName' => $alias->displayName,
                'status'      => $alias->status->value,
                'source'      => $alias->source->value,
            ];
        }

        return $exported;
    }

    /**
     * The per-user integrations — a Nextcloud, an Immich — with their secrets.
     *
     * Distinct from the `integrationProviders` table the database section
     * already carried: that one is the operator's app registration, one row per
     * provider for the whole install, and this one is a person's connection to
     * their own server, with their own token on it.
     *
     * `lastCheckedAt` and `lastError` are left behind: they are this install's
     * verdict on reaching a host from where it stands.
     *
     * @return list<array<string, mixed>>
     */
    private function exportIntegrations(User $user): array
    {
        $exported = [];

        foreach ($this->integrations->findForUserOrdered($user) as $integration) {
            if (false === $integration instanceof Integration) {
                continue;
            }

            $exported[] = [
                'id'                => $integration->id,
                'provider'          => $integration->provider->value,
                'name'              => $integration->name,
                'baseUrl'           => $integration->baseUrl,
                'username'          => $integration->username,
                'secret'            => $integration->secret,
                'oauthAccessToken'  => $integration->oauthAccessToken,
                'oauthRefreshToken' => $integration->oauthRefreshToken,
                'oauthTokenExpiry'  => $integration->oauthTokenExpiry?->format(DateTimeInterface::ATOM),
                'isActive'          => $integration->isActive,
                'settings'          => $integration->settings,
            ];
        }

        return $exported;
    }

    /**
     * Labels, as a flat list carrying each one's parent id.
     *
     * Flat rather than nested because the restorer has to create them in an
     * order the foreign key allows anyway, and a nested document would encode
     * that order twice — once in the shape and once in the ids — with no way to
     * tell which the importer used when they disagreed.
     *
     * System labels (those with a role) travel with their role. They are
     * find-or-created lazily by LabelResolver during sync, so a restore that
     * skipped them would work; it would also lose the colour and the sort order
     * somebody chose for their Inbox.
     *
     * @return list<array<string, mixed>>
     */
    private function exportLabels(User $user): array
    {
        $exported = [];

        foreach ($this->labels->findForUser($user) as $label) {
            if (false === $label instanceof Label) {
                continue;
            }

            $exported[] = [
                'id'        => $label->id,
                'parentId'  => $label->parent?->id,
                'name'      => $label->name,
                'role'      => $label->role?->value,
                'color'     => $label->color,
                'isVisible' => $label->isVisible,
                'sortOrder' => $label->sortOrder,
            ];
        }

        return $exported;
    }

    /**
     * Filters, with their condition trees and action lists carried verbatim.
     *
     * Verbatim except for the ids inside them, which is the whole difficulty:
     * a condition can say `hasLabel: 41` and an action `labelId: 41` or
     * `integrationId: 7`, and those numbers are rows in the source database.
     * They are exported as they are and rewritten on the way in — see
     * {@see ConfigBackupUserRestorer}, which owns the remap so that the
     * knowledge of which keys hold ids lives in one place.
     *
     * The run state is not exported. "Apply to existing mail" is a job that ran
     * over messages that are not here.
     *
     * @return list<array<string, mixed>>
     */
    private function exportRules(User $user): array
    {
        $exported = [];

        foreach ($this->rules->findForUserOrdered($user) as $rule) {
            if (false === $rule instanceof MailRule) {
                continue;
            }

            $exported[] = [
                'name'           => $rule->name,
                'accountId'      => $rule->account?->id,
                'conditions'     => $rule->conditions,
                'actions'        => $rule->actions,
                'isEnabled'      => $rule->isEnabled,
                'sortOrder'      => $rule->sortOrder,
                'stopProcessing' => $rule->stopProcessing,
            ];
        }

        return $exported;
    }

    /**
     * Calendars as *sources*, never as contents.
     *
     * A subscribed calendar has no URL or password of its own: it is a row with
     * `role = Remote`, a `remoteId`, and an `integration_id` pointing at the
     * Integration that holds the base URL and the credential. So restoring a
     * CalDAV subscription is restoring these two rows together, and the
     * integration is exported above with its secret for exactly that reason.
     *
     * `syncToken`, `lastSyncedAt`, `lastSyncError` and the four push-channel
     * columns stay behind. The push channel is the sharpest of them: it is a
     * live registration with Google naming the *old* instance's webhook URL and
     * carrying the shared secret that authenticates it. Restored, it would
     * describe a subscription the new install does not have and cannot renew.
     *
     * @return list<array<string, mixed>>
     */
    private function exportCalendars(User $user): array
    {
        $exported = [];

        foreach ($this->calendars->findForUser($user) as $calendar) {
            if (false === $calendar instanceof Calendar) {
                continue;
            }

            $exported[] = [
                'id'            => $calendar->id,
                'accountId'     => $calendar->account?->id,
                'integrationId' => $calendar->integration?->id,
                'name'          => $calendar->name,
                'color'         => $calendar->color,
                'timeZone'      => $calendar->timeZone,
                'role'          => $calendar->role->value,
                'isVisible'     => $calendar->isVisible,
                'isDefault'     => $calendar->isDefault,
                'isReadOnly'    => $calendar->isReadOnly,
                'sortOrder'     => $calendar->sortOrder,
                'remoteId'      => $calendar->remoteId,
                'settings'      => $calendar->settings,
            ];
        }

        return $exported;
    }

    /**
     * Published calendar links, by their token digest.
     *
     * The digest is what makes this worth carrying: the URL somebody mailed to
     * their team is `…/share/<token>`, the row stores only SHA-256 of that
     * token, and the check is a digest comparison. Carry the digest and every
     * link that was ever handed out keeps working after the move; regenerate
     * and every one of them 404s with nobody able to say why.
     *
     * It is also why the digest is not a secret worth withholding from the
     * file — it cannot be turned back into a URL. The token itself was never
     * stored and is not here.
     *
     * @return list<array<string, mixed>>
     */
    private function exportShareLinks(User $user): array
    {
        $exported = [];

        foreach ($this->shareLinks->findForUser($user) as $link) {
            if (false === $link instanceof CalendarShareLink) {
                continue;
            }

            $calendarIds = [];

            foreach ($link->calendars as $calendar) {
                if ($calendar instanceof Calendar) {
                    $calendarIds[] = $calendar->id;
                }
            }

            $exported[] = [
                'name'        => $link->name,
                'tokenDigest' => $link->tokenDigest,
                'details'     => $link->details,
                'windowMode'  => $link->windowMode->value,
                'rollingDays' => $link->rollingDays,
                'startsOn'    => $link->startsOn?->format('Y-m-d'),
                'endsOn'      => $link->endsOn?->format('Y-m-d'),
                'revokedAt'   => $link->revokedAt?->format(DateTimeInterface::ATOM),
                'calendarIds' => $calendarIds,
            ];
        }

        return $exported;
    }

    /**
     * Booking pages, on the same reasoning as share links: the digest is the
     * published URL's only surviving half, and dropping it breaks every link.
     *
     * `CalendarBooking` rows — the appointments people actually made — are
     * event data and are not here.
     *
     * @return list<array<string, mixed>>
     */
    private function exportBookingPages(User $user): array
    {
        $exported = [];

        foreach ($this->bookingPages->findForUser($user) as $page) {
            if (false === $page instanceof BookingPage) {
                continue;
            }

            $busyCalendarIds = [];

            foreach ($page->busyCalendars as $calendar) {
                if ($calendar instanceof Calendar) {
                    $busyCalendarIds[] = $calendar->id;
                }
            }

            $exported[] = [
                'name'            => $page->name,
                'description'     => $page->description,
                'tokenDigest'     => $page->tokenDigest,
                'isEnabled'       => $page->isEnabled,
                'timeZone'        => $page->timeZone,
                'weekdays'        => $page->weekdays,
                'startMinute'     => $page->startMinute,
                'endMinute'       => $page->endMinute,
                'slotMinutes'     => $page->slotMinutes,
                'bufferMinutes'   => $page->bufferMinutes,
                'noticeMinutes'   => $page->noticeMinutes,
                'horizonDays'     => $page->horizonDays,
                'calendarId'      => $page->calendar->id,
                'busyCalendarIds' => $busyCalendarIds,
            ];
        }

        return $exported;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsOf(User $user): array
    {
        $settings = [];

        foreach (self::USER_SETTINGS as $key) {
            $value = $user->getSetting($key);

            if (null !== $value) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsOfAccount(Account $account): array
    {
        $settings = [];

        foreach (self::ACCOUNT_SETTINGS as $key) {
            $value = $account->getSetting($key);

            if (null !== $value) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }
}
