<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * Where an IMAP server is and how to reach it.
 */
final readonly class ImapEndpoint
{
    /**
     * @param bool $verifyCertificate off only in tests, against a server with a
     *                                certificate made up for the occasion; no
     *                                account setting turns it off
     */
    public function __construct(
        public string       $host,
        public int          $port,
        public ImapSecurity $security,
        public bool         $verifyCertificate = true,
    ) {
    }
}
