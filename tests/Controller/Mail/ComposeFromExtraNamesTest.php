<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use App\Tests\Support\Mail\OpensComposeWindow;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * One address, several names: "A", "a" and "Arnaud" all writing from
 * a@joder.dev, each a line of its own in the From menu.
 *
 * The name picked is carried by the draft — the address alone would put every
 * reopened draft back on the address's own name — and the window reopens on
 * the line that was picked.
 */
final class ComposeFromExtraNamesTest extends WebTestCase
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

    public function testEachNameIsALineOfItsOwnUnderItsAddress(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'a@joder.dev', primary: true);
        $alias   = $this->withNames($account, 'Arnaud', 'a');

        $options = $this->options($client->request('GET', '/compose/new', server: self::DOCK_FRAME));

        self::assertSame([
            $account->id . '|a@joder.dev' => 'Jane Doe <a@joder.dev>',
            $alias->identityId(1)         => 'Arnaud <a@joder.dev>',
            $alias->identityId(2)         => 'a <a@joder.dev>',
        ], $options);
    }

    public function testADraftKeepsTheNamePickedAndReopensOnIt(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'a@joder.dev', primary: true);
        $alias   = $this->withNames($account, 'Arnaud');

        $draft = $this->saveDraft($client, $alias->identityId(1));

        self::assertSame('a@joder.dev', $draft->fromAddress);
        self::assertSame('Arnaud', $draft->fromName);

        $crawler = $client->request('GET', '/compose/edit/' . $draft->id, server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame($alias->identityId(1), $this->selectedFrom($crawler));
    }

    /** A name another user's alias carries is not a From line anybody else may post. */
    public function testATokenForSomebodyElsesNameDoesNotLendItsName(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'a@joder.dev', primary: true);

        $stranger = new User();
        $stranger->email     = 'stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Some';
        $stranger->nameLast  = 'Body';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($stranger);
        $theirs = $this->account($stranger, 'them@joder.dev', primary: false);
        $alias  = $this->withNames($theirs, 'Not you');

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertArrayNotHasKey($alias->identityId(1), $this->options($crawler));

        $client->request('POST', '/compose/draft', ['compose' => [
            '_token'      => $crawler->filter('input[name="compose[_token]"]')->attr('value'),
            'account'     => $alias->identityId(1),
            'toAddresses' => ['rike@example.test'],
            'subject'     => 'Borrowed name',
            'bodyHtml'    => '<p>Hello</p>',
        ]]);

        $this->em->clear();

        $borrowed = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.fromName = :name',
        )->setParameter('name', 'Not you')->getSingleScalarResult();

        self::assertSame(0, $borrowed, 'a draft went out under somebody else\'s name');
        self::assertNotNull($account->id);
    }

    private function withNames(Account $account, string ...$names): EmailAlias
    {
        $alias = $account->aliasFor($account->email);
        self::assertNotNull($alias);

        foreach ($names as $name) {
            $alias->addExtraName($name, $account->senderNameFor($alias->address));
        }

        $this->em->flush();

        return $alias;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function saveDraft(KernelBrowser $client, string $fromToken): Message
    {
        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        $client->request('POST', '/compose/draft', ['compose' => [
            '_token'      => $crawler->filter('input[name="compose[_token]"]')->attr('value'),
            'account'     => $fromToken,
            'toAddresses' => ['rike@example.test'],
            'subject'     => 'Half written',
            'bodyHtml'    => '<p>Hello</p>',
        ]]);

        $this->em->clear();

        $draft = $this->em->createQuery(
            'SELECT m FROM ' . Message::class . ' m WHERE m.subject = :subject ORDER BY m.id DESC',
        )->setParameter('subject', 'Half written')->setMaxResults(1)->getOneOrNullResult();

        self::assertInstanceOf(Message::class, $draft, 'the POST saved a draft');

        return $draft;
    }

    /** @return array<string,string> token => label */
    private function options(Crawler $crawler): array
    {
        $options = [];

        $crawler->filter('select[name="compose[account]"] option')->each(
            static function (Crawler $option) use (&$options): void {
                $options[(string) $option->attr('value')] = trim($option->text());
            },
        );

        return $options;
    }

    private function selectedFrom(Crawler $crawler): string
    {
        $selected = $crawler->filter('select[name="compose[account]"] option[selected]');

        self::assertSame(1, $selected->count(), 'the From selector has exactly one option selected');

        return (string) $selected->attr('value');
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

        // The seed user's own accounts would add options of their own; only
        // the two made here should be in the selector.
        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.mailEnabled = false, a.sendEnabled = false, a.isPrimary = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email, bool $primary): Account
    {
        $account = new Account();

        $account->usr         = $user;
        $account->name        = 'Jane Doe';
        $account->username    = $email;
        $account->email       = $email;
        $account->authType    = 'password';
        $account->mailEnabled = true;
        $account->isPrimary   = $primary;
        $account->sortOrder   = $primary ? 0 : 1;
        $account->imapHost    = 'imap.example.test';

        $this->em->persist($account);

        $own = new EmailAlias($account, $email, EmailAliasSource::Manual, EmailAliasStatus::Primary);
        $account->addAlias($own);
        $this->em->persist($own);
        $this->em->flush();

        return $account;
    }
}
