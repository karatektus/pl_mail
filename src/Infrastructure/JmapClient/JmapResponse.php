<?php

declare(strict_types=1);

namespace App\Infrastructure\JmapClient;

use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapResyncRequiredException;

/**
 * The method responses to one JMAP request, looked up by call id.
 *
 * A request's calls are answered in order, but an answer is not always one
 * invocation: a method that fails answers `error` in place of its own name,
 * and an implicit call (EmailSubmission/set's onSuccessUpdateEmail runs an
 * Email/set) answers under the same call id as the one that caused it. So the
 * lookup is by id and by name, and an `error` under the id asked for is thrown
 * there and then — the caller asked for a result, and a refusal is not one.
 */
final readonly class JmapResponse
{
    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $invocations
     */
    public function __construct(
        public array  $invocations,
        public string $sessionState,
    ) {
    }

    /**
     * The arguments `name` answered under `callId`.
     *
     * @return array<string, mixed>
     *
     * @throws JmapResyncRequiredException when the server cannot calculate changes
     * @throws JmapApiException            when the method was refused, or not answered at all
     */
    public function get(string $callId, string $name): array
    {
        foreach ($this->invocations as [$answered, $arguments, $id]) {
            if ($id !== $callId) {
                continue;
            }

            if ('error' === $answered) {
                throw self::methodError($name, $arguments);
            }

            if ($answered === $name) {
                return $arguments;
            }
        }

        throw new JmapApiException(sprintf('JMAP server did not answer %s (%s).', $name, $callId));
    }

    /**
     * The method error under `callId`, if that is what the server answered.
     *
     * For the few callers that act on a refusal rather than abandoning the
     * request over it — a Mailbox/set that names a mailbox somebody deleted
     * on another client is not a reason to stop the rest of the batch.
     *
     * @return array<string, mixed>|null
     */
    public function errorFor(string $callId): ?array
    {
        foreach ($this->invocations as [$answered, $arguments, $id]) {
            if ($id === $callId && 'error' === $answered) {
                return $arguments;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function methodError(string $name, array $arguments): JmapApiException
    {
        $type        = is_string($arguments['type'] ?? null) ? $arguments['type'] : 'serverFail';
        $description = is_string($arguments['description'] ?? null) ? ': ' . $arguments['description'] : '';
        $message     = sprintf('JMAP %s refused (%s)%s', $name, $type, $description);

        if ('cannotCalculateChanges' === $type) {
            return new JmapResyncRequiredException($message);
        }

        return new JmapApiException($message, 0, $type);
    }
}
