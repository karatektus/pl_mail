<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The label chips on a message in the conversation (#221 step 3): a label
 * that is a folder on the account says where the mail is and has no ×, a tag
 * has one, and taking a tag off redraws that message's chips without it.
 */
final class MessageLabelChipsTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFolderIsWhereTheMailIsAndATagCanBeTakenOff(): void
    {
        [$message, $folderId, $tagId] = $this->labelledMessage();

        $crawler = $this->client->request('GET', sprintf('/mail/thread/%d', $message->thread?->id));

        self::assertResponseIsSuccessful();

        $chips  = $crawler->filter(sprintf('#message_labels_%d', $message->id));
        $folder = $chips->filter(sprintf('[data-label-chip="%d"]', $folderId));
        $tag    = $chips->filter(sprintf('[data-label-chip="%d"]', $tagId));

        self::assertNotNull($folder->attr('data-label-chip-folder'), 'the folder chip is marked as a place');
        self::assertCount(0, $folder->filter('button'), 'a folder is left with Move to, not with an ×');
        self::assertNull($tag->attr('data-label-chip-folder'));
        self::assertCount(1, $tag->filter('button[data-action="click->mail--label-chip#remove"]'));
    }

    public function testTakingATagOffRedrawsThatMessagesChips(): void
    {
        [$message, $folderId, $tagId] = $this->labelledMessage();

        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag (BulkMoveToTest::postTo()).
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            sprintf('/status/message/%d/label', $message->id),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: json_encode(['labelId' => $tagId, 'attach' => false], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(sprintf('target="message_labels_%d"', $message->id), $body);
        self::assertStringContainsString(sprintf('data-label-chip="%d"', $folderId), $body);
        self::assertStringNotContainsString(sprintf('data-label-chip="%d"', $tagId), $body);
    }

    /**
     * One message wearing a folder of its account and a tag.
     *
     * @return array{Message, int, int}
     */
    private function labelledMessage(): array
    {
        $thread  = $this->thread('Chips');
        $message = $thread->messages->first();
        self::assertInstanceOf(Message::class, $message);

        $folder = $this->seedLabel('Work');
        $tag    = $this->seedLabel('Later');

        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = 'Work';
        $mailbox->fullPath      = 'Work';
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $this->em->persist($mailbox);

        $binding          = new LabelBinding();
        $binding->label   = $folder;
        $binding->account = $this->account;
        $binding->mailbox = $mailbox;
        $this->em->persist($binding);

        $message->addLabel($folder);
        $message->addLabel($tag);
        $this->em->flush();

        return [$message, (int) $folder->id, (int) $tag->id];
    }
}
