<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\Oidc\OidcConfigRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin → SSO saves a provider whole, keeps its secret to itself, and refuses
 * half of one.
 *
 * The secret is the part with a failure nobody would see: the field is
 * write-only, so a second save that only changes the button's name submits it
 * empty, and a form that mapped it would wipe the secret and switch sign-in
 * off for everybody with a "saved" message on screen. And a half-saved provider
 * would look configured on this page while the login page went on using the
 * environment, which is why it is refused here rather than ignored later.
 *
 * Skips itself without the seeded admin, exactly as PageRendersTest does.
 */
final class SsoSettingsControllerTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Each test here is several requests, and the browser boots a fresh
        // kernel for every one after the first unless told not to: a fresh
        // kernel is a fresh connection, outside the transaction below, and
        // what it wrote would stay in the database for every later test.
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($admin);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASavedProviderKeepsItsSecretThroughALaterEditAndNeverShowsIt(): void
    {
        $this->submit(['issuer' => 'https://auth.example.com/', 'clientId' => 'plmail', 'clientSecret' => 'the-secret', 'requireVerifiedEmail' => '1']);
        $this->submit(['issuer' => 'https://auth.example.com/', 'clientId' => 'plmail', 'clientSecret' => '', 'buttonLabel' => 'Authentik', 'requireVerifiedEmail' => '1']);

        $config = static::getContainer()->get(OidcConfigRepository::class)->current();

        self::assertNotNull($config);
        self::assertSame('the-secret', $config->clientSecret, 'an empty secret field means "keep it"');
        self::assertSame('Authentik', $config->buttonLabel);
        self::assertTrue($config->isComplete());
        self::assertStringNotContainsString('the-secret', (string) $this->client->getResponse()->getContent());
    }

    public function testHalfAProviderIsRefused(): void
    {
        $crawler = $this->submit(['issuer' => 'https://auth.example.com/', 'clientId' => '', 'clientSecret' => '']);

        self::assertNull(static::getContainer()->get(OidcConfigRepository::class)->current());
        self::assertStringContainsString('All three, or none of them.', $crawler->filter('turbo-frame#admin-sso')->text());
    }

    /**
     * @param array<string, string> $values
     */
    private function submit(array $values): \Symfony\Component\DomCrawler\Crawler
    {
        $form = $this->client->request('GET', '/admin/sso')->filter('turbo-frame#admin-sso form')->form();

        foreach ($values as $name => $value) {
            $field = $form[sprintf('oidc_config[%s]', $name)];

            if ($field instanceof \Symfony\Component\DomCrawler\Field\ChoiceFormField) {
                '1' === $value ? $field->tick() : $field->untick();

                continue;
            }

            $field->setValue($value);
        }

        return $this->client->submit($form);
    }
}
