<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Gmail\GmailMessageBuilder;
use App\Service\Graph\GraphMessageBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A build that does not finish must leave nothing behind (#447).
 *
 * The Gmail and Graph builders used to persist each part as they went, and the
 * caller persisted the Message afterwards. A build that threw in between landed
 * in the batch handler's catch, which logs and moves on, so the parts stayed
 * managed and pointing at a Message nobody would ever persist. The next flush
 * then failed on them with "a new entity was found through the relationship
 * MessagePart#message", taking down a batch of messages that were fine.
 *
 * What these tests do is the state that catch leaves: build, and never persist
 * the result. Making a real build throw half way would need a fixture for one
 * particular failure, and this is every failure.
 */
final class AbandonedBuildLeavesNoPartsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

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

    public function testAnAbandonedGmailBuildDoesNotFailTheNextFlush(): void
    {
        $this->buildGmail();

        // Throws "Multiple non-persisted new entities" if the part got in.
        $this->em->flush();

        self::assertSame(0, $this->partsWritten());
    }

    public function testAnAbandonedGraphBuildDoesNotFailTheNextFlush(): void
    {
        $this->buildGraph();

        $this->em->flush();

        self::assertSame(0, $this->partsWritten());
    }

    /** The other half: nothing persists the parts by hand any more. */
    public function testPersistingAGmailMessageWritesItsParts(): void
    {
        $this->assertPartWritten($this->buildGmail());
    }

    public function testPersistingAGraphMessageWritesItsParts(): void
    {
        $this->assertPartWritten($this->buildGraph());
    }

    private function assertPartWritten(Message $message): void
    {
        $this->em->persist($message);
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(MessagePart::class)->findBy(['message' => $message]));
    }

    private function partsWritten(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_part p JOIN message m ON m.id = p.message_id WHERE m.account_id = ?',
            [$this->account->id],
        );
    }

    private function buildGmail(): Message
    {
        return self::getContainer()->get(GmailMessageBuilder::class)->build([
            'id'       => 'gmail-' . uniqid('', true),
            'threadId' => 'thread-1',
            'labelIds' => ['INBOX'],
            'payload'  => [
                'mimeType' => 'multipart/mixed',
                'headers'  => [
                    ['name' => 'Subject', 'value' => 'Report'],
                    ['name' => 'From', 'value' => 'Sender <sender@example.test>'],
                    ['name' => 'To', 'value' => 'me@example.test'],
                    ['name' => 'Message-ID', 'value' => '<' . uniqid('', true) . '@example.test>'],
                    ['name' => 'Date', 'value' => 'Mon, 03 Aug 2026 09:00:00 +0000'],
                ],
                'parts' => [
                    [
                        'mimeType' => 'text/plain',
                        'body'     => ['data' => rtrim(strtr(base64_encode('See attached'), '+/', '-_'), '=')],
                    ],
                    [
                        'mimeType' => 'application/pdf',
                        'filename' => 'report.pdf',
                        'body'     => ['attachmentId' => 'att-1', 'size' => 512],
                    ],
                ],
            ],
        ], $this->account);
    }

    private function buildGraph(): Message
    {
        return self::getContainer()->get(GraphMessageBuilder::class)->build(
            [
                'id'               => 'graph-' . uniqid('', true),
                'subject'          => 'Report',
                'from'             => ['emailAddress' => ['name' => 'Sender', 'address' => 'sender@example.test']],
                'receivedDateTime' => '2026-08-03T09:00:00Z',
                'body'             => ['contentType' => 'text', 'content' => 'See attached'],
            ],
            $this->account,
            [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id'          => 'att-1',
                'name'        => 'report.pdf',
                'contentType' => 'application/pdf',
                'size'        => 512,
            ]],
        );
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'abandoned-build-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Abandoned';
        $user->nameLast = 'Build';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Abandoned Build Fixture';
        $account->username = 'abandoned-build-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
