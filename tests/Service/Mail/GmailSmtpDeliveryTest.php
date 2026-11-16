<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Service\Mail\GmailSmtpDelivery;
use App\Service\Mail\SmtpDsnFactory;
use App\Service\Mail\SmtpMailSender;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * The SMTP connection a Google account's sealed mail leaves through (#651):
 * Gmail's submission server, TLS from the start, signed in with the account's
 * OAuth token over XOAUTH2 and nothing else.
 *
 * Read off the transport rather than tried against a server. What matters is
 * that the settings are the ones Gmail takes, and a real connection in the
 * suite would need Google. The authenticator list is private in Symfony, so it
 * is read by reflection: it is the setting that keeps the token from being
 * offered as a password, and nothing public says what it holds.
 */
final class GmailSmtpDeliveryTest extends TestCase
{
    public function testItConnectsToGmailOverTlsAsTheLinkedAddress(): void
    {
        $transport = $this->delivery()->transport($this->account());
        $stream    = $transport->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('smtp.gmail.com', $stream->getHost());
        self::assertSame(465, $stream->getPort());
        self::assertTrue($stream->isTLS(), 'TLS from the first byte, not STARTTLS');
        self::assertSame('someone@gmail.com', $transport->getUsername());
        self::assertSame('fresh-token', $transport->getPassword(), 'the OAuth token, asked for at send time');
    }

    public function testItSignsInWithXoauth2RatherThanOfferingTheTokenAsAPassword(): void
    {
        $transport      = $this->delivery()->transport($this->account());
        $authenticators = new \ReflectionProperty($transport, 'authenticators')->getValue($transport);

        self::assertIsArray($authenticators);
        self::assertCount(1, $authenticators, 'PLAIN and LOGIN would be tried first and refused');
        self::assertInstanceOf(XOAuth2Authenticator::class, $authenticators[0]);
    }

    private function delivery(): GmailSmtpDelivery
    {
        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('fresh-token');

        return new GmailSmtpDelivery($tokens, new SmtpMailSender(new SmtpDsnFactory()));
    }

    private function account(): Account
    {
        $account           = new Account();
        $account->username = 'someone@gmail.com';

        return $account;
    }
}
