<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Helper\AppIconStorage;
use App\Domain\Helper\CustomLogoStorage;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Appearance\LogoIcons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A logo of the user's own, uploaded as an SVG (#48).
 *
 * The whole round trip, because each step reads what the one before wrote:
 * the upload is sanitised and worn, every form of it is served from a public
 * URL under a policy that runs nothing, the layout links those forms, the
 * installed app's PNGs are taken for it as for any choice, and picking a
 * motif or removing the logo puts the motif kept underneath back.
 */
final class CustomLogoTest extends WebTestCase
{
    private const string EMAIL = 'e2e@plmail.test';

    private const string LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
        . '<script>alert(1)</script><circle cx="5" cy="5" r="4" fill="#c026d3" onclick="alert(2)"/></svg>';

    private KernelBrowser $client;
    private CustomLogoStorage $logos;
    private AppIconStorage $appIcons;
    private string $scratch;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->logos    = static::getContainer()->get(CustomLogoStorage::class);
        $this->appIcons = static::getContainer()->get(AppIconStorage::class);
        $this->scratch  = sys_get_temp_dir() . '/plmail-custom-logo-' . bin2hex(random_bytes(4));
        mkdir($this->scratch);

        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();

        array_map('unlink', glob($this->scratch . '/*') ?: []);
        rmdir($this->scratch);

