<?php

declare(strict_types=1);

namespace App\Tests\Controller\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Connecting an OAuth service from Settings → Integrations (#681).
 *
 * Every Connect chip used to open the dialog, which loads its URL into a Turbo
 * frame — a fetch. For an OAuth service that URL redirects to the provider's
 * consent screen, `connect-src 'self'` refused the cross-origin hop, and the
 * dialog sat on its spinner with "Failed to fetch" in the console. An OAuth
 * chip is now a plain link with Turbo off, so the browser navigates; services
 * with a form keep the dialog.
 */
final class OAuthConnectIsANavigationTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user     = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->em->getConnection()->beginTransaction();
        $this->em->getConnection()->executeStatement(
            "DELETE FROM integration_provider_config WHERE provider IN ('dropbox', 'nextcloud')",
        );
        $this->em->getConnection()->executeStatement('DELETE FROM integration WHERE usr_id = :u', ['u' => $user->id]);

        $dropbox               = new IntegrationProviderConfig(Provider::Dropbox);
        $dropbox->isEnabled    = true;
        $dropbox->clientId     = 'client-id';
        $dropbox->clientSecret = 'client-secret';
        $this->em->persist($dropbox);

        $nextcloud            = new IntegrationProviderConfig(Provider::Nextcloud);
        $nextcloud->isEnabled = true;
        $this->em->persist($nextcloud);

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

    public function testAnOAuthServiceIsAPlainLinkToTheConsentRedirect(): void
    {
        $crawler = $this->client->request('GET', '/settings/integrations/list');

        self::assertResponseIsSuccessful();

        $link = $crawler->filter('a[href="/integrations/oauth/dropbox/connect"]');

        self::assertCount(1, $link, 'Dropbox is offered as a link to its OAuth start');
        self::assertSame('false', $link->attr('data-turbo'), 'a Turbo visit is a fetch, which the CSP refuses');
        self::assertCount(
            0,
            $crawler->filter('[data-ui--modal-src-value$="/connect/dropbox"]'),
            'no dialog for a consent screen',
        );
    }

    public function testAServiceWithAFormStillOpensTheDialog(): void
    {
        $crawler = $this->client->request('GET', '/settings/integrations/list');

        self::assertCount(1, $crawler->filter('button[data-ui--modal-src-value="/settings/integrations/connect/nextcloud"]'));
    }
}
