<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\MailServerHost;
use App\Entity\Mail\Account;

/**
 * Single source of truth for the SMTP DSN.
 *
 * Extracted from SmtpMailSender so the connection tester probes the exact
 * transport configuration that a real send would use — a test that builds its
 * own DSN can go green while sending still fails.
 */
final class SmtpDsnFactory
{
    public function forAccount(Account $account): string
    {
        $rawEncryption = $account->smtpEncryption;

        if (null === $rawEncryption) {
            $encryption = 'tls';
        } else {
            $encryption = strtolower($rawEncryption);
        }

        if ('ssl' === $encryption) {
            $scheme = 'smtps';
        } else {
            $scheme = 'smtp';
        }

        $port = $account->smtpPort;

        if (null === $port) {
            $port = 587;
        }

        // Refused rather than encoded: a host is not a value that CAN be
        // percent-encoded into an authority, and one carrying `?verify_peer=0`
        // was otherwise read by the DSN parser as options for this transport.
        if (false === MailServerHost::isValid($account->smtpHost)) {
            throw new \InvalidArgumentException('The SMTP host is not a valid hostname or IP address.');
        }

        // rawurlencode, not urlencode: Dsn::fromString() decodes the userinfo
        // with rawurldecode, so urlencode's `+` for a space came back as a
        // literal `+` and a password with a space in it never authenticated.
        return sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode((string) $account->username),
            rawurlencode((string) $account->password),
            MailServerHost::forAuthority((string) $account->smtpHost),
            $port,
        );
    }

    /**
     * Strips the credentials out of any string that may embed the DSN —
     * transport exceptions frequently echo it back verbatim.
     */
    public function redact(string $text, Account $account): string
    {
        $password = (string) $account->password;

        if ('' === $password) {
            return $text;
        }

        return str_replace(
            [$password, urlencode($password), rawurlencode($password)],
            '***',
            $text,
        );
    }
}
