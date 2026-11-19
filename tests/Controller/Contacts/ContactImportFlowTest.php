<?php

declare(strict_types=1);

namespace App\Tests\Controller\Contacts;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use App\Service\Job\JobUploadStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploading a contacts file, previewing it and confirming it (#672): the
 * preview still answers in the request, and the confirm queues the import
 * rather than doing it.
 *
 * The file has to be somewhere the worker can read. It used to be staged under
 * var/contacts, which the worker container does not mount; JobUploadStore
 * keeps it under var/uploads, and this asserts the job names a file that is
 * really there.
 */
final class ContactImportFlowTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->client->loginUser($user);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testConfirmingQueuesTheImportOfAFileTheWorkerCanRead(): void
    {
        $before = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contacts_contact WHERE usr_id = ?', [$this->user->id]);

        $crawler = $this->client->request('GET', '/contacts/import');
        $form    = $crawler->filter('form[action$="/contacts/import"]')->form();

        // Named .vcf: the format is read off the name, as a browser sends it.
        $path = sys_get_temp_dir() . '/' . uniqid('contacts-', true) . '.vcf';
        file_put_contents($path, "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Ada Preview\r\nEMAIL:ada@example.test\r\nEND:VCARD\r\n");

        $field = $form['file'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
        $crawler = $this->client->submit($form);
        @unlink($path);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ada Preview', (string) $this->client->getResponse()->getContent(), 'the preview is drawn in the request');

        $this->client->submit($crawler->filter('form[action$="/contacts/import/confirm"]')->form());

        self::assertResponseRedirects('/contacts', 303);

        $job = $this->em->getRepository(BackgroundJob::class)->findOneBy(
            ['usr' => $this->user, 'kind' => JobKind::ImportContacts],
            ['id' => 'DESC'],
        );

        self::assertNotNull($job, 'the confirm queued nothing');
        self::assertSame(JobState::Queued, $job->state);
        self::assertNotNull(
            static::getContainer()->get(JobUploadStore::class)->path((int) $this->user->id, (string) ($job->view['file'] ?? '')),
            'the job names a file that is not where the worker looks',
        );
        self::assertSame(
            $before,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contacts_contact WHERE usr_id = ?', [$this->user->id]),
            'the request imported the contacts itself',
        );

        static::getContainer()->get(JobUploadStore::class)->forget((int) $this->user->id, (string) ($job->view['file'] ?? ''));
    }
}
