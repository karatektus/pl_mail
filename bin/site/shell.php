<?php

declare(strict_types=1);

/**
 * The page shell — everything around the rendered Markdown.
 *
 * Returned as a closure rather than written as a class, because it is one
 * function with no state and lives beside the one script that calls it; a class
 * here would be a namespace, an autoload entry and a file, to hold nothing.
 *
 * `$root` is the path back to the site root from wherever this page sits, and
 * every asset and navigation link is written against it. Absolute paths were
 * rejected deliberately: the site has to work at `/` on a custom domain and at
 * `/pl_mail/` on github.io, and a leading slash silently picks one of those.
 *
 * The landing page and a handbook page share this shell and differ by a flag,
 * because they differ in two things only — the landing page has no sidebar and
 * gets a hero — and two templates would drift in the ten things they agree on.
 */

return static function (
    string $title,
    string $content,
    string $root,
    string $here,
    array  $nav,
    bool   $landing,
    string $source,
    string $heroLead = '',
    array  $themes = [],
    array  $languages = [],
    string $locale = 'en',
    bool   $fallback = false,
): string {
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $sidebar = '';

    if (false === $landing) {
        $sidebar .= '<nav class="sidebar" aria-label="Handbook">';

        foreach ($nav as $section) {
            $sidebar .= sprintf('<p class="sidebar-heading">%s</p><ul>', $escape($section['title']));

            foreach ($section['pages'] as $page) {
                $sidebar .= sprintf(
                    '<li><a href="%s%s"%s>%s</a></li>',
                    $escape($root . 'docs/'),
                    $escape($page['href']),
                    $page['href'] === $here ? ' aria-current="page"' : '',
                    $escape($page['title']),
                );
            }

            $sidebar .= '</ul>';
        }

        $sidebar .= '</nav>';
    }

    // The hero holds no writing of its own. The lead is README's own opening
    // paragraph, handed in by the generator, so there is no sentence on this
    // site that is not also in the repository — which is the property the whole
    // build exists to have, and it would be odd to break it on the first screen.
    $hero = '';

    if (true === $landing) {
        $lead = $escape($heroLead);

        $hero = <<<HTML
            <header class="hero">
                <div class="hero-inner">
                    <p class="hero-eyebrow">Self-hosted mail and calendar</p>
                    <h1 class="hero-title">plMail</h1>
                    <p class="hero-lead">{$lead}</p>
                    <p class="hero-actions">
                        <a class="button button-primary" href="{$root}docs/install/docker.html">Install it</a>
                        <a class="button" href="{$root}docs/">Read the handbook</a>
                        <a class="button" href="https://github.com/karatektus/pl_mail">Source</a>
                    </p>
                </div>
            </header>
            HTML;
    }

    // The seven the app itself offers, generated from Theme::swatch() — see
    // bin/build-site.php. Selected on load by site.js from what was stored,
    // because the shell is static and has no idea what this visitor chose.
    $themeOptions = '';

    foreach ($themes as $theme) {
        $themeOptions .= sprintf(
            '<option value="%s">%s</option>',
            $escape($theme['value']),
            $escape($theme['label']),
        );
    }

    // A language with no handbook is listed and disabled rather than omitted.
    $languageOptions = '';

    foreach ($languages as $language) {
        $languageOptions .= sprintf(
            '<option value="%s"%s>%s%s</option>',
            $escape($language['value']),
            true === $language['available'] ? '' : ' disabled',
            $escape($language['label']),
            true === $language['available'] ? '' : ' — not translated yet',
        );
    }

    // Said in the language the reader asked for, because a reader who does not
    // read English is exactly the person this notice is for.
    $untranslated = [
        'de' => 'Diese Seite ist noch nicht übersetzt und wird auf Englisch angezeigt.',
    ];

    $notice = true === $fallback && true === isset($untranslated[$locale])
        ? sprintf('<p class="fallback-notice">%s</p>', $escape($untranslated[$locale]))
        : '';

    $bodyClass = true === $landing ? 'is-landing' : 'is-doc';

    return <<<HTML
        <!doctype html>
        <html lang="{$locale}">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$escape($title)}</title>
        <meta name="description" content="plMail — a self-hosted mail client with a calendar, for one person or a household.">
        <link rel="stylesheet" href="{$root}assets/site.css">
        <script>
        // Before first paint, so a dark-mode visitor never sees a white flash.
        // Inline for the same reason: a separate file is a round trip, and the
        // round trip IS the flash.
        (function () {
            // Solar unless the reader has said otherwise. A default of "system"
            // would be the safer-looking choice and would make the site look
            // like every other docs site; Solar is one of plMail's own seven and
            // is what the site is for.
            var chosen = localStorage.getItem("plmail-theme") || "solar";
            if (chosen !== "system") {
                document.documentElement.dataset.theme = chosen;
            }
        })();
        </script>
        </head>
        <body class="{$bodyClass}">

        <a class="skip" href="#content">Skip to content</a>

        <div class="topbar">
            <a class="brand" href="{$root}index.html">plMail</a>
            <div class="topbar-links">
                <a href="{$root}docs/">Handbook</a>
                <a href="https://github.com/karatektus/pl_mail">GitHub</a>
            </div>
            <div class="search" role="search">
                <input id="search" type="search" placeholder="Search the handbook" autocomplete="off"
                       aria-label="Search the handbook" data-root="{$root}" data-locale="{$locale}">
                <ul id="results" hidden></ul>
            </div>
            <div class="pickers">
                <label class="picker">
                    <span class="picker-label">Theme</span>
                    <select id="theme" aria-label="Theme">{$themeOptions}</select>
                </label>
                <label class="picker">
                    <span class="picker-label">Language</span>
                    <select id="language" aria-label="Language">{$languageOptions}</select>
                </label>
            </div>
        </div>

        {$hero}

        <div class="layout">
            {$sidebar}
            <main id="content" class="prose">
        {$notice}
        {$content}
                <footer class="page-footer">
                    <a href="https://github.com/karatektus/pl_mail/blob/main/{$escape($source)}">Edit this page</a>
                    — it is generated from <code>{$escape($source)}</code> in the repository.
                </footer>
            </main>
        </div>

        <script src="{$root}assets/site.js" defer></script>
        </body>
        </html>
        HTML;
};
