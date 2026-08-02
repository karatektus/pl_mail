<?php

declare(strict_types=1);

namespace App\Jmap\Query;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\MessageFlag;
use App\Jmap\Protocol\Exception\MethodException;

/**
 * Compiles a JMAP FilterCondition / FilterOperator tree (RFC 8621 §4.4.1) into
 * a SQL fragment over the "message" table aliased as m.
 *
 * SQL rather than DQL on purpose: Email/query returns ids only, so nothing is
 * hydrated, and the conditions that matter most here — jsonb containment for
 * keyword flags, tsvector matching for full-text — have no portable DQL form.
 *
 * Anything not understood raises "unsupportedFilter" rather than being silently
 * dropped: a filter that is quietly ignored returns too many Emails, which a
 * client cannot detect.
 */
final class EmailFilterCompiler
{
    /**
     * Keywords backed by a dedicated column. The rest fall through to the
     * flags json array.
     */
    private const array COLUMN_KEYWORDS = [
        '$seen' => 'm.seen_at',
        '$flagged' => 'm.starred_at',
    ];

    private int $parameterIndex = 0;

    /**
     * @param array<string,mixed> $filter
     */
    public function compile(array $filter): CompiledFilter
    {
        $this->parameterIndex = 0;
        $parameters = [];
        $sql = $this->node($filter, $parameters);

        return new CompiledFilter($sql, $parameters);
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $parameters
     */
    private function node(array $filter, array &$parameters): string
    {
        if (true === array_key_exists('operator', $filter)) {
            return $this->operator($filter, $parameters);
        }

        return $this->condition($filter, $parameters);
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $parameters
     */
    private function operator(array $filter, array &$parameters): string
    {
        $operator = $filter['operator'];
        $conditions = $filter['conditions'] ?? null;

        if (false === is_array($conditions) || 0 === count($conditions)) {
            throw new MethodException('invalidArguments', 'A FilterOperator requires a non-empty "conditions" array.');
        }

        $parts = [];

        foreach ($conditions as $condition) {
            if (false === is_array($condition)) {
                throw new MethodException('invalidArguments', 'Each entry in "conditions" must be an object.');
            }

            $parts[] = $this->node($condition, $parameters);
        }

        return match ($operator) {
            'AND' => '('.implode(' AND ', $parts).')',
            'OR' => '('.implode(' OR ', $parts).')',
            'NOT' => 'NOT ('.implode(' OR ', $parts).')',
            default => throw new MethodException('invalidArguments', 'FilterOperator must be AND, OR or NOT.'),
        };
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $parameters
     */
    private function condition(array $filter, array &$parameters): string
    {
        $parts = [];

        foreach ($filter as $property => $value) {
            $parts[] = $this->property((string) $property, $value, $parameters);
        }

        if (0 === count($parts)) {
            return 'TRUE';
        }

        return '('.implode(' AND ', $parts).')';
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function property(string $property, mixed $value, array &$parameters): string
    {
        return match ($property) {
            'inMailbox' => $this->inMailbox($value, $parameters),
            'inMailboxOtherThan' => $this->inMailboxOtherThan($value, $parameters),
            'before' => $this->dateBound('<', $value, $parameters),
            'after' => $this->dateBound('>=', $value, $parameters),
            'minSize' => $this->sizeBound('>=', $value, $parameters),
            'maxSize' => $this->sizeBound('<', $value, $parameters),
            'hasKeyword' => $this->keyword($value, true, $parameters),
            'notKeyword' => $this->keyword($value, false, $parameters),
            'hasAttachment' => $this->hasAttachment($value),
            'text' => $this->fullText($value, $parameters),
            'body' => $this->like(['m.body_text'], $value, $parameters),
            'subject' => $this->like(['m.subject'], $value, $parameters),
            'from' => $this->like(['m.from_address', 'm.from_name'], $value, $parameters),
            'to' => $this->jsonAddressLike('m.to_addresses', $value, $parameters),
            'cc' => $this->jsonAddressLike('m.cc_addresses', $value, $parameters),
            'bcc' => $this->jsonAddressLike('m.bcc_addresses', $value, $parameters),
            // Added for mail rules (see App\Domain\Filter\FilterVocabulary).
            // hasLabel takes a user-scoped Label id, unlike inMailbox, which
            // takes a per-account LabelBinding id — rules have no reason to
            // know about the JMAP id space.
            'hasLabel' => $this->hasLabel($value, true, $parameters),
            'notLabel' => $this->hasLabel($value, false, $parameters),
            'filename' => $this->filename($value, $parameters),
            'listId' => $this->header('list-id', $value, $parameters),
            // plMail extension; see threadCategory() and src/Jmap/README.md.
            'threadCategory' => $this->threadCategory($value, $parameters),
            default => throw new MethodException('unsupportedFilter', sprintf('Filter condition "%s" is not supported.', $property)),
        };
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function inMailbox(mixed $value, array &$parameters): string
    {
        $name = $this->bind($this->requireIntish($value, 'inMailbox'), $parameters);

        // A JMAP Mailbox id is a label_binding id; message_label stores label
        // ids. The subquery bridges the two id spaces.
        return sprintf(
            'EXISTS (SELECT 1 FROM message_label ml
                     WHERE ml.message_id = m.id
                       AND ml.label_id = (SELECT lb.label_id FROM label_binding lb WHERE lb.id = :%s))',
            $name,
        );
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function inMailboxOtherThan(mixed $value, array &$parameters): string
    {
        if (false === is_array($value) || 0 === count($value)) {
            throw new MethodException('invalidArguments', '"inMailboxOtherThan" must be a non-empty array of Mailbox ids.');
        }

        $ids = [];

        foreach ($value as $entry) {
            $ids[] = $this->requireIntish($entry, 'inMailboxOtherThan');
        }

        $name = $this->bind($ids, $parameters);

        return sprintf(
            'EXISTS (SELECT 1 FROM message_label ml
                     WHERE ml.message_id = m.id
                       AND ml.label_id NOT IN (SELECT lb.label_id FROM label_binding lb WHERE lb.id IN (:%s)))',
            $name,
        );
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function hasLabel(mixed $value, bool $present, array &$parameters): string
    {
        $name = $this->bind($this->requireIntish($value, 'hasLabel'), $parameters);

        $exists = sprintf(
            'EXISTS (SELECT 1 FROM message_label ml WHERE ml.message_id = m.id AND ml.label_id = :%s)',
            $name,
        );

        if (true === $present) {
            return $exists;
        }

        return 'NOT '.$exists;
    }

    /**
     * The conversation's inbox category — the plMail extension that makes the
     * Gmail-style tabs a server-side filter rather than a client-side sieve.
     *
     * **Thread-scoped, not message-scoped, and that is the whole design.**
     * `message.category` is the raw per-message signal; `message_thread.category`
     * is that signal resolved most-recent-wins, and it is what the web inbox
     * filters on (`MessageThreadRepository::findForUnifiedInbox`). Filtering the
     * per-message column instead would put one conversation in two tabs whenever
     * its messages disagreed — a newsletter answered by a human is the ordinary
     * case, not a corner — so the phone and the browser would show different
     * mail under the same tab name.
     *
     * It also has to be the *server* that filters, which is why this condition
     * exists at all rather than the client reading `Thread.category` and hiding
     * rows. `Email/query` windows by position and limit; a client that fetched
     * twenty-five and dropped twenty-three of them would draw a nearly empty
     * Promotions tab under a scrollbar that had already reached the end.
     *
     * A message with no thread never matches, and cannot: the value being
     * filtered on does not exist for it. A thread with a null category matches
     * nothing either, which is deliberate — it is exactly what the web query
     * does, and a JMAP tab that contained conversations the browser's tab did
     * not is the "my phone shows different mail" bug this whole layer is
     * careful about. `app:backfill category` is what fills those in.
     *
     * @param array<string,mixed> $parameters
     */
    private function threadCategory(mixed $value, array &$parameters): string
    {
        if (false === is_string($value)) {
            throw new MethodException('invalidArguments', '"threadCategory" must be a string.');
        }

        $category = MessageCategory::tryFrom($value);

        // invalidArguments rather than unsupportedFilter, and the difference is
        // real: the server supports every category there is, so an unknown token
        // is not a condition it cannot answer — it is a value that names nothing.
        // The description carries the vocabulary because a closed set the caller
        // cannot discover is only marginally better than no set, which is the
        // lesson Mailbox.color paid for.
        if (null === $category) {
            throw new MethodException('invalidArguments', sprintf(
                '"%s" is not a known category. Use one of: %s.',
                $value,
                implode(', ', array_column(MessageCategory::cases(), 'value')),
            ));
        }

        $name = $this->bind($category->value, $parameters);

        return sprintf(
            'EXISTS (SELECT 1 FROM message_thread mt WHERE mt.id = m.thread_id AND mt.category = :%s)',
            $name,
        );
    }

    /**
     * Matches any attachment's filename. message_part rows exist for inline
     * parts too, but those carry a null filename and so never match.
     *
     * @param array<string,mixed> $parameters
     */
    private function filename(mixed $value, array &$parameters): string
    {
        if (false === is_string($value) || '' === $value) {
            throw new MethodException('invalidArguments', '"filename" must be a non-empty string.');
        }

        $name = $this->bind('%'.$this->escapeLike($value).'%', $parameters);

        return sprintf(
            'EXISTS (SELECT 1 FROM message_part mp WHERE mp.message_id = m.id AND mp.filename ILIKE :%s)',
            $name,
        );
    }

    /**
     * Substring match on one header's value.
     *
     * Safe to look the key up directly because HeaderNormalizer canonicalises
     * every header bag at ingest to lowercase, dash-separated names — before
     * that, the same header arrived as "List-Id" from Gmail and "list_id" from
     * php-imap, and a lookup like this matched on one provider only.
     *
     * The bag holds either a string or an array of strings depending on
     * whether the header repeated, so the value is cast to text and searched
     * whole rather than compared.
     *
     * @param array<string,mixed> $parameters
     */
    private function header(string $canonicalName, mixed $value, array &$parameters): string
    {
        if (false === is_string($value) || '' === $value) {
            throw new MethodException('invalidArguments', 'Header filters must be non-empty strings.');
        }

        $key = $this->bind($canonicalName, $parameters);
        $needle = $this->bind('%'.$this->escapeLike($value).'%', $parameters);

        return sprintf('(m.headers::jsonb -> :%s)::text ILIKE :%s', $key, $needle);
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function dateBound(string $comparison, mixed $value, array &$parameters): string
    {
        if (false === is_string($value)) {
            throw new MethodException('invalidArguments', 'Date filters must be UTCDate strings.');
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new MethodException('invalidArguments', sprintf('"%s" is not a valid UTCDate.', $value));
        }

        $name = $this->bind($date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $parameters);

        return sprintf('m.received_at %s :%s', $comparison, $name);
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function sizeBound(string $comparison, mixed $value, array &$parameters): string
    {
        if (false === is_int($value)) {
            throw new MethodException('invalidArguments', 'Size filters must be integers.');
        }

        $name = $this->bind($value, $parameters);

        return sprintf('m.size %s :%s', $comparison, $name);
    }

    /**
     * $seen and $flagged read their dedicated timestamp columns; $draft and
     * $answered fall through to jsonb containment on the IMAP flags array.
     *
     * @param array<string,mixed> $parameters
     */
    private function keyword(mixed $value, bool $present, array &$parameters): string
    {
        if (false === is_string($value)) {
            throw new MethodException('invalidArguments', 'Keyword filters must be strings.');
        }

        $column = self::COLUMN_KEYWORDS[$value] ?? null;

        if (null !== $column) {
            return sprintf('%s IS %s NULL', $column, true === $present ? 'NOT' : '');
        }

        $flag = $this->flagFor($value);
        $name = $this->bind(json_encode([$flag], JSON_THROW_ON_ERROR), $parameters);
        $containment = sprintf('m.flags::jsonb @> :%s::jsonb', $name);

        if (true === $present) {
            return $containment;
        }

        return 'NOT '.$containment;
    }

    private function flagFor(string $keyword): string
    {
        return match ($keyword) {
            '$draft' => MessageFlag::DRAFT->value,
            '$answered' => MessageFlag::ANSWERED->value,
            default => throw new MethodException('unsupportedFilter', sprintf('Keyword "%s" is not supported.', $keyword)),
        };
    }

    private function hasAttachment(mixed $value): string
    {
        if (false === is_bool($value)) {
            throw new MethodException('invalidArguments', '"hasAttachment" must be a boolean.');
        }

        if (true === $value) {
            return 'm.has_attachments = TRUE';
        }

        return 'COALESCE(m.has_attachments, FALSE) = FALSE';
    }

    /**
     * Uses the generated search_vector column, so "text" is a real stemmed
     * search rather than a substring scan.
     *
     * The 'english' config and websearch_to_tsquery both have to match how the
     * column is generated (Message::$searchVector) and how MessageThread search
     * queries it — a mismatched config silently returns nothing, since the
     * stemmed tokens never line up.
     *
     * @param array<string,mixed> $parameters
     */
    private function fullText(mixed $value, array &$parameters): string
    {
        if (false === is_string($value) || '' === trim($value)) {
            throw new MethodException('invalidArguments', '"text" must be a non-empty string.');
        }

        $name = $this->bind($value, $parameters);

        return sprintf('m.search_vector @@ websearch_to_tsquery(\'english\', :%s)', $name);
    }

    /**
     * @param list<string>          $columns
     * @param array<string,mixed>   $parameters
     */
    private function like(array $columns, mixed $value, array &$parameters): string
    {
        if (false === is_string($value) || '' === $value) {
            throw new MethodException('invalidArguments', 'Text filters must be non-empty strings.');
        }

        $name = $this->bind('%'.$this->escapeLike($value).'%', $parameters);
        $parts = [];

        foreach ($columns as $column) {
            $parts[] = sprintf('%s ILIKE :%s', $column, $name);
        }

        return '('.implode(' OR ', $parts).')';
    }

    /**
     * to/cc/bcc are json arrays of {name, address}; matching is a substring
     * scan over the serialised array, which covers both fields at once.
     *
     * @param array<string,mixed> $parameters
     */
    private function jsonAddressLike(string $column, mixed $value, array &$parameters): string
    {
        if (false === is_string($value) || '' === $value) {
            throw new MethodException('invalidArguments', 'Address filters must be non-empty strings.');
        }

        $name = $this->bind('%'.$this->escapeLike($value).'%', $parameters);

        return sprintf('%s::text ILIKE :%s', $column, $name);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function requireIntish(mixed $value, string $property): int
    {
        if (true === is_int($value)) {
            return $value;
        }

        if (true === is_string($value) && true === ctype_digit($value)) {
            return (int) $value;
        }

        throw new MethodException('invalidArguments', sprintf('"%s" must be a Mailbox id.', $property));
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function bind(mixed $value, array &$parameters): string
    {
        $name = 'f'.$this->parameterIndex;
        ++$this->parameterIndex;
        $parameters[$name] = $value;

        return $name;
    }
}
