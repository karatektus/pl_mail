<?php

declare(strict_types=1);

namespace App\Tests\Controller\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A connection with a name the user already has for that service (#216).
 *
 * `(usr, provider, name)` is unique, and both ways of connecting start from the
 * provider's own name ("Nextcloud"). Saving one twice went straight to the
 * INSERT and came back as a 500:
 *
 *   - the setup wizard built a new connection every time its step was saved,
 *     so going back to it and saving again collided with the first;
 *   - the settings page allows a second connection to the same service (home
 *     and work), but one that kept the default name collided the same way.
 *
 * The wizard now edits the existing connection; the settings page says the
 * name is taken, on the name field.
 *
 * The server address is a closed port on purpose: every save probes the
 * service, and a refused connection is recorded on the entity, not thrown.
 */
final class IntegrationNameTakenTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string NOWHERE     = 'http://127.0.0.1:9';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user     = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->em->getConnection()->beginTransaction();

        // Whatever the e2e suite left behind: one connectable Nextcloud, and
        // no connections of this user's.
        $this->em->getConnection()->executeStatement("DELETE FROM integration_provider_config WHERE provider = 'nextcloud'");
        $this->em->getConnection()->executeStatement('DELETE FROM integration WHERE usr_id = :u', ['u' => $user->id]);

        $config            = new IntegrationProviderConfig(Provider::Nextcloud);
        $config->isEnabled = true;
        $this->em->persist($config);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testASecondConnectionWithTheSameNameIsAFieldErrorNotA500(): void
    {
        $this->connection('Nextcloud', 'home');

        $this->submit('/settings/integrations/connect/nextcloud', ['username' => 'work', 'secret' => 'pw']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'You already have a connection to this service called "Nextcloud"',
            $this->client->getCrawler()->filter('form[name="integration_connect"]')->text(),
        );
        self::assertSame(['Nextcloud' => 'home'], $this->stored());
    }

    /** Two of the same service is allowed; only the name has to differ. */
    public function testASecondConnectionWithItsOwnNameIsSaved(): void
    {
        $this->connection('Nextcloud', 'home');

        $this->submit('/settings/integrations/connect/nextcloud', ['name' => 'Work', 'username' => 'work', 'secret' => 'pw']);

        self::assertResponseIsSuccessful();
        self::assertSame(['Nextcloud' => 'home', 'Work' => 'work'], $this->stored());
    }

    public function testSavingTheWizardStepAgainEditsTheConnection(): void
    {
        $this->connection('Nextcloud', 'old');

        $crawler = $this->client->request('GET', '/onboarding/integrations?connect=nextcloud');
        self::assertSame('old', $this->field($crawler, 'username'), 'the step opens on what is connected');

        $this->submit('/onboarding/integrations?connect=nextcloud', ['username' => 'new']);

        self::assertResponseRedirects();
        self::assertSame(['Nextcloud' => 'new'], $this->stored());
    }

    /** Home and work, both added in settings: the wizard edits the older one, every time. */
    public function testTheWizardEditsTheOldestOfTwo(): void
    {
        $this->connection('Nextcloud', 'home');
        $this->connection('Work', 'work');

        $crawler = $this->client->request('GET', '/onboarding/integrations?connect=nextcloud');

        self::assertSame('Nextcloud', $this->field($crawler, 'name'));
        self::assertSame('home', $this->field($crawler, 'username'));
    }

    private function connection(string $name, string $username): void
    {
        $integration           = new Integration($this->user, Provider::Nextcloud, $name);
        $integration->baseUrl  = self::NOWHERE;
        $integration->username = $username;
        $integration->secret   = 'stored-secret';

        $this->em->persist($integration);
        $this->em->flush();
    }

    /**
     * Opens the form at $url, fills in the server address and $fields, submits.
     *
     * @param array<string, string> $fields field name inside `integration_connect` => value
     */
    private function submit(string $url, array $fields): void
    {
        $form = $this->client->request('GET', $url)->filter('form[name="integration_connect"]')->form();

        $form['integration_connect[baseUrl]'] = self::NOWHERE;

        foreach ($fields as $field => $value) {
            $form['integration_connect[' . $field . ']'] = $value;
        }

        $this->client->submit($form);
    }

    private function field(Crawler $crawler, string $field): string
    {
        return (string) $crawler->filter('[name="integration_connect[' . $field . ']"]')->attr('value');
    }

    /** @return array<string, string> name => username, for this user's Nextclouds */
    private function stored(): array
    {
        /** @var array<string, string> */
        return $this->em->getConnection()->fetchAllKeyValue(
            "SELECT name, username FROM integration WHERE usr_id = :u AND provider = 'nextcloud' ORDER BY name",
            ['u' => $this->user->id],
        );
    }
}
