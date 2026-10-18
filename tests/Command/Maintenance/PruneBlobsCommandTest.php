<?php

declare(strict_types=1);

namespace App\Tests\Command\Maintenance;

use App\Command\Maintenance\PruneBlobsCommand;
use App\Domain\Helper\UploadStorage;
use App\Repository\Mail\MessagePartRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\UploadedBlobRepository;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The orphan sweep deletes only what its own stores wrote (#214).
 *
 * Profile pictures, signature images and backgrounds live in uploads/, sealed
 * messages in raw/ — beside the files this command answers for, and named by
 * no column it reads. Walking the whole root took every one of them for an
 * orphan, and the Sunday run deleted them a week after they were made. Run
 * here against a project directory of its own, so a test cannot sweep the
 * stack's real var/.
 */
final class PruneBlobsCommandTest extends KernelTestCase
{
    private const int OLD = 8 * 86400;

    private string $projectDir;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = sys_get_temp_dir() . '/prune-blobs-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir);

        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if (true === is_dir($this->projectDir)) {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                true === $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }

            rmdir($this->projectDir);
        }

        parent::tearDown();
    }

    public function testOtherStoragesFilesSurviveAndRealOrphansGo(): void
    {
        $kept = [
            'var/uploads/avatars/7/avatar.png',
            'var/uploads/signatures/7/signature.png',
            'var/uploads/backgrounds/background.jpg',
            'var/raw/sealed/0/1234.eml',
            'var/raw/sealed/0/1234.draft.asc',
            'var/raw/sealed/0/1234.decrypted.eml',
        ];
        $orphans = [
            'var/uploads/42/abandoned.blob',
            'var/raw/42/0/99.eml',
            'var/attachments/42/0/0/99/file.pdf',
        ];

        foreach ([...$kept, ...$orphans] as $path) {
            $this->file($path, self::OLD);
        }

        $this->prune();

        foreach ($kept as $path) {
            self::assertFileExists($this->projectDir . '/' . $path, 'deleted though it is another storage\'s');
        }

        foreach ($orphans as $path) {
            self::assertFileDoesNotExist($this->projectDir . '/' . $path, 'an orphan in an account directory was kept');
        }

        self::assertDirectoryExists($this->projectDir . '/var/uploads/avatars', 'the emptied-directory cleanup reached outside the account directories');
    }

    /** What a row points at, and what is too young to judge, stay — as before. */
    public function testReferencedAndRecentFilesStay(): void
    {
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $message = MailFixtures::message($em, MailFixtures::account($em, MailFixtures::user($em, 'prune')));
        $message->rawPath = 'var/raw/42/0/' . $message->id . '.eml';
        $em->flush();

        $this->file($message->rawPath, self::OLD);
        $this->file('var/uploads/42/still-being-attached.blob', 60);

        $this->prune();

        self::assertFileExists($this->projectDir . '/' . $message->rawPath);
        self::assertFileExists($this->projectDir . '/var/uploads/42/still-being-attached.blob');
    }

    private function prune(): void
    {
        $container = static::getContainer();
        $command   = new PruneBlobsCommand(
            $container->get(UploadedBlobRepository::class),
            $container->get(MessagePartRepository::class),
            $container->get(MessageRepository::class),
            $container->get(UploadStorage::class),
            $container->get(EntityManagerInterface::class),
            $this->projectDir,
        );

        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:prune:blobs'));
        $tester->execute(['--skip-uploads' => true]);
        $tester->assertCommandIsSuccessful();
    }

    private function file(string $relative, int $ageSeconds): void
    {
        $path = $this->projectDir . '/' . $relative;

        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, 'x');
        touch($path, time() - $ageSeconds);
    }
}
