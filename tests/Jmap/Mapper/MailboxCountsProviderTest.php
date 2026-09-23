<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Mapper;

use App\Jmap\Mapper\MailboxCountsProvider;
use App\Repository\Mail\MessageRepository;
use PHPUnit\Framework\TestCase;

/**
 * Mailbox/get only pays for the counts it prints.
 *
 * Both grains are a full aggregate over the account's labelled mail, and every
 * Mailbox/get ran both — including the ones asking for nothing but names and
 * roles, which is what a client refreshing its sidebar structure sends.
 */
final class MailboxCountsProviderTest extends TestCase
{
    public function testNoCountIsReadWhenNoCountPropertyIsAsked(): void
    {
        $messages = $this->createMock(MessageRepository::class);
        $messages->expects(self::never())->method('countEmailsPerLabelForAccount');
        $messages->expects(self::never())->method('countThreadsPerLabelForAccount');

        (new MailboxCountsProvider($messages))->forAccount(7, ['name', 'role']);
    }

    public function testOnlyTheAskedGrainIsReadAndOnlyForTheNamedLabels(): void
    {
        $messages = $this->createMock(MessageRepository::class);
        $messages->expects(self::once())
            ->method('countEmailsPerLabelForAccount')
            ->with(7, [3, 4])
            ->willReturn([3 => ['total' => 2, 'unread' => 1]]);
        $messages->expects(self::never())->method('countThreadsPerLabelForAccount');

        $counts = (new MailboxCountsProvider($messages))->forAccount(7, ['unreadEmails'], [3, 4]);

        self::assertSame(1, $counts->forLabel(3)['unreadEmails']);
    }
}
