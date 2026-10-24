<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Gmail\GmailMessageBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An unsent draft has no sentAt, whoever wrote it.
 *
 * Gmail stamps a draft with an internalDate like any other message, and the
 * builder turned that into both receivedAt and sentAt. Everything that reads
 * "sentAt is set" as "this has gone out" (the editor above all, which answers
 * 404 for a message that is not an unsent draft) then refused a draft that
 * arrived by sync. receivedAt stays: it is when the row appeared.
 */
final class GmailDraftSentAtTest extends KernelTestCase
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

    public function testADraftHasNoSentAtButKeepsItsReceivedAt(): void
    {
        $message = $this->builder->build($this->payload(['DRAFT']), $this->account);

        // Presence companions: it IS a draft, and it still has its date.
        self::assertTrue($message->hasFlag(MessageFlag::DRAFT));
        self::assertSame(gmmktime(14, 15, 51, 10, 14, 2026), $message->receivedAt?->getTimestamp());

        self::assertNull($message->sentAt);
    }

    public function testAnInboxMessageStillGetsItsDateAsSentAt(): void
    {
        $message = $this->builder->build($this->payload(['INBOX']), $this->account);

        self::assertFalse($message->hasFlag(MessageFlag::DRAFT));
        self::assertSame(gmmktime(14, 15, 51, 10, 14, 2026), $message->sentAt?->getTimestamp());
    }

    /**
     * @param list<string> $labelIds
     *
     * @return array<string,mixed>
     */
    private function payload(array $labelIds): array
    {
        return [
            'id'           => 'draft-' . uniqid('', true),
            'threadId'     => 'thread-draft',
            'labelIds'     => $labelIds,
            'internalDate' => (string) (gmmktime(14, 15, 51, 10, 14, 2026) * 1000),
            'payload'      => [
                'mimeType' => 'text/plain',
                'headers'  => [
                    ['name' => 'Subject', 'value' => 'Draft or not'],
                    ['name' => 'From', 'value' => 'me@example.test'],
                    ['name' => 'Message-ID', 'value' => '<draft-' . uniqid('', true) . '@example.test>'],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode('Hi'), '+/', '-_'), '=')],
            ],
        ];
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'gmail-draft-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'Draft';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'gmail-draft-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'oauth_google';
        $account->isActive       = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
