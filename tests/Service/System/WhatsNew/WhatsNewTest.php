<?php

declare(strict_types=1);

namespace App\Tests\Service\System\WhatsNew;

use App\Entity\User\User;
use App\Service\System\AppVersion;
use App\Service\System\WhatsNew\ChangelogReader;
use App\Service\System\WhatsNew\WhatsNew;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * When What's new has something to say, and to whom (#165).
 *
 * The rules the owner set: after an update and never on an install; everybody
 * who was here before starts at 0.3.1; "Don't show this after updates" is
 * silence, dot included; and a build that is not a tag counts as the newest
 * release in its changelog.
 */
final class WhatsNewTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'changelog');

        file_put_contents($this->file, <<<'MD'
            ## Unreleased

            **Coming.** Not yet.

            ## v0.3.6 — 2026-10-16

            **Six.** Text.

            ## v0.3.5 — 2026-10-13

            **Five.** Text.

            ## v0.3.2 — 2026-10-09

            **Two.** Text.

            ## v0.3.1 — 2026-10-08

            **One.** Text.
            MD);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function labels(): iterable
    {
        yield 'a release tag'      => ['v0.3.6', '0.3.6'];
        yield 'without the v'      => ['0.3.6', '0.3.6'];
        yield 'the main image'     => ['main', '0.3.6'];
        yield 'a commit image'     => ['sha-1a2b3c4', '0.3.6'];
        yield 'a checkout'         => ['v0.3.5-12-g1a2b3c4-dirty', '0.3.6'];
    }

    #[DataProvider('labels')]
    public function testABuildIsItsTagOrTheNewestReleaseItHolds(string $label, ?string $expected): void
    {
        self::assertSame($expected, $this->whatsNew($label)->running());
    }

    /** Somebody who was here before the dialog: 0.3.1, from the migration. */
    public function testAnUpdateShowsEveryReleaseSinceTheOneLastSeen(): void
    {
        $user = $this->user('0.3.1');
        $news = $this->whatsNew('v0.3.6');

        self::assertSame(['0.3.6', '0.3.5', '0.3.2'], $this->versions($news->since($news->seenBy($user))));
        self::assertTrue($news->shouldAnnounce($user));
    }

    /** A fresh install: nobody has a version until their first request records the running one. */
    public function testSomebodyNewStartsOnTheRunningReleaseAndIsToldNothing(): void
    {
        $user = $this->user(null);
        $news = $this->whatsNew('v0.3.6');

        self::assertFalse($news->shouldAnnounce($user), 'nothing recorded reads as the running release');

        self::assertTrue($news->startIfNew($user));
        self::assertSame('0.3.6', $user->whatsNewSeenVersion);
        self::assertFalse($news->startIfNew($user), 'once, and never again');
    }

    /** An install that follows main: the dialog is about the newest tagged release, never about Unreleased. */
    public function testAMainBuildAnnouncesItsNewestReleaseAndNothingUnreleased(): void
    {
        $news = $this->whatsNew('main');

        self::assertSame(['0.3.6', '0.3.5', '0.3.2'], $this->versions($news->since('0.3.1')));
        self::assertTrue($news->shouldAnnounce($this->user('0.3.1')));
        self::assertFalse($news->shouldAnnounce($this->user('0.3.6')));

        $new = $this->user(null);
        self::assertTrue($news->startIfNew($new));
        self::assertSame('0.3.6', $new->whatsNewSeenVersion);
    }

    public function testQuietMeansNoAnnouncementThoughThereIsNews(): void
    {
        $user = $this->user('0.3.1');
        $user->whatsNewQuiet = true;

        $news = $this->whatsNew('v0.3.6');

        self::assertTrue($news->hasUnseen($user));
        self::assertFalse($news->shouldAnnounce($user));
    }

    public function testSeenNeverMovesBackwards(): void
    {
        $user = $this->user('0.3.6');

        $this->whatsNew('v0.3.5')->markSeen($user);
        self::assertSame('0.3.6', $user->whatsNewSeenVersion, 'rolling back must not make the next update old news');

        $other = $this->user('0.3.1');
        $this->whatsNew('v0.3.6')->markSeen($other);
        self::assertSame('0.3.6', $other->whatsNewSeenVersion);
        self::assertFalse($this->whatsNew('v0.3.6')->shouldAnnounce($other));
    }

    /** A release newer than the build is not one this install has, whatever the file says. */
    public function testNothingNewerThanTheRunningReleaseIsShown(): void
    {
        $news = $this->whatsNew('v0.3.5');

        self::assertSame(['0.3.5', '0.3.2'], $this->versions($news->since('0.3.1')));
        self::assertSame(['0.3.5', '0.3.2', '0.3.1'], $this->versions($news->history()));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function whatsNew(string $label): WhatsNew
    {
        return new WhatsNew(new ChangelogReader(new ArrayAdapter(), $this->file), new AppVersion($label));
    }

    private function user(?string $seen): User
    {
        $user = new User();
        $user->whatsNewSeenVersion = $seen;

        return $user;
    }

    /**
     * @param list<\App\Domain\DTO\System\ChangelogRelease> $releases
     *
     * @return list<string>
     */
    private function versions(array $releases): array
    {
        return array_map(static fn ($release): string => $release->version, $releases);
    }
}
