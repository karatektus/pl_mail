<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Ai\PromptRules;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Ai\MessageTranslator;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Translating a message (#713), through the real endpoint and the real page,
 * with the model host answered by a MockHttpClient.
 *
 * What is at risk, in the order it would hurt: the translation reaching
 * somebody who may not have it (a stranger's message, a switched-off
 * installation); the model being told the wrong thing (the "never translate"
 * language rule, the wrong language, the quoted history); and the page not
 * saying that what it shows is a model's translation and not the mail.
 *
 * Everything happens inside one transaction that is rolled back.
 */
final class MessageTranslationTest extends WebTestCase
{
    private const string MODEL = 'qwen3:30b';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    /** @var list<array<string, mixed>> what was sent to /api/chat */
    private array $sent = [];

    public function testAnInstallationWithTranslationOffAnswers409AndOffersNothing(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('שלום, אנא שלחו את החשבונית עד יום חמישי.');

        $this->configureAi(false);

        $this->post($client, $message);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->sent, 'nothing may reach the model');

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $message->thread?->id));

        self::assertCount(0, $crawler->filter('[data-testid="translate-message"]'));
        self::assertCount(0, $crawler->filter('[data-controller="mail--message-translation"]'));
    }

    public function testAReaderWhoHasSwitchedTranslationOffAnswers409(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('Bonjour.');

        $this->configureAi();
        $this->user->aiPreferences->translationOff = true;
        $this->em->flush();

        $this->post($client, $message);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->sent);
    }

    public function testAnotherAccountsMessageIsRefused(): void
    {
        $client = $this->signIn();

        $stranger = new User();
        $stranger->email     = 'stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Stranger';
        $stranger->nameLast  = 'Fixture';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $this->em->persist($stranger);

        $message = $this->messageOn($this->account($stranger), 'Geheim.');

        $this->configureAi();

        $this->post($client, $message);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->sent);
    }

    public function testATokenlessPostIsRefused(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('Bonjour.');

        $this->configureAi();

        $client->request('POST', sprintf('/mail/message/%d/translation', $message->id));

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->sent);
    }

    public function testAMessageWithNoTextIsRefusedBeforeTheModelIsAsked(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('');

        $this->configureAi();

        $this->post($client, $message);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame(['error' => 'empty'], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame([], $this->sent);
    }

    /**
     * The model is told what a translation is and nothing else: the translation
     * prompt WITHOUT the language rule, whose "never translate the message into
     * another language" is the opposite of the job; the reader's language named
     * in the message; and a window big enough for the answer.
     */
    public function testTheModelIsAskedForATranslationIntoTheChosenLanguage(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('שלום, אנא שלחו את החשבונית עד יום חמישי.');

        $this->configureAi();
        $this->user->aiPreferences->translateInto = 'ru';
        $this->em->flush();

        $frames = $this->frames($this->post($client, $message));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('application/x-ndjson', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        self::assertCount(1, $this->sent);
        $sent = $this->sent[0];

        self::assertSame(PromptRules::TRANSLATION, $sent['messages'][0]['content']);
        self::assertStringNotContainsString('Never translate', $sent['messages'][0]['content']);
        self::assertStringContainsString('שלום, אנא שלחו את החשבונית עד יום חמישי.', $sent['messages'][1]['content']);
        self::assertStringEndsWith('Translate it into Russian.', $sent['messages'][1]['content']);
        self::assertSame(MessageTranslator::CONTEXT_TOKENS, $sent['options']['num_ctx'] ?? null);

        $done = $frames[array_key_last($frames)];

        self::assertSame('done', $done['type']);
        self::assertSame('Пришлите счёт до четверга.', $done['text']);
        self::assertFalse($done['partial']);
        self::assertFalse($done['leavesOutQuote']);

        self::assertSame(
            ['ru', 'Пришлите счёт до четверга.', self::MODEL],
            array_values((array) $this->connection->fetchAssociative(
                'SELECT target_language, translation, model FROM message_translation WHERE message_id = ?',
                [$message->id],
            )),
        );
    }

    public function testWithoutAChoiceTheInterfaceLanguageIsTheTarget(): void
    {
        $client = $this->signIn();

        $this->user->locale = 'de';
        $message = $this->seedMessage('Hello, please send the invoice by Thursday.');

        $this->configureAi();

        $this->post($client, $message);

        self::assertStringEndsWith('Translate it into German.', $this->sent[0]['messages'][1]['content']);
        self::assertSame('de', $this->connection->fetchOne('SELECT target_language FROM message_translation WHERE message_id = ?', [$message->id]));
    }

    /**
     * A reply is sent without the earlier mail it quotes, and the answer says
     * so, because the page has to tell a reader who cannot read the original.
     */
    public function testTheQuotedHistoryOfAReplyIsLeftOutAndSaidToBe(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage(
            "Danke, Donnerstag passt.\n\nAm Di., 3. Nov. 2026 um 10:00 schrieb Anna <anna@example.test>:\n> Können wir uns am Donnerstag treffen?\n> Viele Grüße, Anna",
        );

        $this->configureAi();

        $frames = $this->frames($this->post($client, $message));

        self::assertStringContainsString('Danke, Donnerstag passt.', $this->sent[0]['messages'][1]['content']);
        self::assertStringNotContainsString('Können wir uns', $this->sent[0]['messages'][1]['content']);
        self::assertTrue($frames[array_key_last($frames)]['leavesOutQuote']);
    }

    /**
     * A stored translation opens AS the translation, under the bar that says it
     * is one, with the original one click away — and without asking the model.
     */
    public function testAStoredTranslationOpensAsTheTranslation(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('שלום, אנא שלחו את החשבונית עד יום חמישי.');

        $this->configureAi();
        $this->store($message, 'en', 'Hello, please send the invoice by Thursday.', true);

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $message->thread?->id));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->sent, 'opening a translated message asks the model nothing');

        $bar = $crawler->filter('[data-testid="translation-bar"]');

        self::assertCount(1, $bar);
        self::assertNull($bar->attr('hidden'));
        self::assertStringContainsString('This is not the original text.', $bar->text());
        self::assertStringContainsString(self::MODEL, $bar->text());
        self::assertStringContainsString('English', $bar->text());
        self::assertSame('Show original', trim($crawler->filter('[data-testid="translation-toggle"]')->text()));

        $text = $crawler->filter('[data-testid="translation-text"]');

        self::assertNull($text->attr('hidden'));
        self::assertSame('Hello, please send the invoice by Thursday.', $text->text());
        self::assertNotNull($crawler->filter('[data-mail--message-translation-target="original"]')->attr('hidden'));
        self::assertCount(1, $crawler->filter('[data-testid="translate-message"]'));
    }

    public function testAStaleTranslationIsShownMarkedWithTheOfferToTranslateAgain(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('שלום, אנא שלחו את החשבונית עד יום חמישי.');

        $this->configureAi();
        $this->store($message, 'en', 'An older translation.', false);

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $message->thread?->id));

        self::assertNull($crawler->filter('[data-mail--message-translation-target="stale"]')->attr('hidden'));
        self::assertNull($crawler->filter('[data-mail--message-translation-target="again"]')->attr('hidden'));
        self::assertSame('An older translation.', $crawler->filter('[data-testid="translation-text"]')->text());
    }

    /** Nobody has asked yet: the menu offers it, and the page shows the original. */
    public function testAnUntranslatedMessageShowsTheOriginalAndTheOffer(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('Bonjour, pouvez-vous envoyer la facture ?');

        $this->configureAi();

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $message->thread?->id));

        self::assertCount(1, $crawler->filter('[data-testid="translate-message"]'));
        self::assertNotNull($crawler->filter('[data-testid="translation-bar"]')->attr('hidden'));
        self::assertNull($crawler->filter('[data-mail--message-translation-target="original"]')->attr('hidden'));
        self::assertSame([], $this->sent);
    }

    /**
     * ThreadViewQueryBudgetTest's rule with translation switched on and stored
     * translations on the page: ten messages cost no more queries than two.
     * The person's permission, language, model and prompt are read once, and
     * the stored translations of the conversation in one query.
     */
    public function testALongTranslatedConversationCostsNoMoreQueriesThanAShortOne(): void
    {
        $client = $this->signIn();

        $short = $this->conversation(2);
        $long  = $this->conversation(10);

        $this->configureAi();

        foreach ([$short, $long] as $thread) {
            foreach ($thread->messages as $index => $message) {
                if (0 === $index % 2) {
                    $this->store($message, 'en', 'Translated.', true);
                }
            }
        }

        $shortCost = $this->queriesFor($client, sprintf('/mail/thread/%d', $short->id));
        $longCost  = $this->queriesFor($client, sprintf('/mail/thread/%d', $long->id));

        self::assertLessThanOrEqual($shortCost, $longCost, sprintf('two messages cost %d queries and ten cost %d', $shortCost, $longCost));
        self::assertCount(5, $client->getCrawler()->filter('[data-testid="translation-text"]:not([hidden])'));
    }

    /**
     * What the page remembers about the reader lives for one request. The
     * kernel stays up between these requests as a FrankenPHP worker keeps it
     * up, and a memo that outlived its request would go on showing the old
     * language, or the menu entry after the switch was turned off (#716 was
     * that bug in another service).
     */
    public function testWhatThePageRemembersAboutTheReaderDiesWithTheRequest(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage('Bonjour, pouvez-vous envoyer la facture ?');

        $this->configureAi();
        $this->store($message, 'en', 'Hello, can you send the invoice?', true);

        $url = sprintf('/mail/thread/%d', $message->thread?->id);

        self::assertSame('Hello, can you send the invoice?', $client->request('GET', $url)->filter('[data-testid="translation-text"]')->text());

        // Another language, through the real form: nothing is stored for it.
        $client->submit($client->request('GET', '/settings/ai')->filter('form[data-ai-translate-into]')->form(['language' => 'de']));

        $crawler = $client->request('GET', $url);

        self::assertNotNull($crawler->filter('[data-testid="translation-bar"]')->attr('hidden'));
        self::assertStringContainsString('German', (string) $crawler->filter('[data-controller="mail--message-translation"]')->attr('data-mail--message-translation-generated-label-value'));

        // Switched off by the reader, through the real switch.
        $token = (string) $client->request('GET', '/settings/ai')->filter('[data-settings--toggle-token-value]')->attr('data-settings--toggle-token-value');

        $client->request('POST', '/settings/ai/toggle', ['_token' => $token, 'key' => 'translation', 'enabled' => '0']);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', $url);

        self::assertCount(0, $crawler->filter('[data-testid="translate-message"]'));
        self::assertCount(0, $crawler->filter('[data-controller="mail--message-translation"]'));
    }

    public function testTheReaderChoosesTheLanguageInSettings(): void
    {
        $client = $this->signIn();

        $this->configureAi();

        $crawler = $client->request('GET', '/settings/ai');
        $form    = $crawler->filter('form[data-ai-translate-into]');

        self::assertCount(1, $form);

        $client->submit($form->form(['language' => 'he']));
        self::assertResponseRedirects();
        self::assertSame('he', $this->reloadedUser()->aiPreferences->translateInto);

        $client->submit($client->request('GET', '/settings/ai')->filter('form[data-ai-translate-into]')->form(['language' => '']));
        self::assertNull($this->reloadedUser()->aiPreferences->translateInto, 'empty follows the interface language again');
    }

    public function testALanguageOutsideTheListIsRefused(): void
    {
        $client = $this->signIn();

        $this->configureAi();

        $crawler = $client->request('GET', '/settings/ai');
        $token   = $crawler->filter('form[data-ai-translate-into] input[name="_token"]')->attr('value');

        $client->request('POST', '/settings/ai/translate-into', ['_token' => $token, 'language' => 'klingon']);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNull($this->reloadedUser()->aiPreferences->translateInto);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function frames(string $streamed): array
    {
        return array_map(
            static fn (string $line): array => (array) json_decode($line, true),
            array_values(array_filter(explode("\n", $streamed))),
        );
    }

    /** The streamed body, wherever this http-kernel version leaves it; see ThreadSummaryTest::post(). */
    private function post(KernelBrowser $client, Message $message): string
    {
        $token = $this->token($client);

        ob_start();

        try {
            $client->request(
                'POST',
                sprintf('/mail/message/%d/translation', $message->id),
                server: ['HTTP_X_CSRF_TOKEN' => $token],
            );
        } finally {
            $escaped = (string) ob_get_clean();
        }

        return '' !== $escaped ? $escaped : (string) $client->getInternalResponse()->getContent();
    }

    private function token(KernelBrowser $client): string
    {
        $client->request('GET', '/mail/inbox');

        $container = static::getContainer();
        $stack     = $container->get('request_stack');

        $stack->push($client->getRequest());

        try {
            return (string) $container->get('security.csrf.token_manager')->getToken('message_translation')->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function store(Message $message, string $language, string $text, bool $fresh): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO message_translation (message_id, target_language, translation, source_hash, model, prompt_hash, created_at)
                VALUES (:id, :language, :text, :hash, :model, :prompt, NOW())
            SQL,
            [
                'id'       => $message->id,
                'language' => $language,
                'text'     => $text,
                'hash'     => true === $fresh ? MessageTranslator::sourceOf($message)->hash() : str_repeat('0', 64),
                'model'    => self::MODEL,
                'prompt'   => static::getContainer()->get(MessageTranslator::class)->promptFingerprint(),
            ],
        );
    }

    /** The second of two identical requests, as ThreadViewQueryBudgetTest counts. */
    private function queriesFor(KernelBrowser $client, string $uri): int
    {
        $client->request('GET', $uri, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        $this->em->clear();

        $client->enableProfiler();
        $client->request('GET', $uri, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotFalse($profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function conversation(int $length): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = sprintf('Translated %d', $length);
        $thread->normalizedSubject = mb_strtolower($thread->subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->messageCount      = $length;
        $this->em->persist($thread);

        for ($index = 0; $index < $length; ++$index) {
            $message                 = new Message();
            $message->account        = $this->account;
            $message->thread         = $thread;
            $message->subject        = $thread->subject;
            $message->fromAddress    = sprintf('sender%d@example.test', $index);
            $message->fromName       = sprintf('Sender %d', $index);
            $message->bodyText       = sprintf('Bonjour, message %d.', $index);
            $message->receivedAt     = new DateTimeImmutable(sprintf('-%d minutes', $length - $index));
            $message->seenAt         = $message->receivedAt;
            $message->sentAt         = $message->receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $message->messageId      = sprintf('<translated-%s-%d@example.test>', uniqid('', true), $index);

            $thread->addMessage($message);
            $this->em->persist($message);
        }

        $this->em->flush();

        return $thread;
    }

    private function reloadedUser(): User
    {
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function configureAi(bool $translationEnabled = true): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');
        $this->em->clear();

        $settings                     = new AiSettings();
        $settings->isEnabled          = true;
        $settings->baseUrl            = 'http://model-host.invalid:11434';
        $settings->chatModel          = self::MODEL;
        $settings->translationEnabled = $translationEnabled;

        $this->em->persist($settings);
        $this->em->flush();

        $user = $this->em->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);

        $this->user = $user;
    }

    private function seedMessage(string $body): Message
    {
        return $this->messageOn($this->account, $body);
    }

    private function messageOn(Account $account, string $body): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Invoice';
        $thread->normalizedSubject = 'invoice';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-05-01');
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = 'Invoice';
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Sender';
        $message->bodyText       = $body;
        $message->receivedAt     = new DateTimeImmutable('2026-05-01');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->messageId      = sprintf('<translation-%s@example.test>', uniqid('', true));

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function account(User $owner): Account
    {
        $account                 = new Account();
        $account->usr            = $owner;
        $account->name           = 'Translation fixture';
        $account->email          = 'translation@example.test';
        $account->username       = 'translation-' . uniqid('', true) . '@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->mailEnabled    = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();

        $client->disableReboot();

        $container = static::getContainer();

        $container->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (false === str_ends_with($url, '/api/chat')) {
                return new MockResponse('{"models":[]}');
            }

            $this->sent[] = (array) json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse(
                json_encode(['message' => ['content' => 'Пришлите счёт '], 'done' => false], JSON_UNESCAPED_UNICODE) . "\n"
                . json_encode(['message' => ['content' => 'до четверга.'], 'done' => false], JSON_UNESCAPED_UNICODE) . "\n"
                . json_encode(['done' => true, 'eval_count' => 2]) . "\n",
            );
        }));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'translation-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Translation';
        $this->user->nameLast  = 'Fixture';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();

        $client->loginUser($this->user);

        $this->account = $this->account($this->user);

        return $client;
    }
}
