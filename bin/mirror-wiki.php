<?php

declare(strict_types=1);

/**
 * Publishes docs/ to the GitHub wiki.
 *
 * The wiki is a second git repository (pl_mail.wiki.git) with a flat namespace:
 * every page is one Markdown file at the root, and the file name IS the page
 * name and the URL. docs/ is a tree. So this flattens — docs/features/mail.md
 * becomes Features-Mail.md — and rewrites every relative link to match, because
 * a link to ../internals/jmap.md means nothing once both files are siblings.
 *
 * Authoring stays in docs/, in the code repository, where a change to the sync
 * engine and the paragraph describing it are in one commit and one review. The
 * wiki is a mirror and nothing else: it is overwritten wholesale on every run,
 * and an edit made in the browser survives exactly until the next push to main.
 * That cost is deliberate and the wiki footer says so on every page, because a
 * contributor who does not know it will lose work.
 *
 * The alternative — the wiki as the source, edited in the browser — was
 * rejected for the reason the documentation exists at all: prose that is not
 * versioned with the code it describes drifts from it, and the drift is
 * invisible until somebody follows an instruction that stopped being true.
 *
 * Usage:
 *
 *   php bin/mirror-wiki.php <checkout-of-the-wiki-repo>
 *   php bin/mirror-wiki.php --check          (renders to a temporary directory
 *                                             and asserts nothing is left
 *                                             dangling; what CI runs)
 *
 * The wiki repository does not exist until somebody has created one page in the
 * browser — GitHub does not create it on demand and there is no API for it. The
 * script says so plainly rather than failing with a git error nobody can act on.
 */

$projectDir = \dirname(__DIR__);
$docsDir    = $projectDir . '/docs';

$target = $argv[1] ?? null;

if (null === $target) {
    fwrite(STDERR, "usage: php bin/mirror-wiki.php <wiki-checkout>|--check\n");

    exit(1);
}

$checkOnly = '--check' === $target;

if (true === $checkOnly) {
    $target = sys_get_temp_dir() . '/plmail-wiki-check-' . bin2hex(random_bytes(4));

    mkdir($target, 0o775, true);
}

if (false === is_dir($target)) {
    fwrite(STDERR, sprintf(
        "%s is not a directory.\n\n"
        . "If the wiki has never been used, GitHub has not created its repository yet:\n"
        . "open https://github.com/karatektus/pl_mail/wiki and save any page once,\n"
        . "then clone git@github.com:karatektus/pl_mail.wiki.git and pass it here.\n",
        $target,
    ));

    exit(1);
}

/** docs-relative path → wiki page name, e.g. features/mail.md → Features-Mail */
$pageNameFor = static function (string $relative): string {
    if ('README.md' === $relative) {
        return 'Home';
    }

    $withoutExtension = substr($relative, 0, -\strlen('.md'));

    $words = preg_split('/[\/\-_]/', $withoutExtension) ?: [];

    // Lowercased before capitalising, so CLIENT_DEVELOPMENT.md becomes
    // Client-Development rather than CLIENT-DEVELOPMENT. A page name is a URL,
    // so this is worth getting right once and never changing: renaming a page
    // later breaks every link anybody has saved or sent.
    return implode('-', array_map(
        static fn (string $word): string => ucfirst(strtolower($word)),
        array_filter($words, static fn (string $word): bool => '' !== $word),
    ));
};

/** @return list<string> docs-relative paths */
$collect = static function (string $dir, string $prefix = '') use (&$collect): array {
    $found = [];

    foreach (scandir($dir) ?: [] as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (true === is_dir($path)) {
            $found = [...$found, ...$collect($path, $prefix . $entry . '/')];

            continue;
        }

        if ('md' === pathinfo($entry, PATHINFO_EXTENSION)) {
            $found[] = $prefix . $entry;
        }
    }

    return $found;
};

$pages = $collect($docsDir);
sort($pages);

$dangling = [];

foreach ($pages as $relative) {
    $body = file_get_contents($docsDir . '/' . $relative);

    if (false === $body) {
        continue;
    }

    $sourceDir = \dirname($docsDir . '/' . $relative);

    // Rewrite every relative link into a flat wiki page name. Anything that
    // resolves outside docs/ — ../README.md, ../CONTRIBUTING.md — becomes an
    // absolute link to the file on GitHub, because the wiki cannot reach the
    // code repository's tree with a relative path.
    $body = preg_replace_callback(
        '/\]\(([^)#]+)(#[^)]*)?\)/',
        static function (array $match) use ($sourceDir, $docsDir, $pageNameFor, $relative, &$dangling): string {
            $target   = $match[1];
            $fragment = $match[2] ?? '';

            if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://') || str_starts_with($target, 'mailto:')) {
                return $match[0];
            }

            $resolved = realpath($sourceDir . '/' . $target);

            if (false === $resolved) {
                $dangling[] = sprintf('%s → %s', $relative, $target);

                return $match[0];
            }

            // An image or anything else that is not a page: point at the raw
            // file on GitHub, since the wiki has no copy of it.
            if ('md' !== pathinfo($resolved, PATHINFO_EXTENSION)) {
                $inRepo = str_replace($docsDir . '/../', '', $resolved);
                $inRepo = str_replace(\dirname($docsDir) . '/', '', $inRepo);

                return sprintf('](https://raw.githubusercontent.com/karatektus/pl_mail/main/%s)', $inRepo);
            }

            if (false === str_starts_with($resolved, $docsDir . '/')) {
                $inRepo = str_replace(\dirname($docsDir) . '/', '', $resolved);

                return sprintf('](https://github.com/karatektus/pl_mail/blob/main/%s%s)', $inRepo, $fragment);
            }

            return sprintf('](%s%s)', $pageNameFor(substr($resolved, \strlen($docsDir) + 1)), $fragment);
        },
        $body,
    ) ?? $body;

    $body .= sprintf(
        "\n\n---\n\n*This page is generated from [`docs/%s`](https://github.com/karatektus/pl_mail/blob/main/docs/%s)."
        . " Edit it there — changes made here are overwritten on the next push to `main`.*\n",
        $relative,
        $relative,
    );

    file_put_contents($target . '/' . $pageNameFor($relative) . '.md', $body);
}

// The sidebar, generated from the index so the two cannot disagree.
$sidebar = "### plMail\n\n";
$section = '';

foreach (file($docsDir . '/README.md') ?: [] as $line) {
    if (1 === preg_match('/^## (.+)$/', trim($line), $heading)) {
        $section = $heading[1];

        if ('Conventions in these pages' !== $section) {
            $sidebar .= sprintf("\n**%s**\n\n", $section);
        }

        continue;
    }

    if ('Conventions in these pages' === $section) {
        continue;
    }

    if (1 === preg_match('/^\| \[([^\]]+)\]\(([^)]+\.md)\)/', trim($line), $row)) {
        $sidebar .= sprintf("- [%s](%s)\n", $row[1], $pageNameFor($row[2]));
    }
}

file_put_contents($target . '/_Sidebar.md', $sidebar);

if ([] !== $dangling) {
    fwrite(STDERR, "These links do not resolve and were left as-is:\n  " . implode("\n  ", $dangling) . "\n");

    exit(1);
}

printf("%d pages rendered to %s\n", \count($pages), $target);

if (true === $checkOnly) {
    array_map(unlink(...), glob($target . '/*.md') ?: []);
    rmdir($target);
}
