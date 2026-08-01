<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Gmail\GmailMessageBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A Gmail account used to lose every message a German sender wrote in latin-1.
 *
 * base64url decoding only undoes the transfer encoding; what falls out is
 * still in the sender's charset, and the builder never read the part's
 * Content-Type to find out which — `$part['headers']` was consulted only when
 * deciding whether an attachment was inline. So an ISO-8859-1 body went into
 * the entity with its 0xFC intact, and Postgres does not store that:
 *
 *   SELECT convert_from('\x477225fc25dfe5'::bytea, 'UTF8');
 *   ERROR: invalid byte sequence for encoding "UTF8": 0xfc
 *
 * The INSERT failed and took the rest of its batch with it, so this never
 * looked like a charset bug from the outside — it looked like mail going
 * missing.
 *
 * The flush at the end of each test is the assertion that matters; the string
 * comparisons only say the bytes are also correct.
 */
final class GmailMessageBuilderCharsetTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private GmailMessageBuilder $builder;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->builder    = $container->get(GmailMessageBuilder::class);

        $this->connection->beginTransaction();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testALatin1BodySurvivesToValidUtf8(): void
    {
        $message = $this->build($this->payload(
            charset: 'ISO-8859-1',
            text: "Gr\xfc\xdfe von J\xf6rg",
            html: "<p>Gr\xfc\xdfe von J\xf6rg</p>",
        ));

        self::assertSame('Grüße von Jörg', $message->getBodyText());
        self::assertSame('<p>Grüße von Jörg</p>', $message->getBodyHtml());
    }

    /**
     * The reason the fallback is windows-1252 and never iso-8859-1. 0x93 and
     * 0x94 are the curly quotes a Windows client emits under a latin-1 label;
     * read as the C1 control characters latin-1 actually defines there they
     * become invisible, and the sentence loses its punctuation silently.
     */
    public function testCp1252PunctuationUnderALatin1LabelIsReadAsPunctuation(): void
    {
        $message = $this->build($this->payload(
            charset: 'iso-8859-1',
            text: "Das \x93Sonderangebot\x94 \x96 nur heute",
            html: "<p>Das \x93Sonderangebot\x94</p>",
        ));

        self::assertSame('Das “Sonderangebot” – nur heute', $message->getBodyText());
        self::assertStringContainsString('“Sonderangebot”', (string) $message->getBodyHtml());
    }

    /** The overwhelmingly common case has to be byte-for-byte untouched. */
    public function testAUtf8BodyIsUnchanged(): void
    {
        $message = $this->build($this->payload(
            charset: 'UTF-8',
            text: 'Grüße 🎉',
            html: '<p>Grüße 🎉</p>',
        ));

        self::assertSame('Grüße 🎉', $message->getBodyText());
        self::assertSame('<p>Grüße 🎉</p>', $message->getBodyHtml());
    }

    /**
     * No charset parameter at all is normal for text/plain, and the part is
     * usually genuine UTF-8 — but when it is not, it still must not be able to
     * fail the INSERT.
     */
    public function testAnUndeclaredEightBitBodyIsStillStorable(): void
    {
        $message = $this->build($this->payload(
            charset: null,
            text: "Gr\xfc\xdfe",
            html: '<p>ok</p>',
        ));

        self::assertSame('Grüße', $message->getBodyText());
    }

    /** A charset name mbstring cannot use is not a reason to lose a message. */
    public function testABogusCharsetDoesNotThrow(): void
    {
        $message = $this->build($this->payload(
            charset: 'x-mac-nonsense-9000',
            text: "Gr\xfc\xdfe",
            html: '<p>ok</p>',
        ));

        self::assertTrue(mb_check_encoding((string) $message->getBodyText(), 'UTF-8'));
    }

    /**
     * The subject went through MimeHeaderHelper and the filename did not, so a
     * raw 8-bit attachment name was the same rejected INSERT by another route.
     */
    public function testARaw8BitAttachmentFilenameIsNormalised(): void
    {
        $payload = $this->payload(charset: 'UTF-8', text: 'x', html: '<p>x</p>');
        $payload['payload']['parts'][] = [
            'mimeType' => 'application/pdf',
            'filename' => "\xdcbersicht.pdf",
            'body'     => ['attachmentId' => 'att-1', 'size' => 1024],
        ];

        $message = $this->build($payload);

        self::assertSame('Übersicht.pdf', $this->attachmentPart($message)?->getFilename());
    }

    /** And an encoded word in a filename is decoded rather than displayed. */
    public function testAnEncodedWordFilenameIsDecoded(): void
    {
        $payload = $this->payload(charset: 'UTF-8', text: 'x', html: '<p>x</p>');
        $payload['payload']['parts'][] = [
            'mimeType' => 'application/pdf',
            'filename' => '=?ISO-8859-1?Q?Geb=FChren.pdf?=',
            'body'     => ['attachmentId' => 'att-2', 'size' => 1024],
        ];

        $message = $this->build($payload);

        self::assertSame('Gebühren.pdf', $this->attachmentPart($message)?->getFilename());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     */
    private function build(array $payload): Message
    {
        $message = $this->builder->build($payload, $this->account);

        $this->em->persist($message);

        // The point of the whole test: before the fix this threw
        // "invalid byte sequence for encoding UTF8".
        $this->em->flush();

        return $message;
    }

    private function attachmentPart(Message $message): ?MessagePart
    {
        $parts = $this->em->getRepository(MessagePart::class)->findBy(['message' => $message]);

        return $parts[0] ?? null;
    }

    /**
     * One multipart/alternative with a text and an HTML part, both carrying
     * the same declared charset — which is how real mail arrives.
     *
     * @return array<string,mixed>
     */
    private function payload(?string $charset, string $text, string $html): array
    {
        $contentType = static fn (string $type): array => [
            ['name' => 'Content-Type', 'value' => null === $charset ? $type : $type . '; charset=' . $charset],
        ];

        return [
            'id'       => 'gmail-charset-' . uniqid('', true),
            'threadId' => 'thread-charset',
            'labelIds' => ['INBOX'],
            'payload'  => [
                'mimeType' => 'multipart/mixed',
                'headers'  => [
                    ['name' => 'Subject', 'value' => 'Angebot'],
                    ['name' => 'From', 'value' => 'Jörg <joerg@example.test>'],
                    ['name' => 'To', 'value' => 'me@example.test'],
                    ['name' => 'Message-ID', 'value' => '<charset-' . uniqid('', true) . '@example.test>'],
                ],
                'parts' => [[
                    'mimeType' => 'multipart/alternative',
                    'parts'    => [
                        [
                            'mimeType' => 'text/plain',
                            'headers'  => $contentType('text/plain'),
                            'body'     => ['data' => self::encode($text)],
                        ],
                        [
                            'mimeType' => 'text/html',
                            'headers'  => $contentType('text/html'),
                            'body'     => ['data' => self::encode($html)],
                        ],
                    ],
                ]],
            ],
        ];
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user
            ->setEmail('gmail-charset-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Gmail')
            ->setNameLast('Charset')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Gmail Charset Fixture')
            ->setUsername('gmail-charset-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
