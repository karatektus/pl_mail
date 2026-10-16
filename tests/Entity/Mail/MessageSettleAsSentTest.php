<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * A draft that was sent somewhere else stops being one here.
 *
 * Sync stores a draft once, as an unsent draft, and a delta on a stored message
 * does not rewrite its flags. So when the user sends it from Outlook or Gmail
 * the row stayed `\Draft` with no sentAt, which the editor opens and the send
 * queue will send again. This is the one place that says what the draft
 * becoming sent means for the row.
 */
final class MessageSettleAsSentTest extends TestCase
{
    public function testADraftBecomesASentMessage(): void
    {
        $draft = $this->message([MessageFlag::DRAFT->value, MessageFlag::SEEN->value]);
        $at    = new DateTimeImmutable('2026-10-14 15:00:00');

        $draft->settleAsSent($at);

        self::assertFalse($draft->isDraft());
        self::assertSame($at, $draft->sentAt);
        // The rest of the flags are none of its business.
        self::assertSame([MessageFlag::SEEN->value], $draft->flags);
    }

    public function testTheThreadIsRankedByTheTimeItWasSent(): void
    {
        $draft  = $this->message([MessageFlag::DRAFT->value]);
        $thread = new MessageThread();

        $thread->lastMessageAt = new DateTimeImmutable('2026-10-14 09:00:00');
        $draft->thread         = $thread;

        $draft->settleAsSent(new DateTimeImmutable('2026-10-14 15:00:00'));

        self::assertSame('2026-10-14 15:00:00', $thread->lastMessageAt->format('Y-m-d H:i:s'));
    }

    /**
     * A draft stored before synced drafts were kept unsent has a sentAt
     * already (its receivedAt). It keeps it, and ranks the thread by it, not by
     * the moment the sync noticed: otherwise re-reading old drafts, which an
     * upgrade does to every Gmail mailbox, floats each of their conversations
     * to the top.
     */
    public function testADraftThatAlreadyHasASentAtKeepsItAndRanksByIt(): void
    {
        $draft  = $this->message([MessageFlag::DRAFT->value]);
        $thread = new MessageThread();

        $draft->sentAt         = new DateTimeImmutable('2026-10-14 10:00:00');
        $thread->lastMessageAt = new DateTimeImmutable('2026-10-14 09:00:00');
        $draft->thread         = $thread;

        $draft->settleAsSent(new DateTimeImmutable('2026-10-14 15:00:00'));

        self::assertFalse($draft->isDraft());
        self::assertSame('2026-10-14 10:00:00', $draft->sentAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-14 10:00:00', $thread->lastMessageAt->format('Y-m-d H:i:s'));
    }

    /** Never backwards: a newer message in the thread keeps its place. */
    public function testANewerMessageInTheThreadKeepsItsRank(): void
    {
        $draft  = $this->message([MessageFlag::DRAFT->value]);
        $thread = new MessageThread();

        $thread->lastMessageAt = new DateTimeImmutable('2026-10-14 16:00:00');
        $draft->thread         = $thread;

        $draft->settleAsSent(new DateTimeImmutable('2026-10-14 15:00:00'));

        self::assertSame('2026-10-14 16:00:00', $thread->lastMessageAt->format('Y-m-d H:i:s'));
    }

    /**
     * Anything that is not an unsent draft is left exactly as it is: the sync
     * calls this for every stored message whose provider says it is not a
     * draft, which is nearly all of them.
     */
    public function testAMessageThatIsNotADraftIsUntouched(): void
    {
        $sent   = $this->message([MessageFlag::SEEN->value]);
        $before = new DateTimeImmutable('2026-10-14 10:00:00');
        $thread = new MessageThread();

        $sent->sentAt          = $before;
        $thread->lastMessageAt = new DateTimeImmutable('2026-10-14 09:00:00');
        $sent->thread          = $thread;

        $sent->settleAsSent(new DateTimeImmutable('2026-10-14 15:00:00'));

        self::assertSame($before, $sent->sentAt);
        self::assertSame([MessageFlag::SEEN->value], $sent->flags);
        self::assertSame('2026-10-14 09:00:00', $thread->lastMessageAt->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<string> $flags
     */
    private function message(array $flags): Message
    {
        $message        = new Message();
        $message->flags = $flags;

        return $message;
    }
}
