<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Enum\Theme\Theme;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The link between the theme and the mark: who decides the colourway.
 *
 * Every logo style is a theme now, value for value, and appearance_logo_linked
 * says which of the two axes dresses the mark. What is pinned here is the
 * resolution — Appearance::effectiveLogoStyle() — as the favicon route
 * actually serves it: unlinked, the user's own logoStyle answers; linked, the
 * theme's namesake style answers; and a linked classic theme (paper, dark…)
 * has no namesake, so the product default answers rather than an error or a
 * stale choice.
 */
final class LogoLinkedTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    protected function tearDown(): void
    {
        // Linked-true on the default colourway and the default theme is what a
        // fresh account holds — put the seed user back rather than leaking one
        // test's choices into the next suite.
        if (null !== $user = $this->find()) {
            $user->appearance->theme      = Theme::Paper;
            $user->appearance->logoStyle  = LogoStyle::DEFAULT;
            $user->appearance->logoLinked = true;
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testAnUnlinkedMarkWearsItsOwnColourway(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, ['logoLinked' => false, 'logoStyle' => 'ocean']);

        $svg = $this->favicon($client);

        foreach (LogoStyle::Ocean->strokes() as $hex) {
            self::assertStringContainsString($hex, $svg, 'the unlinked mark serves the chosen ocean');
        }
    }

    public function testALinkedMarkFollowsTheTheme(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, ['logoLinked' => true, 'theme' => 'ember']);

        $svg = $this->favicon($client);

        foreach (LogoStyle::Ember->strokes() as $hex) {
            self::assertStringContainsString($hex, $svg, 'the linked mark serves the theme\'s ember');
        }

        self::assertStringNotContainsString(
            '#a21caf',
            $svg,
            'the stored berry logoStyle must not bleed through the link',
        );
    }

    public function testALinkedClassicThemeServesTheProductDefault(): void
    {
        [$client] = $this->signedIn();

        // Give the stored logoStyle a loud value first, so serving the default
        // proves the LINK resolved it — not a column that happened to be berry.
        $this->post($client, ['logoLinked' => false, 'logoStyle' => 'postal']);
        $this->post($client, ['logoLinked' => true, 'theme' => 'paper']);

        $svg = $this->favicon($client);

        foreach (LogoStyle::DEFAULT->strokes() as $hex) {
            self::assertStringContainsString($hex, $svg, 'a classic theme has no namesake style; berry answers');
        }

        self::assertStringNotContainsString(
            '#c8402f',
            $svg,
            'the stored postal must stay stored, not served, while linked',
        );
    }

    private function post(KernelBrowser $client, array $payload): void
    {
        $client->request(
            'POST',
            '/settings/appearance',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );

        self::assertResponseIsSuccessful();
    }

    private function favicon(KernelBrowser $client): string
    {
        $client->request('GET', '/branding/favicon.svg');

        self::assertResponseIsSuccessful();

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

    private function find(): ?User
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        return $user instanceof User ? $user : null;
    }
}
