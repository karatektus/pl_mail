<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\MessageThread;
use App\Repository\Label\Label\LabelRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\InboxCategory\InboxCategoryRepository;
use App\Repository\Mail\Message\MessageListRepository;
use App\Repository\Mail\MessageThread\MessageThreadRepository;
use App\Repository\Mail\MessageThread\MessageThreadSearchRepository;
use App\Service\Mail\AllMailView;
use App\Service\Mail\InboxTabs;
use App\Service\Mail\ListViewResolver;
use App\Service\Mail\UnifiedInboxes;
use App\Service\Search\SearchQueryParser;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Select all N" on a search acts on the search's results (#711).
 *
 * It acted on nothing. The search page posted the layout's default scope, `*`,
 * which is not a view this resolves, so the worker drained no conversations
 * and the job finished as Done: "Started moving to bin — this runs in the
 * background", and then nothing, with no error and no log line. Trash, archive,
 * mark read and label all did the same, on every provider.
 *
 * The scope is `search` now and the value is the query as typed. What is
 * pinned here is that it resolves to the conversations the page listed — the
 * same words, the same bin and spam rules, the body pass where the page ran
 * it — and to nothing for a query that is no query at all.
 *
 * Against a real database, because the answer is a full-text match over a
 * generated tsvector and nothing short of Postgres computes it.
 */
final class ListViewResolverSearchTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private ListViewResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Built by hand: a service with one consumer is inlined away.
        $this->resolver = new ListViewResolver(
            $container->get(MessageThreadRepository::class),
            $container->get(MessageListRepository::class),
            $container->get(LabelRepository::class),
            new InboxTabs($container->get(InboxCategoryRepository::class)),
            new AllMailView($container->get(AccountRepository::class)),
            $container->get(UnifiedInboxes::class),
            $container->get(SearchQueryParser::class),
            $container->get(MessageThreadSearchRepository::class),
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

    /** The bug itself: a search resolved to no conversations at all. */
    public function testASearchResolvesToItsResultsRatherThanToNothing(): void
    {
        $this->thread('Invoice for March');
        $this->thread('Invoice for April');
        $this->thread('Lunch on Thursday');

        self::assertSame(
            ['Invoice for April', 'Invoice for March'],
            $this->subjects($this->resolver->threadsIn($this->user, 'search', 'invoice', false)),
        );
    }

    /**
     * Operators come through as well as words: the value is the whole query,
     * re-parsed, not only its free text.
     */
    public function testTheQueryIsReadWithItsOperators(): void
    {
        $this->thread('Invoice from billing', fromName: 'Acme Billing');
        $this->thread('Invoice from a stranger', fromName: 'Somebody Else');

        self::assertSame(
            ['Invoice from billing'],
            $this->subjects($this->resolver->threadsIn($this->user, 'search', 'invoice from:acme', false)),
        );
    }

    /**
     * A query that is all half-typed operator shows an empty page, and must
     * select an empty set — not, as an operator-less search would, the whole
     * mailbox.
     */
    #[DataProvider('noQuery')]
    public function testAQueryThatIsNoQuerySelectsNothing(string $value): void
    {
        $this->thread('Anything at all');

        self::assertSame([], $this->resolver->threadsIn($this->user, 'search', $value, false));
    }

    /** @return iterable<string, array{string}> */
    public static function noQuery(): iterable
    {
        yield 'nothing typed' => [''];
        yield 'only whitespace' => ['   '];
        yield 'a bare operator' => ['from:'];
    }

    /**
     * The bin is out of a search unless it says `in:trash`, and so it is out
     * of "select all" on one: "move to bin" over a search must not reach mail
     * that is already there and was never listed.
     */
    public function testABinnedConversationIsNotSelectedUnlessTheSearchAsksForIt(): void
    {
        $trash = $this->seedLabel('Trash', LabelRole::Trash);

        $this->thread('Invoice still live');
        $this->binThread($this->thread('Invoice already binned'), $trash);

        self::assertSame(
            ['Invoice still live'],
            $this->subjects($this->resolver->threadsIn($this->user, 'search', 'invoice', false)),
        );
        self::assertSame(
            ['Invoice already binned'],
            $this->subjects($this->resolver->threadsIn($this->user, 'search', 'invoice in:trash', false)),
        );
    }

    /**
     * Where the page found something only by reading bodies, so does "select
     * all": a search whose words came back thin runs the body pass, and the
     * set acted on is the set that was shown.
     */
    public function testAResultOnlyTheBodyPassFindsIsSelected(): void
    {
        $this->thread('Quarterly figures', body: 'The pelican audit is attached.');
        $this->thread('Lunch on Thursday');

        self::assertSame(
            ['Quarterly figures'],
            $this->subjects($this->resolver->threadsIn($this->user, 'search', 'elica', false)),
        );
    }

    /** A search reaches no further than the searcher's own mail. */
    public function testAnotherUsersMailIsNotSelected(): void
    {
        $this->thread('Invoice of mine');

        $mine          = $this->user;
        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->thread('Invoice of theirs');

        self::assertSame(
            ['Invoice of mine'],
            $this->subjects($this->resolver->threadsIn($mine, 'search', 'invoice', false)),
        );
    }

    /**
     * A search's rows are conversations whatever the list setting, so the page
     * never posts the email level for one; if it did, it would select nothing
     * rather than every email of every result.
     */
    public function testTheEmailLevelOfASearchIsEmpty(): void
    {
        $this->thread('Invoice for March');

        self::assertSame([], $this->resolver->emailsIn($this->user, 'search', 'invoice', false));
    }

    /**
     * The scopes BulkStatusController lets a whole-view action name — every
     * one the list templates use, and not the layout's `*`.
     */
    #[DataProvider('scopes')]
    public function testOnlyAScopeThatResolvesIsNamed(string $scope, bool $named): void
    {
        self::assertSame($named, ListViewResolver::names($scope));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function scopes(): iterable
    {
        foreach (['inbox', 'label', 'starred', 'unread', 'search', 'all_mail', 'archive', 'trash', 'spam', 'sent', 'drafts', 'snoozed'] as $scope) {
            yield $scope => [$scope, true];
        }

        yield 'the layout default' => ['*', false];
        yield 'nothing' => ['', false];
        yield 'a role with no list' => ['important', false];
    }

    /**
     * @param list<MessageThread> $threads
     *
     * @return list<string>
     */
    private function subjects(array $threads): array
    {
        $subjects = array_map(static fn (MessageThread $thread): string => (string) $thread->subject, $threads);

        sort($subjects);

        return $subjects;
    }
}
