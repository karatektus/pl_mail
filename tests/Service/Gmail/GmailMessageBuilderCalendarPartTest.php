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
 * A Gmail account used to lose its meeting invites entirely.
 *
 * The attachment gate requires a filename or a Content-ID, which is right for
 * its purpose — keeping the text/plain and text/html body parts out of the
 * attachment list — and wrong for a calendar invite, which has neither. Google
 * Calendar sends `text/calendar; method=REQUEST` inside multipart/alternative,
 * unnamed, and when it is small it arrives as base64 in `body.data` with no
 * attachmentId at all. It matched no branch, so no MessagePart was written and
 * there was nothing for extraction to find.
 *
 * The payloads here are the three shapes that actually arrive.
 */
final class GmailMessageBuilderCalendarPartTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private GmailMessageBuilder $builder;
    private Account $account;

    /** @var list<string> */
    private array $written = [];

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
        foreach ($this->written as $path) {
            @unlink($path);
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The common shape, and the one that was lost completely: no attachmentId,
     * no filename, no Content-ID — just bytes inline in a multipart/alternative
     * beside the text and the HTML.
     */
    public function testAnInlineInviteIsKeptWithItsBytes(): void
    {
        $message = $this->build($this->payloadWithInlineInvite());

        $part = $this->calendarPart($message);

        self::assertNotNull($part, 'the invite must produce a MessagePart');
        self::assertSame('text/calendar', $part->contentType);
        self::assertTrue($part->isInline);

        // A real path, not a gmail:// stub — the bytes were already in hand, so
        // there is nothing for AttachmentResolver to go back for.
        self::assertStringStartsNotWith('gmail://', (string) $part->storagePath);
        self::assertGreaterThan(0, (int) $part->size);
    }

    /**
     * The paperclip is the tell that this went wrong: an invite counted as an
     * attachment puts one on every meeting in the thread list, plus an
     * "invite.ics" chip nobody asked for.
     */
    public function testAnInviteDoesNotMakeTheMessageLookLikeItHasAttachments(): void
    {
        $message = $this->build($this->payloadWithInlineInvite());

        self::assertFalse((bool) $message->hasAttachments());
    }

    /**
     * The larger shape: Gmail gives it an attachmentId instead of inlining it,
     * still with no filename and no Content-ID. Kept as a lazy stub, since the
     * bytes genuinely are elsewhere.
     */
    public function testAnInviteWithAnAttachmentIdIsKeptAsAStub(): void
    {
        $message = $this->build($this->payloadWithInviteAttachmentId());

        $part = $this->calendarPart($message);

        self::assertNotNull($part);
        self::assertSame('gmail://att-invite-1', $part->storagePath);
    }

    /**
     * The gate still does its job: a body part is not an attachment, however
     * the walk reaches it.
     */
    public function testBodyPartsAreStillNotPersistedAsParts(): void
    {
        $message = $this->build($this->payloadWithInlineInvite());
        $parts   = $this->partsOf($message);

        self::assertNotSame([], $parts, 'the fixture should produce at least the invite');

        foreach ($parts as $part) {
            self::assertNotContains(
                $part->contentType,
                ['text/plain', 'text/html'],
                'a body part was persisted as an attachment',
            );
        }
    }

    /** And the body itself still comes through either way. */
    public function testTheBodyIsUnaffected(): void
    {
        $message = $this->build($this->payloadWithInlineInvite());

        self::assertStringContainsString('Standup', (string) $message->getBodyText());
        self::assertStringContainsString('Standup', (string) $message->getBodyHtml());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function build(array $payload): Message
    {
        $message = $this->builder->build($payload, $this->account);

        $this->em->persist($message);
        $this->em->flush();

        foreach ($this->partsOf($message) as $part) {
            $path = (string) $part->storagePath;

            if ('' !== $path && false === str_starts_with($path, 'gmail://')) {
                $this->written[] = self::getContainer()->getParameter('kernel.project_dir') . '/' . $path;
            }
        }

        return $message;
    }

    /**
     * Read the parts back from the database rather than off the entity. The
     * OneToMany is mappedBy with no cascade, so the builder persists parts
     * without touching the in-memory collection — reading that side would test
     * Doctrine's hydration, not the builder.
     *
     * @return list<MessagePart>
     */
    private function partsOf(Message $message): array
    {
        return $this->em->getRepository(MessagePart::class)->findBy(['message' => $message]);
    }

    private function calendarPart(Message $message): ?MessagePart
    {
        foreach ($this->partsOf($message) as $part) {
            if ('text/calendar' === $part->contentType) {
                return $part;
            }
        }

        return null;
    }

    private const string ICS = "BEGIN:VCALENDAR\nMETHOD:REQUEST\nBEGIN:VEVENT\nUID:abc\nEND:VEVENT\nEND:VCALENDAR";

    /**
     * @return array<string,mixed>
     */
    private function payloadWithInlineInvite(): array
    {
        return [
            'id'       => 'gmail-invite-inline',
            'threadId' => 'thread-1',
            'labelIds' => ['INBOX'],
            'payload'  => [
                'mimeType' => 'multipart/mixed',
                'headers'  => [
                    ['name' => 'Subject', 'value' => 'Invitation: Standup'],
                    ['name' => 'From', 'value' => 'Organiser <organiser@example.test>'],
                    ['name' => 'To', 'value' => 'me@example.test'],
                    ['name' => 'Message-ID', 'value' => '<invite-inline@example.test>'],
                    ['name' => 'Date', 'value' => 'Mon, 03 Aug 2026 09:00:00 +0000'],
                ],
                'parts' => [[
                    'mimeType' => 'multipart/alternative',
                    'parts'    => [
                        [
                            'mimeType' => 'text/plain',
                            'body'     => ['data' => self::encode('Standup, 9am')],
                        ],
                        [
                            'mimeType' => 'text/html',
                            'body'     => ['data' => self::encode('<p>Standup, 9am</p>')],
                        ],
                        [
                            // No filename, no Content-ID, no attachmentId.
                            'mimeType' => 'text/calendar',
                            'body'     => ['data' => self::encode(self::ICS)],
                        ],
                    ],
                ]],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadWithInviteAttachmentId(): array
    {
        $payload = $this->payloadWithInlineInvite();
        $payload['id'] = 'gmail-invite-stub';
        $payload['payload']['headers'][3]['value'] = '<invite-stub@example.test>';
        $payload['payload']['parts'][0]['parts'][2] = [
            'mimeType' => 'text/calendar',
            'body'     => ['attachmentId' => 'att-invite-1', 'size' => 512],
        ];

        return $payload;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user
            ->setEmail('gmail-invite-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Gmail')
            ->setNameLast('Invite')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Gmail Invite Fixture')
            ->setUsername('gmail-invite-fixture@example.test')
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
