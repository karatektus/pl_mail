<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Search\SearchQueryParser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Search, from the string a person types to the rows that come back.
 *
 * Driven through the parser rather than by handing the repository a filter
 * object, because the two halves are only useful together and the bugs live in
 * the seam: an operator the parser understands and the SQL never applies is
 * indistinguishable, from the outside, from one it applies to the wrong column.
 * Both failures widen the result set, and a search that returns too much is one
 * nobody notices is broken.
 *
 * Against Postgres, not a double: every operator here is a range, a JSON
 * containment or a full-text match, which is exactly what a fake would have to
 * reimplement to be wrong in a different way.
 */
final class MessageSearchTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageThreadRepository $repository;
    private SearchQueryParser $parser;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageThreadRepository::class);
        $this->parser     = $container->get(SearchQueryParser::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testFromMatchesTheAddressAndTheName(): void
    {
        $this->seedMessage(subject: 'Invoice', fromAddress: 'billing@acme.test', fromName: 'Acme Billing');
        $this->seedMessage(subject: 'Lunch', fromAddress: 'kim@example.test', fromName: 'Kim');

        self::assertSame(['Invoice'], $this->search('from:billing@'));
        self::assertSame(['Invoice'], $this->search('from:acme billing'));
        // Case is the sender's business, not the searcher's.
        self::assertSame(['Invoice'], $this->search('from:BILLING@ACME.TEST'));
    }

    /**
     * to: and cc: read a JSON array of {name, address} objects. cc: existed on
     * the SQL side for a long time with no way to reach it — the parser had no
     * case for it — so this is as much about the seam as the column.
     */
    public function testToAndCcReadTheirOwnRecipientLists(): void
    {
        $this->seedMessage(
            subject: 'Direct',
            to: [['name' => 'Kim', 'address' => 'kim@example.test']],
        );
        $this->seedMessage(
            subject: 'Copied',
            to: [['name' => 'Sam', 'address' => 'sam@example.test']],
            cc: [['name' => 'Kim', 'address' => 'kim@example.test']],
        );

        self::assertSame(['Direct'], $this->search('to:kim@example.test'));
        self::assertSame(['Copied'], $this->search('cc:kim@example.test'));
    }

    public function testSubjectMatchesPartOfIt(): void
    {
        $this->seedMessage(subject: 'Quarterly invoice 2026-0841');
        $this->seedMessage(subject: 'Lunch on Thursday');

        self::assertSame(['Quarterly invoice 2026-0841'], $this->search('subject:invoice'));
    }

    /**
     * Free text goes through the generated tsvector, which is the whole reason
     * it is Postgres doing the matching: "meetings" finds "meeting" because the
     * column is stemmed, and no LIKE would.
     */
    public function testFreeTextIsStemmed(): void
    {
        $this->seedMessage(subject: 'Notes', body: 'The meeting is postponed until Tuesday.');
        $this->seedMessage(subject: 'Other', body: 'Nothing relevant in here.');

        self::assertSame(['Notes'], $this->search('meetings'));
    }

    /**
     * The table from the bug report, verbatim.
     *
     * One mail, five ways of reaching for it, four of which used to come back
     * empty. Kept as one test over one fixture because that is the shape of the
     * complaint: the SAME mail, visible on screen, unfindable by every spelling
     * of what is visibly in it except the one nobody would type.
     *
     * The two failures have different causes and different fixes, which is why
     * both a prefix pass and a substring pass exist:
     *
     *   "Testmai"   a prefix of a lexeme. `:*` reaches it.
     *   "wirhub"    NOT a prefix of anything — the tokenizer emits
     *               "help.wirhub.de" as one host lexeme, so it is a substring
     *               of a lexeme, and only ILIKE reaches it.
     */
    #[DataProvider('reportedSearches')]
    public function testTheReportedSearchesAllFindTheMail(string $query): void
    {
        $this->seedMessage(
            subject: 'Testmail Betreff',
            body: 'Bitte besuche help.wirhub.de fuer mehr Infos',
        );
        $this->seedMessage(subject: 'Unrelated', body: 'Nothing in particular.');

        self::assertSame(['Testmail Betreff'], $this->search($query));
    }

    /** @return iterable<string, array{string}> */
    public static function reportedSearches(): iterable
    {
        yield 'whole word, which already worked' => ['Testmail'];
        yield 'prefix of a subject word'         => ['Testmai'];
        yield 'inside a host token'              => ['wirhub'];
        yield 'a suffix of a host token'         => ['wirhub.de'];
        yield 'the host token entire'            => ['help.wirhub.de'];
    }

    /**
     * Widening free text must not widen a query that was narrowed on purpose.
     *
     * Negation is the one that matters: OR-ing a prefix match onto `-invoice`
     * hands back precisely the mail the user asked not to see, which is worse
     * than the bug being fixed here. So websearch syntax turns the extra passes
     * off rather than fighting them.
     */
    public function testDeliberateWebsearchSyntaxIsNotWidened(): void
    {
        $this->seedMessage(subject: 'Invoice reminder', body: 'Payment for the invoice.');
        $this->seedMessage(subject: 'Payment plan', body: 'A plan for payment.');

        self::assertSame(['Payment plan'], $this->search('payment -invoice'));
        self::assertSame(['Payment plan'], $this->search('"payment plan"'));
        self::assertCount(2, $this->search('invoice OR plan'));
    }

    /**
     * tsquery syntax typed into the box is text, not syntax. Before the prefix
     * pass there was nothing to escape — websearch_to_tsquery is total over its
     * input — so this is new surface and worth pinning: any of these reaching
     * to_tsquery unescaped is a 500, and `!` reaching it is a search that
     * returns the complement of what was asked for.
     */
    #[DataProvider('hostileQueries')]
    public function testTsqueryPunctuationIsTextNotSyntax(string $query, int $expected): void
    {
        $this->seedMessage(subject: 'Invoice reminder', body: 'Payment for the invoice.');

        self::assertCount($expected, $this->search($query));
    }

    /**
     * The counts are the point, not just the absence of an exception: the
     * punctuation is stripped and the words around it still do their work, and
     * a query made of nothing BUT punctuation finds nothing rather than
     * everything — which is what `!` would do if it reached to_tsquery as an
     * operator.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function hostileQueries(): iterable
    {
        yield 'bare colon star'    => ['invoice:*', 1];
        yield 'unbalanced quote'   => ["invoice'", 1];
        yield 'boolean operators'  => ['invoice & payment', 1];
        yield 'negation operator'  => ['!invoice', 1];
        yield 'unbalanced paren'   => ['(invoice', 1];
        yield 'nothing but syntax' => ['&|!():*', 0];
    }

    public function testLabelMatchesAUserLabelByName(): void
    {
        $receipts = $this->seedLabel('Receipts');

        $this->seedMessage(subject: 'Filed', labels: [$receipts]);
        $this->seedMessage(subject: 'Unfiled');

        self::assertSame(['Filed'], $this->search('label:receipts'));
    }

    /**
     * in: names a mailbox and the SQL matches a role, which only works because
     * the parser resolves the alias first. `in:junk` finding nothing would look
     * like an empty spam folder rather than a filter that never applied.
     */
    public function testInMatchesTheRoleBehindTheMailboxName(): void
    {
        $spam = $this->seedLabel('Spam', LabelRole::Spam);

        $this->seedMessage(subject: 'Dubious', labels: [$spam]);
        $this->seedMessage(subject: 'Ordinary');

        self::assertSame(['Dubious'], $this->search('in:junk'));
        self::assertSame(['Dubious'], $this->search('in:spam'));
    }

    /**
     * The rule every list view applies since v0.0.25, arriving at search last
     * because search builds its own SQL: a deleted conversation is not part of
     * the mailbox somebody is looking through until they say `in:trash`.
     *
     * The trashed thread here deliberately carries a second label, because
     * that is the case a naive `lbl.role <> 'trash'` would get wrong — the
     * label join exists once per label, so the thread would keep matching
     * through its "Receipts" row. `label:receipts` finding nothing is the
     * assertion that actually pins the NOT EXISTS.
     */
    public function testTrashIsOnlyFoundWhenAskedFor(): void
    {
        $trash    = $this->seedLabel('Trash', LabelRole::Trash);
        $receipts = $this->seedLabel('Receipts');

        $this->seedMessage(subject: 'Binned invoice', body: 'the quarterly invoice', labels: [$trash, $receipts]);
        $this->seedMessage(subject: 'Kept invoice', body: 'the quarterly invoice');

        self::assertSame(['Kept invoice'], $this->search('invoice'));
        self::assertSame([], $this->search('label:receipts'));
        self::assertSame(['Binned invoice'], $this->search('in:trash'));
        self::assertSame(['Binned invoice'], $this->search('in:bin'));
    }

    public function testFlagsAndDatesNarrowTheSameWay(): void
    {
        $this->seedMessage(subject: 'Unread old', receivedAt: '2024-03-01', seen: false);
        $this->seedMessage(subject: 'Read recent', receivedAt: '2026-03-01', seen: true);

        self::assertSame(['Unread old'], $this->search('is:unread'));
        self::assertSame(['Read recent'], $this->search('is:read'));
        self::assertSame(['Read recent'], $this->search('after:2025-01-01'));
        self::assertSame(['Unread old'], $this->search('before:2025-01-01'));
    }

    /** Operators are ANDed: each one may only ever narrow the result. */
    public function testOperatorsCombine(): void
    {
        $this->seedMessage(subject: 'Invoice', fromAddress: 'billing@acme.test');
        $this->seedMessage(subject: 'Invoice', fromAddress: 'other@else.test');

        self::assertSame(['Invoice'], $this->search('subject:invoice from:acme'));
        self::assertCount(2, $this->search('subject:invoice'));
    }

    /**
     * The guard the whole search rests on. Every clause is ANDed onto a user
     * scope, so a filter that matches everything still may not reach another
     * user's mail — the failure mode nobody would see in their own account.
     */
    public function testSearchNeverLeavesTheUser(): void
    {
        $this->seedMessage(subject: 'Mine', fromAddress: 'billing@acme.test');

        $stranger = $this->seedUser();
        $this->seedMessage(
            subject: 'Theirs',
            fromAddress: 'billing@acme.test',
            account: $this->seedAccount($stranger),
        );

        self::assertSame(['Mine'], $this->search('from:billing@acme.test'));
        self::assertSame(1, $this->repository->countSearch($this->user, $this->parser->parse('from:billing@acme.test')));
    }

    // ── Order ────────────────────────────────────────────────────────────────

    /**
     * The default is newest first, and it did not use to be.
     *
     * Search answered in `ts_rank` order with no way to ask for anything else,
     * so a keyword that matched a 2004 mail and a 2026 mail put them in an
     * order that had nothing to do with when they arrived. That is a search
     * engine's answer to a mailbox's question.
     */
    public function testResultsAreNewestFirstByDefault(): void
    {
        // Deliberately seeded out of order, and with the *worse* full-text
        // match on the newest one: under the old ordering "Ancient" led.
        $this->seedMessage(subject: 'Middle',  body: 'invoice', receivedAt: '2015-06-01');
        $this->seedMessage(subject: 'Ancient', body: 'invoice invoice invoice', receivedAt: '2004-12-01');
        $this->seedMessage(subject: 'Newest',  body: 'invoice', receivedAt: '2026-05-01');

        self::assertSame(['Newest', 'Middle', 'Ancient'], $this->search('invoice'));
    }

    /** And the other position of the switch is the order search shipped with. */
    public function testRelevanceOrdersByRankInstead(): void
    {
        $this->seedMessage(subject: 'Middle',  body: 'invoice', receivedAt: '2015-06-01');
        $this->seedMessage(subject: 'Ancient', body: 'invoice invoice invoice', receivedAt: '2004-12-01');
        $this->seedMessage(subject: 'Newest',  body: 'invoice', receivedAt: '2026-05-01');

        $byRank = $this->search('invoice', SearchSortOrder::Relevance);

        self::assertSame('Ancient', $byRank[0], 'the densest match should lead under relevance');
        self::assertCount(3, $byRank);
        self::assertNotSame($this->search('invoice'), $byRank, 'the two orders must differ');
    }

    /**
     * Pagination over a tied sort, which is the bug this order switch exposed.
     *
     * `ORDER BY rank DESC, last_message_at DESC` is not a total order: identical
     * text scoring identically on the same day leaves Postgres free to break the
     * tie however each page's plan happens to, and LIMIT/OFFSET over that shows
     * a row twice and some other row never. Not an exotic case either — a query
     * that stems to nothing makes `ts_rank` degenerate and ties *every* row, so
     * the entire ordering rests on the tiebreaker.
     *
     * Asserted as "the pages, concatenated, are the whole result set in the
     * documented order" rather than merely "no duplicates": a wrong-but-stable
     * order would pass the weaker check on a small table.
     */
    public function testRelevanceStaysStableAcrossPagesWhenRanksTie(): void
    {
        // Same body, same day: rank ties and last_message_at ties with it.
        for ($i = 1; $i <= 6; $i++) {
            $this->seedMessage(subject: 'Tied ' . $i, body: 'quarterly report', receivedAt: '2026-02-02');
        }

        $parsed = $this->parser->parse('quarterly report');
        $single = $this->searchIds($parsed, page: 1, perPage: 6, sort: SearchSortOrder::Relevance);

        self::assertCount(6, $single);

        $paged = array_merge(
            $this->searchIds($parsed, page: 1, perPage: 2, sort: SearchSortOrder::Relevance),
            $this->searchIds($parsed, page: 2, perPage: 2, sort: SearchSortOrder::Relevance),
            $this->searchIds($parsed, page: 3, perPage: 2, sort: SearchSortOrder::Relevance),
        );

        self::assertSame($single, $paged, 'paging a tied ranking dropped or repeated rows');
        self::assertSame($single, array_values(array_unique($paged)));

        // The tiebreaker is id, descending — deterministic, and the same one
        // whichever page you are on.
        $descending = $single;
        rsort($descending);

        self::assertSame($descending, $single);
    }

    /** The same guarantee for the default order, whose dates tie just as often. */
    public function testRecentStaysStableAcrossPagesWhenDatesTie(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->seedMessage(subject: 'Same day ' . $i, body: 'standup notes', receivedAt: '2026-02-02');
        }

        $parsed = $this->parser->parse('standup notes');
        $single = $this->searchIds($parsed, page: 1, perPage: 6);

        $paged = array_merge(
            $this->searchIds($parsed, page: 1, perPage: 2),
            $this->searchIds($parsed, page: 2, perPage: 2),
            $this->searchIds($parsed, page: 3, perPage: 2),
        );

        self::assertCount(6, $single);
        self::assertSame($single, $paged, 'paging a tied date order dropped or repeated rows');

        $descending = $single;
        rsort($descending);

        self::assertSame($descending, $single);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @return list<string> subjects of the matching threads */
    private function search(string $query, SearchSortOrder $sort = SearchSortOrder::Recent): array
    {
        return array_map(
            static fn (MessageThread $thread): string => (string) $thread->subject,
            $this->repository->search($this->user, $this->parser->parse($query), sort: $sort),
        );
    }

    /** @return list<int> thread ids, in the order the SQL returned them */
    private function searchIds(
        ParsedSearchQuery $parsed,
        int $page = 1,
        int $perPage = 50,
        SearchSortOrder $sort = SearchSortOrder::Recent,
    ): array {
        return array_map(
            static fn (MessageThread $thread): int => (int) $thread->id,
            $this->repository->search($this->user, $parsed, $page, $perPage, $sort),
        );
    }

    /**
     * @param list<array{name: string, address: string}> $to
     * @param list<array{name: string, address: string}> $cc
     * @param list<Label>                                $labels
     */
    private function seedMessage(
        string $subject,
        string $fromAddress = 'sender@example.test',
        string $fromName = 'Sender',
        array $to = [],
        array $cc = [],
        array $labels = [],
        string $body = 'Nothing in particular.',
        string $receivedAt = '2026-01-01',
        bool $seen = false,
        ?Account $account = null,
    ): void {
        $account ??= $this->account;

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable($receivedAt);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = $fromAddress;
        $message->fromName       = $fromName;
        $message->toAddresses    = $to;
        $message->ccAddresses    = $cc;
        $message->bodyText       = $body;
        $message->receivedAt     = new DateTimeImmutable($receivedAt);
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];

        if (true === $seen) {
            $message->seenAt = new DateTimeImmutable($receivedAt);
        }

        foreach ($labels as $label) {
            $message->addLabel($label);
            $thread->addLabel($label);
        }

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();
    }

    private function seedLabel(string $name, ?LabelRole $role = null): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Search fixture';
        $account->email          = 'search@example.test';
        $account->username       = uniqid('search-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'search-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Search';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
