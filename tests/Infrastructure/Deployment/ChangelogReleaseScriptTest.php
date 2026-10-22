<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Deployment;

use App\Service\System\WhatsNew\ChangelogReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * bin/changelog-release.sh: the fragments in changelog.d/ become a release's
 * section of CHANGELOG.md.
 *
 * The script is the one step of a release that writes the notes every install
 * reads in What's new, so these tests read its output back through
 * ChangelogReader as well as checking the text: a section the dialog cannot
 * read is notes nobody sees. Run as the real script in a child shell, as
 * GenerateSecretsScriptTest does, because its only interface is the files it
 * leaves behind.
 */
final class ChangelogReleaseScriptTest extends TestCase
{
    private const string PREAMBLE = "# Changelog\n\nNotable changes per release.\n\n";

    private const string OLDER = "## v0.3.7 — 2026-10-20\n\n**An older change.** Already released.\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/plmail-changelog-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/changelog.d', 0777, true);
        file_put_contents($this->root . '/changelog.d/README.md', "# Not an entry\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/changelog.d/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->root . '/*') ?: [] as $file) {
            if (true === is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->root . '/changelog.d');
        rmdir($this->root);
    }

    /**
     * Highlights first, then the rest, each by file name; the fragments are
     * gone afterwards and the README stays. The older release is untouched.
     */
    public function testTheFragmentsBecomeTheReleaseHighlightsFirst(): void
    {
        $this->changelog(self::PREAMBLE . self::OLDER);
        $this->fragment('266-read-state.md', "**A fix.** Read state agrees.\n");
        $this->fragment('268-bin.md', "\n\n**Another fix.** The bin rule.\n\n\n");
        $this->fragment('300-feature.md', "<!-- highlight -->\n**A feature.** Worth a highlight.\n");

        [$code] = $this->release('v0.3.8', '2026-10-22');

        self::assertSame(0, $code);
        self::assertSame(
            self::PREAMBLE
                . "## v0.3.8 — 2026-10-22\n\n"
                . "<!-- highlight -->\n**A feature.** Worth a highlight.\n\n"
                . "**A fix.** Read state agrees.\n\n"
                . "**Another fix.** The bin rule.\n\n"
                . self::OLDER,
            $this->read(),
        );

        self::assertSame(['README.md'], array_map('basename', glob($this->root . '/changelog.d/*') ?: []));

        $release = ChangelogReader::parse($this->read())[0];
        self::assertSame('0.3.8', $release->version);
        self::assertCount(3, $release->entries);
        self::assertTrue($release->entries[0]->highlight);
    }

    /**
     * What is still written under `## Unreleased` goes in last, and the
     * heading goes. Last because a `### Before you upgrade` there claims every
     * entry after it for administrators: put first, it would have hidden the
     * fragments from everybody else.
     */
    public function testWhatIsLeftUnderUnreleasedComesLast(): void
    {
        $this->changelog(
            self::PREAMBLE
            . "## Unreleased\n\n**Written the old way.** Before changelog.d existed.\n\n"
            . "### Before you upgrade\n\nBack up the database first.\n\n"
            . self::OLDER,
        );
        $this->fragment('266-read-state.md', "**A fix.** Read state agrees.\n");

        [$code] = $this->release('v0.3.8', '2026-10-22');

        self::assertSame(0, $code);
        self::assertStringNotContainsString('## Unreleased', $this->read());

        $release = ChangelogReader::parse($this->read())[0];
        self::assertSame(['A fix.', 'Written the old way.'], array_map(static fn ($entry): string => $entry->headline, $release->entries));
        self::assertCount(1, $release->adminNotes, 'Before you upgrade is still for administrators');
    }

    /** A release written twice would be two sections of one version. */
    public function testAVersionThatAlreadyHasASectionChangesNothing(): void
    {
        $this->changelog(self::PREAMBLE . self::OLDER);
        $this->fragment('266-read-state.md', "**A fix.** Read state agrees.\n");

        [$code, $error] = $this->release('v0.3.7', '2026-10-22');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('already has', $error);
        self::assertSame(self::PREAMBLE . self::OLDER, $this->read());
        self::assertFileExists($this->root . '/changelog.d/266-read-state.md');
    }

    /** No fragments and nothing under Unreleased: a release with no notes is a mistake to stop at. */
    public function testNothingToReleaseIsRefused(): void
    {
        $this->changelog(self::PREAMBLE . "## Unreleased\n\n" . self::OLDER);

        [$code, $error] = $this->release('v0.3.8', '2026-10-22');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('Nothing to release', $error);
        self::assertSame(self::PREAMBLE . "## Unreleased\n\n" . self::OLDER, $this->read());
    }

    private function changelog(string $markdown): void
    {
        file_put_contents($this->root . '/CHANGELOG.md', $markdown);
    }

    private function fragment(string $name, string $markdown): void
    {
        file_put_contents($this->root . '/changelog.d/' . $name, $markdown);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->root . '/CHANGELOG.md');
    }

    /**
     * @return array{int, string} the exit code and what it wrote to stderr
     */
    private function release(string $tag, string $date): array
    {
        $process = proc_open(
            ['sh', \dirname(__DIR__, 3) . '/bin/changelog-release.sh', $tag, $date],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['CHANGELOG_ROOT' => $this->root, 'PATH' => (string) getenv('PATH')],
        );

        if (false === is_resource($process)) {
            throw new RuntimeException('Could not start bin/changelog-release.sh');
        }

        stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $error];
    }
}
