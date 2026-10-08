<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Entity\Label\Label;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use App\Tests\Support\Mercure\RecordingHub;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;

/**
 * The browser's label form refuses a name the user already has — for a new
 * label and for a rename, capitals ignored.
 *
 * The rule itself is LabelRepository::findNameConflict() and LabelRepositoryTest
 * pins it. What is pinned here is that both of the browser's ways of naming a
 * label reach it, because for a long time only one of them checked anything:
 * new() did an exact-match lookup written out inline, and edit() did none, so a
 * rename could land on a sibling's name outright. "work" beside "Work" passed
 * both, and Gmail refused it later in a worker with nobody watching.
 *
 * Mailbox/set asks the same method (MailboxSetStructureTest), which is the
 * point: a name the apps are refused is refused here, and the other way round.
 */
final class LabelNameTakenTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        // Before anything else is pulled out of the container, for the reason
        // LabelChangeReachesEveryTabTest gives: a successful save publishes,
        // and the hub cannot be replaced once something has reached for it.
        $container->set(HubInterface::class, new RecordingHub());

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testANewLabelCannotTakeANameThatDiffersOnlyInCapitals(): void
    {
        $this->seedLabel('Work');

        $this->submit('/labels/new', 'work');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->labelsNamedLike('work'), 'the second label must not have been created');
    }

    /** The half that had no check at all: a rename onto a sibling's name. */
    public function testARenameCannotLandOnAnotherLabelsName(): void
    {
        $this->seedLabel('Work');
        $receipts = $this->seedLabel('Receipts');

        $this->submit('/labels/' . $receipts->id . '/edit', 'WORK');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Receipts', $this->storedName($receipts), 'a refused rename must not be stored');
    }

    /** A label is not in its own way: changing only its capitals is a rename like any other. */
    public function testALabelMayChangeOnlyItsOwnCapitals(): void
    {
        $receipts = $this->seedLabel('Receipts');

        $this->submit('/labels/' . $receipts->id . '/edit', 'receipts');

        self::assertResponseIsSuccessful();
        self::assertSame('receipts', $this->storedName($receipts));
    }

    /**
     * Fetched and submitted rather than hand-built, so the CSRF token and the
     * parent and colour fields are exactly what a person's click sends.
     */
    private function submit(string $path, string $name): void
    {
        $crawler = $this->client->request('GET', $path);

        $form                = $crawler->filter('form')->form();
        $form['label[name]'] = $name;

        $this->client->submit($form);
    }

    /** From the table, not the entity: the form writes onto the managed object before anything is refused. */
    private function storedName(Label $label): string
    {
        return (string) $this->connection->fetchOne('SELECT name FROM label WHERE id = ?', [$label->id]);
    }

    private function labelsNamedLike(string $name): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM label WHERE usr_id = ? AND LOWER(name) = ?',
            [$this->user->id, mb_strtolower($name)],
        );
    }
}
