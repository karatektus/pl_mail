<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The mark follows the person: topbar, tab icon and the setting that drives
 * both.
 *
 * The tab icon is a ROUTE, because a static file can wear exactly one icon and
 * the icon is a per-user choice. The page names the choice in the link, and the
 * route draws what the link names and nothing else. What is pinned here is that
 * loop: the setting round-trips through the appearance endpoint, the link
 * names it, the route draws it without consulting the session, and an
 * anonymous page links the product default.
 */
final class BrandingFaviconTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    protected function tearDown(): void
    {
        // The logo is a per-user preference with no fixture of its own — put
        // the seed user back on the default rather than leaking one test's
        // choice into the next suite's screenshots.
        if (null !== $user = $this->find()) {
            $user->appearance->logoMotif    = LogoMotif::DEFAULT;
            $user->appearance->logoOriginal = false;
            $user->appearance->logoStyle    = LogoStyle::DEFAULT;
            $user->appearance->logoLinked   = true;
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testAnAnonymousPageLinksTheDefaultTabIcon(): void
    {
        $client = static::createClient();

        $svg = $this->tabIcon($client, '/login');

        foreach (LogoStyle::DEFAULT->strokes() as $hex) {
            self::assertStringContainsString($hex, $svg);
        }
    }

    public function testTheTabIconWearsTheUsersChoice(): void
    {
        [$client, $user] = $this->signedIn();

        // Unlinked, so the colourway set here is the one that answers — while
        // linked the theme would dress the mark instead (LogoLinkedTest).
        $user->appearance->logoStyle  = LogoStyle::Postal;
        $user->appearance->logoLinked = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $svg = $this->tabIcon($client, '/mail/inbox');

        self::assertStringContainsString('#1e3a6e', $svg, "the postal navy 'p'");
        self::assertStringContainsString('#c8402f', $svg, "the postal red 'l'");
        self::assertStringNotContainsString('#a21caf', $svg, 'the default berry must not bleed through a choice');
    }

    /**
     * The bug this route was rebuilt for. The appearance pane points the tab
     * at a new choice the moment it is clicked, and the save lands after. A
     * tab icon drawn from the session drew the choice from BEFORE the click,
     * and the browser cached it under the new URL for a week. Every tab icon
     * was the previous one, except where the browser had already fetched that
     * URL correctly.
     */
    public function testTheTabIconDrawsWhatItsUrlNamesNotWhatTheSessionHolds(): void
    {
        [$client, $user] = $this->signedIn();

        // The session says: the pl mark in postal.
        $user->appearance->logoMotif  = LogoMotif::Pl;
        $user->appearance->logoStyle  = LogoStyle::Postal;
        $user->appearance->logoLinked = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // The link says: the blue horn in its own design — what was just picked.
        $client->request('GET', '/branding/favicon/blue-horn/original.svg');

        self::assertResponseIsSuccessful();

        $svg = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('#1e3a6e', $svg, 'the postal in the session must not be what is drawn');

        // Public, which it can be now that nothing in it is the session's.
        self::assertStringNotContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));

        // The horn's tab icon is its tile.
        $client->request('GET', '/branding/icon/blue-horn/original.svg');
        self::assertSame((string) $client->getResponse()->getContent(), $svg);
    }

    public function testTheTopbarWearsTheSameStrokesTheFaviconDoes(): void
    {
        [$client, $user] = $this->signedIn();

        $user->appearance->logoStyle  = LogoStyle::MidnightGold;
        $user->appearance->logoLinked = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $mark = $crawler->filter('[data-logo-live] [data-logo-stroke]');

        self::assertCount(7, $mark, 'the live mark draws exactly the seven contracted strokes');

        $strokes = $mark->each(static fn ($node): string => (string) $node->attr('stroke'));

        // The seed user's theme decides which chrome the topbar paints for,
        // so accept either list — what may not happen is a mix, or a palette
        // belonging to another style.
        self::assertContains(
            $strokes,
            [LogoStyle::MidnightGold->strokes(), LogoStyle::MidnightGold->strokes(true)],
        );
    }

    public function testTheColourwayRoundTripsThroughTheAppearanceEndpoint(): void
    {
        [$client] = $this->signedIn();

        // The layout's `ajax` token, which the appearance controller sends.
        $token = (string) $client->request('GET', '/mail/inbox')->filter('meta[name="csrf-token"]')->attr('content');

        $client->request(
            'POST',
            '/settings/appearance',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: json_encode(['logoStyle' => 'ocean']),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(LogoStyle::Ocean, $this->reread()->appearance->logoStyle);

        // An unknown value is ignored rather than defaulted: the payload is
        // browser-supplied, and "keep what I had" is the only answer that
        // cannot lose a choice to a typo in a client.
        $client->request(
            'POST',
            '/settings/appearance',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: json_encode(['logoStyle' => 'chartreuse-dreams']),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(LogoStyle::Ocean, $this->reread()->appearance->logoStyle);
    }

    /** The tab icon a page links, fetched the way the browser would. */
    private function tabIcon(KernelBrowser $client, string $page): string
    {
        $href = (string) $client->request('GET', $page)
            ->filter('link[rel="icon"][type="image/svg+xml"]')
            ->attr('href');

        $client->request('GET', $href);

        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $client->getResponse()->headers->get('Content-Type'));

        return (string) $client->getResponse()->getContent();
    }

    /** @return array{KernelBrowser, User} */
    private function signedIn(): array
    {
        $client = static::createClient();

        $user = $this->find();

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return [$client, $user];
    }

    /** The user as the database now has them, not as the last request left them. */
    private function reread(): User
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $user = $this->find();

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function find(): ?User
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        return $user instanceof User ? $user : null;
    }
}
