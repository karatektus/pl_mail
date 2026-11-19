<?php

declare(strict_types=1);

namespace App\Service\Job;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where an uploaded file waits for the background job that reads it (#672):
 * a contacts import between its preview and the worker, an .ics until the
 * worker imports it.
 *
 * UNDER var/uploads, AND THAT IS THE POINT. The web container and the worker
 * are separate containers and share only a few volumes; var/uploads is the
 * one meant for files both sides read (see SenderPictureStore). The contacts
 * preview used to stage under var/contacts/_staging, which is local to the
 * web container: a worker handed that path would find nothing there.
 *
 * In a directory of its own, `job-staging/<user>/`, which app:prune:blobs does
 * not walk — it only touches files laid out the way its own writers lay them
 * out — and swept here instead: a file older than a day belongs to a preview
 * nobody confirmed, or a job that died before it could delete it.
 *
 * Names are random and checked on the way back in, so a name read off a job
 * row or a session can only ever reach a file in that user's directory.
 */
final readonly class JobUploadStore
{
    private const int MAX_AGE_SECONDS = 86400;

    private const string NAME_PATTERN = '/^[0-9a-f]{16}\.(vcf|csv|ics)$/';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/job-staging')]
        private string $root,
    ) {
    }

    /**
     * Move the upload into the user's directory. Returns the name to hand to
     * the job, which is all a later read() needs besides the user.
     */
    public function keep(int $userId, UploadedFile $file, string $extension): string
    {
        $dir = $this->dir($userId);
        $this->sweep($dir);

        $name = bin2hex(random_bytes(8)) . '.' . $extension;
        $file->move($dir, $name);

        return $name;
    }

    /** The staged file's path, or null when it is gone or the name is not one of ours. */
    public function path(int $userId, string $name): ?string
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $path = $this->dir($userId) . '/' . $name;

        return true === is_file($path) ? $path : null;
    }

    public function forget(int $userId, string $name): void
    {
        $path = $this->path($userId, $name);

        if (null !== $path) {
            @unlink($path);
        }
    }

    private function dir(int $userId): string
    {
        $dir = $this->root . '/' . $userId;

        if (false === is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function sweep(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (true === is_file($file) && filemtime($file) < time() - self::MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
