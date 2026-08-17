<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The one check that keeps one user's mail away from another's.
 *
 * Every route that touches an owned entity now asks this class instead of
 * re-deriving the comparison, which makes it the single point of failure for
 * tenant isolation in the whole application — so the cases it must never get
 * wrong are pinned here rather than inferred from the controllers that call it.
 *
 * The null cases are the ones worth the most: all but two of the links to a
 * user are nullable, so "this row has no owner" is representable, and an
 * implementation that simply compared `$owner === $user` would answer TRUE for
 * an orphaned row against an unauthenticated request. Both halves of that are
 * asserted below.
 */
final class OwnershipVoterTest extends TestCase
{
    public function testTheOwnerIsGrantedTheirOwnAccount(): void
    {
        $user = new User();

        $account = new Account();
        $account->usr = $user;

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($account, $user),
        );
    }

    /**
     * Every owned type, reached by whatever path leads to its user.
     *
     * @return iterable<string, array{callable(User): object}>
     */
    public static function ownedSubjects(): iterable
    {
        yield 'account' => [static function (User $user): Account {
            $account = new Account();
            $account->usr = $user;

            return $account;
        }];

        yield 'calendar' => [static function (User $user): Calendar {
            $calendar = new Calendar();
            $calendar->usr = $user;

            return $calendar;
        }];

        yield 'calendar event' => [static function (User $user): CalendarEvent {
            $event = new CalendarEvent();
            $event->usr = $user;

            return $event;
        }];

        yield 'label' => [static function (User $user): Label {
            $label = new Label();
            $label->usr = $user;

            return $label;
        }];

        yield 'mail rule' => [static function (User $user): MailRule {
            $rule = new MailRule();
            $rule->usr = $user;

            return $rule;
        }];

        // Through the account, which is the non-nullable link the voter
        // deliberately prefers over the mailbox-then-thread walk.
        yield 'message' => [static function (User $user): Message {
            $message = new Message();
            $message->account = self::accountOf($user);

            return $message;
        }];

        // Two hops: part -> message -> account -> user.
        yield 'message part' => [static function (User $user): MessagePart {
            $message = new Message();
            $message->account = self::accountOf($user);

            $part = new MessagePart();
            $part->message = $message;

            return $part;
        }];

        yield 'message thread' => [static function (User $user): MessageThread {
            $thread = new MessageThread();
            $thread->account = self::accountOf($user);

            return $thread;
        }];
    }

    #[DataProvider('ownedSubjects')]
    public function testTheOwnerIsGranted(callable $build): void
    {
        $user = new User();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($build($user), $user));
    }

    /**
     * The case the nineteen hand-written helpers existed to prevent.
     */
    #[DataProvider('ownedSubjects')]
    public function testAStrangerIsDenied(callable $build): void
    {
        $subject = $build(new User());

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($subject, new User()),
            'a second user was granted access to the first user\'s data',
        );
    }

    // ── Failing closed ────────────────────────────────────────────────────

    /**
     * An orphaned row must not be granted to whoever asks first. `null ===
     * null` is the bug this asserts against: the account below has no user, and
     * so does the token in testAnOrphanIsDeniedToAnAnonymousToken().
     */
    public function testARowWithNoOwnerIsDenied(): void
    {
        $account = new Account();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($account, new User()));
    }

    public function testAPartWhoseMessageIsMissingIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new MessagePart(), new User()));
    }

    public function testAThreadWithNoAccountIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new MessageThread(), new User()));
    }

    public function testAnAnonymousRequestIsDenied(): void
    {
        $user = new User();

        $account = new Account();
        $account->usr = $user;

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            (new OwnershipVoter())->vote($this->anonymous(), $account, [OwnershipVoter::OWN]),
        );
    }

    // ── Scope ─────────────────────────────────────────────────────────────

    /**
     * Anything this voter does not model must abstain rather than deny, or it
     * would veto attributes that belong to other voters and to access_control.
     */
    public function testAnUnknownAttributeAbstains(): void
    {
        $user = new User();

        $account = new Account();
        $account->usr = $user;

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            (new OwnershipVoter())->vote($this->tokenFor($user), $account, ['ROLE_ADMIN']),
        );
    }

    public function testAnUnknownSubjectAbstains(): void
    {
        $user = new User();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            (new OwnershipVoter())->vote($this->tokenFor($user), new \stdClass(), [OwnershipVoter::OWN]),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function accountOf(User $user): Account
    {
        $account = new Account();
        $account->usr = $user;

        return $account;
    }

    private function vote(object $subject, User $user): int
    {
        return (new OwnershipVoter())->vote($this->tokenFor($user), $subject, [OwnershipVoter::OWN]);
    }

    private function tokenFor(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /**
     * A token carrying no User. NullToken is what Symfony's firewall installs
     * for an unauthenticated request.
     */
    private function anonymous(): TokenInterface
    {
        return new \Symfony\Component\Security\Core\Authentication\Token\NullToken();
    }
}
