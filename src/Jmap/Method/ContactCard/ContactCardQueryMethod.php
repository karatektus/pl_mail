<?php

declare(strict_types=1);

namespace App\Jmap\Method\ContactCard;

use App\Jmap\Account\ContactAccountResolver;
use App\Jmap\Contact\ContactCards;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\Change\ContactChangeReader;

/**
 * "ContactCard/query" (RFC 9610 §3.4), by address book. `inAddressBook` is the
 * one condition, and it is what a syncing client asks; any other, and a sort,
 * are refused rather than ignored, for the reason EmailFilterCompiler gives — a
 * condition quietly dropped returns too much. Searching contacts is plMail's
 * own Contact/autocomplete.
 */
final class ContactCardQueryMethod implements JmapMethod
{
    public function __construct(
        private readonly ContactAccountResolver $accounts,
        private readonly ContactRepository $contacts,
        private readonly ContactChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'ContactCard/query';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $filter  = $arguments['filter'] ?? null;

        if (null !== $filter && false === is_array($filter)) {
            throw new MethodException('invalidArguments', '"filter" must be an object or null.');
        }

        foreach (array_keys($filter ?? []) as $condition) {
            if ('inAddressBook' !== $condition) {
                throw new MethodException('unsupportedFilter', sprintf('Filter condition "%s" is not supported.', $condition));
            }
        }

        if (null !== ($arguments['sort'] ?? null)) {
            throw new MethodException('unsupportedSort', 'ContactCard/query sorts by id.');
        }

        $position = $arguments['position'] ?? 0;
        $limit    = $arguments['limit'] ?? null;

        if (false === is_int($position) || $position < 0 || (null !== $limit && (false === is_int($limit) || $limit < 0))) {
            throw new MethodException('invalidArguments', '"position" and "limit" must be non-negative integers.');
        }

        $book = $filter['inAddressBook'] ?? null;
        $ids  = match (true) {
            null === $book                     => $this->contacts->findIdsForCards($context->user, null),
            ContactCards::LOCAL_BOOK === $book => $this->contacts->findIdsForCards($context->user, 0),
            is_string($book) && ctype_digit($book) && '0' !== $book => $this->contacts->findIdsForCards($context->user, (int) $book),
            default                            => [],
        };

        return [
            'accountId'           => (string) $account->id,
            // The card state: it moves whenever a card does, which is every
            // time this answer could. No queryChanges, so the client re-runs
            // the query, as RFC 8620 allows.
            'queryState'          => $this->changes->cardState((int) $context->user->id),
            'canCalculateChanges' => false,
            'position'            => $position,
            'ids'                 => array_map(strval(...), array_slice($ids, $position, $limit)),
            'total'               => count($ids),
        ];
    }
}
