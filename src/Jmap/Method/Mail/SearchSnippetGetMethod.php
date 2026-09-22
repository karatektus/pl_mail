<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Mail\MessageRepository;
use App\Service\Search\SearchHighlighter;

/**
 * "SearchSnippet/get" (RFC 8621 §5).
 *
 * The bit of a message that actually matched, rather than its opening line.
 * Without it a ranked full-text search presents identically to a plain filter:
 * every result shows the same generic preview, and the user has to open each
 * one to find out why it came back.
 *
 * Built on Postgres `ts_headline` over the same generated `search_vector` and
 * `websearch_to_tsquery` that ran the query, so a snippet cannot highlight
 * something the search did not actually match on — which is the failure mode of
 * re-implementing the matching in PHP.
 *
 * Per the spec the strings are HTML, with `<mark>` around each hit. Everything
 * else is escaped: this is message content being handed back for display, and
 * the one thing it must not be able to do is carry markup of its own.
 *
 * That last sentence was a claim and not a fact for as long as this class asked
 * `ts_headline` for `StartSel=<mark>` directly. `ts_headline` does not escape
 * its document; it inserts the delimiters and hands back everything else
 * exactly as it found it, so a mail whose subject or text part contained markup
 * shipped that markup to every client as snippet HTML. The escaping now happens
 * in SearchHighlighter, which is also where the options string lives — the
 * conversion and the delimiters it converts cannot be changed apart.
 *
 * WHAT THE SECOND STATEMENT COSTS, AND WHY IT IS PAID. `ts_headline` cannot
 * mark a term that sits INSIDE a compound token — `chargecloud` in
 * `chargecloud.de`, or in a link — because the parser makes each of those ONE
 * lexeme and a highlighter built on lexemes can only mark what a lexeme can be.
 * The search finds those rows anyway, through the weight-D token-parts arm of
 * `search_vector` and through the substring pass, so a client was being told a
 * row matched and then handed a null snippet for it.
 * SearchHighlighter::headlineOrFallback() closes that, and it needs the field's
 * RAW text to search. The web page has that for free — `preloadForRows()` has
 * hydrated every message before SearchResultHighlights runs — and this method,
 * holding DBAL rows, does not. So it fetches it, and this is the bill.
 *
 * Measured against Postgres 18 on 5,000 messages with ~4.5KB text parts, warm,
 * median of five, the whole statement round trip:
 *
 *   ids   what the second statement had to fetch        added    on a request of
 *   ----+---------------------------------------------+--------+---------------
 *     1 | one subject, one body                        | 0.3ms  | ~1.0ms
 *   500 | 500 subjects, no body (the ordinary request) | 1.5ms  | ~197ms  (+0.8%)
 *   500 | 500 subjects, 250 bodies                     | 4.5ms  | ~197ms  (+2.3%)
 *   500 | 500 subjects, 500 bodies (the worst case)    | 7.9ms  | ~197ms  (+4.0%)
 *
 * The request was already spending that ~197ms parsing every one of those
 * bodies with `ts_headline`; what is new is only the transfer, and at 500 whole
 * bodies it is 2.2MB through PHP.
 *
 * The suggestion when this was deferred was to scope the second statement to
 * the ids whose headline came back unmarked, on the reasoning that such a set
 * is normally empty or tiny. Measuring it says otherwise, and in an instructive
 * way: the field that is normally unmarked is the SUBJECT, because most
 * searches match in the body — so "rows needing something" is normally ALL of
 * them, and scoping by row would have fetched 2.2MB of bodies to deliver 30KB
 * of subjects. What is normally empty is the set needing the expensive column.
 * Hence rawTextsFor() passes two id lists and MessageRepository gates
 * `body_text` behind a CASE; see there for why that actually avoids the read.
 *
 * The alternative shape — widening findSearchHeadlines() to return the text it
 * headlined — measures the same at 500 ids (201ms against 197ms + 7.9ms) and
 * was still refused twice over: it pays the 2.2MB unconditionally, including on
 * the ordinary request that wants none of it, and that method is shared with
 * the web search page, whose own docblock records deciding against exactly this
 * widening for exactly this reason.
 */
