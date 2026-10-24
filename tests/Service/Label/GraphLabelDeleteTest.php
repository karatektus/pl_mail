<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\GraphApiException;
use App\Domain\Exception\LabelDeleteRefusedException;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyLabelStructureHandler;
use App\Infrastructure\Messaging\Message\ApplyLabelStructureMessage;
use App\Service\Label\LabelResolver;
use App\Service\Label\RemoteLabelRemover;
use App\Service\Mail\GraphApiClient;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Deleting a label on Exchange, both ways it can be one (issue #40).
 *
 * A category: it used to be addressed by the folder id, which a category does
 * not have, so the delete never reached Exchange and the next sync read the
 * category back as a label. A folder: deleted only once Exchange says it is
 * empty, because Graph deletes a folder's mail along with it.
 */
final class GraphLabelDeleteTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    /** @var list<string> method and path of every request made */
    private array $requests = [];

    /** @var int what Exchange says the folder holds */
    private int $folderItems = 0;

    /** @var int how many folders Exchange says are inside it */
    private int $childFolders = 0;

    /** @var int what Exchange answers a DELETE with */
    private int $deleteStatus = 204;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

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

    public function testACategoryIsDeletedByItsOwnId(): void
    {
        $this->structureHandler()($this->deleteMessage(remoteId: null, categoryId: 'cat-1'));

        self::assertContains('DELETE /me/outlook/masterCategories/cat-1', $this->requests);
    }

    /** One recorded nowhere is found by name, which is its identity there. */
    public function testACategoryWithNoRecordedIdIsFoundByName(): void
    {
        $this->structureHandler()($this->deleteMessage(remoteId: null, categoryId: null));

        self::assertContains('GET /me/outlook/masterCategories', $this->requests);
        self::assertContains('DELETE /me/outlook/masterCategories/found-by-name', $this->requests);
    }

    /** A folder never goes this way: Graph would take its mail with it. */
    public function testAFolderIsRefusedByTheStructureHandler(): void
    {
        $this->structureHandler()($this->deleteMessage(remoteId: 'folder-1', categoryId: null));

        self::assertSame([], array_values(array_filter(
            $this->requests,
            static fn (string $request): bool => str_starts_with($request, 'DELETE'),
        )));
    }

    public function testAFolderWithMailInItIsNotDeleted(): void
    {
        $this->folderItems = 3;
        $binding           = $this->folderBinding();

        self::assertFalse($this->remover()->remove($binding));
        self::assertNotContains('DELETE /me/mailFolders/folder-1', $this->requests);
        self::assertSame('folder-1', $binding->graphFolderId);
    }

    /**
     * Graph deletes child folders with their parent, mail and all, and the
     * parent's item count does not include them. A subfolder plMail never
     * synced is still a reason to wait.
     */
    public function testAFolderWithAFolderInsideItIsNotDeleted(): void
    {
        $this->childFolders = 1;
        $binding            = $this->folderBinding();

        self::assertFalse($this->remover()->remove($binding));
        self::assertNotContains('DELETE /me/mailFolders/folder-1', $this->requests);
    }

    /** Exchange saying no to the delete is its answer, and ends the job. */
    public function testAForbiddenDeleteIsARefusal(): void
    {
        $this->deleteStatus = 403;
        $binding            = $this->folderBinding();

        try {
            $this->remover()->remove($binding);
            self::fail('a 403 on the delete was not reported as a refusal');
        } catch (LabelDeleteRefusedException $e) {
            self::assertSame('Receipts', $e->folder);
        }

        self::assertSame('folder-1', $binding->graphFolderId, 'a refused folder was forgotten');
    }

    /** Exchange failing on its own side is weather, and is waited out. */
    public function testAServerErrorOnTheDeleteIsNotARefusal(): void
    {
        $this->deleteStatus = 503;
        $binding            = $this->folderBinding();

        try {
            $this->remover()->remove($binding);
            self::fail('a 503 on the delete was swallowed');
        } catch (LabelDeleteRefusedException) {
            self::fail('a 503 on the delete was reported as a refusal');
        } catch (GraphApiException) {
            // GraphApiClient reads a 503 as Exchange asking to slow down; what
            // matters here is only that it reached the caller as itself, which
            // the catch above it already rules out being a refusal.
            $this->addToAssertionCount(1);
        }
    }

    public function testAnEmptyFolderIsDeletedAndForgotten(): void
    {
        $binding = $this->folderBinding();

        self::assertTrue($this->remover()->remove($binding));
        self::assertContains('DELETE /me/mailFolders/folder-1', $this->requests);
        self::assertNull($binding->graphFolderId);
    }

    private function deleteMessage(?string $remoteId, ?string $categoryId): ApplyLabelStructureMessage
    {
        return new ApplyLabelStructureMessage(
            accountId: (int) $this->account->id,
            action: ApplyLabelStructureMessage::ACTION_DELETE,
            labelId: 999_999_999,
            fullName: 'Receipts',
            remoteId: $remoteId,
            categoryRemoteId: $categoryId,
        );
    }

    private function folderBinding(): LabelBinding
    {
        $resolver = self::getContainer()->get(LabelResolver::class);
        $label    = $resolver->customChain(['Receipts'], $this->account);

        self::assertNotNull($label);

        $binding                = $resolver->binding($label, $this->account);
        $binding->graphFolderId = 'folder-1';
        $this->em->flush();

        return $binding;
    }

    private function graph(): GraphApiClient
    {
        $client = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $path = str_replace('/v1.0', '', (string) parse_url($url, PHP_URL_PATH));

            $this->requests[] = $method . ' ' . $path;

            if ('GET' === $method && '/me/outlook/masterCategories' === $path) {
                return $this->json(['value' => [['id' => 'found-by-name', 'displayName' => 'Receipts']]]);
            }

            if ('GET' === $method && str_starts_with($path, '/me/mailFolders/')) {
                return $this->json(['totalItemCount' => $this->folderItems, 'childFolderCount' => $this->childFolders]);
            }

            if ('DELETE' === $method) {
                return new MockResponse(204 === $this->deleteStatus ? '' : '{"error":{"code":"ErrorAccessDenied"}}', ['http_code' => $this->deleteStatus]);
            }

            return new MockResponse('', ['http_code' => 204]);
        });

        return new GraphApiClient($client, self::getContainer()->get('App\Service\OAuth\OAuthTokenManager'));
    }

    private function structureHandler(): ApplyLabelStructureHandler
    {
        $container = self::getContainer();

        return new ApplyLabelStructureHandler(
            $container->get('App\Repository\Mail\AccountRepository'),
            $container->get('App\Repository\Label\LabelRepository'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('messenger.default_bus'),
            $container->get('App\Service\Graph\GraphCategoryColorMapper'),
            $container->get('App\Service\Gmail\GmailLabelColorMapper'),
            $container->get('App\Service\Mail\GmailApiClient'),
            $this->graph(),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get(LabelResolver::class),
            $this->em,
            new NullLogger(),
        );
    }

    private function remover(): RemoteLabelRemover
    {
        $container = self::getContainer();

        return new RemoteLabelRemover(
            $container->get('App\Domain\Helper\ImapConnectionFactory'),
            $container->get('App\Service\Mail\GmailApiClient'),
            $this->graph(),
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('App\Service\Imap\ImapFolderProvisioner'),
            $container->get(LabelResolver::class),
            $this->em,
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'graph-delete-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Delete';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                   = new Account();
        $account->usr              = $user;
        $account->email            = 'graph-delete-' . uniqid('', true) . '@example.test';
        $account->username         = $account->email;
        $account->imapHost         = 'outlook.office365.com';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->smtpHost         = 'smtp.office365.com';
        $account->smtpPort         = 587;
        $account->smtpEncryption   = 'starttls';
        $account->password         = 'x';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = MailProvider::Microsoft->value;
        $account->isActive         = true;
        // Without a token that is good for a while, the client goes off to
        // refresh one and no request reaches the mock.
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $this->em->persist($account);

        $this->em->flush();

        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;
    }
}
