<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Enum\Mail\ReadReceiptMode;

/**
 * What to do about the read receipt this message asked for, and why.
 *
 * The "why" is carried rather than discarded because the two consumers need
 * different halves of it. ReadReceiptSender needs $mode and $notifyTo; the
 * message view needs $downgraded so it can say *why* it is asking a user who
 * chose "always" — a prompt appearing in a mailbox set to answer
 * automatically, with no explanation, reads as a bug rather than as the guard
 * it is.
 */
final readonly class ReadReceiptDecision
{
    /**
     * @param ReadReceiptMode $mode       what will actually happen, after any downgrade
     * @param string|null     $notifyTo   the address the MDN goes to, or null when nothing is sent
     * @param string|null     $finalRecipient the address the receipt reports as having read the mail
     * @param bool            $downgraded whether the stored setting said Always and the
     *                                    sender/notify-to mismatch pulled it back to Ask
     */
    public function __construct(
        public ReadReceiptMode $mode,
        public ?string         $notifyTo = null,
        public ?string         $finalRecipient = null,
        public bool            $downgraded = false,
    ) {
    }

    /**
     * Nothing to do, for any of the reasons there are: no request on the
     * message, no address to answer, or a mailbox set to Never.
     */
    public static function silent(): self
    {
        return new self(ReadReceiptMode::Never);
    }

    /** Whether an MDN may be produced at all — automatically or on a click. */
    public function isSendable(): bool
    {
        return ReadReceiptMode::Never !== $this->mode && null !== $this->notifyTo;
    }

    /** Whether the message view should show the confirm/decline prompt. */
    public function needsPrompt(): bool
    {
        return ReadReceiptMode::Ask === $this->mode && null !== $this->notifyTo;
    }

    /** Whether a read transition should fire the receipt without asking. */
    public function isAutomatic(): bool
    {
        return $this->mode->isAutomatic() && null !== $this->notifyTo;
    }
}
