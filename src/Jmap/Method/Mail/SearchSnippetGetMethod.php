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
 * WHAT THIS METHOD DELIBERATELY DOES NOT DO. `ts_headline` cannot mark a term
 * that sits INSIDE a compound token — `chargecloud` in `chargecloud.de`, or in
 * a link — while the search finds such rows perfectly well through the
 * token-parts arm of `search_vector`. SearchHighlighter::headlineOrFallback()
 * closes that on the web, and this method does not call it, because the
 * fallback needs the field's RAW text to search and this method does not have
 * any. The web page does: `preloadForRows()` has hydrated every message on the
 * page before SearchResultHighlights runs, so the fallback there reads memory
 * and costs nothing. Here it would mean up to MAX_OBJECTS whole bodies fetched
 * a second time — once as a headline, once raw — or a second statement, to
 * find out whether a substring occurs.
 *
 * That is a cost worth measuring before paying, not one to slip in behind a
 * highlighting fix, so a JMAP client still sees a null snippet for those hits
 * and falls back to its own preview. If it is ever paid, the fallback is one
 * call away and needs no new way of producing a `<mark>`.
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

        $list = [];
        $found = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $found[] = $id;

            $list[] = [
                'emailId' => $id,
                // Null rather than the whole field when nothing matched in it:
                // a "snippet" that is just the subject again tells the reader
                // nothing about why the message came back. toHtml() answers
                // null for exactly that case.
                'subject' => $this->highlighter->toHtml($row['subject']),
                'preview' => $this->highlighter->toHtml($row['preview']),
            ];
        }

        return [
            'accountId' => (string) $accountId,
            'list' => $list,
            'notFound' => array_values(array_diff($requested, $found)),
        ];
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
