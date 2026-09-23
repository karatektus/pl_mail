<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Service\Imap\MailboxSyncer;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Folder;

/**
 * Which folder is Sent, on servers that do not call it "Sent".
 *
 * Detection used to be an English name list, so GMX's "Gesendet" and
 * Exchange's "Sent Items" had no Sent folder at all and every sent message
 * silently skipped its APPEND. The server's RFC 6154 attribute is read first
 * now, and the name list is the fallback.
 */
final class MailboxSpecialUseDetectionTest extends TestCase
{
    public function testTheServerAttributeWinsAndNamesCoverTheRest(): void
    {
        $roles = MailboxSyncer::assignSpecialUses([
            self::listed('INBOX', []),
            self::listed('INBOX.Postausgang', ['\\HasNoChildren', '\\Sent']),
            self::listed('INBOX.Gesendet', ['\\HasNoChildren']),
            self::listed('Deleted Items', []),
            self::listed('Junk E-mail', []),
            self::listed('Entw&APw-rfe', []),
        ]);

        self::assertSame(MailboxSpecialUse::INBOX, $roles['INBOX'] ?? null);
        self::assertSame(MailboxSpecialUse::SENT, $roles['INBOX.Postausgang'] ?? null, 'the \\Sent attribute names the folder, whatever it is called');
        self::assertArrayNotHasKey('INBOX.Gesendet', $roles, 'a role the server assigned is not handed out again by name');
        self::assertSame(MailboxSpecialUse::TRASH, $roles['Deleted Items'] ?? null);
        self::assertSame(MailboxSpecialUse::JUNK, $roles['Junk E-mail'] ?? null);
        self::assertSame(MailboxSpecialUse::DRAFTS, $roles['Entw&APw-rfe'] ?? null);
    }

    public function testAGermanSentFolderWithoutAttributesIsFoundByName(): void
    {
        $roles = MailboxSyncer::assignSpecialUses([
            self::listed('Gesendet', []),
        ]);

        self::assertSame(MailboxSpecialUse::SENT, $roles['Gesendet'] ?? null);
    }

    /**
     * @param list<string> $attributes
     *
     * @return array{folder: Folder, attributes: list<string>}
     */
    private static function listed(string $path, array $attributes): array
    {
        return [
            'folder'     => new Folder(new Client(Config::make()), $path, '.', $attributes),
            'attributes' => $attributes,
        ];
    }
}
