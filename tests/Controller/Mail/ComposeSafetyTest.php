<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Tests\Support\Mail\OpensComposeWindow;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The two halves of compose the browser cannot be trusted with.
 *
 * Which account a fresh window sends *from*, and whether the server will accept
 * a send addressed to nothing. Both came out of one external bug report, and
 * both are server-side questions: the From default is chosen before any script
 * runs, and "the UI would not let me do that" is not a reason the API may
 * assume it was not done.
 */
final class ComposeSafetyTest extends WebTestCase
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

    /**
     * The From selector must open on the account marked primary — whatever the
     * alphabet thinks.
     *
     * The reported case exactly: two accounts whose alphabetical order is the
     * reverse of the user's own arrangement. `isPrimary` is derived from
     * position 0 of that arrangement (AccountCreator::resequence()), so the
     * account badged PRIMARY in settings is the second one alphabetically —
     * and the settings page was listing, and badging, alphabetically.
     */
    public function testAFreshWindowSendsFromThePrimaryAccount(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        // "aaa@" sorts first; the primary is deliberately the other one.
        $this->account($user, 'aaa@joder.dev', sortOrder: 1, primary: false);
        $primary = $this->account($user, 'pmd@joder.dev', sortOrder: 0, primary: true);

        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();

        $selected = $crawler->filter('select.sr-only option[selected]');

        self::assertCount(1, $selected, 'exactly one From option is preselected');
        self::assertSame(
            $primary->id . '|pmd@joder.dev',
            $selected->attr('value'),
            'the primary account, not the alphabetically first one',
        );
    }

    /**
     * With nothing flagged primary the answer still has to be stable.
     *
     * Any account that was not created through AccountCreator::create() — a
     * seed, an import, a config restore — carries isPrimary = false, so this is
     * not a corner case. The fallback used to be an unordered findOneBy, which
     * meant the From default could differ between two loads of the same window.
     */
    public function testWithNoPrimaryFlagTheFirstArrangedAccountIsUsed(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $this->account($user, 'zzz@joder.dev', sortOrder: 0, primary: false);
        $this->account($user, 'aaa@joder.dev', sortOrder: 1, primary: false);

        $this->em->flush();

        $addresses = [];

        for ($i = 0; $i < 2; $i++) {
            $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

            self::assertResponseIsSuccessful();

            $addresses[] = $crawler->filter('select.sr-only option[selected]')->attr('value');
        }

        self::assertSame($addresses[0], $addresses[1], 'the same window twice gives the same From');
        self::assertStringEndsWith(
            '|zzz@joder.dev',
            (string) $addresses[0],
            'sortOrder 0 — the head of the user\'s own arrangement',
        );
    }

    /**
     * A send with no usable recipient is refused, and says why.
     *
     * The address in the report — `keine-gueltige-adresse`, no `@` — never
     * becomes a Contact (ContactAutocompleteField drops what it cannot
     * validate), so by the time the form is bound it is indistinguishable from
     * an empty To. One rule covers both, and it lives in the `send` validation
     * group so a *draft* may still be saved with no recipient.
     */
    public function testTheServerRefusesASendWithNoValidRecipient(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $account = $this->account($user, 'sender@joder.dev', sortOrder: 0, primary: true);

        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', '/compose/send', [
            'compose' => [
                '_token'      => $token,
                'account'     => $account->id . '|sender@joder.dev',
                'toAddresses' => ['keine-gueltige-adresse'],
                'subject'     => 'No valid recipient',
                'bodyHtml'    => '<p>Body</p>',
            ],
        ]);

        // 422, not 500 and not 200: the window comes back carrying its errors,
        // which is also the status Turbo needs to re-render a rejected form.
        self::assertResponseStatusCodeSame(422);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString(
            'toast-region',
            $body,
            'no send toast — nothing was dispatched',
        );
        self::assertStringContainsString(
            'recipient',
            strtolower($body),
            'and the window says what was wrong with it',
        );
    }

    /** A draft, by contrast, may have no recipient at all. */
    public function testADraftMayStillBeSavedWithNoRecipient(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $account = $this->account($user, 'drafter@joder.dev', sortOrder: 0, primary: true);

        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);
        $token   = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', '/compose/draft', [
            'compose' => [
                '_token'   => $token,
                'account'  => $account->id . '|drafter@joder.dev',
                'subject'  => 'Just thinking out loud',
                'bodyHtml' => '<p>Not addressed to anyone yet.</p>',
            ],
        ]);

        self::assertResponseIsSuccessful();
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function boot(object $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        // The fixtures below live in a transaction that is rolled back in
        // tearDown, so they exist only on this connection. A rebooted kernel
        // builds a new one and cannot see them — which is why a test making
        // two requests found no accounts on the second.
        $client->disableReboot();

        $this->connection->beginTransaction();

        // Every account this user already has would otherwise compete with the
        // two the test is about — the seeded mailbox included.
        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email, int $sortOrder, bool $primary): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = $primary;
        $account->sortOrder = $sortOrder;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);

        return $account;
    }
}
