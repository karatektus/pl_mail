<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Helper\ApiMime;
use App\Entity\Mail\Account;
use App\Service\Mail\GmailApiSender;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Email;

/**
 * Blind recipients have to be in the raw MIME the HTTP send APIs receive.
 *
 * Gmail and Graph read the recipients out of the message they are handed, and
 * Email::toString() strips Bcc (it is written for SMTP, where Bcc travels in
 * the envelope) — so Bcc recipients on Google and Microsoft accounts were
 * silently never sent to.
 */
final class ApiSenderBccTest extends TestCase
{
    public function testGmailRawPayloadCarriesTheBccHeader(): void
    {
        $captured = null;

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode($options['body'], true)['raw'];

            return new MockResponse('{"id":"x"}', ['http_code' => 200]);
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('token');

        $sender = new GmailApiSender($tokens, $http, new NullLogger());

        self::assertTrue($sender->send($this->email(), new Account()));
        self::assertIsString($captured);

        $mime = base64_decode(strtr($captured, '-_', '+/'));

        self::assertMatchesRegularExpression('/^Bcc: hidden@example\.test/m', $mime);
        self::assertStringContainsString('To: visible@example.test', $mime);
    }

    public function testTheGraphMimeCarriesTheBccHeaderAndSmtpStillDoesNot(): void
    {
        $email = $this->email();

        // GraphApiSender hands exactly this string to sendMime().
        self::assertMatchesRegularExpression('/^Bcc: hidden@example\.test/m', ApiMime::toString($email));

        // The SMTP serialisation is untouched: there Bcc belongs in the envelope.
        self::assertStringNotContainsString('hidden@example.test', $email->toString());
    }

    private function email(): Email
    {
        return new Email()
            ->from('me@example.test')
            ->to('visible@example.test')
            ->bcc('hidden@example.test')
            ->subject('Hi')
            ->text('Body');
    }
}
