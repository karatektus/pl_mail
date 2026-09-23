<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Gmail\GmailMessageBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A Gmail message built for a Gmailify sibling remembers which account carries it.
 *
 * Without the carrier, LabelChangePropagator asked the row's own (IMAP) account
 * whether to tell Gmail about a change, and the answer was always no.
 */
final class GmailMessageBuilderCarrierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testARowBuiltForASiblingRemembersItsCarrier(): void
    {
        $user    = $this->user();
        $imap    = $this->account($user, 'imap');
        $carrier = $this->account($user, 'gmail');
        $builder = self::getContainer()->get(GmailMessageBuilder::class);

        self::assertSame($carrier, $builder->build($this->payload(), $imap, $carrier)->gmailCarrierAccount);
        self::assertNull($builder->build($this->payload(), $carrier, $carrier)->gmailCarrierAccount);
        self::assertNull($builder->build($this->payload(), $carrier)->gmailCarrierAccount);
    }

    private function user(): User
    {
        $user            = new User();
        $user->email     = 'gmail-carrier-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'Carrier';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        return $user;
    }

    private function account(User $user, string $kind): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $kind . '-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'gmail' === $kind ? 'oauth_google' : 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'id'       => 'carrier-' . uniqid('', true),
            'threadId' => 'thread-carrier',
            'labelIds' => ['INBOX'],
            'payload'  => [
                'mimeType' => 'text/plain',
                'headers'  => [
                    ['name' => 'Subject', 'value' => 'Hello'],
                    ['name' => 'From', 'value' => 'someone@example.test'],
                    ['name' => 'Message-ID', 'value' => '<carrier-' . uniqid('', true) . '@example.test>'],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode('Hi'), '+/', '-_'), '=')],
            ],
        ];
    }
}
