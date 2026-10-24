<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Graph\GraphMessageBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An unsent draft has no sentAt, whoever wrote it.
 *
 * The editor, the send queue and the thread both read "sentAt is set" as "this
 * has gone out". Outlook gives a draft a receivedDateTime (when it was created)
 * and no sentDateTime, and the builder used to fall back from the second to the
 * first, so a draft that arrived by sync looked sent and the editor answered
 * 404 for it.
 *
 * receivedAt is untouched: it is when the row appeared, and a draft is still
 * ordered and listed by it.
 */
final class GraphDraftSentAtTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private GraphMessageBuilder $builder;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->builder    = $container->get(GraphMessageBuilder::class);

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
        $message = $this->builder->build($this->payload(draft: true), $this->account, []);

        // Presence companions: it IS a draft, and it still has its date.
        self::assertTrue($message->hasFlag(MessageFlag::DRAFT));
        self::assertSame(gmmktime(14, 15, 51, 10, 14, 2026), $message->receivedAt?->getTimestamp());

        self::assertNull($message->sentAt);
    }

    public function testAReceivedMessageStillGetsItsReceivedAtAsSentAt(): void
    {
        $message = $this->builder->build($this->payload(draft: false), $this->account, []);

        self::assertFalse($message->hasFlag(MessageFlag::DRAFT));
        self::assertSame(gmmktime(14, 15, 51, 10, 14, 2026), $message->sentAt?->getTimestamp());
    }

    public function testASentMessageKeepsItsOwnSentDateTime(): void
    {
        $payload                 = $this->payload(draft: false);
        $payload['sentDateTime'] = '2026-10-14T14:10:00Z';

        $message = $this->builder->build($payload, $this->account, []);

        self::assertSame(gmmktime(14, 10, 0, 10, 14, 2026), $message->sentAt?->getTimestamp());
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(bool $draft): array
    {
        return [
            'id'                => 'graph-' . uniqid('', true),
            'internetMessageId' => '<' . uniqid('', true) . '@example.test>',
            'subject'           => 'Draft or not',
            'isDraft'           => $draft,
            'from'              => ['emailAddress' => ['name' => 'Me', 'address' => 'me@example.test']],
            'toRecipients'      => [['emailAddress' => ['address' => 'you@example.test']]],
            'receivedDateTime'  => '2026-10-14T14:15:51Z',
            'body'              => ['contentType' => 'html', 'content' => '<p>Hello</p>'],
        ];
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'graph-draft-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Draft';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Graph Draft Fixture';
        $account->username       = 'graph-draft-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
