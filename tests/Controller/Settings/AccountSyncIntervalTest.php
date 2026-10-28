<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Choosing how often an account is polled (#202).
 *
 * A control on the account's row beside the colour menu, for the colour
 * menu's reason: AccountController::edit() refuses OAuth accounts, and every
 * account has an interval. AccountColourTest pins the same shape.
 *
 * - the owner sets an IMAP account's interval, and an OAuth one's too;
 * - somebody else's account cannot be changed by guessing its id;
 * - a POST without a valid CSRF token is refused;
 * - a value that is not one of the offered choices is refused, not rounded;
 * - every row offers every choice and marks the current one.
 *
 * Skips itself without the seeded users, as the other controller tests do.
 */
final class AccountSyncIntervalTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Staged inside a rolled-back transaction, so the kernel must not
        // reboot between requests; see AccountColourTest.
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->client->loginUser($user);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheOwnerSetsAnImapAccountsInterval(): void
    {
        $account = $this->account($this->user);

        $this->post($account, '5', $this->csrfToken($account));

        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->stored($account));
    }

    public function testTheOwnerSetsAnOauthAccountsIntervalToo(): void
    {
        $account = $this->account($this->user, oauth: true);

        $this->post($account, '60', $this->csrfToken($account));

        self::assertResponseIsSuccessful();
        self::assertSame(60, $this->stored($account));
    }

    public function testAnotherUsersAccountCannotBeChanged(): void
    {
        $theirs = $this->account($this->stranger());

        $this->post($theirs, '1', $this->csrfToken($theirs));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(15, $this->stored($theirs));
    }

    public function testAPostWithoutAValidCsrfTokenIsRefused(): void
    {
        $account = $this->account($this->user);

        $this->post($account, '1', 'not-the-token');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(15, $this->stored($account));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function valuesNotOffered(): iterable
    {
        yield 'between two choices' => ['10'];
        yield 'zero'                => ['0'];
        yield 'negative'            => ['-5'];
        yield 'not a number'        => ['often'];
        yield 'absent'              => [null];
    }

    #[DataProvider('valuesNotOffered')]
    public function testAValueThatIsNotOfferedIsRefused(?string $value): void
    {
        $account = $this->account($this->user);

        $this->post($account, $value, $this->csrfToken($account));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(15, $this->stored($account));
    }

    public function testEveryRowOffersEveryChoiceAndMarksTheCurrentOne(): void
    {
        $imap  = $this->account($this->user);
        $oauth = $this->account($this->user, oauth: true);
        $oauth->syncIntervalMinutes = 30;
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings?section=accounts');
        self::assertResponseIsSuccessful();

        foreach ([[$imap, 15], [$oauth, 30]] as [$account, $current]) {
            $choices = $crawler->filter(sprintf('form[action$="/account/%d/sync-interval"] button[name="minutes"]', $account->id));

            self::assertSame(
                array_map(strval(...), Account::SYNC_INTERVALS),
                $choices->each(static fn ($node): string => (string) $node->attr('value')),
            );

            $pressed = $choices->reduce(static fn ($node): bool => 'true' === $node->attr('aria-pressed'));

            self::assertCount(1, $pressed);
            self::assertSame((string) $current, $pressed->attr('value'));
        }
    }

    private function stored(Account $account): int
    {
        $this->em->clear();

        return (int) $this->em->find(Account::class, $account->id)?->syncIntervalMinutes;
    }

    private function post(Account $account, ?string $minutes, string $token): void
    {
        $payload = ['_token' => $token];

        if (null !== $minutes) {
            $payload['minutes'] = $minutes;
        }

        $this->client->request('POST', '/account/' . $account->id . '/sync-interval', $payload);
    }

    /** Fetched through the page and its session saved; see AccountColourTest. */
    private function csrfToken(Account $account): string
    {
        $this->client->request('GET', '/settings?section=accounts');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken('account-sync-interval' . $account->id)
                ->getValue();
        } finally {
            $this->client->getRequest()->getSession()->save();
            $stack->pop();
        }
    }

    private function stranger(): User
    {
        $user = new User();

        $user->email     = 'stranger-' . bin2hex(random_bytes(4)) . '@example.test';
        $user->password  = 'not-a-real-hash';
        $user->nameFirst = 'Stran';
        $user->nameLast  = 'Ger';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function account(User $user, bool $oauth = false): Account
    {
        $email   = 'interval-' . bin2hex(random_bytes(4)) . '@example.test';
        $account = new Account();

        $account->usr      = $user;
        $account->name     = $email;
        $account->username = $email;
        $account->email    = $email;
        $account->isActive = true;

        if ($oauth) {
            $account->authType          = AuthType::OAuth2->value;
            $account->oauthProvider     = MailProvider::Google->value;
            $account->oauthAccessToken  = 'access-token';
            $account->oauthRefreshToken = 'refresh-token';
        } else {
            $account->authType = AuthType::Password->value;
            $account->imapHost = 'imap.example.test';
        }

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
