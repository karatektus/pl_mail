<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Insight\InsightExtractorInterface;
use App\Service\Insight\InsightExtractorRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The insight toggles: who may flip them, what a flip stores, and that the
 * settings page really is a rendering of the registry.
 *
 * Every assertion here is written against "whatever the registry holds" rather
 * than a named extractor, because that is the feature's own promise: the page
 * lists the registry, not a hand-kept catalogue, so a test naming 'parcel'
 * would break the day the catalogue changed while proving nothing about the
 * seam. The registry is never empty in this environment —
 * ScriptedInsightExtractor is registered in services_test.yaml for exactly
 * this suite.
 *
 * The setting stores DISABLED keys (see User::SETTING_INSIGHTS_DISABLED), so
 * "toggle off persists the key" and "toggle on removes it" are the two halves
 * pinned, plus the guards: unknown keys bounce with a 400 before anything is
 * written, and the token rules follow AppearancePaneStateTest — a new
 * state-changing POST carries a token, whatever its blast radius.
 */
final class InsightSettingsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/settings/insights/toggle';

    public function testTogglingOffPersistsTheKeyInTheSetting(): void
    {
        [$client] = $this->signedIn();
        $key = $this->firstKey();

        $client->request('POST', self::PATH, [
            'key'     => $key,
            'enabled' => '0',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertContains($key, $this->disabledKeys());
    }

    public function testTogglingBackOnRemovesTheKey(): void
    {
        [$client, $user] = $this->signedIn();
        $key = $this->firstKey();

        $user->setSetting(User::SETTING_INSIGHTS_DISABLED, [$key]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::PATH, [
            'key'     => $key,
            'enabled' => '1',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotContains($key, $this->disabledKeys());
    }

    /**
     * A key the registry does not know is refused before anything is written.
     *
     * The setting is a list the harvester reads back; accepting arbitrary
     * strings would turn a preference into a free-form store, and the only
     * legitimate caller is our own settings page — an unknown key is a bug and
     * should say so.
     */
    public function testAnUnknownKeyIsA400AndWritesNothing(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, [
            'key'     => 'no-such-extractor',
            'enabled' => '0',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertNotContains('no-such-extractor', $this->disabledKeys());
    }

    public function testATokenlessPostIsRefusedAndWritesNothing(): void
    {
        [$client] = $this->signedIn();
        $key = $this->firstKey();

        $client->request('POST', self::PATH, ['key' => $key, 'enabled' => '0']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNotContains($key, $this->disabledKeys(), 'a tokenless POST flipped a toggle');
    }

    public function testAForgedTokenIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, [
            'key'     => $this->firstKey(),
            'enabled' => '0',
            '_token'  => 'nonsense',
        ]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /** Signed out, there is nothing to configure and nothing to attack. */
    public function testItIsBehindTheLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', self::PATH, ['key' => 'parcel', 'enabled' => '0']);

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 401, 403],
            'the endpoint answered an anonymous POST',
        );
    }

    /**
     * The page renders one row per registry extractor — asserted by the
     * `data-insight-extractor` attribute rather than by translated text, so
     * neither a rename in the catalogue nor a missing translation for the
     * test-only extractor can fail it.
     */
    public function testTheSettingsPageRendersOneRowPerRegistryExtractor(): void
    {
        [$client] = $this->signedIn();

        $extractors = $this->registry()->all();
        $crawler = $client->request('GET', '/settings?section=insights');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(
            count($extractors),
            $crawler->filter('[data-insight-extractor]'),
            'the settings page and the registry disagree about how many extractors exist',
        );

        foreach ($extractors as $extractor) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('[data-insight-extractor="%s"]', $extractor::key())),
                sprintf('no settings row for the "%s" extractor', $extractor::key()),
            );
        }
    }

    protected function tearDown(): void
    {
        // The toggle state is a per-user preference with no fixture of its
        // own, so put it back rather than leaving whatever the last case wrote
        // for the next suite to trip over.
        if (null !== $user = $this->find()) {
            $user->setSetting(User::SETTING_INSIGHTS_DISABLED, []);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    private function registry(): InsightExtractorRegistry
    {
        return static::getContainer()->get(InsightExtractorRegistry::class);
    }

    /** Any real key will do — the endpoint treats them all alike. */
    private function firstKey(): string
    {
        $all = $this->registry()->all();

        self::assertNotSame(
            [],
            $all,
            'the registry is empty even though ScriptedInsightExtractor is registered for the test environment',
        );

        $first = $all[0];
        \assert($first instanceof InsightExtractorInterface);

        return $first::key();
    }

    /**
     * A token minted for this session, read off the page that renders the
     * toggles — which is also the only place the real one comes from.
     */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings?section=insights');

        return (string) $crawler
            ->filter('[data-settings--insights-token-value]')
            ->last()
            ->attr('data-settings--insights-token-value');
    }

    /**
     * The disabled keys as the database now has them, not as the last request
     * left them in memory.
     *
     * @return list<string>
     */
    private function disabledKeys(): array
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $user = $this->find();

        self::assertInstanceOf(User::class, $user);

        $stored = $user->getSetting(User::SETTING_INSIGHTS_DISABLED);

        return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
    }

    /** @return array{KernelBrowser, User} */
    private function signedIn(): array
    {
        $client = static::createClient();

        $user = $this->find();

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return [$client, $user];
    }

    private function find(): ?User
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        return $user instanceof User ? $user : null;
    }
}
