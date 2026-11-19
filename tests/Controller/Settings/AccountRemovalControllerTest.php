<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a browser sees of removing an account (#672): the request answers at
 * once, the row stays greyed out with nothing to press while the worker
 * empties the account, and a tab opened before cannot switch it back on.
 *
 * The worker half is AccountRemovalTest's. Here nothing consumes the queue, so
 * the account stays mid-removal for the whole test, which is the state the page
 * has to draw.
 */
final class AccountRemovalControllerTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($user);

        $this->connection->beginTransaction();
        $this->account = MailFixtures::account($this->em, $user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testRemoveAnswersAtOnceWithTheRowGreyedOut(): void
    {
        $this->remove();

        self::assertResponseIsSuccessful();

        $row = sprintf('li[data-account-id="%d"]', $this->account->id);

        self::assertSelectorExists($row . '[data-account-removing]', 'the row stays, marked as on its way out');
        self::assertSelectorNotExists($row . ' form', 'and offers nothing to press');
        self::assertSelectorExists('turbo-stream[target="jobs-indicator"]', 'the work shows in the indicator straight away');

        $this->em->clear();

        self::assertNotNull($this->em->find(Account::class, $this->account->id), 'the worker removes it, not the request');
    }

    public function testAnOldTabCannotSwitchARemovingAccountBackOn(): void
    {
        $this->remove();

        $crawler = $this->client->request('GET', '/settings/accounts');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('li[data-account-id="%d"][data-account-removing]', $this->account->id)));

        $this->client->request('POST', sprintf('/account/%d/service/mail', $this->account->id), [
            '_token'  => $this->token('account-service' . $this->account->id),
            'enabled' => '1',
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testTheListsCanBeReadAgainWithoutAToast(): void
    {
        $this->remove();

        $this->client->request('GET', '/account/lists', server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-stream[target="settings-account-list"]');
        self::assertSelectorNotExists('turbo-stream[target="toast-region"]');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function remove(): void
    {
        $this->client->request('POST', sprintf('/account/%d/delete', $this->account->id), [
            '_token' => $this->token('delete' . $this->account->id),
        ], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
    }

    /** A token in the browser's own session, as AccountFolderActionsTest mints them. */
    private function token(string $id): string
    {
        $this->client->request('GET', '/settings/accounts');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $this->client->getRequest()->getSession()->save();
            $stack->pop();
        }
    }
}
