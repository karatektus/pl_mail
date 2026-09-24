<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\ImapFolderLocator;
use App\Entity\Mail\Mailbox;
use App\Tests\Service\Imap\FakeListingClient;
use PHPUnit\Framework\TestCase;

/**
 * Two folders with the same leaf name are two folders.
 *
 * getFolder("Invoices") answered the first "…/Invoices" on the account, so both
 * rows synced one server folder. The locator goes by the stored path.
 */
final class ImapFolderLocatorTest extends TestCase
{
    public function testFoldersThatShareALeafNameAreTellApart(): void
    {
        $client = new FakeListingClient(['Archive.Invoices' => [], 'Projects.Invoices' => []]);

        $projects           = new Mailbox();
        $projects->name     = 'Invoices';
        $projects->fullPath = 'Projects.Invoices';

        $archive           = new Mailbox();
        $archive->name     = 'Invoices';
        $archive->fullPath = 'Archive.Invoices';

        self::assertSame('Projects.Invoices', ImapFolderLocator::of($client, $projects)?->path);
        self::assertSame('Archive.Invoices', ImapFolderLocator::of($client, $archive)?->path);
    }

    /**
     * GMX's Trash, as its LIST spells it. getFolder() encodes the stored path
     * again and finds nothing, which is what every move to and purge in a
     * non-ASCII folder ran into.
     */
    public function testAFolderWithAnUmlautIsFoundAtItsStoredPath(): void
    {
        $client = new FakeListingClient(['INBOX' => [], 'Gel&APY-scht' => ['\\Trash']]);

        self::assertNull($client->getFolder('Gel&APY-scht'), 'the library behaviour the bug rested on');
        self::assertSame('Gel&APY-scht', ImapFolderLocator::atPath($client, 'Gel&APY-scht')?->path);
    }
}
