<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Service\Mail\SmtpDsnFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * STARTTLS has to be required, not attempted: Symfony signs in whether or not
 * the upgrade happened, so a stripped STARTTLS offer sent the password in
 * clear text. `require_tls` makes that a failed send instead.
 */
final class SmtpDsnFactoryTlsTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string, ?string}>
     */
    public static function encryptions(): iterable
    {
        yield 'STARTTLS requires TLS'                 => ['starttls', 'smtp', 'true'];
        yield 'no choice stored means STARTTLS'       => [null, 'smtp', 'true'];
        yield 'an older stored "tls" means STARTTLS'  => ['tls', 'smtp', 'true'];
        yield 'SSL is TLS from the first byte'        => ['ssl', 'smtps', null];
        yield 'None is plain text on purpose'         => ['none', 'smtp', null];
    }

    #[DataProvider('encryptions')]
    public function testOnlyStartTlsCarriesRequireTls(?string $encryption, string $scheme, ?string $requireTls): void
    {
        $account                 = new Account();
        $account->username       = 'me@example.com';
        $account->password       = 'secret';
        $account->smtpHost       = 'mail.example.com';
        $account->smtpPort       = 587;
        $account->smtpEncryption = $encryption;

        $dsn = Dsn::fromString(new SmtpDsnFactory()->forAccount($account));

        self::assertSame($scheme, $dsn->getScheme());
        self::assertSame('mail.example.com', $dsn->getHost());
        self::assertSame('secret', $dsn->getPassword());
        self::assertSame($requireTls, $dsn->getOption('require_tls'));
    }
}
