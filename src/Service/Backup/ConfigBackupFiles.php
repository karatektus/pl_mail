<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Enum\Backup\ConfigBackupDisposition;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The secrets volume, as a config backup sees it: three files, addressed by a
 * name rather than by a path.
 *
 * **Logical names, local paths.** The document says `jwt/private.pem`; where
 * that lands is decided by the instance opening it, from its own
 * JWT_SECRET_KEY. Exporting absolute paths would be exporting the source's
 * layout, and a TrueNAS install restoring a backup made on a Pi would be told
 * to write into a directory that is not there. The bytes are the portable part;
 * the address is not.
 *
 * **What is here, and what is deliberately not.** The JWT keypair, because it
 * is generated per install and every service in the stack has to verify tokens
 * the others signed — an instance that regenerates it invalidates every JMAP
 * client's session at once. `postgres_password`, because it is a real file the
 * Postgres image reads at container-create time and a restore that forgets it
 * is a stack that will not come up. NOT generated.env: everything in it is
 * already in the env section by name, and a second copy as an opaque blob would
 * be two descriptions of one thing with no way to tell which the importer used.
 *
 * **Writability is measured, not assumed.** is_writable on the directory, per
 * file, at the moment the review is built. The web service and the workers
 * mount this volume in configurations plMail does not control, and one that
 * mounts it read-only is a supported deployment — the review has to say so
 * rather than fail at the write.
 */
