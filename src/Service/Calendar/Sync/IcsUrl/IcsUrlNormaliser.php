<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\IcsUrl;

use App\Domain\Exception\IntegrationException;
use App\Service\Integration\IntegrationUrlValidator;

/**
 * The one place a calendar feed address is turned into something plMail is
 * allowed to fetch.
 *
 * Two jobs, and they are here together because doing either without the other
 * is a bug. Rewriting `webcal://` without validating leaves an SSRF hole;
 * validating without rewriting refuses every address a user will actually
 * paste, because `webcal://` is what "Subscribe" copies to the clipboard in
 * Google Calendar, Outlook, Fastmail and every fixture-list site.
 *
 * ── webcal is http under another name ─────────────────────────────────────
 *
 * Apple registered the scheme; no client has ever spoken a protocol by that
 * name. A `webcal://` URL is fetched by replacing the scheme and doing an
 * ordinary GET, which is what this does — and it maps to **https**, not to the
 * http the original 1990s note implied. Two reasons, in order: every publisher
 * that still offers webcal today also serves the same path over TLS, and
 * mapping to http would hand the address to IntegrationUrlValidator's
 * plaintext refusal, so a user pasting the link their calendar gave them would
 * be told to ask their administrator to allow plain http. `webcals://` exists
 * in the wild too and means the same thing.
 *
 * Rewriting only the scheme, by length, rather than with a regular expression
 * over the whole URL: a feed address routinely carries a query string with its
 * own `://` inside a percent-encoded redirect parameter, and a pattern that
 * matched anywhere would rewrite that instead.
 *
 * ── The SSRF check is not a second one ────────────────────────────────────
 *
 * IntegrationUrlValidator::assertAllowed() is called, never reimplemented. Its
 * blocked ranges, its allow-list, its INTEGRATIONS_ALLOW_HTTP flag and its
 * refusal of credentials in the URL are exactly the rules a feed needs, for
 * exactly the reason it wrote them: this address comes from a user, and it is
 * fetched server-side on a schedule from a container that can reach Postgres,
 * Mercure and the workers. Its documented gap — a hostname that resolves into a
 * private range at request time — is inherited here rather than closed, because
 * closing it needs the resolved IP pinned into the HTTP client and Symfony's
 * client does not expose that. IcsFeedClient re-validates every redirect
 * target through the same method, which is the half of the surface that *is*
 * closeable and that following redirects automatically would have opened.
 */
final readonly class IcsUrlNormaliser
{
    /**
     * The schemes that are http(s) wearing a calendar's clothes, and what each
     * becomes.
     *
     * @var array<string,string>
     */
    private const array ALIASES = [
        'webcal'  => 'https',
        'webcals' => 'https',
    ];

    public function __construct(
        private IntegrationUrlValidator $urls,
    ) {
    }

    /**
     * The address to fetch, refused outright if a user must not be able to
     * reach it.
     *
     * @throws IntegrationException when the URL is malformed, uses a scheme
     *                              this cannot fetch, or points inside the
     *                              deployment's own network
     */
    public function normalise(string $url): string
    {
        $normalised = $this->rewriteScheme(trim($url));

        $this->urls->assertAllowed($normalised);

        return $normalised;
    }

    /**
     * Whether an address is one this can fetch, without saying why not.
     *
     * For the form's own validation, where the question is whether to render a
     * field error rather than what to put in it — the message the exception
     * carries is written for a person and is shown by the caller that catches
     * it.
     */
    public function isFetchable(string $url): bool
    {
        try {
            $this->normalise($url);

            return true;
        } catch (IntegrationException) {
            return false;
        }
    }

    /**
     * What to call a feed that has not named itself.
     *
     * The file's own name without its extension, falling back to the host, and
     * to a bare word when the URL has neither. All three are addresses rather
     * than names, which is why every caller prefers X-WR-CALNAME — but
     * "feiertage-deutschland" is something a person picks out of a sidebar and
     * "Calendar" is not.
     *
     * Here rather than in the driver, although the driver is the only place
     * that reads a feed's own name, because the connector needs the same answer
     * *before* anything has been fetched: an Integration is unique on
     * (user, provider, name) and one called after its provider would make a
     * second feed a constraint violation. Two spellings of "what is this feed
     * called" is how the connection ends up named differently from the calendar
     * it produced.
     */
    public function suggestedName(string $url): string
    {
        $path    = (string) parse_url($url, PHP_URL_PATH);
        $segment = rawurldecode(basename($path));
        $name    = trim((string) preg_replace('/\.ics$/i', '', $segment));

        if ('' !== $name) {
            return mb_substr($name, 0, 100);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return '' === $host ? 'Calendar feed' : mb_substr($host, 0, 100);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The same URL with a webcal scheme replaced, and untouched otherwise.
     *
     * Anything that is not one of the aliases is returned as it arrived,
     * including a scheme this cannot fetch at all — `ftp://` and a bare
     * `example.com/feed.ics` both come back unchanged and are refused by the
     * validator, which already has a sentence for each. Guessing a scheme onto
     * a bare host would mean deciding on the user's behalf which of two
     * addresses they meant.
     */
    private function rewriteScheme(string $url): string
    {
        foreach (self::ALIASES as $alias => $scheme) {
            $prefix = $alias . '://';

            if (true === str_starts_with(mb_strtolower($url), $prefix)) {
                return $scheme . '://' . mb_substr($url, mb_strlen($prefix));
            }
        }

        return $url;
    }
}