        parent::tearDown();
    }

    public function testAnUploadIsSanitisedWornAndServed(): void
    {
        $user = $this->signIn();

        $this->uploadLogo(self::LOGO);
        self::assertResponseIsSuccessful();

        $user = $this->reload($user);
        $hash = $user->appearance->customLogo();

        self::assertNotNull($hash, 'uploading a logo is choosing it');

        $this->client->request('GET', $this->url('icon', $user, $hash));
        self::assertResponseIsSuccessful();

        $svg = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<circle', $svg);
        self::assertStringNotContainsString('alert', $svg, 'the script and the handler were cut out before it was kept');

        $headers = $this->client->getResponse()->headers;
        self::assertSame('image/svg+xml', $headers->get('Content-Type'));
        self::assertStringContainsString("default-src 'none'", (string) $headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    }

    /** The bleed form puts the logo in the middle of a ground to every edge. */
    public function testTheBleedFormIsTheLogoOnAGround(): void
    {
        $user = $this->signIn();
        $this->uploadLogo(self::LOGO);
        $user = $this->reload($user);

        $this->client->request('GET', $this->url('bleed', $user, (string) $user->appearance->logoCustom));
        self::assertResponseIsSuccessful();

        $svg = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('viewBox="0 0 108 108"', $svg);
        self::assertStringContainsString('x="18" y="18" width="72" height="72"', $svg);
        self::assertStringContainsString('<circle', $svg);
    }

    /** The tab icon, the manifest and iOS's icon all name the uploaded logo. */
    public function testTheLayoutLinksTheUploadedLogo(): void
    {
        $user = $this->signIn();
        $this->uploadLogo(self::LOGO);
        $user = $this->reload($user);
        $hash = (string) $user->appearance->logoCustom;

        $crawler = $this->client->request('GET', '/settings?section=appearance');
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('/branding/favicon/custom/' . $hash . '.svg', (string) $crawler->filter('link[rel="icon"]')->attr('href'));
        self::assertStringContainsString('/branding/manifest/custom/' . $hash . '.webmanifest', (string) $crawler->filter('link[rel="manifest"]')->attr('href'));
        self::assertStringContainsString('/custom/' . $hash . '.png', (string) $crawler->filter('link[rel="apple-touch-icon"]')->attr('href'));
        self::assertSame('true', $crawler->filter('[data-logo-motif="custom"]')->attr('aria-pressed'));
        self::assertSame('custom', $crawler->filter('input[data-settings--appearance-field="logoMotif"]')->attr('value'));

        $this->client->request('GET', (string) $crawler->filter('link[rel="manifest"]')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/custom/' . $hash . '.png', (string) $this->client->getResponse()->getContent());
    }

    /** PNGs for the uploaded logo are taken for the uploader's own hash only. */
    public function testTheInstalledAppsPngsAreTakenForTheOwnLogoOnly(): void
    {
        $user = $this->signIn();
        $this->uploadLogo(self::LOGO);
        $user = $this->reload($user);
        $hash = (string) $user->appearance->logoCustom;

        $this->uploadPngs('custom', str_repeat('0', 16));
        self::assertResponseStatusCodeSame(422, 'a hash that is not this user\'s logo names nothing');

        $this->uploadPngs('custom', $hash);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->appIcons->has((string) $user->id, LogoIcons::customChoice($hash), $this->version()));

        $this->client->request('GET', sprintf('/branding/app-icon/%s/tile-192/custom/%s.png', $this->appIcons->keyFor((string) $user->id), $hash));
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
    }

    /** A real motif takes the logo off, and keeps it to come back to. */
    public function testPickingAMotifTakesTheLogoOffButKeepsIt(): void
    {
        $user = $this->signIn();
        $this->uploadLogo(self::LOGO);

        $this->client->request('POST', '/settings/appearance', server: [
            'HTTP_X_CSRF_TOKEN' => $this->token(),
            'CONTENT_TYPE'      => 'application/json',
        ], content: json_encode(['logoMotif' => 'blue-horn'], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $user = $this->reload($user);
        self::assertNull($user->appearance->customLogo());
        self::assertNotNull($user->appearance->logoCustom);
        self::assertSame(LogoMotif::BlueHorn, $user->appearance->logoMotif);

        $this->client->request('POST', '/settings/appearance', server: [
            'HTTP_X_CSRF_TOKEN' => $this->token(),
            'CONTENT_TYPE'      => 'application/json',
        ], content: json_encode(['logoMotif' => 'custom'], JSON_THROW_ON_ERROR));

        self::assertNotNull($this->reload($user)->appearance->customLogo(), 'and picking it again wears it again');
    }

    public function testRemovingTheLogoForgetsTheFile(): void
    {
        $user = $this->signIn();
        $this->uploadLogo(self::LOGO);
        $hash = (string) $this->reload($user)->appearance->logoCustom;

        $this->client->request('POST', '/settings/appearance/logo/remove', server: ['HTTP_X_CSRF_TOKEN' => $this->token()]);
        self::assertResponseIsSuccessful();

        $user = $this->reload($user);
        self::assertNull($user->appearance->logoCustom);
        self::assertFalse($this->logos->has((string) $user->id, $hash));

        $this->client->request('GET', $this->url('icon', $user, $hash));
        self::assertResponseStatusCodeSame(404);
    }

    /** What is not a logo is refused with a sentence, and nothing is kept. */
    public function testAFileThatIsNotALogoIsRefused(): void
    {
        $user = $this->signIn();

        $this->uploadLogo('<html><body>not a drawing</body></html>');
        self::assertResponseStatusCodeSame(422);
        self::assertNotEmpty(json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? null);

        self::assertNull($this->reload($user)->appearance->logoCustom);
    }

    public function testTheUploadNeedsTheToken(): void
    {
        $this->signIn();

        $this->uploadLogo(self::LOGO, 'not-the-token');

        self::assertResponseStatusCodeSame(403);
    }

    /** Another key is another user's directory, and the URL cannot borrow it. */
    public function testALogoIsServedOnlyUnderItsOwnersKey(): void
    {
        $user = $this->signIn();
        $this->uploadLogo(self::LOGO);
        $user = $this->reload($user);

        $this->client->request('GET', sprintf('/branding/icon/custom/%s.svg?u=%s', $user->appearance->logoCustom, str_repeat('a', 20)));

        self::assertResponseStatusCodeSame(404);
    }

    private function uploadLogo(string $svg, ?string $token = null): void
    {
        $path = $this->scratch . '/logo-' . bin2hex(random_bytes(3)) . '.svg';
        file_put_contents($path, $svg);

        $this->client->request(
            'POST',
            '/settings/appearance/logo',
            files: ['logo' => new UploadedFile($path, 'logo.svg', 'image/svg+xml', test: true)],
            server: ['HTTP_X_CSRF_TOKEN' => $token ?? $this->token()],
        );
    }

    private function uploadPngs(string $motif, string $paint): void
    {
        $files = [];

        foreach (AppIconStorage::ICONS as $name => $size) {
            $image = imagecreatetruecolor($size, $size);
            $path  = $this->scratch . '/' . $name . '-' . bin2hex(random_bytes(3)) . '.png';
            imagepng($image, $path);
            $files[$name] = new UploadedFile($path, $name . '.png', 'image/png', test: true);
        }

        $this->client->request(
            'POST',
            '/settings/appearance/app-icons',
            ['motif' => $motif, 'paint' => $paint, 'version' => $this->version()],
            $files,
            ['HTTP_X_CSRF_TOKEN' => $this->token()],
        );
    }

    private function url(string $form, User $user, string $hash): string
    {
        return static::getContainer()->get(LogoIcons::class)->customUrl($form, $this->appIcons->keyFor((string) $user->id), $hash);
    }

    /** The layout's `ajax` token, as the page sends it. */
    private function token(): string
    {
        return (string) $this->client->request('GET', '/settings?section=appearance')
            ->filter('meta[name="csrf-token"]')->attr('content');
    }

    private function version(): string
    {
        return static::getContainer()->get(LogoIcons::class)->version();
    }

    private function reset(): void
    {
        $user = $this->user();

        $this->logos->deleteAllFor((string) $user->id);
        $this->appIcons->deleteAllFor((string) $user->id);

        $user->appearance->logoCustom       = null;
        $user->appearance->logoCustomActive = false;
        $user->appearance->logoMotif        = LogoMotif::DEFAULT;
        $user->appearance->logoOriginal     = true;

        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function signIn(): User
    {
        $user = $this->user();
        $this->client->loginUser($user);

        return $user;
    }

    private function reload(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }

    private function user(): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        return $user;
    }
}
