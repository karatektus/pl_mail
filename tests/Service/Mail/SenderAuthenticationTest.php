<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\SenderVerdict;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Mail\SenderAuthentication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whose Authentication-Results header is believed, and what in it is a pass.
 *
 * The header is the receiving server's verdict and also a line any sender can
 * write into their own mail. Every case below that returns something other
 * than Pass for a message carrying the words "dmarc=pass" is a forgery this
 * class has to see through: calendar changes are applied without asking on the
 * strength of a Pass (EventReconciler, issue #34), so a wrong Pass is somebody
 * else's entry on the user's calendar.
 */
final class SenderAuthenticationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, string, string|list<string>|null, SenderVerdict}>
     */
    public static function cases(): iterable
    {
        $pass = 'dkim=pass header.i=@airline.example header.s=s1; spf=pass smtp.mailfrom=bounce@airline.example; dmarc=pass (p=REJECT) header.from=airline.example';

        yield 'gmail: its own server says pass' => ['gmail', null, 'a@airline.example', 'mx.google.com; ' . $pass, SenderVerdict::Pass];
        yield 'gmail: a line under another name is the sender\'s own' => ['gmail', null, 'a@airline.example', 'mx.evil.example; ' . $pass, SenderVerdict::Unknown];
        yield 'gmail: its server says fail, whatever a second line claims' => [
            'gmail', null, 'a@airline.example',
            ['mx.google.com; spf=fail smtp.mailfrom=x@evil.example; dmarc=fail header.from=airline.example', 'mx.evil.example; ' . $pass],
            SenderVerdict::Fail,
        ];
        yield 'gmail: no header at all' => ['gmail', null, 'a@airline.example', null, SenderVerdict::Unknown];

        yield 'no dmarc record, aligned dkim' => ['gmail', null, 'a@small.example', 'mx.google.com; dkim=pass header.i=@small.example; dmarc=none', SenderVerdict::Pass];
        yield 'no dmarc record, dkim for somebody else\'s domain' => ['gmail', null, 'a@airline.example', 'mx.google.com; dkim=pass header.i=@evil.example; spf=pass smtp.mailfrom=x@evil.example; dmarc=none', SenderVerdict::Fail];
        yield 'aligned on a subdomain' => ['gmail', null, 'a@airline.example', 'mx.google.com; dkim=pass header.d=mail.airline.example', SenderVerdict::Pass];

        yield 'microsoft: the single nameless line is believed' => ['microsoft', null, 'a@airline.example', 'spf=pass (sender IP is 1.2.3.4) smtp.mailfrom=airline.example; dkim=pass header.d=airline.example;dmarc=pass action=none header.from=airline.example;compauth=pass reason=100', SenderVerdict::Pass];
        yield 'microsoft: two lines means one is not theirs' => [
            'microsoft', null, 'a@airline.example',
            ['spf=fail smtp.mailfrom=evil.example; dmarc=fail header.from=airline.example', 'spf=pass smtp.mailfrom=airline.example; dmarc=pass header.from=airline.example'],
            SenderVerdict::Unknown,
        ];

        yield 'imap: a server on the account\'s own domain' => ['imap', 'imap.example.org', 'a@airline.example', 'mx.example.org; ' . $pass, SenderVerdict::Pass];
        yield 'imap: a server somewhere else is not believed' => ['imap', 'imap.example.org', 'a@airline.example', 'mx.other.example; ' . $pass, SenderVerdict::Unknown];
        yield 'imap: a nameless line is not believed' => ['imap', 'imap.example.org', 'a@airline.example', $pass, SenderVerdict::Unknown];
        yield 'imap on gmail: google receives it' => ['imap', 'imap.gmail.com', 'a@airline.example', 'mx.google.com; ' . $pass, SenderVerdict::Pass];
        yield 'imap: two lines under the believed name that disagree' => [
            'imap', 'imap.example.org', 'a@airline.example',
            ['mx.example.org; dmarc=fail header.from=airline.example', 'mx.example.org; ' . $pass],
            SenderVerdict::Fail,
        ];
    }

    /**
     * @param string|list<string>|null $header
     */
    #[DataProvider('cases')]
    public function testTheVerdict(string $provider, ?string $imapHost, string $from, string|array|null $header, SenderVerdict $expected): void
    {
        $account = new Account();

        if ('imap' === $provider) {
            $account->authType = 'password';
            $account->imapHost = $imapHost;
        } else {
            $account->authType      = AuthType::OAuth2->value;
            $account->oauthProvider = 'gmail' === $provider ? MailProvider::Google->value : MailProvider::Microsoft->value;
        }

        $message              = new Message();
        $message->account     = $account;
        $message->fromAddress = $from;
        $message->headers     = null === $header ? [] : ['authentication-results' => $header];

        self::assertSame($expected, new SenderAuthentication()->verdict($message));
    }
}
