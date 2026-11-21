<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sidebar's width and its peek (#699): who may change them, to what, and
 * whether every page with a sidebar is drawn from them.
 *
 * The same promises as ReadingPaneStateTest, for the same reasons: a token is
 * required; the clamp is the server's, read straight out of the bag because
 * the getter clamps too and would hide a missing one; the two fields are
 * independent, because the drag posts only the width and the Settings switch
 * only the peek; and the first paint is the server's, which no browser test
 * can tell apart from a correction a moment later.
 */
final class SidebarStateTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/mail/sidebar/state';

    public function testAWidthWithoutATokenIsRefusedAndWritesNothing(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => '300']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull($this->reread()->getSetting(User::SETTING_SIDEBAR_WIDTH));
    }

    public function testAForgedTokenIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => '300', '_token' => 'nonsense']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(User::SIDEBAR_DEFAULT_WIDTH, $this->reread()->sidebarWidth);
    }

    public function testAWidthIsRemembered(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => '300', '_token' => $this->token($client)]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(300, $this->reread()->getSetting(User::SETTING_SIDEBAR_WIDTH));
        self::assertSame(
            ['width' => 300, 'peek' => true],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function outOfRange(): iterable
    {
        yield 'far past the maximum' => ['40000', User::SIDEBAR_MAX_WIDTH];
        yield 'one past the maximum' => ['481', User::SIDEBAR_MAX_WIDTH];
        yield 'below the minimum'    => ['100', User::SIDEBAR_MIN_WIDTH];
        yield 'negative'             => ['-50', User::SIDEBAR_MIN_WIDTH];
    }

    #[DataProvider('outOfRange')]
    public function testTheWidthIsClampedServerSide(string $posted, int $expected): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => $posted, '_token' => $this->token($client)]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(
            $expected,
            $this->reread()->getSetting(User::SETTING_SIDEBAR_WIDTH),
            'the bag holds what was posted, not what the endpoint made of it',
        );
    }

    public function testThePeekIsRememberedBothWaysAndRedrawsTheSidebar(): void
    {
        [$client] = $this->signedIn();
        $token    = $this->token($client);

        // On is what a fresh user reads as, so off comes first and only the
        // way back proves a stored Off was replaced.
        $client->request('POST', self::PATH, ['key' => 'peek', 'enabled' => '0', '_token' => $token]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertFalse($this->reread()->sidebarPeek);

        // The answer is the sidebar, so the one beside the switch changes at
        // once — and it carries the setting it was just given.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('target="sidebar"', $body);
        self::assertStringNotContainsString('data-sidebar-peek', $body);

        $client->request('POST', self::PATH, ['key' => 'peek', 'enabled' => '1', '_token' => $token]);

        self::assertTrue($this->reread()->sidebarPeek);
        self::assertStringContainsString('data-sidebar-peek', (string) $client->getResponse()->getContent());
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['key' => 'width', 'enabled' => '1', '_token' => $this->token($client)]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testPostingThePeekLeavesTheWidthAlone(): void
    {
        [$client, $user] = $this->signedIn();

        $user->sidebarWidth = 320;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::PATH, ['key' => 'peek', 'enabled' => '1', '_token' => $this->token($client)]);

        self::assertSame(320, $this->reread()->sidebarWidth);
    }

    public function testPostingTheWidthLeavesThePeekAlone(): void
    {
        [$client, $user] = $this->signedIn();

        $user->sidebarPeek = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::PATH, ['width' => '260', '_token' => $this->token($client)]);

        self::assertFalse($this->reread()->sidebarPeek);
    }

    public function testItIsBehindTheLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', self::PATH, ['width' => '300']);

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    // ── the first paint ───────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function pagesWithASidebar(): iterable
    {
        yield 'mailbox'  => ['/mail/inbox'];
        yield 'calendar' => ['/calendar'];
        yield 'settings' => ['/settings/layout'];
        yield 'admin'    => ['/admin'];
    }

    /**
     * The same stored width, and one handle, on all four pages: a sidebar that
     * is 300px on the mail and 224px in Settings reads as the page moving.
     */
    #[DataProvider('pagesWithASidebar')]
    public function testEveryPageWithASidebarIsDrawnAtTheStoredWidth(string $path): void
    {
        [$client, $user] = $this->signedIn();

        $user->sidebarWidth = 310;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();

        $boundary = $crawler->filter('.sidebar-boundary[data-controller="ui--sidebar-split"]');

        self::assertCount(1, $boundary);
        self::assertStringContainsString('--sidebar-width: 310px', (string) $boundary->attr('style'));
        self::assertCount(1, $boundary->filter('[data-sidebar-handle][role="separator"]'));
        self::assertCount(1, $boundary->filter('.sidebar-pane > #sidebar'));

        // What the clamp measures against has to exist on the page.
        $room = (string) $boundary->attr('data-ui--sidebar-split-main-selector-value');
        self::assertGreaterThan(0, $crawler->filter($room)->count(), "nothing on {$path} answers to {$room}");
    }

    public function testThePeekIsOnByDefaultOnlyOnTheInlineSidebarAndCanBeSwitchedOff(): void
    {
        [$client, $user] = $this->signedIn();

        $crawler = $client->request('GET', '/mail/inbox');
        self::assertCount(1, $crawler->filter('#sidebar[data-sidebar-peek]'));
        self::assertCount(0, $crawler->filter('#sidebar-drawer-inner[data-sidebar-peek]'));

        $user->sidebarPeek = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/mail/inbox');
        self::assertCount(0, $crawler->filter('[data-sidebar-peek]'));
    }

    public function testTheSwitchIsInTheSidebarCardWithTheStoredValueChosen(): void
    {
        [$client, $user] = $this->signedIn();

        $user->sidebarPeek = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/settings/layout');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#card-sidebar input[name="sidebar-peek"][value="0"][checked]'));
        self::assertCount(0, $crawler->filter('#card-sidebar input[name="sidebar-peek"][value="1"][checked]'));
    }

    protected function tearDown(): void
    {
        if (null !== $user = $this->find()) {
            // Per-user preferences with no fixture of their own; null reads as
            // the default for both.
            $user->setSetting(User::SETTING_SIDEBAR_WIDTH, null);
            $user->setSetting(User::SETTING_SIDEBAR_PEEK, null);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    /** The token the page renders onto the boundary, which is where the real one comes from. */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/mail/inbox');

        return (string) $crawler
            ->filter('[data-ui--sidebar-split-token-value]')
            ->first()
            ->attr('data-ui--sidebar-split-token-value');
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
