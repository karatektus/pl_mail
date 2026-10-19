<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Helper\AppIconStorage;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Appearance\LogoIcons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The installed app wears the icon the person picked (#141).
 *
 * The tab icon followed the choice (BrandingFaviconTest) while the install
 * icon stayed the wax seal: a PWA's icon comes from its manifest, and the
 * manifest was one static file. It is a route now, with the choice in the URL
 * like every other picture of the logo, and the route draws what the URL names
 * and never what the session holds: a manifest is fetched without
 * credentials, so a session could not speak for it even if it were allowed to.
 * The user's key in the URL says whose PNGs it names (AppIconStorage).
 *
 * From #92 by @Flouss, adapted to PNGs the browser draws.
 */
final class BrandingManifestTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    protected function tearDown(): void
    {
        if (null !== $user = $this->find()) {
            $user->appearance->logoMotif    = LogoMotif::DEFAULT;
            $user->appearance->logoOriginal = true;
            $user->appearance->logoStyle    = LogoStyle::DEFAULT;
            $user->appearance->logoLinked   = true;
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testAnAnonymousPageLinksTheDefaultManifestWithoutAKey(): void
    {
        $client = static::createClient();

        $href = $this->manifestLink($client, '/login');

        // Hand-spelled: the default is the wax seal in its own design, and the
        // route's path is `<motif>/<paint>`.
        self::assertStringStartsWith('/branding/manifest/wax-seal/original.webmanifest?v=', $href);
        self::assertStringNotContainsString('u=', $href);
    }

    public function testAPageLinksTheManifestOfTheUsersChoiceAndKey(): void
    {
        [$client, $user] = $this->signedIn();

        $user->appearance->logoMotif    = LogoMotif::BlueHorn;
        $user->appearance->logoOriginal = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $href = $this->manifestLink($client, '/mail/inbox');

        self::assertStringStartsWith('/branding/manifest/blue-horn/original.webmanifest?u=' . $this->key($user) . '&v=', $href);

        // The paint is part of the choice, so a recolour is a new URL, which is
        // what makes the browser look again.
        $user->appearance->logoMotif  = LogoMotif::Pl;
        $user->appearance->logoStyle  = LogoStyle::Postal;
        $user->appearance->logoLinked = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertStringStartsWith('/branding/manifest/pl/postal.webmanifest?u=', $this->manifestLink($client, '/mail/inbox'));
    }

    /**
     * The point of the route: the installed app's icons are the choice's, as
     * the user's PNGs. The rounded tile at the two sizes a manifest is
     * expected to carry, and the ground-to-the-edge form as the maskable icon.
     * One picture per purpose and size: a static candidate beside them is how
     * the wax seal outvoted the tab icon in Firefox.
     */
    public function testWithAKeyTheInstallIconsAreTheUsersPngsOfTheChoice(): void
    {
        $client = static::createClient();
        $key    = str_repeat('ab', 10);

        $manifest = $this->manifest($client, '/branding/manifest/blue-horn/ocean.webmanifest?u=' . $key);

        // The presence companion: no icons at all would pass the loop below.
        self::assertCount(3, $manifest['icons'], 'a tile at 192 and 512, and the maskable 512');

        $expected = [
            ['tile-192', '192x192', 'any'],
            ['tile-512', '512x512', 'any'],
            ['bleed-512', '512x512', 'maskable'],
        ];

        foreach ($expected as $i => [$name, $sizes, $purpose]) {
            $icon = $manifest['icons'][$i];

            self::assertSame('image/png', $icon['type']);
            self::assertSame($sizes, $icon['sizes']);
            self::assertSame($purpose, $icon['purpose']);
            self::assertStringStartsWith(sprintf('/branding/app-icon/%s/%s/blue-horn/ocean.png?v=', $key, $name), $icon['src']);
        }
    }

    /** Nobody signed in: the static file's own wax seal, as it always was. */
    public function testWithoutAKeyTheInstallIconsAreTheStaticFilesOwn(): void
    {
        $client = static::createClient();

        $manifest = $this->manifest($client, '/branding/manifest/wax-seal/original.webmanifest');

        self::assertSame($this->staticManifest()['icons'], $manifest['icons']);
    }

    /**
     * iOS ignores the manifest for its home-screen icon and reads the page's
     * apple-touch-icon, which has to be a PNG. iOS rounds the corners itself and
     * paints a transparent one black, hence the form that fills the square.
     */
    public function testTheHomeScreenIconOfIosIsTheUsersBleedPng(): void
    {
        [$client, $user] = $this->signedIn();

        $user->appearance->logoMotif    = LogoMotif::BlueHorn;
        $user->appearance->logoOriginal = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $link = $client->request('GET', '/mail/inbox')->filter('link[rel="apple-touch-icon"]');

        self::assertCount(1, $link);
        self::assertStringStartsWith(
            sprintf('/branding/app-icon/%s/bleed-180/blue-horn/original.png?v=', $this->key($user)),
            (string) $link->attr('href'),
        );
    }

    public function testAnAnonymousPageLinksTheStaticHomeScreenIcon(): void
    {
        $link = static::createClient()->request('GET', '/login')->filter('link[rel="apple-touch-icon"]');

        self::assertCount(1, $link);
        self::assertSame('/icons/apple-touch-icon.png', $link->attr('href'));
    }

    /** Everything but the icons is the static manifest's, so the two cannot drift. */
    public function testTheRestOfTheManifestIsTheStaticFilesOwn(): void
    {
        $client = static::createClient();

        $static  = $this->staticManifest();
        $dynamic = $this->manifest($client, '/branding/manifest/wax-seal/original.webmanifest?u=' . str_repeat('ab', 10));

        unset($static['icons'], $dynamic['icons']);

        // Presence companion: an empty static file would equal an empty route.
        self::assertSame('/mail/inbox', $static['start_url']);
        self::assertSame($static, $dynamic);
    }

    /**
     * Drawn from the URL, not the session: the manifest is fetched without
     * credentials, and the appearance pane re-points it before the save lands.
     */
    public function testTheManifestNamesWhatItsUrlNamesNotWhatTheSessionHolds(): void
    {
        [$client, $user] = $this->signedIn();

        $user->appearance->logoMotif  = LogoMotif::Pl;
        $user->appearance->logoStyle  = LogoStyle::Postal;
        $user->appearance->logoLinked = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $manifest = $this->manifest($client, '/branding/manifest/blue-horn/original.webmanifest?u=' . $this->key($user));

        self::assertCount(3, $manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            self::assertStringContainsString('/blue-horn/original.png', $icon['src']);
        }
    }

    /**
     * The manifest route promises its URL is immutable, so a change to the file
     * it serves has to move the URL, or every browser keeps the old app name or
     * colour for a year.
     */
    public function testEditingTheStaticManifestMovesTheVersion(): void
    {
        self::bootKernel();

        $dir = sys_get_temp_dir() . '/plmail-manifest-' . bin2hex(random_bytes(4));
        mkdir($dir . '/public', recursive: true);

        $versionWith = static function (string $manifest) use ($dir): string {
            file_put_contents($dir . '/public/manifest.webmanifest', $manifest);

            // A fresh instance each time: the version is memoised per object.
            return new LogoIcons(static::getContainer()->get(UrlGeneratorInterface::class), $dir)->version();
        };

        try {
            $first = $versionWith('{"name":"plMail"}');

            // The stable half, so "always different" would not pass.
            self::assertSame($first, $versionWith('{"name":"plMail"}'));
            self::assertNotSame($first, $versionWith('{"name":"plMail2"}'));
        } finally {
            @unlink($dir . '/public/manifest.webmanifest');
            @rmdir($dir . '/public');
            @rmdir($dir);
        }
    }

    public function testTheManifestIsPublicAndTheRightType(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/manifest/wax-seal/original.webmanifest');

        self::assertResponseIsSuccessful();
        self::assertSame('application/manifest+json', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringNotContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertTrue($client->getResponse()->headers->has('ETag'));
    }

    /** Two users' manifests for one choice are two documents, so one ETag must not answer for both. */
    public function testTheKeyIsPartOfTheManifestsEtag(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/manifest/wax-seal/original.webmanifest?u=' . str_repeat('ab', 10));
        $first = $client->getResponse()->headers->get('ETag');

        $client->request('GET', '/branding/manifest/wax-seal/original.webmanifest?u=' . str_repeat('cd', 10));

        self::assertNotSame($first, $client->getResponse()->headers->get('ETag'));
    }

    public function testAnUnknownChoiceOrAMalformedKeyHasNoManifest(): void
    {
        $client = static::createClient();

        foreach ([
            '/branding/manifest/chartreuse/original.webmanifest',
            '/branding/manifest/pl/chartreuse-dreams.webmanifest',
            '/branding/manifest/pl/original.webmanifest?u=../../etc',
            '/branding/manifest/pl/original.webmanifest?u=ABABABABABABABABABAB',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    /** The manifest a page links, as the href the browser would fetch. */
    private function manifestLink(KernelBrowser $client, string $page): string
    {
        $link = $client->request('GET', $page)->filter('link[rel="manifest"]');

        // Presence companion: a page with no manifest link at all would pass
        // any "does not link the static file" check.
        self::assertCount(1, $link, 'exactly one manifest is linked');

        return (string) $link->attr('href');
    }

    /** @return array{icons: list<array{src: string, sizes: string, type: string, purpose: string}>, start_url: string} */
    private function manifest(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        /** @var array{icons: list<array{src: string, sizes: string, type: string, purpose: string}>, start_url: string} */
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function staticManifest(): array
    {
        /** @var array<string, mixed> */
        return json_decode(
            (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/public/manifest.webmanifest'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function key(User $user): string
    {
        return static::getContainer()->get(AppIconStorage::class)->keyFor((string) $user->id);
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
