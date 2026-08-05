<?php

declare(strict_types=1);

namespace App\Service\System;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Which build this is, for someone standing in front of it asking.
 *
 * The question this answers is not vanity — it is the first one worth asking
 * when a deployment behaves unlike the branch somebody is reading, and it has
 * no cheap answer otherwise: migrations run automatically on boot, so "which
 * schema is this?" and "which code is this?" are the same question, and an
 * image is pulled by a tag that moves.
 *
 * **Baked at build time, not read at runtime.** A container has no .git — the
 * Dockerfile copies the source in and the history stays behind — so the ref has
 * to arrive as a build argument and live in the environment. Anything that
 * shelled out to git in production would answer "unknown" at best, and at worst
 * would answer with whatever repository happened to be mounted.
 *
 * The git fallback exists for the other case, and only for it: a developer
 * running from a checkout, where APP_VERSION was never set and .git is right
 * there. It runs once per request at most, is memoised, and never raises —
 * a missing git binary, a shallow clone or a directory that is not a
 * repository all mean "unknown", which is the honest answer and not an error
 * worth failing a page render over.
 */
final class AppVersion
{
    /**
     * What the admin panel shows when there is nothing to show.
     *
     * Deliberately not "unknown": a checkout that was never built has no
     * version and saying so plainly is more useful than a word that reads like
     * something went wrong.
     */
    private const string UNBUILT = 'development';

    /** Memoised including the failure, so a missing git is not asked twice. */
    private ?string $described = null;

    private bool $resolved = false;

    public function __construct(
        #[Autowire('%env(default::APP_VERSION)%')]
        private readonly ?string $version = null,
        #[Autowire('%env(default::APP_COMMIT)%')]
        private readonly ?string $commit = null,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
    ) {
    }

    /**
     * The release this is, when it was built from one — "v0.0.16" — or the
     * describe of a working tree, or "development".
     */
    public function label(): string
    {
        $version = trim((string) $this->version);

        if ('' !== $version) {
            return $version;
        }

        return $this->fromGit() ?? self::UNBUILT;
    }

    /**
     * The exact commit, short. Null when nothing knows it, which is the state a
     * plain `docker build` with no arguments leaves behind — and the reason the
     * label alone is not enough: two images can both call themselves `main`.
     */
    public function commit(): ?string
    {
        $commit = trim((string) $this->commit);

        if ('' !== $commit) {
            return substr($commit, 0, 7);
        }

        return null;
    }

    /** Whether there is anything worth rendering at all. */
    public function isKnown(): bool
    {
        return self::UNBUILT !== $this->label() || null !== $this->commit();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function fromGit(): ?string
    {
        if (true === $this->resolved) {
            return $this->described;
        }

        $this->resolved = true;

        // --always so a repository with no tags still answers, --dirty so a
        // developer is told their working tree is not what the hash says.
        $process = new Process(
            ['git', 'describe', '--tags', '--always', '--dirty'],
            $this->projectDir,
        );

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (false === $process->isSuccessful()) {
            return null;
        }

        $described = trim($process->getOutput());

        return $this->described = '' === $described ? null : $described;
    }
}
