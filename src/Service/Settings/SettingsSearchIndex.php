<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Service\Ai\AiPermissions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything Settings search can find, translated for the reader: one entry per
 * page and one per card (#569).
 *
 * Served as JSON to the ⌘K palette, which is on every page and so cannot be
 * handed the tree the settings page renders. The palette asks once, on first
 * open, and filters in the browser: about eighty short entries, which is less
 * than one round trip per keystroke would cost.
 *
 * The filter above the settings tree does NOT read this. It works over the
 * tree the page already drew (see settings/_nav.html.twig), so the two share
 * the table in SettingsNavigation and the matching rule in
 * assets/settings/search.js, not this list.
 *
 * Text, not keys, because the browser has no catalogue. Matching ignores case
 * and accents on the client; the words go out as the catalogue has them.
 *
 * The Assistant page is left out where the administrator has switched every AI
 * feature off, for the reason the tree leaves it out — a result leading to
 * three rows of "ask your administrator" is not an answer. Account health is
 * kept: a page about what is broken is worth finding even when nothing is.
 */
final readonly class SettingsSearchIndex
{
    public function __construct(
        private SettingsNavigation    $navigation,
        private TranslatorInterface   $translator,
        private UrlGeneratorInterface $urls,
        private AiPermissions         $aiPermissions,
    ) {
    }

    /**
     * @return list<array{title: string, crumb: string, url: string, terms: string, page: bool}>
     */
    public function entries(): array
    {
        $aiOn    = $this->aiPermissions->anyAdminEnabled();
        $entries = [];

        foreach ($this->navigation->areas() as $area) {
            $areaName = $this->translator->trans($area->labelKey);

            foreach ($area->pages as $page) {
                if ('ai' === $page->slug && false === $aiOn) {
                    continue;
                }

                $pageName = $this->translator->trans($page->labelKey);
                $pageUrl  = $this->urls->generate('app_settings_page', ['page' => $page->slug]);

                $pageEntry = [
                    'title' => $pageName,
                    'crumb' => $areaName,
                    'url'   => $pageUrl,
                    'terms' => '',
                    'page'  => true,
                ];
                $cardEntries = [];

                foreach ($page->cards as $card) {
                    $title = $this->translator->trans($card->headingKey);
                    $terms = null === $card->keywordsKey ? '' : $this->translator->trans($card->keywordsKey);

                    // A page of one card usually names the card after itself
                    // (Labels → "Labels"). That card is the page as far as a
                    // reader can tell, so its words go on the page's entry
                    // rather than into a second result with the same title,
                    // which would only push a different one off the list.
                    if ($title === $pageName) {
                        $pageEntry['terms'] = trim(sprintf('%s %s', $pageEntry['terms'], $terms));

                        continue;
                    }

                    $cardEntries[] = [
                        'title' => $title,
                        'crumb' => sprintf('%s › %s', $areaName, $pageName),
                        'url'   => sprintf('%s#%s', $pageUrl, $card->anchor),
                        'terms' => $terms,
                        'page'  => false,
                    ];
                }

                array_push($entries, $pageEntry, ...$cardEntries);
            }
        }

        return $entries;
    }
}
