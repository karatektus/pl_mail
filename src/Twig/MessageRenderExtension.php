<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\Message;
use App\Service\Mail\MessageRender;
use App\Service\Mail\MessageRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `message_render(message)` — the safe, decided form of a message body.
 *
 * A function rather than a controller variable for the same reason
 * `message_invite` is one: the body partial is included from the conversation
 * view, the single-message pane and the print page, and threading a per-message
 * decision through all three means the next caller has to remember to. Here the
 * failure mode of forgetting is not a missing card, it is a page that loads
 * tracking pixels — so it must not be possible to forget.
 */
final class MessageRenderExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageRenderer $renderer,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('message_render', $this->render(...)),
        ];
    }

    public function render(Message $message, bool $forceImages = false): MessageRender
    {
        return $this->renderer->render($message, $forceImages);
    }
}
