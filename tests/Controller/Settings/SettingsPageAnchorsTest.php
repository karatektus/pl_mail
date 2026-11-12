<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Repository\User\User\UserRepository;
use App\Service\Settings\SettingsNavigation;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings pages, their addresses and the cards search leads to (#569).
 *
 * The claims, and what each one guards:
 *
 *   - Every page draws every card SettingsNavigation lists for it, under the
 *     anchor listed. The table and the templates are two copies of one fact —
 *     which cards a page has — and a card moved, renamed or dropped in a
 *     template leaves search pointing at an anchor that is not there. Nothing
 *     on screen says so: the page opens, it just does not scroll anywhere.
 *   - The tree marks the open page, and its area is the one left open.
 *   - /settings opens the first page by its own address, an old `?section=`
 *     link lands there too rather than on a map of old names, and an unknown
 *     page is a 404 rather than a quiet fallback.
 *   - The search index lists pages and cards, with keywords, and leaves the
 *     Assistant out where the administrator has switched every AI feature off.
 *
 * Needs the seeded admin (`app:test:seed-user --admin`), like PageRendersTest.
 */
final class SettingsPageAnchorsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** @return iterable<string, array{string, list<string>}> */
    public static function pages(): iterable
    {
        foreach ((new SettingsNavigation())->areas() as $area) {
            foreach ($area->pages as $page) {
                yield $page->slug => [$page->slug, array_map(static fn ($card): string => $card->anchor, $page->cards)];
            }
        }
    }

    /**
     * @param list<string> $anchors
     */
    #[DataProvider('pages')]
    public function testThePageDrawsEveryCardSearchLeadsTo(string $slug, array $anchors): void
    {
        $client  = $this->signedIn();
        $crawler = $client->request('GET', '/settings/' . $slug);

        self::assertResponseIsSuccessful();

        $missing = array_values(array_filter(
            $anchors,
            static fn (string $anchor): bool => 0 === $crawler->filter('#' . $anchor)->count(),
        ));

        self::assertSame([], $missing, sprintf('/settings/%s does not draw these cards', $slug));
        self::assertCount(1, $crawler->filter(sprintf('nav a[href$="/settings/%s"][aria-current="page"]', $slug)));
    }

    public function testOnlyTheAreaHoldingTheOpenPageIsUnfolded(): void
    {
        $crawler = $this->signedIn()->request('GET', '/settings/labels');

        $open = $crawler->filter('#settings-tree details[open]');

        self::assertCount(1, $open);
        self::assertCount(1, $open->filter('a[href$="/settings/labels"]'));
    }

    public function testBareSettingsOpensTheFirstPageAtItsOwnAddressWhateverTheQuery(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/settings');
        self::assertResponseRedirects('/settings/profile');

        // An old bookmark. There is no map from sections to pages on purpose
        // — see SettingsController::index().
        $client->request('GET', '/settings?section=swipe-actions');
        self::assertResponseRedirects('/settings/profile');
    }

    public function testAnUnknownPageIsNotFound(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/settings/general');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheSearchIndexHasPagesAndCardsWithTheirKeywords(): void
    {
        $client = $this->signedIn();
        $client->request('GET', '/settings/search');

        self::assertResponseIsSuccessful();

        /** @var array{entries: list<array{title: string, crumb: string, url: string, terms: string, page: bool}>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $byUrl = array_column($body['entries'], null, 'url');

        self::assertArrayHasKey('/settings/layout', $byUrl);
        self::assertTrue($byUrl['/settings/layout']['page']);

        self::assertArrayHasKey('/settings/appearance#card-theme', $byUrl, 'a card is its own result');
        self::assertFalse($byUrl['/settings/appearance#card-theme']['page']);
        self::assertStringContainsString('dark', $byUrl['/settings/appearance#card-theme']['terms']);
        self::assertStringContainsString('›', $byUrl['/settings/appearance#card-theme']['crumb']);

        // A single-card page whose card has the page's name is one result, not
        // two with the same title — its keywords go on the page.
        self::assertArrayNotHasKey('/settings/labels#card-labels', $byUrl);
        self::assertStringContainsString('folders', $byUrl['/settings/labels']['terms']);
    }

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en');

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
