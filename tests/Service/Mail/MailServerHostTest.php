<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Service\Mail\ConnectionTester;
use App\Service\Mail\SmtpDsnFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The mail host fields are spliced into a DSN and a socket address, so they
 * take a hostname or an IP and nothing that parses as more URL; and a failed
 * connection test says what kind of failure it was, not what the server said.
 */
final class MailServerHostTest extends TestCase
{
    public function testAHostCarryingDsnOptionsIsRefusedBeforeItReachesTheDsn(): void
    {
        $account           = new Account();
        $account->username = 'me@example.com';
        $account->password = 'a pass+word';
        $account->smtpHost = 'mail.example.com?verify_peer=0';

        $this->expectException(\InvalidArgumentException::class);

        new SmtpDsnFactory()->forAccount($account);
    }

    public function testCredentialsAreEncodedSoTheyDecodeToThemselves(): void
    {
        $account           = new Account();
        $account->username = 'me@example.com';
        $account->password = 'a pass+word/?#';
        $account->smtpHost = 'mail.example.com';
        $account->smtpPort = 587;

        $dsn = Dsn::fromString(new SmtpDsnFactory()->forAccount($account));

        self::assertSame('mail.example.com', $dsn->getHost());
        self::assertSame('a pass+word/?#', $dsn->getPassword());
        self::assertNull($dsn->getOption('verify_peer'));
    }

    public function testAFailedTestNamesACategoryNotTheServersWords(): void
    {
        $account           = new Account();
        $account->username = 'me@example.com';
        $account->password = 'secret';
        $account->imapHost = 'imap.example.com';
        $account->imapPort = 993;
        $account->smtpHost = 'smtp.example.com';
        $account->smtpPort = 587;

        $imap = $this->createStub(ImapConnectionFactory::class);
        $imap->method('connect')->willThrowException(new \RuntimeException(
            'connection setup failed',
            0,
            new \RuntimeException('* OK Dovecot (Ubuntu) 2.3.16 ready, AUTHENTICATIONFAILED Authentication failed.'),
        ));

        $result = new ConnectionTester($imap, new SmtpDsnFactory(), new IdentityTranslator(), new NullLogger())->test($account);

        self::assertFalse($result->imapOk);
        self::assertSame('account.test.result.auth', $result->imapMessage);
        self::assertStringNotContainsString('Dovecot', $result->imapMessage);
    }
}
