<?php

declare(strict_types=1);

namespace App\Jmap\Method\ContactCard;

use App\Domain\DTO\Contacts\ContactDelta;
use App\Jmap\Protocol\Exception\MethodException;

/** The arguments and the answer the two /changes methods here share (RFC 8620 §5.2). */
final class ChangesArguments
{
    /** @param array<string, mixed> $arguments */
    public static function sinceState(array $arguments): string
    {
        $sinceState = $arguments['sinceState'] ?? null;

        if (false === is_string($sinceState)) {
            throw new MethodException('invalidArguments', '"sinceState" is required.');
        }

        return $sinceState;
    }

    /** @param array<string, mixed> $arguments */
    public static function maxChanges(array $arguments): ?int
    {
        $maxChanges = $arguments['maxChanges'] ?? null;

        if (null !== $maxChanges && false === is_int($maxChanges)) {
            throw new MethodException('invalidArguments', '"maxChanges" must be a number.');
        }

        return $maxChanges;
    }

    /** @return array<string, mixed> */
    public static function response(string $accountId, ContactDelta $delta): array
    {
        return [
            'accountId'      => $accountId,
            'oldState'       => $delta->oldState,
            'newState'       => $delta->newState,
            'hasMoreChanges' => $delta->hasMoreChanges,
            'created'        => $delta->created,
            'updated'        => $delta->updated,
            'destroyed'      => $delta->destroyed,
        ];
    }
}
