<?php

declare(strict_types=1);

namespace App\Tests\Documentation;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The documentation cannot fall behind the thing it documents.
 *
 * Prose rots silently. A console command gains an option, a variable is added
 * to .env, a page is renamed — and nothing anywhere notices, because no test
 * has ever had an opinion about Markdown. Six months later the handbook is
 * confidently wrong, which is worse than absent: an operator pastes a default
 * that no longer exists into production, and the file that told them to still
 * looks authoritative.
 *
 * So the parts of the documentation that CAN be checked mechanically are, and
 * a build fails rather than a reader being misled. What is checkable is the
 * inventory — which commands exist, which variables exist, which pages exist,
 * which links resolve. Whether a paragraph is TRUE is not checkable and is not
 * attempted; this is the floor, not the ceiling, and it is the same bargain
 * TimestampableTest strikes for an attribute a trait cannot require of itself.
 *
 * CONTRIBUTING.md has claimed since it was written that "every command is
 * listed in CONTRIBUTING.md with a one-line description — that table is part of
 * the definition of done". Nothing enforced it. It does now.
 *
 * **Adding a command, a variable or a page means adding a line of prose.** That
 * is the whole point, and the failure message says where to put it. If a
 * command genuinely should not be documented, it belongs under `app:test:`,
 * which is excluded below for exactly that reason.
 */
final class DocumentationCoverageTest extends KernelTestCase
{
    /**
     * Fixture and scaffolding commands, excluded from the command table.
     *
     * They exist for the browser suite and are documented by the specs that
     * call them. A contributor reading CONTRIBUTING.md to learn what plMail can
     * do is not helped by `app:test:seed-duplicate-event`.
     */
    private const string TEST_COMMAND_PREFIX = 'app:test:';

    /** Every command plMail adds is namespaced, so this is what "ours" means. */
    private const string OWN_COMMAND_PREFIX = 'app:';

    /**
     * The handbook's translations, and the heading each one spells the traps
     * section with.
     *
     * The heading has to be pinned per language rather than left free, because
     * testEveryPageCollectsItsTraps looks for it — and a translator who invents
     * their own wording removes the check without anyone noticing. It is also
     * the one string a translated page cannot translate freely, which is worth
     * saying in the file the translator reads.
     *
     * @var array<string, string>
     */
    private const array TRANSLATIONS = [
        'de' => 'Fallstricke',
    ];

    /** What a translated page records about the English it was made from. */
    private const string SOURCE_MARKER = '/^<!--\s*translated-from:\s*(\S+)\s+sha1:([0-9a-f]{40})\s*-->/m';

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Every console command an operator could run is described somewhere they
     * would look.
     */
    public function testEveryCommandIsInTheCommandTable(): void
    {
        self::bootKernel();

        $reference = file_get_contents(self::projectDir() . '/CONTRIBUTING.md');

        self::assertIsString($reference, 'CONTRIBUTING.md carries the command table');

        $undocumented = [];

        foreach (new Application(self::$kernel)->all() as $command) {
            $name = $command->getName();

            if (null === $name
                || false === str_starts_with($name, self::OWN_COMMAND_PREFIX)
                || true === str_starts_with($name, self::TEST_COMMAND_PREFIX)
            ) {
                continue;
            }

            if (false === str_contains($reference, $name)) {
                $undocumented[] = $name;
            }
        }

        self::assertSame(
            [],
            $undocumented,
            sprintf(
                "These commands exist and CONTRIBUTING.md does not mention them.\n"
                . "Add a row to the command reference table with a one-line description:\n  %s",
                implode("\n  ", $undocumented),
            ),
        );
    }

