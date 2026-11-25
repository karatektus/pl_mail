<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MessagePriority;
use App\Domain\Helper\MessageParser;
use App\Service\Imap\MessageSyncer;
use App\Service\Mail\HeaderNormalizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Priority was only ever written for outgoing mail; MessagePriority knew how
 * to read it back and nothing called it. The IMAP ingest now does, and this
 * pins the header names it reads against what the parser actually produces.
 */
final class InboundPriorityTest extends KernelTestCase
{
    public function testTheSendersPriorityIsReadOnIngest(): void
    {
        self::assertSame(MessagePriority::High, $this->priorityOf("X-Priority: 1 (Highest)\r\nSubject: Dringend\r\n"));
        self::assertSame(MessagePriority::Low, $this->priorityOf("Importance: low\r\nX-Priority: 1\r\nSubject: Egal\r\n"));
        self::assertNull($this->priorityOf("Subject: Nichts gesagt\r\n"));
    }

    private function priorityOf(string $rawHeader): ?MessagePriority
    {
        self::bootKernel();

        $container = self::getContainer();

        // The same bag MessageSyncer::buildFromParsed() normalises.
        $headers = $container->get(HeaderNormalizer::class)->normalize(MessageParser::parse($rawHeader . "\r\n")->headers);

        return $container->get(MessageSyncer::class)->priorityOf($headers);
    }
}
