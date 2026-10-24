<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Interface\MailDeliveryInterface;
use App\Domain\Interface\SentCopyLookupInterface;
use App\Entity\Mail\Account;
use Symfony\Component\Mime\Email;

/**
 * A sender whose every attempt is scripted: what deliver() throws, or nothing
 * for a send that goes through, and what Sent answers when it is looked in.
 *
 * Lookup-capable, the way Gmail and Graph are. SendRetryTest wraps the same
 * script in a sender that is not, for SMTP's side of a lost answer.
 */
final class ScriptedDeliverySender implements MailDeliveryInterface, SentCopyLookupInterface
{
    /** @var list<string> the Message-ID header of every email deliver() was handed */
    public array $delivered = [];

    /** @var list<string> every Message-ID Sent was searched for */
    public array $lookedFor = [];

    /**
     * @param list<\Throwable|null> $attempts   what each deliver() does, in order; null sends
     * @param \Throwable|bool       $inSent     what isInSent() answers, or throws
     */
    public function __construct(
        private array $attempts = [],
        public \Throwable|bool $inSent = false,
    ) {
    }

    public function supports(Account $account): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        throw new \LogicException('MessageSendService uses deliver() for a sender that has it.');
    }

    public function deliver(Email $email, Account $account): void
    {
        $this->delivered[] = (string) $email->getHeaders()->get('Message-ID')?->getBodyAsString();

        $next = array_shift($this->attempts);

        if (null !== $next) {
            throw $next;
        }
    }

    public function filesSentCopy(): bool
    {
        // As Gmail and Graph do, so no IMAP append is attempted after a send.
        return true;
    }

    public function isInSent(Account $account, string $messageId): bool
    {
        $this->lookedFor[] = $messageId;

        if ($this->inSent instanceof \Throwable) {
            throw $this->inSent;
        }

        return $this->inSent;
    }
}
