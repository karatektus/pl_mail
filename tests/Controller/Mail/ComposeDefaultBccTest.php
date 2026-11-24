<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use App\Tests\Support\Mail\OpensComposeWindow;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The compose window's side of Always Bcc (#692): the addresses are in the Bcc
 * row, shown, when a new window opens, and the window carries every From
 * option's list for the From switch to trade them.
 *
 * DefaultBccSettingsTest covers the card that writes the setting, and
 * ReplyDraftBuilderTest the reply and forward drafts.
 */
final class ComposeDefaultBccTest extends WebTestCase
{
    use OpensComposeWindow;

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

    public function testANewWindowOpensWithTheDefaultBccShown(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'work@joder.dev');
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@joder.dev']);
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();

        $selected = $crawler->filter('select[name$="[bccAddresses][]"] option[selected]');

        self::assertCount(1, $selected);
        self::assertStringContainsString('backup@joder.dev', $selected->text());
        self::assertStringNotContainsString(
            'hidden',
            (string) $crawler->filter('[data-compose--compose-target="bccField"]')->attr('class'),
            'a Bcc the sender did not type is a Bcc the sender can see',
        );
    }

    public function testWithoutADefaultTheBccRowStaysFolded(): void
    {
        $client = static::createClient();
        $this->account($this->boot($client), 'work@joder.dev');

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertCount(0, $crawler->filter('select[name$="[bccAddresses][]"] option[selected]'));
        self::assertStringContainsString('hidden', (string) $crawler->filter('[data-compose--compose-target="bccField"]')->attr('class'));
    }

    /** A mailto: link that already Bccs the backup names it once. */
    public function testAMailtoLinkThatAlreadyBccsTheAddressNamesItOnce(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'work@joder.dev');
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@joder.dev', 'archive@joder.dev']);
        $this->em->flush();

        $crawler = $client->request(
            'GET',
            '/compose/new?mailto=' . rawurlencode('mailto:ada@example.com?bcc=Backup@joder.dev'),
            server: self::DOCK_FRAME,
        );

        $bcc = $crawler->filter('select[name$="[bccAddresses][]"] option[selected]')
            ->each(static fn ($option): string => strtolower($option->text()));

        self::assertCount(2, $bcc);
        self::assertCount(1, array_filter($bcc, static fn (string $text): bool => str_contains($text, 'backup@joder.dev')));
    }

    public function testTheWindowCarriesEveryFromOptionsBccList(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'work@joder.dev');
        $alias   = $this->alias($account, 'personal@joder.dev');

        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@joder.dev']);
        $account->setSetting(Account::defaultBccAliasSetting((int) $alias->id), []);
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);
        $map     = json_decode(
            (string) $crawler->filter('[data-compose--compose-default-bcc-value]')->attr('data-compose--compose-default-bcc-value'),
            true,
        );

        self::assertIsArray($map);
        self::assertSame(['backup@joder.dev'], $map[$account->id . '|work@joder.dev'] ?? null);
        self::assertSame([], $map[$account->id . '|personal@joder.dev'] ?? null);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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
            'UPDATE ' . Account::class . ' a SET a.mailEnabled = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr         = $user;
        $account->name        = $email;
        $account->username    = $email;
        $account->email       = $email;
        $account->authType    = 'password';
        $account->mailEnabled = true;
        $account->isPrimary   = true;
        $account->sortOrder   = 0;
        $account->imapHost    = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function alias(Account $account, string $address): EmailAlias
    {
        $alias = new EmailAlias($account, $address, EmailAliasSource::Manual, EmailAliasStatus::Active);

        $account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
