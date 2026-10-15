<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Aliases: the name an address sends under, and catch-all domains.
 *
 * Both are driven through the page's own forms — the token is read off the
 * rendered row — so a field that stops being drawn fails here as well as one
 * that stops being stored.
 */
final class AliasSenderNameAndCatchAllTest extends WebTestCase
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

    // ── sender name ──────────────────────────────────────────────────────────

    public function testAnAliasCanBeGivenANameToSendUnder(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $alias   = $this->alias($account, 'family@joder.dev');

        $url = sprintf('/account/%d/alias/%d/name', $account->id, $alias->id);

        $client->request('POST', $url, [
            '_token' => $this->token($client, $url),
            'name'   => "  Mr and Mrs\r\nSmith ",
        ]);

        self::assertResponseRedirects();

        $account = $this->reload($account);

        // The line break is gone: the name is shown in a selector and sent in
        // a header, and neither has room for one.
        self::assertSame('Mr and Mrs Smith', $account->senderNameFor('family@joder.dev'));
        self::assertSame('John Smith', $account->senderNameFor('me@joder.dev'), 'the other address is untouched');
    }

    public function testClearingTheNameGoesBackToTheAccounts(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $alias   = $this->alias($account, 'family@joder.dev', 'Mr and Mrs Smith');

        $url = sprintf('/account/%d/alias/%d/name', $account->id, $alias->id);

        $client->request('POST', $url, ['_token' => $this->token($client, $url), 'name' => '   ']);

        self::assertSame('John Smith', $this->reload($account)->senderNameFor('family@joder.dev'));
    }

    /** The placeholder is what the recipient sees until a name is typed. */
    public function testTheNameFieldShowsTheStoredNameOverTheAccountsAsPlaceholder(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $alias   = $this->alias($account, 'family@joder.dev', 'Mr and Mrs Smith');

        $crawler = $client->request('GET', '/settings?section=aliases');
        $field   = $crawler->filter(sprintf('form[action$="/alias/%d/name"] input[name="name"]', $alias->id));

        self::assertSame(1, $field->count());
        self::assertSame('Mr and Mrs Smith', $field->attr('value'));
        self::assertSame('John Smith', $field->attr('placeholder'));
    }

    public function testANameCannotBeSetWithoutTheRowsToken(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $alias   = $this->alias($account, 'family@joder.dev');

        $client->request('POST', sprintf('/account/%d/alias/%d/name', $account->id, $alias->id), [
            '_token' => 'not-the-token',
            'name'   => 'Somebody Else',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('John Smith', $this->reload($account)->senderNameFor('family@joder.dev'));
    }

    // ── catch-all domains ────────────────────────────────────────────────────

    #[DataProvider('domainSpellings')]
    public function testADomainIsAcceptedHoweverItWasTyped(string $typed): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');

        $url = sprintf('/account/%d/alias/catch-all/add', $account->id);

        $client->request('POST', $url, ['_token' => $this->token($client, $url), 'domain' => $typed]);

        self::assertSame(['shop.test'], $this->reload($account)->catchAllDomains);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function domainSpellings(): iterable
    {
        yield 'bare' => ['shop.test'];
        yield 'capitalised, with spaces' => ['  Shop.TEST '];
        yield 'with the at sign' => ['@shop.test'];
        yield 'as a wildcard address' => ['*@shop.test'];
        yield 'as one of its addresses' => ['yo@shop.test'];
    }

    #[DataProvider('notDomains')]
    public function testWhatIsNotADomainIsRefused(string $typed): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');

        $url = sprintf('/account/%d/alias/catch-all/add', $account->id);

        $client->request('POST', $url, ['_token' => $this->token($client, $url), 'domain' => $typed]);

        self::assertSame([], $this->reload($account)->catchAllDomains);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDomains(): iterable
    {
        yield 'empty' => [''];
        yield 'one label' => ['localhost'];
        yield 'every domain' => ['*'];
        yield 'a wildcard label' => ['*.test'];
        yield 'a space inside' => ['shop .test'];
        yield 'a leading hyphen' => ['-shop.test'];
        yield 'a numeric top level' => ['192.168.1.1'];
    }

    public function testACatchAllDomainIsListedAndCanBeRemoved(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');

        $account->catchAllDomains = ['shop.test', 'other.test'];
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=aliases');

        self::assertStringContainsString('*@shop.test', $crawler->filter('[data-catch-all]')->text());

        $url = sprintf('/account/%d/alias/catch-all/remove', $account->id);

        $client->request('POST', $url, ['_token' => $this->token($client, $url), 'domain' => 'shop.test']);

        self::assertSame(['other.test'], $this->reload($account)->catchAllDomains);
    }

    /**
     * Gmail sends only as addresses Google knows about, so the section is not
     * drawn there and a hand-made POST is refused: the setting would promise
     * something the first reply would break.
     */
    public function testAGmailAccountIsNotOfferedCatchAllDomainsAndCannotBeGivenOne(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $plain  = $this->account($user, 'me@joder.dev');
        $gmail  = $this->account($user, 'me@gmail.test');

        // Read while the account is still a password one and the form is
        // drawn: the token is per account, and afterwards there is no form to
        // read it from. That is the stale page a real refusal would come from.
        $url   = sprintf('/account/%d/alias/catch-all/add', $gmail->id);
        $token = $this->token($client, $url);

        $gmail                = $this->reload($gmail);
        $gmail->authType      = 'oauth2';
        $gmail->oauthProvider = 'google';
        $this->em->flush();

        self::assertTrue($gmail->isGmail(), 'the fixture is a Gmail account');

        $crawler = $client->request('GET', '/settings?section=aliases');

        self::assertSame(1, $crawler->filter(sprintf('[data-catch-all="%d"]', $plain->id))->count());
        self::assertSame(0, $crawler->filter(sprintf('[data-catch-all="%d"]', $gmail->id))->count());

        $client->request('POST', $url, ['_token' => $token, 'domain' => 'shop.test']);

        self::assertSame([], $this->reload($gmail)->catchAllDomains);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = 'John Smith';
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = true;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);

        $primary = new EmailAlias($account, $email, EmailAliasSource::Manual, EmailAliasStatus::Primary);
        $account->addAlias($primary);
        $this->em->persist($primary);
        $this->em->flush();

        return $account;
    }

    private function alias(Account $account, string $address, ?string $name = null): EmailAlias
    {
        $alias = new EmailAlias($account, $address, EmailAliasSource::Manual, EmailAliasStatus::Active, $name);

        $account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
