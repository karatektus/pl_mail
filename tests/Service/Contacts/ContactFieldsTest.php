<?php

declare(strict_types=1);

namespace App\Tests\Service\Contacts;

use App\Service\Contacts\ContactFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dates in every spelling the sources write, to the two plMail keeps; and
 * the cleaning every writer of a labelled list goes through.
 */
final class ContactFieldsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function dates(): iterable
    {
        yield 'extended'                  => ['1980-05-12', '1980-05-12'];
        yield 'basic, vCard 4'            => ['19800512', '1980-05-12'];
        yield 'no year, basic'            => ['--0512', '--05-12'];
        yield 'no year, extended'         => ['--05-12', '--05-12'];
        yield "Apple's no year"           => ['1604-05-12', '--05-12'];
        yield "Outlook's timestamp"       => ['1980-05-12T11:59:00Z', '1980-05-12'];
        yield "Outlook's no year"         => ['1601-05-12T00:00:00Z', '--05-12'];
        yield 'not a day'                 => ['1980-02-30', null];
        yield 'the 29th without a year'   => ['--02-29', '--02-29'];
        yield 'text'                      => ['next spring', null];
        yield 'nothing'                   => [null, null];
    }

    #[DataProvider('dates')]
    public function testADateIsReadInEverySpelling(?string $written, ?string $kept): void
    {
        self::assertSame($kept, ContactFields::date($written));
    }

    public function testTheFormsThreeFieldsMakeADate(): void
    {
        self::assertSame('--12-10', ContactFields::fromForm(['day' => '10', 'month' => '12', 'year' => '']));
        self::assertSame('1815-12-10', ContactFields::fromForm(['day' => '10', 'month' => '12', 'year' => '1815']));
        self::assertNull(ContactFields::fromForm(['day' => '', 'month' => '12', 'year' => '1815']), 'a month alone is not a date');
        self::assertNull(ContactFields::fromForm('1815-12-10'));
    }

    public function testAListIsCleanedRowByRow(): void
    {
        self::assertSame([
            ['label' => 'other', 'name' => 'Mary'],
            ['label' => 'Godmother', 'name' => 'Ann'],
        ], ContactFields::relations([
            ['label' => '', 'name' => ' Mary '],
            ['label' => 'other', 'name' => 'Mary'],
            ['label' => 'spouse', 'name' => ''],
            'not a row',
            ['label' => 'Godmother', 'name' => 'Ann', 'extra' => 'dropped'],
        ]), 'trimmed, an empty label made other, empty and repeated rows and unknown keys left out');

        self::assertSame([['service' => 'other', 'handle' => 'h', 'label' => 'other']], ContactFields::ims([['handle' => 'h']]));
        self::assertSame([['label' => 'anniversary', 'date' => '--06-01']], ContactFields::dates([['label' => 'anniversary', 'date' => '1604-06-01'], ['label' => 'other', 'date' => 'never']]));
    }
}
