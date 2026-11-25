<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use PHPUnit\Framework\TestCase;

/**
 * The other names an address sends under (EmailAlias::$extraNames): "A", "a"
 * and "Arnaud" at a@mail.com.
 *
 * Most of this is about the keys. They are what a From token and a JMAP
 * identity id are made of, so they have to survive a rename and never be
 * handed out twice on one alias — a draft that picked "Arnaud" must not find
 * itself signed by whatever name took the key after "Arnaud" was removed.
 */
final class EmailAliasExtraNamesTest extends TestCase
{
    public function testNamesAreAddedWithKeysOfTheirOwn(): void
    {
        $alias = $this->alias();

        self::assertSame(1, $alias->addExtraName('A', 'Arnaud Default'));
        self::assertSame(2, $alias->addExtraName('a', 'Arnaud Default'));
        self::assertSame([['key' => 1, 'name' => 'A'], ['key' => 2, 'name' => 'a']], $alias->extraNames);
    }

    public function testANameTheAddressAlreadyHasIsNotAddedTwice(): void
    {
        $alias = $this->alias();
        $alias->addExtraName('Arnaud', 'Default');

        self::assertNull($alias->addExtraName('Arnaud', 'Default'), 'the same extra name twice');
        self::assertNull($alias->addExtraName(' Default ', 'Default'), 'the address\'s own name as an extra one');
        self::assertNull($alias->addExtraName("  \n ", 'Default'), 'an empty name');
        self::assertCount(1, $alias->extraNames);
    }

    public function testAKeyIsNeverReusedOnceItsNameIsRemoved(): void
    {
        $alias = $this->alias();
        $alias->addExtraName('A', null);
        $alias->addExtraName('B', null);

        self::assertTrue($alias->removeExtraName(2));
        self::assertSame(3, $alias->addExtraName('C', null), 'a removed name\'s key went to another name');
        self::assertFalse($alias->removeExtraName(2), 'removing twice');
    }

    public function testARenameKeepsTheKey(): void
    {
        $alias = $this->alias();
        $alias->addExtraName('Arnaud', null);
        $alias->addExtraName('A', null);

        self::assertTrue($alias->renameExtraName(1, 'Arnaud D.', null));
        self::assertSame('Arnaud D.', $alias->extraName(1));
        self::assertFalse($alias->renameExtraName(1, 'A', null), 'renamed onto another of its names');
        self::assertFalse($alias->renameExtraName(9, 'X', null), 'an unknown key');
    }

    public function testControlCharactersAreDroppedAndALongNameIsCut(): void
    {
        $alias = $this->alias();
        $alias->addExtraName("Ar\r\nnaud", null);
        $alias->addExtraName(str_repeat('x', 300), null);

        self::assertSame('Ar naud', $alias->extraName(1));
        self::assertSame(EmailAlias::EXTRA_NAME_MAX_LENGTH, mb_strlen((string) $alias->extraName(2)));
    }

    public function testAnIdentityIdNamesTheAliasAndTheKeyAndNothingElseParsesAsOne(): void
    {
        $alias = $this->alias(41);

        self::assertSame('n41-2', $alias->identityId(2));
        self::assertSame([41, 2], EmailAlias::parseIdentityId('n41-2'));

        foreach (['41', '7|a@mail.com', 'addr-YUBtYWlsLmNvbQ', 'n41', 'n0-1', 'n41-0', 'n41-2x', 'N41-2', null, 42] as $notOne) {
            self::assertNull(EmailAlias::parseIdentityId($notOne), sprintf('%s parsed as an identity id', var_export($notOne, true)));
        }
    }

    public function testARestoreKeepsKeysAndDropsWhatAFormWouldHaveRefused(): void
    {
        $alias = $this->alias();
        $alias->restoreExtraNames([
            ['key' => 4, 'name' => 'Arnaud'],
            ['key' => 4, 'name' => 'Twice the key'],
            ['key' => 5, 'name' => 'Arnaud'],
            ['key' => 0, 'name' => 'No key'],
            ['key' => 6, 'name' => "  "],
            'not an entry',
            ['key' => 7, 'name' => 'A'],
        ]);

        self::assertSame([['key' => 4, 'name' => 'Arnaud'], ['key' => 7, 'name' => 'A']], $alias->extraNames);
        self::assertSame(8, $alias->addExtraName('a', null));

        $alias->restoreExtraNames(null);
        self::assertSame([], $alias->extraNames, 'a backup from before extra names');
    }

    private function alias(?int $id = null): EmailAlias
    {
        $alias = new EmailAlias(new Account(), 'a@mail.com', EmailAliasSource::Manual, EmailAliasStatus::Active);

        if (null !== $id) {
            new \ReflectionProperty(EmailAlias::class, 'id')->setValue($alias, $id);
        }

        return $alias;
    }
}
