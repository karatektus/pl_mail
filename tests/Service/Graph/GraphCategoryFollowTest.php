<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyGraphChangesHandler;
use App\Infrastructure\Messaging\Message\ApplyGraphChangesMessage;
use App\Service\Graph\GraphApiSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GraphApiClient;
use App\Tests\Support\Mercure\RecordingHub;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Outlook categories as the label backend, read back as a change (#221 step 4).
 *
 * A category put on or taken off in Outlook puts the label on or takes it off
 * here — read against Message::$graphCategories, what Exchange held when
 * plMail last imported or pushed the message, never as "the delta's list is
 * the truth". Against mocked Graph HTTP; no real mailbox is involved.
 */
final class GraphCategoryFollowTest extends KernelTestCase
{
    private const string INBOX_FOLDER = 'AAMkAD-inbox';
    private const string GRAPH_ID     = 'AAkALgAAcategories';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;
    private Label $inbox;

    /** @var array<string, array<string,mixed>> what the handler PATCHed, by graph id */
    private array $patches = [];

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

    // ── reading ──────────────────────────────────────────────────────────

    public function testACategoryPutOnInOutlookPutsTheLabelOn(): void
    {
        $this->account->followsOutlookCategories = true;
        $this->seedLabel('Clients', null, null);
        $message = $this->seedMessage(['Work'], ['Work']);

        $this->syncDelta(['categories' => ['Work', 'Clients']]);

        self::assertSame(['Clients', 'Work'], $this->tagNames($message));
        self::assertSame(['Work', 'Clients'], $message->graphCategories);
    }

    public function testACategoryTakenOffInOutlookTakesTheLabelOff(): void
    {
        $this->account->followsOutlookCategories = true;
        $message = $this->seedMessage(['Work'], ['Work']);

        $this->syncDelta(['categories' => []]);

        self::assertSame([], $this->tagNames($message));
    }

    /** A category made in Outlook and applied in one go becomes a new label. */
    public function testAnUnknownCategoryBecomesANewLabel(): void
    {
        $this->account->followsOutlookCategories = true;
        $message = $this->seedMessage([], []);

        $this->syncDelta(['categories' => ['Brand new']]);

        self::assertSame(['Brand new'], $this->tagNames($message));
    }

    /**
     * A label just set here, whose push has not landed: the delta still says
     * what the mirror says, so nothing is taken off.
     */
    public function testALabelSetHereIsNotTakenOffByADeltaThatPredatesThePush(): void
    {
        $this->account->followsOutlookCategories = true;
        $message = $this->seedMessage(['Work', 'Follow up'], ['Work']);

        $this->syncDelta(['categories' => ['Work']]);

        self::assertSame(['Follow up', 'Work'], $this->tagNames($message));
    }

    /** A row stored before the mirror existed learns it, and nothing changes. */
    public function testAnUnknownMirrorIsLearnedWithoutChangingLabels(): void
    {
        $this->account->followsOutlookCategories = true;
        $message = $this->seedMessage(['Local'], null);

        $this->syncDelta(['categories' => ['Other']]);

        self::assertSame(['Local'], $this->tagNames($message));
        self::assertSame(['Other'], $message->graphCategories);
    }

    /** Off by default: categories on mail already here are not followed. */
    public function testWithTheSwitchOffNothingIsFollowed(): void
    {
        $message = $this->seedMessage(['Work'], ['Work']);

        $this->syncDelta(['categories' => []]);

        self::assertSame(['Work'], $this->tagNames($message));
    }

    // ── writing ──────────────────────────────────────────────────────────

    /** A push that lands becomes the mirror, so its echo is not news. */
    public function testWhatIsPushedBecomesTheMirror(): void
    {
        $message = $this->seedMessage(['Work', 'Follow up'], ['Work']);

        $this->runHandler(new ApplyGraphChangesMessage((int) $this->account->id, [(int) $message->id]));

        self::assertEqualsCanonicalizing(['Work', 'Follow up'], $this->patches[self::GRAPH_ID]['categories'] ?? null);
        self::assertEqualsCanonicalizing(['Work', 'Follow up'], $this->em->find(Message::class, $message->id)?->graphCategories);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * @return list<string> the message's tag labels (no role, no folder), sorted
     */
    private function tagNames(Message $message): array
    {
        $this->em->refresh($message);

        $names = [];

        foreach ($message->labels as $label) {
            if (null === $label->role && null === $label->bindingFor($this->account)?->graphFolderId) {
                $names[] = (string) $label->name;
            }
        }

        sort($names);

        return $names;
    }

    /**
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
        $hub       = new RecordingHub();

        $syncer = new GraphApiSyncer(
            new GraphApiClient(
                new MockHttpClient([$this->json($delta)]),
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
            new \App\Service\Mail\SyncNotifier($hub, new \Psr\Log\NullLogger()),
        );

        $this->account->graphDeltaLinks = [self::INBOX_FOLDER => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=old'];

        $syncer->sync($this->account, [self::INBOX_FOLDER]);

        $this->em->flush();
    }

    private function runHandler(ApplyGraphChangesMessage $job): void
    {
        $container = self::getContainer();

        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = str_replace('/v1.0', '', (string) parse_url($url, PHP_URL_PATH));

            if ('POST' === $method && str_ends_with($path, '/$batch')) {
                $requests  = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR)['requests'];
                $responses = [];

                foreach ($requests as $request) {
                    $this->patches[rawurldecode(basename((string) $request['url']))] = $request['body'];
                    $responses[] = ['id' => $request['id'], 'status' => 200, 'body' => []];
                }

                return $this->json(['responses' => $responses]);
            }

            return $this->json(['value' => []]);
        });

        (new ApplyGraphChangesHandler(
            $container->get('App\Repository\Mail\AccountRepository'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('App\Repository\Label\LabelRepository'),
            new GraphApiClient($client, $container->get('App\Service\OAuth\OAuthTokenManager')),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get('messenger.default_bus'),
            $this->em,
            new \Psr\Log\NullLogger(),
            $container->get(LabelResolver::class),
        ))($job);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<string>      $tags   labels the message wears, made when missing
     * @param list<string>|null $mirror what Exchange was last seen to hold
     */
    private function seedMessage(array $tags, ?array $mirror): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Categories';
        $thread->normalizedSubject = 'categories';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');

        $message                  = new Message();
        $message->account         = $this->account;
        $message->thread          = $thread;
        $message->subject         = 'Categories';
        $message->fromAddress     = 'sender@example.test';
        $message->graphId         = self::GRAPH_ID;
        $message->receivedAt      = new DateTimeImmutable('-1 hour');
        $message->sentAt          = $message->receivedAt;
        $message->hasAttachments  = false;
        $message->flags           = [];
        $message->syncedAt        = new DateTimeImmutable();
        $message->graphCategories = $mirror;

        $message->addLabel($this->inbox);

        foreach ($tags as $name) {
            $message->addLabel($this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'name' => $name])
                ?? $this->seedLabel($name, null, null));
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

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'graph-categories-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Categories';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Graph categories fixture';
        $account->email          = 'graph-categories@example.test';
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
}
