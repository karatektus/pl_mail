<?php

declare(strict_types=1);

namespace App\Service\System\WhatsNew;

use App\Domain\DTO\System\ChangelogEntry;
use App\Domain\DTO\System\ChangelogRelease;
use App\Domain\Helper\ChangelogMarkdown;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * CHANGELOG.md, read as the list of releases the What's new dialog shows (#165).
 *
 * The file ships in the image (nothing in .dockerignore keeps it out), so the
 * notes a user reads are the notes of the build they are running, with no
 * request to GitHub and nothing to keep in step by hand.
 *
 * ── How an entry is found ────────────────────────────────────────────────────
 * The changelog has one convention and keeps to it: an entry is a paragraph
 * that opens with a bold sentence, and everything until the next such
 * paragraph is its text. Older releases, from before that convention, group
 * changes under `### Fixed`-style headings as a `- ` list; there each item is
 * an entry. So:
 *
 *   - a block opening with `**` starts an entry, its bold part the headline;
 *   - a `- ` list right under a `###` heading, before any entry under it, is
 *     one entry per item;
 *   - every other block belongs to the entry before it, or to the release's
 *     introduction when there is none yet.
 *
 * ── What only administrators see ─────────────────────────────────────────────
 * `**Migration:**` paragraphs, the tail of a paragraph from `**Migration:**`
 * on, and everything under a `### Before you upgrade` heading. Those are
 * about the server, and the people who run it are the people who can act on
 * them; to everyone else they are a paragraph of table names.
 *
 * ── Highlights ───────────────────────────────────────────────────────────────
 * `<!-- highlight -->` on its own line above an entry makes it a highlight;
 * `<!-- highlight screenshot=pull-to-sync -->` also names its picture, which
 * lives at public/whats-new/<name>.png — by name, not by version, because the
 * pull request that adds a feature does not know which release it will ship
 * in. A comment because GitHub
 * renders the file and a comment is the one thing it shows nothing for.
 *
 * Parsed once per version of the file and cached, keyed on its size and
 * modification time, so a page that asks for it costs a cache read.
 */
