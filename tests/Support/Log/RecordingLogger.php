<?php

declare(strict_types=1);

namespace App\Tests\Support\Log;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that keeps what it was told, for the cases where a log line is the
 * only trace a decision leaves.
 *
 * Reached for sparingly. Asserting on log text couples a test to wording, which
 * is why the helper below matches a fragment rather than a whole message — but
 * some collaborators are final classes with void methods, and then the log is
 * genuinely the only observable evidence that a call was made at all. Preferring
 * a weaker assertion to no assertion is the trade being made here.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** Case-insensitive, so a capitalisation change is not a failing test. */
    public function sawMessageContaining(string $fragment): bool
    {
        foreach ($this->records as $record) {
            if (false !== stripos($record['message'], $fragment)) {
                return true;
            }
        }

        return false;
    }
}
