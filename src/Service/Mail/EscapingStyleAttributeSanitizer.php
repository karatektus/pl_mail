<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Takes out of a `style` attribute the declarations that let an element leave
 * the place it was put.
 *
 * The sanitiser keeps inline styles, because in mail they ARE the design. In
 * the reading pane that costs nothing: the message is in a sandboxed frame and
 * can only rearrange itself. But a message is also shown in the app's own
 * document — quoted in the reply composer, and on the print page — and there
 * `position: fixed` is not a layout choice, it is a way to draw over plMail: a
 * mail could cover the composer with a page of its own the moment somebody
 * pressed Reply (issue #34).
 *
 * `position: fixed`, `sticky` and `absolute` are dropped everywhere. Fixed and
 * sticky mean nothing in mail — there is no viewport to be fixed to in a
 * message. Absolute is dropped because without a positioned ancestor it is placed
 * against the page, which in the composer is the app, and because in the reading
 * pane it does the opposite of what the sender meant: generators that lay a mail
 * out like a print page (Scriptura, for one) put the header banner in an
 * absolutely positioned box, expecting an engine that reserves its space, and
 * the text underneath then runs under the picture (issue #626). Gmail drops
 * `position` too, so the banner lands above the text there.
 *
 * `z-index` is dropped as well where the message sits in the app's own document
 * (`composing`): the reading pane is a sandboxed frame and it can only stack the
 * message's own elements.
 *
 * This is one of two layers. The other is in the stylesheets: the composer's
 * editor and the print page's body are containing blocks that clip, so a
 * declaration that got past this — in a body stored before it existed, or
 * written some way this pattern does not anticipate — still cannot reach
 * outside its own box.
 */
final readonly class EscapingStyleAttributeSanitizer implements AttributeSanitizerInterface
{
    public function __construct(
        private bool $composing = false,
    ) {
    }

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            if ('' === trim($declaration) || true === $this->escapes($declaration)) {
                continue;
            }

            $kept[] = trim($declaration);
        }

        return implode('; ', $kept);
    }

    private function escapes(string $declaration): bool
    {
        [$property, $rest] = array_pad(explode(':', $declaration, 2), 2, '');

        // Compared without comments, whitespace or case: `pos/**/ition` and
        // `POSITION : Fixed` are the same declaration to a browser.
        $property = strtolower((string) preg_replace('~/\*.*?\*/|\s+~s', '', $property));
        $rest     = strtolower((string) preg_replace('~/\*.*?\*/|\s+~s', '', $rest));

        if ('position' === $property) {
            return true === str_contains($rest, 'fixed')
                || true === str_contains($rest, 'sticky')
                || true === str_contains($rest, 'absolute');
        }

        return true === $this->composing && 'z-index' === $property;
    }
}
