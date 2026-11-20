<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Repository\Mail\Message\MessageThreadingRepository;
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

    /**
     * A reply goes into the Gmail thread of the mail it answers (#676).
     *
     * messages.send does not work that out from the headers: without the id,
     * Gmail filed every reply sent from plMail in a thread of its own.
     */
    public function testAReplyIsSentIntoTheGmailThreadOfTheMailItAnswers(): void
    {
        $calls     = 0;
        $requested = null;
        $threading = $this->createMock(MessageThreadingRepository::class);

        $threading->expects(self::once())
            ->method('findProviderThreadKeyForReply')
            ->with(['answered@example.test', 'first@example.test'])
            ->willReturn('gmail-thread-1');

        $email = new Email()->from('me@example.test')->to('you@example.test')->subject('Re: Hi')->text('Body');
        $email->getHeaders()->addIdHeader('In-Reply-To', 'answered@example.test');
        $email->getHeaders()->addIdHeader('References', ['first@example.test', 'answered@example.test']);

        $this->sender($calls, $this->createStub(GmailSmtpDelivery::class), $threading, $requested)->deliver($email, new Account());

        self::assertSame('gmail-thread-1', $requested['threadId'] ?? null);
    }

    /**
     * A thread Gmail no longer has must not cost the reply: it goes out
     * without one, as every reply did before.
     */
    public function testAThreadGmailDoesNotKnowIsLeftOffRatherThanFailingTheSend(): void
    {
        $bodies = [];
        $http   = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            if ('GET' === $method) {
                return self::metadata('x@mail.gmail.test');
            }

            $bodies[] = json_decode((string) $options['body'], true);

            return 1 === count($bodies)
                ? new MockResponse('{"error":{"code":404,"message":"Requested entity was not found."}}', ['http_code' => 404])
                : new MockResponse('{"id":"x"}', ['http_code' => 200]);
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('token');

        $threading = $this->createStub(MessageThreadingRepository::class);
        $threading->method('findProviderThreadKeyForReply')->willReturn('gone-thread');

        $email = new Email()->from('me@example.test')->to('you@example.test')->subject('Re: Hi')->text('Body');
        $email->getHeaders()->addIdHeader('In-Reply-To', 'answered@example.test');

        new GmailApiSender(
            new GmailApiClient($http, $tokens, new GmailQuotaPacer(new MockClock())),
            $this->createStub(GmailSmtpDelivery::class),
            new NullLogger(),
            $threading,
        )->deliver($email, new Account());

        self::assertCount(2, $bodies);
        self::assertSame('gone-thread', $bodies[0]['threadId'] ?? null);
        self::assertArrayNotHasKey('threadId', $bodies[1]);
    }

    /**
     * Gmail replaces the Message-ID of mail sent through messages.send
     * (#702), seen on prod as `<3b5390…@gmail.com>` going in and
     * `<CAA4PN…@mail.gmail.com>` in Sent. deliver() answers Gmail's, read back
     * from the sent message, so the row can carry the one Sent and the
     * recipients have.
     */
    public function testAnswersTheMessageIdGmailGaveTheMail(): void
    {
        $calls = 0;
        $email = new Email()->from('me@example.test')->to('you@example.test')->subject('Hi')->text('Body');
        $email->getHeaders()->addIdHeader('Message-ID', 'ours@example.test');

        $given = $this->sender($calls, $this->createStub(GmailSmtpDelivery::class), given: 'CAA4PN@mail.gmail.test')
            ->deliver($email, new Account());

        self::assertSame('CAA4PN@mail.gmail.test', $given);
        self::assertSame(1, $calls, 'one send; the read-back is not a second one');
    }

    /** Gmail kept the id: nothing to change on the row. */
    public function testAnswersNullWhenGmailKeptTheMessageId(): void
    {
        $calls = 0;
        $email = new Email()->from('me@example.test')->to('you@example.test')->subject('Hi')->text('Body');
        $email->getHeaders()->addIdHeader('Message-ID', 'ours@example.test');

        self::assertNull($this->sender($calls, $this->createStub(GmailSmtpDelivery::class), given: 'ours@example.test')
            ->deliver($email, new Account()));
    }

    /**
     * The mail has left when the id is read back. A failure there must not
     * look like a failed send, which would be retried and sent twice.
     */
    public function testAFailedReadBackIsNotAFailedSend(): void
    {
        $calls = 0;
        $email = new Email()->from('me@example.test')->to('you@example.test')->subject('Hi')->text('Body');
        $email->getHeaders()->addIdHeader('Message-ID', 'ours@example.test');

        self::assertNull($this->sender($calls, $this->createStub(GmailSmtpDelivery::class), given: null)
            ->deliver($email, new Account()));
        self::assertSame(1, $calls);
    }

    /** The SMTP path for sealed mail asks nothing back; Gmail returns no id there. */
    public function testABrowserBuiltMessageAnswersNoMessageId(): void
    {
        $calls = 0;

        self::assertNull($this->sender($calls, $this->createStub(GmailSmtpDelivery::class))->deliver($this->sealedEmail(), new Account()));
    }

    public function testNewMailNamesNoThread(): void
    {
        $calls     = 0;
        $requested = null;
        $threading = $this->createStub(MessageThreadingRepository::class);
        $threading->method('findProviderThreadKeyForReply')->willReturn(null);

        $this->sender($calls, $this->createStub(GmailSmtpDelivery::class), $threading, $requested)->deliver(
            new Email()->from('me@example.test')->to('you@example.test')->subject('Hi')->text('Body'),
            new Account(),
        );

        self::assertIsArray($requested);
        self::assertArrayNotHasKey('threadId', $requested, 'Gmail refuses a threadId it does not know');
    }

    /**
     * @param array<string, mixed>|null $requested the JSON body of the last messages.send
     * @param ?string                    $given     the Message-ID Gmail answers for the sent mail;
     *                                              null for a messages.get that fails
     */
    private function sender(
        int &$calls,
        GmailSmtpDelivery $smtp,
        ?MessageThreadingRepository $threading = null,
        ?array &$requested = null,
        ?string $given = 'x@mail.gmail.test',
    ): GmailApiSender {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$requested, $given): MockResponse {
            if ('GET' === $method) {
                return null === $given
                    ? new MockResponse('{"error":{"code":500,"message":"Backend Error"}}', ['http_code' => 500])
                    : self::metadata($given);
            }

            ++$calls;
            $requested = json_decode((string) $options['body'], true);

            return new MockResponse('{"id":"x","threadId":"t"}', ['http_code' => 200]);
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('token');

        return new GmailApiSender(
            new GmailApiClient($http, $tokens, new GmailQuotaPacer(new MockClock())),
            $smtp,
            new NullLogger(),
            $threading ?? $this->createStub(MessageThreadingRepository::class),
        );
    }

    /** messages.get?format=metadata, as Gmail answers it for one header. */
    private static function metadata(string $messageId): MockResponse
    {
        return new MockResponse(json_encode([
            'id'      => 'x',
            'payload' => ['headers' => [['name' => 'Message-ID', 'value' => '<' . $messageId . '>']]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
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
