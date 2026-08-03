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

    private const string DRAFTS_FOLDER = 'AAMkAD-drafts';
    private const string TRASH_FOLDER  = 'AAMkAD-trash';
    private const string GRAPH_ID      = 'AAkALgAAmessage';

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

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

    // ── Fixtures ─────────────────────────────────────────────────────────────

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
        );

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
