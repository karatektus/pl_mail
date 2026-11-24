<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Compose defaults card (#692): Always Bcc as chips, accounts folded to a
 * summary, and only the addresses that differ listed.
 *
 * Tokens are read off the rendered page, as SignatureSectionTest reads them,
 * so every test also proves the card draws the form it posts to.
 */
final class DefaultBccSettingsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PAGE        = '/settings/compose';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheAccountListIsSavedAndShownAgainAsChips(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $url     = sprintf('/account/%d/compose-defaults/bcc', $account->id);

        // As the chip field posts it: one value per chip.
        $client->request('POST', $url, [
            '_token'    => $this->token($client, sprintf('form[action="%s"]', $url)),
            'addresses' => ['Backup@Joder.dev', 'archive@joder.dev'],
        ]);

        self::assertResponseRedirects();
        self::assertSame(
            ['backup@joder.dev', 'archive@joder.dev'],
            $this->reload($account)->getSetting(Account::SETTING_DEFAULT_BCC),
        );

        $crawler = $client->request('GET', self::PAGE);
        $chips   = $crawler->filter(sprintf('select#bcc-default-%d option[selected]', $account->id));

        self::assertSame(['backup@joder.dev', 'archive@joder.dev'], $chips->each(static fn ($o): string => (string) $o->attr('value')));
        self::assertStringContainsString(
            'Bcc backup@joder.dev, archive@joder.dev',
            $crawler->filter(sprintf('#compose-defaults-summary-%d', $account->id))->text(),
            'the folded account says what it does',
        );
    }

    /** A comma-separated string — a form without the widget — is a list too. */
    public function testAPlainStringOfAddressesIsAcceptedToo(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $url     = sprintf('/account/%d/compose-defaults/bcc', $account->id);

        $client->request('POST', $url, [
            '_token'    => $this->token($client, sprintf('form[action="%s"]', $url)),
            'addresses' => 'one@joder.dev; two@joder.dev',
        ]);

        self::assertSame(['one@joder.dev', 'two@joder.dev'], $this->reload($account)->getSetting(Account::SETTING_DEFAULT_BCC));
    }

    public function testRemovingTheLastChipRemovesTheList(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@joder.dev']);
        $this->em->flush();

        $url = sprintf('/account/%d/compose-defaults/bcc', $account->id);

        // A <select multiple> with nothing selected posts no field at all.
        $client->request('POST', $url, ['_token' => $this->token($client, sprintf('form[action="%s"]', $url))]);

        self::assertNull($this->reload($account)->getSetting(Account::SETTING_DEFAULT_BCC));
    }

    /**
     * A chip saves without rebuilding the card: the response swaps the
     * summary lines and leaves the field being typed in alone.
     */
    public function testAChipChangeUpdatesTheSummariesAndNotTheField(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $url     = sprintf('/account/%d/compose-defaults/bcc', $account->id);

        $client->request('POST', $url, [
            '_token'    => $this->token($client, sprintf('form[action="%s"]', $url)),
            'addresses' => ['backup@joder.dev'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        $body = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(sprintf('target="compose-defaults-summary-%d"', $account->id), $body);
        self::assertStringContainsString('Bcc backup@joder.dev', $body);
        self::assertStringNotContainsString('target="settings-compose-defaults"', $body);
        self::assertStringNotContainsString('<select', $body);
    }

    /**
     * A list with a typo in it is refused whole and the stored list stays:
     * storing the good half would be a backup copy that quietly never comes.
     */
    public function testABadAddressIsRefusedAndNamed(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@joder.dev']);
        $this->em->flush();

        $url = sprintf('/account/%d/compose-defaults/bcc', $account->id);

        $client->request('POST', $url, [
            '_token'    => $this->token($client, sprintf('form[action="%s"]', $url)),
            'addresses' => ['new@joder.dev', 'typo@'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('typo@', (string) $client->getResponse()->getContent());
        self::assertSame(['backup@joder.dev'], $this->reload($account)->getSetting(Account::SETTING_DEFAULT_BCC));
    }

    /**
     * The card that did not scale: an address that follows its account is
     * not shown, only offered under "Add an exception for…".
     */
    public function testOnlyAddressesThatDifferAreListed(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $same    = $this->alias($account, 'same@joder.dev');
        $differs = $this->alias($account, 'differs@joder.dev');
        $account->setSetting(Account::readReceiptAliasSetting((int) $differs->id), 'always');
        $this->em->flush();

        $crawler = $client->request('GET', self::PAGE);

        self::assertNotNull(
            $crawler->filter(sprintf('[data-compose-defaults-exception="%d"]', $same->id))->attr('hidden'),
            'an address that follows its account is hidden',
        );
        self::assertNull($crawler->filter(sprintf('[data-compose-defaults-exception="%d"]', $differs->id))->attr('hidden'));
        self::assertSame(
            ['', (string) $same->id],
            $crawler->filter(sprintf('#exception-add-%d option', $account->id))->each(static fn ($o): string => (string) $o->attr('value')),
            'only the address that follows its account is offered as a new exception',
        );
        self::assertStringContainsString('Exceptions: 1', $crawler->filter(sprintf('#compose-defaults-summary-%d', $account->id))->text());
        self::assertStringContainsString('Receipts: always send', $crawler->filter(sprintf('#compose-defaults-alias-summary-%d', $differs->id))->text());
    }

    /**
     * Inherit, a list of its own, and the empty list that means "nobody" —
     * through the same controls a person uses.
     */
    public function testAnAddressCanOverrideAndGoBackToTheAccountsList(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $alias   = $this->alias($account, 'personal@joder.dev');
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@joder.dev']);
        $this->em->flush();

        $url   = sprintf('/account/%d/compose-defaults/%d/bcc', $account->id, $alias->id);
        $key   = Account::defaultBccAliasSetting((int) $alias->id);
        $token = $this->token($client, sprintf('form[action="%s"]', $url));

        // Started from the account's list, so the field opens on what was in force.
        $client->request('POST', $url, ['_token' => $token, 'override' => '1']);
        self::assertSame(['backup@joder.dev'], $this->reload($account)->getSetting($key));

        $client->request('POST', $url, ['_token' => $token]);
        self::assertSame([], $this->reload($account)->getSetting($key), 'no chips is "nobody", stored');

        $crawler = $client->request('GET', self::PAGE);
        self::assertNull($crawler->filter(sprintf('[data-compose-defaults-exception="%d"]', $alias->id))->attr('hidden'), 'now an exception');
        self::assertStringContainsString('Bcc nobody', $crawler->filter(sprintf('#compose-defaults-alias-summary-%d', $alias->id))->text());

        $client->request('POST', $url, ['_token' => $token, 'inherit' => '1']);
        self::assertNull($this->reload($account)->getSetting($key), 'inherit removes the key');
    }

    public function testRemovingAnExceptionClearsBothSettings(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $alias   = $this->alias($account, 'personal@joder.dev');
        $account->setSetting(Account::readReceiptAliasSetting((int) $alias->id), 'ask');
        $account->setSetting(Account::defaultBccAliasSetting((int) $alias->id), []);
        $this->em->flush();

        $url = sprintf('/account/%d/compose-defaults/%d/exception/remove', $account->id, $alias->id);

        $client->request('POST', $url, ['_token' => $this->token($client, sprintf('form[action="%s"]', $url))]);

        $account = $this->reload($account);
        self::assertNull($account->getSetting(Account::readReceiptAliasSetting((int) $alias->id)));
        self::assertNull($account->getSetting(Account::defaultBccAliasSetting((int) $alias->id)));
    }

    /** After a change the card comes back with that account open, not the first. */
    public function testTheEditedAccountStaysOpen(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $first  = $this->account($user, 'first@joder.dev');
        $second = $this->account($user, 'second@joder.dev', primary: false);
        $url    = sprintf('/account/%d/compose-defaults/read-receipt', $second->id);

        $client->request('POST', $url, [
            '_token' => $this->token($client, sprintf('form[action="%s"]', $url)),
            'mode'   => 'ask',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        $body = (string) $client->getResponse()->getContent();

        self::assertMatchesRegularExpression(sprintf('/data-compose-defaults-account="%d"\s+open/', $second->id), $body);
        self::assertDoesNotMatchRegularExpression(sprintf('/data-compose-defaults-account="%d"\s+open/', $first->id), $body);
    }

    public function testOverridingTwiceKeepsTheEditedList(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');
        $alias   = $this->alias($account, 'personal@joder.dev');
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@joder.dev']);
        $account->setSetting(Account::defaultBccAliasSetting((int) $alias->id), ['mine@joder.dev']);
        $this->em->flush();

        $url = sprintf('/account/%d/compose-defaults/%d/bcc', $account->id, $alias->id);

        $client->request('POST', $url, [
            '_token'   => $this->token($client, sprintf('form[action="%s"]', $url)),
            'override' => '1',
        ]);

        self::assertSame(['mine@joder.dev'], $this->reload($account)->getSetting(Account::defaultBccAliasSetting((int) $alias->id)));
    }

    public function testSavingWithoutACsrfTokenIsRefused(): void
    {
        $client  = static::createClient();
        $account = $this->account($this->boot($client), 'bcc@joder.dev');

        $client->request('POST', sprintf('/account/%d/compose-defaults/bcc', $account->id), [
            'addresses' => 'backup@joder.dev',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAliasOnAnotherAccountIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'mine@joder.dev');
        $alias   = $this->alias($account, 'yours@joder.dev');
        $other   = $this->account($user, 'other@joder.dev', primary: false);
        $token   = $this->token($client, sprintf('form[action="/account/%d/compose-defaults/%d/exception/remove"]', $account->id, $alias->id));

        $client->request('POST', sprintf('/account/%d/compose-defaults/%d/exception/remove', $other->id, $alias->id), [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function token(KernelBrowser $client, string $selector): string
    {
        $crawler = $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();

        $node = $crawler->filter($selector);

        self::assertGreaterThan(0, $node->count(), sprintf('the page draws %s', $selector));

        return (string) $node->filter('input[name="_token"]')->first()->attr('value');
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
            'UPDATE ' . Account::class . ' a SET a.mailEnabled = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email, bool $primary = true): Account
    {
        $account = new Account();

        $account->usr         = $user;
        $account->name        = $email;
        $account->username    = $email;
        $account->email       = $email;
        $account->authType    = 'password';
        $account->mailEnabled = true;
        $account->isPrimary   = $primary;
        $account->sortOrder   = $primary ? 0 : 1;
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
