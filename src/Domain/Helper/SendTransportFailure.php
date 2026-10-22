<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Whether a network failure of an HTTP send happened before the request left.
 *
 * The line that decides whether a failed send may simply be tried again
 * (#125). A connection that never opened — no DNS answer, refused, the TLS
 * handshake failing — sent nothing, and another attempt is safe. Everything
 * after that is not: a timeout or a reset while waiting for the answer may
 * have come after the provider took the mail, and a second attempt would
 * deliver it twice.
 *
 * Read from the message, because that is all Symfony's HttpClient keeps of
 * curl's error: its TransportException carries curl's text and no code. The
 * phrases are curl's own for the connect phase. Anything not recognised here
 * counts as AFTER the request — when in doubt, the answer that cannot send a
 * mail twice.
 */
final class SendTransportFailure
{
    private const string BEFORE_REQUEST = '~could not resolve|couldn\'t resolve|resolving timed out|name or service not known'
        . '|failed to connect|couldn\'t connect|connection refused|network is unreachable|no route to host'
        . '|connection timed out after|ssl connect error|ssl_connect|tls handshake~i';

    public static function beforeRequest(\Throwable $failure): bool
    {
        return 1 === preg_match(self::BEFORE_REQUEST, $failure->getMessage());
    }
}
