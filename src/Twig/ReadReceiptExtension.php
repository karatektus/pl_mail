<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Mail\ReadReceiptDecision;
use App\Entity\Mail\Message;
use App\Service\Mail\ReadReceiptPolicy;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `read_receipt_decision(message)` — whether this message wants a prompt, and
 * what the prompt would do.
 *
 * A function rather than a controller variable, for the reason
 * `message_render` is one: the message partial is included from the
 * conversation view, the single-message pane and a Turbo Stream, and threading
 * a per-message decision through all three means the next caller has to
 * remember to. Here forgetting would show the prompt in one place and not
 * another, so the same request looks answered or unanswered depending on how
 * the user got to it.
 *
 * It is also the only way the template can be certain it is asking the same
 * question the send path answers — both go through ReadReceiptPolicy, so a
 * prompt is drawn exactly when a click on it would do something.
 */
final class ReadReceiptExtension extends AbstractExtension
{
    public function __construct(
        private readonly ReadReceiptPolicy $policy,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('read_receipt_decision', $this->decide(...)),
        ];
    }

    public function decide(Message $message): ReadReceiptDecision
    {
        return $this->policy->decide($message);
    }
}
