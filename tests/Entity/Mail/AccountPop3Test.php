<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Enum\Account\Pop3DeleteMode;
use App\Entity\Mail\Account;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The per-account state POP3 added: which protocol, the delete mode, and the
 * flag for the pass after a protocol switch. The UIDL ledger is a table of
 * its own; see Pop3AccountSyncerTest.
 *
 * All three live on a plain entity with no database, which is the point — the
 * rules that matter here (what an unrecognised delete mode means) are the kind
 * that silently lose mail when they are wrong, and none of them need a server
 * to state.
 */
final class AccountPop3Test extends TestCase
{
    /**
     * A new account is IMAP, because the column's default is.
     *
     * The one that would be catastrophic the other way round: an account born
     * POP3 would have an IMAP syncer reach for a folder listing against a POP3
     * port on its first sync.
     */
    public function testAnAccountStartsAsImap(): void
    {
        $account = new Account();

        self::assertSame(IncomingProtocol::Imap, $account->incomingProtocol);
        self::assertTrue($account->isPlainImap());
        self::assertFalse($account->isPop3());
    }

    public function testAPop3AccountIsNotPlainImap(): void
    {
        $account = new Account();
        $account->incomingProtocol = IncomingProtocol::Pop3;

        self::assertTrue($account->isPop3());
        self::assertFalse($account->isPlainImap());
        self::assertFalse($account->isGmail());
        self::assertFalse($account->isMicrosoft());
    }

    /**
     * Nothing chosen means nothing deleted.
     *
     * The default that keeps mail, and the reason it is the default: a POP3
     * server's copy is the only second copy plMail has.
     */
    public function testTheDeleteModeDefaultsToKeepingMailOnTheServer(): void
    {
        self::assertSame(Pop3DeleteMode::Keep, (new Account())->pop3DeleteMode);
        self::assertFalse((new Account())->pop3DeleteMode->deletes());
    }

    /**
     * Anything the enum does not recognise reads as Keep.
     *
     * The value lives in a jsonb bag, so a restored backup, a hand edit or a
     * value from a future version can all put something else there. Reading
     * that as the destructive answer is the one failure this setting must not
     * have, and it is stated here rather than being left to every caller.
     */
    #[DataProvider('unrecognisedDeleteModes')]
    public function testAnUnrecognisedDeleteModeMeansKeep(mixed $stored): void
    {
        $account = new Account();
        $account->setSetting(Account::SETTING_POP3_DELETE_MODE, $stored);

        self::assertSame(Pop3DeleteMode::Keep, $account->pop3DeleteMode);
        self::assertFalse($account->pop3DeleteMode->deletes());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unrecognisedDeleteModes(): iterable
    {
        yield 'null'          => [null];
        yield 'empty string'  => [''];
        yield 'a typo'        => ['delet'];
        yield 'a future value' => ['delete-after-90-days'];
        yield 'an array'      => [['delete']];
        yield 'a bool'        => [true];
    }

    public function testTheDeleteModeRoundTrips(): void
    {
        $account = new Account();
        $account->pop3DeleteMode = Pop3DeleteMode::Delete;

        self::assertTrue($account->pop3DeleteMode->deletes());
        self::assertSame('delete', $account->getSetting(Account::SETTING_POP3_DELETE_MODE));
    }

    /**
     * The adoption pass is off unless a protocol switch turned it on.
     *
     * On, a repeated Message-ID is skipped as mail the account already holds;
     * on for a new account, it would skip the second copy of a mail sent to
     * two of the user's addresses.
     */
    public function testANewAccountIsNotAdopting(): void
    {
        self::assertFalse((new Account())->pop3Adopting);
    }

    /** Turned off, the key leaves the bag rather than sitting there as false. */
    public function testTheAdoptingFlagRoundTripsAndLeavesNoTrace(): void
    {
        $account = new Account();

        $account->pop3Adopting = true;
        self::assertTrue($account->pop3Adopting);

        $account->pop3Adopting = false;
        self::assertFalse($account->pop3Adopting);
        self::assertNull($account->getSetting(Account::SETTING_POP3_ADOPTING));
    }
}
