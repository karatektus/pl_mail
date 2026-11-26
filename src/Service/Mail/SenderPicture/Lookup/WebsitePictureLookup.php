<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture\Lookup;

use App\Domain\DTO\Mail\FetchedPicture;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Exception\SenderPictureUnavailableException;
use App\Domain\Helper\PersonalMailDomains;
use App\Domain\Interface\SenderPictureLookupInterface;
use App\Service\Mail\SenderPicture\SenderPictureHttp;
use App\Service\Mail\SenderPicture\SenderPictureImage;

/**
 * The icon a sender's website declares for itself (#639) — the last thing
 * tried before initials, and the one most mail has.
 *
 * **What is asked for.** The homepage, for the `<link rel="…icon…">` elements
 * in its head, best first: an apple-touch-icon (a 180-pixel square made for
 * exactly this — a picture on a home screen), then whatever icon declares the
 * largest size, an SVG counting as large. Then `/apple-touch-icon.png`, which
 * plenty of sites serve without declaring it.
 *
 * **What is not.** `/favicon.ico`, and any icon in the ICO format: GD cannot
 * read it, and the 16-pixel picture inside one would be a smudge scaled up to
 * a 36-pixel circle anyway. An icon smaller than SenderPictureImage::MIN_EDGE
 * is passed over for the same reason. A site that offers nothing better gets
 * initials, which are clearer than a blur.
 *
 * **Not for personal mail providers** (PersonalMailDomains). The website
 * behind a Gmail address is Google's, and its logo on every row from a person
 * with a Gmail address would say the opposite of what the row means.
 */
final readonly class WebsitePictureLookup implements SenderPictureLookupInterface
{
    private const int MAX_HTML_BYTES = 512 * 1024;

    private const int MAX_ICON_BYTES = 512 * 1024;

    /** At most this many icons are fetched before giving up on a site. */
    private const int MAX_CANDIDATES = 4;

    public function __construct(
        private SenderPictureHttp  $http,
        private SenderPictureImage $image,
    ) {}

    public function kind(): SenderPictureKind
    {
        return SenderPictureKind::Website;
    }

    public function lookup(string $subject): ?FetchedPicture
    {
        if (true === PersonalMailDomains::contains($subject)) {
            return null;
        }

        $homepage   = sprintf('https://%s/', $subject);
        $candidates = [];
        $unanswered = null;

        try {
            $page = $this->http->get($homepage, ['text/html', 'application/xhtml+xml'], self::MAX_HTML_BYTES);

            if (null !== $page) {
                $candidates = self::declaredIcons($page['body'], $page['url']);
                $homepage   = $page['url'];
            }
        } catch (SenderPictureUnavailableException $exception) {
            // Kept for later: the fallback below may still answer, and only
            // if it does not is this "nobody answered" rather than "no icon".
            $unanswered = $exception;
        }

        $candidates[] = SenderPictureHttp::resolveAgainst($homepage, '/apple-touch-icon.png');
        $candidates   = array_slice(array_values(array_unique($candidates)), 0, self::MAX_CANDIDATES);

        foreach ($candidates as $url) {
            try {
                $icon = $this->http->get($url, ['image/'], self::MAX_ICON_BYTES);
            } catch (SenderPictureUnavailableException $exception) {
                $unanswered ??= $exception;

                continue;
            }

            if (null !== $icon && true === $this->image->isUsable($icon['body'], $icon['contentType'])) {
                return new FetchedPicture($icon['body'], $icon['contentType'], $url);
            }
        }

        if (null !== $unanswered) {
            throw $unanswered;
        }

        return null;
    }

    /**
     * The icons a page's head declares, best first, as absolute URLs.
     *
     * @return list<string>
     */
    public static function declaredIcons(string $html, string $pageUrl): array
    {
        $head = stristr($html, '</head>', true);
        $head = false === $head ? substr($html, 0, 65536) : $head;

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $head, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $scored = [];

        foreach ($document->getElementsByTagName('link') as $link) {
            $rel  = ' ' . strtolower(trim((string) $link->getAttribute('rel'))) . ' ';
            $href = trim((string) $link->getAttribute('href'));

            if ('' === $href || false === str_contains($rel, 'icon') || true === str_starts_with(strtolower($href), 'data:')) {
                continue;
            }

            $type = strtolower(trim((string) $link->getAttribute('type')));
            $path = strtolower((string) parse_url($href, PHP_URL_PATH));

            if ('image/x-icon' === $type || 'image/vnd.microsoft.icon' === $type || true === str_ends_with($path, '.ico')) {
                continue;
            }

            $scored[] = [
                'url'   => SenderPictureHttp::resolveAgainst($pageUrl, $href),
                'score' => self::score($rel, strtolower((string) $link->getAttribute('sizes')), $type, $path),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_unique(array_column($scored, 'url')));
    }

    /**
     * How good a declared icon is likely to be, from what the markup says. An
     * apple-touch-icon outranks everything — it is a large square by
     * definition — then size, with an SVG counting as large.
     */
    private static function score(string $rel, string $sizes, string $type, string $path): int
    {
        $score = 0;

        if (true === str_contains($rel, 'apple-touch-icon')) {
            $score += 10000;
        }

        if ('image/svg+xml' === $type || true === str_ends_with($path, '.svg') || true === str_contains($sizes, 'any')) {
            return $score + 512;
        }

        // `sizes` may list several ("16x16 32x32 48x48"); the largest counts.
        if (0 < (int) preg_match_all('/(\d+)x(\d+)/', $sizes, $matches)) {
            return $score + max(array_map('intval', $matches[1]));
        }

        // Undeclared: probably a 32-pixel favicon, worth trying after anything
        // that says it is bigger.
        return $score + 32;
    }
}
