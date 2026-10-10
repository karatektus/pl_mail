<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Twig\SenderAvatarExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Changing the colour an account wears.
 *
 * `Account::$colorIndex` is handed out once, at creation, as the lowest free
 * slot of an eight-entry palette — and nothing ever wrote it again, so a user
 * who disliked the orange their second account got, or wanted two accounts to
 * read as a pair, had no way to say so.
 *
 * It is changed from the account's row in Settings and NOT from the edit
 * modal, which is the one place it could not be: AccountController::edit()
 * refuses an OAuth account outright, so a colour field there would have been
 * missing on every Gmail and Outlook account. That is claim two below, and it
 * is the claim most likely to be broken by someone "tidying" the control into
 * the form it looks like it belongs in.
 *
 * - the owner can change an IMAP account's colour, and the sidebar dot follows;
 * - the owner can change an OAuth account's colour just the same;
 * - changing one account leaves its siblings as they were;
 * - somebody else's account cannot be recoloured by guessing its id;
 * - a POST without a valid CSRF token is refused;
 * - a value outside the palette is refused, not wrapped round by the modulo the
 *   renderer applies — the renderer's tolerance is for old rows, not for input;
 * - the row offers the whole palette and says which one is current.
 *
 * Skips itself without the seeded users, as the other controller tests do.
 */
