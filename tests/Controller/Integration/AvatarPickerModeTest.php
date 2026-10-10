<?php

declare(strict_types=1);

namespace App\Tests\Controller\Integration;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\DTO\Integration\TimelineBucket;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Interface\TimelineDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Service\Integration\IntegrationDriverRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Choosing a profile picture goes through the compose file picker.
 *
 * Settings → Profile used to have a grid of its own — the first page of a
 * service's root, capped, with no albums, search or paging — so the picture
 * could only ever be one of the newest. It opens the real picker now, in an
 * avatar mode of the same browse route, and the mode has to survive every click
 * inside the dialog: breadcrumbs, shortcuts, folders, people, the date bar,
 * search and the next page are all navigations in the same frame, and a link
 * that forgot it turns the following click back into "attach to a draft".
 * That is what is pinned here, from the markup the picker renders.
 */
final class AvatarPickerModeTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Every way of leaving this page of a photo library — crumb, shortcut,
     * album, date bar, next page — stays in the avatar picker.
     *
     * The presence checks come first on purpose: a page with no links would
     * satisfy "none of them lacks the mode" just as well.
     */
    public function testEveryNavigationInAPhotoLibraryKeepsTheAvatarMode(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration(Provider::Immich);

        $crawler = $client->request('GET', '/integrations/' . $integration->id . '/browse?mode=avatar');

        self::assertResponseIsSuccessful();

        // crumb "Library", shortcut "Albums", album "Holiday", scrubber month,
        // next page.
        $links = $this->browseLinks($crawler);
        self::assertGreaterThanOrEqual(5, count($links), 'the page should offer five ways onward');

        foreach ($links as $href) {
            self::assertStringContainsString('mode=avatar', $href);
            self::assertStringNotContainsString('draft=', $href);
        }

        // The search box is a GET form, so its mode is a hidden field.
        self::assertSame('avatar', $crawler->filter('form input[name="mode"]')->attr('value'));
        self::assertCount(0, $crawler->filter('form input[name="draft"]'));
    }

    /**
     * The people grid has its own link partial, which is the easy one to miss.
     */
    public function testAPersonInThePeopleViewKeepsTheAvatarMode(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration(Provider::Immich);

        $crawler = $client->request('GET', '/integrations/' . $integration->id . '/browse?mode=avatar&folder=people');

        self::assertResponseIsSuccessful();

        $person = $crawler->filter('a[href*="folder=person"]');
        self::assertCount(1, $person, 'the person should be a link');
        self::assertStringContainsString('mode=avatar', (string) $person->attr('href'));
        self::assertStringNotContainsString('draft=', (string) $person->attr('href'));
    }

    /**
     * Presence companion to the two above: the same pages in attach mode carry
     * the draft and not the avatar mode, so those assertions are about the mode
     * rather than about a picker that happens to render no draft.
     */
    public function testAttachModeStillCarriesTheDraft(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration(Provider::Immich);

        $crawler = $client->request('GET', '/integrations/' . $integration->id . '/browse?draft=7');

        self::assertResponseIsSuccessful();

        $links = $this->browseLinks($crawler);
        self::assertGreaterThanOrEqual(5, count($links));

        foreach ($links as $href) {
            self::assertStringContainsString('draft=7', $href);
            self::assertStringNotContainsString('mode=avatar', $href);
        }

        self::assertCount(1, $crawler->filter('form input[name="draft"]'));
    }

    /**
     * A file store lists rows rather than tiles, and a library of scans is
     * mostly PDFs. They are shown — hiding them would leave a page that looks
     * empty with "show more" under it — but cannot be chosen, and nothing
     * offers a share link, which is no use as a profile picture.
     */
    public function testAFileStoreShowsNonImagesButDoesNotLetThemBeChosen(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration(Provider::Nextcloud);

        $crawler = $client->request('GET', '/integrations/' . $integration->id . '/browse?mode=avatar');

        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('input[name="mode[p1]"]'), 'the photo can be chosen');
        self::assertCount(0, $crawler->filter('input[name="mode[d1]"]'), 'the PDF cannot');
        self::assertStringContainsString('scan.pdf', (string) $client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('input[value="link"]'));

        // The avatar store's cap, not the attachment one.
        self::assertCount(0, $crawler->filter('input[name="mode[b1]"]'), 'a photo over 4 MB cannot');

        foreach ($this->browseLinks($crawler) as $href) {
            self::assertStringContainsString('mode=avatar', $href);
        }

        // And the other side: asked to attach, the same page lets the PDF be
        // chosen, so the refusal above is the mode's doing.
        $crawler = $client->request('GET', '/integrations/' . $integration->id . '/browse?draft=7');

        self::assertCount(1, $crawler->filter('input[name="mode[b1]"][value="copy"]'));
        self::assertCount(1, $crawler->filter('input[name="mode[d1]"][value="copy"]'));
        self::assertCount(1, $crawler->filter('input[name="mode[d1]"][value="link"]'));
    }

    /**
     * The picker never needs a draft in this mode, but it is still somebody's
     * connection: another user's id is refused like it is when attaching.
     */
    public function testAnotherUsersConnectionIsRefused(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration(Provider::Immich);

        $client->loginUser($this->seedUser('stranger'));
        $client->request('GET', '/integrations/' . $integration->id . '/browse?mode=avatar');

        self::assertResponseStatusCodeSame(403);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @return list<string> the href of every navigation inside the picker
     */
    private function browseLinks(Crawler $crawler): array
    {
        return $crawler->filter('a[href*="/browse"]')->each(
            static fn (Crawler $a): string => (string) $a->attr('href'),
        );
    }

    private function seedIntegration(Provider $provider): Integration
    {
        $integration = new Integration($this->user, $provider, 'Photos');
        $integration->baseUrl = 'https://photos.example.test';

        $this->em->persist($integration);
        $this->em->flush();

        return $integration;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // The service this exercises is the one place the application talks to
        // somebody else's server, so it is replaced; everything between the
        // request and it is real.
        $container->set(IntegrationDriverRegistry::class, new IntegrationDriverRegistry([new AvatarPickerFakeDriver()]));

        $this->connection->beginTransaction();

        $this->user = $this->seedUser('owner');
        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(string $tag): User
    {
        $user = new User();
        $user->email = 'avatar-picker-' . $tag . '-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Avatar';
        $user->nameLast = 'Picker';
        $user->roles = ['ROLE_USER'];
        $user->password = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

/**
 * A service with one page of photos, an album, a person and a date bar.
 *
 * Not a mock: what matters is the markup the picker draws from what it is told,
 * which is easier to state as data.
 */
final class AvatarPickerFakeDriver implements IntegrationDriverInterface, SearchableDriverInterface, TimelineDriverInterface
{
    public function supports(Provider $provider): bool
    {
        return true;
    }

    public function verify(Integration $integration): void
    {
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        if ('people' === $folderId) {
            return new Listing([Entry::person('person:1', 'Ann')], [Entry::folder('', 'Library'), Entry::folder('people', 'People')]);
        }

        return new Listing(
            [
                Entry::folder('album-1', 'Holiday'),
                new Entry('p1', 'beach.jpg', false, 1_000, 'image/jpeg'),
                new Entry('d1', 'scan.pdf', false, 1_000, 'application/pdf'),
                // Fine to attach to a mail, over what a profile picture may be.
                new Entry('b1', 'panorama.jpg', false, 5 * 1024 * 1024, 'image/jpeg'),
            ],
            [Entry::folder('', 'Library'), Entry::folder('timeline', 'All photos')],
            'page-2',
            [Entry::folder('albums', 'Albums')],
        );
    }

    public function search(Integration $integration, string $query, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return $this->list($integration, $folderId, $cursor);
    }

    public function timelineBuckets(Integration $integration): array
    {
        return [new TimelineBucket('cursor-1', '2026', 12, 'March 2026')];
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        throw new \LogicException('Browsing downloads nothing.');
    }

    public function upload(Integration $integration, string $absolutePath, string $filename, string $mime, ?string $folderId = null): string
    {
        throw new \LogicException('Browsing uploads nothing.');
    }

    public function shareLink(Integration $integration, string $fileId): string
    {
        return 'https://share.example.test/' . $fileId;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        return null;
    }
}
