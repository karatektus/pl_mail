<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\Maintenance\DataResetRepository;
use App\Service\Monitoring\WorkerRestartSignal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Emptying a plMail install, to whichever depth was asked for.
 *
 * This used to live inside `app:reset` and is now shared with the admin panel.
 * The command is the documented recovery path for when the web UI is
 * unreachable — which is precisely when someone needs it — so it keeps working
 * exactly as it did; it just prints this class's report instead of doing the
 * work itself. Two implementations of "delete everything" that could drift
 * apart is not a risk worth taking with an operation that has no undo.
 *
 * Nothing here writes to a console or a session. What happened comes back as a
 * ResetReport and the caller decides how to say it.
 */
final readonly class DataResetter
{
    /**
     * Everything generated on first run except the database password.
     *
     * That one stays: Postgres was initialised with it and keeps it in its own
     * data directory, so regenerating it would leave the app unable to log in
     * to the database it just reset. Wiping the database volume is the only way
     * to change it, and that is `docker compose down -v`, not this.
     */
    public const array RESETTABLE_SECRETS = [
        'APP_SECRET',
        'APP_ENCRYPTION_KEY',
        'MERCURE_JWT_SECRET',
        'VAPID_PUBLIC_KEY',
        'VAPID_PRIVATE_KEY',
        'APP_PUBLIC_URL',
    ];

    /**
     * Referenced by rows that will not exist afterwards, so leaving them would
     * be leaking disk to nothing.
     */
    private const array STORED_FILE_DIRECTORIES = [
        'var/attachments',
        'var/raw',
        'var/uploads',
    ];

    public function __construct(
        private DataResetRepository $tables,
        private GeneratedSecretsFile $secrets,
        private Filesystem $filesystem,
        private WorkerRestartSignal $workerRestart,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Delete synced data down to the depth $scope asks for, leaving the install
     * usable and the operator signed in.
     */
    public function reset(ResetScope $scope): ResetReport
    {
        $tables = [
            'messenger_messages',
            'message_part',
            'message_label',
            'thread_label',
            'message',
            'message_thread',
            'jmap_change_log',
            'uploaded_blob',
        ];

        if (true === $scope->mailboxes) {
            // Before label: label_binding FKs both, and mailbox is referenced
            // by binding.mailbox_id.
            $tables[] = 'label_binding';
            $tables[] = 'label';
            $tables[] = 'mailbox';
        }

        if (true === $scope->contacts) {
            $tables[] = 'contact';
        }

        if (true === $scope->accounts) {
            // Every table with an account_id, then the accounts. The user rows
            // stay: dropping those would lock you out of the app you are
            // resetting.
            $tables[] = 'email_alias';
            $tables[] = 'account';
        }

        if (true === $scope->monitoring) {
            $tables[] = 'log_entry';
            $tables[] = 'process_heartbeat';
        }

        $existing = $this->tables->existingTables();
        $attempted = [];

        $this->tables->disableForeignKeyChecks();

        try {
            foreach ($tables as $table) {
                if (false === in_array($table, $existing, true)) {
                    $attempted[$table] = false;

                    continue;
                }

                $this->tables->truncate($table);
                $attempted[$table] = true;
            }

            $cursorsCleared = [];

            // Clear per-account sync cursors so the next run re-syncs from
            // scratch. Pointless when the accounts themselves are gone.
            if (false === $scope->accounts) {
                $this->tables->clearAccountSyncCursors();
                $cursorsCleared[] = 'account';
            }

            if (false === $scope->mailboxes) {
                $this->tables->clearMailboxSyncCursors();
                $cursorsCleared[] = 'mailbox';
            }
        } finally {
            // In a finally block, unlike the code this was extracted from: a
            // statement that throws half-way would otherwise leave foreign-key
            // enforcement off for the rest of the session, and in the web
            // process a session is a pooled connection that the next request
            // inherits.
            $this->tables->enableForeignKeyChecks();
        }

        return new ResetReport(
            tables: $attempted,
            cursorsCleared: $cursorsCleared,
        );
    }

    /**
     * Put the install back to the state a fresh `docker compose up` produces:
     * no data, no users, optionally no generated secrets. The next page load
     * offers the create-your-account screen again.
     *
     * Deliberately separate from reset() rather than another flag on it. That
     * one keeps you signed in and keeps your accounts syncing; this one deletes
     * the user calling it, and there is nothing to undo it with.
     */
    public function fullReset(bool $rotateSecrets): ResetReport
    {
        $tables = $this->tables->everyDataTable();
        $attempted = [];

        $this->tables->disableForeignKeyChecks();

        try {
            foreach ($tables as $table) {
                $this->tables->truncate($table);
                $attempted[$table] = true;
            }
        } finally {
            $this->tables->enableForeignKeyChecks();
        }

        $emptied = [];

        foreach (self::STORED_FILE_DIRECTORIES as $directory) {
            if (true === $this->emptyDirectory($this->projectDir . '/' . $directory)) {
                $emptied[] = $directory;
            }
        }

        if (false === $rotateSecrets) {
            // Deliberately untouched. Rotating the encryption key cannot be
            // made safe from inside a running fleet: the other services hold
            // the old one in memory until they restart, so for a while half of
            // them cannot read what the other half writes. The data it
            // protected is gone anyway, which is most of the reason to rotate.
            return new ResetReport(tables: $attempted, emptiedDirectories: $emptied);
        }

        $removed = $this->secrets->remove(self::RESETTABLE_SECRETS);

        $this->emptyDirectory($this->projectDir . '/var/secrets/jwt');

        // Best effort: the workers recycle onto the new key rather than
        // lingering on the old one. The web process cannot be recycled this way
        // — see WebProcessRestart — which is why the caller still has to say a
        // restart is required.
        $workersSignalled = true;

        try {
            $this->workerRestart->request();
        } catch (Throwable) {
            // A nudge that fails changes nothing about what has to happen next.
            $workersSignalled = false;
        }

        return new ResetReport(
            tables: $attempted,
            // The JWT directory stays out of emptiedDirectories: it is not
            // stored user data, it is half of the secret rotation, and callers
            // report it beside the secret names rather than beside the
            // attachments.
            emptiedDirectories: $emptied,
            removedSecrets: $removed,
            workersSignalled: $workersSignalled,
        );
    }

    /**
     * Delete everything inside a directory, but not the directory.
     *
     * These paths are bind mounts — attachments, raw messages and uploads are
     * on host storage so the web container and the workers can both reach them
     * — and a mount point cannot be removed from inside the container:
     * rmdir() answers "Device or resource busy". Emptying is what was meant
     * anyway; the directory itself has to survive for the next write.
     *
     * @return bool whether there was a directory to empty
     */
    private function emptyDirectory(string $path): bool
    {
        if (false === is_dir($path)) {
            return false;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
            $this->filesystem->remove($path . '/' . $child);
        }

        return true;
    }
}
