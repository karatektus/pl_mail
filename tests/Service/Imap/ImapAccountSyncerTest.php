<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\ImapAccountSyncer;
use App\Service\Imap\MailboxSyncer;
use App\Service\Imap\MessageSyncer;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One folder's trouble stays that folder's.
 *
 * An account sync walks its folders with one entity manager, and two things a
 * folder's sync does to that manager used to reach the folders after it. A
 * failed flush closes it: every later folder then failed on its first persist,
 * each of its messages logged as unbuildable. And MessageSyncer clears it
 * between batches: every later folder was then handed over detached, and what
 * the sweep wrote onto it was flushed into nothing.
 *
 * The folder sync is a stand-in that does to the manager exactly what the real
 * one does. Both faults live between folders, which is this class, and the real
 * one could not get there without an IMAP server.
 */
final class ImapAccountSyncerTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $this->seedFolder('Archive');
        $this->seedFolder('INBOX');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFolderWhoseFlushFailedDoesNotFailTheNextOne(): void
    {
        $handedOver = $this->syncAccount(function (): void {
            // What a failed flush leaves behind: UnitOfWork closes the manager
            // on its way out, and then the error escapes.
            $this->em->close();

            throw new \RuntimeException('SQLSTATE[23505]: Unique violation');
        });

        self::assertTrue($handedOver[1]['open'], 'the next folder was handed a closed entity manager');
    }

    public function testEveryFolderIsHandedOverManagedAfterAnEarlierOneClearedTheManager(): void
    {
        // What any folder with new mail does between its batches.
        $handedOver = $this->syncAccount(fn () => $this->em->clear());

        self::assertTrue($handedOver[1]['managed'], 'the next folder was detached, so its sweep wrote nothing');
    }

    /**
     * Syncs the account through a real ImapAccountSyncer. The first folder's
     * sync does $first; every folder reports the state it arrived in.
     *
     * In call order rather than by id: the folders come from an unordered
     * findBy(), and which of the two goes first is not this test's business.
     *
     * @return list<array{open: bool, managed: bool}>
     */
    private function syncAccount(\Closure $first): array
    {
        $handedOver = [];

        $folders = $this->createStub(MessageSyncer::class);
        $folders->method('syncMailbox')->willReturnCallback(
            function (Mailbox $mailbox) use ($first, &$handedOver): void {
                $handedOver[] = [
                    'open'    => $this->em->isOpen(),
                    'managed' => $this->em->contains($mailbox),
                ];

                if (1 === count($handedOver)) {
                    $first();
                }
            },
        );

        $structure = $this->createStub(MailboxSyncer::class);
        $structure->method('syncForAccount')->willReturn(
            ['created' => 0, 'updated' => 0, 'deleted' => 0, 'renamed' => 0, 'missing' => 0],
        );

        $connections = $this->createStub(ImapConnectionFactory::class);
        $connections->method('connect')->willReturn(new FakeListingClient([]));

        $syncer = new ImapAccountSyncer(
            static::getContainer()->get(MailboxRepository::class),
            $structure,
            $folders,
            $connections,
            new NullLogger(),
            $this->em,
            static::getContainer()->get(ManagerRegistry::class),
        );

        $syncer->sync($this->account);

        self::assertCount(2, $handedOver, 'both folders were synced');

        return $handedOver;
    }

    private function seedFolder(string $name): void
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->isSyncEnabled = true;

        $this->em->persist($mailbox);
    }
}
