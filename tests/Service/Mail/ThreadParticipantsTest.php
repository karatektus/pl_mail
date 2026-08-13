<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
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
        $message = $this->message('me@example.test', 'Me Myself', '2026-07-28 09:00:00');
        $message->toAddresses = [
            ['name' => 'Jörg Müller', 'address' => 'joerg@example.test'],
            ['name' => '', 'address' => 'anna@example.test'],
        ];

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

    // ── one name per person, not one per address ──────────────────────────

    /**
     * The reported row: a Trash thread whose sender column read "ich, ich".
     *
     * De-duplication was by ADDRESS, and this account owns two of them — its
     * email and its username. The reader wrote from each at some point, both
     * resolved to the label "me", and the column printed it twice. Which is a
     * row that says a conversation has two participants who are both you.
     */
    public function testTheReaderIsNamedOnceEvenWhenTheyWroteFromTwoOwnAddresses(): void
    {
        $thread = $this->thread(
            $this->message('joerg@example.test', 'Jörg Müller', '2026-07-28 09:00:00'),
            $this->message('me@example.test', 'Me Myself', '2026-07-28 10:00:00'),
            $this->message('me.other@example.test', 'Me Myself', '2026-07-28 11:00:00'),
        );

        self::assertSame(['Jörg Müller', 'ich'], $this->participants->forThread($thread, 'ich'));
    }

    /**
     * And the same collapse when the reader is the ONLY participant, which is
     * the case the fallback to recipients has to be reached through: two owned
     * addresses used to make `names` ['me', 'me'], which is not `[$me]`, so the
     * thread did not look self-written and the recipients never stood in.
     */
    public function testAThreadTheReaderWroteFromTwoOwnAddressesStillFallsBackToRecipients(): void
    {
        $first  = $this->message('me@example.test', 'Me Myself', '2026-07-28 09:00:00');
        $second = $this->message('me.other@example.test', 'Me Myself', '2026-07-28 10:00:00');

        $second->toAddresses = [['name' => 'Jörg Müller', 'address' => 'joerg@example.test']];

        self::assertSame(
            ['Jörg Müller'],
            $this->participants->forThread($this->thread($first, $second)),
        );
    }

    /**
     * A correspondent writing from two of their own addresses under one name is
     * the same fault with somebody else's name in it.
     */
    public function testOneCorrespondentWritingFromTwoAddressesIsNamedOnce(): void
    {
        $thread = $this->thread(
            $this->message('joerg@example.test', 'Jörg Müller', '2026-07-28 09:00:00'),
            $this->message('j.mueller@example.test', 'Jörg Müller', '2026-07-28 10:00:00'),
        );

        self::assertSame(['Jörg Müller'], $this->participants->forThread($thread));
    }

    /** Recipients collapse too — the Sent column is built from those. */
    public function testRepeatedRecipientNamesCollapse(): void
    {
        $message = $this->message('me@example.test', 'Me Myself', '2026-07-28 09:00:00');
        $message->toAddresses = [
            ['name' => 'Jörg Müller', 'address' => 'joerg@example.test'],
            ['name' => 'Jörg Müller', 'address' => 'j.mueller@example.test'],
            ['name' => 'Anna', 'address' => 'anna@example.test'],
        ];

        self::assertSame(
            ['Jörg Müller', 'Anna'],
            $this->participants->forThread($this->thread($message)),
        );
    }

    private function thread(Message ...$messages): MessageThread
    {
        // email AND username, because that is what Account::$ownedAddresses
        // falls back to before aliases are seeded — and two owned addresses is
        // precisely the shape that produced "me, me".
        $account           = new Account();
        $account->email    = 'me@example.test';
        $account->username = 'me.other@example.test';

        $thread          = new MessageThread();
        $thread->account = $account;

        foreach ($messages as $message) {
            $thread->addMessage($message);
        }

        return $thread;
    }

    private function message(string $from, ?string $name, string $receivedAt): Message
    {
        $message = new Message();
        $message->fromAddress = $from;
        $message->fromName = $name;
        $message->receivedAt = new \DateTimeImmutable($receivedAt);

        return $message;
    }
}
