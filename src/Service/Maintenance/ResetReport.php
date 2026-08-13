<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

/**
 * What a reset actually did, for whoever asked to say so afterwards.
 *
 * Exists because the same work is now reported two ways — the console prints it
 * line by line, the admin panel renders it into a page — and the resetter must
 * not know which. Returning a report rather than writing to a SymfonyStyle is
 * what makes the service callable from a controller at all.
 */
final readonly class ResetReport
{
    /**
     * @param array<string, bool> $tables             table name => truncated, in the order they were attempted.
     *                                                False means the table is not in the schema and was skipped.
     * @param list<string>        $cursorsCleared     'account' and/or 'mailbox'
     * @param list<string>        $emptiedDirectories project-relative paths
     * @param list<string>        $removedSecrets     names actually found in the secrets file and dropped
     * @param int                 $pushRevoked        provider-side push registrations handed back before the
     *                                                truncate — see DataResetter::revokePushFor(). Reported
     *                                                because it is the step whose absence produced weeks of
     *                                                unexplained "unknown channel" warnings, and a step nobody
     *                                                is told about is a step nobody notices has stopped working
     */
    public function __construct(
        public array $tables = [],
        public array $cursorsCleared = [],
        public array $emptiedDirectories = [],
        public array $removedSecrets = [],
        public bool $workersSignalled = false,
        public int $pushRevoked = 0,
    ) {
    }

    /**
     * @return list<string>
     */
    public function truncatedTables(): array
    {
        return array_keys(array_filter($this->tables));
    }

    /**
     * @return list<string>
     */
    public function skippedTables(): array
    {
        return array_keys(array_filter($this->tables, static fn (bool $truncated): bool => false === $truncated));
    }
}
