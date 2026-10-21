<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Enum\Mail\SendFailure;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

/**
 * The mail did not leave, and another attempt may get it out.
 *
 * Recoverable, with the delay the provider asked for when it named one
 * (Retry-After) and the export transport's own ladder when it did not.
 * forceRetry() is false: the transport's max_retries still bounds it, and
 * SendMessageHandler bounds the whole wait to two minutes on top of that,
 * because a person is waiting on a send in a way nobody waits on a sync.
 */
final class SendDeferredException extends SendFailureException implements RecoverableExceptionInterface
{
    public function __construct(
        string $message,
        SendFailure $failure,
        private readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $failure, $previous);
    }

    public function getRetryDelay(): ?int
    {
        return null === $this->retryAfterSeconds ? null : 1000 * $this->retryAfterSeconds;
    }

    public function forceRetry(): bool
    {
        return false;
    }

    /** The provider's Retry-After, when it gave one. */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /** The same failure, waiting no longer than $seconds — see SendMessageHandler's two-minute budget. */
    public function waitingAtMost(int $seconds): self
    {
        $wait = null === $this->retryAfterSeconds ? null : min($this->retryAfterSeconds, max(1, $seconds));

        return new self($this->getMessage(), $this->failure(), $wait, $this->getPrevious());
    }
}
