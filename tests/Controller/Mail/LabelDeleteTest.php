<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Service\Label\LabelResolver;
use App\Tests\Support\Mail\SeedsFolderLabels;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The delete dialog and the route behind it (issue #40).
 *
 * A tag is deleted on the spot, as it always was. A label that is a folder on
 * the mail server is handed to a job, and when its folder has mail in it the
 * route refuses to start until it is told where that mail goes — a refusal
 * that holds whatever the dropdown offered.
 */
final class LabelDeleteTest extends WebTestCase
{
    use SeedsFolderLabels;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->seedFolderUser();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheDialogAsksWhereTheMailGoesOnlyWhenThereIsSome(): void
    {
        $full  = $this->folderLabel(['Full']);
        $empty = $this->folderLabel(['Empty']);

        $this->messageIn($full, 'Something');

        $this->client->request('GET', sprintf('/labels/%d/delete', $full->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="label_delete[destination]"]');
        self::assertSelectorExists('option[value="archive"]');

        $this->client->request('GET', sprintf('/labels/%d/delete', $empty->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="label_delete[destination]"]');
    }

    /** A tag holds no mail: gone at once, no job, no question. */
    public function testATagIsDeletedStraightAway(): void
    {
        $tag = $this->tagLabel('Important');
        $id  = $tag->id;

        $this->delete($tag);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->jobsFor($id));

        $this->em->clear();

        self::assertNull($this->em->find(Label::class, $id));
    }

    /**
     * No destination, no delete. The folder cannot go with mail in it, and
     * nothing else is a safe guess.
     */
    public function testAFolderWithMailIsNotDeletedWithoutADestination(): void
    {
        $label = $this->folderLabel(['Full']);
        $this->messageIn($label, 'Something');

        $this->delete($label);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->jobsFor($label->id));
        self::assertNotNull($this->em->find(Label::class, $label->id));
    }

    /** Not the label itself, and not one the dialog never offered. */
    public function testADestinationTheDialogDidNotOfferIsRefused(): void
    {
        $label = $this->folderLabel(['Full']);
        $this->messageIn($label, 'Something');

        $this->delete($label, 'label:' . $label->id);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->jobsFor($label->id));
    }

    /**
     * A destination starts the job and leaves the label where it is: it is
     * removed by the job, after its folder.
     */
    public function testADestinationStartsTheJobAndTheLabelStays(): void
    {
        $label = $this->folderLabel(['Full']);
        $this->messageIn($label, 'Something');

        $this->delete($label, 'archive');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->jobsFor($label->id));
        self::assertNotNull($this->em->find(Label::class, $label->id));

        $job = $this->em->getRepository(BackgroundJob::class)->findOneBy(['kind' => JobKind::DeleteLabel], ['id' => 'DESC']);

        self::assertNotNull($job);
        self::assertNotNull($job->view['destinationId'] ?? null);
    }

    /**
     * A folder still being imported has mail on the server that is not here
     * yet: the count would be short and the folder would never empty. The
     * dialog says so, and the route will not start.
     */
    public function testAFolderStillBeingImportedIsNotDeletedYet(): void
    {
        $label = $this->folderLabel(['Arriving']);
        $mailbox = $label->bindingFor($this->account)?->mailbox;

        self::assertNotNull($mailbox);

        $mailbox->importFloorUid = 40;
        $this->em->flush();

        $this->client->request('GET', sprintf('/labels/%d/delete', $label->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'still importing');

        $this->delete($label);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->jobsFor($label->id));
    }

    /** Pressed twice: the second press says it is under way, and starts nothing. */
    public function testASecondPressDoesNotStartASecondJob(): void
    {
        $label = $this->folderLabel(['Empty']);

        $this->delete($label);
        $this->delete($label);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('already being deleted', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->jobsFor($label->id));
    }

    public function testADeleteWithoutAValidTokenIsRefused(): void
    {
        $label = $this->folderLabel(['Full']);

        $this->client->request('POST', sprintf('/labels/%d/delete', $label->id), ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    /** What a tab fetches when a job tells it its label lists are stale. */
    public function testTheListsRouteAnswersWithTheLabelStreams(): void
    {
        $this->client->request('GET', '/labels/lists');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="settings-label-list"', (string) $this->client->getResponse()->getContent());
    }

    private function delete(Label $label, ?string $destination = null): void
    {
        $crawler = $this->client->request('GET', sprintf('/labels/%d/delete', $label->id));
        $token   = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $body = ['_token' => $token];

        if (null !== $destination) {
            $body['label_delete'] = ['destination' => $destination];
        }

        $this->client->request('POST', sprintf('/labels/%d/delete', $label->id), $body);
    }

    private function jobsFor(?int $labelId): int
    {
        $count = 0;

        foreach ($this->em->getRepository(BackgroundJob::class)->findBy(['usr' => $this->user, 'kind' => JobKind::DeleteLabel]) as $job) {
            if ($labelId === ($job->view['labelId'] ?? null)) {
                ++$count;
            }
        }

        return $count;
    }
}
