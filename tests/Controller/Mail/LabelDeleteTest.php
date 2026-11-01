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
 * the mail server is not deleted from here at all (#221): the route answers 409
 * and the folder, its mail and its label stay.
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

    /** A folder is not a tag: the dialog says so, asks nothing and offers no destination. */
    public function testTheDialogSaysAFolderIsNotDeletedHere(): void
    {
        $full = $this->folderLabel(['Full']);

        $this->messageIn($full, 'Something');

        $this->client->request('GET', sprintf('/labels/%d/delete', $full->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'is a folder on your mail server');
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

    /** Full or empty, a folder is left alone: no job, the label and its folder stay. */
    public function testAFolderIsNotDeleted(): void
    {
        $full  = $this->folderLabel(['Full']);
        $empty = $this->folderLabel(['Empty']);

        $this->messageIn($full, 'Something');

        foreach ([$full, $empty] as $label) {
            $this->delete($label);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(0, $this->jobsFor($label->id));
            self::assertNotNull($this->em->find(Label::class, $label->id));
        }
    }

    /** Deleting a parent deletes what is nested under it, so a folder nested under it counts. */
    public function testATagWithAFolderNestedUnderItIsNotDeletedEither(): void
    {
        $child  = $this->folderLabel(['Work', 'Invoices']);
        $parent = $child->parent;

        self::assertNotNull($parent);

        $this->delete($parent);

        self::assertResponseStatusCodeSame(409);
        self::assertNotNull($this->em->find(Label::class, $parent->id));
        self::assertNotNull($this->em->find(Label::class, $child->id));
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
