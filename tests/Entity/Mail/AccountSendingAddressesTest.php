<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use PHPUnit\Framework\TestCase;

/**
 * Which addresses an account sends as, and under what name.
 *
 * The From selector, a reply's choice of sender, and the check on the token
 * the browser posts back all ask the account these questions. They are pinned
 * here once because the three must give one answer: an address the selector
 * offers and the send refuses is a mail that cannot go out, and the reverse is
 * a mail sent as somebody the user never was.
 */
final class AccountSendingAddressesTest extends TestCase
{
    // ── catch-all domains ────────────────────────────────────────────────────

    public function testAnAccountHasNoCatchAllDomainsUntilOneIsAdded(): void
    {
        $account = $this->account();

        self::assertSame([], $account->catchAllDomains);
        self::assertFalse($account->catchesAddress('anything@example.test'));
    }

    public function testEveryAddressAtACatchAllDomainIsCaught(): void
    {
        $account                  = $this->account();
        $account->catchAllDomains = ['Example.TEST '];

        self::assertSame(['example.test'], $account->catchAllDomains);
        self::assertTrue($account->catchesAddress('anything@example.test'));
        self::assertTrue($account->catchesAddress('  Sales@EXAMPLE.test'));
    }

    /** A catch-all is set up per domain at the provider; a subdomain is another one. */
    public function testACatchAllDomainCoversNeitherItsSubdomainsNorItsLookalikes(): void
    {
        $account                  = $this->account();
        $account->catchAllDomains = ['example.test'];

        self::assertFalse($account->catchesAddress('me@mail.example.test'));
        self::assertFalse($account->catchesAddress('me@notexample.test'));
        self::assertFalse($account->catchesAddress('me@example.test.evil.test'));
        self::assertFalse($account->catchesAddress('@example.test'));
        self::assertFalse($account->catchesAddress('example.test'));
        self::assertFalse($account->catchesAddress(null));
    }

    public function testRemovingTheLastCatchAllDomainRemovesTheSetting(): void
    {
        $account                  = $this->account();
        $account->catchAllDomains = ['example.test'];
        $account->catchAllDomains = [];

        self::assertNull($account->getSetting(Account::SETTING_CATCH_ALL_DOMAINS));
    }

    /** The bag is jsonb: a value that is not a list of strings reads as none. */
    public function testAStoredValueThatIsNotAListOfDomainsReadsAsNone(): void
    {
        $account = $this->account();

        $account->setSetting(Account::SETTING_CATCH_ALL_DOMAINS, 'example.test');
        self::assertSame([], $account->catchAllDomains);

        $account->setSetting(Account::SETTING_CATCH_ALL_DOMAINS, [null, 7, '', 'example.test']);
        self::assertSame(['example.test'], $account->catchAllDomains);
    }

    // ── what may go in From ──────────────────────────────────────────────────

    public function testAnAccountMaySendAsItsEnabledAliasesAndNotItsDisabledOnes(): void
    {
        $account = $this->account();
        $this->alias($account, 'me@example.test', EmailAliasStatus::Primary);
        $this->alias($account, 'sales@example.test', EmailAliasStatus::Active);
        $this->alias($account, 'old@example.test', EmailAliasStatus::Inactive);

        self::assertTrue($account->maySendAs('me@example.test'));
        self::assertTrue($account->maySendAs('Sales@Example.test'));
        self::assertFalse($account->maySendAs('old@example.test'));
        self::assertFalse($account->maySendAs('stranger@example.test'));
        self::assertFalse($account->maySendAs(''));
    }

    public function testAnAccountWithNoAliasRowsMaySendAsItsOwnAddress(): void
    {
        self::assertTrue($this->account()->maySendAs('me@example.test'));
    }

    public function testAnAccountMaySendAsAnyAddressAtACatchAllDomain(): void
    {
        $account = $this->account();
        $this->alias($account, 'me@example.test', EmailAliasStatus::Primary);
        $account->catchAllDomains = ['shop.test'];

        self::assertTrue($account->maySendAs('orders@shop.test'));
        self::assertFalse($account->maySendAs('orders@other.test'));
    }

    // ── the name mail goes out under ─────────────────────────────────────────

    public function testAnAliasWithANameOfItsOwnSendsUnderIt(): void
    {
        $account = $this->account();
        $this->alias($account, 'me@example.test', EmailAliasStatus::Primary);
        $this->alias($account, 'family@example.test', EmailAliasStatus::Active, 'Mr and Mrs Smith');

        self::assertSame('Mr and Mrs Smith', $account->senderNameFor('family@example.test'));
        self::assertSame('Mr and Mrs Smith', $account->senderNameFor('Family@Example.test'));
    }

    public function testAnAliasWithoutANameSendsUnderTheAccounts(): void
    {
        $account = $this->account();
        $this->alias($account, 'me@example.test', EmailAliasStatus::Primary);

        self::assertSame('John Smith', $account->senderNameFor('me@example.test'));
        self::assertSame('John Smith', $account->senderNameFor('orders@shop.test'));
        self::assertSame('John Smith', $account->senderNameFor(null));
    }

    private function account(): Account
    {
        $account        = new Account();
        $account->name  = 'John Smith';
        $account->email = 'me@example.test';

        return $account;
    }

    private function alias(Account $account, string $address, EmailAliasStatus $status, ?string $name = null): void
    {
        $account->addAlias(new EmailAlias($account, $address, EmailAliasSource::Manual, $status, $name));
    }
}