final class SearchSnippetGetMethod implements JmapMethod
{
    /**
     * Matches maxObjectsInGet. A snippet costs a `ts_headline` over a whole
     * body, so this is the one place where the general get ceiling is worth
     * enforcing rather than assuming.
     */
    private const int MAX_OBJECTS = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messages,
        private readonly SearchHighlighter $highlighter,
    ) {
    }

    public function name(): string
    {
        return 'SearchSnippet/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = (int) $account->id;

        $emailIds = $arguments['emailIds'] ?? null;

        if (false === is_array($emailIds)) {
            throw new MethodException('invalidArguments', '"emailIds" must be an array.');
        }

        if (count($emailIds) > self::MAX_OBJECTS) {
            throw new MethodException(
                'requestTooLarge',
                sprintf('At most %d ids per SearchSnippet/get.', self::MAX_OBJECTS),
            );
        }

        $requested = array_values(array_map(
            static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id,
            $emailIds,
        ));

        $text = $this->freeText($arguments['filter'] ?? null);

        // No free-text term means nothing to highlight. The spec's answer is a
        // snippet object with null strings rather than an omission, so a client
        // can tell "no highlight" from "message not found".
        if (null === $text || [] === $requested) {
            return [
                'accountId' => (string) $accountId,
                'list' => array_map(
                    static fn (string $id): array => [
                        'emailId' => $id,
                        'subject' => null,
                        'preview' => null,
                    ],
                    $requested,
                ),
                'notFound' => [],
            ];
        }

        return $this->snippets($accountId, $requested, $text);
    }

    /**
     * @param list<string> $requested
     *
     * @return array<string,mixed>
     */
    private function snippets(int $accountId, array $requested, string $text): array
    {
        // The options are the highlighter's, not this method's: they name the
        // delimiters it converts, and the web search page asks for the same
        // ones. Two callers spelling them out separately is how one of them
        // ends up highlighting nothing.
        $rows = $this->messages->findSearchHeadlines(
            $accountId,
            array_map('intval', $requested),
            $text,
            SearchHighlighter::HEADLINE_OPTIONS,
        );

        $rawTexts = $this->rawTextsFor($accountId, $rows);

        $list = [];
        $found = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $found[] = $id;

            // Absent for a row `ts_headline` marked in both fields, which is
            // the row the second statement was never asked about. Passing null
            // text is then correct rather than merely harmless:
            // headlineOrFallback() hands back the marked headline without
            // looking at it.
            $raw = $rawTexts[(int) $row['id']] ?? ['subject' => null, 'body_text' => null];

            $list[] = [
                'emailId' => $id,
                // Null rather than the whole field when nothing matched in it:
                // a "snippet" that is just the subject again tells the reader
                // nothing about why the message came back. Both producers
                // answer null for that case — `ts_headline`'s opening-words
                // reply carries no sentinel, and the fallback declines a field
                // the term genuinely is not in — and toHtml() is the one place
                // that turns either into markup, after escaping it.
                'subject' => $this->highlighter->toHtml(
                    $this->highlighter->headlineOrFallback($row['subject'], $raw['subject'], $text),
                ),
                'preview' => $this->highlighter->toHtml(
                    $this->highlighter->headlineOrFallback($row['preview'], $raw['body_text'], $text),
                ),
            ];
        }

        return [
            'accountId' => (string) $accountId,
            'list' => $list,
            'notFound' => array_values(array_diff($requested, $found)),
        ];
    }

    /**
     * The raw subject and body of the rows that need one, keyed by id.
     *
     * Only the fields `ts_headline` left unmarked are worth fetching, and the
     * two are counted separately because they cost different amounts — see the
     * class docblock for the numbers and MessageRepository::findSearchTexts()
     * for how the body column is gated. A request whose every field came back
     * marked issues no second statement at all: `$wanted` is empty, and the
     * repository answers without touching the database.
     *
     * isMarked() rather than a `str_contains` here. The sentinel belongs to
     * SearchHighlighter, and a second opinion about what "marked" means is how
     * this method would come to fetch text it does not need, or skip text it
     * does, on the day the delimiters change.
     *
     * @param list<array{id: int|string, subject: mixed, preview: mixed}> $rows
     *
     * @return array<int, array{subject: mixed, body_text: mixed}>
     */
    private function rawTextsFor(int $accountId, array $rows): array
    {
        $wanted = [];
        $withBody = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if (false === $this->highlighter->isMarked($row['preview'])) {
                $withBody[] = $id;
                $wanted[]   = $id;

                continue;
            }

            if (false === $this->highlighter->isMarked($row['subject'])) {
                $wanted[] = $id;
            }
        }

        return $this->messages->findSearchTexts($accountId, $wanted, $withBody);
    }

    /**
     * The free-text term the query ran on, or null when it had none.
     *
     * Walks the same filter tree the query was given, because RFC 8621 has the
     * client resend it here rather than referring to a stored query. Only
     * "text" and "body" carry something highlightable — "from" and friends
     * match a header the snippet does not show.
     */
    private function freeText(mixed $filter): ?string
    {
        if (false === is_array($filter)) {
            return null;
        }

        foreach (['text', 'body', 'subject'] as $property) {
            $value = $filter[$property] ?? null;

            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        $conditions = $filter['conditions'] ?? null;

        if (false === is_array($conditions)) {
            return null;
        }

        // The first one wins. A query with two different free-text terms under
        // an OR has no single right answer here, and highlighting one of them
        // is more useful than highlighting neither.
        foreach ($conditions as $condition) {
            $found = $this->freeText($condition);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}
