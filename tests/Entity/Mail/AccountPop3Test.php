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
 * ledger of UIDLs already stored.
 *
 * All three live on a plain entity with no database, which is the point — the
 * rules that matter here (what an unrecognised delete mode means, what happens
 * when the ledger fills) are the kind that silently lose mail when they are
 * wrong, and none of them need a server to state.
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
     *
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

    public function testAFreshAccountHasStoredNothing(): void
    {
        $account = new Account();

        self::assertSame([], $account->pop3SeenUidls);
        self::assertFalse($account->hasStoredPop3Uidl('anything'));
    }

    public function testRecordingAUidlMakesItRecognisable(): void
    {
        $account = new Account();
        $account->rememberPop3Uidls(['aaa', 'bbb']);

        self::assertTrue($account->hasStoredPop3Uidl('aaa'));
        self::assertTrue($account->hasStoredPop3Uidl('bbb'));
        self::assertFalse($account->hasStoredPop3Uidl('ccc'));
    }

    /**
     * Recording the same UIDL twice does not grow the ledger.
     *
     * A sync that retries a message it has already stored — the delete mark
     * failed, say — must not spend a slot on it and push an older UIDL out.
     */
    public function testRecordingTheSameUidlTwiceDoesNotDuplicateIt(): void
    {
        $account = new Account();
        $account->rememberPop3Uidls(['aaa']);
        $account->rememberPop3Uidls(['aaa', 'bbb']);

        self::assertSame(['aaa', 'bbb'], $account->pop3SeenUidls);
    }

    /**
     * The ledger keeps the newest entries when it fills.
     *
     * A POP3 server lists its whole mailbox every sync, so what is worth
     * remembering is what arrived recently. Dropping from the front is what
     * makes the cap invisible: the messages that fall out are ones the server
     * has already stopped offering.
     */
    public function testTheLedgerKeepsTheNewestUidlsWhenItFills(): void
    {
        $account = new Account();

        // One over the cap, so exactly one falls off.
        $uidls = [];
        for ($i = 0; $i <= Account::POP3_UIDL_MEMORY; ++$i) {
            $uidls[] = 'uidl-' . $i;
        }

        $account->pop3SeenUidls = $uidls;

        $stored = $account->pop3SeenUidls;

        self::assertCount(Account::POP3_UIDL_MEMORY, $stored);
        self::assertSame('uidl-1', $stored[0], 'the oldest went, not the newest');
        self::assertSame('uidl-' . Account::POP3_UIDL_MEMORY, $stored[count($stored) - 1]);
        self::assertFalse($account->hasStoredPop3Uidl('uidl-0'));
    }

    /**
     * A ledger that is not a list of strings keeps only what is usable.
     *
     * The bag is jsonb and a restored or hand-edited value can be anything.
     * The rule is per entry: strings survive, everything else is dropped, and
     * a value that is not a list at all reads as empty. Dropping an entry
     * costs a message fetched again; keeping a junk entry would cost the
     * message never being fetched.
     */
    public function testAMalformedLedgerKeepsOnlyItsUsableEntries(): void
    {
        $cases = [
            'null'              => [null, []],
            'a bare string'     => ['uidl', []],
            'an int'            => [42, []],
            // A JSON object rather than a list: its string VALUES come back
            // as the ledger. Harmless — a map was never a ledger anyone wrote
            // — and the alternative, refusing it wholesale, would drop real
            // UIDLs if jsonb ever re-encoded a list as an object, which it
            // does not but which nothing should depend on.
            'a map, not a list' => [['a' => 'b'], ['b']],
            'mixed'             => [['ok', 7, null, ''], ['ok']],
        ];

        foreach ($cases as $label => [$junk, $expected]) {
            $account = new Account();
            $account->setSetting(Account::SETTING_POP3_SEEN_UIDLS, $junk);

            self::assertSame($expected, $account->pop3SeenUidls, $label);
        }
    }

    public function testRememberingNothingChangesNothing(): void
    {
        $account = new Account();
        $account->pop3SeenUidls = ['aaa'];
        $account->rememberPop3Uidls([]);

        self::assertSame(['aaa'], $account->pop3SeenUidls);
    }
}
