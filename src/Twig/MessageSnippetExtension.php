<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Mail\MessageSnippet;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `mail_snippet(message)` for the list rows.
 *
 * A pass-through to the service, which carries the reasoning. It exists so the
 * two places that draw a row — the threaded one and the message one — cannot
 * end up with two different ideas of what a preview is, which is how one of
 * them came to be showing raw markup while the other showed a sender's
 * "this mail is only available in HTML" boilerplate.
 */
final class MessageSnippetExtension extends AbstractExtension
{
    public function __construct(private readonly MessageSnippet $snippet)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mail_snippet', $this->snippet->of(...)),
        ];
    }
}
