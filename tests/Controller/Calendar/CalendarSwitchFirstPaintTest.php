<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The topbar's calendar switch is drawn in the remembered position at first
 * paint, at the widths that show that position.
 *
 * It used to go out as `mail` whatever the preference said, and ui--split
 * corrected it on connect. By then the page had been styled, and the switch
 * carries `transition-colors` for its hover, so every desktop load and every
 * Turbo visit with a remembered split or calendar painted the mail icon, then
 * swapped in the right one and faded the accent in over 150ms.
 *
 * Below lg the controller opens every position on the mail, so there the first
 * paint has to stay `mail`. The server cannot know the width, so it writes both
 * answers behind the `lg` breakpoint and ui--split takes those classes off on
 * connect (see "The first paint" in _partials/_topbar.html.twig).
 *
 * Asserted against the raw response, like SidebarRowStateTest: the test client
 * runs no JavaScript, so the classes are read here the way the stylesheet reads
 * them at each width. The browser-level reading — `transitionrun` and per-frame
 * computed styles at 1280 and 400 pixels, through a load and a Turbo visit —
 * was taken in Chromium when this changed; it is not repeated here.
 */
final class CalendarSwitchFirstPaintTest extends WebTestCase
{
    private const string CONTROLLER = 'assets/controllers/ui/split_controller.js';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The user below lives in this test's transaction, and a reboot
        // between requests would take the connection holding it.
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{CalendarPaneMode}> */
    public static function remembered(): iterable
    {
        yield 'split, the default' => [CalendarPaneMode::Split];
        yield 'calendar' => [CalendarPaneMode::Calendar];
    }

    #[DataProvider('remembered')]
    public function testAPhonePaintsMailAndADesktopPaintsTheRememberedPosition(CalendarPaneMode $mode): void
    {
        $switch = $this->switchOn('/mail/inbox', $mode);

        self::assertSame(['mail'], $this->iconsDrawn($switch, wide: false), 'below lg ui--split opens on the mail');
        self::assertSame([$mode->value], $this->iconsDrawn($switch, wide: true));

        // Accented from lg up only. A plain `text-accent` would accent a phone's
        // first paint too, and connect() would fade it straight back out.
        $classes = $this->classes($switch);
        self::assertContains('text-ink-muted', $classes);
        self::assertContains('lg:text-accent', $classes);
        self::assertNotContains('text-accent', $classes);

        // Every class the server added for the first paint is one ui--split
        // takes off. One it did not know about would outlive connect and fight
        // _render: an `lg:hidden` left on the mail icon hides it from every
        // desktop for good. The icons settle to `flex`, the switch to what a
        // page without the first paint renders.
        $icons = array_merge(...$switch->filter('[data-calendar-mode-icon]')->each(
            fn (Crawler $icon): array => array_diff($this->classes($icon), ['flex']),
        ));

        self::assertEqualsCanonicalizing($this->controllerList('FIRST_PAINT_ICON'), array_values(array_unique($icons)));
        self::assertEqualsCanonicalizing(
            $this->controllerList('FIRST_PAINT_TOGGLE'),
            array_values(array_filter($classes, static fn (string $class): bool => str_starts_with($class, 'lg:'))),
        );
    }

    /**
     * Only the mailbox shell has a ui--split to take the first paint's classes
     * off. Anywhere else they would stay for good — no icon at all from lg up —
     * so every other page that includes the bar draws `mail`, as it always did.
     */
    public function testAPageWithoutTheShellDrawsTheSwitchAsMail(): void
    {
        $switch = $this->switchOn('/settings', CalendarPaneMode::Split);

        self::assertSame(['mail'], $this->iconsDrawn($switch, wide: false));
        self::assertSame(['mail'], $this->iconsDrawn($switch, wide: true));
        self::assertSame([], array_values(array_filter($this->classes($switch), static fn (string $class): bool => str_contains($class, 'lg:'))));
    }

    private function switchOn(string $page, CalendarPaneMode $mode): Crawler
    {
        $user            = new User();
        $user->email     = 'first-paint-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'First';
        $user->nameLast  = 'Paint';
        $user->roles     = [User::ROLE_USER];
        $user->password  = 'x';

        $user->calendarPaneMode = $mode;

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $page);

        self::assertResponseIsSuccessful();

        $switch = $crawler->filter('a[data-calendar-toggle]');
        self::assertCount(1, $switch);

        return $switch;
    }

    /**
     * Which icons the stylesheet draws at one side of `lg`: the attribute hides
     * at every width, `lg:hidden` from lg up, and the `hidden` class everywhere
     * except where `lg:flex` puts it back.
     *
     * @return list<string>
     */
    private function iconsDrawn(Crawler $switch, bool $wide): array
    {
        $drawn = $switch->filter('[data-calendar-mode-icon]')->each(function (Crawler $icon) use ($wide): ?string {
            $classes = $this->classes($icon);
            $hidden  = null !== $icon->attr('hidden')
                || (in_array('hidden', $classes, true) && (false === $wide || false === in_array('lg:flex', $classes, true)))
                || (true === $wide && in_array('lg:hidden', $classes, true));

            return true === $hidden ? null : (string) $icon->attr('data-calendar-mode-icon');
        });

        return array_values(array_filter($drawn, static fn (?string $mode): bool => null !== $mode));
    }

    /** @return list<string> */
    private function classes(Crawler $element): array
    {
        return array_values(array_filter(preg_split('/\s+/', (string) $element->attr('class')) ?: []));
    }

    /** @return list<string> */
    private function controllerList(string $constant): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::CONTROLLER);

        self::assertSame(
            1,
            preg_match('/static ' . $constant . '\s*=\s*\[([^\]]*)\]/', $source, $match),
            sprintf('%s no longer declares %s', self::CONTROLLER, $constant),
        );

        preg_match_all('/"([^"]+)"/', $match[1], $classes);

        return $classes[1];
    }
}
