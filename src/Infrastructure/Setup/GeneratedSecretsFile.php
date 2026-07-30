<?php

declare(strict_types=1);

namespace App\Infrastructure\Setup;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one file per install that holds what nobody configured by hand: the
 * generated secrets, and the handful of settings chosen during setup.
 *
 * `frankenphp/docker-entrypoint.sh` creates it and writes APP_SECRET and
 * APP_ENCRYPTION_KEY into it before PHP ever boots — those two are needed by
 * the kernel itself, so they cannot come from a console command. Everything
 * that *can* wait until PHP is up is added here instead, by
 * `app:secrets:init`, so there is still only one place to back up.
 *
 * Writes take the same lock the entrypoint uses, for the same reason: four
 * services start from this image at once, and two of them generating the same
 * secret independently is a failure that shows up much later, as data one
 * container can read and another cannot.
 */
final readonly class GeneratedSecretsFile
{
    public function __construct(
        #[Autowire(env: 'APP_SECRETS_FILE')]
        private string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Return the stored value for $name, generating and appending it first if
     * it is absent. The generator is only ever called while the lock is held,
     * so a value is minted once per install however many services start.
     *
     * @param callable(): string $generate
     */
    public function ensure(string $name, callable $generate): string
    {
        $handle = $this->open();

        try {
            if (false === flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Could not lock %s.', $this->path));
            }

            // Re-read under the lock: another service may have written this
            // between our first read and acquiring it.
            $existing = $this->readFrom($handle);

            if (isset($existing[$name]) && '' !== $existing[$name]) {
                return $existing[$name];
            }

            $value = $generate();

            if (false === fwrite($handle, sprintf("%s=%s\n", $name, $value))) {
                throw new RuntimeException(sprintf('Could not write %s to %s.', $name, $this->path));
            }

            return $value;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, string>
     */
    public function read(): array
    {
        if (false === is_file($this->path)) {
            return [];
        }

        $handle = $this->open();

        try {
            flock($handle, LOCK_SH);

            return $this->readFrom($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Write $name, replacing whatever was there.
     *
     * Unlike ensure(), this is for values a human chose rather than values the
     * install minted — APP_PUBLIC_URL, entered during setup. Same file because
     * it is the one place a running container can write that every other
     * service already reads.
     */
    public function set(string $name, string $value): void
    {
        $handle = $this->open();

        try {
            if (false === flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Could not lock %s.', $this->path));
            }

            $values        = $this->readFrom($handle);
            $values[$name] = $value;

            // Rewritten whole: appending a second line for the same name would
            // leave the old one above it, and read() takes the last it sees.
            ftruncate($handle, 0);
            rewind($handle);

            foreach ($values as $key => $stored) {
                fwrite($handle, sprintf("%s=%s\n", $key, $stored));
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Drop the named values, leaving the rest of the file alone.
     *
     * Used by `app:reset --full`, which puts an install back to first-run
     * state. POSTGRES_PASSWORD is never among them — see the note there.
     *
     * @param list<string> $names
     *
     * @return list<string> the names that were actually present
     */
    public function remove(array $names): array
    {
        $handle = $this->open();

        try {
            if (false === flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Could not lock %s.', $this->path));
            }

            $values  = $this->readFrom($handle);
            $removed = array_values(array_intersect($names, array_keys($values)));

            foreach ($names as $name) {
                unset($values[$name]);
            }

            ftruncate($handle, 0);
            rewind($handle);

            foreach ($values as $key => $stored) {
                fwrite($handle, sprintf("%s=%s\n", $key, $stored));
            }

            return $removed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function has(string $name): bool
    {
        $values = $this->read();

        return isset($values[$name]) && '' !== $values[$name];
    }

    /**
     * @return resource
     */
    private function open()
    {
        $dir = \dirname($this->path);

        if (false === is_dir($dir) && false === @mkdir($dir, 0700, true) && false === is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create the secrets directory %s.', $dir));
        }

        // 'c+' creates without truncating, which is what makes it safe to open
        // the file before taking the lock.
        $handle = @fopen($this->path, 'c+');

        if (false === $handle) {
            throw new RuntimeException(sprintf('Could not open the secrets file %s.', $this->path));
        }

        @chmod($this->path, 0600);

        return $handle;
    }

    /**
     * @param resource $handle
     *
     * @return array<string, string>
     */
    private function readFrom($handle): array
    {
        rewind($handle);

        $values = [];

        while (false !== ($line = fgets($handle))) {
            $line = trim($line);

            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');

            $values[$name] = $value;
        }

        // Leave the handle at EOF so a subsequent write appends.
        fseek($handle, 0, SEEK_END);

        return $values;
    }
}
