<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Ai\EmbeddingPreset;
use App\Domain\Enum\Ai\AiProvider;
use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\User\UserRepository;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\OpenAiClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

    /**
     * The presets are on the page, carrying all three values.
     *
     * Rendered rather than reasoned about: the buttons hand their values to a
     * Stimulus controller through data attributes, so a rename on either side
     * is a button that silently does nothing when pressed. There is no error,
     * no console message and no failing request — the administrator simply
     * finds that clicking a preset fills nothing in.
     */
    public function testThePresetsOfferModelInstructionAndThresholdTogether(): void
    {
        $crawler = $this->client->request('GET', '/admin/ai');

        self::assertResponseIsSuccessful();

        $buttons = $crawler->filter('[data-action="admin--embedding-preset#apply"]');

        self::assertCount(count(EmbeddingPreset::cases()), $buttons, 'every preset is offered');

        $default = $buttons->reduce(
            static fn ($node): bool => EmbeddingPreset::Qwen3Embedding06b->value === $node->attr('data-model'),
        );

        self::assertCount(1, $default, 'the default preset is one of them');
        self::assertSame(
            EmbeddingPreset::Qwen3Embedding06b->queryInstruction(),
            $default->attr('data-instruction'),
            'the instruction travels with the model, newline and all',
        );
        self::assertSame(
            (string) EmbeddingPreset::Qwen3Embedding06b->minSimilarity(),
            $default->attr('data-similarity'),
        );
    }

    /**
     * And the two new fields are really bound to the entity.
     *
     * A form field that does not map writes nothing and reports success, which
     * on this page means an administrator choosing a preset, pressing Save,
     * seeing no error, and getting exactly the search they had before.
     */
    public function testTheInstructionAndThresholdAreSaved(): void
    {
        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();

        $form['ai_settings[embeddingModel]']         = EmbeddingPreset::Qwen3Embedding06b->value;
        $form['ai_settings[searchQueryInstruction]'] = "Instruct: find mail\nQuery: ";
        $form['ai_settings[semanticMinSimilarity]']  = '0.42';

        $this->client->submit($form);

        $this->em->clear();
        $stored = $this->settings->currentOrDefault();

        self::assertSame("Instruct: find mail\nQuery: ", $stored->searchQueryInstruction);
        self::assertSame(0.42, $stored->semanticMinSimilarity);
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
     * Test asks the address on screen, with the key on screen, and stores
     * neither.
     *
     * The whole value of the button is that it answers BEFORE anything is
     * committed to. So the request has to go to what was typed, carry what was
     * typed, and leave the row exactly as it was.
     */
    public function testTheTestButtonAsksWhatWasTypedAndSavesNothing(): void
    {
        $asked = $this->recordModelHostRequests();

        $this->storeHost('http://192.0.2.1:11434', 'stored-key');

        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();

        $form['ai_settings[baseUrl]']  = 'http://192.0.2.7:11434';
        $form['ai_settings[apiToken]'] = 'typed-key';

        $this->client->request('POST', '/admin/ai/test', $form->getPhpValues());

        self::assertResponseIsSuccessful();
        self::assertNotSame([], $asked->getArrayCopy(), 'the host was never asked');

        foreach ($asked as $url => $headers) {
            self::assertStringStartsWith('http://192.0.2.7:11434/', $url);
            self::assertStringContainsString('Bearer typed-key', $headers);
        }

        self::assertStringNotContainsString('typed-key', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $stored = $this->settings->currentOrDefault();

        self::assertSame('http://192.0.2.1:11434', $stored->baseUrl);
        self::assertSame('stored-key', $stored->apiToken);
    }

    /**
     * The stored key is not shown to an address it was not stored for.
     *
     * Testing a different address with the key box left empty is the ordinary
     * way to try a new host, and it must not be a way to post the old host's
     * credential to it.
     */
    public function testTestingAnotherAddressDoesNotTakeTheStoredKeyAlong(): void
    {
        $asked = $this->recordModelHostRequests();

        $this->storeHost('http://192.0.2.1:11434', 'stored-key');

        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();

        $form['ai_settings[baseUrl]'] = 'http://192.0.2.7:11434';

        $this->client->request('POST', '/admin/ai/test', $form->getPhpValues());

        self::assertNotSame([], $asked->getArrayCopy());

        foreach ($asked as $headers) {
            self::assertStringNotContainsString('stored-key', $headers);
        }
    }

    /**
     * The provider on screen decides how the address on screen is tested.
     *
     * Chosen and not yet saved, like the address beside it. A test that used
     * the stored provider would ask a new OpenAI-compatible endpoint for
     * Ollama's `/api/tags`, get a 404, and report a good address as a bad one.
     */
    public function testTheTestButtonSpeaksTheProviderThatWasChosen(): void
    {
        $this->client->disableReboot();

        /** @var \ArrayObject<int, string> $asked */
        $asked = new \ArrayObject();

        static::getContainer()->set(OpenAiClient::class, new OpenAiClient(
            new MockHttpClient(static function (string $method, string $url) use ($asked): MockResponse {
                $asked[] = $url;

                return new MockResponse((string) json_encode(['data' => [['id' => 'a-writing-model']]]));
            }),
            new NullLogger(),
        ));

        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();

        $form['ai_settings[provider]']       = 'openai';
        $form['ai_settings[baseUrl]']        = 'https://api.example.test/v1';
        $form['ai_settings[chatModel]']      = 'a-writing-model';
        $form['ai_settings[embeddingModel]'] = '';

        $this->client->request('POST', '/admin/ai/test', $form->getPhpValues());

        self::assertResponseIsSuccessful();
        self::assertSame(['https://api.example.test/v1/models'], $asked->getArrayCopy());
        self::assertSelectorExists('.fa-plug-circle-check', 'the model that was named is on the list');
    }

    /**
     * The controls that are about Ollama's memory are put away for the other
     * provider — rendered, so their values survive a save, and hidden.
     */
    public function testTheOllamaOnlyControlsAreHiddenForAnotherProvider(): void
    {
        // One kernel for both requests: a rebooted one opens its own database
        // connection, which cannot see what this test's transaction has written.
        $this->client->disableReboot();

        $crawler = $this->client->request('GET', '/admin/ai');

        self::assertCount(0, $crawler->filter('[data-admin--ai-connection-target="ollamaOnly"][hidden]'));

        $settings = $this->settings->currentOrDefault();
        $settings->provider = AiProvider::OpenAi;
        $settings->baseUrl  = 'https://api.example.test/v1';

        if (null === $settings->id) {
            $this->em->persist($settings);
        }

        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/ai');
        $hidden  = $crawler->filter('[data-admin--ai-connection-target="ollamaOnly"][hidden]');

        self::assertCount(1, $hidden, 'the writing model\'s keep-alive with its warm-up button');
        self::assertCount(1, $hidden->filter('#ai_settings_chatKeepAlive'), 'still in the form, so still submitted');
        self::assertCount(1, $hidden->filter('[data-controller="admin--ai-warmup"]'));

        self::assertCount(
            1,
            $crawler->filter('[data-admin--ai-connection-target="searchOllamaOnly"][hidden] #ai_settings_embeddingKeepAlive'),
            'search runs on the same host, so its keep-alive goes the same way',
        );
    }

    /**
     * The search model's keep-alive follows the host search runs on.
     *
     * Writing from an OpenAI-compatible endpoint and search from an Ollama at
     * home is the arrangement the second host exists for — and there the
     * search keep-alive is the one Ollama setting that still means something.
     * Hidden along with the writing one, it could not be set at all.
     */
    public function testTheSearchKeepAliveFollowsTheSearchHost(): void
    {
        $settings = $this->settings->currentOrDefault();
        $settings->provider               = AiProvider::OpenAi;
        $settings->baseUrl                = 'https://api.example.test/v1';
        $settings->embeddingOwnConnection = true;
        $settings->embeddingProvider      = AiProvider::Ollama;
        $settings->embeddingBaseUrl       = 'http://192.0.2.1:11434';

        if (null === $settings->id) {
            $this->em->persist($settings);
        }

        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/ai');

        self::assertCount(1, $crawler->filter('[data-admin--ai-connection-target="ollamaOnly"][hidden]'));
        self::assertCount(0, $crawler->filter('[data-admin--ai-connection-target="searchOllamaOnly"][hidden]'));
        self::assertCount(0, $crawler->filter('[data-admin--ai-connection-target="searchOwn"][hidden]'), 'the second host is in use, so its fields are shown');
    }

    /** Off, which is nearly always: the second host's fields are put away. */
    public function testTheSecondHostsFieldsAreHiddenUntilItIsSwitchedOn(): void
    {
        $settings = $this->settings->currentOrDefault();
        $settings->embeddingOwnConnection = false;

        if (null === $settings->id) {
            $this->em->persist($settings);
        }

        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/ai');

        self::assertCount(1, $crawler->filter('[data-admin--ai-connection-target="searchOwn"][hidden] #ai_settings_embeddingBaseUrl'));
    }

    /**
     * With two hosts, Test asks both — each for the model that lives on it.
     *
     * Asked for both models, the writing host would be reported as missing the
     * search model it was never meant to hold, and an administrator would go
     * and pull an embedding model onto the wrong machine.
     */
    public function testTheTestButtonAsksEachHostForItsOwnModel(): void
    {
        $this->client->disableReboot();

        /** @var \ArrayObject<int, string> $asked */
        $asked = new \ArrayObject();

        static::getContainer()->set(OpenAiClient::class, new OpenAiClient(
            new MockHttpClient(static function (string $method, string $url) use ($asked): MockResponse {
                $asked[] = $url;

                return new MockResponse((string) json_encode(['data' => [['id' => 'a-writing-model']]]));
            }),
            new NullLogger(),
        ));

        static::getContainer()->set(OllamaClient::class, new OllamaClient(
            new MockHttpClient(static function (string $method, string $url) use ($asked): MockResponse {
                $asked[] = $url;

                return new MockResponse((string) json_encode(['models' => [['name' => 'a-search-model:latest']], 'version' => '0.6.2']));
            }),
            new NullLogger(),
        ));

        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();

        $form['ai_settings[provider]']          = 'openai';
        $form['ai_settings[baseUrl]']           = 'https://api.example.test/v1';
        $form['ai_settings[chatModel]']         = 'a-writing-model';
        $form['ai_settings[embeddingModel]']    = 'a-search-model';
        $form['ai_settings[embeddingProvider]'] = 'ollama';
        $form['ai_settings[embeddingBaseUrl]']  = 'http://192.0.2.1:11434';

        // Ticked in the values rather than on the field: an unticked box is
        // left out of a submission altogether, so "ticked" is the key existing.
        $values = $form->getPhpValues();
        $values['ai_settings']['embeddingOwnConnection'] = '1';

        $crawler = $this->client->request('POST', '/admin/ai/test', $values);

        self::assertResponseIsSuccessful();
        self::assertContains('https://api.example.test/v1/models', $asked->getArrayCopy());
        self::assertContains('http://192.0.2.1:11434/api/tags', $asked->getArrayCopy());

        self::assertCount(2, $crawler->filter('.fa-plug-circle-check'), 'each host holds the one model asked of it');
        self::assertCount(0, $crawler->filter('.fa-triangle-exclamation'), 'and neither is blamed for the other\'s');

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Search host', $html, 'with two results, each says which host it is about');
    }

    /**
     * The page says which extra headers are on file, and shows no value.
     *
     * Rendered rather than read off the form, because the leak this guards is a
     * template one: a value that reached the page through a help text, a
     * placeholder or the re-rendered field would not show in any form test.
     */
    public function testStoredHeadersAreNamedOnThePageAndTheirValuesAreNot(): void
    {
        $settings = $this->settings->currentOrDefault();
        $settings->baseUrl = 'http://192.0.2.1:11434';
        $settings->headers = '{"X-Gateway-Key":"the-secret-value"}';

        if (null === $settings->id) {
            $this->em->persist($settings);
        }

        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/ai');
        $html    = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('X-Gateway-Key', $html);
        self::assertStringNotContainsString('the-secret-value', $html);
        self::assertSame('', $crawler->filter('#ai_settings_headers')->text());
        self::assertCount(1, $crawler->filter('#ai_settings_clearHeaders'), 'and there is a way to remove them');
    }

    /** With nothing stored there is nothing to remove, and no box offering to. */
    public function testWithNoStoredHeadersThereIsNoRemoveBox(): void
    {
        $settings = $this->settings->currentOrDefault();
        $settings->headers = null;

        if (null === $settings->id) {
            $this->em->persist($settings);
        }

        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/ai');

        self::assertCount(1, $crawler->filter('#ai_settings_headers'));
        self::assertCount(0, $crawler->filter('#ai_settings_clearHeaders'));
    }

    /** Without a valid token this action sends nothing anywhere. */
    public function testATestWithoutAValidCsrfTokenAsksNobody(): void
    {
        $asked = $this->recordModelHostRequests();

        $crawler = $this->client->request('GET', '/admin/ai');
        $values  = $crawler->filter('form[name="ai_settings"]')->form()->getPhpValues();

        $values['ai_settings']['baseUrl'] = 'http://192.0.2.7:11434';
        $values['ai_settings']['_token']  = 'not-the-token';

        $this->client->request('POST', '/admin/ai/test', $values);

        self::assertSame([], $asked->getArrayCopy());
    }

    /**
     * A model host that answers, and a record of what it was asked with.
     *
     * The kernel is kept across requests so the replaced client survives from
     * the GET that renders the form to the POST that tests it.
     *
     * @return \ArrayObject<string, string> request headers by URL
     */
    private function recordModelHostRequests(): \ArrayObject
    {
        $this->client->disableReboot();

        /** @var \ArrayObject<string, string> $asked */
        $asked = new \ArrayObject();

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($asked): MockResponse {
            $asked[$url] = implode("\n", $options['headers'] ?? []);

            return new MockResponse((string) json_encode(['models' => [], 'version' => '0.0.0']));
        });

        static::getContainer()->set(OllamaClient::class, new OllamaClient($http, new NullLogger()));

        return $asked;
    }

    private function storeHost(string $address, string $key): void
    {
        $settings = $this->settings->currentOrDefault();

        $settings->baseUrl  = $address;
        $settings->apiToken = $key;

        if (null === $settings->id) {
            $this->em->persist($settings);
        }

        $this->em->flush();
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
