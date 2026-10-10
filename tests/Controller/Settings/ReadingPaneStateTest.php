<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\ReadingPaneMode;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The reading pane's two settings: who may change them, to what, and whether
 * the page is drawn from them.
 *
 * Three promises, kept apart because each fails differently.
 *
 * A token is required, for the reason AppearancePaneStateTest gives about its
 * own endpoint: a forged request can only rearrange the victim's own mailbox,
 * which is an argument for leaving old endpoints alone and not for minting new
 * ones without a check.
 *
 * The clamp and the mode check are the SERVER's. The drag handle bounds what it
 * sends, but a stored share of 4000 would draw a list nobody can see, and a
 * request with a mode this build has never heard of must not switch the pane
 * off for somebody who had chosen it.
 *
 * And the two fields are independent. The Settings control posts only the mode,
 * the drag posts only the width; a handler that wrote both, defaulting
 * whichever was missing, would reset the share every time somebody switched
 * the pane off and on.
 *
 * The first paint is pinned here too, because the attribute the layout is
 * drawn from is rendered by the server and is not something a browser test can
 * tell apart from a client-side correction a moment later.
 */
final class ReadingPaneStateTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/settings/appearance/reading-pane';

    public function testAModeWithoutATokenIsRefusedAndWritesNothing(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['mode' => 'right']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(
            ReadingPaneMode::Off,
            $this->reread()->readingPaneMode,
            'a tokenless POST switched the pane on',
        );
    }

    public function testAForgedTokenIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['mode' => 'right', '_token' => 'nonsense']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(ReadingPaneMode::Off, $this->reread()->readingPaneMode);
    }

    public function testTheModeIsRememberedBothWays(): void
    {
        [$client] = $this->signedIn();
        $token    = $this->token($client);

        $client->request('POST', self::PATH, ['mode' => 'right', '_token' => $token]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(ReadingPaneMode::Right, $this->reread()->readingPaneMode);

        // The presence companion: Off is what a fresh user reads as, so a
        // handler that ignored the field would look right on the first request
        // and only the second proves a stored Right was actually replaced.
        $client->request('POST', self::PATH, ['mode' => 'off', '_token' => $token]);

        self::assertSame(ReadingPaneMode::Off, $this->reread()->readingPaneMode);
    }

    public function testAModeThisBuildDoesNotKnowKeepsTheOneAlreadyChosen(): void
    {
        [$client, $user] = $this->signedIn();

        $user->readingPaneMode = ReadingPaneMode::Right;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::PATH, ['mode' => 'bottom', '_token' => $this->token($client)]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(ReadingPaneMode::Right, $this->reread()->readingPaneMode);
    }

    public function testAWidthIsRemembered(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => '40', '_token' => $this->token($client)]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(40, $this->reread()->readingPaneWidthPct);
        self::assertSame(40, $this->reread()->getSetting(User::SETTING_READING_PANE_WIDTH), 'stored, not only read back');
    }

    /** @return iterable<string, array{string, int}> */
    public static function outOfRange(): iterable
    {
        yield 'far past the maximum' => ['4000', User::READING_PANE_MAX_PCT];
        yield 'one past the maximum' => ['76', User::READING_PANE_MAX_PCT];
        yield 'below the minimum'    => ['10', User::READING_PANE_MIN_PCT];
        yield 'negative'             => ['-50', User::READING_PANE_MIN_PCT];
    }

    /**
     * What the ENDPOINT stores, read straight out of the bag.
     *
     * Not through $readingPaneWidthPct: that getter clamps on its own, so a
     * test reading the width back through it passes whether or not the
     * endpoint clamped anything — the two guards hide each other. Removing
     * either clamp from the endpoint survived the first version of this test.
     * The raw value is the only place the endpoint's own clamp is visible.
     */
    #[DataProvider('outOfRange')]
    public function testTheWidthIsClampedServerSide(string $posted, int $expected): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => $posted, '_token' => $this->token($client)]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(
            $expected,
            $this->reread()->getSetting(User::SETTING_READING_PANE_WIDTH),
            'the bag holds what was posted, not what the endpoint made of it',
        );
    }

    public function testPostingTheModeLeavesTheWidthAlone(): void
    {
        [$client, $user] = $this->signedIn();

        $user->setSetting(User::SETTING_READING_PANE_WIDTH, 40);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::PATH, ['mode' => 'right', '_token' => $this->token($client)]);

        self::assertSame(40, $this->reread()->readingPaneWidthPct);
    }

    public function testPostingTheWidthLeavesTheModeAlone(): void
    {
        [$client, $user] = $this->signedIn();

        $user->readingPaneMode = ReadingPaneMode::Right;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::PATH, ['width' => '60', '_token' => $this->token($client)]);

        self::assertSame(ReadingPaneMode::Right, $this->reread()->readingPaneMode);
    }

    public function testTheAnswerSaysWhatIsNowStored(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, [
            'mode'   => 'right',
            'width'  => '4000',
            '_token' => $this->token($client),
        ]);

        self::assertSame(
            ['mode' => 'right', 'width' => User::READING_PANE_MAX_PCT],
            json_decode((string) $client->getResponse()->getContent(), true),
            'the answer carries the clamped figure, which is what the handle settles on',
        );
    }

    public function testItIsBehindTheLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', self::PATH, ['mode' => 'right']);

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 401, 403],
            'the endpoint answered an anonymous POST',
        );
    }

    // ── the first paint ───────────────────────────────────────────────────

    public function testTheMailboxIsDrawnOnePanedForSomebodyWhoHasNotChosen(): void
    {
        [$client] = $this->signedIn();

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.main-pane[data-reading-pane="off"]'));
        self::assertCount(0, $crawler->filter('.main-pane[data-reading-pane="right"]'));
    }

    public function testTheMailboxIsDrawnFromTheStoredModeAndShare(): void
    {
        [$client, $user] = $this->signedIn();

        $user->readingPaneMode = ReadingPaneMode::Right;
        $user->setSetting(User::SETTING_READING_PANE_WIDTH, 40);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $card = $crawler->filter('.main-pane[data-reading-pane="right"]');

        self::assertCount(1, $card, 'the stored mode is in the HTML, not applied by a script afterwards');
        self::assertStringContainsString('--reading-pane-pct: 40', (string) $card->attr('style'));
    }

    public function testTheSettingsPageOffersBothPositionsWithTheStoredOneChosen(): void
    {
        [$client, $user] = $this->signedIn();

        $user->readingPaneMode = ReadingPaneMode::Right;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/settings?section=reading-pane');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('input[name="readingPane"]'));
        self::assertCount(1, $crawler->filter('input[name="readingPane"][value="right"][checked]'));
        self::assertCount(0, $crawler->filter('input[name="readingPane"][value="off"][checked]'));
    }

    /**
     * The control began as the last one on Appearance, the one thing on that
     * page that was not part of the theme, and the shortcuts switch as the
     * fifth card on General. Both have a page under Features now, and the
     * places they came from must not still be drawing a second copy: two
     * controls for one stored value is how one of them ends up stale.
     */
    public function testItHasAPageOfItsOwnAndTheShortcutsDoTooRatherThanACornerOfAnotherPage(): void
    {
        [$client] = $this->signedIn();

        $appearance = $client->request('GET', '/settings?section=appearance');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $appearance->filter('input[name="readingPane"]'));
        self::assertCount(1, $appearance->filter('a[href$="section=reading-pane"]'), 'the navigation offers the page');
        self::assertCount(1, $appearance->filter('a[href$="section=shortcuts"]'));

        $general = $client->request('GET', '/settings?section=general');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $general->filter('form[action$="/settings/shortcuts"]'));

        // Presence: the form is where the navigation says it is.
        $shortcuts = $client->request('GET', '/settings?section=shortcuts');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $shortcuts->filter('form[action$="/settings/shortcuts"]'));
        self::assertCount(1, $shortcuts->filter('a[href$="section=shortcuts"][aria-current="page"]'));
    }

    protected function tearDown(): void
    {
        // Per-user preferences with no fixture of their own: put them back
        // rather than leaving the last case's choice for the next suite.
        if (null !== $user = $this->find()) {
            $user->readingPaneMode = ReadingPaneMode::Off;
            $user->setSetting(User::SETTING_READING_PANE_WIDTH, User::READING_PANE_DEFAULT_PCT);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    /**
     * A token minted for this session, read off the page that renders the
     * control — which is also the only place the real one comes from.
     */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings?section=reading-pane');

        return (string) $crawler
            ->filter('[data-settings--reading-pane-token-value]')
            ->first()
            ->attr('data-settings--reading-pane-token-value');
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
