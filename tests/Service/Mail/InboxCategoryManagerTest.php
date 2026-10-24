<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Exception\InvalidInboxCategoryException;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\InboxCategoryRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\InboxCategoryManager;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Making, renaming and removing the tabs a reader made.
 *
 * Two things are worth the effort here. The names, because a tab strip with two
 * tabs called the same thing, or one called "Primary" beside the real one, is a
 * strip nobody can trust. And removal, because it is the one operation that has
 * to un-file mail the database cannot un-file on its own: a foreign key can null
 * a pointer, but it cannot clear a PIN, and a pinned conversation whose
 * category has gone would be skipped by every recompute for ever — a
 * conversation stuck in a tab that no longer exists, undetectable from outside.
 */
final class InboxCategoryManagerTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private InboxCategoryManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        // Built by hand rather than fetched: a service with one consumer is
        // inlined into it when the container is compiled, and cannot be asked for.
        $this->manager    = new InboxCategoryManager(
            $container->get(InboxCategoryRepository::class),
            $container->get(MessageThreadRepository::class),
            $this->em,
            $container->get(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $container->get(\App\Jmap\State\StateManager::class),
            $container->get(\App\Service\Mail\InboxTabChanges::class),
        );

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testACategoryIsTrimmed(): void
    {
        $first = $this->manager->create($this->user, '  Shipping  ');

        self::assertNotNull($first->id);
        self::assertSame('Shipping', $first->name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'nothing'               => ['', 'settings.categories.error.empty'];
        yield 'only spaces'           => ["   \t ", 'settings.categories.error.empty'];
        yield 'forty-one characters'  => [str_repeat('x', 41), 'settings.categories.error.too_long'];
        yield 'a built-in name'       => ['Primary', 'settings.categories.error.reserved'];
        yield 'a built-in in capitals' => ['FORUMS', 'settings.categories.error.reserved'];
        // A built-in's label in any interface language, not only the reader's:
        // the German Promotions tab is "Werbung", the French Primary "Principal".
        yield 'a German label'        => ['Werbung', 'settings.categories.error.reserved'];
        yield 'a French label'        => ['principal', 'settings.categories.error.reserved'];
        yield 'a two-word label'      => ['Soziale Netzwerke', 'settings.categories.error.reserved'];
        yield 'one the reader has'    => ['shipping', 'settings.categories.error.taken'];
    }

    #[DataProvider('refusedNames')]
    public function testANameThatWouldMakeTheStripAmbiguousIsRefused(string $name, string $expectedKey): void
    {
        $this->manager->create($this->user, 'Shipping');

        try {
            $this->manager->create($this->user, $name);

            self::fail('accepted ' . json_encode($name));
        } catch (InvalidInboxCategoryException $e) {
            self::assertSame($expectedKey, $e->translationKey);
        }
    }

    /**
     * The presence companion for every refusal above: the same call with an
     * acceptable name works, on the same fixture, so "refuses everything" cannot
     * pass for "refuses what it should".
     */
    public function testNamesThatAreFineAreAccepted(): void
    {
        $this->manager->create($this->user, 'Shipping');

        self::assertSame(str_repeat('x', 40), $this->manager->create($this->user, str_repeat('x', 40))->name);

        // Another reader's tab is none of this reader's business.
        $other = $this->seedUser();
        self::assertSame('Shipping', $this->manager->create($other, 'Shipping')->name);
    }

    public function testRenamingKeepsTheCategoryAndMayChangeOnlyItsCase(): void
    {
        $shipping = $this->manager->create($this->user, 'Shipping');
        $this->manager->create($this->user, 'Receipts');

        $this->manager->rename($shipping, 'SHIPPING');
        self::assertSame('SHIPPING', $shipping->name, 'a category could not be renamed to a different case of itself');

        $this->manager->rename($shipping, 'Parcels');
        self::assertSame('Parcels', $shipping->name);

        $this->expectException(InvalidInboxCategoryException::class);
        $this->manager->rename($shipping, 'receipts');
    }

    /**
     * Fixture, derived by hand. The category C is removed; D stays.
     *
     *   thread          built-in   own tab  pinned   message verdict
     *   draggedIn       primary    C        yes      -               (pinned INTO C)
     *   filtered        promotions C        no       C               (filed by a filter)
     *   draggedElsewhere primary   -        yes      -               (pinned to a built-in tab)
     *   inOther         promotions D        yes      D               (a different category)
     *
     * After removing C:
     *   draggedIn  : own tab gone, PIN GONE (or no recompute would ever move it)
     *   filtered   : own tab gone, message verdict gone
     *   draggedElsewhere : untouched, pin kept — it was never pinned to C
     *   inOther    : untouched, pin kept
     * and no mail was deleted.
     */
    public function testRemovingACategoryUnfilesItsMailAndFreesItsPinsAndNothingElse(): void
    {
        $c = $this->manager->create($this->user, 'Shipping');
        $d = $this->manager->create($this->user, 'Receipts');

        $draggedIn         = $this->thread('dragged in', MessageCategory::Promotions, unread: 1);
        $draggedIn->category          = MessageCategory::Primary;
        $draggedIn->userCategory      = $c;
        $draggedIn->categoryPinnedAt  = new \DateTimeImmutable('2026-07-28 10:00:00');

        $filtered                     = $this->thread('filtered', MessageCategory::Promotions, unread: 1);
        $filtered->userCategory       = $c;
        $filtered->messages->first()->ruleCategory = $c;

        $draggedElsewhere             = $this->thread('dragged elsewhere', MessageCategory::Promotions, unread: 1);
        $draggedElsewhere->category   = MessageCategory::Primary;
        $draggedElsewhere->categoryPinnedAt = new \DateTimeImmutable('2026-07-28 10:00:00');

        $inOther                      = $this->thread('in other', MessageCategory::Promotions, unread: 1);
        $inOther->userCategory        = $d;
        $inOther->categoryPinnedAt    = new \DateTimeImmutable('2026-07-28 10:00:00');
        $inOther->messages->first()->ruleCategory = $d;

        $this->em->flush();

        $this->manager->remove($c);

        self::assertSame(0, $this->rows('inbox_category', 'id = ' . (int) $c->id), 'the category is still there');
        self::assertSame(1, $this->rows('inbox_category', 'id = ' . (int) $d->id), 'another category went with it');

        self::assertNull($this->threadColumn($draggedIn, 'user_category_id'));
        self::assertNull($this->threadColumn($draggedIn, 'category_pinned_at'), 'a conversation pinned into the removed category is still pinned, so no recompute will ever move it');

        self::assertNull($this->threadColumn($filtered, 'user_category_id'));
        self::assertNull($this->messageColumn($filtered, 'rule_category_id'), 'a message is still filed under a category that is gone');

        self::assertNotNull($this->threadColumn($draggedElsewhere, 'category_pinned_at'), 'a pin to a built-in tab was cleared');
        self::assertSame('primary', $this->threadColumn($draggedElsewhere, 'category'));

        self::assertSame($d->id, (int) $this->threadColumn($inOther, 'user_category_id'));
        self::assertNotNull($this->threadColumn($inOther, 'category_pinned_at'));
        self::assertSame($d->id, (int) $this->messageColumn($inOther, 'rule_category_id'));

        self::assertSame(4, $this->rows('message_thread', 'account_id = ' . (int) $this->account->id), 'removing a category removed a conversation');
        self::assertSame(4, $this->rows('message', 'account_id = ' . (int) $this->account->id), 'removing a category removed mail');
    }

    /**
     * Why the pin has to go, shown rather than argued — and that removing the
     * category is what re-resolves the conversation, with nothing else to ask.
     *
     * Dragging a conversation into a tab of the reader's own leaves its built-in
     * category at whatever it was when it was dragged. Once the pin is cleared
     * the conversation is free to follow its messages again, but nothing would
     * re-resolve it until its next message arrived: it would sit in a stale
     * built-in tab for days, in the one place the removal was meant to put it
     * right. So the removal re-resolves exactly the conversations it freed.
     */
    public function testAConversationPinnedIntoARemovedCategoryIsResolvedAgainAfterwards(): void
    {
        $c = $this->manager->create($this->user, 'Shipping');

        $thread                    = $this->thread('dragged in', MessageCategory::Promotions, unread: 1);
        $thread->messages->first()->category = MessageCategory::Promotions;
        $thread->category          = MessageCategory::Primary;
        $thread->userCategory      = $c;
        $thread->categoryPinnedAt  = new \DateTimeImmutable('2026-07-28 10:00:00');
        $this->em->flush();

        $this->manager->remove($c);

        self::assertSame(
            'promotions',
            $this->threadColumn($thread, 'category'),
            'the conversation stayed where the removed pin had put it',
        );
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function rows(string $table, string $where): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where));
    }

    private function threadColumn(MessageThread $thread, string $column): mixed
    {
        $value = $this->connection->fetchOne(sprintf('SELECT %s FROM message_thread WHERE id = :id', $column), ['id' => $thread->id]);

        return false === $value ? null : $value;
    }

    private function messageColumn(MessageThread $thread, string $column): mixed
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT %s FROM message WHERE thread_id = :id ORDER BY id LIMIT 1', $column),
            ['id' => $thread->id],
        );

        return false === $value ? null : $value;
    }
}
