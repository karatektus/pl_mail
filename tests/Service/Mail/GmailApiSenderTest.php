<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GmailApiSender;
use App\Service\Mail\GmailSmtpDelivery;
use App\Service\Mail\Mime\SealedEntityPart;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Email;

/**
 * A message the browser built leaves a Google account over SMTP, never through
 * the Gmail API (#651).
 *
 * messages.send rewrites what it is given: a PGP/MIME mail went in as
 * multipart/encrypted and arrived as multipart/mixed with the ciphertext as
 * plain text, so the recipient could not open it. The Sent copy kept the
 * original, which is why the sender saw nothing wrong.
 *
 * With doubles for both ways out, because the claim is which one is taken:
 * the HTTP client counts the API calls, and the SMTP delivery is a mock that
 * counts its own. GmailSmtpDeliveryTest covers what that delivery connects to.
 */
final class GmailApiSenderTest extends TestCase
{
    public function testABrowserBuiltMessageGoesOverSmtpRatherThanTheApi(): void
    {
        $calls = 0;
        $email = $this->sealedEmail();
        $smtp  = $this->createMock(GmailSmtpDelivery::class);

        $smtp->expects(self::once())->method('deliver')->with($email);

        $this->sender($calls, $smtp)->deliver($email, new Account());

        self::assertSame(0, $calls, 'the Gmail API rewrites the message; it must not be asked to send it');
    }

    public function testOrdinaryMailStaysOnTheApi(): void
    {
        $calls = 0;
        $smtp  = $this->createMock(GmailSmtpDelivery::class);

        $smtp->expects(self::never())->method('deliver');

        $this->sender($calls, $smtp)->deliver(
            new Email()->from('me@example.test')->to('you@example.test')->subject('Hi')->text('Body'),
            new Account(),
        );

        self::assertSame(1, $calls, 'one messages.send for a message plMail built itself');
    }

    private function sender(int &$calls, GmailSmtpDelivery $smtp): GmailApiSender
    {
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"id":"x"}', ['http_code' => 200]);
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('token');

        return new GmailApiSender(new GmailApiClient($http, $tokens, new GmailQuotaPacer(new MockClock())), $smtp, new NullLogger());
    }

    /**
     * Shaped like what assets/crypto/compose.js writes for an encrypted mail.
     */
    private function sealedEmail(): Email
    {
        $entity = implode("\r\n", [
            'Content-Type: multipart/encrypted; protocol="application/pgp-encrypted"; boundary="b"',
            '',
            '--b',
            'Content-Type: application/pgp-encrypted',
            '',
            'Version: 1',
            '',
            '--b',
            'Content-Type: application/octet-stream; name="encrypted.asc"',
            '',
            '-----BEGIN PGP MESSAGE-----',
            '-----END PGP MESSAGE-----',
            '',
            '--b--',
            '',
        ]);

        return new Email()
            ->from('me@example.test')
            ->to('you@example.test')
            ->subject('...')
            ->setBody(new SealedEntityPart($entity));
    }
}
