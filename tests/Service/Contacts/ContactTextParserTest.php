<?php

declare(strict_types=1);

namespace App\Tests\Service\Contacts;

use App\Service\Contacts\ContactDraft;
use App\Service\Contacts\ContactTextParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the quick-add box and "Paste details" read out of pasted text, by
 * rules alone — the signatures and cards people actually paste.
 */
final class ContactTextParserTest extends KernelTestCase
{
    private ContactTextParser $parser;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->parser = static::getContainer()->get(ContactTextParser::class);
    }

    public function testACleanSignatureIsReadWhole(): void
    {
        $draft = $this->parser->parse(<<<'TEXT'
            Jonas Weber
            Head of Operations | Kranich Logistik GmbH
            Mobil: +49 160 9876543
            Tel. +49 40 123456-0
            jonas.weber@kranich-logistik.example
            www.kranich-logistik.example
            Hafenstraße 12, 20457 Hamburg
            TEXT, 'DE');

        self::assertSame(ContactDraft::SOURCE_RULES, $draft->source);
        self::assertSame('Jonas Weber', $draft->name);
        self::assertSame(['givenName' => 'Jonas', 'familyName' => 'Weber'], $draft->parts);
        self::assertSame([
            ['email', 'work', 'jonas.weber@kranich-logistik.example'],
            ['url', 'work', 'www.kranich-logistik.example'],
            ['address', 'work', 'Hafenstraße 12, 20457 Hamburg'],
            ['jobTitle', '', 'Head of Operations'],
            ['company', '', 'Kranich Logistik GmbH'],
            ['phone', 'mobile', '+49 160 9876543'],
            ['phone', 'work', '+49 40 123456-0'],
        ], $this->rows($draft));
        self::assertSame(['street' => 'Hafenstraße 12', 'postcode' => '20457', 'city' => 'Hamburg'], $draft->fields[2]['address']);
        self::assertSame([], $draft->unsure);
    }

    public function testAMessySignatureSaysWhatItCouldNotPlace(): void
    {
        $draft = $this->parser->parse(<<<'TEXT'
            Mit freundlichen Grüßen

            Dr. Anna-Lena van der Berg
            Leiterin Einkauf
            BERG & SÖHNE Feinkost
            Fon 0221 98765-12 | Fax -99
            a.vanderberg@berg-soehne.example
            TEXT, 'DE');

        self::assertSame('Dr. Anna-Lena van der Berg', $draft->name);
        self::assertSame(['namePrefix' => 'Dr.', 'givenName' => 'Anna-Lena', 'familyName' => 'van der Berg'], $draft->parts, 'the particle starts the family name');
        self::assertSame(['Mit freundlichen Grüßen'], $draft->skipped);
        self::assertSame(['BERG & SÖHNE Feinkost'], $draft->unsure, 'no suffix, no separator: a question, not a guess');
        self::assertContains(['phone', 'work', '0221 98765-12'], $this->rows($draft));
        self::assertContains(['phone', 'other', '0221 98765-99'], $this->rows($draft), '"Fax -99" completes the number above it');
    }

    public function testAHeaderLineGivesNameAndAddress(): void
    {
        $draft = $this->parser->parse('Mia Hoffmann <mia.hoffmann@gmx.de>');

        self::assertSame('Mia Hoffmann', $draft->name);
        self::assertSame([['email', 'home', 'mia.hoffmann@gmx.de']], $this->rows($draft), 'a mailbox provider is a home address');
    }

    public function testAVCardIsReadByTheImporterNotGuessed(): void
    {
        $draft = $this->parser->parse("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Sophie Klein\r\nN:Klein;Sophie;;;\r\nORG:Studio Klein\r\nTITLE:Architect\r\nEMAIL;TYPE=WORK:sophie@studio-klein.example\r\nTEL;TYPE=CELL:+49 152 1112233\r\nBDAY:1990-05-21\r\nNOTE:Prefers calls before 10.\r\nEND:VCARD\r\n");

        self::assertSame(ContactDraft::SOURCE_VCARD, $draft->source);
        self::assertSame('Sophie Klein', $draft->name);
        self::assertSame(['givenName' => 'Sophie', 'familyName' => 'Klein'], $draft->parts);
        $rows = $this->rows($draft);
        self::assertContains(['email', 'work', 'sophie@studio-klein.example'], $rows);
        self::assertContains(['phone', 'mobile', '+49 152 1112233'], $rows);
        self::assertContains(['company', '', 'Studio Klein'], $rows);
        self::assertContains(['jobTitle', '', 'Architect'], $rows);
        self::assertContains(['birthday', '', '1990-05-21'], $rows);
        self::assertContains(['notes', '', 'Prefers calls before 10.'], $rows);
    }

    public function testAnAddressOverTwoLinesWithACountry(): void
    {
        $draft = $this->parser->parse("Lena Vogt\nTorstraße 140\n10119 Berlin\nDeutschland");

        self::assertSame([['address', 'work', 'Torstraße 140, 10119 Berlin, Deutschland']], $this->rows($draft));
    }

    public function testANumberWithoutCountryIsWrittenInternationallyAndKnownAsMobile(): void
    {
        $draft = $this->parser->parse("Paul Becker\n0171 2345678", 'DE');

        self::assertSame([['phone', 'mobile', '+49 171 2345678']], $this->rows($draft));
    }

    public function testJustANameIsAName(): void
    {
        $draft = $this->parser->parse('Paul Becker');

        self::assertSame('Paul Becker', $draft->name);
        self::assertSame([], $draft->fields);
        self::assertSame([], $draft->unsure);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function names(): iterable
    {
        yield 'two words'         => ['Ada Lovelace', ['givenName' => 'Ada', 'familyName' => 'Lovelace']];
        yield 'middle name'       => ['Lena Marie Vogt', ['givenName' => 'Lena', 'middleName' => 'Marie', 'familyName' => 'Vogt']];
        yield 'prefix'            => ['Prof. Dr. Hans Meier', ['namePrefix' => 'Prof. Dr.', 'givenName' => 'Hans', 'familyName' => 'Meier']];
        yield 'suffix'            => ['Martin Luther King, Jr.', ['givenName' => 'Martin', 'middleName' => 'Luther', 'familyName' => 'King', 'nameSuffix' => 'Jr.']];
        yield 'particle'          => ['Ursula von der Leyen', ['givenName' => 'Ursula', 'familyName' => 'von der Leyen']];
        yield 'abbreviations'     => ['Dr. Hon. Med. Sergej Gil', ['namePrefix' => 'Dr. Hon. Med.', 'givenName' => 'Sergej', 'familyName' => 'Gil']];
        yield 'lower-case title'  => ['Dr. rer. nat. Eva Schulz', ['namePrefix' => 'Dr. rer. nat.', 'givenName' => 'Eva', 'familyName' => 'Schulz']];
        yield 'title, no dots'    => ['Dr med Hans Meier', ['namePrefix' => 'Dr med', 'givenName' => 'Hans', 'familyName' => 'Meier']];
        yield 'title, particle'   => ['Dr. von Braun', ['namePrefix' => 'Dr.', 'givenName' => 'von', 'familyName' => 'Braun']];
        yield 'initials'          => ['J. R. R. Tolkien', ['givenName' => 'J.', 'middleName' => 'R. R.', 'familyName' => 'Tolkien']];
        yield 'mixed-case degree' => ['Anna Weber MPhil', ['givenName' => 'Anna', 'familyName' => 'Weber', 'nameSuffix' => 'MPhil']];
        yield 'dotted degrees'    => ['Tom Hall, LL.M., M.A.', ['givenName' => 'Tom', 'familyName' => 'Hall', 'nameSuffix' => 'LL.M. M.A.']];
        yield 'caps after comma'  => ['Tom Hall, MBA, CPA', ['givenName' => 'Tom', 'familyName' => 'Hall', 'nameSuffix' => 'MBA CPA']];
        yield 'caps family name'  => ['Jean DUPONT', ['givenName' => 'Jean', 'familyName' => 'DUPONT']];
        yield 'one word'          => ['Cher', ['givenName' => 'Cher']];
        yield 'nothing'           => ['', []];
    }

    /**
     * @param array<string, string> $parts
     */
    #[DataProvider('names')]
    public function testANameIsSplitIntoItsParts(string $name, array $parts): void
    {
        self::assertSame($parts, ContactTextParser::splitName($name));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function rows(ContactDraft $draft): array
    {
        return array_map(static fn (array $f): array => [$f['type'], $f['label'], $f['value']], $draft->fields);
    }
}
