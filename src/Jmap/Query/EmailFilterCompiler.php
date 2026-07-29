<?php

declare(strict_types=1);

namespace App\Jmap\Query;

use App\Domain\Enum\MessageFlag;
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
