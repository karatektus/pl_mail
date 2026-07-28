<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Account;
use App\Entity\Message;
use App\Entity\MessageThread;
use App\Service\Mail\ThreadParticipants;
use PHPUnit\Framework\TestCase;

final class ThreadParticipantsTest extends TestCase
{
    private ThreadParticipants $participants;

    protected function setUp(): void
    {
        $this->participants = new ThreadParticipants();
    }

    /**
     * The regression: the row used to show the newest sender, so every
     * conversation the user had replied to was attributed to the user.
     */
    public function testAnsweredConversationLeadsWithTheOtherParty(): void
    {
        $thread = $this->thread(
            $this->message('joerg@example.test', 'Jörg Müller', '2026-07-28 09:00:00'),
            $this->message('me@example.test', 'Me Myself', '2026-07-28 10:00:00'),
        );

        self::assertSame(['Jörg Müller', 'me'], $this->participants->forThread($thread));
    }

    public function testEachParticipantAppearsOnceInTheOrderTheyJoined(): void
    {
        $thread = $this->thread(
            $this->message('joerg@example.test', 'Jörg Müller', '2026-07-28 09:00:00'),
            $this->message('me@example.test', 'Me Myself', '2026-07-28 10:00:00'),
            $this->message('joerg@example.test', 'Jörg Müller', '2026-07-28 11:00:00'),
            $this->message('me@example.test', 'Me Myself', '2026-07-28 12:00:00'),
        );

        self::assertSame(['Jörg Müller', 'me'], $this->participants->forThread($thread));
    }

    /** Aliases count as the reader too — that is what getOwnedAddresses() is for. */
    public function testOwnAddressIsRecognisedCaseInsensitively(): void
    {
        $thread = $this->thread($this->message('ME@Example.test', 'Me Myself', '2026-07-28 09:00:00'));

        self::assertSame(['me'], $this->participants->forThread($thread, 'me'));
    }

    /** The label is translated by the caller, so it must be honoured. */
    public function testOwnLabelIsTheOnePassedIn(): void
    {
        $thread = $this->thread(
            $this->message('joerg@example.test', 'Jörg Müller', '2026-07-28 09:00:00'),
            $this->message('me@example.test', null, '2026-07-28 10:00:00'),
        );

        self::assertSame(['Jörg Müller', 'ich'], $this->participants->forThread($thread, 'ich'));
    }

    public function testAddressStandsInForAMissingDisplayName(): void
    {
        $thread = $this->thread($this->message('joerg@example.test', null, '2026-07-28 09:00:00'));

        self::assertSame(['joerg@example.test'], $this->participants->forThread($thread));
    }

    /**
     * A thread only the reader has written in — Sent, or a draft. Every such
     * row would otherwise read "me", so the recipients stand in.
     */
    public function testThreadWrittenOnlyByTheReaderShowsItsRecipients(): void
    {
        $message = $this->message('me@example.test', 'Me Myself', '2026-07-28 09:00:00')
            ->setToAddresses([
                ['name' => 'Jörg Müller', 'address' => 'joerg@example.test'],
                ['name' => '', 'address' => 'anna@example.test'],
            ]);

        self::assertSame(
            ['Jörg Müller', 'anna@example.test'],
            $this->participants->forThread($this->thread($message)),
        );
    }

    public function testThreadWrittenOnlyByTheReaderWithNoRecipientsStaysOnMe(): void
    {
        $thread = $this->thread($this->message('me@example.test', 'Me Myself', '2026-07-28 09:00:00'));

        self::assertSame(['me'], $this->participants->forThread($thread));
    }

    /** One line of column: the two ends identify the conversation. */
    public function testLongCastCollapsesToItsEnds(): void
    {
        $thread = $this->thread(
            $this->message('a@example.test', 'Anna', '2026-07-28 09:00:00'),
            $this->message('b@example.test', 'Bert', '2026-07-28 10:00:00'),
            $this->message('c@example.test', 'Cleo', '2026-07-28 11:00:00'),
            $this->message('d@example.test', 'Dora', '2026-07-28 12:00:00'),
        );

        self::assertSame(['Anna', '…', 'Dora'], $this->participants->forThread($thread));
    }

    public function testEmptyThreadHasNoParticipants(): void
    {
        self::assertSame([], $this->participants->forThread($this->thread()));
    }

    private function thread(Message ...$messages): MessageThread
    {
        $account = new Account()->setEmail('me@example.test');
        $thread  = new MessageThread()->setAccount($account);

        foreach ($messages as $message) {
            $thread->addMessage($message);
        }

        return $thread;
    }

    private function message(string $from, ?string $name, string $receivedAt): Message
    {
        return new Message()
            ->setFromAddress($from)
            ->setFromName($name)
            ->setReceivedAt(new \DateTimeImmutable($receivedAt));
    }
}
