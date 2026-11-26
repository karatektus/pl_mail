<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ImportContactsHandler;
use App\Infrastructure\Messaging\Message\ImportContactsMessage;
use App\Service\Job\JobUploadStore;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;

/**
 * A confirmed contacts import runs on the bulk worker, in chunks (#672).
 *
 * It used to run inside the confirm request, where a thousand contacts took
 * over forty seconds — every contact's flushes looked at every contact before
 * it. The claims: the whole file arrives, the job counts it, the staged file
 * is gone afterwards, and a file that is no longer there fails the job with a
 * sentence instead of importing nothing silently.
 *
 * 250 contacts, so the import crosses ImportRunner's chunk boundary twice: the
 * entity manager is cleared there, and a contact or an owner held across the
 * clear is exactly what would break.
 */
final class ImportContactsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private JobUploadStore $uploads;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->uploads    = $container->get(JobUploadStore::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheWholeFileArrivesAndTheJobCountsIt(): void
    {
        $user  = MailFixtures::user($this->em, 'imp');
        $file  = $this->stage($user, 250);
        $jobId = $this->job($user, $file);

        $this->work($jobId);

        $this->em->clear();

        $job = $this->em->find(BackgroundJob::class, $jobId);

        self::assertSame(JobState::Done, $job?->state, (string) $job?->failureReason);
        self::assertSame(250, $job->total);
        self::assertSame(250, $job->processed);
        self::assertSame(250, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contacts_contact WHERE usr_id = ?', [$user->id]));
        self::assertSame(
            250,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM contacts_contact_email e JOIN contacts_contact c ON c.id = e.contact_id WHERE c.usr_id = ?',
                [$user->id],
            ),
            'an address went missing across a chunk boundary',
        );
        self::assertNull($this->uploads->path((int) $user->id, $file), 'the staged file is left behind');
    }

    public function testAFileThatIsGoneFailsTheJobWithASentence(): void
    {
        $user  = MailFixtures::user($this->em, 'imp');
        $file  = $this->stage($user, 1);
        $jobId = $this->job($user, $file);

        $this->uploads->forget((int) $user->id, $file);

        $this->work($jobId);

        $this->em->clear();

        $job = $this->em->find(BackgroundJob::class, $jobId);

        self::assertSame(JobState::Failed, $job?->state);
        self::assertNotEmpty($job->failureReason);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contacts_contact WHERE usr_id = ?', [$user->id]));
    }

    /**
     * Asked of the routing, for BulkStatusOffloadTest's reason: `bulk` is a
     * real, consumed queue in the test stack. Unrouted, a message is handled
     * inline — in the request each of these exists to get out of.
     */
    public function testEveryNewJobIsHandedToTheBulkQueue(): void
    {
        $locator = self::getContainer()->get('messenger.senders_locator');

        foreach ([
            new ImportContactsMessage(1),
            new \App\Infrastructure\Messaging\Message\ImportCalendarMessage(1),
            new \App\Infrastructure\Messaging\Message\ResortRemovedCategoryMessage(1),
        ] as $message) {
            self::assertSame(
                ['bulk'],
                array_keys(iterator_to_array($locator->getSenders(new Envelope($message)))),
                $message::class . ' is not addressed to the bulk queue',
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function stage(User $user, int $contacts): string
    {
        $vcf = '';

        for ($i = 0; $i < $contacts; ++$i) {
            $vcf .= "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Person {$i}\r\nN:Example;Person {$i};;;\r\n"
                . "EMAIL;TYPE=work:person{$i}@example.test\r\nEND:VCARD\r\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'vcf');
        self::assertIsString($path);
        file_put_contents($path, $vcf);

        return $this->uploads->keep((int) $user->id, new UploadedFile($path, 'contacts.vcf', 'text/vcard', null, true), 'vcf');
    }

    private function job(User $user, string $file): int
    {
        $job       = new BackgroundJob($user, JobKind::ImportContacts);
        $job->view = ['scope' => 'contacts', 'value' => '', 'unreadOnly' => false, 'file' => $file, 'format' => 'vcf'];

        $this->em->persist($job);
        $this->em->flush();

        return (int) $job->id;
    }

    /** What the bulk worker does with the message the job was queued under. */
    private function work(int $jobId): void
    {
        (self::getContainer()->get(ImportContactsHandler::class))(new ImportContactsMessage($jobId));
    }
}
