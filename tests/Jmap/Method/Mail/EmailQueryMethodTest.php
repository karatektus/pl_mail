<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Entity\Mail\Message;
use App\Jmap\Method\Mail\EmailQueryMethod;
use App\Tests\Jmap\JmapTestCase;

/**
 * Email/query pages in SQL, and `position`/`total` still count the list the
 * client asked for.
 *
 * The window used to be applied in PHP over every matching row of the
 * account. Moving it into the query is only correct if a collapsed list is
 * collapsed before it is windowed — otherwise position 1 lands on the second
 * message of the newest thread rather than on the second thread.
 */
final class EmailQueryMethodTest extends JmapTestCase
{
    /** @var list<Message> oldest first */
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['-4 hours', '-3 hours', '-2 hours', '-1 hour'] as $age) {
            $message             = $this->receivedMessage();
            $message->receivedAt = new \DateTimeImmutable($age);
            $this->messages[]    = $message;
        }

        // The oldest and the newest are one conversation.
        [$oldest, , , $newest] = $this->messages;
        $newest->thread?->removeMessage($newest);
        $oldest->thread?->addMessage($newest);

        $this->em->flush();
    }

    public function testACollapsedPageIsWindowedOverThreads(): void
    {
        $result = $this->query(['collapseThreads' => true, 'position' => 1, 'limit' => 1]);

        self::assertSame([$this->id(2)], $result['ids'], 'position 1 is the second conversation, not the second message');
        self::assertSame(3, $result['total']);
    }

    public function testAnUncollapsedPageIsWindowedOverMessages(): void
    {
        $result = $this->query(['position' => 1, 'limit' => 2]);

        self::assertSame([$this->id(2), $this->id(1)], $result['ids']);
        self::assertSame(4, $result['total']);
    }

    public function testATotalTheClientDeclinedIsNotCounted(): void
    {
        $result = $this->query(['calculateTotal' => false, 'limit' => 1]);

        self::assertSame([$this->id(3)], $result['ids']);
        self::assertArrayNotHasKey('total', $result);
    }

    private function id(int $index): string
    {
        return (string) $this->messages[$index]->id;
    }

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function query(array $arguments): array
    {
        return self::getContainer()->get(EmailQueryMethod::class)->handle(
            $arguments + ['accountId' => $this->accountId()],
            $this->context(),
        );
    }
}
