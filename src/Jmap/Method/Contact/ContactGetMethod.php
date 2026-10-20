<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Contacts\ContactRepository;

/**
 * "Contact/get": resolves contact ids into ContactObjects.
 *
 * Scoped to the owner: somebody else's contact is notFound rather than
 * forbidden — the two are distinguishable to a client, and only one of them
 * confirms that the id exists.
 */
final class ContactGetMethod implements JmapMethod
{
    /**
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId', 'ids', 'properties'];

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactRepository $contacts,
        private readonly ContactObjectMapper $mapper,
    ) {
    }

    public function name(): string
    {
        return 'Contact/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $ids = $this->requireIds($arguments['ids'] ?? null);
        $properties = $this->resolveProperties($arguments['properties'] ?? null);

        $intIds = [];

        foreach ($ids as $id) {
            if (true === ctype_digit($id)) {
                $intIds[] = (int) $id;
            }
        }

        $byId = [];

        if ([] !== $intIds) {
            foreach ($this->contacts->findBy(['id' => $intIds, 'usr' => $context->user]) as $contact) {
                $byId[(string) $contact->id] = $contact;
            }
        }

        $list = [];
        $notFound = [];

        foreach ($ids as $id) {
            if (false === isset($byId[$id])) {
                $notFound[] = $id;

                continue;
            }

            $list[] = $this->mapper->filterProperties($this->mapper->toArray($byId[$id]), $properties);
        }

        return [
            'accountId' => (string) $account->id,
            'list' => $list,
            'notFound' => $notFound,
        ];
    }

    /**
     * @return list<string>
     */
    private function requireIds(mixed $ids): array
    {
        if (false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" is required and must be an array of contact id strings.');
        }

        $result = [];

        foreach (array_values($ids) as $id) {
            if (false === is_string($id) && false === is_int($id)) {
                throw new MethodException('invalidArguments', 'Each "ids" entry must be a contact id string.');
            }

            $result[] = (string) $id;
        }

        return $result;
    }

    /**
     * @return list<string>|null
     */
    private function resolveProperties(mixed $properties): ?array
    {
        if (null === $properties) {
            return null;
        }

        if (false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array of property names.');
        }

        return array_values($properties);
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
                '"%s" is not an argument of Contact/get. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }
}
