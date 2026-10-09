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
 * Two strengths, because the two places differ in what they can afford:
 *
 *   everywhere   `position: fixed` and `sticky`. Neither means anything in
 *                mail — there is no viewport to be fixed to in a message — so
 *                nothing genuine is lost by dropping them at ingest.
 *
 *   composing    `position: absolute` and `z-index` as well. Absolutely
 *                positioned content with no positioned ancestor is placed
 *                against the page, which in the composer is the app. Some real
 *                newsletters do position absolutely, which is why this half is
 *                not applied to mail that is only being read.
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

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
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
                || (true === $this->composing && true === str_contains($rest, 'absolute'));
        }

        return true === $this->composing && 'z-index' === $property;
    }
}
