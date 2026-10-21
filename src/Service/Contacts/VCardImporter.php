<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * Parses a .vcf file into ParsedContact DTOs for ImportRunner.
 *
 * The parsing itself is VCardMapper's, the same that reads cards from a
 * CardDAV server, so an imported card and a synced one mean the same thing.
 */
final readonly class VCardImporter
{
    public function __construct(
        private VCardMapper $mapper,
    ) {
    }

    /**
     * @return list<ParsedContact>
     */
    public function import(string $content): array
    {
        return $this->mapper->parseAll($content);
    }
}
