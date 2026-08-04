<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it was told.
 *
 * Exists for one claim, and it is the claim that makes the conflict rules worth
 * having: a local edit discarded in favour of the remote must leave a trace
 * carrying the discarded object. That is not observable in the database — the
 * whole point is that the row is gone — so the log line is the assertion
 * target, and asserting on it needs a logger a test can read back.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
    public array $records = [];

    /**
     * @param array<string,mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * The records at one level whose message contains $needle.
     *
     * @return list<array{level:string,message:string,context:array<string,mixed>}>
     */
    public function matching(string $level, string $needle): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] === $level
                && true === str_contains($record['message'], $needle),
        ));
    }
}
