<?php

declare(strict_types=1);

/**
 * Builds plmail.dev — the landing page and the handbook — out of this
 * repository.
 *
 * There is no content here. The landing page IS README.md and the handbook IS
 * docs/, rendered into a shell; nothing on the site can say something the
 * repository does not, because there is nowhere for it to be written down.
 *
 * The second renderer of the same source, alongside bin/mirror-wiki.php, and
 * both are kept. They are not redundant: the wiki is where somebody already
 * inside GitHub looks, and this is what a link to plMail shows a person who has
 * never seen it — a landing page, which a wiki has no way to be. Neither can
 * drift, because neither is written in: an edit made in the wiki's browser
 * editor survives until the next push, and there is no editor here at all.
 *
 * README.md as the landing page is not a shortcut. README's stated audience is
 * "someone deciding whether to run it", which is a landing page's job written
 * out — CODESTYLE §11.1 assigned it that job long before there was a site.
 *
 * **The docs tree is preserved rather than flattened.** docs/features/mail.md
 * becomes docs/features/mail.html, so a relative link between two pages needs
 * only its extension changed, and an image reference needs nothing at all. The
 * wiki mirror had to flatten and rewrite every link because a wiki has one flat
 * namespace; this does not, and the rewriting that remains is therefore the
 * small honest kind.
 *
 * Three link shapes, three rules:
 *
 *   a .md inside docs/     → the same path with .html
 *   anything else in docs/ → left alone; the file is copied beside it
 *   a path outside docs/   → an absolute URL into GitHub, because the site
 *                            carries the handbook and not the source
 *
 * Usage:
 *
 *   php bin/build-site.php [output-dir]     (defaults to build/site)
 *
 * Requires league/commonmark, which is a require-dev dependency: the site is
 * built in CI and never by the application, so shipping a Markdown parser in
 * the runtime image would be a dependency nothing runs.
 */

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use App\Domain\Enum\Theme\Theme;
use League\CommonMark\MarkdownConverter;

require __DIR__ . '/../vendor/autoload.php';

const REPO = 'https://github.com/karatektus/pl_mail';

$projectDir = \dirname(__DIR__);
$docsDir    = $projectDir . '/docs';
$out        = $argv[1] ?? $projectDir . '/build/site';

$environment = new Environment([
    'html_input'         => 'allow',
    'allow_unsafe_links' => false,
    'heading_permalink'  => [
        'html_class' => 'anchor',
        'symbol'     => '#',
        'insert'     => 'after',
        // The id a heading already gets, so an anchor somebody bookmarked from
        // the GitHub rendering of the same file still lands.
        'id_prefix'  => '',
        'fragment_prefix' => '',
    ],
]);

$environment->addExtension(new CommonMarkCoreExtension());
$environment->addExtension(new TableExtension());
$environment->addExtension(new AutolinkExtension());
$environment->addExtension(new HeadingPermalinkExtension());

$markdown = new MarkdownConverter($environment);

// ---------------------------------------------------------------- the tree

/** @return list<string> paths relative to $dir */
$walk = static function (string $dir, string $prefix = '') use (&$walk): array {
    $found = [];

    foreach (scandir($dir) ?: [] as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (true === is_dir($path)) {
            $found = [...$found, ...$walk($path, $prefix . $entry . '/')];

            continue;
        }

        $found[] = $prefix . $entry;
    }

    return $found;
};

