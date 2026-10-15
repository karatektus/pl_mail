<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Tests\Support\Mail\OpensComposeWindow;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The From a reply window opens on, and what a draft saved from it keeps.
 *
 * ReplyFromResolverTest pins which address is chosen. These pin that the
 * choice survives the three places it used to be lost: the window pre-setting
 * its selector from the account alone, the form refusing an address that is in
 * no list of aliases, and the saved draft naming the account holder instead of
 * the alias.
 */
final class ReplyFromAddressTest extends WebTestCase
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

    public function testAReplyToMailForAnAliasOpensOnThatAlias(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $this->alias($account, 'sales@joder.dev');

        $original = $this->received($account, to: 'sales@joder.dev');

        $crawler = $client->request('GET', '/compose/reply/' . $original->id, server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame($account->id . '|sales@joder.dev', $this->selectedFrom($crawler));
    }

    public function testAReplyToMailForThePrimaryAddressStaysOnIt(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $this->alias($account, 'sales@joder.dev');

        $original = $this->received($account, to: 'me@joder.dev');

        $crawler = $client->request('GET', '/compose/reply/' . $original->id, server: self::DOCK_FRAME);

        self::assertSame($account->id . '|me@joder.dev', $this->selectedFrom($crawler));
    }

    /** The alias's own signature, not the account's: the reply is signed as who sends it. */
    public function testAReplyFromAnAliasCarriesThatAliasesSignature(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $alias   = $this->alias($account, 'sales@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>John, privately</p>');
        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>The sales desk</p>');
        $this->em->flush();

        $original = $this->received($account, to: 'sales@joder.dev');

        $client->request('GET', '/compose/reply/' . $original->id, server: self::DOCK_FRAME);
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('The sales desk', $body);
        self::assertStringNotContainsString('John, privately', $body);
    }

    public function testAReplyToMailForACatchAllAddressOffersAndSelectsThatAddress(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');

        $account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $original = $this->received($account, to: 'yo@shop.test');

        $crawler = $client->request('GET', '/compose/reply/' . $original->id, server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame($account->id . '|yo@shop.test', $this->selectedFrom($crawler));
        self::assertStringContainsString(
            'yo@shop.test',
            $crawler->filter('select[name="compose[account]"] option[selected]')->text(),
        );
    }

    /**
     * The address is in no list the form was built from, so the form has to
     * admit it from the token — on a first save there is no stored draft yet.
     */
    public function testADraftFromACatchAllAddressIsSavedAsThatAddress(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');

        $account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $this->save($client, $account->id . '|yo@shop.test');

        $draft = $this->lastDraft($account);

        self::assertSame('yo@shop.test', $draft->fromAddress);
        self::assertSame('John Smith', $draft->fromName);
    }

    /** The same token for a domain nobody declared is a forged sender, and stays refused. */
    public function testAnAddressAtADomainThatIsNotACatchAllIsNotAccepted(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');

        $account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $this->save($client, $account->id . '|ceo@bank.test');

        $drafts = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.account = :account AND m.fromAddress = :from',
        )->setParameter('account', $account->id)->setParameter('from', 'ceo@bank.test')->getSingleScalarResult();

        self::assertSame(0, $drafts);
    }

    /** #70: the name in the From selector is the name the saved mail carries. */
    public function testADraftFromAnAliasWithItsOwnNameCarriesThatName(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $this->alias($account, 'family@joder.dev', 'Mr and Mrs Smith');

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertStringContainsString(
            'Mr and Mrs Smith <family@joder.dev>',
            $crawler->filter('select[name="compose[account]"]')->text(),
        );

        $this->save($client, $account->id . '|family@joder.dev');

        $draft = $this->lastDraft($account);

        self::assertSame('family@joder.dev', $draft->fromAddress);
        self::assertSame('Mr and Mrs Smith', $draft->fromName);
    }

    /** A draft saved from an alias used to reopen on the primary address. */
    public function testReopeningADraftKeepsTheAliasItWasSavedFrom(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');
        $this->alias($account, 'sales@joder.dev');

        $this->save($client, $account->id . '|sales@joder.dev');

        $draft   = $this->lastDraft($account);
        $crawler = $client->request('GET', '/compose/edit/' . $draft->id, server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame($account->id . '|sales@joder.dev', $this->selectedFrom($crawler));
    }

    // ── the change signal ────────────────────────────────────────────────────

    /**
     * Here because the composer is the write this was found on: a draft is
     * saved by a form on a timer, with no controller of its own to tell the
     * sidebar, and the Drafts badge did not move. The server marks the
     * response instead, and the counts answer carries the sequence a tab
     * compares a `mail.changed` nudge against.
     */
    public function testSavingADraftMarksTheResponseAndMovesTheCountsSequence(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'me@joder.dev');

        $client->request('GET', '/mail/sidebar/counts');
        $before = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsInt($before['state:sequence']);
        self::assertFalse(
            $client->getResponse()->headers->has('X-Mail-Changed'),
            'reading the counts changes nothing, and saying otherwise would make the sidebar ask for them forever',
        );

        $this->save($client, $account->id . '|me@joder.dev');

        self::assertSame('1', $client->getResponse()->headers->get('X-Mail-Changed'));

        $client->request('GET', '/mail/sidebar/counts');
        $after = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThan($before['state:sequence'], $after['state:sequence']);
        self::assertSame(1, $after['role:drafts']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function selectedFrom(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $selected = $crawler->filter('select[name="compose[account]"] option[selected]');

        self::assertSame(1, $selected->count(), 'the From selector has one option selected');

        return (string) $selected->attr('value');
    }

    private function save(KernelBrowser $client, string $fromToken): void
    {
        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);
        $token   = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', '/compose/draft', ['compose' => [
            '_token'      => $token,
            'account'     => $fromToken,
            'toAddresses' => ['rike@example.test'],
            'subject'     => 'From an address of mine',
            'bodyHtml'    => '<p>Hello</p>',
        ]]);
    }

    private function lastDraft(Account $account): Message
    {
        $this->em->clear();

        $message = $this->em->createQuery(
            'SELECT m FROM ' . Message::class . ' m WHERE m.account = :account AND m.subject = :subject ORDER BY m.id DESC',
        )->setParameter('account', $account->id)
            ->setParameter('subject', 'From an address of mine')
            ->setMaxResults(1)
            ->getOneOrNullResult();

        self::assertNotNull($message, 'the POST saved a draft');

        return $message;
    }

    private function received(Account $account, string $to): Message
    {
        $message                 = new Message();
        $message->account        = $account;
        $message->subject        = 'A question';
        $message->fromName       = 'Katharina';
        $message->fromAddress    = 'k@example.test';
        $message->toAddresses    = [['name' => '', 'address' => $to]];
        $message->messageId      = '<question-' . bin2hex(random_bytes(4)) . '@example.test>';
        $message->bodyHtml       = '<p>Do you ship to Bremen?</p>';
        $message->hasAttachments = false;
        $message->flags          = [];

        $this->em->persist($message);
        $this->em->flush();

        return $message;
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
