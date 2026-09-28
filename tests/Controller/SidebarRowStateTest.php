<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Twig\SidebarRowStateExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which sidebar row is open is in the HTML, not applied after paint.
 *
 * The rows used to go out with no colour class, and ui--sidebar gave the open
 * one its pill on connect — after the browser had already styled the page, so
 * every row's hover transition turned that pass into a 150ms fade from black,
 * on every load and every Turbo visit. SidebarRowStateExtension renders the
 * classes instead, and the controller's pass then changes nothing.
 *
 * Asserted against the raw response, like SidebarSectionCollapseTest: the test
 * client runs no JavaScript, so a class seen here can only have come from the
 * server. The browser-level reading — `transitionrun` events and per-frame
 * computed colours through a load — was taken in Chromium when this changed;
 * it is not repeated here.
 *
 * The fix holds only while the server and the controller agree, on the rule
 * and on the classes, so both halves of that agreement are pinned below.
 */
final class SidebarRowStateTest extends WebTestCase
{
    private const string CONTROLLER = 'assets/controllers/ui/sidebar_controller.js';

    public function testTheOpenRowIsRenderedAndNoRowIsLeftForTheControllerToColour(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'e2e-admin@plmail.test']);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/mail/inbox');

        $rows = $crawler
            ->filter('#sidebar [data-ui--sidebar-target="link"], #sidebar-drawer-inner [data-ui--sidebar-target="link"]')
            ->each(static function (Crawler $link): array {
                $row = $link->closest('.nav-item') ?? $link;

                return [(string) $link->attr('href'), explode(' ', (string) $row->attr('class'))];
            });

        self::assertNotEmpty($rows, 'no sidebar rows rendered at all');

        $open = [];

        foreach ($rows as [$href, $classes]) {
            $isOpen   = [] === array_diff(explode(' ', SidebarRowStateExtension::OPEN), $classes);
            $isClosed = [] === array_diff(explode(' ', SidebarRowStateExtension::CLOSED), $classes);

            // Neither is the regression: a row the controller colours on
            // connect, from whatever the browser had already painted it as.
            self::assertTrue(
                $isOpen xor $isClosed,
                sprintf('the row for %s was rendered without its open/closed state: %s', $href, implode(' ', $classes)),
            );

            if ($isOpen) {
                $open[] = $href;
            }
        }

        // Once in the sidebar and once in the drawer, which is the same partial.
        self::assertSame(['/mail/inbox', '/mail/inbox'], $open);
    }

    /**
     * ui--sidebar#_matches, restated — the cases where getting it slightly
     * wrong would be invisible until a row faded.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function rows(): iterable
    {
        yield 'a parent label is open while a child is' => ['/mail/label/path/Work/Clients', '/mail/label/path/Work', true];
        yield 'a label sharing a prefix is not its child' => ['/mail/label/path/Workshop', '/mail/label/path/Work', false];
        yield 'an account folder, on that account' => ['/mail/label/5?account=3', '/mail/label/5?account=3', true];
        yield 'an account folder, on another account' => ['/mail/label/5?account=4', '/mail/label/5?account=3', false];
        yield 'a malformed account is closed, not an error' => ['/mail/label/5?account[]=3', '/mail/label/5?account=3', false];
    }

    #[DataProvider('rows')]
    public function testARowIsOpenExactlyWhereTheControllerWouldOpenIt(string $page, string $href, bool $open): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create($page));

        self::assertSame($open, new SidebarRowStateExtension($stack)->isOpen($href));
    }

    public function testTheServerAndTheControllerDrawARowTheSameWay(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::CONTROLLER);

        foreach (['ACTIVE_CLASSES' => SidebarRowStateExtension::OPEN, 'INACTIVE_CLASSES' => SidebarRowStateExtension::CLOSED] as $constant => $rendered) {
            self::assertSame(1, preg_match('/const ' . $constant . '\s*=\s*\[([^\]]*)\]/', $source, $match), sprintf('%s no longer declares %s', self::CONTROLLER, $constant));

            preg_match_all('/"([^"]+)"/', $match[1], $classes);

            self::assertSame(
                explode(' ', $rendered),
                $classes[1],
                sprintf(
                    '%s in %s and the classes SidebarRowStateExtension renders have drifted apart. '
                    . 'Every row the server draws would then be redrawn on connect — and fade.',
                    $constant,
                    self::CONTROLLER,
                ),
            );
        }
    }
}
