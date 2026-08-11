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

    private const string INBOX_FOLDER = 'AAMkAD-inbox';
    private const string GRAPH_ID     = 'AAkALgAAmessage';

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

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
        $delta = [
            'value' => [array_merge([
                'id'             => self::GRAPH_ID,
                'parentFolderId' => self::INBOX_FOLDER,
            ], $extra)],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=new',
        ];

        $container = self::getContainer();

        $syncer = new GraphApiSyncer(
            new GraphApiClient(
                new MockHttpClient([new MockResponse(json_encode($delta, JSON_THROW_ON_ERROR), [
                    'response_headers' => ['content-type' => 'application/json'],
                ])]),
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
        );

        $syncer->sync($this->account, [self::INBOX_FOLDER]);

        $this->em->flush();
    }

    private function seedMessage(bool $seen, bool $flagged = false): Message
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
        $message->graphId        = self::GRAPH_ID;
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