final readonly class ConfigBackupFiles
{
    public const string JWT_PRIVATE = 'jwt/private.pem';

    public const string JWT_PUBLIC = 'jwt/public.pem';

    /**
     * The Postgres image's own copy of the password, written beside the
     * generated secrets by frankenphp/generate-secrets.sh.
     *
     * Carried so a restore is complete, never applied — and the reason is a
     * property of the Postgres image rather than a caution. `generate-secrets`
     * rewrites this file from the `POSTGRES_PASSWORD=` line in `generated.env`
     * on *every* boot, so it does stay in step with the secrets file; but the
     * image consumes `POSTGRES_PASSWORD_FILE` only at initdb, when the data
     * directory is first created. On a database that already exists the ROLE
     * keeps the password it was created with, and plMail — a client of that
     * database, not its administrator — cannot do the other half. Writing this
     * alone produces an instance that cannot connect on its next start.
     */
    public const string POSTGRES_PASSWORD = 'postgres_password';

    /** @var list<string> */
    private const array NAMES = [
        self::JWT_PRIVATE,
        self::JWT_PUBLIC,
        self::POSTGRES_PASSWORD,
    ];

    /**
     * What an export carries: postgres_password is deliberately not in it -
     * see ConfigBackupEnvironment::VARIABLES for the reasoning. It stays in
     * NAMES so an OLD backup that carries the file is still recognised and
     * classified External on import, rather than silently dropped.
     *
     * @var list<string>
     */
    private const array EXPORTED = [
        self::JWT_PRIVATE,
        self::JWT_PUBLIC,
    ];

    /**
     * Largest file this will carry, per file.
     *
     * A PEM is two kilobytes and a password is twenty-four bytes. The ceiling
     * exists because these paths are configuration: an install that pointed
     * JWT_SECRET_KEY at something enormous would otherwise turn an export into
     * an out-of-memory, base64-encoded, at 4/3 the size.
     */
    private const int MAX_BYTES = 262144;

    public function __construct(
        // resolve:, because these carry %kernel.project_dir% the way lexik's
        // own configuration does — the same note InitSecretsCommand makes.
        #[Autowire('%env(resolve:JWT_SECRET_KEY)%')]
        private string $jwtPrivatePath,
        #[Autowire('%env(resolve:JWT_PUBLIC_KEY)%')]
        private string $jwtPublicPath,
        #[Autowire(env: 'resolve:APP_SECRETS_FILE')]
        private string $generatedSecretsPath,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return self::NAMES;
    }

    public function pathFor(string $name): string
    {
        return match ($name) {
            self::JWT_PRIVATE       => $this->jwtPrivatePath,
            self::JWT_PUBLIC        => $this->jwtPublicPath,
            self::POSTGRES_PASSWORD => \dirname($this->generatedSecretsPath) . '/' . self::POSTGRES_PASSWORD,
            default                 => throw new RuntimeException(sprintf('%s is not a file a config backup carries.', $name)),
        };
    }

    /**
     * Every carried file that exists here, base64-encoded.
     *
     * base64 rather than raw: the document is JSON, a PEM is text today and the
     * postgres password file is bytes, and one encoding for both means the
     * importer has no per-file rule to get wrong.
     *
     * @return array<string, string>
     */
    public function export(): array
    {
        $files = [];

        foreach (self::EXPORTED as $name) {
            $contents = $this->read($name);

            if (null !== $contents) {
                $files[$name] = base64_encode($contents);
            }
        }

        return $files;
    }

    /** The bytes on this instance, or null when the file is absent or unreadable. */
    public function read(string $name): ?string
    {
        $path = $this->pathFor($name);

        if (false === is_file($path) || false === is_readable($path)) {
            return null;
        }

        $size = filesize($path);

        if (false === $size || $size > self::MAX_BYTES) {
            return null;
        }

        $contents = file_get_contents($path);

        return false === $contents ? null : $contents;
    }

    /**
     * What becomes of one restored file.
     *
     * The order matters: the postgres password is refused on principle before
     * anybody looks at permissions, so an instance that happens to have a
     * writable secrets volume is not offered a write that would break it.
     *
     * The JWT keypair is `AppliedOnRestart` rather than `Applied` even though
     * the bytes land immediately. lexik's key loader reads and parses the PEM
     * once per process, and under FrankenPHP's worker mode a process outlives
     * many requests — so the tokens this container signs go on being signed
     * with the old key until it is restarted. Calling that "applied" would be
     * true of the disk and false of the behaviour.
     */
    public function dispositionFor(string $name): ConfigBackupDisposition
    {
        if (self::POSTGRES_PASSWORD === $name) {
            return ConfigBackupDisposition::External;
        }

        return $this->isWritable($name)
            ? ConfigBackupDisposition::AppliedOnRestart
            : ConfigBackupDisposition::NotWritable;
    }

    /**
     * An existing file has to be writable itself; an absent one needs a
     * directory that can be created, which is not the same test and is the one
     * that decides the case that actually happens — a fresh install restoring a
     * backup before `app:secrets:init` has ever made var/secrets/jwt/.
     *
     * So the walk upwards: the nearest ancestor that exists is the one whose
     * permissions decide whether the rest can be made. Stopping at the
     * immediate parent answered "no" for every fresh install and sent the JWT
     * keypair to the manual list, which is a lie in the safe direction but
     * still a lie.
     */
    public function isWritable(string $name): bool
    {
        $path = $this->pathFor($name);

        if (true === is_file($path)) {
            return is_writable($path);
        }

        $directory = \dirname($path);

        while (false === is_dir($directory)) {
            $parent = \dirname($directory);

            // Reached the filesystem root without finding anything: there is no
            // ancestor left to ask, so the answer is no.
            if ($parent === $directory) {
                return false;
            }

            $directory = $parent;
        }

        return is_writable($directory);
    }

    /**
     * @throws RuntimeException when the write fails despite isWritable() having
     *                          said it would not — a race, or a full disk
     */
    public function write(string $name, string $contents): void
    {
        $path      = $this->pathFor($name);
        $directory = \dirname($path);

        if (false === is_dir($directory) && false === @mkdir($directory, 0700, true) && false === is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create the secrets directory %s.', $directory));
        }

        if (false === @file_put_contents($path, $contents)) {
            throw new RuntimeException(sprintf('Could not write %s.', $path));
        }

        // The same mode lexik's own generator leaves behind. A private key that
        // arrives world-readable because it came out of a backup rather than
        // out of openssl is a difference nobody would look for.
        @chmod($path, 0600);
    }
}
