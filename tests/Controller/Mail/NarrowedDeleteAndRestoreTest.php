<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Delete in a Drafts list takes the drafts; Restore in the bin or Spam takes
 * what is in there. Neither takes the rest of the conversation.
 *
 * Both lists show a conversation for one part of it — the unsent reply, the
 * one message thrown away — and both buttons used to act on every message of
 * it: Delete on a draft binned the mail it answered, and Restore on one binned
 * reply pulled the archived rest of the conversation, and the user's own sent
 * copy, into the inbox. See DraftsView and DiscardedView.
 *
 * Each case has its control beside it: the same button pressed outside those
 * lists still takes the whole conversation, so a fix that narrowed everything
 * would fail here as surely as the bug did.
 *
 * Through every route a row or a selection reaches: the row's own button
 * (/status/thread/{id}/…, which the swipe, the long-press sheet and the `#`
 * key all press) and the toolbar's selection (/status/bulk/…). The whole-view
 * worker has its pair in RunBulkStatusHandlerTest.
 */
final class NarrowedDeleteAndRestoreTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private Label $drafts;
    private Label $trash;
    private Label $spam;
    private Label $archive;

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
        $this->drafts  = $this->seedLabel('Drafts', LabelRole::Drafts);
        $this->trash   = $this->seedLabel('Trash', LabelRole::Trash);
        $this->spam    = $this->seedLabel('Spam', LabelRole::Spam);
        $this->archive = $this->seedLabel('Archive', LabelRole::Archive);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Delete in Drafts ─────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'the row' => ['row'];
        yield 'a selection' => ['bulk'];
    }

    #[DataProvider('routes')]
    public function testDeleteInDraftsBinsTheDraftAndNotTheMailItAnswers(string $route): void
    {
        [$thread, $received, $draft] = $this->conversationWithADraft();

        $this->act($route, 'trash', $thread, 'drafts');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertTrue($this->wears($draft, LabelRole::Trash), 'the draft is in the bin');
        self::assertFalse($this->wears($received, LabelRole::Trash), 'the mail it answers is not');
        self::assertTrue($this->wears($received, LabelRole::Inbox), 'and is still in the inbox');
    }

    /**
     * An account's own Drafts folder is a label view, and the same list.
     */
    public function testDeleteInAnAccountsDraftsFolderBinsOnlyTheDraft(): void
    {
        [$thread, $received, $draft] = $this->conversationWithADraft();

        $this->act('row', 'trash', $thread, 'label', (string) $this->drafts->id);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->wears($draft, LabelRole::Trash));
        self::assertFalse($this->wears($received, LabelRole::Trash));
    }

    /**
     * The control: in the Inbox the row is the conversation, and Delete takes
     * all of it — the unsent reply included.
     */
    #[DataProvider('routes')]
    public function testDeleteInTheInboxStillBinsTheWholeConversation(string $route): void
    {
        [$thread, $received, $draft] = $this->conversationWithADraft();

        $this->act($route, 'trash', $thread, 'inbox');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->wears($received, LabelRole::Trash));
        self::assertTrue($this->wears($draft, LabelRole::Trash));
    }

    // ── Restore in the bin and Spam ──────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function discardedLists(): iterable
    {
        foreach (self::routes() as $name => [$route]) {
            yield $name . ' in the bin' => [$route, 'trash'];
            yield $name . ' in Spam' => [$route, 'spam'];
        }
    }

    #[DataProvider('discardedLists')]
    public function testRestoreBringsBackOnlyWhatIsInTheList(string $route, string $list): void
    {
        $role = LabelRole::from($list);

        [$thread, $archived, $sent, $thrown] = $this->conversationPartlyIn($role);

        $this->act($route, 'restore', $thread, $list);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertTrue($this->wears($thrown, LabelRole::Inbox), 'the thrown-away message is back in the inbox');
        self::assertFalse($this->wears($thrown, $role), 'and out of the list');

        self::assertFalse($this->wears($archived, LabelRole::Inbox), 'the archived rest of the conversation is not pulled into the inbox');
        self::assertTrue($this->wears($archived, LabelRole::Archive), 'and is still archived');
        self::assertFalse($this->wears($sent, LabelRole::Inbox), 'nor is the reply the user sent');
    }

    /**
     * The control: Restore on an archived conversation, from the Archive,
     * brings all of it back, as it always has.
     */
    public function testRestoreFromTheArchiveStillBringsBackTheWholeConversation(): void
    {
        [$thread, $archived, $sent] = $this->conversationPartlyIn(LabelRole::Trash);

        $this->act('row', 'restore', $thread, 'archive');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->wears($archived, LabelRole::Inbox));
        self::assertTrue($this->wears($sent, LabelRole::Inbox));
    }

    /**
     * Move to → Inbox from the bin is Restore by another name, and goes
     * through restore() too.
     */
    public function testMoveToInboxFromTheBinBringsBackOnlyTheBinnedPart(): void
    {
        [$thread, $archived, $sent, $thrown] = $this->conversationPartlyIn(LabelRole::Trash);

        $this->client->request(
            'POST',
            '/status/bulk/move-to',
            [],
            [],
            $this->headers(),
            (string) json_encode(['ids' => [$thread->id], 'role' => 'inbox', 'scope' => 'trash', 'value' => '']),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->wears($thrown, LabelRole::Inbox));
        self::assertFalse($this->wears($archived, LabelRole::Inbox));
        self::assertFalse($this->wears($sent, LabelRole::Inbox));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Received mail in the inbox and the user's unsent reply to it.
     *
     * @return array{MessageThread, int, int} the thread, the received message's id, the draft's id
     */
    private function conversationWithADraft(): array
    {
        $thread   = $this->thread('Lunch on Friday?');
        $received = $thread->messages->first();
        $draft    = $this->reply($thread, $this->drafts);

        // thread() puts Inbox on the thread alone; the received mail wears it
        // too, as synced mail does.
        $received->addLabel($this->inbox);

        $draft->flags = [MessageFlag::DRAFT->value];
        $thread->addLabel($this->drafts);

        $this->em->flush();

        return [$thread, (int) $received->id, (int) $draft->id];
    }

    /**
     * An archived conversation holding the user's sent reply and one more
     * message that went to the bin or to Spam.
     *
     * @return array{MessageThread, int, int, int} the thread, then the ids of
     *                                             the archived, sent and thrown-away messages
     */
    private function conversationPartlyIn(LabelRole $role): array
    {
        $label  = LabelRole::Trash === $role ? $this->trash : $this->spam;
        $thread = $this->thread('Quarterly numbers');

        $archived = $thread->messages->first();
        $archived->removeLabel($this->inbox);
        $archived->addLabel($this->archive);

        $sent   = $this->reply($thread, null);
        $thrown = $this->reply($thread, $label);

        $thread->removeLabel($this->inbox);
        $thread->addLabel($this->archive);
        $thread->addLabel($label);

        $this->em->flush();

        return [$thread, (int) $archived->id, (int) $sent->id, (int) $thrown->id];
    }

    private function reply(MessageThread $thread, ?Label $label): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Re: ' . $thread->subject;
        $message->fromAddress    = $this->account->email;
        $message->receivedAt     = new DateTimeImmutable();
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = $message->receivedAt;
        $message->flags          = [];
        $message->hasAttachments = false;

        if (null !== $label) {
            $message->addLabel($label);
        }

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function wears(int $messageId, LabelRole $role): bool
    {
        $this->em->clear();

        $message = $this->em->find(Message::class, $messageId);

        self::assertNotNull($message);

        foreach ($message->labels as $label) {
            if ($role === $label->role) {
                return true;
            }
        }

        return false;
    }

    private function act(string $route, string $action, MessageThread $thread, string $scope, string $value = ''): void
    {
        $url  = 'row' === $route ? sprintf('/status/thread/%d/%s', $thread->id, $action) : '/status/bulk/' . $action;
        $body = ['scope' => $scope, 'value' => $value];

        if ('bulk' === $route) {
            $body['ids'] = [$thread->id];
        }

        $this->client->request('POST', $url, [], [], $this->headers(), (string) json_encode($body));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token];
    }
}
