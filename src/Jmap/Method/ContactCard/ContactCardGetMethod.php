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
 * "ContactCard/get" (RFC 9610 §3.1). A contact in the bin is notFound: to a
 * JMAP client it was destroyed when it went there.
 */
final class ContactCardGetMethod implements JmapMethod
{
    /** The Session's maxObjectsInGet, which is the limit a client chunks by. */
    private const int MAX_OBJECTS = 500;

    public function __construct(
        private readonly ContactAccountResolver $accounts,
        private readonly ContactRepository $contacts,
        private readonly ContactCards $cards,
        private readonly ContactChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'ContactCard/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account    = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $ids        = $arguments['ids'] ?? null;
        $properties = $arguments['properties'] ?? null;

        if (null !== $ids && false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        if (null !== $properties && false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        // Read before the cards, so a change landing in between is one the
        // client is told about again rather than one it never hears of.
        $state = $this->changes->cardState((int) $context->user->id);
        $ids ??= array_map(strval(...), $this->contacts->findIdsForCards($context->user, null));

        if (count($ids) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per ContactCard/get.', self::MAX_OBJECTS));
        }

        $list     = [];
        $notFound = [];

        foreach ($ids as $id) {
            $id      = $context->resolveId((string) $id) ?? (string) $id;
            $contact = $this->contacts->findOneCardForUser($context->user, $id);

            if (null === $contact) {
                $notFound[] = $id;

                continue;
            }

            $card   = $this->cards->card($contact);
            $list[] = null === $properties ? $card : array_intersect_key($card, array_flip([...array_map('strval', $properties), 'id']));
        }

        return [
            'accountId' => (string) $account->id,
            'state'     => $state,
            'list'      => $list,
            'notFound'  => $notFound,
        ];
    }
}
