<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\DTO\Integration\TimelineBucket;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Interface\DestinationDriverInterface;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\TimelineDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Integration\IntegrationDriverRegistry;
use App\Service\Integration\IntegrationFilePicker;
use App\Service\Mail\AttachmentResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The picker's dealings with a service that misbehaves.
 *
 * A file store is the one collaborator in this application that is expected to
 * be down, slow or partially broken at any moment, and the picker's job is to
 * keep rendering anyway. Almost every test here is about a failure: a listing
 * that throws, a file over the cap, a share link the service declines to mint.
 * The successful paths are interesting mainly as the thing those failures must
 * not take down with them.
 *
 * A kernel test only because AttachmentResolver is final and has four
 * collaborators of its own; the driver is a fake, so nothing here touches a
 * network or the database.
 */
final class IntegrationFilePickerTest extends KernelTestCase
{
    private const int CAP = 1_000;

    private string $storageRoot;
    private AttachmentResolver $attachmentResolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->attachmentResolver = self::getContainer()->get(AttachmentResolver::class);
        $this->storageRoot = sys_get_temp_dir() . '/picker-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (true === is_dir($this->storageRoot)) {
            $this->removeTree($this->storageRoot);
        }

        parent::tearDown();
    }

    // ── browsing ─────────────────────────────────────────────────────────────

    /**
     * A service that is down must leave the picker renderable: entries gone,
     * reason present, no exception past this boundary.
     */
    public function testAFailedListingBecomesAReasonRatherThanAnException(): void
    {
        $driver = new FakePickerDriver();
        $driver->listError = 'Nextcloud said 503';

        $integration = $this->integration();

        $view = $this->picker($driver)->browse($integration, null, null, null);

        self::assertNull($view->listing);
        self::assertSame('Nextcloud said 503', $view->error);
        // And recorded, so the settings list can say the connection is stale
        // before the user next reaches for it.
        self::assertFalse($integration->isHealthy());
    }

    /**
     * Search is only offered where the user's provider declares it *and* the
     * driver implements it. A driver missing the interface must be listed, not
     * searched, however the query arrived — otherwise the picker offers a box
     * that silently does nothing.
     */
    public function testADriverThatCannotSearchIsListedInstead(): void
    {
        $driver = new FakePickerDriver();

        // Immich declares Capability::Search; this fake does not implement
        // SearchableDriverInterface, which is the half that decides.
        $view = $this->picker($driver)->browse($this->integration(Provider::Immich), null, null, 'holiday');

        self::assertFalse($view->canSearch);
        self::assertSame(['list'], $driver->calls);
    }

    /**
     * The scrubber describes a whole library, so it must not appear beside a
     * slice of one — an album, a person, or a search result.
     */
    public function testTheTimelineScrubberIsSuppressedOutsideTheWholeLibrary(): void
    {
        $picker = $this->picker(new FakeTimelinePickerDriver());
        $immich = $this->integration(Provider::Immich);

        self::assertCount(1, $picker->browse($immich, null, null, null)->buckets);
        self::assertCount(1, $picker->browse($immich, 'timeline', null, null)->buckets);
        self::assertSame([], $picker->browse($immich, 'album:7', null, null)->buckets);
        self::assertSame([], $picker->browse($immich, null, null, 'holiday')->buckets);
    }

    /**
     * A scrubber that cannot be built is cosmetic: the listing already
     * succeeded, and losing the files over a missing date bar would be absurd.
     */
    public function testAFailedScrubberDoesNotCostTheListing(): void
    {
        $driver = new FakeTimelinePickerDriver();
        $driver->bucketError = 'timeline unavailable';

        $view = $this->picker($driver)->browse($this->integration(Provider::Immich), null, null, null);

        self::assertNotNull($view->listing);
        self::assertNull($view->error);
        self::assertSame([], $view->buckets);
    }

    /** A preview a service has no answer for is a null, not a failed request. */
    public function testAPreviewFailureIsIndistinguishableFromNoPreview(): void
    {
        $driver = new FakePickerDriver();
        $driver->thumbnailError = 'no such asset';

        $integration = $this->integration();

        self::assertNull($this->picker($driver)->preview($integration, 'person:42'));
        // Missing previews are routine — a zip, a face crop never generated —
        // so they must not mark the connection unhealthy.
        self::assertTrue($integration->isHealthy());
    }

    // ── pulling files into a draft ───────────────────────────────────────────

    public function testACopiedFileBecomesAnAttachmentAndSetsThePaperclip(): void
    {
        $driver = new FakePickerDriver();
        $driver->files['doc.pdf'] = new RemoteFile('doc.pdf', 'application/pdf', 'hello');

        $draft = $this->draft();

        $transfer = $this->picker($driver)->pullIntoDraft(
            $this->integration(),
            $draft,
            ['doc.pdf' => 'copy'],
            self::CAP,
        );

        $part = $draft->messageParts->first();

        self::assertSame(1, $transfer->attached);
        self::assertSame('doc.pdf', $part->filename);
        self::assertSame(5, $part->size);
        self::assertFalse($part->isInline);
        // Without this the paperclip is missing from the list and the thread
        // header while the file is demonstrably attached.
        self::assertTrue($draft->hasAttachments);
    }

    /**
     * The cap is the whole reason `link` exists. A file over it is refused by
     * name — never truncated, and never silently dropped.
     */
    public function testAFileOverTheCapIsRefusedByName(): void
    {
        $driver = new FakePickerDriver();
        $driver->files['huge.iso'] = new RemoteFile(
            'huge.iso',
            'application/octet-stream',
            str_repeat('x', self::CAP + 1),
        );

        $draft = $this->draft();

        $transfer = $this->picker($driver)->pullIntoDraft(
            $this->integration(),
            $draft,
            ['huge.iso' => 'copy'],
            self::CAP,
        );

        self::assertSame(0, $transfer->attached);
        self::assertSame(['huge.iso'], $transfer->errors);
        self::assertCount(0, $draft->messageParts);
        // Left exactly as it was found: nothing was attached, so nothing about
        // the draft's paperclip may have changed.
        self::assertNotTrue($draft->hasAttachments);
    }

    /** A linked file never becomes a part — it is a URL for the body. */
    public function testALinkedFileComesBackAsAUrlAndNotAnAttachment(): void
    {
        $driver = new FakePickerDriver();
        $driver->links['photos/beach.jpg'] = 'https://cloud.test/s/abc';

        $draft = $this->draft();

        $transfer = $this->picker($driver)->pullIntoDraft(
            $this->integration(),
            $draft,
            ['photos/beach.jpg' => 'link'],
            self::CAP,
        );

        self::assertSame([['name' => 'beach.jpg', 'url' => 'https://cloud.test/s/abc']], $transfer->links);
        self::assertCount(0, $draft->messageParts);
    }

    /**
     * Immich cannot share a single asset, so shareLink legitimately answers
     * null. That is a refusal to report, not a link to nowhere.
     */
    public function testAServiceThatDeclinesToMintALinkReportsTheFile(): void
    {
        $draft = $this->draft();

        $transfer = $this->picker(new FakePickerDriver())->pullIntoDraft(
            $this->integration(),
            $draft,
            ['photos/beach.jpg' => 'link'],
            self::CAP,
        );

        self::assertSame([], $transfer->links);
        self::assertSame(['beach.jpg'], $transfer->errors);
    }

    /**
     * The one that matters most: four selected files where one fails must still
     * deliver the other three. A selection that silently became three is worse
     * than one that says which file did not make it.
     */
    public function testOneBadFileDoesNotCostTheRestOfTheSelection(): void
    {
        $driver = new FakePickerDriver();
        $driver->files['a.txt'] = new RemoteFile('a.txt', 'text/plain', 'aaa');
        $driver->files['c.txt'] = new RemoteFile('c.txt', 'text/plain', 'ccc');
        $driver->links['d.txt'] = 'https://cloud.test/s/d';
        $driver->downloadErrors['b.txt'] = 'b.txt is gone';

        $draft = $this->draft();

        $transfer = $this->picker($driver)->pullIntoDraft(
            $this->integration(),
            $draft,
            ['a.txt' => 'copy', 'b.txt' => 'copy', 'c.txt' => 'copy', 'd.txt' => 'link'],
            self::CAP,
        );

        self::assertSame(2, $transfer->attached);
        self::assertSame(['b.txt is gone'], $transfer->errors);
        self::assertCount(1, $transfer->links);
        self::assertCount(2, $draft->messageParts);
    }

    // ── pushing an attachment out ────────────────────────────────────────────

    /**
     * A destination the user chose in the picker is threaded straight through
     * to the driver's upload — the whole point of the picker over the fixed
     * folder. A driver that can vouch for a destination is asked to first.
     */
    public function testAChosenDestinationIsValidatedThenThreadedToTheUpload(): void
    {
        $driver = new FakeDestinationDriver();

        $error = $this->picker($driver)->pushAttachment(
            $this->integration(),
            $this->storedPart(),
            'Photos/2026',
            validate: true,
        );

        self::assertNull($error);
        self::assertSame('Photos/2026', $driver->assertedDestination, 'the chosen destination was vouched for');
        self::assertSame('Photos/2026', $driver->uploadedTo, 'and then uploaded to');
    }

    /**
     * The security property: a destination the driver refuses — a foreign album
     * id, a traversal path — never reaches the upload. The request is
     * attacker-controllable, so a rejection here is the difference between
     * "saved nowhere" and "wrote somewhere it should not".
     */
    public function testARefusedDestinationNeverReachesTheUpload(): void
    {
        $driver = new FakeDestinationDriver();
        $driver->assertError = 'That album is not in this account.';

        $integration = $this->integration();

        $error = $this->picker($driver)->pushAttachment(
            $integration,
            $this->storedPart(),
            '../../someone-else',
            validate: true,
        );

        self::assertSame('That album is not in this account.', $error);
        self::assertNull($driver->uploadedTo, 'the upload was never attempted');
        self::assertNotContains('upload', $driver->calls);
    }

    /**
     * The configured default is trusted — an admin set it, no request can reach
     * it — so it is uploaded without the validation round trip a picked
     * destination gets.
     */
    public function testTheTrustedDefaultSkipsValidation(): void
    {
        $driver = new FakeDestinationDriver();

        $this->picker($driver)->pushAttachment(
            $this->integration(),
            $this->storedPart(),
            'Mail attachments',
            validate: false,
        );

        self::assertNull($driver->assertedDestination, 'the default was not re-validated');
        self::assertSame('Mail attachments', $driver->uploadedTo);
    }

    /** The destination listing comes from the driver's own destinations view. */
    public function testDestinationsAsksTheDriverForItsContainers(): void
    {
        $driver = new FakeDestinationDriver();

        $view = $this->picker($driver)->destinations($this->integration(), null, null);

        self::assertNotNull($view->listing);
        self::assertSame(['Albums'], array_map(
            static fn (Entry $e): string => $e->name,
            $view->listing->entries,
        ));
    }

    public function testCreateDestinationReturnsTheNewContainerId(): void
    {
        $driver = new FakeDestinationDriver();

        $id = $this->picker($driver)->createDestination($this->integration(), null, 'Trips');

        self::assertSame('created:Trips', $id);
        self::assertTrue($this->picker($driver)->canCreateDestination($this->integration()));
    }

    public function testASuccessfulUploadUsesTheConfiguredFolderAndClearsTheError(): void
    {
        $driver = new FakePickerDriver();

        $integration = $this->integration();
        $integration->recordFailure('an older failure');

        $error = $this->picker($driver)->pushAttachment($integration, $this->storedPart(), 'Mail attachments');

        self::assertNull($error);
        self::assertSame('Mail attachments', $driver->uploadedTo);
        self::assertTrue($integration->isHealthy());
    }

    /**
     * An upload failure is the connection's fault and is recorded as such, so
     * the settings list can say "reconnect this".
     */
    public function testAFailedUploadIsReportedAndRecorded(): void
    {
        $driver = new FakePickerDriver();
        $driver->uploadError = 'quota exceeded';

        $integration = $this->integration();

        $error = $this->picker($driver)->pushAttachment($integration, $this->storedPart(), null);

        self::assertSame('quota exceeded', $error);
        self::assertFalse($integration->isHealthy());
    }

    /**
     * A provider-hosted part whose bytes cannot be materialised is a different
     * failure: it says nothing about the service, so the connection must not be
     * marked unhealthy for it.
     */
    public function testAnUnmaterialisablePartDoesNotBlameTheService(): void
    {
        $integration = $this->integration();

        $part = $this->storedPart();
        // A Gmail part with no attachment id behind the scheme — what a row
        // written by an interrupted sync looks like.
        $part->storagePath = 'gmail://';

        $error = $this->picker(new FakePickerDriver())->pushAttachment($integration, $part, null);

        self::assertNotNull($error);
        self::assertTrue($integration->isHealthy());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function picker(IntegrationDriverInterface $driver): IntegrationFilePicker
    {
        return new IntegrationFilePicker(
            new IntegrationDriverRegistry([$driver]),
            new AttachmentStorageHelper($this->storageRoot, 'attachments'),
            $this->attachmentResolver,
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function integration(Provider $provider = Provider::Nextcloud): Integration
    {
        return new Integration(new User(), $provider, $provider->label());
    }

    private function draft(): Message
    {
        $message = new Message();
        $message->account = new Account();

        return $message;
    }

    private function storedPart(): MessagePart
    {
        $part = new MessagePart();
        $part->message = $this->draft();
        $part->filename = 'doc.pdf';
        $part->contentType = 'application/pdf';
        $part->storagePath = '1/0/1/doc.pdf';

        return $part;
    }

    private function removeTree(string $path): void
    {
        foreach ((array) scandir($path) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path . '/' . $entry;

            true === is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}

/**
 * A service that answers exactly what a test told it to, and records what it
 * was asked. Not a mock: the assertions here are about what the picker returns
 * after a driver misbehaves, which is easier to state as data than as
 * expectations.
 */
class FakePickerDriver implements IntegrationDriverInterface
{
    public ?string $listError = null;
    public ?string $thumbnailError = null;
    public ?string $uploadError = null;
    public ?string $uploadedTo = null;

    /** @var array<string,RemoteFile> */
    public array $files = [];
    /** @var array<string,string> */
    public array $links = [];
    /** @var array<string,string> */
    public array $downloadErrors = [];
    /** @var list<string> */
    public array $calls = [];

    public function supports(Provider $provider): bool
    {
        return true;
    }

    public function verify(Integration $integration): void
    {
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        $this->calls[] = 'list';

        if (null !== $this->listError) {
            throw new IntegrationException($this->listError);
        }

        return new Listing([new Entry('f1', 'file.txt', false)]);
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $this->calls[] = 'download';

        if (true === isset($this->downloadErrors[$fileId])) {
            throw new IntegrationException($this->downloadErrors[$fileId]);
        }

        return $this->files[$fileId] ?? throw new IntegrationException('no such file');
    }

    public function upload(
        Integration $integration,
        string $absolutePath,
        string $filename,
        string $mime,
        ?string $folderId = null,
    ): string {
        $this->calls[] = 'upload';
        $this->uploadedTo = $folderId;

        if (null !== $this->uploadError) {
            throw new IntegrationException($this->uploadError);
        }

        return 'remote-id';
    }

    public function shareLink(Integration $integration, string $fileId): ?string
    {
        $this->calls[] = 'shareLink';

        return $this->links[$fileId] ?? null;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $this->calls[] = 'thumbnail';

        if (null !== $this->thumbnailError) {
            throw new IntegrationException($this->thumbnailError);
        }

        return null;
    }
}

/**
 * The same fake, plus the destination half Nextcloud and the photo libraries
 * have: it can vouch for a chosen container and create one, and records what it
 * was asked to vouch for so the picker's guard can be observed.
 */
final class FakeDestinationDriver extends FakePickerDriver implements DestinationDriverInterface
{
    public ?string $assertError = null;
    public ?string $assertedDestination = null;

    public function destinations(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return new Listing([new Entry('a1', 'Albums', true)]);
    }

    public function assertDestination(Integration $integration, string $destination): void
    {
        $this->assertedDestination = $destination;

        if (null !== $this->assertError) {
            throw new IntegrationException($this->assertError);
        }
    }

    public function createDestination(Integration $integration, ?string $parent, string $name): string
    {
        return 'created:'.$name;
    }
}

/** The same fake, plus the timeline half Immich has and nothing else does. */
final class FakeTimelinePickerDriver extends FakePickerDriver implements TimelineDriverInterface
{
    public ?string $bucketError = null;

    public function timelineBuckets(Integration $integration): array
    {
        if (null !== $this->bucketError) {
            throw new IntegrationException($this->bucketError);
        }

        return [new TimelineBucket('cursor-1', 'Mar', 12, 'March 2026')];
    }
}
