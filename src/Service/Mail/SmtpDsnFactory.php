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
        // STARTTLS is required, not merely attempted. Symfony's EsmtpTransport
        // upgrades only when the server advertises STARTTLS and then signs in
        // either way, so someone between plMail and the server who strips the
        // offer from the EHLO reply received the password in clear text. With
        // `require_tls` a server that does not offer STARTTLS fails the send
        // (and the connection test) loudly instead. `ssl` is TLS from the
        // first byte and needs no flag; `none` is the user saying plain text
        // on purpose, for a relay on the same host or network.
        $query = '';

        if ('ssl' !== $encryption && 'none' !== $encryption) {
            $query = '?require_tls=true';
        }

        return sprintf(
            '%s://%s:%s@%s:%d%s',
            $scheme,
            // The sending username and password, which are the account's own
            // unless it has been given separate ones (#660, #42). See
            // Account::$sendingUsername and Account::$sendingPassword.
            rawurlencode((string) $account->sendingUsername),
            rawurlencode((string) $account->sendingPassword),
            MailServerHost::forAuthority((string) $account->smtpHost),
            $port,
            $query,
        );
    }

    /**
     * Strips the credentials out of any string that may embed the DSN —
     * transport exceptions frequently echo it back verbatim.
     */
    public function redact(string $text, Account $account): string
    {
        // Both, where there are two: the DSN carries the sending one, and an
        // IMAP error quoted in the same report may carry the other.
        foreach (array_unique([(string) $account->sendingPassword, (string) $account->password]) as $password) {
            if ('' === $password) {
                continue;
            }

            $text = str_replace(
                [$password, urlencode($password), rawurlencode($password)],
                '***',
                $text,
            );
        }

        return $text;
    }
}
