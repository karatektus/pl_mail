<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Which unread messages a conversation counts (#470).
 *
 * Spam and the bin do not count while the conversation has mail outside them,
 * so a spam reply cannot make an Inbox conversation bold. A conversation of
 * set-aside mail only counts all of it, for the Spam and Trash rows.
 */
final class MessageThreadUnreadTest extends TestCase
{
    public function testUnreadMailOutsideSpamAndTheBinCounts(): void
    {
        self::assertSame(2, MessageThread::unreadAmong([
            $this->message(LabelRole::Inbox, read: false),
            $this->message(LabelRole::Inbox, read: false),
            $this->message(LabelRole::Inbox, read: true),
        ]));
    }

    public function testSpamAndTheBinDoNotCountBesideOtherMail(): void
    {
        self::assertSame(0, MessageThread::unreadAmong([
            $this->message(LabelRole::Inbox, read: true),
            $this->message(LabelRole::Spam, read: false),
            $this->message(LabelRole::Trash, read: false),
        ]));
    }

    public function testAConversationOfSetAsideMailOnlyCountsAllOfIt(): void
    {
        self::assertSame(2, MessageThread::unreadAmong([
            $this->message(LabelRole::Spam, read: false),
            $this->message(LabelRole::Spam, read: true),
            $this->message(LabelRole::Trash, read: false),
        ]));
    }

    public function testNoMessagesCountNothing(): void
    {
        self::assertSame(0, MessageThread::unreadAmong([]));
    }

    public function testTheThreadSaysWhetherItHoldsSetAsideMail(): void
    {
        $thread = new MessageThread();
        $thread->addLabel($this->label(LabelRole::Inbox));

        self::assertFalse($thread->holdsSetAsideMail());

        $thread->addLabel($this->label(LabelRole::Spam));

        self::assertTrue($thread->holdsSetAsideMail());
    }

    private function message(LabelRole $role, bool $read): Message
    {
        $message         = new Message();
        $message->seenAt = true === $read ? new DateTimeImmutable('2026-10-01 09:00:00') : null;
        $message->addLabel($this->label($role));

        return $message;
    }

    private function label(LabelRole $role): Label
    {
        $label       = new Label();
        $label->role = $role;

        return $label;
    }
}
