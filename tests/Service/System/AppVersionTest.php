<?php

declare(strict_types=1);

namespace App\Tests\Service\System;

use App\Service\System\AppVersion;
use PHPUnit\Framework\TestCase;

/**
 * What the admin header says this build is.
 *
 * The claim worth pinning is that a stamped image is believed and an unstamped
 * one does not invent an answer. Both halves matter: a version read from
 * somewhere other than the build argument would describe the wrong thing on
 * every deployment — a container has no .git, so anything it discovered at
 * runtime would be discovered from a mounted volume or from nothing at all —
 * and a checkout that was never built has no version, which is worth saying
 * plainly rather than dressing up as an error.
 *
 * A plain TestCase: this takes three strings and answers three questions.
 */
final class AppVersionTest extends TestCase
{
    public function testAStampedImageReportsWhatItWasBuiltAs(): void
    {
        $version = new AppVersion('v0.0.16', '3172438c0ffee1234567890abcdef', '/nonexistent');

        self::assertSame('v0.0.16', $version->label());
        self::assertTrue($version->isKnown());
    }

    /**
     * Short, because the header is a chip and nobody reads forty hex digits off
     * one — but long enough to paste into `git show`.
     */
    public function testTheCommitIsShortenedForReading(): void
    {
        $version = new AppVersion('v0.0.16', '3172438c0ffee1234567890abcdef', '/nonexistent');

        self::assertSame('3172438', $version->commit());
    }

    /**
     * The state a plain `docker build` with no build arguments leaves behind.
     * It must not fall through to reading a repository — inside a container
     * there is none, and anywhere else it would be describing the wrong tree.
     */
    public function testAnUnstampedBuildInventsNothing(): void
    {
        $version = new AppVersion(null, null, '/nonexistent');

        self::assertSame('development', $version->label());
        self::assertNull($version->commit());
        self::assertFalse($version->isKnown(), 'nothing to show means the header shows nothing');
    }

    /** An empty argument is the same as an absent one: `--build-arg APP_VERSION=`. */
    public function testAnEmptyStampIsTreatedAsNoStamp(): void
    {
        $version = new AppVersion('', '', '/nonexistent');

        self::assertSame('development', $version->label());
        self::assertNull($version->commit());
    }

    /**
     * A commit with no version still counts as knowing something — that is the
     * `main` and `sha-…` image case, where the tag moves and the hash is the
     * only thing that identifies the build.
     */
    public function testACommitAloneIsWorthShowing(): void
    {
        $version = new AppVersion(null, 'abcdef1234567', '/nonexistent');

        self::assertTrue($version->isKnown());
        self::assertSame('abcdef1', $version->commit());
    }

    /**
     * There is deliberately no test that a real checkout describes itself.
     *
     * It would assert a property of wherever the suite is running rather than
     * of this class: the test container has the source but not the history, and
     * where it does have both, git refuses the directory for dubious ownership
     * — so such a test passes on a developer's machine, fails in CI, and says
     * nothing either way about the code. The suite has already been bitten by
     * exactly that shape twice (a sequence gap that only existed on a
     * well-used database, a sidebar state that only existed after another spec
     * ran), and the answer both times was to assert the contract instead.
     *
     * The contract here is above: an unreadable or absent repository means
     * "development", which testAnUnstampedBuildInventsNothing pins with a path
     * that cannot exist.
     */
    public function testTheFallbackIsUnreadableRatherThanUntested(): void
    {
        $version = new AppVersion(null, null, '/nonexistent');

        self::assertSame('development', $version->label());
    }
}
