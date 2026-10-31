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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A conversation partly in the bin is two things, and each list shows its own.
 *
 * A message moved to Trash stays in its conversation, and the conversation in
 * the Inbox until all of it is deleted (#268). Opened from the Inbox it showed
 * a discarded draft among the mail, unmarked, and its row offered Restore and
 * Delete forever because the thread wore Trash. Now the side the user came
 * from decides (BinView): from Trash the deleted messages, from anywhere else
 * the others, with counts to match — as the Android app does since 0.4.2.
 *
 * And Delete forever on such a conversation deletes the deleted part only.
 */
final class HalfDeletedThreadTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    /** The conversation's own toolbar, not the actions beside each message. */
    private const string TOOLBAR = '[data-mail--message-actions-entity-type-value="thread"]';

    private KernelBrowser $client;
    private Connection $connection;
    private Label $trash;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user                   = $this->seedUser();
        $this->user->deleteNowButtons = true;
        $this->account                = $this->seedAccount();
        $this->inbox                  = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->trash                  = $this->seedLabel('Trash', LabelRole::Trash);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOpenedFromTheInboxItShowsOnlyTheLiveMessages(): void
    {
        [$thread, $live, $deleted] = $this->halfDeleted();

        $pane = $this->open('/mail/thread/' . $thread);

        self::assertCount(2, $pane->filter('[id^="thread_message_"]'), 'the two live messages, and only those');
        self::assertCount(0, $pane->filter('#thread_message_' . $deleted), 'the discarded draft is not among them');
        self::assertCount(1, $pane->filter('#thread_message_' . $live));
        self::assertSame('2', trim($pane->filter('h1[data-thread-subject] + span')->text()), 'counted without it');
    }

    public function testOpenedFromTheBinItShowsOnlyTheDeletedOnes(): void
    {
        [$thread, $live, $deleted] = $this->halfDeleted();

        $pane = $this->open('/mail/thread/' . $thread . '?bin=1');

        self::assertCount(1, $pane->filter('[id^="thread_message_"]'));
        self::assertCount(1, $pane->filter('#thread_message_' . $deleted), 'the discarded draft');
        self::assertCount(0, $pane->filter('#thread_message_' . $live));
        self::assertCount(0, $pane->filter('h1[data-thread-subject] + span'), 'one message has no count beside it');
    }

    /**
     * A link or a search hit names no list. A conversation wholly in the bin
     * has nothing on the live side, and is shown whole rather than empty.
     */
    public function testAConversationWhollyInTheBinIsShownWholeFromAnywhere(): void
    {
        $thread = $this->thread('All gone');
        $this->addMessage($thread, 'All gone, too');
        $this->binThread($thread, $this->trash);
        $id = (int) $thread->id;

        self::assertCount(2, $this->open('/mail/thread/' . $id)->filter('[id^="thread_message_"]'));
    }

    public function testEachListDrawsItsOwnSideOfTheRow(): void
    {
        [$thread] = $this->halfDeleted();

        $inboxRow = $this->row('/mail/inbox', $thread);

        self::assertCount(1, $inboxRow->filter('[data-action="click->mail--message-row#trash"]'), 'Delete, as for live mail');
        self::assertCount(0, $inboxRow->filter('[data-action="click->mail--message-row#purge"]'), 'and no Delete forever');
        self::assertCount(0, $inboxRow->filter('[data-action="click->mail--message-row#restore"]'));
        self::assertStringContainsString('Live reply', $inboxRow->text(), 'the snippet is the live mail\'s');
        self::assertCount(0, $inboxRow->filter('[data-draft-badge]'), 'sent mail is not called a draft');
        self::assertStringNotContainsString('?bin=1', (string) $inboxRow->filter('a[href*="/mail/thread/"]')->attr('href'));

        $binRow = $this->row('/mail/trash', $thread);

        self::assertCount(1, $binRow->filter('[data-action="click->mail--message-row#purge"]'), 'Delete forever in the bin');
        self::assertCount(1, $binRow->filter('[data-action="click->mail--message-row#restore"]'));
        self::assertCount(0, $binRow->filter('[data-action="click->mail--message-row#trash"]'));
        self::assertStringNotContainsString('Live reply', $binRow->text());
    }

    /**
     * One account's Trash, under the account in the sidebar, is the label view
     * of its Trash-role label rather than the Trash list. It drew the live side
     * and linked the conversation's live side, so a conversation whose only
     * deleted message was a draft was listed there and opened without it.
     */
    public function testOneAccountsTrashDrawsTheBinSideOfTheRow(): void
    {
        [$thread] = $this->halfDeleted();

        $row = $this->row(sprintf('/mail/label/%d?account=%d', $this->trash->id, $this->account->id), $thread);

        self::assertCount(1, $row->filter('[data-action="click->mail--message-row#purge"]'), 'Delete forever, as in the bin');
        self::assertCount(1, $row->filter('[data-action="click->mail--message-row#restore"]'));
        self::assertCount(0, $row->filter('[data-action="click->mail--message-row#trash"]'));
        self::assertStringNotContainsString('Live reply', $row->text());
        self::assertStringContainsString('bin=1', (string) $row->filter('a[href*="/mail/thread/"]')->attr('href'), 'and opens the deleted side');
    }

    /**
     * A row action pressed there names the list as the label it is, and the
     * row that comes back is the bin's, not the live side swapped in under the
     * pointer.
     */
    public function testARowActionInOneAccountsTrashRedrawsTheBinSide(): void
    {
        [$thread] = $this->halfDeleted();

        $this->request('POST', sprintf('/status/thread/%d/star', $thread), ['scope' => 'label', 'value' => (string) $this->trash->id]);

        $stream = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('click->mail--message-row#purge', $stream);
        self::assertStringNotContainsString('Live reply', $stream);
    }

    /** Any other label is the live side, as before. */
    public function testARowActionInAnotherLabelRedrawsTheLiveSide(): void
    {
        [$thread] = $this->halfDeleted();

        $this->request('POST', sprintf('/status/thread/%d/star', $thread), ['scope' => 'label', 'value' => (string) $this->inbox->id]);

        $stream = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('click->mail--message-row#purge', $stream);
        self::assertStringContainsString('Live reply', $stream);
    }

    /**
     * A thrown-away draft is the whole of the bin's side here, and that row
     * opened it in the composer: editable, sendable, autosaved while it still
     * wore Trash. It opens as the mail it is instead.
     */
    public function testInTheBinADraftOpensAsAMailAndNotInTheComposer(): void
    {
        [$thread, , $draft] = $this->halfDeleted();

        $row = $this->row('/mail/trash', $thread);

        self::assertCount(0, $row->filter('a[href*="/compose/edit/"]'));
        self::assertCount(1, $row->filter('[data-draft-badge]'), 'still said to be a draft, not sent mail');
        self::assertStringContainsString('bin=1', (string) $row->filter('a[href*="/mail/thread/"]')->attr('href'));

        $pane = $this->open('/mail/thread/' . $thread . '?bin=1');

        self::assertCount(1, $pane->filter('#thread_message_' . $draft . '[data-controller~="mail--thread-message"]'), 'drawn as a message');
        self::assertCount(0, $pane->filter('a[href*="/compose/edit/' . $draft . '"]'), 'with no way into the editor');
        self::assertCount(1, $pane->filter('#thread_message_' . $draft . ' [data-draft-badge]'), 'and marked as a draft');
    }

    /**
     * The reading pane had neither of the bin's two actions, so mail opened
     * from Trash could only be taken out of it from the list.
     */
    public function testThePaneOffersTheBinsActionsOnTheBinsSide(): void
    {
        [$thread] = $this->halfDeleted();

        $bin = $this->open('/mail/thread/' . $thread . '?bin=1');

        self::assertCount(1, $bin->filter(self::TOOLBAR . ' [data-action="click->mail--message-actions#restore"]'));
        self::assertCount(1, $bin->filter(self::TOOLBAR . ' [data-action="click->mail--message-actions#purge"]'));
        self::assertCount(0, $bin->filter(self::TOOLBAR . ' [data-action="click->mail--message-actions#trash"]'));

        $live = $this->open('/mail/thread/' . $thread);

        self::assertCount(0, $live->filter(self::TOOLBAR . ' [data-action="click->mail--message-actions#restore"]'));
        self::assertCount(0, $live->filter(self::TOOLBAR . ' [data-action="click->mail--message-actions#purge"]'));
        self::assertCount(1, $live->filter(self::TOOLBAR . ' [data-action="click->mail--message-actions#trash"]'));
    }

    /**
     * The draft's Drafts label stays on its conversation after it is thrown
     * away, and the conversation is not wholly in the bin. Drafts listed it and
     * opened its live side, without the draft. A draft that is still one keeps
     * its conversation in the list.
     */
    public function testDraftsListsOnlyDraftsThatAreNotInTheBin(): void
    {
        $drafts = $this->seedLabel('Drafts', LabelRole::Drafts);

        $live      = $this->thread('Still writing');
        $liveDraft = $this->addMessage($live, 'Not done yet', draft: true);
        $liveDraft->addLabel($drafts);
        $live->addLabel($drafts);
        $this->em->flush();
        $liveId   = (int) $live->id;
        $draftsId = (int) $drafts->id;

        [$binned, , $binnedDraft] = $this->halfDeleted();

        $drafts = $this->em->find(Label::class, $draftsId);
        $draft  = $this->em->find(Message::class, $binnedDraft);
        self::assertNotNull($drafts);
        self::assertNotNull($draft);
        $draft->addLabel($drafts);
        $draft->thread?->addLabel($drafts);
        $this->em->flush();
        $this->em->clear();

        $list = $this->client->request('GET', '/mail/drafts');

        self::assertCount(0, $list->filter('#thread_' . $binned), 'the thrown-away draft is in Trash only');
        self::assertCount(1, $list->filter('#thread_' . $liveId));
    }

    /**
     * The row counts its own side. The thread's counter covers all three
     * messages, deleted one included, and was what both lists showed.
     */
    public function testTheRowCountsOnlyItsSide(): void
    {
        [$thread] = $this->halfDeleted();

        self::assertStringContainsString('2', $this->countOn($this->row('/mail/inbox', $thread)));

        // One message, and a row of one draws no count at all.
        self::assertSame('', $this->countOn($this->row('/mail/trash', $thread)));
    }

    public function testDeleteForeverDeletesOnlyTheDeletedPart(): void
    {
        [$thread, $live, $deleted] = $this->halfDeleted();

        $this->purge('thread', $thread);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNull($this->em->find(Message::class, $deleted), 'the discarded draft is gone for good');
        self::assertNotNull($this->em->find(Message::class, $live), 'the live mail is untouched');
        self::assertNotNull($this->em->find(MessageThread::class, $thread), 'and so is the conversation');
    }

    public function testDeleteForeverIsStillRefusedForAConversationWithNothingDeleted(): void
    {
        $thread = $this->thread('Nothing deleted');
        $id     = (int) $thread->id;

        $this->purge('thread', $id);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNotNull($this->em->find(MessageThread::class, $id));
    }

    public function testDeleteForeverOnASelectionDeletesOnlyTheDeletedParts(): void
    {
        [$thread, $live, $deleted] = $this->halfDeleted();

        $this->request('POST', '/status/bulk/purge', ['ids' => [$thread]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNull($this->em->find(Message::class, $deleted));
        self::assertNotNull($this->em->find(Message::class, $live));
    }

    /**
     * Three messages: an older live one, a live reply, and a draft that was
     * discarded. The thread wears both Inbox and Trash, as sync leaves it.
     *
     * @return array{int, int, int} thread id, the live reply's id, the draft's id
     */
    private function halfDeleted(): array
    {
        $thread = $this->thread('Half deleted', lastMessageAt: '-1 hour');
        $live   = $this->addMessage($thread, 'Live reply', at: '-30 minutes');
        $draft  = $this->addMessage($thread, 'My discarded draft', at: '-10 minutes', draft: true);

        $draft->addLabel($this->trash);
        $thread->addLabel($this->trash);
        $thread->messageCount = 3;
        $this->em->flush();

        $ids = [(int) $thread->id, (int) $live->id, (int) $draft->id];

        // `binned` is the database's, written by a trigger; read it back.
        $this->em->clear();

        return $ids;
    }

    private function addMessage(MessageThread $thread, string $body, string $at = 'now', bool $draft = false): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $thread->subject;
        $message->fromAddress    = 'sender@example.test';
        $message->bodyText       = $body;
        $message->receivedAt     = $draft ? null : new DateTimeImmutable($at);
        $message->sentAt         = $draft ? null : new DateTimeImmutable($at);
        $message->seenAt         = new DateTimeImmutable($at);
        $message->flags          = $draft ? [MessageFlag::DRAFT->value] : [];
        $message->hasAttachments = false;

        foreach ($thread->labels as $label) {
            if (LabelRole::Inbox === $label->role) {
                $message->addLabel($label);
            }
        }

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function open(string $url): Crawler
    {
        $this->em->clear();

        return $this->client->request('GET', $url, [], [], ['HTTP_X_REQUESTED_WITH' => 'fetch']);
    }

    private function row(string $list, int $thread): Crawler
    {
        $this->em->clear();

        $row = $this->client->request('GET', $list)->filter('#thread_' . $thread);

        self::assertCount(1, $row, sprintf('the conversation is listed in %s', $list));

        return $row;
    }

    /** The count beside the sender, or '' for a row that draws none. */
    private function countOn(Crawler $row): string
    {
        $count = $row->filter('span.ml-1.text-xs.font-medium.text-ink-faint');

        return 0 === $count->count() ? '' : trim($count->text());
    }

    private function purge(string $type, int $id): void
    {
        $this->request('POST', sprintf('/status/%s/%d/purge', $type, $id), ['scope' => 'trash', 'value' => '']);
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $url, array $body): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->em->clear();

        $this->client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
