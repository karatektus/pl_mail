<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Service\Rule\RuleActionExecutor;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The spam button: what its menu offers for a conversation, and what each
 * choice does.
 *
 * The move is "Move to → Spam" and BulkMoveToTest pins that. What is pinned
 * here is the part this button adds — who the sender is taken to be, the
 * filter, and that an Undo takes the filter back with the move.
 */
final class SpamButtonTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the menu ─────────────────────────────────────────────────────────────

    public function testTheMenuOffersTheMoveAndBothFilters(): void
    {
        $thread = $this->threadFrom('News@Shop-Deals.example');

        $crawler = $this->client->request('GET', sprintf('/status/thread/%d/spam-menu', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-spam-filter="none"]'));
        self::assertStringContainsString('news@shop-deals.example', $crawler->filter('[data-spam-filter="sender"]')->text());

        // The domain row is on the menu, asks first, and the button that acts
        // is inside the question.
        $domain = $crawler->filter('[data-testid="spam-filter-domain"]');

        self::assertStringContainsString('@shop-deals.example', $domain->text());
        self::assertNull($domain->attr('hidden'));
        self::assertNull($domain->attr('data-spam-filter'), 'the first click on the domain row must not be the action');
        self::assertNotNull($crawler->filter('[data-mail--spam-menu-target="confirm"]')->attr('hidden'));
        self::assertCount(1, $crawler->filter('[data-mail--spam-menu-target="confirm"] [data-spam-filter="domain"]'));
        self::assertCount(0, $crawler->filter('[data-testid="spam-more"]'));
    }

    /**
     * gmail.com is not somebody's domain, it is everybody's. The domain row is
     * there but out of sight, behind one more button.
     */
    public function testAMailProvidersDomainIsBehindMoreOptions(): void
    {
        $thread = $this->threadFrom('someone@gmail.com');

        $crawler = $this->client->request('GET', sprintf('/status/thread/%d/spam-menu', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="spam-more"]'));
        self::assertNotNull($crawler->filter('[data-testid="spam-filter-domain"]')->attr('hidden'));
        self::assertCount(1, $crawler->filter('[data-spam-filter="sender"]'));
    }

    /**
     * The newest message somebody else wrote, not the newest message: the
     * user's own reply is the last thing in the conversation and is nobody to
     * filter.
     */
    public function testTheSenderIsWhoeverElseWroteLast(): void
    {
        $thread = $this->threadFrom('first@old.example', '-3 days');

        $this->addMessage($thread, 'second@new.example', '-2 days');
        $this->addMessage($thread, (string) $this->account->email, '-1 day');

        $crawler = $this->client->request('GET', sprintf('/status/thread/%d/spam-menu', $thread->id));

        self::assertStringContainsString('second@new.example', $crawler->filter('[data-spam-filter="sender"]')->text());
    }

    public function testAConversationOfOnesOwnMailOffersOnlyTheMove(): void
    {
        $thread = $this->threadFrom((string) $this->account->email);

        $crawler = $this->client->request('GET', sprintf('/status/thread/%d/spam-menu', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-spam-filter="none"]'));
        self::assertCount(0, $crawler->filter('[data-spam-filter="sender"]'));
        self::assertCount(0, $crawler->filter('[data-spam-filter="domain"]'));
    }

    // ── the action ───────────────────────────────────────────────────────────

    public function testMovingAloneMakesNoFilter(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $this->report($thread, ['filter' => 'none', 'scope' => 'inbox']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(sprintf('action="remove" target="thread_%d"', $thread->id), $body);
        self::assertMatchesRegularExpression('#/status/undo/[a-f0-9]{32}#', $body);
        self::assertSame(['Spam'], $this->labelNames($thread));
        self::assertSame([], $this->rules());
    }

    public function testFilteringTheSenderMakesAnOrdinaryRule(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $this->report($thread, ['filter' => 'sender', 'scope' => 'inbox']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('news@shop-deals.example', (string) $this->client->getResponse()->getContent());
        self::assertSame(['Spam'], $this->labelNames($thread));

        $rules = $this->rules();

        self::assertCount(1, $rules);
        // The whole address, not `from`: a substring would also file
        // xnews@shop-deals.example, and anybody whose display name held this.
        self::assertSame(['operator' => 'AND', 'conditions' => [['fromAddress' => 'news@shop-deals.example']]], $rules[0]->conditions);
        self::assertSame([['type' => RuleActionExecutor::MARK_SPAM]], $rules[0]->actions);
        self::assertTrue($rules[0]->isEnabled);
        self::assertNull($rules[0]->account, 'spam from this sender is spam in every mailbox');
        self::assertStringContainsString('news@shop-deals.example', (string) $rules[0]->name);
    }

    public function testFilteringTheDomainMatchesOnTheDomain(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $this->report($thread, ['filter' => 'domain', 'scope' => 'inbox']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $rules = $this->rules();

        self::assertCount(1, $rules);
        self::assertSame(['operator' => 'AND', 'conditions' => [['fromDomain' => 'shop-deals.example']]], $rules[0]->conditions);
        self::assertStringContainsString('@shop-deals.example', (string) $rules[0]->name);
    }

    /**
     * What the filter matches on is read off the conversation. A body that
     * names an address of its own is not an instruction this route has.
     */
    public function testAClientCannotNameWhoIsFiltered(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $this->report($thread, [
            'filter'  => 'sender',
            'scope'   => 'inbox',
            'sender'  => 'boss@work.example',
            'address' => 'boss@work.example',
            'from'    => 'boss@work.example',
        ]);

        self::assertSame('news@shop-deals.example', $this->rules()[0]->conditions['conditions'][0]['fromAddress']);
    }

    public function testASecondPressOnTheSameSenderMakesNoSecondRule(): void
    {
        $first  = $this->threadFrom('news@shop-deals.example');
        $second = $this->threadFrom('news@shop-deals.example');

        $this->report($first, ['filter' => 'sender', 'scope' => 'inbox']);
        $this->report($second, ['filter' => 'sender', 'scope' => 'inbox']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->rules());
    }

    public function testUndoTakesTheFilterBackWithTheMove(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $this->report($thread, ['filter' => 'sender', 'scope' => 'inbox']);

        self::assertSame(1, preg_match('#/status/undo/[a-f0-9]{32}#', (string) $this->client->getResponse()->getContent(), $found));
        self::assertCount(1, $this->rules());

        $this->postTo($found[0], []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Inbox'], $this->labelNames($thread));
        self::assertSame([], $this->rules());
    }

    /**
     * A rule that was there before the press is not this press's to take
     * away: undoing the second report leaves the first one's filter standing.
     */
    public function testUndoLeavesAFilterItDidNotMake(): void
    {
        $first  = $this->threadFrom('news@shop-deals.example');
        $second = $this->threadFrom('news@shop-deals.example');

        $this->report($first, ['filter' => 'sender', 'scope' => 'inbox']);
        $this->report($second, ['filter' => 'sender', 'scope' => 'inbox']);

        self::assertSame(1, preg_match('#/status/undo/[a-f0-9]{32}#', (string) $this->client->getResponse()->getContent(), $found));

        $this->postTo($found[0], []);

        self::assertSame(['Inbox'], $this->labelNames($second));
        self::assertCount(1, $this->rules());
    }

    public function testAnUnknownFilterIsRefusedBeforeAnythingMoves(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $this->report($thread, ['filter' => 'everyone', 'scope' => 'inbox']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Inbox'], $this->labelNames($thread));
    }

    public function testAFilterWithNobodyToFilterIsRefusedBeforeAnythingMoves(): void
    {
        $thread = $this->threadFrom((string) $this->account->email);

        $this->report($thread, ['filter' => 'sender', 'scope' => 'inbox']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Inbox'], $this->labelNames($thread));
        self::assertSame([], $this->rules());
    }

    public function testSomebodyElsesConversationIsRefused(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $stranger = $this->seedUser();
        $this->client->loginUser($stranger);

        $this->client->request('GET', sprintf('/status/thread/%d/spam-menu', $thread->id));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->report($thread, ['filter' => 'sender', 'scope' => 'inbox']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Inbox'], $this->labelNames($thread));
    }

    // ── where the button is ──────────────────────────────────────────────────

    public function testTheButtonIsInTheRowTheToolbarAndTheReadingPane(): void
    {
        $thread = $this->threadFrom('news@shop-deals.example');

        $list = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $list->filter(sprintf('#thread_%d [data-controller="mail--spam-menu"]', $thread->id)));
        self::assertCount(1, $list->filter('[data-action~="click->mail--list-toolbar#spamSelected"]'));

        $pane = $this->client->request('GET', sprintf('/mail/thread/%d', $thread->id));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $pane->filter(sprintf(
            '[data-mail--spam-menu-report-url-value="/status/thread/%d/spam"][data-mail--spam-menu-closes-pane-value="true"]',
            $thread->id,
        ))->count());
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** A conversation in the Inbox whose one message is from $address. */
    private function threadFrom(string $address, string $when = 'now'): MessageThread
    {
        $thread = $this->thread('From ' . $address, lastMessageAt: $when);

        foreach ($thread->messages as $message) {
            $message->fromAddress = $address;
            $message->addLabel($this->inbox);
        }

        $this->em->flush();

        return $thread;
    }

    private function addMessage(MessageThread $thread, string $from, string $when): void
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = (string) $thread->subject;
        $message->fromAddress    = $from;
        $message->receivedAt     = new DateTimeImmutable($when);
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = $message->receivedAt;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->addLabel($this->inbox);

        $thread->addMessage($message);

        $this->em->persist($message);
        $this->em->flush();
    }

    /** @return list<MailRule> */
    private function rules(): array
    {
        $this->em->clear();

        return $this->em->getRepository(MailRule::class)->findBy(['usr' => $this->user->id], ['id' => 'ASC']);
    }

    /**
     * Read back from the database rather than off the instance held here.
     *
     * @return list<string>
     */
    private function labelNames(MessageThread $thread): array
    {
        $this->em->clear();

        $fresh = $this->em->find(MessageThread::class, $thread->id);

        self::assertNotNull($fresh);

        $names = [];

        foreach ($fresh->messages as $message) {
            foreach ($message->labels as $label) {
                $names[] = (string) $label->name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /** @param array<string, mixed> $body */
    private function report(MessageThread $thread, array $body): void
    {
        $this->postTo(sprintf('/status/thread/%d/spam', $thread->id), $body);
    }

    /** @param array<string, mixed> $body */
    private function postTo(string $url, array $body): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