final readonly class ChangelogReader
{
    /**
     * Part of the cache key, raised whenever what parse() produces changes.
     * The file's size and time say when the NOTES changed; an image that
     * only changed how they are read would otherwise go on serving the
     * last image's reading of them.
     */
    private const int FORMAT = 2;

    private const string HIGHLIGHT = '/<!--\s*highlight(?:\s+screenshot=([a-z0-9][a-z0-9-]*))?\s*-->/';

    public function __construct(
        private CacheInterface $cache,
        #[Autowire('%kernel.project_dir%/CHANGELOG.md')]
        private string         $path,
    ) {
    }

    /**
     * Every tagged release, newest first.
     *
     * @return list<ChangelogRelease>
     */
    public function releases(): array
    {
        $stat = @stat($this->path);

        if (false === $stat) {
            return [];
        }

        $key = 'whats_new.changelog.' . self::FORMAT . '.' . $stat['size'] . '.' . $stat['mtime'];

        return $this->cache->get($key, fn (): array => self::parse((string) file_get_contents($this->path)));
    }

    /** One release by its version, with or without the `v`. */
    public function release(string $version): ?ChangelogRelease
    {
        $version = ltrim($version, 'v');

        foreach ($this->releases() as $release) {
            if ($release->version === $version) {
                return $release;
            }
        }

        return null;
    }

    /**
     * The parse itself, public and static so it can be tested on a string.
     *
     * @return list<ChangelogRelease>
     */
    public static function parse(string $markdown): array
    {
        $releases = [];
        $heading  = null;
        $lines    = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
            if (str_starts_with($line, '## ')) {
                if (null !== $heading) {
                    $releases[] = self::build($heading, $lines);
                }

                $heading = self::versionHeading(substr($line, 3));
                $lines   = [];

                continue;
            }

            $lines[] = $line;
        }

        if (null !== $heading) {
            $releases[] = self::build($heading, $lines);
        }

        return $releases;
    }

    /**
     * The version and date of a `## ` heading, or null for one that is not a
     * release (`Unreleased`), whose section is then skipped.
     *
     * @return array{string, ?DateTimeImmutable}|null
     */
    private static function versionHeading(string $text): ?array
    {
        if (1 !== preg_match('/^v?(\d+\.\d+\.\d+)(?:\s*[—–-]\s*(\d{4}-\d{2}-\d{2}))?/u', trim($text), $m)) {
            return null;
        }

        $date = isset($m[2]) ? DateTimeImmutable::createFromFormat('!Y-m-d', $m[2]) : false;

        return [$m[1], false === $date ? null : $date];
    }

    /**
     * @param array{string, ?DateTimeImmutable} $heading
     * @param list<string>                      $lines
     */
    private static function build(array $heading, array $lines): ChangelogRelease
    {
        /** @var list<array{headline: string, body: list<string>, highlight: bool, screenshot: ?string}> $entries */
        $entries        = [];
        $admin          = [];
        $intro          = [];
        $adminSection   = false;
        $entryInSection = false;
        $headed         = false;
        $mark           = null;

        foreach (self::blocks($lines) as $block) {
            if (1 === preg_match(self::HIGHLIGHT, $block, $m)) {
                $mark  = ['screenshot' => $m[1] ?? null];
                $block = (string) preg_replace(self::HIGHLIGHT, '', $block);
            }

            $block = trim((string) preg_replace('/<!--.*?-->/s', '', $block), "\n");

            if ('' === trim($block)) {
                continue;
            }

            if (str_starts_with($block, '### ')) {
                [$title, $block] = array_pad(explode("\n", $block, 2), 2, '');

                $adminSection   = 1 === preg_match('/before you upgrade/i', $title);
                $entryInSection = false;
                $headed         = true;

                if ('' === trim($block)) {
                    continue;
                }
            }

            if (true === $adminSection || str_starts_with($block, '**Migration:**')) {
                $admin[] = $block;

                continue;
            }

            if (str_starts_with($block, '**')) {
                [$headline, $rest] = self::splitHeadline(ChangelogMarkdown::join($block));
                [$rest, $migration] = self::splitMigration($rest);

                $entries[] = [
                    'headline'   => $headline,
                    'body'       => '' === $rest ? [] : [$rest],
                    'highlight'  => null !== $mark,
                    'screenshot' => $mark['screenshot'] ?? null,
                ];

                if (null !== $migration) {
                    $admin[] = $migration;
                }

                $mark           = null;
                $entryInSection = true;

                continue;
            }

            // A list straight under a heading, with no entry of its own above
            // it in that section: the older releases' "### Fixed" style, one
            // change per item.
            if (false === $entryInSection && ChangelogMarkdown::isList($block) && (true === $headed || [] === $entries)) {
                foreach (ChangelogMarkdown::items($block) as $item) {
                    [$headline, $rest] = str_starts_with($item, '**') ? self::splitHeadline($item) : [$item, ''];
                    [$rest, $migration] = self::splitMigration($rest);

                    $entries[] = ['headline' => $headline, 'body' => '' === $rest ? [] : [$rest], 'highlight' => false, 'screenshot' => null];

                    if (null !== $migration) {
                        $admin[] = $migration;
                    }
                }

                $entryInSection = true;

                continue;
            }

            [$block, $migration] = self::splitMigration($block);

            if (null !== $migration) {
                $admin[] = $migration;
            }

            if ('' === trim($block)) {
                continue;
            }

            if ([] === $entries) {
                $intro[] = $block;
            } else {
                $entries[count($entries) - 1]['body'][] = $block;
            }
        }

        return new ChangelogRelease(
            $heading[0],
            $heading[1],
            array_map(static fn (array $e): ChangelogEntry => new ChangelogEntry(
                ChangelogMarkdown::inline($e['headline']),
                ChangelogMarkdown::blocks($e['body']),
                $e['highlight'],
                $e['screenshot'],
            ), $entries),
            array_map(static fn (string $note): string => ChangelogMarkdown::blocks([$note]), $admin),
            ChangelogMarkdown::blocks($intro),
        );
    }

    /**
     * The section's lines, split on blank lines.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function blocks(array $lines): array
    {
        $blocks  = [];
        $current = [];

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                if ([] !== $current) {
                    $blocks[]  = implode("\n", $current);
                    $current = [];
                }

                continue;
            }

            $current[] = rtrim($line);
        }

        if ([] !== $current) {
            $blocks[] = implode("\n", $current);
        }

        return $blocks;
    }

    /**
     * `**Headline.** The rest` as the headline and the rest. The headline is
     * the bold part, without its asterisks.
     *
     * @return array{string, string}
     */
    private static function splitHeadline(string $text): array
    {
        if (1 !== preg_match('/^\*\*(.+?)\*\*\s*(.*)$/s', $text, $m)) {
            return [$text, ''];
        }

        return [trim($m[1]), trim($m[2])];
    }

    /**
     * A paragraph that ends in `**Migration:** …`, cut there: the part before
     * is about the change, the part from it is for whoever runs the server.
     *
     * @return array{string, ?string}
     */
    private static function splitMigration(string $text): array
    {
        $at = strpos($text, '**Migration:**');

        if (false === $at) {
            return [$text, null];
        }

        return [rtrim(substr($text, 0, $at)), substr($text, $at)];
    }
}
