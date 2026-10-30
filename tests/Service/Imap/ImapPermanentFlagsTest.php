<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Infrastructure\ImapClient\ImapLoop;
use App\Service\Imap\ImapPermanentFlags;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reading `\*` out of PERMANENTFLAGS, on the SELECT answers of the servers
 * #221 was checked against (#221 step 2), as ImapSession::select() reads them.
 */
final class ImapPermanentFlagsTest extends KernelTestCase
{
    /** Stalwart 0.11: `* OK [PERMANENTFLAGS (\Deleted … \*)] All allowed` */
    public function testAServerThatTakesNewKeywordsSaysSo(): void
    {
        self::assertTrue($this->answer([
            '* 1 EXISTS',
            '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft)',
            '* OK [PERMANENTFLAGS (\Deleted \Seen \Answered \Flagged \Draft \*)] All allowed',
            '* OK [UIDVALIDITY 4079336918] UIDs valid',
        ]));
    }

    /** A folder that keeps only the system flags. */
    public function testOnlySystemFlagsIsNo(): void
    {
        self::assertFalse($this->answer([
            '* OK [PERMANENTFLAGS (\Deleted \Seen \Answered \Flagged \Draft)] Limited',
        ]));
    }

    /** Dovecot on a read-only selection: an empty list, which is a "no". */
    public function testAnEmptyListIsNo(): void
    {
        self::assertFalse($this->answer([
            '* OK [PERMANENTFLAGS ()] Read-only mailbox.',
        ]));
    }

    /** No PERMANENTFLAGS code at all is not an answer. */
    public function testSilenceIsUnknown(): void
    {
        self::assertNull($this->answer([
            '* 1 EXISTS',
            '* OK [UIDVALIDITY 1] UIDs valid',
        ]));
    }

    /**
     * @param list<string> $untagged what the server sends before its OK
     */
    private function answer(array $untagged): ?bool
    {
        $server = new FakeImapServer(self::getContainer()->get(ImapLoop::class));
        $server->on('SELECT', static fn (): array => [...$untagged, 'OK [READ-WRITE] SELECT completed']);

        try {
            return ImapPermanentFlags::keepsKeywords($server->session()->select('INBOX')->permanentFlags);
        } finally {
            $server->stop();
        }
    }
}