    /**
     * And nothing documents a command that does not exist.
     *
     * The other direction, and it fails differently: a missing row is a feature
     * nobody discovers, whereas a row for a command that was renamed or deleted
     * is an instruction that errors when followed, from a table that looks
     * authoritative because everything around it is right. The reference listed
     * `app:label:backfill` for months after it stopped existing.
     */
    public function testNothingDocumentsACommandThatDoesNotExist(): void
    {
        self::bootKernel();

        $real = [];

        foreach (new Application(self::$kernel)->all() as $command) {
            $name = $command->getName();

            if (null !== $name) {
                $real[] = $name;

                // An alias is as valid a thing to document as the name.
                $real = [...$real, ...$command->getAliases()];
            }
        }

        $invented = [];

        foreach ([...$this->documentationFiles(), new SplFileInfo(self::projectDir() . '/CONTRIBUTING.md')] as $file) {
            $body = file_get_contents($file->getPathname());

            if (false === $body) {
                continue;
            }

            // Backticked, because that is how the house writes a command and
            // because prose mentioning "the app:mail:sync family" should not be
            // held to naming a real one.
            preg_match_all('/`(app:[a-z0-9:-]+)/', $body, $matches);

            foreach (array_unique($matches[1]) as $mentioned) {
                // A namespace rather than a command — the prose talks about
                // `app:test:` fixtures and `app:mail:` as families, and neither
                // is a thing you can run.
                if (true === str_ends_with($mentioned, ':')) {
                    continue;
                }

                if (false === \in_array($mentioned, $real, true)) {
                    $invented[] = sprintf('%s → %s', $this->relativePath($file->getPathname()), $mentioned);
                }
            }
        }

        self::assertSame(
            [],
            $invented,
            sprintf(
                "These commands are documented and do not exist. Rename or remove the mention:\n  %s",
                implode("\n  ", $invented),
            ),
        );
    }

    /**
     * Every environment variable is in the configuration reference.
     *
     * The one page an operator is entitled to treat as complete — it is linked
     * from the installation pages as "every environment variable", and a
     * variable missing from it is a variable somebody discovers by reading the
     * source of a mail client at two in the morning.
     */
    public function testEveryEnvironmentVariableIsInTheConfigurationReference(): void
    {
        $page = file_get_contents(self::projectDir() . '/docs/install/configuration.md');

        self::assertIsString($page, 'docs/install/configuration.md is the reference');

        $undocumented = [];

        foreach ($this->declaredEnvironmentVariables() as $name) {
            if (false === str_contains($page, $name)) {
                $undocumented[] = $name;
            }
        }

        self::assertSame(
            [],
            $undocumented,
            sprintf(
                "These variables are set in .env and docs/install/configuration.md does not mention them.\n"
                . "Add a row saying what each does, its default, and what happens if it is wrong:\n  %s",
                implode("\n  ", $undocumented),
            ),
        );
    }

    /**
     * Every relative link in the documentation goes somewhere.
     *
     * A renamed page leaves working links in the files that were renamed
     * alongside it and dead ones everywhere else, and nobody clicks all of
     * them. Anchors are stripped before the check and fragments are not
     * verified: a heading that moved within a page is a much smaller lie than a
     * page that is not there.
     */
    public function testEveryRelativeLinkResolves(): void
    {
        $broken = [];

        foreach ($this->documentationFiles() as $file) {
            $body = file_get_contents($file->getPathname());

            if (false === $body) {
                continue;
            }

            preg_match_all('/\]\(([^)]+)\)/', $body, $matches);

            foreach ($matches[1] as $target) {
                if (true === $this->isExternal($target)) {
                    continue;
                }

                // A link to a heading on the same page. Tested before the split
                // below and not with strtok, which SKIPS leading delimiters and
                // so answers "feature-parity-checklist" for "#feature-parity-
                // checklist" — a path, cheerfully, and every same-page anchor in
                // CLIENT_DEVELOPMENT.md was reported as a dead link.
                if (true === str_starts_with($target, '#')) {
                    continue;
                }

                $path = strtok($target, '#');

                if (false === $path) {
                    continue;
                }

                // A translated page's links are resolved against the ENGLISH
                // tree, which is also how bin/build-site.php rewrites them: a
                // link in docs/de/features/mail.md means the same target its
                // English counterpart means, and the site places the result
                // inside the German tree afterwards. Resolving them here
                // instead would demand that every page a translation links to
                // is itself already translated — which makes a partial
                // translation impossible, and a partial translation is the only
                // kind anybody ever starts.
                $locale = $this->localeOf($this->relativePath($file->getPathname()));
                $from   = null === $locale
                    ? $file->getPath()
                    : str_replace('/docs/' . $locale . '/', '/docs/', $file->getPath());

                $resolved = realpath($from . '/' . $path);

                if (false === $resolved) {
                    $broken[] = sprintf(
                        '%s → %s',
                        $this->relativePath($file->getPathname()),
                        $target,
                    );
                }
            }
        }

        self::assertSame([], $broken, "These links point at files that do not exist:\n  " . implode("\n  ", $broken));
    }

