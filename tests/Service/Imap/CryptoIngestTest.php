<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Mailbox;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Service\Imap\ImapUidPresence;
use App\Service\Imap\MessageSyncer;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * OpenPGP and S/MIME mail is recognised as it is stored, and stored the way
 * each kind has to be.
 *
 * Through the real IMAP batch path, because the claim that matters is about
 * the row as it is first INSERTed: search_vector is generated from body_text
 * on insert, so an encrypted message whose body reached the row even once
 * would have its ciphertext — or worse, inline armor — in the search index.
 * A unit test of the detector would pass against a builder that called it a
 * line too late.
 */
final class CryptoIngestTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private int $mailboxId;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;

        $this->em->persist($mailbox);
        $this->em->flush();

        $this->mailboxId = (int) $mailbox->id;
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function shapes(): iterable
    {
        yield 'PGP/MIME encrypted' => [
            "Content-Type: multipart/encrypted; protocol=\"application/pgp-encrypted\"; boundary=\"E\"\r\n\r\n"
            . "--E\r\nContent-Type: application/pgp-encrypted\r\n\r\nVersion: 1\r\n"
            . "--E\r\nContent-Type: application/octet-stream; name=\"encrypted.asc\"\r\n"
            . "Content-Disposition: inline; filename=\"encrypted.asc\"\r\n\r\n"
            . "-----BEGIN PGP MESSAGE-----\r\n\r\nhQEMA1secretsecretsecret\r\n-----END PGP MESSAGE-----\r\n"
            . "--E--\r\n",
            'pgp_encrypted',
            true,
        ];

        yield 'PGP/MIME signed' => [
            "Content-Type: multipart/signed; micalg=pgp-sha256;\r\n protocol=\"application/pgp-signature\"; boundary=\"S\"\r\n\r\n"
            . "--S\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSigned and readable.\r\n"
            . "--S\r\nContent-Type: application/pgp-signature; name=\"signature.asc\"\r\n"
            . "Content-Disposition: attachment; filename=\"signature.asc\"\r\n\r\n"
            . "-----BEGIN PGP SIGNATURE-----\r\n\r\niHUEARYKAB0WIQ\r\n-----END PGP SIGNATURE-----\r\n"
            . "--S--\r\n",
            'pgp_signed',
            false,
        ];

        yield 'S/MIME signed' => [
            "Content-Type: multipart/signed; protocol=\"application/pkcs7-signature\"; micalg=sha-256; boundary=\"P\"\r\n\r\n"
            . "--P\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSigned and readable.\r\n"
            . "--P\r\nContent-Type: application/pkcs7-signature; name=\"smime.p7s\"\r\n"
            . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"smime.p7s\"\r\n\r\n"
            . "MIAGCSqGSIb3DQEHAqCAMIACAQEx\r\n"
            . "--P--\r\n",
            'smime_signed',
            false,
        ];

        yield 'S/MIME encrypted' => [
            "Content-Type: application/pkcs7-mime; smime-type=enveloped-data; name=\"smime.p7m\"\r\n"
            . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"smime.p7m\"\r\n\r\n"
            . "MIAGCSqGSIb3DQEHA6CAMIACAQAx\r\n",
            'smime_encrypted',
            true,
        ];

        yield 'inline PGP encrypted' => [
            "Content-Type: text/plain; charset=utf-8\r\n\r\n"
            . "-----BEGIN PGP MESSAGE-----\r\n\r\nhQEMA1secretsecretsecret\r\n-----END PGP MESSAGE-----\r\n",
            'pgp_inline_encrypted',
            true,
        ];
    }

    #[DataProvider('shapes')]
    public function testEachShapeIsRecognisedAndStoredTheWayItHasToBe(string $mime, string $crypto, bool $encrypted): void
    {
        $this->store($mime);

        $row = $this->row();

        self::assertSame($crypto, $row['crypto']);

        if (true === $encrypted) {
            self::assertSame('', (string) $row['body_text'], 'ciphertext reached body_text, and with it the search index');
            self::assertSame('', (string) $row['body_html']);
            self::assertStringNotContainsString('secret', (string) $row['search_vector']);
        } else {
            self::assertStringContainsString('Signed and readable.', (string) $row['body_text'], 'a signed message lost its body');
        }

        // The wrapping is not offered as an attachment: no signature.asc, no
        // smime.p7s, no encrypted.asc, and no paperclip for any of them.
        self::assertSame([], $this->partNames());
        self::assertFalse((bool) $row['has_attachments']);
    }

    /**
     * A plain message that carries somebody's signature.asc as an ordinary
     * attachment — a forwarded file, say — is not a signed message. The
     * header decides, not the part list.
     */
    public function testAPlainMessageWithASignatureFileAttachedIsLeftAlone(): void
    {
        $this->store(
            "Content-Type: multipart/mixed; boundary=\"M\"\r\n\r\n"
            . "--M\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHere is the file.\r\n"
            . "--M\r\nContent-Type: application/pgp-signature; name=\"signature.asc\"\r\n"
            . "Content-Disposition: attachment; filename=\"signature.asc\"\r\n\r\n"
            . "-----BEGIN PGP SIGNATURE-----\r\n\r\niHUEARYKAB0WIQ\r\n-----END PGP SIGNATURE-----\r\n"
            . "--M--\r\n",
        );

        self::assertNull($this->row()['crypto']);
        self::assertSame(['signature.asc'], $this->partNames());
    }

    /** A message that mentions armor in a sentence is not inline PGP. */
    public function testArmorQuotedInASentenceIsNotInlinePgp(): void
    {
        $this->store("Content-Type: text/plain; charset=utf-8\r\n\r\nPaste the block that starts with -----BEGIN PGP MESSAGE----- here.\r\n");

        self::assertNull($this->row()['crypto']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function store(string $mime): void
    {
        $raw = "From: Sender <sender@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Crypto shape\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: <crypto-" . bin2hex(random_bytes(4)) . "@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . $mime;

        $message = ImapMessage::fromString($raw, Config::make([
            'decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]],
        ]))->setUid(101);

        $synced   = [];
        $lowest   = null;
        $presence = new ImapUidPresence($this->account, static::getContainer()->get(ImapConnectionFactory::class), new NullLogger());

        new \ReflectionMethod(MessageSyncer::class, 'processBatch')->invokeArgs(
            static::getContainer()->get(MessageSyncer::class),
            [[$message], $this->mailboxId, (int) $this->account->id, 0, &$synced, &$lowest, $presence, []],
        );
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT crypto, body_text, body_html, has_attachments, search_vector::text AS search_vector FROM message WHERE mailbox_id = ?',
            [$this->mailboxId],
        );

        self::assertIsArray($row, 'the message was not stored at all');

        return $row;
    }

    /** @return list<string> */
    private function partNames(): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT p.filename FROM message_part p JOIN message m ON m.id = p.message_id WHERE m.mailbox_id = ?',
            [$this->mailboxId],
        );
    }
}
