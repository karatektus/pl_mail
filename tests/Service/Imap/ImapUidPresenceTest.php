<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapUidPresence;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * "Is this UID still there?" is answered by the search, without building the
 * message (#357).
 *
 * Proton Mail Bridge held a message whose Date line was there three times.
 * The probe fetched it to see whether it existed, webklex handed preg_match()
 * an array, and the answer was "could not tell" — on every sync, logged as an
 * error, and the duplicate the reconciler had asked about was never repaired.
 *
 * FakeImportClient's folder answers SEARCH from the script and only builds a
 * message on FETCH, where a scripted throwable is what building it throws: so
 * a probe that fetched would fail here the way it failed against the Bridge.
 */
final class ImapUidPresenceTest extends TestCase
{
    /** @var AbstractLogger&object{logged: list<string>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };
    }

    public function testAMessageTheParserCannotReadIsStillThere(): void
    {
        $presence = $this->presence(FakeImportClient::holding([
            6331 => new \TypeError('preg_match(): Argument #2 ($subject) must be of type string, array given'),
        ]));

        self::assertTrue($presence($this->mailbox(), 6331));
        self::assertSame([], $this->logger->logged, 'nothing to report: the server answered');
    }

    public function testAUidTheFolderDoesNotListIsGone(): void
    {
        $presence = $this->presence(FakeImportClient::holding([6331 => new \TypeError('never built')]));

        self::assertFalse($presence($this->mailbox(), 6332));
    }

    private function presence(FakeImportClient $server): ImapUidPresence
    {
        $connections = $this->createStub(ImapConnectionFactory::class);
        $connections->method('connect')->willReturn($server);

        return new ImapUidPresence(new Account(), $connections, $this->logger);
    }

    private function mailbox(): Mailbox
    {
        $mailbox           = new Mailbox();
        $mailbox->name     = 'gmail.com 27-02-2026 11:08';
        $mailbox->fullPath = 'Labels/gmail.com 27-02-2026 11:08';

        return $mailbox;
    }
}
