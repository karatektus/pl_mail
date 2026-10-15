<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Repository\User\UserRepository;
use App\Service\Mail\MailChangeNotifier;
use App\Tests\Support\Mercure\RecordingHub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The nudge an open tab gets when the user's mail changed.
 *
 * What is pinned is its shape — who it goes to, and the one number it carries
 * — because the browser decides from that number alone whether to re-read its
 * counts, and a wrong one is either a stale badge or a request per change.
 */
final class MailChangeNotifierTest extends TestCase
{
    private RecordingHub $hub;

    public function testEachOwnerIsToldOnceWithTheHighestSequenceOfTheirAccounts(): void
    {
        $this->notifier(owners: [11 => 1, 12 => 1, 20 => 2])->publish([
            11 => ['Email' => '400', 'Thread' => '412'],
            12 => ['Email' => '405'],
            20 => ['Mailbox' => '90'],
        ]);

        self::assertCount(2, $this->hub->updates);

        self::assertSame(['mail/user/1'], $this->hub->updates[0]->getTopics());
        self::assertSame(['type' => 'mail.changed', 'sequence' => 412], json_decode($this->hub->updates[0]->getData(), true));
        self::assertTrue($this->hub->updates[0]->isPrivate(), 'one user\'s activity is not for another\'s browser');

        self::assertSame(['mail/user/2'], $this->hub->updates[1]->getTopics());
        self::assertSame(['type' => 'mail.changed', 'sequence' => 90], json_decode($this->hub->updates[1]->getData(), true));
    }

    public function testAnAccountWithNoOwnerIsNobodysToTell(): void
    {
        $this->notifier(owners: [])->publish([11 => ['Email' => '400']]);

        self::assertSame([], $this->hub->updates);
    }

    public function testNothingChangedPublishesNothing(): void
    {
        $this->notifier(owners: [11 => 1])->publish([]);

        self::assertSame([], $this->hub->updates);
    }

    /** No hub is a supported way to run this; the change is already committed. */
    public function testAHubThatIsDownDoesNotFailTheRequestThatChangedTheMail(): void
    {
        $this->notifier(owners: [11 => 1], hubIsDown: true)->publish([11 => ['Email' => '400']]);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<int,int> $owners account id => user id
     */
    private function notifier(array $owners, bool $hubIsDown = false): MailChangeNotifier
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOwnersOfAccounts')->willReturn($owners);

        $this->hub = true === $hubIsDown ? RecordingHub::refusing() : new RecordingHub();

        return new MailChangeNotifier($this->hub, $users, new NullLogger());
    }
}
