<?php

declare(strict_types=1);

namespace App\Jmap\Method\Template;

use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;

/**
 * What the Template and TemplateFolder methods all do with their arguments.
 *
 * A trait rather than a base class because the four get/set methods share
 * argument handling and nothing else — each has its own dependencies and its
 * own object — and JmapMethod is an interface every method implements directly.
 */
trait TemplateMethodSupport
{
    /**
     * NO accountId, like Appearance/get and for the same reason: templates
     * belong to the authenticated user, not to a connected mail account. Which
     * account a template is filed under is a property of the template. An
     * accountId sent anyway is refused rather than ignored, so a client that
     * thought it was listing one account's templates learns otherwise at the
     * first call.
     *
     * @param array<string, mixed> $arguments
     */
    private function refuseAccountId(array $arguments, string $type): void
    {
        if (true === array_key_exists('accountId', $arguments) && null !== $arguments['accountId']) {
            throw new MethodException('invalidArguments', sprintf(
                '%s objects are per user, not per account; "accountId" is not accepted as an argument. It is a property of the object.',
                $type,
            ));
        }
    }

    /**
     * @return list<string>|null null for "every object"
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
     * @param list<string> $known
     *
     * @return list<string>|null null for "every property"
     */
    private function requestedProperties(mixed $properties, array $known, string $type): ?array
    {
        if (null === $properties) {
            return null;
        }

        if (false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $wanted = ['id'];

        foreach ($properties as $property) {
            $property = (string) $property;

            // Named rather than dropped, as Appearance/get does: a client
            // asking for a property this object does not have is working from
            // a different idea of it.
            if (false === in_array($property, $known, true)) {
                throw new MethodException('invalidArguments', sprintf(
                    '"%s" is not a %s property. Use one of: %s.',
                    $property,
                    $type,
                    implode(', ', $known),
                ));
            }

            $wanted[] = $property;
        }

        return $wanted;
    }

    /**
     * The /get response for a list that is small enough to map whole.
     *
     * @param list<array<string, mixed>> $objects    every object the user has
     * @param list<string>|null          $ids
     * @param list<string>|null          $properties
     *
     * @return array{list: list<array<string, mixed>>, notFound: list<string>}
     */
    private function select(array $objects, ?array $ids, ?array $properties): array
    {
        $byId = array_column($objects, null, 'id');
        $list = [];
        $notFound = [];

        foreach ($ids ?? array_keys($byId) as $id) {
            $object = $byId[$id] ?? null;

            if (null === $object) {
                $notFound[] = (string) $id;

                continue;
            }

            $list[] = null === $properties ? $object : array_intersect_key($object, array_flip($properties));
        }

        return ['list' => $list, 'notFound' => $notFound];
    }

    /**
     * Refuse any property outside the ones a create or update may carry.
     *
     * @param array<string, mixed> $properties
     * @param list<string>         $allowed
     */
    private function rejectUnsupported(array $properties, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($properties)), $allowed));

        if ([] !== $unknown) {
            throw new MethodException('invalidProperties', sprintf(
                'Not settable here: %s. Settable: %s.',
                implode(', ', $unknown),
                implode(', ', $allowed),
            ));
        }
    }

    /** A single line of text: no markup, no line breaks, no longer than the column. */
    private function line(mixed $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_scalar($value) ? (string) $value : '')));

        return mb_substr($text, 0, 255);
    }

    /**
     * An empty JMAP map has to serialise as {} rather than [].
     *
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>|\stdClass
     */
    private function map(array $map): array|\stdClass
    {
        return [] === $map ? new \stdClass() : $map;
    }
}
