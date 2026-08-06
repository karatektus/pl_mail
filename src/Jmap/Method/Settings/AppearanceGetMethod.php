<?php

declare(strict_types=1);

namespace App\Jmap\Method\Settings;

use App\Jmap\Mapper\AppearanceMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;

/**
 * "Appearance/get" — plMail extension, `urn:plmail:params:jmap:appearance`.
 *
 * A singleton: one object, id "singleton", modelled on RFC 8621's
 * VacationResponse. There is nothing to enumerate — a user has exactly one
 * appearance, always — so `ids: null` returns it and any other id is notFound.
 *
 * NO accountId, like PushSubscription/get and for the same reason: appearance
 * belongs to the authenticated user, not to a connected mail account. A user
 * with three accounts has one theme, and answering per account would publish
 * the same object three times under three ids for a client to reconcile. An
 * accountId sent anyway is refused rather than ignored — a client that thought
 * it was reading one account's theme has a misunderstanding worth surfacing at
 * the first call rather than the first mismatch.
 */
final class AppearanceGetMethod implements JmapMethod
{
    public function __construct(
        private readonly AppearanceMapper $mapper,
    ) {
    }

    public function name(): string
    {
        return 'Appearance/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        if (true === array_key_exists('accountId', $arguments) && null !== $arguments['accountId']) {
            throw new MethodException(
                'invalidArguments',
                'Appearance is per user, not per account; "accountId" is not accepted.',
            );
        }

        $appearance = $context->user->appearance;
        $object = $this->mapper->toJmap($appearance);

        $properties = $this->requestedProperties($arguments['properties'] ?? null);

        if (null !== $properties) {
            // "id" is always returned, whatever the client asked for
            // (RFC 8620 §5.1).
            $object = array_intersect_key($object, array_flip([...$properties, 'id']));
        }

        $ids = $this->requestedIds($arguments['ids'] ?? null, $context);
        $list = [];
        $notFound = [];

        if (null === $ids) {
            $list[] = $object;
        }

        foreach ($ids ?? [] as $id) {
            if (AppearanceMapper::SINGLETON_ID === $id) {
                $list[] = $object;
                continue;
            }

            $notFound[] = $id;
        }

        return [
            'state' => $this->mapper->state($appearance),
            'list' => $list,
            'notFound' => $notFound,
        ];
    }

    /**
     * @return list<string>|null null for "every object", which here is the one
     */
    private function requestedIds(mixed $ids, JmapContext $context): ?array
    {
        if (null === $ids) {
            return null;
        }

        if (false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        return array_values(array_map(
            static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id,
            $ids,
        ));
    }

    /**
     * @return list<string>|null null for "every property"
     */
    private function requestedProperties(mixed $properties): ?array
    {
        if (null === $properties) {
            return null;
        }

        if (false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $wanted = [];

        foreach ($properties as $property) {
            $property = (string) $property;

            // Named rather than dropped: a client asking for a property this
            // server does not have is working from a different idea of the
            // object, and silently returning the rest hides that behind a
            // response that looks complete.
            if ('id' !== $property && false === in_array($property, AppearanceMapper::PROPERTIES, true)) {
                throw new MethodException('invalidArguments', sprintf(
                    '"%s" is not an Appearance property. Use one of: %s.',
                    $property,
                    implode(', ', AppearanceMapper::PROPERTIES),
                ));
            }

            $wanted[] = $property;
        }

        return $wanted;
    }
}