final class AccountColourTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The rows below are staged inside a rolled-back transaction, so the
        // kernel must not reboot between requests — a rebooted kernel opens a
        // fresh connection that cannot see them. Same as PushDeviceRemovalTest.
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

    public function testTheOwnerRecoloursAnImapAccountAndItsSidebarDotFollows(): void
    {
        $account = $this->account($this->user, $this->address(), colorIndex: 0);

        // The baseline half of the claim: no dot is index 3's tone yet, so
        // finding them there afterwards cannot be an accident of the fixture.
        foreach ($this->sidebarDotClasses($account) as $class) {
            self::assertStringNotContainsString($this->tone(3), $class, 'the account starts on a different colour');
        }

        $this->post($account, '3', $this->csrfToken($account));

        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(3, $this->em->find(Account::class, $account->id)?->colorIndex);

        foreach ($this->sidebarDotClasses($account) as $class) {
            self::assertStringContainsString($this->tone(3), $class, 'every sidebar dot wears the new colour');
        }
    }

    /**
     * The reason this is a control on the row and not a field in the edit
     * modal: that modal is closed to OAuth accounts.
     */
    public function testTheOwnerRecoloursAnOauthAccountToo(): void
    {
        $account = $this->oauthAccount($this->user, $this->address(), colorIndex: 1);

        $this->post($account, '5', $this->csrfToken($account));

        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(5, $this->em->find(Account::class, $account->id)?->colorIndex);
    }

    public function testRecolouringOneAccountLeavesItsSiblingsAlone(): void
    {
        $target  = $this->account($this->user, $this->address(), colorIndex: 0);
        $sibling = $this->account($this->user, $this->address(), colorIndex: 2);

        $this->post($target, '6', $this->csrfToken($target));

        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(6, $this->em->find(Account::class, $target->id)?->colorIndex, 'the target moved');
        self::assertSame(2, $this->em->find(Account::class, $sibling->id)?->colorIndex, 'the sibling did not');
    }

    /**
     * The id is the only handle the page has, and it is a small integer — so
     * it is checked against the owner rather than trusted.
     */
    public function testAnotherUsersAccountCannotBeRecolouredByGuessingItsId(): void
    {
        $stranger = $this->stranger();
        $theirs   = $this->account($stranger, $this->address(), colorIndex: 2);

        $this->post($theirs, '4', $this->csrfToken($theirs));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertSame(2, $this->em->find(Account::class, $theirs->id)?->colorIndex, 'their colour is untouched');
    }

    public function testAPostWithoutAValidCsrfTokenIsRefused(): void
    {
        $account = $this->account($this->user, $this->address(), colorIndex: 2);

        $this->post($account, '4', 'not-the-token');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertSame(2, $this->em->find(Account::class, $account->id)?->colorIndex);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function valuesOutsideThePalette(): iterable
    {
        // One past the end is the case the renderer's modulo would quietly
        // accept as index 0, which is exactly why it has to be refused here.
        yield 'one past the last entry' => [(string) SenderAvatarExtension::ACCOUNT_COLOURS];
        yield 'negative'                => ['-1'];
        yield 'not a number'            => ['teal'];
        yield 'a fraction'              => ['2.5'];
        yield 'empty'                   => [''];
        yield 'absent'                  => [null];
    }

    #[DataProvider('valuesOutsideThePalette')]
    public function testAValueOutsideThePaletteIsRefusedAndNothingIsWritten(?string $value): void
    {
        $account = $this->account($this->user, $this->address(), colorIndex: 2);

        $this->post($account, $value, $this->csrfToken($account));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertSame(2, $this->em->find(Account::class, $account->id)?->colorIndex);
    }

    /**
     * The presence half of the refusals above: the same request with an
     * in-range value goes through, so they were refused for the value and not
     * because the fixture or the token was wrong.
     */
    public function testTheLastEntryOfThePaletteIsAccepted(): void
    {
        $account = $this->account($this->user, $this->address(), colorIndex: 0);
        $last    = SenderAvatarExtension::ACCOUNT_COLOURS - 1;

        $this->post($account, (string) $last, $this->csrfToken($account));

        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame($last, $this->em->find(Account::class, $account->id)?->colorIndex);
    }

    /**
     * Both kinds of account, because the control exists for the OAuth one — and
     * the current entry is marked, so the user is not left to guess which of
     * eight dots they are already wearing.
     */
    public function testEveryRowOffersTheWholePaletteAndMarksTheCurrentEntry(): void
    {
        $imap  = $this->account($this->user, $this->address(), colorIndex: 4);
        $oauth = $this->oauthAccount($this->user, $this->address(), colorIndex: 6);

        $crawler = $this->client->request('GET', '/settings?section=accounts');
        self::assertResponseIsSuccessful();

        foreach ([[$imap, 4], [$oauth, 6]] as [$account, $current]) {
            $swatches = $crawler->filter(sprintf('form[action$="/account/%d/colour"] button[name="colour"]', $account->id));

            self::assertCount(
                SenderAvatarExtension::ACCOUNT_COLOURS,
                $swatches,
                sprintf('%s (%s) is offered every colour', $account->email, $account->authType),
            );

            $pressed = $swatches->reduce(static fn ($node): bool => 'true' === $node->attr('aria-pressed'));

            self::assertCount(1, $pressed, 'exactly one entry is marked');
            self::assertSame((string) $current, $pressed->attr('value'), 'and it is the account\'s own');
        }
    }

    /**
     * The palette lives in SenderAvatarExtension and the names the picker reads
     * out live in the catalogue, because a colour's name is a word and words are
     * translated. Two places for one list — so a ninth entry without a name
     * would render as a raw translation key under its swatch, and nothing else
     * would notice. The other locales are held to the English keys by
     * CatalogueParityTest.
     */
    public function testEveryPaletteEntryHasAName(): void
    {
        $tree  = Yaml::parseFile(dirname(__DIR__, 3) . '/translations/messages.en.yaml');
        $names = $tree['settings']['accounts']['colour']['swatch'] ?? [];

        self::assertSame(
            range(0, SenderAvatarExtension::ACCOUNT_COLOURS - 1),
            array_keys($names),
            'one name per palette entry, indexed the way colorIndex is',
        );
    }

    private function post(Account $account, ?string $colour, string $token): void
    {
        $payload = ['_token' => $token];

        if (null !== $colour) {
            $payload['colour'] = $colour;
        }

        $this->client->request('POST', '/account/' . $account->id . '/colour', $payload);
    }

    /**
     * The token manager reads the session off the current request, and there
     * is no current request between two client calls — so the page the form
     * lives on is fetched and its session pushed onto the stack, exactly as
     * PushDeviceRemovalTest does it.
     */
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
                ->getToken('account-colour' . $account->id)
                ->getValue();
        } finally {
            // Generated outside any kernel request, so nothing will save the
            // session for us — without this the token exists only in memory
            // and the POST's freshly-loaded session has never heard of it.
            $this->client->getRequest()->getSession()->save();
            $stack->pop();
        }
    }

    /**
     * The class list of each of this account's dots in the sidebar — the mark
     * that is meant to identify the account everywhere, found by the address it
     * is labelled with so other accounts on the same user cannot satisfy it.
     *
     * More than one, deliberately: the desktop rail and the mobile drawer both
     * render the account list on every page, so there are two. Asserting on all
     * of them is the point — a change that repainted only one would be a dot
     * that disagrees with its twin.
     *
     * @return list<string>
     */
    private function sidebarDotClasses(Account $account): array
    {
        $crawler = $this->client->request('GET', '/settings?section=accounts');
        $dots    = $crawler->filter(sprintf('span[role="img"][aria-label*="%s"]', $account->email));

        self::assertGreaterThanOrEqual(1, count($dots), 'the account has a sidebar dot to read');

        return $dots->each(static fn ($dot): string => (string) $dot->attr('class'));
    }

    /** The Tailwind classes the palette paints entry $index with. */
    private function tone(int $index): string
    {
        return (new SenderAvatarExtension())->accountColor($index);
    }

    private function address(): string
    {
        return 'colour-' . bin2hex(random_bytes(4)) . '@example.test';
    }

    private function stranger(): User
    {
        $user = new User();

        $user->email     = 'stranger-' . bin2hex(random_bytes(4)) . '@joder.dev';
        $user->password  = 'not-a-real-hash';
        $user->nameFirst = 'Stran';
        $user->nameLast  = 'Ger';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function account(User $user, string $email, int $colorIndex): Account
    {
        $account = new Account();

        $account->usr        = $user;
        $account->name       = $email;
        $account->username   = $email;
        $account->email      = $email;
        $account->authType   = AuthType::Password->value;
        $account->isActive   = true;
        $account->imapHost   = 'imap.example.test';
        $account->colorIndex = $colorIndex;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function oauthAccount(User $user, string $email, int $colorIndex): Account
    {
        $account = new Account();

        $account->usr               = $user;
        $account->name              = $email;
        $account->username          = $email;
        $account->email             = $email;
        $account->authType          = AuthType::OAuth2->value;
        $account->oauthProvider     = MailProvider::Google->value;
        $account->oauthAccessToken  = 'access-token';
        $account->oauthRefreshToken = 'refresh-token';
        $account->isActive          = true;
        $account->colorIndex        = $colorIndex;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
