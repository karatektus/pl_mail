<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The one relative URL a mail body may keep: our own inline-attachment route.
 *
 * A relative URL in a mail means nothing to its sender — it only has a meaning
 * once the body sits inside plMail, where it resolves against plMail itself.
 * The allow-list admitted every one (allowRelativeLinks, allowRelativeMedias)
 * because resolveCids() writes `/mail/attachment/{id}` for inline images, and
 * the print view puts the body straight into an app page — so a stranger's
 * `<img src="/settings/…">` or `<a href="/admin/…">` became a request, made
 * with the reader's session, to wherever in the app the sender liked.
 *
 * Absolute URLs are not this class's business: the scheme allow-list and the
 * image proxy deal with those. Inline `style` gets the same rule for `url(…)`,
 * because resolveCids() rewrites CSS references too and a background image is
 * a request like any other.
 */
final class RelativeUrlAttributeSanitizer implements AttributeSanitizerInterface
{
    /** What resolveCids() and InlineImageRewriter write, and nothing else. */
    private const string INLINE_ATTACHMENT = '#^/mail/attachment/\d+$#D';

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src', 'href', 'background', 'lowsrc', 'poster', 'style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ('style' === $attribute) {
            return (string) preg_replace_callback(
                '/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
                fn (array $m): string => true === $this->isAllowed(trim($m[2])) ? $m[0] : 'none',
                $value,
            );
        }

        return true === $this->isAllowed(trim($value)) ? $value : null;
    }

    private function isAllowed(string $url): bool
    {
        // A scheme or a host makes it absolute, which is decided elsewhere.
        // `//host/x` counts as absolute: it names a host.
        if (1 === preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) || str_starts_with($url, '//')) {
            return true;
        }

        // A bare fragment stays: `#top` goes nowhere.
        if ('' === $url || str_starts_with($url, '#')) {
            return true;
        }

        return 1 === preg_match(self::INLINE_ATTACHMENT, $url);
    }
}
