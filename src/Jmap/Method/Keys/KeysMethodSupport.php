<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;

/**
 * What the `urn:plmail:params:jmap:keys` methods share: the keyring and the
 * contacts' keys are the person's, not an account's, so their /get methods
 * take no accountId — the same arrangement as Template/get.
 */
trait KeysMethodSupport
{
    /**
     * @param array<string, mixed> $arguments
     */
    private function refuseAccountId(array $arguments, string $type): void
    {
        if (true === array_key_exists('accountId', $arguments) && null !== $arguments['accountId']) {
            throw new MethodException('invalidArguments', sprintf('%s objects are per user, not per account; "accountId" is not accepted.', $type));
        }
    }

    /**
     * @return list<string>|null
     */
    private function requestedIds(mixed $ids, JmapContext $context): ?array
    {
        if (null === $ids) {
            return null;
        }

        if (false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        return array_values(array_map(static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id, $ids));
    }

    /**
     * @param list<array<string, mixed>> $objects
     * @param list<string>|null          $ids
     *
     * @return array{state: string, list: list<array<string, mixed>>, notFound: list<string>}
     */
    private function getResponse(array $objects, ?array $ids): array
    {
        $byId     = array_column($objects, null, 'id');
        $list     = [];
        $notFound = [];

        foreach ($ids ?? array_keys($byId) as $id) {
            if (true === isset($byId[$id])) {
                $list[] = $byId[$id];
            } else {
                $notFound[] = (string) $id;
            }
        }

        return [
            'state'    => substr(hash('xxh128', json_encode($objects, JSON_THROW_ON_ERROR)), 0, 16),
            'list'     => $list,
            'notFound' => $notFound,
        ];
    }
}
