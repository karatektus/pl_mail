<?php

declare(strict_types=1);

namespace App\Tests\Service\System\WhatsNew;

use App\Service\System\WhatsNew\ChangelogReader;
use PHPUnit\Framework\TestCase;

/**
 * CHANGELOG.md read as releases and entries for the What's new dialog (#165).
 *
 * On small changelogs written the way the real one is, so each test shows the
 * convention it relies on. The last test reads the real file, because a
 * changelog this parser cannot read is a dialog with nothing in it, found by
 * the first person to update.
 */
final class ChangelogReaderTest extends TestCase
{
    private const string CHANGELOG = <<<'MD'
        # Changelog

        Notable changes per release.

        ## Unreleased

        **Not tagged yet.** Nobody running a release has this.

        ## v0.3.6 — 2026-10-16

        <!-- highlight screenshot=pull-to-sync -->
        **Pull the mail list down to sync it, on a phone.** Drag the list down
        and let go.

        - A mouse behaves as before.

        <!-- highlight -->
        **Notifications only for new mail.** Reading no longer says "New mail".

        **A draft opens in the editor.** It used to say `Not found`. **Migration:** one
        column on `message`.

        **Migration:** a table of its own.

        ## v0.3.5 — 2026-10-13

        **A <script> in the text stays text.** See [the issue](https://github.com/x/y/issues/1)
        and [this](javascript:alert(1)).

        ### Before you upgrade

        Back up the database first.

        ## v0.0.1

        ### Fixed

        - **Snooze.** It works now.
        - Login throttling.
        MD;

    public function testOnlyTaggedReleasesAreRead(): void
    {
        $releases = ChangelogReader::parse(self::CHANGELOG);

        self::assertSame(['0.3.6', '0.3.5', '0.0.1'], array_map(static fn ($r) => $r->version, $releases));
        self::assertSame('2026-10-16', $releases[0]->date?->format('Y-m-d'));
        self::assertNull($releases[2]->date, 'a heading without a date is still a release');
    }

    public function testAnEntryIsABoldParagraphAndWhatFollowsIt(): void
    {
        $entries = ChangelogReader::parse(self::CHANGELOG)[0]->entries;

        self::assertCount(3, $entries);
        self::assertSame('Pull the mail list down to sync it, on a phone.', $entries[0]->headline);
        self::assertStringContainsString('<p>Drag the list down and let go.</p>', $entries[0]->body, 'the wrapped line is one paragraph again');
        self::assertStringContainsString('<ul><li>A mouse behaves as before.</li></ul>', $entries[0]->body, 'a list under an entry belongs to it');
    }

    public function testHighlightsAreMarkedByTheCommentAboveThem(): void
    {
        $release = ChangelogReader::parse(self::CHANGELOG)[0];

        self::assertTrue($release->entries[0]->highlight);
        self::assertSame('pull-to-sync', $release->entries[0]->screenshot);
        self::assertTrue($release->entries[1]->highlight);
        self::assertNull($release->entries[1]->screenshot, 'a highlight without a picture');
        self::assertFalse($release->entries[2]->highlight);
        self::assertCount(2, $release->highlights());
        self::assertCount(1, $release->others());
        self::assertStringNotContainsString('<!--', $release->entries[0]->body . $release->entries[0]->headline);
    }

    /** Migration notes are the administrator's, whether a paragraph or a paragraph's tail. */
    public function testMigrationNotesAreTakenOutOfTheEntries(): void
    {
        $release = ChangelogReader::parse(self::CHANGELOG)[0];

        self::assertSame('<p>It used to say <code>Not found</code>.</p>', $release->entries[2]->body);
        self::assertCount(2, $release->adminNotes);
        self::assertStringContainsString('one column on <code>message</code>', $release->adminNotes[0]);
        self::assertStringContainsString('a table of its own', $release->adminNotes[1]);
    }

    public function testBeforeYouUpgradeIsForAdministrators(): void
    {
        $release = ChangelogReader::parse(self::CHANGELOG)[1];

        self::assertCount(1, $release->entries);
        self::assertSame(['<p>Back up the database first.</p>'], $release->adminNotes);
    }

    /** Nothing in the file becomes markup but the forms this writes, and links only to http(s). */
    public function testTheTextIsEscapedBeforeAnythingIsPutBack(): void
    {
        $entry = ChangelogReader::parse(self::CHANGELOG)[1]->entries[0];

        self::assertSame('A &lt;script&gt; in the text stays text.', $entry->headline);
        self::assertStringContainsString('<a href="https://github.com/x/y/issues/1" target="_blank" rel="noopener noreferrer">the issue</a>', $entry->body);
        self::assertStringNotContainsString('href="javascript', $entry->body);
    }

    /** The older releases' style: a list under a heading, one change per item. */
    public function testAListUnderAHeadingIsOneEntryPerItem(): void
    {
        $entries = ChangelogReader::parse(self::CHANGELOG)[2]->entries;

        self::assertCount(2, $entries);
        self::assertSame('Snooze.', $entries[0]->headline);
        self::assertSame('<p>It works now.</p>', $entries[0]->body);
        self::assertSame('Login throttling.', $entries[1]->headline);
    }

    /** The file that ships, read whole: every release, and every one of them with something to show. */
    public function testTheRealChangelogReads(): void
    {
        $markdown = (string) file_get_contents(dirname(__DIR__, 4) . '/CHANGELOG.md');
        $releases = ChangelogReader::parse($markdown);

        self::assertSame(
            preg_match_all('/^## v\d+\.\d+\.\d+/m', $markdown),
            count($releases),
            'every `## vX.Y.Z` heading is a release',
        );

        foreach ($releases as $release) {
            self::assertNotEmpty($release->entries, $release->tag() . ' reads as having no changes');
        }
    }

    /**
     * Every screenshot a highlight names is in public/whats-new, and no two
     * highlights claim the same name — the files are named by feature, not by
     * release. Read off the raw file, so Unreleased is checked too: that is
     * where a new highlight is added, by the pull request that adds the feature.
     */
    public function testEveryScreenshotAHighlightNamesExists(): void
    {
        $root = dirname(__DIR__, 4);

        preg_match_all('/<!--\s*highlight\s+screenshot=([a-z0-9-]+)\s*-->/', (string) file_get_contents($root . '/CHANGELOG.md'), $m);

        self::assertSame(array_unique($m[1]), $m[1], 'two highlights name the same screenshot');

        foreach ($m[1] as $name) {
            self::assertFileExists(
                $root . '/public/whats-new/' . $name . '.png',
                sprintf('A highlight names the screenshot "%s"; generate it with `npm run test:e2e:screenshots` (CONTRIBUTING.md → Release notes).', $name),
            );
        }
    }
}
