<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use Doctrine\ORM\EntityManagerInterface;

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
        private readonly EntityManagerInterface $entityManager,
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
        // Options chosen so a snippet is a snippet: one fragment, short enough
        // to sit on a list row, and no ellipsis of our own — the client decides
        // how to truncate for its width.
        $options = 'StartSel=<mark>, StopSel=</mark>, MaxWords=24, MinWords=8, '
            .'ShortWord=3, MaxFragments=1, FragmentDelimiter=" … "';

        $sql = <<<'SQL'
            SELECT
                m.id,
                ts_headline('english', coalesce(m.subject, ''),
                    websearch_to_tsquery('english', :text), :options) AS subject,
                ts_headline('english', coalesce(m.body_text, ''),
                    websearch_to_tsquery('english', :text), :options) AS preview
            FROM message m
            WHERE m.account_id = :account
              AND m.id IN (:ids)
            SQL;

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            $sql,
            [
                'text' => $text,
                'options' => $options,
                'account' => $accountId,
                'ids' => array_map('intval', $requested),
            ],
            [
                'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ],
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
                // nothing about why the message came back.
                'subject' => $this->highlighted($row['subject']),
                'preview' => $this->highlighted($row['preview']),
            ];
        }

        return [
            'accountId' => (string) $accountId,
            'list' => $list,
            'notFound' => array_values(array_diff($requested, $found)),
        ];
    }

    /**
     * `ts_headline` returns the text either way; only a string containing a
     * marker actually had a hit in that field.
     */
    private function highlighted(mixed $value): ?string
    {
        if (false === is_string($value) || '' === $value) {
            return null;
        }

        return str_contains($value, '<mark>') ? $value : null;
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
