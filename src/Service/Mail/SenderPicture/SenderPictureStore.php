<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where looked-up sender pictures are kept on disk (#639):
 * `{APP_STORAGE_DIR}/uploads/sender-pictures/ab/abcdef….png`.
 *
 * **Under uploads, because two containers use it** — ContactPhotoStore's
 * lesson, learned the hard way: the worker writes these and the web container
 * serves them, and var/uploads is the volume both mount. Anywhere else the
 * web container answers 404 for every picture the worker found.
 *
 * **Named by contents.** The file name is the SHA-256 of the stored bytes, so
 * fifty addresses at one company share one file, a re-check that finds the
 * same Gravatar writes nothing, and a name can never point at a different
 * picture than the one it was minted for — which is what lets the route cache
 * it as immutable. Two directory characters keep any one directory small.
 */
class SenderPictureStore
{
    private const string DIRECTORY = 'uploads/sender-pictures';

    /** What a stored name looks like; nothing else is ever turned into a path. */
    public const string NAME_PATTERN = '/^[0-9a-f]{64}\.(png|svg)$/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(APP_STORAGE_DIR)%')]
        private readonly string $storageDir = 'var',
    ) {}

    /** Stores the bytes and returns the file name, writing nothing if it is already there. */
    public function store(string $bytes, string $extension): string
    {
        $name = sprintf('%s.%s', hash('sha256', $bytes), $extension);
        $path = (string) $this->path($name);

        if (true === is_file($path)) {
            return $name;
        }

        $directory = dirname($path);

        if (false === is_dir($directory) && false === @mkdir($directory, 0775, true) && false === is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create %s', $directory));
        }

        // Write-then-rename: the web container may be serving this name to a
        // row the moment the job is done, and must never read half a file.
        $temporary = $path . '.' . bin2hex(random_bytes(4));

        if (false === @file_put_contents($temporary, $bytes) || false === @rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Cannot write %s', $path));
        }

        return $name;
    }

    /** The file on disk for a stored name, or null for a name that is not one. */
    public function path(string $name): ?string
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        return sprintf('%s/%s/%s', $this->root(), substr($name, 0, 2), $name);
    }

    /**
     * Deletes every stored file not in $keep. The caller passes what the
     * database still names (SenderPictureRepository::referencedFiles()).
     *
     * @param list<string> $keep
     */
    public function deleteAllExcept(array $keep): int
    {
        $root = $this->root();

        if (false === is_dir($root)) {
            return 0;
        }

        $keep    = array_flip($keep);
        $deleted = 0;

        foreach ((array) glob($root . '/*/*') as $path) {
            $name = basename((string) $path);

            if (1 === preg_match(self::NAME_PATTERN, $name) && false === isset($keep[$name]) && true === @unlink((string) $path)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function root(): string
    {
        // Relative to the project, as ContactPhotoStore and AvatarStorage
        // read the same variable: one meaning for APP_STORAGE_DIR.
        return $this->projectDir . '/' . trim($this->storageDir, '/') . '/' . self::DIRECTORY;
    }
}
