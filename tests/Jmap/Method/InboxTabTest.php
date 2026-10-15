<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Jmap\Method\Mail\EmailQueryMethod;
use App\Jmap\Method\Mail\InboxTabChangesMethod;
use App\Jmap\Method\Mail\InboxTabGetMethod;
use App\Jmap\Method\Mail\ThreadGetMethod;
use App\Jmap\Method\Mail\ThreadSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Session\SessionBuilder;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Service\Mail\InboxTabChanges;
use App\Tests\Jmap\JmapTestCase;

/**
 * The reader's inbox tabs over JMAP — what a phone needs to draw the strip the
 * browser draws, and to move a conversation between tabs.
 *
 * The claim throughout is agreement with the web: the same tabs in the same
 * order, the same conversation in the same tab, the same list under the same
 * key. Each test sets up something the web would show one way and asserts the
 * phone is told the same.
 */
final class InboxTabTest extends JmapTestCase
{
    private InboxCategory $shipping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipping       = new InboxCategory();
        $this->shipping->usr  = $this->user;
        $this->shipping->name = 'Shipping';
        $this->em->persist($this->shipping);
        $this->em->flush();
    }

    public function testTheSessionAdvertisesTheCapabilityOnEveryAccount(): void
    {
        $second  = $this->secondAccount();
        $session = self::getContainer()->get(SessionBuilder::class)->build($this->user);

        self::assertArrayHasKey('urn:plmail:params:jmap:inboxtabs', $session['capabilities']);
        self::assertArrayHasKey('urn:plmail:params:jmap:inboxtabs', $session['accounts'][$this->accountId()]['accountCapabilities']);
        self::assertArrayHasKey('urn:plmail:params:jmap:inboxtabs', $session['accounts'][(string) $second->id]['accountCapabilities']);
    }

    /**
     * Primary first, then the reader's order; a switched-off built-in listed
     * and marked, so a settings screen can switch it back on; built-ins
     * unnamed, for the client to name in its own language.
     */
    public function testTheTabsComeInTheReadersOrderWithTheirSwitches(): void
    {
        $this->user->categorySorting->arrange([$this->shipping->tabKey(), 'updates']);
        $this->user->categorySorting->switchOff([MessageCategory::Forums]);
        $this->em->flush();

        $result = $this->get();

        self::assertSame(
            ['primary', $this->shipping->tabKey(), 'updates', 'social', 'promotions', 'forums'],
            array_column($result['list'], 'id'),
        );
        self::assertSame([0, 1, 2, 3, 4, 5], array_column($result['list'], 'sortOrder'));

        $byId = array_column($result['list'], null, 'id');

        self::assertSame(['id' => $this->shipping->tabKey(), 'name' => 'Shipping', 'builtIn' => null, 'isEnabled' => true, 'sortOrder' => 1], $byId[$this->shipping->tabKey()]);
        self::assertSame(['id' => 'forums', 'name' => null, 'builtIn' => 'forums', 'isEnabled' => false, 'sortOrder' => 5], $byId['forums']);
    }

    public function testEveryAccountAnswersWithTheSameTabs(): void
    {
        $second = $this->secondAccount();

        self::assertSame(
            array_column($this->get()['list'], 'id'),
            array_column($this->get((string) $second->id)['list'], 'id'),
        );
    }

    public function testAskingForIdsAndPropertiesNarrowsTheAnswer(): void
    {
        $result = $this->get(ids: ['social', 'c999999'], properties: ['name']);

        self::assertSame([['id' => 'social', 'name' => null]], $result['list']);
        self::assertSame(['c999999'], $result['notFound']);
    }

    /**
     * A change to the tabs is recorded in every account's log, so a phone
     * watching any of them is told and InboxTab/changes says what moved.
     */
    public function testAChangeIsReportedThroughEveryAccountsChanges(): void
    {
        $second = $this->secondAccount();
        $before = $this->get()['state'];
        $beforeSecond = $this->get((string) $second->id)['state'];

        self::getContainer()->get(InboxTabChanges::class)->updated($this->user, ['forums']);
        $this->em->flush();

        foreach ([[$this->accountId(), $before], [(string) $second->id, $beforeSecond]] as [$accountId, $since]) {
            $changes = self::getContainer()->get(InboxTabChangesMethod::class)->handle(
                ['accountId' => $accountId, 'sinceState' => $since],
                $this->context(),
            );

            self::assertSame(['forums'], $changes['updated']);
            self::assertNotSame($since, $changes['newState']);
        }
    }

    /**
     * Thread.inboxTab is where the web shows the conversation: the reader's
     * own tab when filed in one, Primary for a category switched off, the
     * category otherwise. Thread.category stays the built-in, for clients that
     * know only the five.
     */
    public function testThreadInboxTabIsWhereTheWebShowsTheConversation(): void
    {
        $filed   = $this->inThread(MessageCategory::Promotions, $this->shipping);
        $off     = $this->inThread(MessageCategory::Forums);
        $updates = $this->inThread(MessageCategory::Updates);

        $this->user->categorySorting->switchOff([MessageCategory::Forums]);
        $this->em->flush();

        $threads = array_column(
            self::getContainer()->get(ThreadGetMethod::class)->handle(
                ['accountId' => $this->accountId(), 'ids' => [(string) $filed->thread->id, (string) $off->thread->id, (string) $updates->thread->id]],
                $this->context(),
            )['list'],
            null,
            'id',
        );

        self::assertSame($this->shipping->tabKey(), $threads[(string) $filed->thread->id]['inboxTab']);
        self::assertSame('promotions', $threads[(string) $filed->thread->id]['category']);
        self::assertSame('primary', $threads[(string) $off->thread->id]['inboxTab']);
        self::assertSame('forums', $threads[(string) $off->thread->id]['category']);
        self::assertSame('updates', $threads[(string) $updates->thread->id]['inboxTab']);
    }

    /**
     * The filter lists what the web lists under the same key.
     */
    public function testThreadInboxTabFiltersLikeTheWebTabs(): void
    {
        $filed   = $this->inThread(MessageCategory::Promotions, $this->shipping);
        $off     = $this->inThread(MessageCategory::Forums);
        $primary = $this->inThread(MessageCategory::Primary);
        $promo   = $this->inThread(MessageCategory::Promotions);

        $this->user->categorySorting->switchOff([MessageCategory::Forums]);
        $this->em->flush();

        self::assertSame([(string) $filed->id], $this->query($this->shipping->tabKey()));
        self::assertEqualsCanonicalizing([(string) $off->id, (string) $primary->id], $this->query('primary'));
        self::assertSame([(string) $promo->id], $this->query('promotions'), 'a conversation filed in the reader\'s own tab is not also in its built-in one');
        self::assertSame([], $this->query('forums'), 'a switched-off tab holds nothing');
    }

    public function testAKeyThatIsNoTabIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->query('quatsch');
    }

    /**
     * Thread/set puts a conversation in a tab as a drop on the web does, own
     * tabs included, and refuses a tab the reader does not have — a
     * switched-off one, or somebody else's.
     */
    public function testThreadSetMovesAConversationIntoATab(): void
    {
        $message = $this->inThread(MessageCategory::Promotions);

        $result = $this->set((string) $message->thread->id, $this->shipping->tabKey());

        self::assertArrayHasKey((string) $message->thread->id, (array) $result['updated']);

        $this->em->refresh($message->thread);
        self::assertSame($this->shipping->id, $message->thread->userCategory?->id);
        self::assertNotNull($message->thread->categoryPinnedAt, 'a move is pinned, as a drop is');

        $result = $this->set((string) $message->thread->id, 'updates');
        self::assertArrayHasKey((string) $message->thread->id, (array) $result['updated']);

        $this->em->refresh($message->thread);
        self::assertNull($message->thread->userCategory);
        self::assertSame(MessageCategory::Updates, $message->thread->category);
    }

    public function testThreadSetRefusesATabTheReaderDoesNotHave(): void
    {
        $message = $this->inThread(MessageCategory::Promotions);

        $this->user->categorySorting->switchOff([MessageCategory::Forums]);

        $stranger = new \App\Entity\User\User();
        $stranger->email     = 'stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'S';
        $stranger->nameLast  = 'T';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $this->em->persist($stranger);

        $theirs       = new InboxCategory();
        $theirs->usr  = $stranger;
        $theirs->name = 'Theirs';
        $this->em->persist($theirs);
        $this->em->flush();

        foreach (['forums', $theirs->tabKey(), 'quatsch'] as $key) {
            $result = $this->set((string) $message->thread->id, $key);

            self::assertArrayHasKey((string) $message->thread->id, (array) $result['notUpdated'], $key . ' was accepted');
        }
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * @param list<string>|null $ids
     * @param list<string>|null $properties
     *
     * @return array<string, mixed>
     */
    private function get(?string $accountId = null, ?array $ids = null, ?array $properties = null): array
    {
        return self::getContainer()->get(InboxTabGetMethod::class)->handle(
            ['accountId' => $accountId ?? $this->accountId(), 'ids' => $ids, 'properties' => $properties],
            $this->context(),
        );
    }

    /** @return list<string> email ids */
    private function query(string $tab): array
    {
        return self::getContainer()->get(EmailQueryMethod::class)->handle(
            ['accountId' => $this->accountId(), 'filter' => ['threadInboxTab' => $tab]],
            $this->context(),
        )['ids'];
    }

    /** @return array<string, mixed> */
    private function set(string $threadId, string $tab): array
    {
        return self::getContainer()->get(ThreadSetMethod::class)->handle(
            ['accountId' => $this->accountId(), 'update' => [$threadId => ['inboxTab' => $tab]]],
            $this->context(),
        );
    }

    private function inThread(MessageCategory $category, ?InboxCategory $own = null): Message
    {
        $message = $this->receivedMessage();
        $message->category     = $category;
        $message->ruleCategory = $own;

        $message->thread->category     = $category;
        $message->thread->userCategory = $own;
        $this->em->flush();

        return $message;
    }
}
