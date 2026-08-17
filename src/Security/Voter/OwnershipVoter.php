<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * "Does this belong to the person asking?" — the one answer, for every entity
 * a user owns.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * Nothing in this application is shared between users: an Account, a Message, a
 * Calendar, a Label and a rule each belong to exactly one person, and every
 * route that touches one has to say so. That check used to be a private helper
 * on the controller — nineteen of them, across eighteen files, under six names
 * (assertOwned, assertOwnership, assertOwnsEvent, assertOwnsCalendar,
 * assertOwnedUserLabel, denyUnlessOwner). All nineteen were correct. The
 * problem was that each was a fresh derivation of the same sentence, so the
 * twentieth was a fresh chance to derive it wrong, and a controller that simply
 * never grew one looked no different from the outside.
 *
 * ── Why one voter and not one per entity ─────────────────────────────────────
 * The idiomatic shape is a voter per entity, and it was rejected here because
 * the rule genuinely does not vary: owning is `$owner === $user` for all nine
 * types. Nine classes would have been nine copies of that comparison with nine
 * different ways to reach `$owner`, which is the duplication this replaces
 * wearing a different hat. What varies is only how the owner is REACHED, so
 * that — and only that — is the match below.
 *
 * Rules that are not ownership stay with their controller, because they are not
 * this question: LabelController still refuses to let a SYSTEM label be edited,
 * ComposeController still refuses to edit a draft that has already been sent,
 * and FilePickerController still refuses a part whose message is not a draft.
 * Those are about what the thing IS, not whose it is.
 *
 * ── Failing closed ───────────────────────────────────────────────────────────
 * Every link below except Message::$account and Integration::$usr is nullable,
 * so "no owner" is representable and must never read as "everyone's". A null
 * anywhere on the path returns null from ownerOf() and is denied — never
 * compared, because `null === null` would hand an orphaned row to whoever asked
 * first. An unauthenticated token is denied for the same reason rather than
 * left to the comparison.
 */
final class OwnershipVoter extends Voter
{
    /**
     * The single attribute. Spelled as a verb about the subject rather than
     * 'view'/'edit', because ownership does not distinguish them here — a user
     * may do anything to their own mail and nothing at all to anybody else's,
     * and inventing finer attributes would imply a sharing model that does not
     * exist. If one ever does, it arrives as new attributes beside this one.
     */
    public const string OWN = 'own';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (self::OWN !== $attribute) {
            return false;
        }

        return $subject instanceof Account
            || $subject instanceof Calendar
            || $subject instanceof CalendarEvent
            || $subject instanceof Integration
            || $subject instanceof Label
            || $subject instanceof MailRule
            || $subject instanceof Message
            || $subject instanceof MessagePart
            || $subject instanceof MessageThread;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        if (false === $user instanceof User) {
            $vote?->addReason('The request is not authenticated as a user.');

            return false;
        }

        $owner = $this->ownerOf($subject);

        if (null === $owner) {
            $vote?->addReason(sprintf('%s has no owner to compare against.', get_debug_type($subject)));

            return false;
        }

        // Identity, not id equality: both sides come from the same Doctrine
        // unit of work within a request, and comparing ids would quietly pass
        // for an unpersisted entity whose id is still null on both sides.
        return $owner === $user;
    }

    /**
     * The owning user, or null when the chain to one is broken.
     *
     * Message is reached through its own `$account`, which the mapping declares
     * non-nullable, and NOT through the mailbox-then-thread walk in
     * ThreadStatusUpdater::accountOf(). That walk exists because Gmail-API
     * messages carry no mailbox, and it ends at `$message->thread->account` —
     * but `$thread` is itself nullable, so as an ownership check it can fatal on
     * a message that has neither. The direct link cannot: it is required by the
     * schema and is the same one the attachment, source and compose checks were
     * already using.
     */
    private function ownerOf(mixed $subject): ?User
    {
        return match (true) {
            $subject instanceof Account       => $subject->usr,
            $subject instanceof Calendar      => $subject->usr,
            $subject instanceof CalendarEvent => $subject->usr,
            $subject instanceof Integration   => $subject->usr,
            $subject instanceof Label         => $subject->usr,
            $subject instanceof MailRule      => $subject->usr,
            $subject instanceof Message       => $subject->account->usr,
            $subject instanceof MessagePart   => $subject->message?->account->usr,
            $subject instanceof MessageThread => $subject->account?->usr,
            default                           => null,
        };
    }
}
