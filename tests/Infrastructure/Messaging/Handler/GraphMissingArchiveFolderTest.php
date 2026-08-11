<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
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
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Archiving on an Exchange account whose Archive folder plMail has never bound.
 *
 * This used to read graphFolderId off the binding, find nothing, log a warning
 * and return — so the message left the inbox locally, the server never heard,
 * and the next delta put it back where it was. A warning nobody reads is not a
 * behaviour, and this is the case that produces it: `archive` is not a folder
 * every mailbox has, as GraphFolderSyncer already notes, and an account that
 * has not been folder-synced yet has bound none of them.
 *
 * The order matters more than the creation does. Exchange has a well-known name
 * for each standard folder, so an account that simply has not been synced
 * already *has* an Archive — asking for it by name binds the real one. Creating
 * without asking would leave a second folder called "Archive" beside the one
 * Outlook shows, which is the duplicate-folder mistake in the same family as
 * the duplicate rows this branch started on.
 */
final class GraphMissingArchiveFolderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labels;

    private User $user;
    private Account $account;

    /** @var list<string> the requests the handler made, method and path */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->labels     = $container->get(LabelResolver::class);

        $this->requests = [];

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The ordinary case: the mailbox has an Archive folder and plMail simply
     * had not recorded which one it was. One request by name settles it, and
     * nothing is created.
     */
    public function testAnUnboundArchiveIsResolvedByItsWellKnownNameRatherThanRecreated(): void
    {
        $archive = $this->labels->systemLabel(LabelRole::Archive, $this->account);
        $message = $this->message();
        $this->em->flush();

        $this->archiveVia($message, $archive, wellKnownExists: true);

        self::assertContains('GET /me/mailFolders/archive', $this->requests, 'it asks Exchange for its own Archive');
        self::assertNotContains('POST /me/mailFolders', $this->requests, 'and does not make a second one beside it');

        self::assertSame(
            'wk-archive',
            $archive->bindingFor($this->account)?->graphFolderId,
            'the real folder is bound, so the next archive is a plain read',
        );
    }

    /**
     * And the case creation exists for: a mailbox that genuinely has no Archive
     * folder. GraphFolderSyncer already notes `archive` is missing on some.
     */
    public function testAMailboxWithNoArchiveFolderGetsOneCreated(): void
    {
        $archive = $this->labels->systemLabel(LabelRole::Archive, $this->account);
        $message = $this->message();
        $this->em->flush();

        $this->archiveVia($message, $archive, wellKnownExists: false);

        self::assertContains('POST /me/mailFolders', $this->requests, 'asked for and not there, so it is made');

        self::assertSame(
            'made-archive',
            $archive->bindingFor($this->account)?->graphFolderId,
            'and the created folder is bound like any other',
        );
    }

    /**
     * A binding that already names a folder asks Exchange nothing at all. The
     * resolution is the fallback, not the path.
     */
    public function testAnAlreadyBoundArchiveAsksExchangeNothing(): void
    {
        $archive = $this->labels->systemLabel(LabelRole::Archive, $this->account);
        $this->labels->binding($archive, $this->account)->graphFolderId = 'already-known';
        $message = $this->message();
        $this->em->flush();

        $this->archiveVia($message, $archive, wellKnownExists: true);

        self::assertNotContains('GET /me/mailFolders/archive', $this->requests);
        self::assertNotContains('POST /me/mailFolders', $this->requests);
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function archiveVia(Message $message, Label $moveTo, bool $wellKnownExists): void
    {
        $container = self::getContainer();

        $client = new MockHttpClient(function (string $method, string $url) use ($wellKnownExists): ResponseInterface {
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $path = str_replace('/v1.0', '', (string) $path);

            $this->requests[] = $method . ' ' . $path;

            if ('GET' === $method && str_ends_with($path, '/mailFolders/archive')) {
                return $wellKnownExists
                    ? $this->json(['id' => 'wk-archive', 'displayName' => 'Archive'])
                    : $this->json(['error' => ['code' => 'ErrorItemNotFound']], 404);
            }

            if ('POST' === $method && str_ends_with($path, '/mailFolders')) {
                return $this->json(['id' => 'made-archive', 'displayName' => 'Archive']);
            }

            // Everything else the handler does on the way through — the state
            // PATCH batch and the move batch — answers plausibly and is not
            // what this file is about.
            return $this->json(['responses' => []]);
        });

        $handler = new ApplyGraphChangesHandler(
            $container->get('App\Repository\Mail\AccountRepository'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('App\Repository\Label\LabelRepository'),
            new GraphApiClient($client, $container->get('App\Service\OAuth\OAuthTokenManager')),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get('messenger.default_bus'),
            $this->em,
            new \Psr\Log\NullLogger(),
            $this->labels,
        );

        $handler(new ApplyGraphChangesMessage(
            (int) $this->account->id,
            [(int) $message->id],
            (int) $moveTo->id,
        ));

        $this->em->flush();
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function message(): Message
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Rechnung';
        $thread->normalizedSubject = 'rechnung';
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account        = $this->account;
        $message->graphId        = 'AAkALgAAmessage';
        $message->messageId      = uniqid('', true) . '@example.test';
        $message->subject        = 'Rechnung';
        $message->fromAddress    = 'kunde@example.test';
        $message->bodyText       = 'Rechnung';
        $message->hasAttachments = false;
        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt         = $message->receivedAt;

        $this->em->persist($message);
        $thread->addMessage($message);

        return $message;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'graph-archive-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Graph';
        $this->user->nameLast = 'Archive';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr            = $this->user;
        $this->account->email          = 'graph-archive-fixture@example.test';
        $this->account->username       = 'graph-archive-fixture@example.test';
        $this->account->imapHost       = 'outlook.office365.com';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'smtp.office365.com';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = AuthType::OAuth2->value;
        $this->account->oauthProvider  = MailProvider::Microsoft->value;
        $this->account->isActive       = true;
        // The client asks the token manager before every request, and without a
        // token that is good for a while it goes off to refresh one — which
        // fails, and every assertion below becomes "no requests were made".
        $this->account->oauthAccessToken = 'test-access-token';
        $this->account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $this->em->persist($this->account);

        $this->em->flush();

        $this->user->addAccount($this->account);

        $this->em->flush();
    }
}
