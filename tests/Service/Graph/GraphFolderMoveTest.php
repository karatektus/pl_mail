<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Graph\GraphApiSyncer;
use App\Service\Mail\GraphApiClient;
use App\Tests\Support\Mercure\RecordingHub;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Where a message is, after Exchange says it moved.
 *
 * An Exchange message is in exactly one folder, and the sync used to keep that
 * true by pairing two halves of a move: the destination folder's delta adds the
 * new label, the source folder's delta removes the old one. The pairing is only
 * as good as the ids in it, and personal outlook.com mailboxes do not reliably
 * hand back immutable ids — so the removal half looked up a message id that no
 * longer matched, found nothing, and returned. Silently.
 *
 * The result was four messages filed in Drafts and Trash at once: a state
 * Exchange cannot represent, that no page in the app shows as wrong, and that
 * only surfaced as a warning on every subsequent push. So the test is written
 * the way the bug arrived — deliver ONLY the destination half and require the
 * old location to be gone anyway.
 */
final class GraphFolderMoveTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;
    private Label $drafts;
    private Label $trash;
    private Label $receipts;
    private RecordingHub $hub;

    private const string DRAFTS_FOLDER  = 'AAMkAD-drafts';
    private const string TRASH_FOLDER   = 'AAMkAD-trash';
    private const string ARCHIVE_FOLDER = 'AAMkAD-archive';
    private const string INBOX_FOLDER   = 'AAMkAD-inbox';
    private const string SPAM_FOLDER    = 'AAMkAD-spam';
    private const string GRAPH_ID       = 'AAkALgAAmessage';

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->hub      = new RecordingHub();
        $this->user     = $this->seedUser();
        $this->account  = $this->seedAccount();
        $this->drafts   = $this->seedLabel('Drafts', LabelRole::Drafts, self::DRAFTS_FOLDER);
        $this->trash    = $this->seedLabel('Trash', LabelRole::Trash, self::TRASH_FOLDER);
        $this->receipts = $this->seedLabel('Receipts', null, null);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The bug, exactly: a draft is deleted, only the Trash folder's delta says
     * so, and Drafts must not survive it.
     */
    public function testTheDestinationHalfAloneMovesTheMessage(): void
    {
        $message = $this->seedMessage($this->drafts);

        $this->syncFolder(self::TRASH_FOLDER, $message->graphId);

        self::assertSame(['Trash'], $this->labelNamesOf($message));
    }

    /**
     * Only locations are exclusive. A category is the many-to-many axis and has
     * to survive a move, or filing something in Receipts and then archiving it
     * would quietly unfile it.
     */
    public function testACategorySurvivesTheMove(): void
    {
        $message = $this->seedMessage($this->drafts, $this->receipts);

        $this->syncFolder(self::TRASH_FOLDER, $message->graphId);

        $labels = $this->labelNamesOf($message);

        self::assertContains('Trash', $labels);
        self::assertContains('Receipts', $labels);
        self::assertNotContains('Drafts', $labels);
    }

    /** A message re-reported in the folder it is already in does not change. */
    public function testAMessageStayingPutIsLeftAlone(): void
    {
        $message = $this->seedMessage($this->trash, $this->receipts);

        $this->syncFolder(self::TRASH_FOLDER, $message->graphId);

        self::assertSame(['Receipts', 'Trash'], $this->labelNamesOf($message));
    }

    // ── archive, which on Exchange is an ordinary folder ─────────────────────

    /**
     * Exchange has a real Archive folder, so archiving there is a folder move
     * like any other and the exclusive folder-label swap already handles it.
     * Pinned rather than assumed: the Gmail side needed a rule invented for it,
     * because Gmail expresses archiving as the *absence* of INBOX and there is
     * nothing to arrive in its place. The two providers reach the same end
     * state by different routes, and this is the one that gets there for free.
     */
    public function testAMessageMovedToTheArchiveFolderWearsTheArchiveLabel(): void
    {
        $archive = $this->seedLabel('Archive', LabelRole::Archive, self::ARCHIVE_FOLDER);
        $message = $this->seedMessage($this->drafts);

        $this->syncFolder(self::ARCHIVE_FOLDER, $message->graphId);

        self::assertSame(['Archive'], $this->labelNamesOf($message));
        self::assertTrue($message->hasLabel($archive));
    }

    /**
     * And back out of it, for the same reason the Gmail side pins the reverse:
     * a message that returns to the inbox must not go on claiming to be
     * archived.
     */
    public function testAMessageMovedBackOutOfArchiveStopsWearingTheArchiveLabel(): void
    {
        $this->seedLabel('Archive', LabelRole::Archive, self::ARCHIVE_FOLDER);
        $inbox   = $this->seedLabel('Inbox', LabelRole::Inbox, self::INBOX_FOLDER);
        $message = $this->seedMessage($this->drafts);

        $this->syncFolder(self::ARCHIVE_FOLDER, $message->graphId);
        $this->syncFolder(self::INBOX_FOLDER, $message->graphId);

        self::assertSame(['Inbox'], $this->labelNamesOf($message));
        self::assertTrue($message->hasLabel($inbox));
    }

    // ── the thread follows the message ───────────────────────────────────────

    /**
     * What a list shows is the thread's labels, not the message's. A mail
     * marked as spam in another client arrives as the destination half only,
     * the message swaps Inbox for Spam, and the thread used to gain Spam
     * without losing Inbox: still listed in the inbox, and in Spam as well.
     *
     * The thread starts wearing exactly its message's labels (Inbox), the way
     * ThreadLabelSynchronizer leaves it after an arrival.
     */
    public function testTheThreadLeavesTheOldFolderWithItsMessage(): void
    {
        $inbox = $this->seedLabel('Inbox', LabelRole::Inbox, self::INBOX_FOLDER);
        $this->seedLabel('Spam', LabelRole::Spam, self::SPAM_FOLDER);
        $message = $this->seedMessage($inbox);
        $this->seedThreadLabelsFromMessages($message);

        $this->syncFolder(self::SPAM_FOLDER, $message->graphId);

        // Presence and absence in one assertion: Spam arrived, Inbox left.
        self::assertSame(['Spam'], $this->threadLabelNamesOf($message));
    }

    /**
     * A thread is under a folder while ANY of its messages is. Moving one
     * message out must not take the folder off a thread whose other message
     * is still there.
     */
    public function testTheThreadKeepsAFolderAnotherMessageOfItStillWears(): void
    {
        $inbox = $this->seedLabel('Inbox', LabelRole::Inbox, self::INBOX_FOLDER);
        $this->seedLabel('Spam', LabelRole::Spam, self::SPAM_FOLDER);
        $message = $this->seedMessage($inbox);
        $this->seedSibling($message, 'AAkALgAAsibling', $inbox);
        $this->seedThreadLabelsFromMessages($message);

        $this->syncFolder(self::SPAM_FOLDER, $message->graphId);

        self::assertSame(['Inbox', 'Spam'], $this->threadLabelNamesOf($message));
    }

    /**
     * The same move, delivered the way a run with several folders delivers
     * it: `@removed` on the source, an ordinary item on the destination.
     */
    public function testAMoveSeenFromBothFoldersLeavesTheThreadInTheDestinationOnly(): void
    {
        $inbox = $this->seedLabel('Inbox', LabelRole::Inbox, self::INBOX_FOLDER);
        $this->seedLabel('Spam', LabelRole::Spam, self::SPAM_FOLDER);
        $message = $this->seedMessage($inbox);
        $this->seedThreadLabelsFromMessages($message);

        $this->syncFolders([
            self::INBOX_FOLDER => [$this->removedItem($message->graphId)],
            self::SPAM_FOLDER  => [['id' => $message->graphId, 'parentFolderId' => self::SPAM_FOLDER]],
        ]);

        self::assertSame(['Spam'], $this->threadLabelNamesOf($message));
    }

    /**
     * The other half of a move on its own: `@removed` takes a folder off the
     * message, so it takes it off the thread too. Needs a message that is
     * still somewhere else afterwards (the Drafts-and-Trash state rows were
     * left in before moves became exclusive), or the removal would delete the
     * row and the thread with it.
     */
    public function testARemovalTakesTheFolderOffTheThreadToo(): void
    {
        $message = $this->seedMessage($this->drafts, $this->trash);
        $this->seedThreadLabelsFromMessages($message);

        $this->syncFolders([self::DRAFTS_FOLDER => [$this->removedItem($message->graphId)]]);

        self::assertSame(['Trash'], $this->labelNamesOf($message), 'the row survives, in Trash');
        self::assertSame(['Trash'], $this->threadLabelNamesOf($message));
    }

    // ── and when it moved out of the mailbox altogether ──────────────────────

    /**
     * The gap the move fix left behind. `@removed` took the folder's label off
     * and stopped there, so a message deleted in Outlook lost its label and
     * kept its row — invisible in every list, still counted in every total, and
     * collected by nothing.
     *
     * An Exchange message is in exactly one folder. A row wearing no folder
     * label is not in the mailbox, and the row has to go with it.
     */
    public function testAMessageRemovedFromItsOnlyFolderIsDeletedHere(): void
    {
        $message = $this->seedMessage($this->drafts);
        $rowId   = (int) $message->id;

        $this->syncFolders([self::DRAFTS_FOLDER => [$this->removedItem($message->graphId)]]);

        self::assertNull(
            $this->em->find(Message::class, $rowId),
            'it is in no folder, which for Exchange means it is not there',
        );
    }

    /**
     * And the case that makes the removal ambiguous in the first place: a move
     * is `@removed` on the source and an ordinary item on the destination, both
     * inside one run. Pairing them is what stops a move being read as a delete.
     */
    public function testAMessageRemovedFromOneFolderAndPresentInAnotherIsMovedNotDeleted(): void
    {
        $message = $this->seedMessage($this->drafts);
        $rowId   = (int) $message->id;

        $this->syncFolders([
            self::DRAFTS_FOLDER => [$this->removedItem($message->graphId)],
            self::TRASH_FOLDER  => [['id' => $message->graphId, 'parentFolderId' => self::TRASH_FOLDER]],
        ]);

        $moved = $this->em->find(Message::class, $rowId);

        self::assertNotNull($moved, 'it arrived somewhere, so it was a move');
        self::assertSame(['Trash'], $this->labelNamesOf($moved));
    }

    /**
     * A removal reported for an id plMail has no row for is not a problem to
     * report. Graph deltas are per folder and plMail does not necessarily hold
     * every message a folder has ever had.
     */
    public function testARemovalForAMessageWeDoNotHoldIsIgnored(): void
    {
        $message = $this->seedMessage($this->drafts);

        $this->syncFolders([self::DRAFTS_FOLDER => [$this->removedItem('AAkALgAAsomeoneelse')]]);

        self::assertNotNull($this->em->find(Message::class, (int) $message->id));
    }

    // ── telling the open list ────────────────────────────────────────────────

    /**
     * A move made in Outlook web changes only rows: no mail is ingested, so
     * nothing published account.synced and the open list kept showing the
     * message in its old folder until the page was reloaded.
     */
    public function testAMoveMadeInOutlookTellsTheOpenListToRefresh(): void
    {
        $message = $this->seedMessage($this->drafts);

        $this->syncFolder(self::TRASH_FOLDER, $message->graphId);

        self::assertSame(['Trash'], $this->labelNamesOf($message), 'the move itself was applied');
        $this->assertListToldToRefresh();
    }

    /**
     * Re-reporting a message where it already is — the echo of plMail's own
     * move, coming back on the next delta — is not news. The same message is
     * then really moved, so the silence is not that of a notifier that never
     * speaks.
     */
    public function testAMessageStayingPutIsNotAnnouncedButMovingItIs(): void
    {
        $this->seedLabel('Archive', LabelRole::Archive, self::ARCHIVE_FOLDER);
        $message = $this->seedMessage($this->trash, $this->receipts);

        $this->syncFolder(self::TRASH_FOLDER, $message->graphId);

        self::assertSame([], $this->hub->updates, 'already there: nothing to refresh');

        $this->syncFolder(self::ARCHIVE_FOLDER, $message->graphId);

        self::assertContains('Archive', $this->labelNamesOf($message));
        $this->assertListToldToRefresh();
    }

    /**
     * The source half of a move, on its own: the message still wears another
     * folder label afterwards, so it is not erased and the detach is the only
     * thing that changed.
     */
    public function testARemovalThatTakesAFolderLabelOffIsAnnounced(): void
    {
        $message = $this->seedMessage($this->drafts, $this->trash);

        $this->syncFolders([self::DRAFTS_FOLDER => [$this->removedItem($message->graphId)]]);

        self::assertSame(['Trash'], $this->labelNamesOf($message), 'only the detach happened');
        $this->assertListToldToRefresh();
    }

    /**
     * A deletion on its own: the row wears no folder label, so the removal
     * detaches nothing and the only change is the erase.
     */
    public function testADeletionMadeInOutlookTellsTheOpenListToRefresh(): void
    {
        $message = $this->seedMessage($this->receipts);
        $rowId   = (int) $message->id;

        $this->syncFolders([self::DRAFTS_FOLDER => [$this->removedItem($message->graphId)]]);

        self::assertNull($this->em->find(Message::class, $rowId), 'the erase itself happened');
        $this->assertListToldToRefresh();
    }

    /**
     * Removals that change nothing are not announced: an id plMail holds no
     * row for, and a removal for a message that has no such label to lose.
     */
    public function testARemovalThatChangesNothingIsNotAnnounced(): void
    {
        $message = $this->seedMessage($this->trash);

        $this->syncFolders([self::DRAFTS_FOLDER => [
            $this->removedItem('AAkALgAAsomeoneelse'),
            $this->removedItem($message->graphId),
        ]]);

        self::assertSame(['Trash'], $this->labelNamesOf($message), 'it was never in Drafts');
        self::assertSame([], $this->hub->updates);
    }

    private function assertListToldToRefresh(): void
    {
        self::assertCount(1, $this->hub->updates);

        $update = $this->hub->updates[0];

        self::assertSame(['mail/user/' . $this->user->id], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertSame(
            ['type' => 'account.synced', 'accountId' => $this->account->id],
            json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    // ── re-reported, not moved ───────────────────────────────────────────────

    /**
     * Graph re-sends a message in its folder's delta whenever anything about it
     * changes, and most of those changes are not a move. Writing a change-log
     * row for a message that did not move makes the server announce new mail
     * to the phone, so a message already wearing its folder's label must leave
     * no trace in the change log at all.
     */
    public function testAMessageAlreadyInItsFolderWritesNoChangeLog(): void
    {
        $message = $this->seedMessage($this->trash);

        $this->syncFolder(self::TRASH_FOLDER, $message->graphId);

        self::assertSame(['Trash'], $this->labelNamesOf($message));
        self::assertSame(0, $this->changeLogRows('Email', 'updated'));
        self::assertSame(0, $this->changeLogRows('Thread', 'updated'));
    }

    /**
     * The production shape, not the tidy one. A delta entry for a message plMail
     * has just marked read carries `isRead` alongside its folder, and the flag
     * state that comes with it is already what plMail stores for an unread
     * message, so nothing moved and nothing changed.
     */
    public function testAReReportedMessageWithAnUnchangedReadStateWritesNoChangeLog(): void
    {
        $message = $this->seedMessage($this->trash);

        $this->syncFolders([self::TRASH_FOLDER => [[
            'id'             => $message->graphId,
            'parentFolderId' => self::TRASH_FOLDER,
            'isRead'         => false,
        ]]]);

        self::assertSame(['Trash'], $this->labelNamesOf($message));
        self::assertSame(0, $this->changeLogRows('Email', 'updated'));
        self::assertSame(0, $this->changeLogRows('Thread', 'updated'));
    }

    /**
     * The counterpart to the two tests above: the same syncer, the same
     * assertion helper, a real move. Without it, zero rows could mean the
     * counting query never sees the table.
     */
    public function testAMessageThatMovedWritesOneEmailAndOneThreadChangeLogRow(): void
    {
        $message = $this->seedMessage($this->drafts);

        $this->syncFolder(self::TRASH_FOLDER, $message->graphId);

        self::assertSame(1, $this->changeLogRows('Email', 'updated'));
        self::assertSame(1, $this->changeLogRows('Thread', 'updated'));
    }

    /**
     * A removal reported under a folder the message is not in is a stale or
     * misdirected delta entry. There is nothing to take off, so nothing may be
     * written and the message stays where it is.
     */
    public function testARemovalFromAFolderTheMessageIsNotInWritesNoChangeLog(): void
    {
        $message = $this->seedMessage($this->trash);
        $rowId   = (int) $message->id;

        $this->syncFolders([self::DRAFTS_FOLDER => [$this->removedItem($message->graphId)]]);

        self::assertNotNull($this->em->find(Message::class, $rowId));
        self::assertSame(['Trash'], $this->labelNamesOf($message));
        self::assertSame(0, $this->changeLogRows('Email', 'updated'));
        self::assertSame(0, $this->changeLogRows('Thread', 'updated'));
    }

    /**
     * The counterpart to the removal test above: the message does wear the
     * label being removed, and it still has another folder label to keep it
     * from being read as a deletion, so this is a real change and must be
     * recorded.
     */
    public function testARemovalFromAFolderTheMessageIsInWritesAChangeLogRow(): void
    {
        $message = $this->seedMessage($this->drafts, $this->trash);
        $rowId   = (int) $message->id;

        $this->syncFolders([
            self::DRAFTS_FOLDER => [$this->removedItem($message->graphId)],
            self::TRASH_FOLDER  => [['id' => $message->graphId, 'parentFolderId' => self::TRASH_FOLDER]],
        ]);

        self::assertNotNull($this->em->find(Message::class, $rowId));
        self::assertGreaterThanOrEqual(1, $this->changeLogRows('Email', 'updated'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function removedItem(?string $graphId): array
    {
        return ['id' => $graphId, '@removed' => ['reason' => 'deleted']];
    }

    /**
     * Run several folders' deltas in one pass, which is what the syncer does
     * and what makes a removal interpretable at all.
     *
     * @param array<string, list<array<string,mixed>>> $byFolder
     */
    private function syncFolders(array $byFolder): void
    {
        $responses = [];

        foreach ($byFolder as $items) {
            $responses[] = new MockResponse(
                json_encode([
                    'value'            => $items,
                    '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=new',
                ], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }

        $container = self::getContainer();

        $syncer = new GraphApiSyncer(
            new GraphApiClient(
                new MockHttpClient($responses),
                $container->get('App\Service\OAuth\OAuthTokenManager'),
            ),
            $container->get('App\Service\Graph\GraphFolderResolver'),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            $container->get('messenger.default_bus'),
            new \Psr\Log\NullLogger(),
            $container->get('App\Service\Mail\MessageEraser'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            new \App\Service\Mail\SyncOrigin(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            new \App\Service\Mail\SyncNotifier($this->hub, new \Psr\Log\NullLogger()),
        );

        // Folders that are already being followed. One without a delta link is
        // the import's and would not be asked for changes at all — see
        // GraphImportTest for that half.
        $this->account->graphDeltaLinks = array_fill_keys(array_keys($byFolder), 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=old');

        $syncer->sync($this->account, array_keys($byFolder));

        $this->em->flush();
    }


    /**
     * Runs one folder's delta, reporting the given message as present in it.
     *
     * The syncer is built by hand around a GraphApiClient on a MockHttpClient,
     * the way the Gmail syncer tests do it: the client is final, and what is
     * being tested is what the syncer writes, not how it asks.
     */
    private function syncFolder(string $folderId, ?string $graphId): void
    {
        $delta = [
            'value' => [[
                'id'             => $graphId,
                'parentFolderId' => $folderId,
            ]],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=new',
        ];

        $container = self::getContainer();

        $client = new GraphApiClient(
            new MockHttpClient([new MockResponse(json_encode($delta, JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ])]),
            $container->get('App\Service\OAuth\OAuthTokenManager'),
        );

        $syncer = new GraphApiSyncer(
            $client,
            $container->get('App\Service\Graph\GraphFolderResolver'),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            $container->get('messenger.default_bus'),
            new \Psr\Log\NullLogger(),
            $container->get('App\Service\Mail\MessageEraser'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            new \App\Service\Mail\SyncOrigin(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            new \App\Service\Mail\SyncNotifier($this->hub, new \Psr\Log\NullLogger()),
        );

        // Folders that are already being followed. One without a delta link is
        // the import's and would not be asked for changes at all — see
        // GraphImportTest for that half.
        $this->account->graphDeltaLinks = array_fill_keys([$folderId], 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=old');

        $syncer->sync($this->account, [$folderId]);

        $this->em->flush();
    }

    /** @return list<string> sorted, so the assertions read as sets */
    private function labelNamesOf(Message $message): array
    {
        $this->em->refresh($message);

        $names = [];

        foreach ($message->labels as $label) {
            $names[] = (string) $label->name;
        }

        sort($names);

        return $names;
    }

    /**
     * How many change-log rows this account has of one object type and change.
     * The fixtures write none, so after a sync the count is what the sync wrote.
     */
    private function changeLogRows(string $objectType, string $changeType): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM jmap_change_log WHERE account_id = ? AND object_type = ? AND change_type = ?',
            [$this->account->id, $objectType, $changeType],
        );
    }

    /**
     * The thread's labels as a list shows them, read back from the database.
     *
     * @return list<string> sorted, so the assertions read as sets
     */
    private function threadLabelNamesOf(Message $message): array
    {
        $thread = $message->thread;
        self::assertNotNull($thread);

        $this->em->refresh($thread);

        $names = [];

        foreach ($thread->labels as $label) {
            $names[] = (string) $label->name;
        }

        sort($names);

        return $names;
    }

    /**
     * seedMessage() leaves the thread without labels. Give it the union of
     * its messages' labels, which is what an arrival leaves behind.
     */
    private function seedThreadLabelsFromMessages(Message $message): void
    {
        $thread = $message->thread;
        self::assertNotNull($thread);

        foreach ($thread->messages as $member) {
            foreach ($member->labels as $label) {
                $thread->addLabel($label);
            }
        }

        $this->em->flush();
    }

    private function seedSibling(Message $of, string $graphId, Label ...$labels): Message
    {
        $thread = $of->thread;
        self::assertNotNull($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Moved about';
        $message->fromAddress    = 'sender@example.test';
        $message->graphId        = $graphId;
        $message->receivedAt     = new \DateTimeImmutable('-30 minutes');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new \DateTimeImmutable();

        foreach ($labels as $label) {
            $message->addLabel($label);
        }

        $thread->addMessage($message);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedMessage(Label ...$labels): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Moved about';
        $thread->normalizedSubject = 'moved about';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Moved about';
        $message->fromAddress    = 'sender@example.test';
        $message->graphId        = self::GRAPH_ID;
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new \DateTimeImmutable();

        foreach ($labels as $label) {
            $message->addLabel($label);
        }

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedLabel(string $name, ?LabelRole $role, ?string $graphFolderId): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        if (null !== $graphFolderId) {
            // The binding owns the relation, so the label has to be set on it
            // rather than only added to the collection — addBinding() does not
            // set the back-reference, and the insert fails on label_id.
            $binding                = new LabelBinding();
            $binding->label         = $label;
            $binding->account       = $this->account;
            $binding->graphFolderId = $graphFolderId;

            $label->addBinding($binding);

            $this->em->persist($binding);
            $this->em->flush();
        }

        return $label;
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Graph fixture';
        $account->email          = 'graph@example.test';
        $account->username       = uniqid('graph-', true);
        $account->imapHost       = 'outlook.office365.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        // OAuth with a token that is not about to expire: the client asks the
        // token manager on every request, and a password account would send it
        // off to refresh — which fails, and the syncer swallows the failure as
        // "delta failed", leaving the test asserting against a sync that never
        // ran.
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new \DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'graph-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
