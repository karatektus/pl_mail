<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Exception\SendFailureException;
use App\Entity\Mail\Account;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Gmail's own SMTP submission, signed in with the account's OAuth token, for
 * the mail Gmail's API would change on the way out (#651).
 *
 * Gmail's messages.send does not deliver the message it is given: it parses
 * it and writes a new one. For ordinary mail that costs nothing anyone sees.
 * For OpenPGP it breaks the message — measured on 2026-11-16, a PGP/MIME mail
 * from plMail left as multipart/encrypted and arrived as multipart/mixed, the
 * ciphertext turned into a text/plain part, the "Version: 1" part into a
 * base64 attachment, and the Message-ID replaced. A program that reads
 * PGP/MIME then has nothing to open. A detached signature fares no better: it
 * covers the exact bytes of the entity it signs, and those bytes are what
 * Gmail rewrites. Only the copy Gmail files in Sent keeps the original, which
 * is why the sender sees nothing wrong.
 *
 * This is Google's bug, known since November 2020 (their tracker 171715824;
 * FlowCrypt, lieer and Mailvelope each ran into it) and not fixed. FlowCrypt
 * and Mailvelope answered it by sending inline PGP only; plMail does not,
 * because the other end expects PGP/MIME and a program that reads only that
 * would still have nothing to open. SMTP delivers what it is handed, which is
 * also how these accounts sent encrypted mail before they moved to OAuth.
 *
 * Only for the mail that needs it — see GmailApiSender::deliver(). Ordinary
 * mail stays on the API, which is where the quota pacing and the "is it
 * already in Sent?" lookup live. Gmail files an SMTP send in Sent as well, so
 * filesSentCopy() stays true either way and that lookup still finds it.
 *
 * Port 465 with TLS from the first byte, like Gmail's own setup instructions,
 * and XOAUTH2 as the only mechanism. Symfony's default order tries PLAIN and
 * LOGIN first, which would hand the access token over as a password and be
 * refused before every send, a failed login on the account each time.
 */
class GmailSmtpDelivery
{
    public const string HOST = 'smtp.gmail.com';
    public const int    PORT = 465;

    public function __construct(
        private readonly OAuthTokenManager $tokens,
        private readonly SmtpMailSender    $smtp,
    ) {}

    /**
     * @throws SendFailureException classified as any SMTP send is — see
     *                              SmtpMailSender::classify()
     */
    public function deliver(Email $email, Account $account): void
    {
        // Before the try: a token that cannot be refreshed is not an SMTP
        // answer, and must not be read as one. It leaves the way it does on
        // the API path, where GmailApiClient asks for the token the same way.
        $transport = $this->transport($account);

        try {
            $transport->send($email);
        } catch (TransportExceptionInterface $e) {
            throw $this->smtp->classify($account, $e);
        }
    }

    /**
     * The transport a send uses. Public so its settings can be checked without
     * a server to connect to.
     */
    public function transport(Account $account): EsmtpTransport
    {
        $transport = new EsmtpTransport(self::HOST, self::PORT, true);

        // The Google address the account was linked with; OAuthAccountLinker
        // writes it to username, and XOAUTH2 names the user it signs in.
        $transport->setUsername((string) $account->username);
        $transport->setPassword($this->tokens->getValidAccessToken($account));
        $transport->setAuthenticators([new XOAuth2Authenticator()]);

        return $transport;
    }
}
