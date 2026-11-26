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
 * Two accounts that may both send as one address, under one sender name (#704).
 *
 * Gmail's "Send mail as" commonly lists each account's address on the other,
 * and once both accounts carry the same name the two From options read the
 * same. They used to be keyed by that text, so the second replaced the first:
 * the window opened on a token that was no longer an option, showed an empty
 * From, and the browser posted the first remaining option, which sent through
 * the other account. These pin that both options survive, that the user can
 * tell them apart, and that a From the window cannot show is never guessed.
 */
final class ComposeFromSharedAddressTest extends WebTestCase
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

    public function testBothAccountsKeepTheirOptionForTheSharedAddress(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $a      = $this->account($user, 'a@joder.dev', primary: true);
        $b      = $this->account($user, 'b@joder.dev', primary: false);
        $this->alias($b, 'a@joder.dev');

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();

        $options = $this->options($crawler);

        self::assertArrayHasKey($a->id . '|a@joder.dev', $options);
        self::assertArrayHasKey($b->id . '|a@joder.dev', $options);
        self::assertArrayHasKey($b->id . '|b@joder.dev', $options);
        self::assertSame($a->id . '|a@joder.dev', $this->selectedFrom($crawler), 'the window opens on the primary account, not on B');
    }

    /** The two that would read the same say which account they go through; the rest read as before. */
    public function testOnlyTheClashingOptionsNameTheirAccount(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $a      = $this->account($user, 'a@joder.dev', primary: true);
        $b      = $this->account($user, 'b@joder.dev', primary: false);
        $this->alias($b, 'a@joder.dev');

        $options = $this->options($client->request('GET', '/compose/new', server: self::DOCK_FRAME));

        self::assertSame('Jane Doe <a@joder.dev> · via a@joder.dev', $options[$a->id . '|a@joder.dev']);
        self::assertSame('Jane Doe <a@joder.dev> · via b@joder.dev', $options[$b->id . '|a@joder.dev']);
        self::assertSame('Jane Doe <b@joder.dev>', $options[$b->id . '|b@joder.dev']);
    }

    /**
     * A window opened on a token no option carries (here: a draft of an account
     * that has since stopped sending) used to leave the select with nothing
     * selected, and a browser then posts the first option.
     */
    public function testAWindowWhoseFromIsNotAnOptionPostsNothingRatherThanTheFirstOption(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $a      = $this->account($user, 'a@joder.dev', primary: true);
        $this->account($user, 'b@joder.dev', primary: false);

        $draft = $this->saveDraft($client, $a->id . '|a@joder.dev');

        $this->em->find(Account::class, $a->id)->sendEnabled = false;
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/edit/' . $draft->id, server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame('', $this->selectedFrom($crawler));
        self::assertStringContainsString(
            'Choose an address to send from',
            $crawler->filter('[data-compose--compose-target="fromLabel"]')->text(),
        );
    }

    /** And a send with no From is refused, where it used to fall back to an account. */
    public function testASendWithNoFromIsRefused(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $a      = $this->account($user, 'a@joder.dev', primary: true);

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);
        $token   = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', '/compose/send', ['compose' => [
            '_token'      => $token,
            'account'     => '',
            'toAddresses' => ['rike@example.test'],
            'subject'     => 'Sent from nowhere',
            'bodyHtml'    => '<p>Hello</p>',
        ]]);

        self::assertStringContainsString(
            'Choose an address to send from before sending.',
            (string) $client->getResponse()->getContent(),
        );

        $this->em->clear();

        $saved = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.account = :account AND m.subject = :subject',
        )->setParameter('account', $a->id)->setParameter('subject', 'Sent from nowhere')->getSingleScalarResult();

        self::assertSame(0, $saved);
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

    private function alias(Account $account, string $address): EmailAlias
    {
        $alias = new EmailAlias($account, $address, EmailAliasSource::Provider, EmailAliasStatus::Active);

        $account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
