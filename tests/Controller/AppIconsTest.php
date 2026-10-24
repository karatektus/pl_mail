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
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The installed app's PNGs: drawn by the user's browser, kept per user, and
 * served to a manifest that is fetched without credentials (#141).
 *
 * The server never draws them (see AppIconStorage for why), so what is pinned
 * here is what it does with somebody else's drawing: it takes only PNGs of
 * exactly the right size, only for the uploader, and it never names an icon
 * that is not there.
 */
final class AppIconsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string USER_EMAIL  = 'e2e@plmail.test';
    private const string UPLOAD      = '/settings/appearance/app-icons';

    private KernelBrowser $client;
    private AppIconStorage $storage;
    private string $scratch;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->storage = static::getContainer()->get(AppIconStorage::class);
        $this->scratch = sys_get_temp_dir() . '/plmail-app-icons-' . bin2hex(random_bytes(4));
        mkdir($this->scratch);

        foreach ([self::ADMIN_EMAIL, self::USER_EMAIL] as $email) {
            $this->storage->deleteAllFor((string) $this->user($email)->id);
        }
    }

    protected function tearDown(): void
    {
        foreach ([self::ADMIN_EMAIL, self::USER_EMAIL] as $email) {
            $user = $this->user($email);
            $this->storage->deleteAllFor((string) $user->id);

            $user->appearance->logoMotif    = LogoMotif::DEFAULT;
            $user->appearance->logoOriginal = true;
            $user->appearance->logoStyle    = LogoStyle::DEFAULT;
            $user->appearance->logoLinked   = true;
        }

        static::getContainer()->get(EntityManagerInterface::class)->flush();

        array_map('unlink', glob($this->scratch . '/*') ?: []);
        rmdir($this->scratch);

        parent::tearDown();
    }

    public function testUploadedPngsAreWhatTheManifestsIconsServe(): void
    {
        $admin = $this->signIn(self::ADMIN_EMAIL);

        $this->upload('blue-horn', 'ocean', $this->pngs(0x1d4ed8));
        self::assertResponseIsSuccessful();
        self::assertSame(['choice' => 'blue-horn.ocean'], json_decode((string) $this->client->getResponse()->getContent(), true));

        $manifest = $this->manifestFor($admin, 'blue-horn', 'ocean');

        foreach ($manifest['icons'] as $icon) {
            $this->client->request('GET', $icon['src']);

            self::assertResponseIsSuccessful();
            self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));

            $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('immutable', $cache, 'the user\'s own, at the current drawing, is kept');
            self::assertStringNotContainsString('private', $cache);

            $image = imagecreatefromstring((string) $this->client->getResponse()->getContent());
            self::assertNotFalse($image);
            self::assertSame(0x1d4ed8, imagecolorat($image, 5, 5) & 0xFFFFFF, 'the uploaded picture, not the wax seal');
        }
    }

    /**
     * A choice the browser has not drawn yet (picked before this existed, or a
     * deploy changed the drawing): the wax seal of the same shape stands in, so
     * a manifest never names a 404, and `no-cache` so it does not outlive the
     * real one.
     */
    public function testAMissingIconIsTheWaxSealAndNotKept(): void
    {
        $admin = $this->signIn(self::ADMIN_EMAIL);
        $root  = (string) static::getContainer()->getParameter('kernel.project_dir') . '/public/';

        foreach (AppIconStorage::FALLBACK as $name => $file) {
            $this->client->request('GET', $this->iconUrl($admin, $name, 'blue-horn', 'ocean'));

            self::assertResponseIsSuccessful();
            self::assertSame((string) file_get_contents($root . $file), (string) $this->client->getResponse()->getContent(), $name);
            self::assertStringContainsString('no-cache', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }
    }

    /** @return iterable<string, array{\Closure(self): array<string, string>}> */
    public static function badUploads(): iterable
    {
        yield 'a size that is not the name\'s' => [static fn (self $t): array => ['tile-192' => $t->png(256, 0)] + $t->pngs(0)];
        yield 'not a PNG' => [static fn (self $t): array => ['tile-512' => $t->jpeg(512)] + $t->pngs(0)];
        yield 'not an image at all' => [static fn (self $t): array => ['bleed-180' => '<svg xmlns="http://www.w3.org/2000/svg"/>'] + $t->pngs(0)];
        yield 'too big' => [static fn (self $t): array => ['tile-512' => $t->png(512, 0) . str_repeat("\0", AppIconStorage::MAX_BYTES)] + $t->pngs(0)];
        yield 'one missing' => [static fn (self $t): array => array_diff_key($t->pngs(0), ['bleed-512' => true])];
    }

    /**
     * @param \Closure(self): array<string, string> $files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badUploads')]
    public function testOnlyPngsOfExactlyTheirSizeAreKept(\Closure $files): void
    {
        $admin = $this->signIn(self::ADMIN_EMAIL);

        $this->upload('blue-horn', 'ocean', $files($this));

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->storage->has((string) $admin->id, 'blue-horn.ocean', $this->version()), 'nothing half-stored');
    }

    public function testAChoiceTheRoutesCannotNameIsRefused(): void
    {
        $this->signIn(self::ADMIN_EMAIL);

        $this->upload('chartreuse', 'original', $this->pngs(0));
        self::assertResponseStatusCodeSame(422);

        $this->upload('pl', 'chartreuse-dreams', $this->pngs(0));
        self::assertResponseStatusCodeSame(422);
    }

    /** A page loaded before a deploy changed the drawing: its drawing is not this server's. */
    public function testADrawingFromAnotherVersionIsRefused(): void
    {
        $this->signIn(self::ADMIN_EMAIL);

        $this->upload('blue-horn', 'ocean', $this->pngs(0), version: '0123456789ab');

        self::assertResponseStatusCodeSame(409);
    }

    public function testAnUploadNeedsTheToken(): void
    {
        $this->signIn(self::ADMIN_EMAIL);

        $this->upload('blue-horn', 'ocean', $this->pngs(0), token: 'forged');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNobodySignedInCannotUpload(): void
    {
        $this->upload('blue-horn', 'ocean', $this->pngs(0), token: 'none');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** An upload is the uploader's: it changes nobody else's icons. */
    public function testAnUploadOnlyEverChangesTheUploadersIcons(): void
    {
        $other = $this->user(self::USER_EMAIL);
        $this->storage->store((string) $other->id, 'blue-horn.ocean', $this->version(), $this->pngs(0x00ff00));

        $this->signIn(self::ADMIN_EMAIL);
        $this->upload('blue-horn', 'ocean', $this->pngs(0xff0000));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->iconUrl($other, 'tile-192', 'blue-horn', 'ocean'));
        $image = imagecreatefromstring((string) $this->client->getResponse()->getContent());

        self::assertNotFalse($image);
        self::assertSame(0x00ff00, imagecolorat($image, 5, 5) & 0xFFFFFF);
        self::assertNotSame(
            $this->storage->keyFor((string) $other->id),
            $this->storage->keyFor((string) $this->user(self::ADMIN_EMAIL)->id),
        );
    }

    /** Only the current choice is ever linked, so only it is kept. */
    public function testANewChoiceReplacesTheOldOne(): void
    {
        $admin = $this->signIn(self::ADMIN_EMAIL);

        $this->upload('blue-horn', 'ocean', $this->pngs(0));
        $this->upload('pl', 'postal', $this->pngs(0));

        self::assertTrue($this->storage->has((string) $admin->id, 'pl.postal', $this->version()));
        self::assertFalse($this->storage->has((string) $admin->id, 'blue-horn.ocean', $this->version()));
    }

    /**
     * The page draws them only when they are missing: the layout carries the
     * element that does it until an upload for the current choice has landed.
     */
    public function testThePageAsksForTheIconsOnlyWhileTheyAreMissing(): void
    {
        $admin = $this->signIn(self::ADMIN_EMAIL);

        $admin->appearance->logoMotif    = LogoMotif::BlueHorn;
        $admin->appearance->logoOriginal = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $drawer = $this->client->request('GET', '/mail/inbox')->filter('[data-controller="core--app-icons"]');

        self::assertCount(1, $drawer);
        self::assertStringStartsWith('/branding/icon/blue-horn/original.svg?v=', (string) $drawer->attr('data-core--app-icons-tile-url-value'));
        self::assertStringStartsWith('/branding/bleed/blue-horn/original.svg?v=', (string) $drawer->attr('data-core--app-icons-bleed-url-value'));

        $this->upload('blue-horn', 'original', $this->pngs(0));

        self::assertCount(0, $this->client->request('GET', '/mail/inbox')->filter('[data-controller="core--app-icons"]'));
    }

    /**
     * The bleed is the tile with its edges let out: the 108 canvas, no rounded
     * clip, so iOS and a maskable icon can cut their own corners.
     */
    public function testTheBleedSvgIsTheWholeCanvasWithoutTheRoundedClip(): void
    {
        $this->client->request('GET', '/branding/bleed/wax-seal/original.svg');
        $bleed = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', '/branding/icon/wax-seal/original.svg');
        $tile = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('viewBox="0 0 108 108"', $bleed);
        self::assertStringNotContainsString('clip-path', $bleed);

        // The companion: the tile still is the rounded one.
        self::assertStringContainsString('viewBox="18 18 72 72"', $tile);
        self::assertStringContainsString('clip-path="url(#tile)"', $tile);
    }

    /** @return array<string, string> a valid set, every name at its size, filled with $rgb */
    public function pngs(int $rgb): array
    {
        $pngs = [];

        foreach (AppIconStorage::ICONS as $name => $size) {
            $pngs[$name] = $this->png($size, $rgb);
        }

        return $pngs;
    }

    public function png(int $size, int $rgb): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, $rgb);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function jpeg(int $size): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($size, $size));

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, string> $pngs name => bytes
     */
    private function upload(string $motif, string $paint, array $pngs, ?string $version = null, ?string $token = null): void
    {
        $files = [];

        foreach ($pngs as $name => $bytes) {
            $path = $this->scratch . '/' . $name . '-' . bin2hex(random_bytes(3)) . '.png';
            file_put_contents($path, $bytes);
            $files[$name] = new UploadedFile($path, $name . '.png', 'image/png', test: true);
        }

        if (null === $token) {
            // The layout's `ajax` token, as the page sends it.
            $token = (string) $this->client->request('GET', '/settings?section=appearance')
                ->filter('meta[name="csrf-token"]')->attr('content');
        }

        $this->client->request(
            'POST',
            self::UPLOAD,
            ['motif' => $motif, 'paint' => $paint, 'version' => $version ?? $this->version()],
            $files,
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
    }

    /** @return array{icons: list<array{src: string}>} */
    private function manifestFor(User $user, string $motif, string $paint): array
    {
        $this->client->request('GET', sprintf(
            '/branding/manifest/%s/%s.webmanifest?u=%s',
            $motif,
            $paint,
            $this->storage->keyFor((string) $user->id),
        ));

        self::assertResponseIsSuccessful();

        /** @var array{icons: list<array{src: string}>} */
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function iconUrl(User $user, string $name, string $motif, string $paint): string
    {
        return static::getContainer()->get(LogoIcons::class)->appIconUrl(
            $name,
            $this->storage->keyFor((string) $user->id),
            LogoMotif::from($motif),
            LogoStyle::tryFrom($paint),
        );
    }

    private function version(): string
    {
        return static::getContainer()->get(LogoIcons::class)->version();
    }

    private function signIn(string $email): User
    {
        $user = $this->user($email);
        $this->client->loginUser($user);

        return $user;
    }

    private function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` and `app:test:seed-user --admin` first');
        }

        return $user;
    }
}
