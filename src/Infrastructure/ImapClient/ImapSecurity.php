<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * How a connection to an IMAP server is protected.
 */
enum ImapSecurity: string
{
    /** TLS from the first byte, the way port 993 works. */
    case Tls = 'tls';

    /**
     * A plain connection upgraded with STARTTLS before anything else is said.
     *
     * Never downgraded: a server that does not offer STARTTLS on a connection
     * configured for it is refused rather than spoken to in the clear.
     */
    case StartTls = 'starttls';

    /** Nothing. For servers on a network that is trusted already. */
    case None = 'none';

    /**
     * The account's imapEncryption column, read as it always has been: 'ssl'
     * and 'tls' both mean TLS from the start (webklex, which read it before
     * this client, opened either with a TLS socket), 'starttls' means
     * STARTTLS, anything else nothing.
     */
    public static function fromAccountSetting(?string $setting): self
    {
        return match ($setting) {
            'ssl', 'tls' => self::Tls,
            'starttls'   => self::StartTls,
            default      => self::None,
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Tls                  => 993,
            self::StartTls, self::None => 143,
        };
    }
}
