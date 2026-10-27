<?php

declare(strict_types=1);

namespace App\Tests\Service\JmapClient;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Infrastructure\Messaging\Handler\ApplyJmapChangesHandler;
use App\Infrastructure\Messaging\Message\ApplyJmapChangesMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\JmapClient\JmapAccountSyncer;
use App\Service\JmapClient\JmapImporter;
use App\Service\JmapClient\JmapImportStep;
use App\Service\Label\LabelChangePropagator;
use App\Service\Mail\JmapApiSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The whole JMAP client against a real server (#27: "test with stalwart").
 *
 * Opt-in, because a real server is not part of the suite: it runs when
 * JMAP_TEST_SESSION_URL, JMAP_TEST_USERNAME and JMAP_TEST_PASSWORD name an
 * account on a JMAP server — a Stalwart started for the purpose, see
 * CONTRIBUTING.md — and is skipped otherwise. The account's mail is changed:
 * use one that holds nothing worth keeping.
 *
 * The fake server the other JMAP tests use is built from the RFCs; this is
 * the check that a server built by somebody else reads them the same way.
 * Each step goes through plMail's own services and then asks the server
 * directly whether the change landed.
 */
#[Group('stalwart')]
final class StalwartRoundTripTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Account $account;

    protected function setUp(): void
    {
        $sessionUrl = getenv('JMAP_TEST_SESSION_URL');
        $username   = getenv('JMAP_TEST_USERNAME');
        $password   = getenv('JMAP_TEST_PASSWORD');

        if (false === $sessionUrl || false === $username || false === $password || '' === $sessionUrl) {
            self::markTestSkipped('Set JMAP_TEST_SESSION_URL, JMAP_TEST_USERNAME and JMAP_TEST_PASSWORD to run against a JMAP server.');
        }

        self::bootKernel();

        // The test's own server is on the test network, which the integration
        // client refuses by design; this is the one place that is wanted.
        static::getContainer()->set('app.http_client.integration', HttpClient::create());

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user            = new User();
        $user->email     = 'stalwart-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $user->nameFirst = 'Stalwart';
        $user->nameLast  = 'Round trip';
        $user->password  = 'x';
        $this->em->persist($user);

        $account                   = new Account();
        $account->usr              = $user;
        $account->name             = 'Stalwart';
        $account->email            = (string) (getenv('JMAP_TEST_ADDRESS') ?: $username);
        $account->username         = $username;
        $account->password         = $password;
        $account->incomingProtocol = IncomingProtocol::Jmap;
        $account->imapHost         = (string) parse_url($sessionUrl, PHP_URL_HOST);
        $account->imapPort         = 443;
        $account->jmapSessionUrl   = $sessionUrl;
        $account->authType         = 'password';
        $account->isActive         = true;
        $this->em->persist($account);
        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;
    }

    protected function tearDown(): void
    {
        if (true === isset($this->em) && true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testMailIsImportedChangedAndSentThroughTheServer(): void
    {
        $subject = 'plMail round trip ' . bin2hex(random_bytes(4));
        $emailId = $this->seedInboxMessage($subject);

        $this->syncAndImport();

        $row = $this->rowWithSubject($subject);

        self::assertSame($emailId, $row->jmapId, 'the server Email is the row, by id');
        self::assertNull($row->seenAt);

        static::getContainer()->get(LabelChangePropagator::class)->markRead([$row], true);
        $this->em->flush();
        $this->applyQueuedChanges();

        self::assertArrayHasKey('$seen', $this->serverEmail($emailId)['keywords'] ?? [], 'read here is read on the server');

        if (false === $this->jmap()->session($this->account)->has(JmapCapability::SUBMISSION)) {
            return;
        }

        $messageId = bin2hex(random_bytes(8)) . '@plmail.test';
        $email     = new Email()->from($this->account->email)->to($this->account->email)->subject($subject . ' (sent)')->text('Sent by the round-trip test.');
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        $sender = static::getContainer()->get(JmapApiSender::class);
        $sender->deliver($email, $this->account);

        self::assertTrue($sender->isInSent($this->account, $messageId), 'the server filed the sent copy in Sent');
    }

    // ---------------------------------------------------------------- helpers

    private function seedInboxMessage(string $subject): string
    {
        $jmap      = $this->jmap();
        $accountId = (string) $jmap->session($this->account)->accountFor(JmapCapability::MAIL);
        $raw       = sprintf("From: Round Trip <roundtrip@example.test>\r\nTo: %s\r\nSubject: %s\r\nMessage-ID: <%s@plmail.test>\r\nDate: %s\r\n\r\nSeeded by StalwartRoundTripTest.\r\n", $this->account->email, $subject, bin2hex(random_bytes(8)), date(DATE_RFC2822));
        $blobId    = $jmap->upload($this->account, $accountId, $raw, 'message/rfc822');

        $boxes = $jmap->call($this->account, [JmapCapability::MAIL], [
            ['Mailbox/query', ['accountId' => $accountId, 'filter' => ['role' => 'inbox']], 'q'],
        ])->get('q', 'Mailbox/query');

        $imported = $jmap->call($this->account, [JmapCapability::MAIL], [
            ['Email/import', ['accountId' => $accountId, 'emails' => ['m' => [
                'blobId'     => $blobId,
                'mailboxIds' => [(string) $boxes['ids'][0] => true],
            ]]], 'i'],
        ])->get('i', 'Email/import');

        return (string) $imported['created']['m']['id'];
    }

    /** @return array<string, mixed> */
    private function serverEmail(string $emailId): array
    {
        $jmap      = $this->jmap();
        $accountId = (string) $jmap->session($this->account)->accountFor(JmapCapability::MAIL);

        $result = $jmap->call($this->account, [JmapCapability::MAIL], [
            ['Email/get', ['accountId' => $accountId, 'ids' => [$emailId], 'properties' => ['mailboxIds', 'keywords']], 'g'],
        ])->get('g', 'Email/get');

        return $result['list'][0] ?? [];
    }

    private function syncAndImport(): void
    {
        static::getContainer()->get(JmapAccountSyncer::class)->sync($this->account);

        $importer = static::getContainer()->get(JmapImporter::class);

        for ($page = 0; $page < 200; ++$page) {
            if (JmapImportStep::Finished === $importer->importPage($this->em->find(Account::class, $this->account->id))) {
                break;
            }
        }

        $this->account = $this->em->find(Account::class, $this->account->id);
    }

    private function applyQueuedChanges(): void
    {
        /** @var InMemoryTransport $queue */
        $queue   = static::getContainer()->get('messenger.transport.export');
        $handler = static::getContainer()->get(ApplyJmapChangesHandler::class);

        foreach ($queue->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof ApplyJmapChangesMessage) {
                $handler($message);
            }
        }

        $queue->reset();
    }

    private function rowWithSubject(string $subject): Message
    {
        $row = static::getContainer()->get(MessageRepository::class)->findOneBy(['account' => $this->account->id, 'subject' => $subject]);

        self::assertInstanceOf(Message::class, $row, 'the seeded message was imported');

        return $row;
    }

    private function jmap(): JmapClient
    {
        return static::getContainer()->get(JmapClient::class);
    }
}
