<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use PHPUnit\Framework\TestCase;

/**
 * The per-account state JMAP added: the protocol, the session override, the
 * token switch and the sync states.
 *
 * The predicate is the one that matters most. isPlainImap() is what every
 * IMAP code path asks before it opens a connection, and a JMAP account that
 * answered yes would have the IMAP syncer speak IMAP at a web server — which
 * does not fail on a greeting, it hangs on a TLS socket waiting for HTTP.
 */
final class AccountJmapTest extends TestCase
{
    public function testAJmapAccountIsNotPlainImapNorAnyOtherProvider(): void
    {
        $account                   = new Account();
        $account->incomingProtocol = IncomingProtocol::Jmap;

        self::assertTrue($account->isJmap());
        self::assertFalse($account->isPlainImap());
        self::assertFalse($account->isPop3());
        self::assertFalse($account->isGmail());
        self::assertFalse($account->isMicrosoft());
    }

    public function testJmapStartsOnTheHttpsPort(): void
    {
        self::assertSame(443, IncomingProtocol::Jmap->defaultPort());
        self::assertFalse(IncomingProtocol::Jmap->isImap());
    }

    /**
     * A blank override is no override. The form submits an empty string for
     * an untouched field, and storing that would point every request at "".
     */
    public function testABlankSessionUrlIsForgotten(): void
    {
        $account                 = new Account();
        $account->jmapSessionUrl = '  ';

        self::assertNull($account->jmapSessionUrl);
        self::assertNull($account->getSetting(Account::SETTING_JMAP_SESSION_URL));
    }

    public function testStatesAreRememberedPerTypeAndForgottenOneAtATime(): void
    {
        $account = new Account();
        $account->rememberJmapState('Email', 's1');
        $account->rememberJmapState('Mailbox', 'm1');
        $account->rememberJmapState('Email', null);

        self::assertNull($account->jmapState('Email'));
        self::assertSame('m1', $account->jmapState('Mailbox'));
    }

    public function testTokenAuthIsOffUntilChosen(): void
    {
        $account = new Account();

        self::assertFalse($account->jmapTokenAuth);

        $account->jmapTokenAuth = true;

        self::assertTrue($account->jmapTokenAuth);
    }
}
