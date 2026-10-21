<?php

declare(strict_types=1);

namespace App\Domain\Enum\User;

/**
 * What an app password may do on the JMAP surface.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * An app password used to be the whole of its owner's access: hand a client one
 * and it could read every mailbox, send as any alias and empty the trash. That
 * is fine for a phone the person is holding and wrong for anything else — an
 * automation, a script, an assistant — where the useful question is not "may
 * this read mail" but "may this delete it", and the answer wants to be no.
 *
 * Three capabilities, and the shape is deliberate: `read` is the base and
 * `send`/`delete` are additions to it. A token holding only `delete` would be a
 * credential that can destroy mail it cannot see, which is not a useful
 * arrangement and is not one anybody meant to create.
 *
 * ── Fail closed ──────────────────────────────────────────────────────────────
 * The stored value is a jsonb list, so a restored backup, a hand edit or a
 * value from a future version can all put something else there. Anything not
 * recognised is dropped rather than guessed at, and a token with no recognised
 * scope can do nothing. That is the opposite of how a missing setting usually
 * reads, and it is the right direction here: an app password that arrives with
 * less access than expected is a support question, and one that arrives with
 * more is an incident.
 *
 * ── Existing tokens ──────────────────────────────────────────────────────────
 * The migration that introduced this backfills every existing row with all
 * three. A token that has been syncing somebody's phone for a year must not
 * become read-only because the column is new — see Version20261021140000.
 */
enum ApiTokenScope: string
{
    /** Read mail, mailboxes, threads and calendars; own the session it uses. */
    case Read = 'read';

    /** Send mail: submit a draft through EmailSubmission/set. */
    case Send = 'send';

    /**
     * Destroy things: delete messages and threads, remove mailboxes.
     *
     * plMail's destroy is a move to Trash rather than a row delete — see
     * EmailSetMethod — so this is "may put things in the bin", not "may burn
     * them". It is still the capability worth gating.
     */
    case Delete = 'delete';

    /**
     * Every scope, in the order a person would grant them.
     *
     * The backfill for tokens that existed before scopes did, and what the
     * settings page ticks when creating a token the old way.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::Read, self::Send, self::Delete];
    }

    /**
     * The JMAP methods this scope alone permits.
     *
     * Read is the whole reading surface and whatever a session needs to exist
     * at all: Core/echo, the Session object, Identity (a client shows the From
     * it may use), the push subscriptions a client installs for itself, and
     * the appearance preference.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return match ($this) {
            self::Read => [
                'Core/echo',
                'Mailbox/get',
                'Mailbox/query',
                'Mailbox/changes',
                'Email/get',
                'Email/query',
                'Email/changes',
                'Thread/get',
                'Thread/changes',
                'SearchSnippet/get',
                'EmailSubmission/get',
                'EmailSubmission/changes',
                'Identity/get',
                'Calendar/get',
                'Calendar/changes',
                'CalendarEvent/get',
                'CalendarEvent/query',
                'CalendarEvent/changes',
                'Contact/autocomplete',
                'InboxTab/get',
                'InboxTab/changes',
                'Template/get',
                'Template/render',
                'TemplateFolder/get',
                'Appearance/get',
                'PushSubscription/get',
                'PushSubscription/set',
            ],
            self::Send => [
                'EmailSubmission/set',
            ],
            // Only the destroying half of these is gated; creating and updating
            // (a draft, a keyword, a mailbox's name) belongs to whatever else
            // the token may do, and Email/set asks this class per operation.
            // See JmapProcessor::scopeFor(), which is where the split is made.
            self::Delete => [
                'Thread/set',
                'Mailbox/set',
            ],
        };
    }

    /**
     * The `Email/set` operations this scope decides, if any.
     *
     * Email/set is one method carrying three different powers — creating a
     * draft, patching keywords/mailboxes, and destroying — so it cannot be
     * mapped to a scope as a whole without either over- or under-granting.
     * Creation rides with `send` (that is what a draft is for), patching rides
     * with `read` (marking read, filing), and destroy needs `delete`.
     *
     * @return list<string> the operations: 'create', 'update', 'destroy'
     */
    public function emailSetOperations(): array
    {
        return match ($this) {
            self::Send   => ['create'],
            self::Read   => ['update'],
            self::Delete => ['destroy'],
        };
    }

    /** How the scope is named on the settings page. */
    public function labelKey(): string
    {
        return 'settings.app_passwords.scope.' . $this->value;
    }

    /**
     * Read a stored list back into scopes, dropping anything unrecognised.
     *
     * Per entry rather than all-or-nothing: a future version adding a fourth
     * scope should not make an older install unable to read the other three.
     *
     * @return list<self>
     */
    public static function fromStored(mixed $stored): array
    {
        if (false === is_array($stored)) {
            return [];
        }

        $scopes = [];

        foreach ($stored as $value) {
            if (false === is_string($value)) {
                continue;
            }

            $scope = self::tryFrom($value);

            if (null !== $scope && false === in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * @param list<self> $scopes
     *
     * @return list<string>
     */
    public static function toStored(array $scopes): array
    {
        $values = [];

        foreach ($scopes as $scope) {
            $values[] = $scope->value;
        }

        return array_values(array_unique($values));
    }
}
