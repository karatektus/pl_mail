<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin -> AI renders, and the settings card folds itself away once it is done.
 *
 * The fold exists because settings are a thing you FINISH. Once a host and a
 * model are on file, that card is a wall of filled-in fields standing between
 * the administrator and the two cards below it, which are the ones worth coming
 * back to.
 *
 * Rendering it at all is half the value of this file. Nothing else in the suite
 * renders `admin/ai/_frame.html.twig` — the panel test renders only the
 * telemetry partial — so the whole settings card, the form, and the `open`
 * expression that reads `form.vars.valid` had no coverage whatsoever. That
 * expression is evaluated on every visit to the page and would be a 500 on the
 * admin area, which is the kind of thing that is found in production.
 */
final class AiSettingsCardTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);

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

    /**
     * Nothing configured: open, because there is something to do in it.
     *
     * This is also the assertion that the page renders at all.
     */
    public function testAnUnconfiguredInstallationOpensTheCard(): void
    {
        $this->client->request('GET', '/admin/ai');

        self::assertResponseIsSuccessful();

        self::assertTrue(
            $this->settingsCardIsOpen(),
            'nothing is configured, so there is still something to do in this card',
        );
    }

    /** A host and both models on file: folded away, with the chip still speaking. */
    public function testAConfiguredInstallationFoldsTheCardAway(): void
    {
        $settings = $this->settings->currentOrDefault();

        $settings->baseUrl        = 'http://192.0.2.1:11434';
        $settings->chatModel      = 'a-writing-model';
        $settings->embeddingModel = 'a-search-model';

        if (null === $settings->id) {
            $this->em->persist($settings);
        }

        $this->em->flush();

        $this->client->request('GET', '/admin/ai');

        self::assertResponseIsSuccessful();

        self::assertFalse(
            $this->settingsCardIsOpen(),
            'a finished settings card should not stand in the way of the ones below it',
        );

        // The fold must not take the state with it: the status chip lives in the
        // summary, which is the half that stays on screen.
        self::assertStringContainsString('<summary', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Whether the first <details> on the page carries the `open` attribute.
     *
     * Matched against the OPENING TAG and as a whole word, which the first
     * version of this was not — and it was worthless for it. The summary's
     * chevron carries `group-open:rotate-90`, so a plain search for "open"
     * anywhere in the markup is true no matter what the card does: the
     * assertion passed, its negation could never pass, and neither of them was
     * looking at the thing under test.
     */
    private function settingsCardIsOpen(): bool
    {
        $html  = (string) $this->client->getResponse()->getContent();
        $start = strpos($html, '<details');

        self::assertNotFalse($start, 'the settings card did not render at all');

        $end = strpos($html, '>', $start);
        $tag = substr($html, $start, (int) $end - $start);

        return 1 === preg_match('/\bopen\b/', $tag);
    }
}
