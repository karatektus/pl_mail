<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Appearance\LogoIcons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The installed app wears the icon the person picked.
 *
 * The tab icon followed the choice (BrandingFaviconTest) while the install
 * icon stayed the wax seal: a PWA's icon comes from its manifest, and the
 * manifest was one static file. It is a route now, with the choice in the URL
 * like every other picture of the logo, and the route draws what the URL names
 * and never what the session holds — a manifest is fetched without
 * credentials, so a session could not speak for it even if it were allowed to.
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

    public function testAnAnonymousPageLinksTheDefaultManifest(): void
    {
        $client = static::createClient();

        $href = $this->manifestLink($client, '/login');

        // Hand-spelled: the default is the wax seal in its own design, and the
        // route's path is `<motif>/<paint>`.
        self::assertStringStartsWith('/branding/manifest/wax-seal/original.webmanifest?v=', $href);
    }

    public function testAPageLinksTheManifestOfTheUsersChoice(): void
    {
        [$client, $user] = $this->signedIn();

        $user->appearance->logoMotif    = LogoMotif::BlueHorn;
        $user->appearance->logoOriginal = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $href = $this->manifestLink($client, '/mail/inbox');

        self::assertStringStartsWith('/branding/manifest/blue-horn/original.webmanifest?v=', $href);

        // The paint is part of the choice, so a recolour is a new URL, which is
        // what makes the browser look again.
        $user->appearance->logoMotif    = LogoMotif::Pl;
        $user->appearance->logoStyle    = LogoStyle::Postal;
        $user->appearance->logoLinked   = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertStringStartsWith(
            '/branding/manifest/pl/postal.webmanifest?v=',
            $this->manifestLink($client, '/mail/inbox'),
        );
    }

    /**
     * The point of the route. The install icon is the choice's tile, and the
     * only icon: a second, static candidate is how the wax seal outvoted the
     * tab icon in Firefox, and a launcher picks among a manifest's icons the
     * same way.
     */
    public function testTheInstallIconIsTheChoicesTile(): void
    {
        $client = static::createClient();

        $manifest = $this->manifest($client, '/branding/manifest/blue-horn/original.webmanifest');

        // The presence companion: no icons at all would pass the check below.
        self::assertCount(1, $manifest['icons'], 'exactly one install icon');

        $icon = $manifest['icons'][0];

        self::assertSame('image/svg+xml', $icon['type']);
        self::assertSame('any', $icon['sizes'], 'an SVG has no size of its own');
        self::assertSame('any', $icon['purpose']);
        self::assertStringStartsWith('/branding/icon/blue-horn/original.svg?v=', $icon['src']);

        // And what that URL serves is a tile in the horn's own colours, not the
        // wax seal's.
        $client->request('GET', $icon['src']);

        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $client->getResponse()->headers->get('Content-Type'));

        $svg = (string) $client->getResponse()->getContent();

        foreach (LogoMotif::BlueHorn->paints(null) as $hex) {
            if (is_string($hex)) {
                self::assertStringContainsString($hex, $svg);
            }
        }
    }

    public function testThePaintIsInTheInstallIconsUrl(): void
    {
        $client = static::createClient();

        $manifest = $this->manifest($client, '/branding/manifest/pl/postal.webmanifest');

        self::assertStringStartsWith('/branding/icon/pl/postal.svg?v=', $manifest['icons'][0]['src']);
    }

    /** Everything but the icons is the static manifest's, so the two cannot drift. */
    public function testTheRestOfTheManifestIsTheStaticFilesOwn(): void
    {
        $client = static::createClient();

        $static = json_decode(
            (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/public/manifest.webmanifest'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $dynamic = $this->manifest($client, '/branding/manifest/wax-seal/original.webmanifest');

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

        $manifest = $this->manifest($client, '/branding/manifest/blue-horn/original.webmanifest');

        self::assertStringContainsString('blue-horn', $manifest['icons'][0]['src']);
        self::assertStringNotContainsString('postal', $manifest['icons'][0]['src']);
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

    public function testAnUnknownChoiceHasNoManifest(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/manifest/chartreuse/original.webmanifest');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/branding/manifest/pl/chartreuse-dreams.webmanifest');
        self::assertResponseStatusCodeSame(404);
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
