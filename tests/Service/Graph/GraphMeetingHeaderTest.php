<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Graph\GraphMessageBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Graph never hands over the invite itself.
 *
 * A text/calendar part inside multipart/alternative is not an attachment in
 * Graph's object model, so it is absent from /messages/{id}/attachments no
 * matter how the $select is written — there is nothing to widen and no
 * $expand that puts it there as MIME. What Graph does say is
 * meetingMessageType on the message resource, and that is enough to know which
 * messages are worth fetching the raw MIME for later.
 *
 * It is kept in the header bag rather than a column because $headers is
 * already free-form jsonb that MessageCategorizer reads the same way, so this
 * costs no migration — and because it is persisted, extraction can be re-run
 * over old mail without a resync, which is the property the whole extraction
 * design rests on.
 */
final class GraphMeetingHeaderTest extends KernelTestCase
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

    public function testAnInviteIsMarkedOnTheRow(): void
    {
        $message = $this->builder->build($this->payload('meetingRequest'), $this->account, []);

        self::assertSame(
            'meetingRequest',
            ($message->getHeaders() ?? [])[GraphMessageBuilder::MEETING_TYPE_HEADER] ?? null,
        );
    }

    /** Cancellations and responses matter too — REQUEST is not the only one. */
    public function testEveryMeetingTypeIsCarried(): void
    {
        foreach (['meetingRequest', 'meetingCancelled', 'meetingAccepted', 'meetingDeclined'] as $type) {
            $message = $this->builder->build($this->payload($type), $this->account, []);

            self::assertSame(
                $type,
                ($message->getHeaders() ?? [])[GraphMessageBuilder::MEETING_TYPE_HEADER] ?? null,
                $type,
            );
        }
    }

    /**
     * Ordinary mail must not gain the header. Graph sends "none" for a message
     * that is not a meeting at all, and writing that would make every message
     * look like a candidate to the extractor.
     */
    public function testOrdinaryMailIsNotMarked(): void
    {
        foreach (['none', 'None', '', null] as $value) {
            $payload = $this->payload('x');

            if (null === $value) {
                unset($payload['meetingMessageType']);
            } else {
                $payload['meetingMessageType'] = $value;
            }

            $message = $this->builder->build($payload, $this->account, []);

            self::assertArrayNotHasKey(
                GraphMessageBuilder::MEETING_TYPE_HEADER,
                $message->getHeaders() ?? [],
                var_export($value, true),
            );
        }
    }

    /** The sender's own headers still come through beside it. */
    public function testRealHeadersAreUnaffected(): void
    {
        $message = $this->builder->build($this->payload('meetingRequest'), $this->account, []);
        $headers = $message->getHeaders() ?? [];

        self::assertArrayHasKey('x-custom', $headers);
        self::assertSame('kept', $headers['x-custom']);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(string $meetingType): array
    {
        return [
            'id'                 => 'graph-' . uniqid('', true),
            'internetMessageId'  => '<' . uniqid('', true) . '@example.test>',
            'subject'            => 'Standup',
            'meetingMessageType' => $meetingType,
            'from'               => ['emailAddress' => ['name' => 'Organiser', 'address' => 'organiser@example.test']],
            'toRecipients'       => [['emailAddress' => ['address' => 'me@example.test']]],
            'receivedDateTime'   => '2026-08-03T09:00:00Z',
            'body'               => ['contentType' => 'html', 'content' => '<p>Standup</p>'],
            'internetMessageHeaders' => [
                ['name' => 'X-Custom', 'value' => 'kept'],
            ],
        ];
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'graph-meeting-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast = 'Meeting';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Graph Meeting Fixture')
            ->setUsername('graph-meeting-fixture@example.test')
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
