<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * Importer-agnostic parsed contact DTO. Importers (vCard, CSV) produce these;
 * ImportRunner turns them into Contact entities, and a CardDAV pull hands them
 * to VCardMapper::apply().
 */
final class ParsedContact
{
    /** The card's UID; null from CSV, or a vCard that has none. */
    public ?string $uid = null;

    public ?string $displayName = null;
    public ?string $namePrefix = null;
    public ?string $givenName = null;
    public ?string $middleName = null;
    public ?string $familyName = null;
    public ?string $nameSuffix = null;
    public ?string $phoneticGivenName = null;
    public ?string $phoneticMiddleName = null;
    public ?string $phoneticFamilyName = null;
    public ?string $nickname = null;
    public ?string $company = null;
    public ?string $phoneticCompany = null;
    public ?string $department = null;
    public ?string $jobTitle = null;
    public ?string $officeLocation = null;
    public ?string $notes = null;

    /** See Contact::$birthday. */
    public ?string $birthday = null;

    /** @var list<array{label: string, date: string}> */
    public array $dates = [];

    /** @var list<array{label: string, name: string}> */
    public array $relations = [];

    /** @var list<array{label: string, url: string}> */
    public array $urls = [];

    /** @var list<array{service: string, handle: string, label: string}> */
    public array $ims = [];

    /** @var list<array{label: string, value: string}> */
    public array $customFields = [];

    /** @var list<string> */
    public array $tags = [];

    /** @var list<array{email: string, label: string, primary?: bool}> */
    public array $emails = [];

    /** @var list<array{phone: string, label: string, primary?: bool}> */
    public array $phones = [];

    /** @var list<array{label: string, street: ?string, city: ?string, region: ?string, postcode: ?string, country: ?string}> */
    public array $addresses = [];

    /*
     * The photo, in three states: bytes (and their type) when the source
     * carried one; $photoAbsent when it says there is none, so one removed
     * on the server is removed here; neither when it cannot say — a vCard
     * PHOTO that is a link. $photoDeferred is a driver saying the photo is
     * read separately, with AddressBookDriverInterface::photo(), and only if
     * $photoVersion says it changed.
     */
    public ?string $photoBytes = null;
    public ?string $photoMime = null;
    public bool $photoAbsent = false;
    public bool $photoDeferred = false;

    /** See Contact::$photoVersion. */
    public ?string $photoVersion = null;
}
