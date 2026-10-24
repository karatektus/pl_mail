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
 * A draft sent from Outlook stops being a draft here.
 *
 * With immutable ids the draft keeps its id when Outlook files it in Sent
 * Items, so what plMail hears is an ordinary delta entry for a message it
 * already holds, now in another folder. The delta says nothing about isDraft
 * and a stored message is never rebuilt, so the row stayed an unsent draft:
 * the editor would open it and the send queue would send it again, and
 * discarding it would delete the sent copy at Outlook.
 *
 * Only Sent Items means "was sent". A draft moved to Trash or back to Drafts is
 * still an unsent draft, and those rows are the ones a careless fix breaks.
 */
final class GraphDraftSentElsewhereTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    private const string DRAFTS_FOLDER = 'AAMkAD-drafts';
    private const string SENT_FOLDER   = 'AAMkAD-sent';
    private const string TRASH_FOLDER  = 'AAMkAD-trash';
    private const string GRAPH_ID      = 'AAkALgAAdraft';

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $this->seedLabel('Drafts', LabelRole::Drafts, self::DRAFTS_FOLDER);
        $this->seedLabel('Sent', LabelRole::Sent, self::SENT_FOLDER);
        $this->seedLabel('Trash', LabelRole::Trash, self::TRASH_FOLDER);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADraftThatArrivesInSentItemsIsNoLongerADraft(): void
    {
        $draft = $this->seedDraft();

        $before = new DateTimeImmutable();

        $this->syncDelta(self::SENT_FOLDER);

        $this->em->refresh($draft);

        self::assertFalse($draft->isDraft());
        self::assertNotNull($draft->sentAt);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $draft->sentAt->getTimestamp());
        // Presence: the row is still there, and now ranks its thread.
        self::assertGreaterThanOrEqual($before->getTimestamp(), $draft->thread?->lastMessageAt?->getTimestamp());
    }

    /**
     * A draft filed in Sent Items before settling existed: it wears the Sent
     * label already and is still flagged a draft. Its re-report is no move, but
     * settling it is a change, and a change a JMAP client must hear about, so
     * the guard that keeps re-reports quiet must not keep this one quiet.
     */
    public function testADraftAlreadyFiledInSentIsSettledAndRecordedOnItsReReport(): void
    {
        $draft = $this->seedDraft();
        $draft->removeLabel($this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'role' => LabelRole::Drafts]));
        $draft->addLabel($this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'role' => LabelRole::Sent]));
        $this->em->flush();

        $this->syncDelta(self::SENT_FOLDER);

        $this->em->refresh($draft);

        self::assertFalse($draft->isDraft());
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM jmap_change_log WHERE account_id = ? AND object_type = ? AND change_type = ?',
            [$this->account->id, 'Email', 'updated'],
        ), 'the settle is announced, though nothing moved');
    }

    public function testADraftStillInDraftsStaysADraft(): void
    {
        $draft = $this->seedDraft();

        $this->syncDelta(self::DRAFTS_FOLDER);

        $this->em->refresh($draft);

        self::assertTrue($draft->isDraft());
        self::assertNull($draft->sentAt);
    }

    /** Thrown away is not sent. */
    public function testADraftThatArrivesInTrashStaysADraft(): void
    {
        $draft = $this->seedDraft();

        $this->syncDelta(self::TRASH_FOLDER);

        $this->em->refresh($draft);

        self::assertTrue($draft->isDraft());
        self::assertNull($draft->sentAt);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** One folder's delta reporting the stored message in that folder. */
    private function syncDelta(string $folderId): void
    {
        $delta = [
            'value' => [[
                'id'             => self::GRAPH_ID,
                'parentFolderId' => $folderId,
            ]],
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
            new \App\Service\Mail\SyncOrigin(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            new \App\Service\Mail\SyncNotifier(new RecordingHub(), new \Psr\Log\NullLogger()),
        );

        $this->account->graphDeltaLinks = [
            $folderId => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=old',
        ];

        $syncer->sync($this->account, [$folderId]);

        $this->em->flush();
    }

    /** A draft as the sync stored it: Drafts label, no sentAt, \Draft. */
    private function seedDraft(): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Sent from Outlook';
        $thread->normalizedSubject = 'sent from outlook';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 day');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Sent from Outlook';
        $message->fromAddress    = 'graph@example.test';
        $message->graphId        = self::GRAPH_ID;
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->flags          = [MessageFlag::DRAFT->value];
        $message->syncedAt       = new DateTimeImmutable();

        $message->addLabel($this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'role' => LabelRole::Drafts]));

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedLabel(string $name, LabelRole $role, string $graphFolderId): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        $binding                = new LabelBinding();
        $binding->label         = $label;
        $binding->account       = $this->account;
        $binding->graphFolderId = $graphFolderId;

        $label->addBinding($binding);

        $this->em->persist($binding);
        $this->em->flush();

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
        $user->email     = 'graph-sent-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'SentElsewhere';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
