<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * One piece of work running on an ImapLoop, and how it ended.
 */
final class ImapTask
{
    public private(set) bool $isFinished = false;

    public private(set) ?\Throwable $failure = null;

    private mixed $value = null;

    /** What the work returned, or what it threw, thrown again. */
    public function result(): mixed
    {
        if (false === $this->isFinished) {
            throw new \LogicException('The task has not finished.');
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->value;
    }

    /** @internal ImapLoop's to call */
    public function succeed(mixed $value): void
    {
        $this->isFinished = true;
        $this->value      = $value;
    }

    /** @internal ImapLoop's to call */
    public function fail(\Throwable $failure): void
    {
        $this->isFinished = true;
        $this->failure    = $failure;
    }
}
