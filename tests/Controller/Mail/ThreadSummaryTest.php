<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Ai\ThreadSummariser;
use App\Service\Ai\ThreadTranscript;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the summary endpoint refuses, and what the pane costs when it does not
 * have to ask.
 *
 * THE REFUSALS ARE THE SUBJECT
 * ────────────────────────────
 * A summary is the most expensive thing plMail can be made to do — about a
 * minute of a 20.3 GiB model on a single GPU — so every way of reaching it
 * without permission is a way of spending somebody else's hardware. There are
 * four gates and each fails differently on purpose: 409 for a feature that is
 * off (the installation's switch or the person's), 403 for somebody else's
 * mail, 409 for a conversation with nothing in it to summarise, and 403 for a
 * request that cannot prove it came from our own page.
 *
 * The last assertion is the other half: a thread that already carries a fresh
 * summary must render it with NO model call at all. That is what the stored row
 * is for, and a page that quietly re-asked would spend a minute of GPU on every
 * open of every summarised conversation.
 */
final class ThreadSummaryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * 409 and not 403: nothing is forbidden, the feature is simply not switched
     * on — and the card should stop offering it rather than report an error the
     * reader can do nothing about. ComposeAssistController and
     * AiPreferencesController give the same answer for the same reason.
     */
    public function testAnInstallationWithSummariesOffAnswers409(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi(summaryEnabled: false);

        $this->post($client, $thread);

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    /** The floor under the ceiling, refused separately and on its own terms. */
    public function testAReaderWhoHasSwitchedSummariesOffAnswers409(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $this->user->aiPreferences->summaryOff = true;
        $this->em->flush();

        $this->post($client, $thread);

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    /**
     * Somebody else's conversation is 403, and the ownership check is the same
     * one MailController::thread() uses on the same subject.
     */
    public function testAnotherAccountsThreadIsRefused(): void
    {
        $client   = $this->signIn();
        $stranger = $this->seedStrangersThread();

        $this->configureAi();

        $this->post($client, $stranger);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * One message is not a conversation.
     *
     * 409 rather than 400: the request is well formed and the caller did
     * nothing wrong, there is simply nothing here worth half a minute of GPU to
     * be told. The card makes the same check before it offers the button, so
     * this is only reachable from a stale tab.
     */
    public function testAThreadOfOneMessageIsRefused(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(1);

        $this->configureAi();

        $this->post($client, $thread);

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    public function testATokenlessPostIsRefused(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $client->request('POST', sprintf('/mail/thread/%d/summary', $thread->id));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAForgedTokenIsRefused(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $client->request(
            'POST',
            sprintf('/mail/thread/%d/summary', $thread->id),
            server: ['HTTP_X_CSRF_TOKEN' => 'nonsense'],
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * The shared `ajax` token is NOT good enough here.
     *
     * ChecksCsrf's rule — "one token good for every action makes any one XSS
     * worth all of them" — is only a rule if something enforces it, and nothing
     * else would notice this endpoint quietly moving to the shared id.
     */
    public function testTheSharedAjaxTokenIsNotAcceptedForThisAction(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        $client->request(
            'POST',
            sprintf('/mail/thread/%d/summary', $thread->id),
            server: ['HTTP_X_CSRF_TOKEN' => (string) $crawler->filter('meta[name="csrf-token"]')->attr('content')],
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * A real run answers NDJSON, which is the thing a proxy or a debug toolbar
     * would otherwise try to parse as one JSON document.
     */
    public function testAPermittedRunAnswersNdjson(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $streamed = $this->post($client, $thread);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('application/x-ndjson', $client->getResponse()->headers->get('Content-Type'));
        // A stream of assertions about somebody's mail. Nothing may hold a copy.
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        // One JSON document per line, and the FIRST is the residency state:
        // asked before the generator is iterated, because the request itself is
        // what loads the model and a probe after it would report warm on
        // precisely the cold call that needed explaining.
        $frames = array_map(
            static fn (string $line): mixed => json_decode($line, true),
            array_values(array_filter(explode("\n", $streamed))),
        );

        self::assertSame('state', $frames[0]['type']);
        self::assertSame('token', $frames[1]['type']);
        self::assertSame('done', $frames[array_key_last($frames)]['type']);
        self::assertSame('A summary.', $frames[array_key_last($frames)]['text']);
    }

    /**
     * A finished run is stored, so the next open costs nothing.
     *
     * The write happens after the last token and before the `done` frame, and
     * only for a generator that actually finished — see the controller, where
     * falling off the end of an abandoned loop is the cancellation and stores
     * nothing.
     */
    public function testAFinishedRunIsStoredAgainstTheModelAndTheTranscript(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $this->post($client, $thread);

        $row = $this->connection->fetchAssociative(
            'SELECT summary, source_hash, model, prompt_hash FROM thread_summary WHERE thread_id = :id',
            ['id' => $thread->id],
        );

        self::assertIsArray($row);
        self::assertSame('A summary.', $row['summary']);
        self::assertSame('qwen3:30b', $row['model']);
        self::assertSame(
            static::getContainer()->get(ThreadSummariser::class)->promptFingerprint(),
            $row['prompt_hash'],
            'the row was filed under a fingerprint of a prompt other than the one that was sent',
        );
        self::assertSame(
            ThreadTranscript::hash(static::getContainer()->get(ThreadTranscript::class)->forThread($thread)),
            $row['source_hash'],
            'the row was filed under a hash of something other than what was sent',
        );
    }

    /**
     * THE ONE WORTH THE EFFORT. A stored, still-fresh summary renders with no
     * model call at all.
     *
     * Asserted by counting ai_call_metric rows rather than by looking at the
     * page, because the failure this guards against is invisible on the page:
     * a summary that re-asked on every open would look identical and cost a
     * minute of GPU each time.
     */
    public function testAStoredFreshSummaryRendersWithoutAskingTheModel(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $this->storeSummary($thread, 'They agreed on Thursday morning.', fresh: true);

        $before = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ai_call_metric');

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('They agreed on Thursday morning.', $crawler->filter('[data-thread-summary]')->text());
        self::assertSame(
            $before,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ai_call_metric'),
            'opening a summarised conversation asked the model again',
        );
    }

    /**
     * A summary whose conversation has moved on is SHOWN, greyed, rather than
     * hidden.
     *
     * Hiding it would throw away the half-minute somebody already waited over a
     * thread that has since gained one "thanks", and a summary of that thread
     * is still mostly true. The greying and the notice are what make the claim
     * honest.
     */
    public function testAStaleSummaryIsShownGreyedRatherThanHidden(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $this->storeSummary($thread, 'Written before the last message arrived.', fresh: false);

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-thread-summary]');

        self::assertStringContainsString('Written before the last message arrived.', $card->text());
        self::assertStringContainsString(
            'opacity-60',
            (string) $card->filter('[data-mail--thread-summary-target="output"]')->attr('class'),
        );
    }

    /**
     * A person who has the feature off sees no card AND no offer — not a dead
     * button, and not a controller holding a CSRF token for an action that
     * would be refused.
     */
    public function testTheCardIsAbsentWhenTheFeatureIsOff(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi(summaryEnabled: false);

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-thread-summary]'));
        self::assertCount(0, $crawler->filter('[data-mail--thread-summary-target="run"]'));
        self::assertCount(0, $crawler->filter('[data-controller="mail--thread-summary"]'));
    }

    /**
     * And so does a one-message thread, which the endpoint refuses anyway.
     *
     * The refusal is not a disabled button and not a sentence: there is nothing
     * on the page at all. A control that is always refused is worse than no
     * control, and `thread.summary.too_short` is still reachable for the case
     * a rendered page cannot see coming — a conversation that had two messages
     * when it was drawn and one by the time the button was pressed.
     */
    public function testTheCardIsAbsentOnAThreadOfOneMessage(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(1);

        $this->configureAi();

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-thread-summary]'));
        self::assertCount(0, $crawler->filter('[data-mail--thread-summary-target="run"]'));
        self::assertCount(0, $crawler->filter('[data-controller="mail--thread-summary"]'));
    }

    /**
     * THE STATE THE FEATURE IS IN ALMOST ALWAYS: nobody has asked yet.
     *
     * There is an offer beside the subject and NO CARD — a box headed "Summary"
     * with nothing in it claims a summary exists, and the owner's answer to
     * that is this test. What is on the page instead is an inert <template>
     * holding the card until the first click, which is why the assertion is
     * made against the rendered document rather than the source: see
     * rendered().
     */
    public function testAConversationNobodyHasSummarisedOffersTheButtonAndRendersNoCard(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $this->rendered($crawler, '@data-thread-summary'),
            'a conversation nobody has summarised rendered a summary card',
        );

        $offer = $this->rendered($crawler, '@data-mail--thread-summary-target="run"');

        self::assertCount(1, $offer, 'the offer to summarise was not on the page');
        self::assertSame('Summarise this conversation', trim($offer->text()));
        self::assertSame('click->mail--thread-summary#run', $offer->attr('data-action'));

        // The card exists as markup and only as markup, ready for #mount().
        self::assertCount(1, $crawler->filter('template[data-mail--thread-summary-target="cardTemplate"]'));
        self::assertCount(1, $crawler->filter('template [data-thread-summary]'));
    }

    /**
     * The offer sits at the right-hand end of the subject line, not in the
     * conversation below it.
     *
     * Asserted as "inside the row that holds the <h1>", because that is the
     * whole of what was asked for and the classes that place it are not: a
     * button that ended up under the insight strip would still be a button.
     */
    public function testTheOfferSitsInTheSubjectRow(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filterXPath('//div[h1]//*[@data-mail--thread-summary-target="run"]'),
            'the offer to summarise is not in the subject row',
        );
    }

    /**
     * One controller owns both halves, and that is the load-bearing part of
     * moving the button out of the card.
     *
     * The control has to survive the card not existing, so the element the
     * controller is bound to has to be an ancestor of both — Stimulus reaches
     * targets inside its own element and nowhere else. Bind it to the card and
     * the click that creates the card has nothing listening for it.
     */
    public function testOneControllerHoldsBothTheOfferAndTheCard(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="mail--thread-summary"]'));
        self::assertCount(
            1,
            $crawler->filterXPath('//*[@data-controller="mail--thread-summary"]//*[@data-mail--thread-summary-target="run"]'),
        );
        self::assertCount(
            1,
            $crawler->filterXPath('//*[@data-controller="mail--thread-summary"]//template[@data-mail--thread-summary-target="cardTemplate"]'),
        );
    }

    /**
     * A stored summary is a rendered card at first paint — no template, no
     * click, no wait — and the offer beside the subject reads as the second
     * one it is.
     */
    public function testAStoredSummaryRendersTheCardAndOffersToWriteAnother(): void
    {
        $client = $this->signIn();
        $thread = $this->seedThread(2);

        $this->configureAi();

        $this->storeSummary($thread, 'They agreed on Thursday morning.', fresh: true);

        $crawler = $client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();

        self::assertCount(1, $this->rendered($crawler, '@data-thread-summary'));
        self::assertStringContainsString(
            'They agreed on Thursday morning.',
            $this->rendered($crawler, '@data-thread-summary')->text(),
        );
        self::assertCount(0, $crawler->filter('template[data-mail--thread-summary-target="cardTemplate"]'));

        $offer = $this->rendered($crawler, '@data-mail--thread-summary-target="run"');

        self::assertSame('Summarise again', trim($offer->text()));
        self::assertSame('click->mail--thread-summary#regenerate', $offer->attr('data-action'));
    }

    // ── Scaffolding ───────────────────────────────────────────────────────

    /**
     * A POST carrying the card's own per-action token, read off the page the
     * way the real caller reads it.
     *
     * Scraped rather than minted from the token manager, which stores
     * session-backed tokens and throws before a request has been made — and
     * scraping is the honest version of what the Stimulus controller does, so a
     * change that broke the attribute fails here instead of passing against a
     * token the browser never sees.
     */
    private function post(KernelBrowser $client, MessageThread $thread): string
    {
        $token = $this->token($client);

        // A buffer of our own around the request, and it is not tidiness.
        //
        // The endpoint calls ob_flush() on every frame, which is exactly what it
        // must do — php.ini ships output_buffering = 4096, and without it a
        // short summary would arrive in one lump at the end and be
        // indistinguishable from an unstreamed endpoint. The consequence here is
        // that the frames escape the buffer HttpKernelBrowser opens to capture a
        // StreamedResponse and land in whatever buffer is outside it. So this
        // test provides that buffer and reads the frames out of it, which is the
        // only way to see what was actually written to the wire — and it is also
        // why every assertion about a body in this file reads THIS return value
        // rather than $client->getResponse()->getContent(), which is empty by
        // the same mechanism.
        ob_start();

        try {
            $client->request(
                'POST',
                sprintf('/mail/thread/%d/summary', $thread->id),
                server: ['HTTP_X_CSRF_TOKEN' => $token],
            );
        } finally {
            $streamed = (string) ob_get_clean();
        }

        return $streamed;
    }

    private function token(KernelBrowser $client): string
    {
        // A page first, because the token store is session-backed and there is
        // no session until a request has been made. The inbox rather than the
        // thread page: the card is deliberately absent on a one-message thread
        // and with the feature off, and half these cases are exactly that — a
        // per-action token does not depend on the page it was minted on.
        $client->request('GET', '/mail/inbox');

        $container = static::getContainer();
        $stack     = $container->get('request_stack');

        // The kernel pops its request when the response is sent, so the manager
        // has nowhere to find the session by the time a test asks. Pushing the
        // request the client just made puts back exactly the session the next
        // POST will carry — which is the whole point: a token minted against a
        // different session would test nothing but the token manager.
        $stack->push($client->getRequest());

        try {
            return (string) $container->get('security.csrf.token_manager')->getToken('thread_summary')->getValue();
        } finally {
            $stack->pop();
        }
    }

    /**
     * The page as a BROWSER sees it: everything that is not inside a <template>.
     *
     * DomCrawler parses with libxml, which knows nothing about <template> and
     * walks straight into one — so `filter('[data-thread-summary]')` finds a
     * card the browser never renders, never puts in the accessibility tree and
     * never returns from querySelectorAll. An assertion that there is no card
     * therefore has to say which of the two documents it means, and it means
     * the one somebody is looking at.
     *
     * Takes an XPath predicate rather than a CSS selector because the exclusion
     * is itself a predicate, and mixing the two would need two passes.
     */
    private function rendered(Crawler $crawler, string $predicate): Crawler
    {
        return $crawler->filterXPath(sprintf('//*[%s][not(ancestor::template)]', $predicate));
    }

    /**
     * A summary already stored against a thread, the way the store writes one.
     *
     * Freshness is not a column: a stored summary is fresh when its hash
     * matches the transcript the thread would send today, so a stale one is
     * written with a hash that matches nothing that could ever be sent.
     */
    private function storeSummary(MessageThread $thread, string $text, bool $fresh): void
    {
        $hash = str_repeat('0', 64);

        if (true === $fresh) {
            $hash = ThreadTranscript::hash(
                static::getContainer()->get(ThreadTranscript::class)->forThread($thread),
            );
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO thread_summary (thread_id, summary, source_hash, model, prompt_hash, created_at)
                VALUES (:id, :summary, :hash, :model, :prompt, NOW())
            SQL,
            [
                'id'      => $thread->id,
                'summary' => $text,
                'hash'    => $hash,
                'model'   => 'qwen3:30b',
                // The fingerprint of the prompt in force right now, asked of
                // the summariser rather than written out here — a literal would
                // be a second copy of the prompt assembly that goes stale the
                // first time either half of it is edited.
                'prompt'  => static::getContainer()->get(ThreadSummariser::class)->promptFingerprint(),
            ],
        );
    }

    private function configureAi(bool $summaryEnabled = true): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');
        $this->em->clear();

        $settings                 = new AiSettings();
        $settings->isEnabled      = true;
        $settings->baseUrl        = 'http://model-host.invalid:11434';
        $settings->chatModel      = 'qwen3:30b';
        $settings->summaryEnabled = $summaryEnabled;

        $this->em->persist($settings);
        $this->em->flush();

        // The user was detached by the clear() above, and the request that
        // follows reads their preferences.
        $user = $this->em->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);

        $this->user = $user;
    }

    private function seedThread(int $turns): MessageThread
    {
        return $this->threadOn($this->account, $turns);
    }

    private function seedStrangersThread(): MessageThread
    {
        $stranger = new User();
        $stranger->email     = 'stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Stranger';
        $stranger->nameLast  = 'Fixture';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $this->em->persist($stranger);

        return $this->threadOn($this->account($stranger), 2);
    }

    private function threadOn(Account $account, int $turns): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Handover';
        $thread->normalizedSubject = 'handover';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-05-01');
        $thread->unreadCount       = 0;
        $thread->messageCount      = $turns;

        $this->em->persist($thread);

        for ($index = 0; $index < $turns; ++$index) {
            $message                 = new Message();
            $message->account        = $account;
            $message->thread         = $thread;
            $message->subject        = 'Handover';
            $message->fromAddress    = sprintf('sender%d@example.test', $index);
            $message->fromName       = sprintf('Sender %d', $index);
            $message->bodyText       = sprintf('Turn number %d.', $index);
            $message->receivedAt     = (new DateTimeImmutable('2026-05-01'))->modify(sprintf('+%d minutes', $index));
            $message->sentAt         = $message->receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $message->messageId      = sprintf('<summary-%s-%d@example.test>', uniqid('', true), $index);

            $thread->addMessage($message);
            $this->em->persist($message);
        }

        $this->em->flush();

        return $thread;
    }

    private function account(User $owner): Account
    {
        $account                 = new Account();
        $account->usr            = $owner;
        $account->name           = 'Summary fixture';
        $account->email          = 'summary@example.test';
        $account->username       = 'summary-' . uniqid('', true) . '@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();

        // One kernel for the whole case: the fixtures are staged in a
        // transaction that a reboot would detach the EntityManager from.
        $client->disableReboot();

        $container = static::getContainer();

        // First, before anything has had a reason to build the real one: an
        // initialised service cannot be replaced, and this endpoint would
        // otherwise be free to reach whatever is at the configured address.
        // Every case here is about a decision taken before a model answers, so
        // one canned NDJSON reply covers all of them.
        $container->set('http_client', new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(['message' => ['content' => 'A summary.'], 'done' => false]) . "\n"
            . json_encode(['done' => true, 'eval_count' => 1]) . "\n",
        )));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'summary-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Summary';
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
