<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Infrastructure\ImapClient\ImapSession;
use App\Service\Imap\ImapUidPresence;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * "Is this UID still there?" is answered by the search, without building the
 * message (#357), against the test stack's Dovecot.
 *
 * Proton Mail Bridge held a message whose Date line was there three times.
 * The probe fetched it to see whether it existed, the parser threw, and the
 * answer was "could not tell" — on every sync, logged as an error, and the
 * duplicate the reconciler had asked about was never repaired. The same
 * message is put on the server here, and the probe must find it there.
 *
 * Null is the answer that must never be mistaken for "gone": a folder that
 * is not there says nothing about the message.
 */
final class ImapUidPresenceTest extends TestCase
{
    private ImapSession $session;

    /** @var AbstractLogger&object{logged: list<string>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $host     = getenv('IMAP_TEST_HOST');
        $password = getenv('IMAP_TEST_PASSWORD');

        if (false === $host || '' === $host || false === $password) {
            self::markTestSkipped('Set IMAP_TEST_HOST and IMAP_TEST_PASSWORD to run against an IMAP server; the test stack does.');
        }

        $connection = ImapConnection::open(new ImapLoop(), new ImapEndpoint($host, 31143, ImapSecurity::None));
        $connection->authenticate(new ImapCredentials('presence-' . bin2hex(random_bytes(6)), $password));

        $this->session = new ImapSession($connection);
        $this->logger  = new class extends AbstractLogger {
            /** @var list<string> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };
    }

    protected function tearDown(): void
    {
        if (isset($this->session)) {
            $this->session->logout();
        }
    }

    public function testAMessageTheParserCannotReadIsStillThere(): void
    {
        $this->session->create('Labels');
        $uid = $this->session->append('Labels', "Date: Mon, 2 Mar 2026 10:00:00 +0000\r\nDate: Mon, 2 Mar 2026 10:00:00 +0000\r\nDate: x\r\nSubject: Three dates\r\n\r\nBody.\r\n");

        $presence = new ImapUidPresence($this->session, $this->logger);

        self::assertTrue($presence($this->mailbox('Labels'), (int) $uid));
        self::assertSame([], $this->logger->logged, 'nothing to report: the server answered');
    }

    public function testAUidTheFolderDoesNotListIsGone(): void
    {
        $this->session->create('Labels');
        $uid = $this->session->append('Labels', "Subject: One\r\n\r\nBody.\r\n");

        $presence = new ImapUidPresence($this->session, $this->logger);

        self::assertFalse($presence($this->mailbox('Labels'), (int) $uid + 1));
    }

    public function testAFolderThatIsNotThereIsNoAnswerRatherThanGone(): void
    {
        $presence = new ImapUidPresence($this->session, $this->logger);

        self::assertNull($presence($this->mailbox('Not There'), 1));
    }

    private function mailbox(string $path): Mailbox
    {
        $mailbox           = new Mailbox();
        $mailbox->name     = $path;
        $mailbox->fullPath = $path;

        return $mailbox;
    }
}
