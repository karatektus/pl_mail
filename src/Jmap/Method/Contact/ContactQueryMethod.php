<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Contacts\ContactRepository;

/**
 * "Contact/query": the ids of the user's address-book contacts, optionally
 * filtered, for a later Contact/get to resolve.
 *
 * Contacts are user-scoped, not account-scoped, so every one of the user's
 * accounts answers — the same rule Contact/autocomplete follows, and for the
 * same reason: refusing every account but one would fail a perfectly sensible
 * call from whichever account the client happens to be asking from.
 */
final class ContactQueryMethod implements JmapMethod
{
    /**
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId', 'filter', 'sort', 'position', 'limit'];

    /**
     * @var list<string>
     */
    private const array ACCEPTED_FILTER_KEYS = ['text', 'tag'];

    /**
     * @var list<string>
     */
    private const array ACCEPTED_SORTS = ['name', 'recent'];

    public const int DEFAULT_LIMIT = 100;

    public const int MAX_LIMIT = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactRepository $contacts,
    ) {
    }

    public function name(): string
    {
        return 'Contact/query';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $filter = $this->resolveFilter($arguments['filter'] ?? null);
        $sort = $this->resolveSort($arguments['sort'] ?? null);
        $position = $this->resolvePosition($arguments['position'] ?? null);
        $limit = $this->resolveLimit($arguments['limit'] ?? null);

        $ids = [];

        foreach ($this->contacts->findForUser($context->user, $filter['text'], $filter['tag'], $sort, $limit, $position) as $contact) {
            $ids[] = (string) $contact->id;
        }

        return [
            'accountId' => (string) $account->id,
            'ids' => $ids,
            'position' => $position,
            // A tag filter is applied after the page is read, so no honest
            // total exists for it; clients stop on an empty page instead.
            'total' => null === $filter['tag'] ? $this->contacts->countForUser($context->user, $filter['text']) : null,
        ];
    }

    /**
     * @return array{text: ?string, tag: ?string}
     */
    private function resolveFilter(mixed $filter): array
    {
        if (null === $filter) {
            return ['text' => null, 'tag' => null];
        }

        if (false === is_array($filter)) {
            throw new MethodException('invalidArguments', '"filter" must be an object.');
        }

        foreach (array_keys($filter) as $key) {
            if (false === in_array((string) $key, self::ACCEPTED_FILTER_KEYS, true)) {
                throw new MethodException('invalidArguments', sprintf(
                    '"%s" is not a Contact/query filter. It takes: %s.',
                    $key,
                    implode(', ', self::ACCEPTED_FILTER_KEYS),
                ));
            }
        }

        $text = $filter['text'] ?? null;

        if (null !== $text && false === is_string($text)) {
            throw new MethodException('invalidArguments', '"filter.text" must be a string.');
        }

        $text = null === $text ? null : trim($text);

        if ('' === $text) {
            $text = null;
        }

        $tag = $filter['tag'] ?? null;

        if (null !== $tag && false === is_string($tag)) {
            throw new MethodException('invalidArguments', '"filter.tag" must be a string.');
        }

        $tag = null === $tag ? null : trim($tag);

        if ('' === $tag) {
            $tag = null;
        }

        return ['text' => $text, 'tag' => $tag];
    }

    private function resolveSort(mixed $sort): string
    {
        if (null === $sort) {
            return 'name';
        }

        if (false === is_string($sort) || false === in_array($sort, self::ACCEPTED_SORTS, true)) {
            throw new MethodException('invalidArguments', sprintf(
                '"sort" must be one of: %s.',
                implode(', ', self::ACCEPTED_SORTS),
            ));
        }

        return $sort;
    }

    private function resolvePosition(mixed $position): int
    {
        if (null === $position) {
            return 0;
        }

        if (false === is_int($position) || $position < 0) {
            throw new MethodException('invalidArguments', '"position" must be an integer of at least 0.');
        }

        return $position;
    }

    /**
     * Capped rather than refused — RFC 8620 §5.5 gives the server the limit
     * as a maximum rather than an instruction, and the other query methods
     * here read it the same way.
     */
    private function resolveLimit(mixed $limit): int
    {
        if (null === $limit) {
            return self::DEFAULT_LIMIT;
        }

        if (false === is_int($limit) || $limit < 1) {
            throw new MethodException('invalidArguments', sprintf(
                '"limit" must be an integer of at least 1, or null for %d.',
                self::DEFAULT_LIMIT,
            ));
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function rejectUnknownArguments(array $arguments): void
    {
        foreach (array_keys($arguments) as $argument) {
            if (true === in_array((string) $argument, self::ACCEPTED_ARGUMENTS, true)) {
                continue;
            }

            throw new MethodException('invalidArguments', sprintf(
                '"%s" is not an argument of Contact/query. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }
}
