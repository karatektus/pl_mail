<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SeverGmailifyLinksMessage;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Settings → Aliases: the Gmailify switch on a Gmail account (#205).
 *
 * Driven through the page's own form, as AliasSenderNameAndCatchAllTest is,
 * so a switch that stops being drawn fails here as well as one that stops
 * being stored.
 */
final class AliasGmailifyTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** On by default, which is what every install did before the switch. */
    public function testAGmailAccountIsOfferedTheSwitchAndItStartsOn(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $gmail  = $this->account($user, 'me@gmail.test', true);
        $plain  = $this->account($user, 'me@joder.dev', false);

        $crawler = $client->request('GET', '/settings?section=aliases');

        $form = $crawler->filter(sprintf('[data-gmailify="%d"]', $gmail->id));

        self::assertSame(1, $form->count());
        self::assertSame('1', $form->filter('input[name="enabled"]:checked')->attr('value'));
        self::assertSame(0, $crawler->filter(sprintf('[data-gmailify="%d"]', $plain->id))->count(), 'not a setting of an IMAP account');
    }

    /**
     * Off is stored at once and the detaching is queued: a mailbox that
     * collected for years carries tens of thousands of copies, minutes of work
     * a request has no time for. On again only clears the setting.
     */
    public function testSwitchingItOffQueuesTheDetaching(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $gmail  = $this->account($user, 'me@gmail.test', true);

        $url   = sprintf('/account/%d/alias/gmailify', $gmail->id);
        $token = $this->token($client, $url);

        $client->request('POST', $url, ['_token' => $token, 'enabled' => '0']);

        self::assertResponseRedirects();
        self::assertFalse($this->reload($gmail)->gmailifyEnabled);

        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame($gmail->id, $queued[0]->accountId);

        $client->request('POST', $url, ['_token' => $token, 'enabled' => '1']);

        $gmail = $this->reload($gmail);
        self::assertTrue($gmail->gmailifyEnabled);
        self::assertSame('absent', $gmail->getSetting(Account::SETTING_GMAILIFY, 'absent'), 'on is the default, and is not stored');
        // The in-memory transport is reset between requests, so this is what
        // the second request queued: nothing.
        self::assertCount(0, $this->queued(), 'turning it on queues nothing');
    }

    public function testTheSwitchCannotBeFlippedWithoutTheRowsToken(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $gmail  = $this->account($user, 'me@gmail.test', true);

        $client->request('POST', sprintf('/account/%d/alias/gmailify', $gmail->id), [
            '_token'  => 'not-the-token',
            'enabled' => '0',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($gmail)->gmailifyEnabled);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<SeverGmailifyLinksMessage> */
    private function queued(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.maintenance');

        return array_values(array_filter(
            array_map(static fn (object $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof SeverGmailifyLinksMessage,
        ));
    }

    private function token(KernelBrowser $client, string $formAction): string
    {
        $crawler = $client->request('GET', '/settings?section=aliases');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[action="%s"]', $formAction));

        self::assertGreaterThan(0, $form->count(), sprintf('the panel renders a form posting to %s', $formAction));

        return (string) $form->filter('input[name="_token"]')->first()->attr('value');
    }

    private function reload(Account $account): Account
    {
        $this->em->clear();

        return $this->em->find(Account::class, $account->id);
    }

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email, bool $gmail): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = 'John Smith';
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = true === $gmail ? 'oauth2' : 'password';
        $account->isActive  = true;
        $account->isPrimary = false;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';

        if (true === $gmail) {
            $account->oauthProvider = 'google';
        }

        $this->em->persist($account);

        $primary = new EmailAlias($account, $email, EmailAliasSource::Manual, EmailAliasStatus::Primary);
        $account->addAlias($primary);
        $this->em->persist($primary);
        $this->em->flush();

        return $account;
    }
}