$mkdir = static function (string $dir): void {
    if (false === is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
};

// Wiped rather than merged: a page deleted from docs/ has to disappear from the
// site, and a build that only ever adds files leaves the old one served for
// ever, still indexed and still wrong.
if (true === is_dir($out)) {
    foreach (array_reverse($walk($out)) as $stale) {
        unlink($out . '/' . $stale);
    }
}

$mkdir($out);

// ---------------------------------------------------------------- helpers

/** The page title: the first h1, or the file name if a page forgot one. */
$titleOf = static function (string $body, string $relative): string {
    if (1 === preg_match('/^#\s+(.+)$/m', $body, $heading)) {
        return trim($heading[1]);
    }

    return ucfirst(str_replace(['-', '/'], [' ', ' — '], substr($relative, 0, -3)));
};

/**
 * Rewrites the links in one page for its position in the built tree.
 *
 * $depth is how many directories deep the page sits under the site root, which
 * is what "../" has to be counted against when a link escapes docs/.
 */
$rewrite = static function (string $body, string $sourceDir, string $docsDir, string $projectDir): string {
    return preg_replace_callback(
        '/\]\(([^)\s]+)(#[^)\s]*)?\)/',
        static function (array $match) use ($sourceDir, $docsDir, $projectDir): string {
            $target   = $match[1];
            $fragment = $match[2] ?? '';

            if (1 === preg_match('#^(https?:|mailto:|//|\#)#', $target)) {
                return $match[0];
            }

            $resolved = realpath($sourceDir . '/' . $target);

            // Unresolvable is left exactly as written. DocumentationCoverageTest
            // already fails the build on a dangling link, so anything reaching
            // here is a link that test deliberately allows.
            if (false === $resolved) {
                return $match[0];
            }

            if (false === str_starts_with($resolved, $docsDir . '/')) {
                return sprintf(
                    '](%s/blob/main/%s%s)',
                    REPO,
                    str_replace($projectDir . '/', '', $resolved),
                    $fragment,
                );
            }

            if (true === str_ends_with($resolved, '.md')) {
                return sprintf('](%s%s)', substr($target, 0, -3) . '.html', $fragment);
            }

            return $match[0];
        },
        $body,
    ) ?? $body;
};

// ---------------------------------------------------------------- navigation

/**
 * The sidebar, read out of docs/README.md.
 *
 * Generated rather than written, so the navigation and the index cannot
 * disagree — the same argument the wiki mirror made for its own sidebar, and
 * the reason DocumentationCoverageTest asserts every page is listed there.
 *
 * @return list<array{title: string, pages: list<array{title: string, href: string}>}>
 */
$navigation = static function (string $docsDir): array {
    $sections = [];
    $current  = null;

    foreach (file($docsDir . '/README.md') ?: [] as $line) {
        $line = trim($line);

        if (1 === preg_match('/^## (.+)$/', $line, $heading)) {
            if (null !== $current && [] !== $current['pages']) {
                $sections[] = $current;
            }

            $current = ['title' => $heading[1], 'pages' => []];

            continue;
        }

        if (null !== $current && 1 === preg_match('/^\| \[([^\]]+)\]\(([^)]+)\.md\)/', $line, $row)) {
            $current['pages'][] = ['title' => $row[1], 'href' => $row[2] . '.html'];
        }
    }

    if (null !== $current && [] !== $current['pages']) {
        $sections[] = $current;
    }

    return $sections;
};

$nav = $navigation($docsDir);

// ---------------------------------------------------------------- themes

/**
 * The site's palettes, generated from the app's own Theme enum.
 *
 * Theme::swatch() already carries three colours per theme for the appearance
 * picker — surface, ink, accent — and the rest of what a page needs is derived
 * from those by mixing. Generated rather than transcribed for the obvious
 * reason: a hand-copied palette agrees with the app until somebody adds a theme,
 * and then the site quietly offers six of seven.
 *
 * Derived at BUILD time rather than with CSS color-mix(), so the output is
 * ordinary static CSS. color-mix would be shorter and would put the site's
 * legibility behind a feature query.
 */
$mix = static function (string $from, string $to, float $amount): string {
    $channels = static fn (string $hex): array => [
        (int) hexdec(substr($hex, 1, 2)),
        (int) hexdec(substr($hex, 3, 2)),
        (int) hexdec(substr($hex, 5, 2)),
    ];

    [$fr, $fg, $fb] = $channels($from);
    [$tr, $tg, $tb] = $channels($to);

    return sprintf(
        '%d %d %d',
        (int) round($fr + ($tr - $fr) * $amount),
        (int) round($fg + ($tg - $fg) * $amount),
        (int) round($fb + ($tb - $fb) * $amount),
    );
};

$rgb = static function (string $hex): string {
    return sprintf(
        '%d %d %d',
        (int) hexdec(substr($hex, 1, 2)),
        (int) hexdec(substr($hex, 3, 2)),
        (int) hexdec(substr($hex, 5, 2)),
    );
};

/** Black or white on the accent, whichever the accent can carry. */
$readableOn = static function (string $hex): string {
    $r = (int) hexdec(substr($hex, 1, 2));
    $g = (int) hexdec(substr($hex, 3, 2));
    $b = (int) hexdec(substr($hex, 5, 2));

    // Rec. 601 luma, which is enough to choose between two extremes.
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '17 24 39' : '255 255 255';
};

$themeCss = '';
$themeList = [];

foreach (Theme::cases() as $theme) {
    // System follows the operating system and therefore has no palette of its
    // own — it is Light or Dark at read time, which the stylesheet's own
    // prefers-color-scheme block already answers.
    if (Theme::System === $theme) {
        $themeList[] = ['value' => $theme->value, 'label' => 'System'];

        continue;
    }

    [$surface, $ink, $accent] = $theme->swatch();

    $themeCss .= sprintf(
        ":root[data-theme=\"%s\"] {\n"
        . "    --rgb-surface:   %s;\n"
        . "    --rgb-sunken:    %s;\n"
        . "    --rgb-line:      %s;\n"
        . "    --rgb-ink:       %s;\n"
        . "    --rgb-ink-soft:  %s;\n"
        . "    --rgb-ink-muted: %s;\n"
        . "    --rgb-ink-faint: %s;\n"
        . "    --rgb-accent:    %s;\n"
        . "    --rgb-accent-ink:%s;\n"
        . "    --line-alpha: %s;\n"
        . "}\n\n",
        $theme->value,
        $rgb($surface),
        $mix($surface, $ink, 0.05),
        $rgb($ink),
        $rgb($ink),
        $mix($ink, $surface, 0.2),
        $mix($ink, $surface, 0.4),
        $mix($ink, $surface, 0.58),
        $rgb($accent),
        $readableOn($accent),
        true === $theme->isDark() ? '0.18' : '0.12',
    );

    $themeList[] = ['value' => $theme->value, 'label' => ucfirst($theme->value)];
}

// ---------------------------------------------------------------- languages

/**
 * The handbook's languages: a docs/<locale>/ directory is a translation, and
 * its absence is the whole of the configuration.
 *
 * Locales plMail's interface speaks but the handbook does not are listed and
 * disabled rather than hidden. A switcher that silently omits German says the
 * site has no opinion about German; one that says "not translated yet" says
 * where the gap is, and is the honest state of the thing.
 */
$languages = [['value' => 'en', 'label' => 'English', 'available' => true]];

foreach (['de' => 'Deutsch', 'en_PI' => 'Pirate'] as $code => $label) {
    $languages[] = [
        'value'     => $code,
        'label'     => $label,
        'available' => is_dir($docsDir . '/' . $code),
    ];
}

// ---------------------------------------------------------------- the shell

$shell = require __DIR__ . '/site/shell.php';

// ---------------------------------------------------------------- render

$search = [];
$pages  = 0;

foreach ($walk($docsDir) as $relative) {
    $source = $docsDir . '/' . $relative;
    $target = $out . '/docs/' . $relative;

    $mkdir(\dirname($target));

    if (false === str_ends_with($relative, '.md')) {
        copy($source, $target);

        continue;
    }

    $body = (string) file_get_contents($source);
    $slug = substr($relative, 0, -3) . '.html';

    $html = $markdown->convert(
        $rewrite($body, \dirname($source), $docsDir, $projectDir),
    )->getContent();

    $depth = substr_count($slug, '/') + 1;
    $root  = str_repeat('../', $depth);

    file_put_contents(
        $out . '/docs/' . $slug,
        $shell(
            title:   $titleOf($body, $relative),
            content: $html,
            root:    $root,
            here:    $slug,
            nav:     $nav,
            landing: false,
            source:  'docs/' . $relative,
            themes:  $themeList,
            languages: $languages,
        ),
    );

    $search[] = [
        'title' => $titleOf($body, $relative),
        'href'  => 'docs/' . $slug,
        // Stripped to words: the index is fetched by every visitor who opens
        // the search box, and shipping the markup would triple it for nothing.
        // The anchors go before the tags do. HeadingPermalink puts an <a>#</a>
        // inside every heading, and strip_tags alone leaves the # welded to the
        // next word — every heading in the index read "CalDAV# CalDAV is the".
        // Entities are decoded for the same reason: &quot; is not a word, and
        // an excerpt is read by a person.
        'text'  => mb_substr(
            trim(preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode(
                    strip_tags(preg_replace('/<a\b[^>]*class="anchor"[^>]*>.*?<\/a>/s', ' ', $html) ?? $html),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                ),
            ) ?? ''),
            0,
            4000,
        ),
    ];

    ++$pages;
}

