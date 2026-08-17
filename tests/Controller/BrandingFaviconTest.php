<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
 * The favicon is a ROUTE now, because a static file can wear exactly one
 * colourway and the colourway is a per-user choice. What is pinned here is
 * the whole loop: the setting round-trips through the appearance endpoint,
 * the favicon answers in the chosen palette, the topbar renders the same
 * strokes, and an anonymous request gets the product default rather than an
 * error or someone else's choice.
 */
final class BrandingFaviconTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    protected function tearDown(): void
    {
        // The colourway is a per-user preference with no fixture of its own —
        // put the seed user back on the default rather than leaking one
        // test's ink into the next suite's screenshots.
        if (null !== $user = $this->find()) {
            $user->appearance->logoStyle = LogoStyle::DEFAULT;
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testAnAnonymousRequestGetsTheDefaultColourway(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/favicon.svg');

        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $client->getResponse()->headers->get('Content-Type'));

        $svg = (string) $client->getResponse()->getContent();

        foreach (LogoStyle::DEFAULT->strokes() as $hex) {
            self::assertStringContainsString($hex, $svg);
        }
    }

    public function testTheFaviconAnswersInTheUsersChosenColourway(): void
    {
        [$client, $user] = $this->signedIn();

        $user->appearance->logoStyle = LogoStyle::Postal;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/branding/favicon.svg');

        self::assertResponseIsSuccessful();

        $svg = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('#1e3a6e', $svg, "the postal navy 'p'");
        self::assertStringContainsString('#c8402f', $svg, "the postal red 'l'");
        self::assertStringNotContainsString('#a21caf', $svg, 'the default berry must not bleed through a choice');

        // Private, because the answer depends on the session — a shared cache
        // serving one user's colourway to another is invisible until someone
        // wonders whose favicon they are looking at.
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheTopbarWearsTheSameStrokesTheFaviconDoes(): void
    {
        [$client, $user] = $this->signedIn();

        $user->appearance->logoStyle = LogoStyle::MidnightGold;
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

        $client->request(
            'POST',
            '/settings/appearance',
            server: ['CONTENT_TYPE' => 'application/json'],
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
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['logoStyle' => 'chartreuse-dreams']),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(LogoStyle::Ocean, $this->reread()->appearance->logoStyle);
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
