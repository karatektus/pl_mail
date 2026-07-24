<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Account;

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
        $rawEncryption = $account->getSmtpEncryption();

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

        $port = $account->getSmtpPort();

        if (null === $port) {
            $port = 587;
        }

        return sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            urlencode($account->getUsername()),
            urlencode($account->getPassword()),
            $account->getSmtpHost(),
            $port,
        );
    }

    /**
     * Strips the credentials out of any string that may embed the DSN —
     * transport exceptions frequently echo it back verbatim.
     */
    public function redact(string $text, Account $account): string
    {
        $password = (string) $account->getPassword();

        if ('' === $password) {
            return $text;
        }

        return str_replace(
            [$password, urlencode($password)],
            '***',
            $text,
        );
    }
}
