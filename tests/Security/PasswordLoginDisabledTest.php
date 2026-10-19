<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Oidc\OidcConfig;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Single sign-on only" is enforced by the server, not only drawn by the page.
 *
 * Hiding the form is the half anybody can see. The half that matters is that a
 * POST to /login — which needs no form — is refused too, with the right
 * password, and that it is refused the same way for an address nobody has.
 * Both are asserted against a provider saved in the database, and against the
 * same login with the setting off, so the refusal cannot be a login that was
 * simply broken.
 *
 * Skips itself without the seeded admin, exactly as SsoSettingsControllerTest does.
 */
final class PasswordLoginDisabledTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    // As bin/test-unit.sh seeds it.
    private const string ADMIN_PASSWORD = 'e2e-admin-password';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);

        if (false === $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]) instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheLoginPageOffersOnlyTheProviderWhenItIsTheOnlyWayIn(): void
    {
        $this->saveProvider(passwordLoginDisabled: true);

        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/login/oidc"]'));
        self::assertCount(0, $crawler->filter('input[name="password"]'), 'the password field is gone');
        self::assertCount(0, $crawler->filter('input[name="email"]'));
    }

    public function testThePasswordFormStaysWhileThePasswordIsAllowed(): void
    {
        $this->saveProvider(passwordLoginDisabled: false);

        $crawler = $this->client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('a[href="/login/oidc"]'));
        self::assertCount(1, $crawler->filter('input[name="password"]'));
    }

    public function testAPasswordPostIsRefusedEvenWithTheRightCredentials(): void
    {
        $this->saveProvider(passwordLoginDisabled: true);

        $this->client->followRedirects(true);
        $this->client->request('GET', '/login');
        $this->client->request('POST', '/login', [
            'email'    => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
            '_csrf_token' => 'not-needed-the-refusal-comes-first',
        ]);

        self::assertStringContainsString('Password sign-in is turned off', (string) $this->client->getResponse()->getContent());
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken()?->getUser(), 'nobody was signed in');
    }

    /**
     * The control for the test above: the same address and password, through
     * the form, sign in while the setting is off. Without it the refusal could
     * be a login that was broken for some other reason.
     */
    public function testTheSameLoginSignsInWhileThePasswordIsAllowed(): void
    {
        $this->saveProvider(passwordLoginDisabled: false);

        $form = $this->client->request('GET', '/login')->filter('form input[name="password"]')->closest('form')?->form();
        self::assertNotNull($form);

        $this->client->submit($form, ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheRefusalSaysNothingAboutWhichAddressesExist(): void
    {
        $this->saveProvider(passwordLoginDisabled: true);
        $this->client->followRedirects(true);

        $this->client->request('POST', '/login', ['email' => self::ADMIN_EMAIL, 'password' => 'x']);
        $real = $this->message((string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/login', ['email' => 'nobody-here@example.test', 'password' => 'x']);
        $unknown = $this->message((string) $this->client->getResponse()->getContent());

        self::assertNotSame('', $real);
        self::assertSame($real, $unknown);
    }

    private function message(string $html): string
    {
        return 1 === preg_match('~Password sign-in is turned off[^<]*~', $html, $m) ? $m[0] : '';
    }

    private function saveProvider(bool $passwordLoginDisabled): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $row                        = new OidcConfig();
        $row->issuer                = 'https://auth.example.com/';
        $row->clientId              = 'plmail';
        $row->clientSecret          = 'the-secret';
        $row->passwordLoginDisabled = $passwordLoginDisabled;

        $em->persist($row);
        $em->flush();
    }
}
