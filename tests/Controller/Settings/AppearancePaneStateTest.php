<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The width of the appearance preview pane: who may set it, and to what.
 *
 * Two things are pinned here and they are separate promises.
 *
 * A token is required. The appearance endpoints beside this one have never
 * carried one, on the reasoning that a forged request against them can only
 * repaint the victim's own settings — but that is an argument for leaving old
 * endpoints alone, not for minting new ones without a check, and
 * StateChangingPostsNeedCsrfTest exists because that reasoning ran out once
 * already.
 *
 * And the clamp is the SERVER's. ui--split bounds the drag, which is a
 * convenience for the person dragging; a stored 40000 would wedge the settings
 * page at a width with no control left on screen to fix it from.
 */
final class AppearancePaneStateTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/settings/appearance/pane-state';

    public function testAWidthWithoutATokenIsRefusedAndWritesNothing(): void
    {
        [$client, $user] = $this->signedIn();

        $before = $user->appearancePreviewWidth;

        $client->request('POST', self::PATH, ['width' => '420']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(
            $before,
            $this->reread()->appearancePreviewWidth,
            'a tokenless POST moved the pane',
        );
    }

    public function testAForgedTokenIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => '420', '_token' => 'nonsense']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAWidthWithATokenIsRemembered(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, [
            'width'  => '420',
            '_token' => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(420, $this->reread()->appearancePreviewWidth);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'far past the maximum' => ['40000', User::APPEARANCE_PREVIEW_MAX_WIDTH];
        yield 'below the minimum'    => ['10', User::APPEARANCE_PREVIEW_MIN_WIDTH];
        yield 'negative'             => ['-500', User::APPEARANCE_PREVIEW_MIN_WIDTH];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRange')]
    public function testTheWidthIsClampedServerSide(string $posted, int $expected): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, [
            'width'  => $posted,
            '_token' => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($expected, $this->reread()->appearancePreviewWidth);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function storedOutOfRange(): iterable
    {
        // The case the widening actually creates. Nobody's stored width becomes
        // invalid by raising a maximum, but a bag is a JSON column with no
        // constraint behind it: a value can predate a narrower range, arrive
        // from an import, or be written by hand.
        yield 'stored past the maximum'  => [40_000, User::APPEARANCE_PREVIEW_MAX_WIDTH];
        yield 'stored below the minimum' => [10, User::APPEARANCE_PREVIEW_MIN_WIDTH];
        yield 'stored negative'          => [-500, User::APPEARANCE_PREVIEW_MIN_WIDTH];
        // Inside the new range and outside the old one: this is the width the
        // user gets to KEEP, which is the other half of the promise.
        yield 'past the OLD maximum'     => [780, 780];
    }

    /**
     * A width already in the bag, never posted through the endpoint.
     *
     * The endpoint's clamp above only covers what the endpoint writes. This is
     * the reading side, and it is the one that decides what a page RENDERS —
     * the width is served into the first paint from this property, so a stored
     * 40000 that survived the getter would put the preview off the screen with
     * no control left on it to drag back.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storedOutOfRange')]
    public function testAStoredWidthIsClampedWhenItIsRead(int $stored, int $expected): void
    {
        [$client, $user] = $this->signedIn();

        $user->setSetting(User::SETTING_APPEARANCE_PREVIEW_WIDTH, $stored);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame($expected, $this->reread()->appearancePreviewWidth);

        // …and that is the number the panel is rendered with, rather than the
        // stored one reaching the stylesheet by another route.
        $crawler = $client->request('GET', '/settings?section=appearance');

        self::assertStringContainsString(
            "--appearance-preview-width: {$expected}px",
            (string) $crawler->filter('[data-controller="ui--split"]')->last()->attr('style'),
        );
    }

    /** Signed out, there is nothing to remember and nothing to attack. */
    /**
     * The preview is a working miniature, not a still.
     *
     * All of it is static — no query, no `messages` variable, no route — and
     * that is the one rule the preview file states about itself. What these
     * pin is that the moving parts are actually present and wired, because
     * every one of them is markup a refactor can quietly drop: the rows are
     * buttons that swap the sample message, the reading half has a message per
     * row, and the details block and the composer are both there and closed.
     *
     * The list is EIGHT rows because the setting somebody is watching is
     * density, and density is a property of a list long enough to have a
     * rhythm. Three rows left most of a full-height card empty.
     */
    public function testThePreviewOffersEveryPartItClaimsTo(): void
    {
        [$client] = $this->signedIn();
        $crawler  = $client->request('GET', '/settings?section=appearance');

        self::assertResponseIsSuccessful();

        self::assertCount(8, $crawler->filter('[data-preview-open]'), 'the list is long enough to show a rhythm');
        self::assertCount(8, $crawler->filter('[data-preview-message]'), 'one sample message per row');

        // Exactly one open at first paint, and it is the first row.
        self::assertCount(
            7,
            $crawler->filter('[data-preview-message].hidden'),
            'one message is shown and the rest are not',
        );

        self::assertCount(8, $crawler->filter('[data-preview-details]'), 'each message carries its header block');
        self::assertCount(1, $crawler->filter('[data-preview-compose]'), 'and the card carries one composer');

        // Closed to begin with — both are things you open, and a preview that
        // started with everything open would be a picture of a mess.
        self::assertCount(8, $crawler->filter('[data-preview-details].hidden'));
        self::assertCount(1, $crawler->filter('[data-preview-compose].hidden'));
    }

    public function testItIsBehindTheLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', self::PATH, ['width' => '420']);

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 401, 403],
            'the endpoint answered an anonymous POST',
        );
    }

    protected function tearDown(): void
    {
        // The width is a per-user preference with no fixture of its own, so put
        // it back rather than leaving whatever the last case wrote for the next
        // suite to trip over.
        if (null !== $user = $this->find()) {
            $user->setSetting(
                User::SETTING_APPEARANCE_PREVIEW_WIDTH,
                User::APPEARANCE_PREVIEW_DEFAULT_WIDTH,
            );
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    /**
     * A token minted for this session, read off a page that renders the panel —
     * which is also the only place the real one comes from.
     */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings?section=appearance');

        return (string) $crawler
            ->filter('[data-ui--split-token-value]')
            ->last()
            ->attr('data-ui--split-token-value');
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
