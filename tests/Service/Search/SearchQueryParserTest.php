<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SearchQueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the search box understands.
 *
 * The cases worth having are not the operators that work — they are the ones
 * that cannot be honoured, because every way of getting those wrong is silent.
 * An operator dropped on the floor widens the search to the whole mailbox and
 * looks exactly like a search that was ignored, which is what `in:archive`,
 * `is:important` and a half-typed `from:` all used to do.
 */
final class SearchQueryParserTest extends TestCase
{
    private SearchQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SearchQueryParser();
    }

    public function testOperatorsBecomeFilters(): void
    {
        $parsed = $this->parser->parse('from:alice to:bob cc:carol subject:invoice label:Receipts');

        self::assertSame('alice', $parsed->from);
        self::assertSame('bob', $parsed->to);
        self::assertSame('carol', $parsed->cc);
        self::assertSame('invoice', $parsed->subject);
        self::assertSame('Receipts', $parsed->label);
        self::assertSame('', $parsed->freeText);
    }

    public function testTextAroundOperatorsSurvives(): void
    {
        $parsed = $this->parser->parse('quarterly from:alice report');

        self::assertSame('alice', $parsed->from);
        self::assertSame('quarterly report', $parsed->freeText);
    }

    /** A value with a space is one value, not a value and a search term. */
    public function testQuotedValuesStayWhole(): void
    {
        $parsed = $this->parser->parse('from:"Paul Lützner" invoice');

        self::assertSame('Paul Lützner', $parsed->from);
        self::assertSame('invoice', $parsed->freeText);
    }

    /**
     * Half-typed, which every query is on its way to being finished. An empty
     * value must not become a filter: `LIKE '%%'` matches every message, so the
     * search would answer with the whole mailbox the moment the colon is typed.
     * It must not become free text either — nobody searching `from:` is looking
     * for the word "from".
     */
    public function testAnOperatorWithNoValueFiltersNothingAndSearchesNothing(): void
    {
        $parsed = $this->parser->parse('from:');

        self::assertNull($parsed->from);
        self::assertSame('', $parsed->freeText);
        self::assertTrue($parsed->isEmpty());
    }

    public function testASpaceAfterTheColonIsAlsoNoValue(): void
    {
        $parsed = $this->parser->parse('from: paul');

        self::assertNull($parsed->from);
        self::assertSame('paul', $parsed->freeText);
    }

    /**
     * The rule that keeps every failure visible: anything this cannot honour
     * falls through to free text, where it finds little or nothing. Dropping it
     * instead would silently widen the search.
     *
     * @param non-empty-string $query
     */
    #[DataProvider('unhonourable')]
    public function testWhatCannotBeHonouredBecomesText(string $query): void
    {
        $parsed = $this->parser->parse($query);

        self::assertSame($query, $parsed->freeText);
    }

    /** @return iterable<string, array{string}> */
    public static function unhonourable(): iterable
    {
        yield 'unknown operator'        => ['banana:split'];
        yield 'unknown is: value'       => ['is:important'];
        yield 'unknown has: value'      => ['has:image'];
        yield 'a mailbox that is not'   => ['in:nowhere'];
        yield 'a date that is not'      => ['after:nonsense'];
        // Not an operator at all — a subject line people really do search for.
        yield 'a bare colon in text'    => ['Re:'];
    }

    /**
     * `in:` names a mailbox the way people say it, and stores the role plMail
     * files by. Resolved here rather than next to the SQL so an unknown mailbox
     * is caught while it can still become free text.
     */
    #[DataProvider('mailboxes')]
    public function testMailboxNamesResolveToRoles(string $typed, string $role): void
    {
        self::assertSame($role, $this->parser->parse('in:' . $typed)->mailboxRole);
    }

    /** @return iterable<string, array{string, string}> */
    public static function mailboxes(): iterable
    {
        yield 'inbox'    => ['inbox', 'inbox'];
        yield 'archive'  => ['archive', 'archive'];
        yield 'archived' => ['archived', 'archive'];
        yield 'junk'     => ['junk', 'spam'];
        yield 'bin'      => ['bin', 'trash'];
        yield 'deleted'  => ['deleted', 'trash'];
        yield 'draft'    => ['draft', 'drafts'];
        yield 'snoozed'  => ['snoozed', 'snoozed'];
        // Typed the way it is displayed, which is not how it is stored.
        yield 'uppercase' => ['INBOX', 'inbox'];
    }

    public function testFlagsAndDates(): void
    {
        $parsed = $this->parser->parse('is:unread has:attachment after:2024-01-01 before:2024-12-31');

        self::assertTrue($parsed->isUnread);
        self::assertTrue($parsed->hasAttachment);
        self::assertSame('2024-01-01', $parsed->after?->format('Y-m-d'));
        self::assertSame('2024-12-31', $parsed->before?->format('Y-m-d'));
    }

    public function testPlainWordsAreJustText(): void
    {
        $parsed = $this->parser->parse('  bookshelf dimensions  ');

        self::assertSame('bookshelf dimensions', $parsed->freeText);
        self::assertFalse($parsed->isEmpty());
    }
}
