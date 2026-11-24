<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Service\Mail\DefaultBccProvider;
use PHPUnit\Framework\TestCase;

/**
 * The default Bcc (#692): which addresses a sending address copies its mail
 * to, and what putting them on a draft may and may not do.
 *
 * The two-level rule is the signature's, and the case worth pinning is the
 * same one: an alias whose key holds an EMPTY list Bccs nobody, which is not
 * the same as an alias with no key, which takes the account's list.
 */
final class DefaultBccProviderTest extends TestCase
{
    private DefaultBccProvider $bcc;

    protected function setUp(): void
    {
        $this->bcc = new DefaultBccProvider();
    }

    // ── the two levels ───────────────────────────────────────────────────────

    public function testAnAddressWithNoListOfItsOwnTakesTheAccounts(): void
    {
        $account = $this->account();
        $this->alias($account, 'personal@example.test');
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@example.test']);

        self::assertSame(['backup@example.test'], $this->bcc->addressesFor($account, 'personal@example.test'));
        self::assertSame(['backup@example.test'], $this->bcc->addressesFor($account, 'work@example.test'));
    }

    public function testAnAddressWithItsOwnListOverridesTheAccounts(): void
    {
        $account = $this->account();
        $alias   = $this->alias($account, 'personal@example.test');

        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['archive@example.test']);
        $account->setSetting(Account::defaultBccAliasSetting((int) $alias->id), ['me@home.test']);

        self::assertSame(['me@home.test'], $this->bcc->addressesFor($account, 'personal@example.test'));
    }

    public function testAnAddressThatSaysNobodyCopiesNothingOnACopyingAccount(): void
    {
        $account = $this->account();
        $alias   = $this->alias($account, 'personal@example.test');

        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['archive@example.test']);
        $account->setSetting(Account::defaultBccAliasSetting((int) $alias->id), []);

        self::assertSame([], $this->bcc->addressesFor($account, 'personal@example.test'));
    }

    public function testAnAddressIsNeverCopiedToItself(): void
    {
        $account = $this->account();
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['WORK@example.test', 'backup@example.test']);

        self::assertSame(['backup@example.test'], $this->bcc->addressesFor($account, 'work@example.test'));
    }

    /**
     * Nothing the bag holds can put a malformed address in a Bcc row: a
     * hand-edited value, a string where a list belongs, duplicates in two
     * spellings.
     */
    public function testAStoredValueIsReadDefensively(): void
    {
        $account = $this->account();

        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['not an address', 'Backup@Example.test', 'backup@example.test', 42]);
        self::assertSame(['backup@example.test'], $this->bcc->addressesFor($account, 'work@example.test'));

        $account->setSetting(Account::SETTING_DEFAULT_BCC, 'single@example.test');
        self::assertSame(['single@example.test'], $this->bcc->addressesFor($account, 'work@example.test'));

        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['oops' => true]);
        self::assertSame([], $this->bcc->addressesFor($account, 'work@example.test'));
    }

    // ── on a draft ───────────────────────────────────────────────────────────

    public function testTheListIsAddedToTheDraftsBcc(): void
    {
        $account = $this->account();
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@example.test']);

        $draft = new Message();
        $draft->toAddresses = [['name' => 'Bob', 'address' => 'bob@example.test']];

        $this->bcc->applyTo($draft, $account, 'work@example.test');

        self::assertSame([['name' => null, 'address' => 'backup@example.test']], $draft->bccAddresses);
    }

    /**
     * A reply to the backup mailbox, or a mailto: that already Bccs it, must
     * not name it twice — once in To and once in Bcc is a second copy.
     */
    public function testAnAddressTheDraftAlreadyNamesIsNotAddedAgain(): void
    {
        $account = $this->account();
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@example.test', 'archive@example.test']);

        $draft = new Message();
        $draft->toAddresses  = [['name' => null, 'address' => 'Backup@example.test']];
        $draft->bccAddresses = [['name' => 'Arch', 'address' => 'archive@example.test']];

        $this->bcc->applyTo($draft, $account, 'work@example.test');

        self::assertSame([['name' => 'Arch', 'address' => 'archive@example.test']], $draft->bccAddresses);
    }

    public function testNoListLeavesTheDraftsBccAlone(): void
    {
        $draft = new Message();

        $this->bcc->applyTo($draft, $this->account(), 'work@example.test');

        self::assertNull($draft->bccAddresses);
    }

    // ── the settings field ───────────────────────────────────────────────────

    public function testTheFieldAcceptsTheShapesPeoplePasteListsIn(): void
    {
        $parsed = DefaultBccProvider::parse("a@example.test, b@example.test; C@Example.test\nd@example.test e@example.test, \"Doe, Jane\" <f@example.test>, a@example.test");

        self::assertNull($parsed['invalid']);
        self::assertFalse($parsed['tooMany']);
        self::assertSame(
            ['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test', 'e@example.test', 'f@example.test'],
            $parsed['addresses'],
        );
    }

    public function testAnEmptyFieldIsAnEmptyList(): void
    {
        self::assertSame(['addresses' => [], 'invalid' => null, 'tooMany' => false], DefaultBccProvider::parse('  '));
    }

    /**
     * Refused whole, naming the culprit: a typo dropped quietly is a backup
     * copy that silently never arrives.
     */
    public function testOneBadAddressRefusesTheWholeList(): void
    {
        $parsed = DefaultBccProvider::parse('good@example.test, bad@');

        self::assertSame('bad@', $parsed['invalid']);
        self::assertSame([], $parsed['addresses']);
    }

    public function testMoreThanTheLimitIsReported(): void
    {
        $many = implode(',', array_map(static fn (int $n): string => "a{$n}@example.test", range(1, DefaultBccProvider::MAX_ADDRESSES + 1)));

        self::assertTrue(DefaultBccProvider::parse($many)['tooMany']);
    }

    // ── the map the compose window hands the browser ─────────────────────────

    public function testTheTokenMapIsKeyedTheWayTheFromSelectorIs(): void
    {
        $account = $this->account();
        $alias   = $this->alias($account, 'personal@example.test');

        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@example.test']);
        $account->setSetting(Account::defaultBccAliasSetting((int) $alias->id), []);

        $map = $this->bcc->tokenMap([$account]);

        self::assertSame(['backup@example.test'], $map['7|work@example.test'] ?? null);
        self::assertSame([], $map['7|personal@example.test'] ?? null);
    }

    public function testACatchAllAccountHasAWildcardEntry(): void
    {
        $account = $this->account();
        $account->setSetting(Account::SETTING_CATCH_ALL_DOMAINS, ['example.test']);
        $account->setSetting(Account::SETTING_DEFAULT_BCC, ['backup@elsewhere.test']);

        self::assertSame(['backup@elsewhere.test'], $this->bcc->tokenMap([$account])['7|*'] ?? null);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function account(): Account
    {
        $account = new Account();

        $this->setId($account, 7);
        $account->email       = 'work@example.test';
        $account->mailEnabled = true;

        $this->alias($account, 'work@example.test', EmailAliasStatus::Primary, 1);

        return $account;
    }

    private function alias(
        Account          $account,
        string           $address,
        EmailAliasStatus $status = EmailAliasStatus::Active,
        int              $id = 2,
    ): EmailAlias {
        $alias = new EmailAlias($account, $address, EmailAliasSource::Manual, $status);

        $this->setId($alias, $id);
        $account->addAlias($alias);

        return $alias;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
