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
        yield 'imap on gmail: php-imap\'s bare server name beside the line is not a second line' => [
            'imap', 'imap.gmail.com', 'a@airline.example',
            ['mx.google.com; ' . $pass, 'mx.google.com'],
            SenderVerdict::Pass,
        ];
        yield 'imap: a bare server name alone says nothing' => ['imap', 'imap.example.org', 'a@airline.example', 'mx.example.org', SenderVerdict::Unknown];
        yield 'microsoft: the bare token does not make the single line two' => [
            'microsoft', null, 'a@airline.example',
            ['spf=pass smtp.mailfrom=airline.example; dmarc=pass header.from=airline.example', 'spf'],
            SenderVerdict::Pass,
        ];
        yield 'imap: two lines under the believed name that disagree' => [
            'imap', 'imap.example.org', 'a@airline.example',
            ['mx.example.org; dmarc=fail header.from=airline.example', 'mx.example.org; ' . $pass],
            SenderVerdict::Fail,
        ];
        yield 'imap: a believed line with no results says nothing' => ['imap', 'imap.example.org', 'a@airline.example', 'mx.example.org; none', SenderVerdict::Unknown];

        // iCloud (#462): read on imap.mail.me.com, checked under *.icloud.com,
        // one line per method. The first set is the reporter's Booking.com mail.
        $icloud = static fn (string ...$results): array => array_map(
            static fn (string $result): string => explode('=', $result, 2)[0] . '.icloud.com; ' . $result,
            $results,
        );

        yield 'icloud: one line per method, all passing' => [
            'imap', 'imap.mail.me.com', 'noreply@booking.com',
            [
                'bimi.icloud.com; bimi=pass header.d=booking.com header.selector=default policy.authority=pass',
                'arc.icloud.com; arc=none',
                'dmarc.icloud.com; dmarc=pass header.from=booking.com',
                'dkim-verifier.icloud.com; dkim=pass header.d=booking.com header.i=noreply@booking.com header.b=abc',
                'spf.icloud.com; spf=pass (domain of noreply@mailer.booking.com designates 1.2.3.4 as permitted sender) smtp.mailfrom=noreply@mailer.booking.com',
                'bimi.icloud.com',
            ],
            SenderVerdict::Pass,
        ];
        yield 'icloud: a failing BIMI line says nothing about the sender' => [
            'imap', 'imap.mail.me.com', 'noreply@booking.com',
            $icloud('bimi=fail reason="certificate expired"', 'dmarc=pass header.from=booking.com'),
            SenderVerdict::Pass,
        ];
        yield 'icloud: no DMARC record, aligned DKIM, SPF at the mailing service' => [
            'imap', 'imap.mail.me.com', 'a@small.example',
            $icloud('dmarc=none header.from=small.example', 'dkim=pass header.d=small.example', 'spf=pass smtp.mailfrom=bounce@amazonses.com'),
            SenderVerdict::Pass,
        ];
        yield 'icloud: forwarded, so DMARC passes and SPF does not' => [
            'imap', 'imap.mail.me.com', 'a@airline.example',
            $icloud('dmarc=pass header.from=airline.example', 'spf=fail smtp.mailfrom=a@airline.example'),
            SenderVerdict::Pass,
        ];
        yield 'icloud: only BIMI and ARC lines' => [
            'imap', 'imap.mail.me.com', 'a@airline.example',
            $icloud('bimi=pass header.d=airline.example', 'arc=none'),
            SenderVerdict::Unknown,
        ];
        yield 'icloud: passes only for somebody else\'s domain' => [
            'imap', 'imap.mail.me.com', 'a@airline.example',
            $icloud('dmarc=fail header.from=airline.example', 'dkim=pass header.d=evil.example', 'spf=pass smtp.mailfrom=x@evil.example'),
            SenderVerdict::Fail,
        ];
        yield 'icloud: a second DMARC line that disagrees' => [
            'imap', 'imap.mail.me.com', 'a@airline.example',
            $icloud('dmarc=fail header.from=airline.example', 'dmarc=pass header.from=airline.example'),
            SenderVerdict::Fail,
        ];
        yield 'icloud: a second SPF line that disagrees' => [
            'imap', 'imap.mail.me.com', 'a@airline.example',
            $icloud('spf=fail smtp.mailfrom=x@evil.example', 'spf=pass smtp.mailfrom=a@airline.example'),
            SenderVerdict::Fail,
        ];
        yield 'icloud: two DKIM signatures, one broken' => [
            'imap', 'imap.mail.me.com', 'a@airline.example',
            $icloud('dkim=fail header.d=list.example', 'dkim=pass header.d=airline.example'),
            SenderVerdict::Pass,
        ];
        yield 'icloud: a line under a name that is not Apple\'s' => [
            'imap', 'imap.mail.me.com', 'a@airline.example',
            ['mx.evil.example; dmarc=pass header.from=airline.example'],
            SenderVerdict::Unknown,
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
