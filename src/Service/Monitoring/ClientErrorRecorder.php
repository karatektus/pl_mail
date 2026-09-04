<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Entity\Monitoring\ClientError;
use App\Repository\Monitoring\ClientErrorRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a browser's report into a row, or throws it away.
 *
 * THE FILTER IS THE FEATURE, and the reason is that most of what
 * `window.onerror` sees is not ours. Extensions inject scripts into the page
 * and those scripts throw — an ad-attribution metrics script failing inside
 * `requestIdleCallback` is what prompted this to be built, and it had nothing
 * to do with plMail. Log naively and the card fills with other people's bugs,
 * and then nobody reads it, which is the same as not having it.
 *
 * So the browser filters what it can (see assets/client_errors.js) and this
 * refuses the rest. The rule is the same on both sides: a report is ours only
 * if it can be attributed to a script this server sent. An injected script has
 * no URL — Chrome shows it as `VM947` — and a cross-origin one is reported as
 * the literal string `Script error.` with no file, line or message, because the
 * browser will not tell a page about scripts it did not serve. Neither can be
 * acted on and neither is kept.
 */
final readonly class ClientErrorRecorder
{
    /**
     * The message a browser sends instead of details it will not disclose.
     *
     * Nothing can be done with it — no file, no line, no message — and it is
     * always a cross-origin script, which is always somebody else's.
     */
    private const string OPAQUE = 'Script error.';

    public function __construct(
        private ClientErrorRepository  $errors,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Record one report, and say whether it was kept.
     *
     * @param array{
     *     kind?: mixed, message?: mixed, source?: mixed,
     *     line?: mixed, column?: mixed, stack?: mixed, url?: mixed,
     * } $report
     */
    public function record(array $report, string $origin, ?string $userAgent): bool
    {
        $kind    = self::kindOf($report['kind'] ?? null);
        $message = self::text($report['message'] ?? null, ClientError::MAX_MESSAGE_CHARS);

        if (null === $kind || null === $message || self::OPAQUE === $message) {
            return false;
        }

        $source = self::text($report['source'] ?? null, 500);
        $stack  = self::text($report['stack'] ?? null, ClientError::MAX_STACK_CHARS);
        $url    = self::text($report['url'] ?? null, 500);

        // OURS OR NOBODY'S. A script this server sent has a URL on this origin;
        // an extension's has `chrome-extension://`, an injected one has none at
        // all. A CSP report is exempt: the browser generates it about a
        // resource it *refused* to load, so the URL in it is by definition not
        // ours and the report is still entirely about this page's policy.
        if (ClientError::KIND_CSP !== $kind && false === self::isOurs($source, $stack, $origin)) {
            return false;
        }

        $line   = self::positive($report['line'] ?? null);
        $column = self::positive($report['column'] ?? null);

        $fingerprint = sha1(implode("\0", [$kind, $message, (string) $source, (string) $line, (string) $column]));

        // The overwhelmingly common case: a fault that already has a row. One
        // statement, no hydration, and no lost increment when two tabs report
        // the same thing at once.
        if (true === $this->errors->touch($fingerprint, $url, $userAgent)) {
            return true;
        }

        $error              = new ClientError();
        $error->fingerprint = $fingerprint;
        $error->kind        = $kind;
        $error->message     = $message;
        $error->source      = $source;
        $error->line        = $line;
        $error->columnNumber = $column;
        $error->stack       = $stack;
        $error->url         = $url;
        $error->userAgent   = null === $userAgent ? null : mb_substr($userAgent, 0, 255);

        try {
            $this->entityManager->persist($error);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two first sightings of the same fault, racing. The other one won;
            // this one is an increment after all. Not an error worth reporting
            // to anybody — the count is what it would have been either way.
            $this->errors->touch($fingerprint, $url, $userAgent);
        }

        return true;
    }

    /**
     * Whether this report can be attributed to a script this server sent.
     *
     * The source is the reliable half and is checked first. A rejection has no
     * source at all — `unhandledrejection` carries a reason, not a file — so
     * the stack is the only handle, and requiring our origin to appear in it is
     * what keeps an extension's rejected promise out of the card.
     */
    private static function isOurs(?string $source, ?string $stack, string $origin): bool
    {
        if (null !== $source && '' !== $source) {
            return str_starts_with($source, $origin . '/');
        }

        return null !== $stack && str_contains($stack, $origin . '/');
    }

    private static function kindOf(mixed $kind): ?string
    {
        return match ($kind) {
            ClientError::KIND_ERROR     => ClientError::KIND_ERROR,
            ClientError::KIND_REJECTION => ClientError::KIND_REJECTION,
            ClientError::KIND_CSP       => ClientError::KIND_CSP,
            default                     => null,
        };
    }

    /** A non-empty string of at most $limit characters, or null. */
    private static function text(mixed $value, int $limit): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : mb_substr($value, 0, $limit);
    }

    private static function positive(mixed $value): ?int
    {
        return is_int($value) && 0 < $value ? $value : null;
    }
}
