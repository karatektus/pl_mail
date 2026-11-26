<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conversation view switched off, from the web (#615): the switch itself,
 * the list one row per email, and what the rows' actions reach.
 *
 * The selection test is the one most worth keeping. With conversation view
 * off the rows carry email ids, and a selection endpoint that read them as
 * conversation ids would archive other mail entirely — so the list says which
 * kind it sends, and an archive of one email leaves its sibling where it was.
 */
final class EmailListViewTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    private MessageThread $conversation;
    private Message $first;
    private Message $second;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $this->seedConversation($container->get(LabelResolver::class), $container->get(ThreadLabelSynchronizer::class));

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSwitchFlipsTheViewAndGoesBackToTheList(): void
    {
        self::assertTrue($this->user->conversationView, 'conversations are the default');

        $this->toggle('/mail/inbox?page=1');

        self::assertSame('/mail/inbox?page=1', $this->client->getResponse()->headers->get('Location'));
        self::assertFalse($this->reload()->conversationView);

        $this->toggle('/mail/inbox');

        self::assertTrue($this->reload()->conversationView, 'and back again');
    }

    /** `back` is a path on this site or nothing: anything else is an open redirect. */
    public function testTheSwitchGoesNowhereOffSite(): void
    {
        foreach (['https://evil.test/', '//evil.test/', '/\\evil.test'] as $back) {
            $this->toggle($back);

            self::assertStringEndsWith('#card-conversation-view', (string) $this->client->getResponse()->headers->get('Location'), $back);
        }
    }

    public function testTheInboxListsEachEmailWithConversationViewOff(): void
    {
        self::assertSame(['thread'], $this->rowTypes(), 'one row for the conversation, while it is on');

        $this->user->conversationView = false;
        $this->em->flush();

        self::assertSame(['message', 'message'], $this->rowTypes(), 'one row per email once it is off');
    }

    /** Archiving one ticked email leaves the rest of its conversation in the inbox. */
    public function testASelectionOfEmailsActsOnThoseEmailsOnly(): void
    {
        $this->user->conversationView = false;
        $this->em->flush();

        $this->post('/status/bulk/archive', ['ids' => [$this->first->id], 'type' => 'message']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertFalse($this->find($this->first->id)->isInInbox(), 'the ticked email is archived');
        self::assertTrue($this->find($this->second->id)->isInInbox(), 'its sibling is not');
    }

    /**
     * Every drop of the email list (#615) — a label, Starred, a folder, Unread
     * — takes the dragged email and leaves its sibling alone.
     */
    public function testDroppingAnEmailActsOnThatEmailOnly(): void
    {
        $this->user->conversationView = false;
        $this->em->flush();

        $receipts = $this->seedLabel('Receipts');
        $folder   = $this->seedLabel('Projects');
        $first    = (int) $this->first->id;
        $second   = (int) $this->second->id;

        $this->post('/status/bulk/label', ['ids' => [$first], 'type' => 'message', 'labelId' => $receipts->id]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'label');

        $this->post('/status/bulk/star', ['ids' => [$first], 'type' => 'message']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'star');

        $this->post('/status/bulk/read', ['ids' => [$first], 'type' => 'message', 'read' => false]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'unread');

        $this->em->clear();
        $dropped = $this->find($first);
        $sibling = $this->find($second);

        self::assertTrue($dropped->labels->exists(static fn (int $key, $label): bool => 'Receipts' === $label->name), 'labelled');
        self::assertNotNull($dropped->starredAt, 'starred');
        self::assertNull($dropped->seenAt, 'marked unread');
        self::assertFalse($sibling->labels->exists(static fn (int $key, $label): bool => 'Receipts' === $label->name), 'the sibling is not labelled');
        self::assertNull($sibling->starredAt, 'nor starred');
        self::assertNotNull($sibling->seenAt, 'nor unread');

        $this->post('/status/bulk/move', ['ids' => [$first], 'type' => 'message', 'labelId' => $folder->id]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'move');

        $this->em->clear();

        self::assertFalse($this->find($first)->isInInbox(), 'moved out of the inbox');
        self::assertTrue($this->find($second)->isInInbox(), 'its sibling stayed');
    }

    /** In the email list a row is draggable, and says it is an email. */
    public function testEmailRowsAreDraggable(): void
    {
        $this->user->conversationView = false;
        $this->em->flush();

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertSame(
            [['true', 'message']],
            array_values(array_unique($crawler->filter('li[data-row-type]')->extract(['draggable', 'data-row-type']), SORT_REGULAR)),
        );
    }

    /** The row's thumbtack pins the email, not its conversation. */
    public function testAnEmailRowPinsTheEmail(): void
    {
        $this->post(sprintf('/status/message/%d/pin', $this->first->id));

        self::assertSame('true', $this->client->getResponse()->headers->get('X-Pinned'));

        $this->em->clear();

        self::assertTrue($this->find($this->first->id)->isPinned());
        self::assertFalse(($this->em->find(MessageThread::class, $this->conversation->id) ?? self::fail('gone'))->isPinned());
    }

    /** Opened from the email list, an email gets the pane, acting on that email. */
    public function testAnOpenedEmailActsOnItself(): void
    {
        $this->user->conversationView = false;
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/mail/message/%d', $this->first->id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('message', $crawler->filter('[data-mail--message-actions-entity-type-value]')->attr('data-mail--message-actions-entity-type-value'));
        self::assertSame((string) $this->first->id, $crawler->filter('[data-mail--message-actions-entity-id-value]')->attr('data-mail--message-actions-entity-id-value'));
        self::assertCount(1, $crawler->filter('[data-show-conversation]'), 'with the way to the rest of the conversation');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function toggle(string $back): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');
        $token   = (string) $crawler->filter('[data-shortcut="conversation-view"]')->closest('form')?->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/conversation-view', ['_token' => $token, 'back' => $back]);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
    }

    /** @return list<string> */
    private function rowTypes(): array
    {
        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return array_values($crawler->filter('li[data-row-type]')->extract(['data-row-type']));
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body = []): void
    {
        // The `ajax` token, from the layout's meta tag — see PinConversationTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }

    private function reload(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->user->id) ?? self::fail('user gone');
    }

    private function find(?int $id): Message
    {
        return $this->em->find(Message::class, $id) ?? self::fail('email gone');
    }

    /** One conversation of two received emails, each filed in the Inbox. */
    private function seedConversation(LabelResolver $labels, ThreadLabelSynchronizer $sync): void
    {
        $inbox = $labels->systemLabel(LabelRole::Inbox, $this->account);

        $this->conversation                    = new MessageThread();
        $this->conversation->account           = $this->account;
        $this->conversation->subject           = 'Two emails';
        $this->conversation->normalizedSubject = 'two emails';
        $this->conversation->threadingMethod   = ThreadingMethod::References;
        $this->conversation->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $this->conversation->category          = MessageCategory::Primary;
        $this->conversation->unreadCount       = 0;
        $this->conversation->messageCount      = 2;
        $this->em->persist($this->conversation);

        foreach (['first' => '-2 hours', 'second' => '-1 hour'] as $name => $at) {
            $message                 = new Message();
            $message->account        = $this->account;
            $message->subject        = 'Two emails';
            $message->fromAddress    = 'someone@example.test';
            $message->receivedAt     = new DateTimeImmutable($at);
            $message->seenAt         = new DateTimeImmutable($at);
            $message->hasAttachments = false;
            $message->flags          = [];
            $message->messageId      = sprintf('<%s-%s@example.test>', $name, uniqid('', true));
            $message->addLabel($inbox);

            $this->conversation->addMessage($message);
            $this->em->persist($message);

            $this->{$name} = $message;
        }

        $this->em->flush();
        $sync->sync($this->conversation);
        $this->em->flush();
    }
}
