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
use App\Service\Label\LabelResolver;
use App\Service\Mail\GraphApiClient;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Outlook categories as the label backend (#221 step 4): the mirror of what
 * Exchange holds, Message::$graphCategories, follows what plMail pushes.
 * Against mocked Graph HTTP; no real mailbox is involved.
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
