<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Infrastructure\Pop3\Pop3ConnectionFactory;
use App\Service\Imap\ImapDeadline;
use App\Service\Mail\ConnectionTester;
use App\Service\Mail\SmtpDsnFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The connection test for a JMAP account answers both halves from one
 * session.
 *
 * The outgoing half is the one that would mislead if it were wrong. A JMAP
 * account with submission has no SMTP host, and the SMTP probe's answer for
 * that is "sending is disabled for this account" — true of IMAP, false here,
 * and the first thing a person would read after connecting.
 */
final class ConnectionTesterJmapTest extends TestCase
{
    public function testAWorkingServerWithSubmissionPassesBothHalves(): void
    {
        $result = $this->tester([
            $this->session([JmapCapability::MAIL => [], JmapCapability::SUBMISSION => []]),
            new MockResponse(json_encode(['methodResponses' => [
                ['Mailbox/get', ['list' => [['id' => 'a'], ['id' => 'b']]], 'm'],
            ], 'sessionState' => 's'])),
        ])->test($this->account());

        self::assertTrue($result->imapOk);
        self::assertSame('account.test.result.jmap_ok', $result->imapMessage);
        self::assertTrue($result->smtpOk);
        self::assertSame('account.test.result.jmap_submission', $result->smtpMessage);
    }

    /**
     * Without submission it is SMTP that will send, so SMTP is what is
     * tested — and with no SMTP host that is the honest "disabled".
     */
    public function testWithoutSubmissionTheSmtpHalfIsProbed(): void
    {
        $result = $this->tester([
            $this->session([JmapCapability::MAIL => []]),
            new MockResponse(json_encode(['methodResponses' => [['Mailbox/get', ['list' => []], 'm']], 'sessionState' => 's'])),
        ])->test($this->account());

        self::assertTrue($result->imapOk);
        self::assertNull($result->smtpOk);
        self::assertSame('account.test.result.smtp_none', $result->smtpMessage);
    }

    public function testAServerWithoutMailIsAFailureNotAnEmptySuccess(): void
    {
        $result = $this->tester([$this->session([JmapCapability::CALENDARS => []])])->test($this->account());

        self::assertFalse($result->imapOk);
        self::assertSame('account.test.result.jmap_no_mail', $result->imapMessage);
    }

    public function testARefusedPasswordSaysSo(): void
    {
        $result = $this->tester([new MockResponse('', ['http_code' => 401])])->test($this->account());

        self::assertFalse($result->imapOk);
        self::assertSame('account.test.result.auth', $result->imapMessage);
    }

    /**
     * The refusal UserUrlHttpClient gives a LAN address. Nothing in the form
     * fixes it, so "check the host name" would send somebody looking in the
     * wrong place.
     */
    public function testAPrivateNetworkRefusalNamesTheAdministratorsSetting(): void
    {
        $blocked = new MockHttpClient(static function (): never {
            throw new TransportException('Host "mail.lan" is blocked for "https://mail.lan/.well-known/jmap".');
        });

        $result = $this->tester([], $blocked)->test($this->account());

        self::assertFalse($result->imapOk);
        self::assertSame('account.test.result.private_network', $result->imapMessage);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<MockResponse> $responses
     */
    private function tester(array $responses, ?MockHttpClient $http = null): ConnectionTester
    {
        return new ConnectionTester(
            $this->createStub(ImapConnectionFactory::class),
            new SmtpDsnFactory(),
            new IdentityTranslator(),
            new NullLogger(),
            new Pop3ConnectionFactory(new ImapDeadline(new NullLogger())),
            new JmapClient($http ?? new MockHttpClient($responses)),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $capabilities
     */
    private function session(array $capabilities): MockResponse
    {
        $primary = [];

        foreach (array_keys($capabilities) as $capability) {
            $primary[$capability] = 'u1';
        }

        return new MockResponse(json_encode([
            'capabilities'    => [JmapCapability::CORE => []] + $capabilities,
            'primaryAccounts' => $primary,
            'apiUrl'          => 'https://mail.example.test/jmap/api/',
            'downloadUrl'     => 'https://mail.example.test/jmap/download/{accountId}/{blobId}/{name}?accept={type}',
            'uploadUrl'       => 'https://mail.example.test/jmap/upload/{accountId}/',
            'state'           => 's',
        ]));
    }

    private function account(): Account
    {
        $account                   = new Account();
        $account->email            = 'ada@example.test';
        $account->username         = 'ada';
        $account->password         = 'secret';
        $account->incomingProtocol = IncomingProtocol::Jmap;
        $account->imapHost         = 'mail.example.test';
        $account->imapPort         = 443;
        $account->imapEncryption   = 'ssl';

        return $account;
    }
}
