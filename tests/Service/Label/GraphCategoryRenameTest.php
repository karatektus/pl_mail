<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\GraphApiException;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyLabelStructureHandler;
use App\Infrastructure\Messaging\Message\ApplyLabelStructureMessage;
use App\Service\Label\LabelResolver;
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
 * Renaming a label that is an Outlook category (#221 step 4).
 *
 * Graph documents a master category's displayName as fixed once created. The
 * PATCH is still tried; when Exchange refuses it, the rename is done as
 * create-under-the-new-name and delete-the-old, which leaves every message
 * exactly where an accepted rename would. Mocked Graph: what Exchange Online
 * really answers is not known here.
 */
final class GraphCategoryRenameTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private Label $label;

    /** @var list<string> method and path of every request made */
    private array $requests = [];

    /** @var int what Exchange answers the PATCH with */
    private int $patchStatus = 200;

    /** @var list<array{id: string, displayName: string}> the master list */
    private array $masters = [['id' => 'cat-old', 'displayName' => 'Receipts']];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
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

    public function testAnAcceptedRenameIsOnePatch(): void
    {
        $this->handler()($this->rename());

        self::assertContains('PATCH /me/outlook/masterCategories/cat-old', $this->requests);
        self::assertNotContains('POST /me/outlook/masterCategories', $this->requests);
        self::assertNotContains('DELETE /me/outlook/masterCategories/cat-old', $this->requests);
        self::assertSame('cat-old', $this->binding()->graphCategoryId);
    }

    public function testARefusedRenameMakesTheNewCategoryAndDropsTheOld(): void
    {
        $this->patchStatus = 400;

        $this->handler()($this->rename());

        self::assertContains('POST /me/outlook/masterCategories', $this->requests);
        self::assertContains('DELETE /me/outlook/masterCategories/cat-old', $this->requests);
        self::assertSame('cat-new', $this->binding()->graphCategoryId);
    }

    /** A category already under the new name is taken, not doubled. */
    public function testARefusedRenameOntoAnExistingNameReusesIt(): void
    {
        $this->patchStatus = 400;
        $this->masters[]   = ['id' => 'cat-there', 'displayName' => 'Bills'];

        $this->handler()($this->rename());

        self::assertNotContains('POST /me/outlook/masterCategories', $this->requests);
        self::assertContains('DELETE /me/outlook/masterCategories/cat-old', $this->requests);
        self::assertSame('cat-there', $this->binding()->graphCategoryId);
    }

    /** Exchange failing on its own side is not a refusal; the job retries. */
    public function testAServerErrorIsNotTreatedAsARefusal(): void
    {
        $this->patchStatus = 500;

        try {
            $this->handler()($this->rename());
            self::fail('a 500 on the rename was swallowed');
        } catch (GraphApiException) {
            $this->addToAssertionCount(1);
        }

        self::assertNotContains('POST /me/outlook/masterCategories', $this->requests);
        self::assertNotContains('DELETE /me/outlook/masterCategories/cat-old', $this->requests);
    }

    private function rename(): ApplyLabelStructureMessage
    {
        $this->label->name = 'Bills';
        $this->em->flush();

        return new ApplyLabelStructureMessage(
            accountId: (int) $this->account->id,
            action: ApplyLabelStructureMessage::ACTION_RENAME,
            labelId: (int) $this->label->id,
            fullName: 'Bills',
            categoryRemoteId: 'cat-old',
            previousFullName: 'Receipts',
        );
    }

    private function binding(): LabelBinding
    {
        $binding = self::getContainer()->get(LabelResolver::class)->binding($this->label, $this->account);
        $this->em->refresh($binding);

        return $binding;
    }

    private function handler(): ApplyLabelStructureHandler
    {
        $container = self::getContainer();

        $client = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $path = str_replace('/v1.0', '', (string) parse_url($url, PHP_URL_PATH));

            $this->requests[] = $method . ' ' . $path;

            if ('GET' === $method && '/me/outlook/masterCategories' === $path) {
                return $this->json(['value' => $this->masters]);
            }

            if ('PATCH' === $method) {
                return new MockResponse(
                    200 === $this->patchStatus ? '{}' : '{"error":{"code":"ErrorInvalidRequest"}}',
                    ['http_code' => $this->patchStatus],
                );
            }

            if ('POST' === $method) {
                return $this->json(['id' => 'cat-new', 'displayName' => 'Bills']);
            }

            return new MockResponse('', ['http_code' => 204]);
        });

        return new ApplyLabelStructureHandler(
            $container->get('App\Repository\Mail\AccountRepository'),
            $container->get('App\Repository\Label\LabelRepository'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('messenger.default_bus'),
            $container->get('App\Service\Graph\GraphCategoryColorMapper'),
            $container->get('App\Service\Gmail\GmailLabelColorMapper'),
            $container->get('App\Service\Mail\GmailApiClient'),
            new GraphApiClient($client, $container->get('App\Service\OAuth\OAuthTokenManager')),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get(LabelResolver::class),
            $this->em,
            new NullLogger(),
            $container->get('App\Service\JmapClient\JmapMailboxWriter'),
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
        $user->email     = 'graph-rename-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Rename';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                   = new Account();
        $account->usr              = $user;
        $account->email            = 'graph-rename-' . uniqid('', true) . '@example.test';
        $account->username         = $account->email;
        $account->imapHost         = 'outlook.office365.com';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->password         = 'x';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = MailProvider::Microsoft->value;
        $account->isActive         = true;
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $this->em->persist($account);
        $this->em->flush();

        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;

        $resolver = self::getContainer()->get(LabelResolver::class);
        $label    = $resolver->customChain(['Receipts'], $account);
        self::assertNotNull($label);
        $this->label = $label;

        $binding                  = $resolver->binding($label, $account);
        $binding->graphCategoryId = 'cat-old';
        $this->em->flush();
    }
}
