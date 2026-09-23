<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MessagePriority;
use App\Service\Imap\MessageSyncer;
use App\Service\Mail\HeaderNormalizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Header;

/**
 * Priority was only ever written for outgoing mail; MessagePriority knew how
 * to read it back and nothing called it. The IMAP ingest now does, and this
 * pins the header names it reads against what webklex actually produces
 * (it reports X-Priority as "x_priority").
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
        $header    = new Header($rawHeader, Config::make());

        // The same flattening MessageSyncer::buildMessage() does before it
        // normalises the bag.
        $raw = [];

        foreach ($header->getAttributes() as $name => $attribute) {
            $values = $attribute->toArray();

            $raw[(string) $name] = count($values) === 1
                ? (string) reset($values)
                : array_map(static fn ($v): string => (string) $v, $values);
        }

        $headers = $container->get(HeaderNormalizer::class)->normalize($raw);

        return $container->get(MessageSyncer::class)->priorityOf($headers);
    }
}
