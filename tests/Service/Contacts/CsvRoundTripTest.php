<?php

declare(strict_types=1);

namespace App\Tests\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\CsvExporter;
use App\Service\Contacts\CsvImporter;
use PHPUnit\Framework\TestCase;

/**
 * plMail's CSV export reads back in. The exporter writes camelCase headers;
 * the importer used to know only spreadsheet ones ("First name"), so a file
 * plMail wrote came back without its names.
 */
final class CsvRoundTripTest extends TestCase
{
    public function testWhatTheExportWritesTheImportReads(): void
    {
        $contact             = Contact::create(new User(), 'Haru Tanaka');
        $contact->givenName  = 'Haru';
        $contact->middleName = 'K.';
        $contact->familyName = 'Tanaka';
        $contact->department = 'Research';
        $contact->birthday   = '--05-12';
        $contact->urls       = [['label' => 'work', 'url' => 'https://example.com']];
        $contact->tags       = ['team', 'japan'];
        ContactEmail::create($contact, 'haru@example.com', 'work', true);

        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findBy')->willReturn([$contact]);

        $parsed = new CsvImporter()->import(new CsvExporter($contacts)->exportAll(new User()));

        self::assertCount(1, $parsed);
        self::assertSame('Haru Tanaka', $parsed[0]->displayName);
        self::assertSame('Haru', $parsed[0]->givenName);
        self::assertSame('K.', $parsed[0]->middleName);
        self::assertSame('Tanaka', $parsed[0]->familyName);
        self::assertSame('Research', $parsed[0]->department);
        self::assertSame('--05-12', $parsed[0]->birthday);
        self::assertSame([['label' => 'other', 'url' => 'https://example.com']], $parsed[0]->urls);
        self::assertSame('haru@example.com', $parsed[0]->emails[0]['email']);
        self::assertSame(['team', 'japan'], $parsed[0]->tags);
    }
}
