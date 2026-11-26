<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Infrastructure\Dns\DnsResolver;

/**
 * DNS that answers what a test says it does, and nothing else — so a lookup
 * that reads DNS is tested without a network, and an unexpected name shows up
 * as an empty answer rather than as whatever the real internet says today.
 */
final class FakeDns extends DnsResolver
{
    /** @var list<string> every name asked about, in order */
    public array $asked = [];

    /**
     * @param array<string, list<string>>                                                    $txt
     * @param array<string, list<array{target: string, port: int, priority: int, weight: int}>> $srv
     */
    public function __construct(
        private readonly array $txt = [],
        private readonly array $srv = [],
    ) {}

    public function txt(string $name): array
    {
        $this->asked[] = $name;

        return $this->txt[$name] ?? [];
    }

    public function srv(string $name): array
    {
        $this->asked[] = $name;

        return $this->srv[$name] ?? [];
    }
}