    /**
     * Every page is reachable from the index, and the index promises no page
     * that is missing.
     *
     * Both directions, because they fail differently. A page the index does not
     * list is a page nobody finds and nobody maintains; a page the index lists
     * and that does not exist is a broken promise on the first screen somebody
     * reads. The wiki mirror walks the index, so an unlisted page is also a page
     * that never reaches the wiki at all.
     */
    public function testTheIndexAndTheDirectoryAgree(): void
    {
        $index = file_get_contents(self::projectDir() . '/docs/README.md');

        self::assertIsString($index);

        $orphans = [];

        foreach ($this->documentationFiles() as $file) {
            $relative = $this->relativePath($file->getPathname());

            // The index itself, and the client reference it links by name.
            if ('docs/README.md' === $relative) {
                continue;
            }

            // A translated page is not listed in the English index and does not
            // need to be: its position is its path, which mirrors the English
            // page it translates. The index it DOES belong in is its own
            // language's, and testEveryTranslationTranslatesARealPage is what
            // holds it to a real English counterpart.
            if (null !== $this->localeOf($relative)) {
                continue;
            }

            $fromIndex = substr($relative, \strlen('docs/'));

            if (false === str_contains($index, $fromIndex)) {
                $orphans[] = $relative;
            }
        }

        self::assertSame(
            [],
            $orphans,
            sprintf(
                "These pages exist and docs/README.md does not link them, so nobody will find them:\n  %s",
                implode("\n  ", $orphans),
            ),
        );
    }

    /**
     * Every page ends with the section that collects its traps.
     *
     * The house convention, and the most useful part of CONTRIBUTING.md — the
     * section that says what goes wrong rather than what to type. It is asserted
     * rather than hoped for because it is the first thing that gets dropped when
     * a page is written in a hurry, and the pages written in a hurry are the
     * ones whose traps are least obvious.
     */
    public function testEveryPageCollectsItsTraps(): void
    {
        $missing = [];

        foreach ($this->documentationFiles() as $file) {
            $relative = $this->relativePath($file->getPathname());

            // The index is a table of contents, and CLIENT_DEVELOPMENT.md
            // predates the convention and is a protocol reference rather than a
            // guide.
            if (\in_array($relative, ['docs/README.md', 'docs/CLIENT_DEVELOPMENT.md'], true)) {
                continue;
            }

            $locale  = $this->localeOf($relative);
            $heading = null === $locale ? 'Things that bite' : self::TRANSLATIONS[$locale];

            $body = file_get_contents($file->getPathname());

            if (false === $body || false === str_contains($body, '## ' . $heading)) {
                $missing[] = sprintf('%s (looking for "## %s")', $relative, $heading);
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                "These pages have no `## Things that bite` section.\n"
                . "If a page genuinely has no traps, say that in one line under the heading:\n  %s",
                implode("\n  ", $missing),
            ),
        );
    }