// The landing page, from README.md.
//
// Its opening heading and first paragraph are LIFTED into the hero rather than
// copied: a hero written here would be the one piece of prose on the site that
// no file in the repository backs, and the first thing to go stale. They are
// then removed from the body, or the page introduces itself twice.
$readme  = (string) file_get_contents($projectDir . '/README.md');
$heroLead = '';

if (1 === preg_match('/^#\s+(.+?)\n+(?!#)(.+?)\n\n/s', $readme, $opening)) {
    $heroLead = trim(preg_replace('/\s+/', ' ', $opening[2]) ?? '');
    $readme   = substr($readme, \strlen($opening[0]));
}

file_put_contents(
    $out . '/index.html',
    $shell(
        title:   'plMail — self-hosted mail with a calendar',
        content: $markdown->convert(
            $rewrite($readme, $projectDir, $docsDir, $projectDir),
        )->getContent(),
        root:    '',
        here:    '',
        nav:     $nav,
        landing: true,
        source:  'README.md',
        heroLead: $heroLead,
        themes:  $themeList,
        languages: $languages,
    ),
);

$mkdir($out . '/assets');
file_put_contents(
    $out . '/assets/site.css',
    file_get_contents(__DIR__ . '/site/site.css')
    . "\n/* ═══ Generated from App\\Domain\\Enum\\Theme\\Theme::swatch() — do not edit here. ═══ */\n\n"
    . $themeCss,
);
copy(__DIR__ . '/site/site.js', $out . '/assets/site.js');
file_put_contents($out . '/assets/search.json', json_encode($search, JSON_UNESCAPED_SLASHES));

// So Pages serves the tree as-is rather than running it through Jekyll, which
// would silently drop any directory beginning with an underscore.
touch($out . '/.nojekyll');

printf("%d pages + the landing page → %s\n", $pages, $out);
