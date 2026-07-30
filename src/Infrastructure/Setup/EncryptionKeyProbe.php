<?php

declare(strict_types=1);

namespace App\Infrastructure\Setup;

use App\Repository\Mail\AccountRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\ConversionException;
use RuntimeException;

/**
 * Checks, once per container start, that the APP_ENCRYPTION_KEY in force can
 * actually open the credentials already in the database.
 *
 * This is the detector for the two ways a generated-secret setup goes wrong,
 * both of which are otherwise silent:
 *
 *  - a service is missing the volume the generated secrets live on, so it mints
 *    its own key and disagrees with the services that wrote the data;
 *  - APP_ENCRYPTION_KEY was set in the environment, that setting was dropped,
 *    and a fresh key was generated over the top.
 *
 * Either would keep working right up until a sync worker tried to log into a
 * mailbox, and re-saving an account under the wrong key would overwrite data
 * the right key could still have read. Failing at startup costs one unreadable
 * error message; not failing costs the credentials.
 */
final readonly class EncryptionKeyProbe
{
    public function __construct(
        private AccountRepository $accounts,
        private GeneratedSecretsFile $secrets,
    ) {
    }

    /**
     * @throws RuntimeException when stored credentials exist and cannot be read
     */
    public function verify(): void
    {
        try {
            // Hydration is the check: EncryptedStringType decrypts on read, so
            // a key that does not fit surfaces here rather than silently later.
            $this->accounts->findOneWithStoredCredentials();
        } catch (ConversionException $e) {
            throw new RuntimeException($this->explain(), previous: $e);
        } catch (DbalException) {
            // No account table yet: a database that has not been migrated has
            // nothing to protect, so there is nothing to check.
        }
    }

    private function explain(): string
    {
        return implode("\n", [
            'APP_ENCRYPTION_KEY cannot decrypt the credentials already stored in this database.',
            '',
            'Nothing has been changed. Refusing to start, because saving an account under the',
            'wrong key would overwrite data the right key can still read.',
            '',
            'The two usual causes:',
            sprintf('  1. This service cannot see %s — the volume holding the', $this->secrets->path()),
            '     generated secrets must be mounted on every plMail service, not just some.',
            '  2. The key changed while the stack was running, so part of the fleet wrote data',
            '     the rest cannot read. Putting the original value back is the only way to',
            '     recover that data.',
            '',
            'If the unreadable data is expendable — a fresh install, or accounts you can add',
            'again — clear it and start over. This command does not block console commands,',
            'so from a running-or-not container:',
            '',
            '    docker compose exec php php bin/console app:reset --full',
            '',
            'then restart the stack.',
        ]);
    }
}
