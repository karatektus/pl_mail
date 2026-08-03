<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\SenderResolver;
use PHPUnit\Framework\TestCase;

/**
 * Reading the From selector's token back.
 *
 * The token names an account id, and it arrives from the browser — so most of
 * these are about refusing one rather than parsing it. A token that resolves is
 * an address the mail goes out as; a token that does not must leave the caller
 * on its own fallback rather than on somebody else's account.
 */
final class SenderResolverTest extends TestCase
{
    private const int ACCOUNT_ID = 7;

    private SenderResolver $resolver;
    private User $user;

    /** The one account the stubbed repository knows about, if any. */
    private ?Account $stored = null;

    protected function setUp(): void
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('find')->willReturnCallback(
            fn (mixed $id): ?Account => self::ACCOUNT_ID === $id ? $this->stored : null,
        );

        $this->resolver = new SenderResolver($accounts);
        $this->user     = new User();
    }

    public function testATokenNamesTheAccountAndTheAddressPickedFromIt(): void
    {
        $account = $this->account();

        self::assertSame($account, $this->resolver->accountFor('7|alias@example.test', $this->user));
        self::assertSame(
            'alias@example.test',
            $this->resolver->addressFor('7|alias@example.test', $account, $this->user),
        );
    }

    /**
     * The address half is everything after the first separator, because an
     * address may contain one and the id may not.
     */
    public function testTheAddressIsWhateverFollowsTheFirstSeparator(): void
    {
        $account = $this->account();

        self::assertSame(
            'odd|address@example.test',
            $this->resolver->addressFor('7|odd|address@example.test', $account, $this->user),
        );
    }

    public function testAnAccountBelongingToSomebodyElseIsRefused(): void
    {
        $account      = $this->account();
        $account->usr = new User();

        self::assertNull($this->resolver->accountFor('7|alias@example.test', $this->user));
    }

    /**
     * Deactivating an account must take effect on the window that was already
     * open, which is the one place a stale token can still be submitted.
     */
    public function testADeactivatedAccountIsRefused(): void
    {
        $account           = $this->account();
        $account->isActive = false;

        self::assertNull($this->resolver->accountFor('7|alias@example.test', $this->user));
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        self::assertNull($this->resolver->accountFor('7|alias@example.test', $this->user));
    }

    /**
     * Anything that is not a token is not looked up at all — a stray form value
     * must not become an account lookup, let alone a match.
     */
    public function testSomethingThatIsNotATokenIsNotEvenLookedUp(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::never())->method('find');

        $resolver = new SenderResolver($accounts);

        self::assertNull($resolver->accountFor('not-a-token', $this->user));
        self::assertNull($resolver->accountFor(null, $this->user));
        self::assertNull($resolver->accountFor(42, $this->user));
    }

    /**
     * An alias of one account is not an address of another, so a token that
     * settled on a different account than the caller did falls back to that
     * account's own address rather than sending as somebody else.
     */
    public function testATokenForAnotherAccountDoesNotSupplyThisOnesFromAddress(): void
    {
        $this->account();

        $other        = new Account();
        $other->usr   = $this->user;
        $other->email = 'other@example.test';

        self::assertSame(
            'other@example.test',
            $this->resolver->addressFor('7|alias@example.test', $other, $this->user),
        );
    }

    public function testWithoutAUsableTokenTheAccountsOwnAddressIsUsed(): void
    {
        $account = $this->account();

        self::assertSame('me@example.test', $this->resolver->addressFor(null, $account, $this->user));
        self::assertSame('me@example.test', $this->resolver->addressFor('', $account, $this->user));
    }

    public function testTheTokenAFreshWindowIsPreSelectedWithNamesTheAccountsOwnAddress(): void
    {
        $account = $this->account();

        self::assertStringEndsWith('|me@example.test', $this->resolver->token($account));
    }

    private function account(): Account
    {
        $account           = new Account();
        $account->usr      = $this->user;
        $account->email    = 'me@example.test';
        $account->isActive = true;

        $this->stored = $account;

        return $account;
    }
}
