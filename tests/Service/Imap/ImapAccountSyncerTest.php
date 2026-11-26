<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSession;
use App\Service\Imap\ImapDeadline;
use App\Service\Imap\ImapConnector;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\Mail\Mailbox\MailboxRepository;
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
     * A flush Doctrine refuses before it opens a transaction leaves the manager
     * open, with what it refused still scheduled (#299). Left there, every
     * later folder's flush was refused on the same entities, and so was the
     * handler's flush after the sync.
     */
    public function testAFolderWhoseFlushWasRefusedOpenDoesNotPoisonTheNextOne(): void
    {
        $handedOver = $this->syncAccount(function (): void {
            // A part pointing at a message nobody persisted: the shape of the
            // erased-message leftovers, and refused the same way.
            $part              = new MessagePart();
            $part->message     = new Message();
            $part->contentType = 'text/plain';
            $part->size        = 0;
            $part->storagePath = 'nowhere';
            $this->em->persist($part);

            $this->em->flush();
        });

        self::assertTrue($handedOver[1]['open']);
        self::assertTrue($handedOver[1]['clean'], 'the next folder inherited the refused entities');
    }

    /**
     * A folder that lost the connection does not take the next folders with
     * it: they get a session of their own, which starts with nothing selected
     * and so SELECTs before its first UID command (#418).
     */
    public function testAFolderThatLostTheConnectionDoesNotFailTheNextOne(): void
    {
        $handedOver = $this->syncAccount(function (Mailbox $mailbox, ImapSession $session): void {
            $session->connection->close();

            throw new ImapConnectionException('connection lost');
        });

        self::assertTrue($handedOver[1]['connected'], 'the next folder was handed the dead connection');
    }

    /**
     * A folder cut off by the deadline ends the account sync instead: the time
     * is up, and the handler must see a failure rather than a sync that
     * reports success with half its folders missing (#418).
     */
    public function testAFolderCutOffByTheDeadlineEndsTheSync(): void
    {
        $deadline = new ImapDeadline(new NullLogger());

        try {
            $this->syncAccount(function (Mailbox $mailbox, ImapSession $session) use ($deadline): void {
                $deadline->watchSession($session->connection);
                $deadline->expire();

                $session->connection->command('NOOP');
            }, $deadline);

            self::fail('a sync the deadline cut off cannot report success');
        } catch (ImapConnectionException) {
            self::assertCount(1, $this->handedOver, 'no folder was synced after the cut');
        }
    }

    /** @var list<array{open: bool, managed: bool, clean: bool, connected: bool}> */
    private array $handedOver = [];

    /**
     * Syncs the account through a real ImapAccountSyncer. The first folder's
     * sync does $first; every folder reports the state it arrived in.
     *
     * In call order rather than by id: the folders come from an unordered
     * findBy(), and which of the two goes first is not this test's business.
     *
     * @return list<array{open: bool, managed: bool, clean: bool, connected: bool}>
     */
    private function syncAccount(\Closure $first, ?ImapDeadline $deadline = null): array
    {
        $this->handedOver = [];

        $folders = $this->createStub(MessageSyncer::class);
        $folders->method('syncMailbox')->willReturnCallback(
            function (Mailbox $mailbox, ImapSession $session) use ($first): void {
                $this->handedOver[] = [
                    'open'      => $this->em->isOpen(),
                    'managed'   => $this->em->contains($mailbox),
                    'clean'     => [] === $this->em->getUnitOfWork()->getScheduledEntityInsertions(),
                    'connected' => $session->connection->isOpen(),
                ];

                if (1 === count($this->handedOver)) {
                    $first($mailbox, $session);
                }
            },
        );

        $structure = $this->createStub(MailboxSyncer::class);
        $structure->method('syncForAccount')->willReturn(
            ['created' => 0, 'updated' => 0, 'deleted' => 0, 'renamed' => 0, 'missing' => 0],
        );

        // Only logged in to: the folder sync above is a stand-in.
        new FakeImapServer(static::getContainer()->get(ImapLoop::class))->pointAt($this->account);

        // Flushed: a failed folder clears the manager, and a session opened
        // after that reads the account back from the database.
        $this->em->flush();

        $syncer = new ImapAccountSyncer(
            static::getContainer()->get(MailboxRepository::class),
            $structure,
            $folders,
            static::getContainer()->get(ImapConnector::class),
            new NullLogger(),
            $this->em,
            static::getContainer()->get(ManagerRegistry::class),
            $deadline ?? new ImapDeadline(new NullLogger()),
        );

        $syncer->sync($this->account);

        self::assertCount(2, $this->handedOver, 'both folders were synced');

        return $this->handedOver;
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
