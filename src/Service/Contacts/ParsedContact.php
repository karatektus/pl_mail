<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * Importer-agnostic parsed contact DTO. Importers (vCard, CSV) produce these;
 * ImportRunner turns them into Contact entities.
 */
final class ParsedContact
{
    public ?string $displayName = null;
    public ?string $givenName = null;
    public ?string $familyName = null;
    public ?string $nickname = null;
    public ?string $company = null;
    public ?string $jobTitle = null;
    public ?string $notes = null;

    /** @var list<string> */
    public array $tags = [];

    /** @var list<array{email: string, label: string}> */
    public array $emails = [];

    /** @var list<array{phone: string, label: string}> */
    public array $phones = [];

    /** @var list<array{label: string, street: ?string, city: ?string, region: ?string, postcode: ?string, country: ?string}> */
    public array $addresses = [];

    public ?string $photoBytes = null;
    public ?string $photoMime = null;
}