    /**
     * A translation says which English page it was made from, and that page
     * still says what it said then.
     *
     * This is the only check here that is about TRUTH rather than inventory,
     * and it gets at it sideways. Nobody can test whether a German paragraph
     * still describes the code — but a translation is made from an English page
     * at a moment in time, and if that page has changed since, the translation
     * is a claim about the past. That is checkable, and it is very nearly the
     * same thing.
     *
     * It matters more than it sounds. A stale translation is worse than a
     * missing one: a missing page falls back to English and says so, while a
     * stale page is fluent, confident and wrong, and its reader has no way to
     * tell. The fallback in bin/build-site.php exists so that DELETING a stale
     * translation is a reasonable fix.
     *
     * **A content hash, not a git blob hash and not a commit date.** Dates mark
     * every translation stale after a whitespace sweep, and git is not usable
     * from here at all — the test container mounts this repository in a way git
     * refuses for dubious ownership, which is a real failure this suite has
     * already had. sha1_file() answers the same question everywhere.
     */
    public function testNoTranslationDescribesAPageThatHasSinceChanged(): void
    {
        $stale = [];

        foreach ($this->documentationFiles() as $file) {
            $relative = $this->relativePath($file->getPathname());
            $locale   = $this->localeOf($relative);

            if (null === $locale) {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());

            if (1 !== preg_match(self::SOURCE_MARKER, $body, $marker)) {
                $stale[] = sprintf(
                    '%s has no marker — add `<!-- translated-from: %s sha1:%s -->` as its first line',
                    $relative,
                    $this->englishCounterpart($relative, $locale),
                    sha1_file(self::projectDir() . '/docs/' . $this->englishCounterpart($relative, $locale)) ?: '…',
                );

                continue;
            }

            $english = self::projectDir() . '/docs/' . $marker[1];

            if (false === is_file($english)) {
                $stale[] = sprintf('%s names %s, which does not exist', $relative, $marker[1]);

                continue;
            }

            if (sha1_file($english) !== $marker[2]) {
                $stale[] = sprintf(
                    '%s was translated from an older %s — re-read it, then update the hash to %s',
                    $relative,
                    $marker[1],
                    sha1_file($english),
                );
            }
        }

        self::assertSame(
            [],
            $stale,
            sprintf(
                "These translations no longer match the English they were made from.\n"
                . "Bring them up to date, or delete them — an untranslated page falls back to\n"
                . "English and says so, which is better than a fluent page that is wrong:\n  %s",
                implode("\n  ", $stale),
            ),
        );
    }

    /** And a translation translates a page that exists. */
    public function testEveryTranslationTranslatesARealPage(): void
    {
        $orphans = [];

        foreach ($this->documentationFiles() as $file) {
            $relative = $this->relativePath($file->getPathname());
            $locale   = $this->localeOf($relative);

            if (null === $locale) {
                continue;
            }

            $counterpart = $this->englishCounterpart($relative, $locale);

            if (false === is_file(self::projectDir() . '/docs/' . $counterpart)) {
                $orphans[] = sprintf('%s translates %s, which does not exist', $relative, $counterpart);
            }
        }

        self::assertSame(
            [],
            $orphans,
            sprintf(
                "A translation whose English page is gone is unreachable — the site renders a\n"
                . "language from the ENGLISH page list, so nothing will ever link to it:\n  %s",
                implode("\n  ", $orphans),
            ),
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The variable names .env assigns, which is the set an operator can set.
     *
     * Read from .env rather than from the container: the container resolves
     * defaults and would answer for variables nothing declares, and .env is
     * both the list and the documentation of what a deployment may override.
     *
     * @return list<string>
     */
    private function declaredEnvironmentVariables(): array
    {
        $body = file_get_contents(self::projectDir() . '/.env');

        self::assertIsString($body);

        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<SplFileInfo>
     */
    private function documentationFiles(): array
    {
        $files = [];

        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::projectDir() . '/docs', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($walker as $file) {
            if ($file instanceof SplFileInfo && 'md' === $file->getExtension()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function isExternal(string $target): bool
    {
        return str_starts_with($target, 'http://')
            || str_starts_with($target, 'https://')
            || str_starts_with($target, 'mailto:');
    }

    /** The language a page is written in, or null when it is the English one. */
    private function localeOf(string $relative): ?string
    {
        foreach (array_keys(self::TRANSLATIONS) as $locale) {
            if (true === str_starts_with($relative, 'docs/' . $locale . '/')) {
                return $locale;
            }
        }

        return null;
    }

    /** docs/de/features/mail.md → features/mail.md */
    private function englishCounterpart(string $relative, string $locale): string
    {
        return substr($relative, \strlen('docs/' . $locale . '/'));
    }

    private function relativePath(string $absolute): string
    {
        return str_replace(self::projectDir() . '/', '', $absolute);
    }
}
