<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Graph\GraphApiSyncer;
use App\Service\Mail\GraphApiClient;
use App\Tests\Support\Mercure\RecordingHub;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Read state that arrives on an Exchange delta, and whether plMail hears it.
 *
 * It did not. GraphApiSyncer::partition() said, of a message it already had,
 * that "the only thing that can have changed cheaply is where it lives" — and
 * that was wrong in the way that matters: a delta entry carries the whole
 * message resource, isRead and flag included. So a message read in Outlook or
 * on a phone announced itself in the very payload plMail was reading, and
 * plMail attached the folder label and threw the rest away. Read state was
 * captured once at ingest by GraphMessageBuilder and never again, which is the
 * same gap plain IMAP had.
 *
 * The truth table below is the point: each row states what the delta said and
 * asserts what the row became — including the rows where the answer is "nothing
 * happened", which are the ones a careless fix breaks.
 */
final class GraphInboundReadStateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;
    private Label $inbox;
    private RecordingHub $hub;

    private const string INBOX_FOLDER = 'AAMkAD-inbox';
    private const string GRAPH_ID     = 'AAkALgAAmessage';

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->hub     = new RecordingHub();
        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox, self::INBOX_FOLDER);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the truth table ───────────────────────────────────────────────────

    /** isRead true, stored unread → read. The reported gap. */
    public function testAMessageReadInOutlookBecomesReadHere(): void
    {
        $message = $this->seedMessage(seen: false);

        $this->syncDelta(['isRead' => true]);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt);
        self::assertContains(MessageFlag::SEEN->value, $message->flags);
    }

    /** isRead false, stored read → unread. */
    public function testAMessageMarkedUnreadInOutlookBecomesUnreadHere(): void
    {
        $message = $this->seedMessage(seen: true);

        $this->syncDelta(['isRead' => false]);

        $this->em->refresh($message);

        self::assertNull($message->seenAt);
        self::assertNotContains(MessageFlag::SEEN->value, $message->flags);
    }

    /** flagStatus flagged, stored unstarred → starred, thread included. */
    public function testAFlagSetInOutlookArrivesAsAStar(): void
    {
        $message = $this->seedMessage(seen: false);

        $this->syncDelta(['isRead' => false, 'flag' => ['flagStatus' => 'flagged']]);

        $this->em->refresh($message);

        self::assertNotNull($message->starredAt);
        self::assertNotNull($message->thread->starredAt);
    }

    /** flagStatus notFlagged, stored starred → unstarred. */
    public function testAFlagClearedInOutlookRemovesTheStar(): void
    {
        $message = $this->seedMessage(seen: false, flagged: true);

        $this->syncDelta(['isRead' => false, 'flag' => ['flagStatus' => 'notFlagged']]);

        $this->em->refresh($message);

        self::assertNull($message->starredAt);
    }

    /**
     * A delta entry with no `flag` key at all is Exchange saying nothing about
     * flagging, which is not the same as saying "not flagged". The star stays.
     */
    public function testAnAbsentFlagKeyLeavesTheStarAlone(): void
    {
        $message = $this->seedMessage(seen: false, flagged: true);

        $this->syncDelta(['isRead' => false]);

        $this->em->refresh($message);

        self::assertNotNull($message->starredAt, 'silence about the flag is not "unflagged"');
    }

    /**
     * And an entry with no isRead is not an assertion that the message is
     * unread. Absence is not an answer — the same rule the IMAP listing works
     * to, and the rule that keeps a sparse delta from marking a mailbox unread.
     */
    public function testAnAbsentIsReadLeavesTheReadStateAlone(): void
    {
        $message = $this->seedMessage(seen: true);

        $this->syncDelta([]);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt);
    }

    /**
     * The echo race, on Graph. The user marks a message read here, the outbound
     * ApplyGraphChangesMessage has not run, and the delta still says unread.
     * The guard lives in ThreadStatusUpdater, which is exactly why it covers
     * this provider without Graph having to implement it again.
     */
    public function testAnUnconfirmedLocalChangeIsNotRevertedByAStaleDelta(): void
    {
        $message = $this->seedMessage(seen: false);

        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [MessageFlag::SEEN->value];
        $message->flagsTouchedAt = new DateTimeImmutable();
        $this->em->flush();

        $this->syncDelta(['isRead' => false]);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt, 'the local change is still in flight');
    }

    // ── telling the open list ─────────────────────────────────────────────

    /**
     * The row changed, so the browser has to be told. A flag-only delta builds
     * no message, and account.synced is otherwise published only after a batch
     * ingested something: the sidebar counts moved (mail.changed) while the
     * list kept showing the old bold/regular row until the page was reloaded.
     * Read AND unread are both asserted, because each is a separate branch of
     * what flipped.
     */
    public function testAMessageReadInOutlookTellsTheOpenListToRefresh(): void
    {
        $message = $this->seedMessage(seen: false);

        $this->syncDelta(['isRead' => true]);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt, 'the change itself was applied');
        $this->assertListToldToRefresh();
    }

    public function testAMessageMarkedUnreadInOutlookTellsTheOpenListToRefresh(): void
    {
        $message = $this->seedMessage(seen: true);

        $this->syncDelta(['isRead' => false]);

        $this->em->refresh($message);

        self::assertNull($message->seenAt, 'the change itself was applied');
        $this->assertListToldToRefresh();
    }

    /** A star is the other thing the same call applies, and the list shows it. */
    public function testAFlagSetInOutlookTellsTheOpenListToRefresh(): void
    {
        $message = $this->seedMessage(seen: false);

        $this->syncDelta(['isRead' => false, 'flag' => ['flagStatus' => 'flagged']]);

        $this->em->refresh($message);

        self::assertNotNull($message->starredAt, 'the change itself was applied');
        $this->assertListToldToRefresh();
    }

    /**
     * Nothing to say when nothing changed. The delta agreeing with the row is
     * the common case (every echo of our own push), and a refresh per echo
     * would put one list render in every open tab for no reason. The same
     * message is then really changed, so "nothing was published" cannot be the
     * silence of a notifier that never speaks.
     */
    public function testADeltaThatAgreesWithTheRowIsNotAnnounced(): void
    {
        $message = $this->seedMessage(seen: true);

        $this->syncDelta(['isRead' => true]);

        self::assertSame([], $this->hub->updates, 'the server agrees with the row: nothing to refresh');

        $this->syncDelta(['isRead' => false]);

        $this->em->refresh($message);

        self::assertNull($message->seenAt, 'the same message, now changed');
        $this->assertListToldToRefresh();
    }

    /** An entry that says nothing about read state changes nothing and announces nothing. */
    public function testADeltaWithoutReadStateIsNotAnnounced(): void
    {
        $message = $this->seedMessage(seen: true);

        $this->syncDelta([]);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt);
        self::assertSame([], $this->hub->updates);
    }

    /**
     * The echo guard declines the change (see the test above), so there is
     * nothing new on screen to refresh to: applyRemoteFlags() reports 0 rows.
     */
    public function testAChangeTheEchoGuardDeclinesIsNotAnnounced(): void
    {
        $message = $this->seedMessage(seen: false);

        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [MessageFlag::SEEN->value];
        $message->flagsTouchedAt = new DateTimeImmutable();
        $this->em->flush();

        $this->syncDelta(['isRead' => false]);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt, 'declined: the local change is still in flight');
        self::assertSame([], $this->hub->updates);
    }

    /**
     * One update, to this user's own stream, saying which account changed: the
     * shape mercure_controller.js turns into onAccountSynced(), which re-reads
     * whatever list is open.
     */
    private function assertListToldToRefresh(): void
    {
        self::assertCount(1, $this->hub->updates, 'one nudge, however many rows the delta changed');

        $update = $this->hub->updates[0];

        self::assertSame(['mail/user/' . $this->user->id], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertSame(
            ['type' => 'account.synced', 'accountId' => $this->account->id],
            json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The half of a folder's second life that the migration relies on. Every
     * folder's link is cleared once (Version20261014110000), so the folder is
     * the import's and is listed from the top; the listing carries each
     * message's isRead, and a message it already has is settled by it, not
     * skipped as "known". Then the folder is followed again, by the link the
     * listing ends with.
     *
     * Both reads are asserted — the message read here and unread there, and
     * the other way round — because a listing that only ever agreed with one
     * of them would pass either alone.
     */
    public function testAFolderListedAgainSettlesTheReadStateOfWhatItAlreadyHas(): void
    {
        $readHere   = $this->seedMessage(seen: true);
        $unreadHere = $this->seedMessage(seen: false, graphId: 'AAkALgAAother');

        $page = new MockResponse(json_encode([
            'value' => [
                ['id' => self::GRAPH_ID, 'parentFolderId' => self::INBOX_FOLDER, 'isRead' => false],
                ['id' => 'AAkALgAAother', 'parentFolderId' => self::INBOX_FOLDER, 'isRead' => true],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=renewed',
        ], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);

        // What the migration leaves: no link, and the folder planned for the import.
        $this->account->graphDeltaLinks = [];
        $this->account->graphImport     = [['folder' => self::INBOX_FOLDER, 'since' => time(), 'next' => null]];
        $this->em->flush();

        $this->syncerAnswering($page)->importPage($this->account);

        $this->em->clear();

        $readHere   = $this->em->find(Message::class, $readHere->id);
        $unreadHere = $this->em->find(Message::class, $unreadHere->id);
        $account    = $this->em->find(Account::class, $this->account->id);

        self::assertNull($readHere->seenAt, 'read here, unread on the server: the server\'s word is taken');
        self::assertNotNull($unreadHere->seenAt, 'unread here, read on the server: the server\'s word is taken');
        self::assertSame(
            'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=renewed',
            $account->graphDeltaLinks[self::INBOX_FOLDER] ?? null,
            'the listing ends by handing the folder the link it is followed with from now on',
        );

        // The re-listing changed rows the open list is showing, and it runs
        // through importPage(), not sync(): that path has to tell the list too.
        $this->assertListToldToRefresh();
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Run one folder's delta reporting this message with the given extra
     * fields, the way GraphFolderMoveTest does it: a hand-built syncer over a
     * MockHttpClient, because what is under test is what the syncer writes.
     *
     * @param array<string,mixed> $extra
     */
    private function syncDelta(array $extra): void
    {
        $syncer = $this->syncerAnswering($this->deltaPage($extra));

        // Folders that are already being followed. One without a delta link is
        // the import's and would not be asked for changes at all — see
        // GraphImportTest for that half.
        $this->account->graphDeltaLinks = array_fill_keys([self::INBOX_FOLDER], 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=old');

        $syncer->sync($this->account, [self::INBOX_FOLDER]);

        $this->em->flush();
    }

    /**
     * What a folder's delta, or its listing, says about the message.
     *
     * @param array<string,mixed> $extra
     */
    private function deltaPage(array $extra): MockResponse
    {
        $delta = [
            'value' => [array_merge([
                'id'             => self::GRAPH_ID,
                'parentFolderId' => self::INBOX_FOLDER,
            ], $extra)],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=new',
        ];

        return new MockResponse(json_encode($delta, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function syncerAnswering(MockResponse $response): GraphApiSyncer
    {
        $container = self::getContainer();

        return new GraphApiSyncer(
            new GraphApiClient(
                new MockHttpClient([$response]),
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
    }

    private function seedMessage(bool $seen, bool $flagged = false, string $graphId = self::GRAPH_ID): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Read elsewhere';
        $thread->normalizedSubject = 'read elsewhere';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->unreadCount       = true === $seen ? 0 : 1;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Read elsewhere';
        $message->fromAddress    = 'sender@example.test';
        $message->graphId        = $graphId;
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new DateTimeImmutable();

        if (true === $seen) {
            $message->seenAt = new DateTimeImmutable('-30 minutes');
            $message->flags  = [MessageFlag::SEEN->value];
        }

        if (true === $flagged) {
            $message->starredAt = new DateTimeImmutable('-30 minutes');
            $thread->starredAt  = $message->starredAt;
            $message->flags     = MessageFlag::canonicalList(
                array_merge($message->flags, [MessageFlag::FLAGGED->value]),
            );
        }

        $message->addLabel($this->inbox);

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

        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'graph-read-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'ReadState';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
