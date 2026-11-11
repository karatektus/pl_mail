<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\SettingsNavigation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The settings table holds together (#569).
 *
 * Three ways it can be wrong without anything failing on screen:
 *
 *   - PAGE_REQUIREMENT, the route's copy of the slugs, misses a page. That page
 *     answers 404 from every link the tree draws to it.
 *   - Two pages or two cards share a name. A slug is an address and an anchor
 *     is an element id; a duplicate of either opens the first and never the
 *     second.
 *   - A label, heading or keywords key is not in the catalogue. The tree then
 *     shows the raw key, and search matches it.
 */
final class SettingsNavigationTest extends KernelTestCase
{
    public function testTheRouteRequirementNamesEveryPageInTreeOrder(): void
    {
        $slugs = [];

        foreach ($this->navigation()->areas() as $area) {
            foreach ($area->pages as $page) {
                $slugs[] = $page->slug;
            }
        }

        self::assertSame($slugs, explode('|', SettingsNavigation::PAGE_REQUIREMENT));
        self::assertContains(SettingsNavigation::DEFAULT_PAGE, $slugs);
    }

    public function testNoTwoPagesShareASlugAndNoTwoCardsShareAnAnchor(): void
    {
        $slugs   = [];
        $anchors = [];

        foreach ($this->navigation()->areas() as $area) {
            foreach ($area->pages as $page) {
                $slugs[] = $page->slug;

                foreach ($page->cards as $card) {
                    $anchors[] = $card->anchor;
                }
            }
        }

        self::assertSame(array_unique($slugs), $slugs);
        self::assertSame(array_unique($anchors), $anchors);
    }

    /**
     * English only: CatalogueParityTest already holds every other catalogue to
     * the English one, key for key.
     */
    public function testEveryLabelHeadingAndKeywordListIsInTheCatalogue(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        $catalogue = $translator->getCatalogue('en');
        $missing   = [];

        foreach ($this->navigation()->areas() as $area) {
            $keys = [$area->labelKey];

            foreach ($area->pages as $page) {
                $keys[] = $page->labelKey;

                foreach ($page->cards as $card) {
                    $keys[] = $card->headingKey;

                    if (null !== $card->keywordsKey) {
                        $keys[] = $card->keywordsKey;
                    }
                }
            }

            foreach ($keys as $key) {
                if (false === $catalogue->has($key)) {
                    $missing[] = $key;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testAnAreaIsFoundForEveryPageAndAnUnknownSlugIsNoPage(): void
    {
        $navigation = $this->navigation();

        self::assertSame('inbox', $navigation->areaOf($navigation->page('labels') ?? self::fail('no labels page'))->key);
        self::assertSame('general', $navigation->areaOf($navigation->page('layout') ?? self::fail('no layout page'))->key);
        self::assertNull($navigation->page('general'), 'General is an area now, not a page');
        self::assertNull($navigation->page('search'));
    }

    private function navigation(): SettingsNavigation
    {
        return self::getContainer()->get(SettingsNavigation::class);
    }
}
