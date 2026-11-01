<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
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

    /** Nothing to choose: deleting a label never moves mail, so the dialog has no destination. */
    public function testTheDialogNeverAsksWhereTheMailGoes(): void
    {
        $full = $this->folderLabel(['Full']);
        $this->messageIn($full, 'Something');

        $this->client->request('GET', sprintf('/labels/%d/delete', $full->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="label_delete[destination]"]');
        self::assertSelectorTextContains('body', 'The folder and its mail stay there');
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
     * A label that is a folder goes from plMail like any other, with its mail
     * left where it is and no job that would delete the folder on the server
     * (#221).
     */
    public function testAFolderLabelIsDeletedWithoutTouchingTheFolder(): void
    {
        $label = $this->folderLabel(['Full']);
        $message = $this->messageIn($label, 'Something');
        $id    = $label->id;
        $msgId = $message->id;

        $this->delete($label);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->jobsFor($id));

        $this->em->clear();

        self::assertNull($this->em->find(Label::class, $id));
        self::assertNotNull($this->em->find(Message::class, $msgId));
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
