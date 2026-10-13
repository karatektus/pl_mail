<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook;

use App\Domain\DTO\AddressBook\VCardFields;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\AddressBook\ContactCard;
use App\Repository\AddressBook\ContactCardRepository;
use App\Service\AddressBook\AddressBookSync;
use App\Service\AddressBook\CardDav\VCardConverter;
use App\Tests\Service\AddressBook\CardDav\CardDavFixture;
use App\Tests\Service\Calendar\Sync\CalDav\CalDavFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * One address book brought back into agreement with its server.
 *
 * The real driver over a scripted server, and the cards held in memory instead
 * of Postgres — the rules under test are about which row wins, not about SQL.
 *
 * The claims, most important first:
 *
 *   - **Push before pull.** An edit made here reaches the server before the
 *     server's older copy is read, or it would be overwritten on the way out.
 *   - **A pending edit is never overwritten by a pull**, and never deleted by a
 *     full listing that does not mention it.
 *   - **On a conflict the server wins**, and the card is read again.
 *   - **Only a full listing deletes** what it did not mention.
 */
final class AddressBookSyncTest extends TestCase
{
    private const string BOOK = 'https://dav.example.com/dav/addressbooks/alice/contacts/';

    /** @var list<ContactCard> */
    private array $cards = [];

    private AddressBook $book;

    protected function setUp(): void
    {
        $this->cards = [];
        $this->book  = CardDavFixture::book(self::BOOK);
    }

    public function testAFirstSyncCreatesACardPerVCard(): void
    {
        $fixture = $this->server(
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/ada.vcf'   => ['etag' => '"1"', 'vcard' => CardDavFixture::vcard('ada', 'Ada Lovelace', 'ada@example.com')],
                '/dav/addressbooks/alice/contacts/grace.vcf' => ['etag' => '"2"', 'vcard' => CardDavFixture::vcard('grace', 'Grace Hopper', 'grace@example.com')],
            ], 'token-1'),
        );

        $touched = $this->sync($fixture);

        self::assertSame(2, $touched);
        self::assertSame(['Ada Lovelace', 'Grace Hopper'], array_map(static fn (ContactCard $c): string => $c->fullName, $this->cards));
        self::assertSame(['ada@example.com'], $this->cards[0]->emails);
        self::assertSame(self::BOOK . 'ada.vcf', $this->cards[0]->href);
        self::assertSame('"1"', $this->cards[0]->etag);
        self::assertSame('token-1', $this->book->syncToken);
        self::assertTrue($this->book->isHealthy());
        self::assertNotNull($this->book->lastSyncedAt);
    }

    public function testAnEditMadeHereIsPushedBeforeTheServerIsRead(): void
    {
        $card = $this->card('ada', 'Ada Lovelace', etag: '"1"');
        $this->edit($card, 'Ada King');

        $fixture = $this->server(
            // The delta reports our own write back, under the etag the PUT
            // was answered with.
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/ada.vcf' => ['etag' => '"2"', 'vcard' => CardDavFixture::vcard('ada', 'Ada King', 'ada@example.com')],
            ], 'token-2'),
            pushFirst: [CalDavFixture::status(204, ['etag' => '"2"'])],
            syncToken: 'token-1',
        );

        $this->sync($fixture);

        self::assertSame('PUT', $fixture->method(0), 'the push goes first');
        self::assertSame('"1"', $fixture->header(0, 'If-Match'));
        self::assertStringContainsString('FN:Ada King', $fixture->body(0));
        self::assertSame(SyncState::Clean, $card->syncState);
        self::assertSame('"2"', $card->etag);
        self::assertSame('Ada King', $card->fullName, 'the pull saw its own write and left it alone');
        self::assertSame(3, $fixture->requestCount(), 'PUT, PROPFIND, REPORT');
    }

    public function testAConflictLetsTheServerWinAndReadsEverythingAgain(): void
    {
        $card = $this->card('ada', 'Ada Lovelace', etag: '"1"');
        $this->edit($card, 'Ada King');

        $fixture = $this->server(
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/ada.vcf' => ['etag' => '"9"', 'vcard' => CardDavFixture::vcard('ada', 'Countess Lovelace', 'ada@example.com')],
            ], 'token-9'),
            pushFirst: [CalDavFixture::status(412)],
            syncToken: 'token-1',
        );

        $this->sync($fixture);

        self::assertSame('Countess Lovelace', $card->fullName, 'the phone\'s edit won');
        self::assertSame(SyncState::Clean, $card->syncState);
        self::assertStringContainsString('<d:sync-token></d:sync-token>', $fixture->body(2), 'a full listing, not a delta');
    }

    public function testADeletionHereIsSentAndTheRowGoes(): void
    {
        $card            = $this->card('ada', 'Ada Lovelace', etag: '"1"');
        $card->syncState = SyncState::PendingDelete;

        $fixture = $this->server(
            CardDavFixture::report([], 'token-2'),
            pushFirst: [CalDavFixture::status(204)],
            syncToken: 'token-1',
        );

        $this->sync($fixture);

        self::assertSame('DELETE', $fixture->method(0));
        self::assertSame([], $this->cards);
    }

    public function testADeletionOnTheServerRemovesTheLocalCard(): void
    {
        $this->card('ada', 'Ada Lovelace', etag: '"1"');
        $this->card('grace', 'Grace Hopper', etag: '"2"');

        $this->sync($this->server(
            CardDavFixture::report(['/dav/addressbooks/alice/contacts/ada.vcf' => null], 'token-2'),
            syncToken: 'token-1',
        ));

        self::assertSame(['grace'], array_map(static fn (ContactCard $c): string => $c->uid, $this->cards));
    }

    /** Asked with a token, silence means "unchanged", not "deleted". */
    public function testADeltaDoesNotDeleteWhatItDidNotMention(): void
    {
        $this->card('ada', 'Ada Lovelace', etag: '"1"');

        $this->sync($this->server(CardDavFixture::report([], 'token-2'), syncToken: 'token-1'));

        self::assertCount(1, $this->cards);
    }

    public function testAFullListingDeletesWhatItDidNotMentionButNotPendingEdits(): void
    {
        $this->card('gone', 'Gone Person', etag: '"1"');
        $local            = new ContactCard($this->book, 'local-only');
        $local->syncState = SyncState::PendingCreate;
        $local->fill(new VCardFields('local-only', 'Not Yet Sent'));
        $this->cards[] = $local;

        // The PUT for the pending card fails transiently (500): the card stays
        // pending, the sync fails, and nothing is deleted.
        $fixture = $this->server(
            CardDavFixture::report([], 'token-1'),
            pushFirst: [CalDavFixture::status(500)],
        );

        try {
            $this->sync($fixture);
            self::fail('a 500 on the push fails the sync');
        } catch (\App\Domain\Exception\CalendarSyncException) {
        }

        self::assertCount(2, $this->cards, 'nothing was pulled, so nothing was deleted');
        self::assertFalse($this->book->isHealthy());

        // Without the failure: the listing omits both, and only the clean one goes.
        $this->cards = [];
        $this->card('gone', 'Gone Person', etag: '"1"');
        $this->cards[] = $local;
        $this->book->isReadOnly = false;

        // The listing names the card just pushed — it exists there now — and
        // not the one deleted elsewhere.
        $this->sync($this->server(
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/local-only.vcf' => ['etag' => '"n"', 'vcard' => CardDavFixture::vcard('local-only', 'Not Yet Sent', 'x@example.com')],
            ], 'token-1'),
            pushFirst: [CalDavFixture::status(201, ['etag' => '"n"'])],
        ));

        self::assertSame(['local-only'], array_map(static fn (ContactCard $c): string => $c->uid, $this->cards));
        self::assertSame(self::BOOK . 'local-only.vcf', $local->href);
    }

    /**
     * The `valid-sync-token` precondition arrives as an exception rather than
     * as the driver's resync outcome. Let through, it failed the sync with the
     * dead token still stored, and every retry presented the same token to
     * the same refusal.
     */
    public function testADeadSyncTokenIsDroppedAndTheBookReadFromScratch(): void
    {
        $this->card('ada', 'Ada Lovelace', etag: '"1"');
        $this->book->syncToken = 'expired';

        $fixture = new CardDavFixture(
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: true),
            CalDavFixture::deadSyncToken(403),
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: true),
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/grace.vcf' => ['etag' => '"2"', 'vcard' => CardDavFixture::vcard('grace', 'Grace Hopper', 'grace@example.com')],
            ], 'token-fresh'),
        );

        $this->syncWith($fixture);

        self::assertStringContainsString('<d:sync-token></d:sync-token>', $fixture->body(3), 'the second report starts from scratch');
        self::assertSame('token-fresh', $this->book->syncToken);
        self::assertTrue($this->book->isHealthy());
        self::assertSame(['grace'], array_map(static fn (ContactCard $c): string => $c->uid, $this->cards), 'and, being a full listing, it can delete');
    }

    /**
     * A server that moves a card deletes the old href and creates a new one
     * with the same UID, and a delta may list the two in either order. The
     * row must end up at the new href in both — not removed because the
     * deletion of its old address was read after it had moved.
     */
    public function testAMovedCardKeepsItsRowWhicheverOrderTheDeltaListsIt(): void
    {
        $moved = CardDavFixture::vcard('ada', 'Ada Lovelace', 'ada@example.com');

        foreach ([
            'deletion first'  => ['/dav/addressbooks/alice/contacts/ada.vcf' => null, '/dav/addressbooks/alice/contacts/moved/ada.vcf' => ['etag' => '"2"', 'vcard' => $moved]],
            'deletion second' => ['/dav/addressbooks/alice/contacts/moved/ada.vcf' => ['etag' => '"2"', 'vcard' => $moved], '/dav/addressbooks/alice/contacts/ada.vcf' => null],
        ] as $order => $members) {
            $this->cards = [];
            $card        = $this->card('ada', 'Ada Lovelace', etag: '"1"');

            $this->sync($this->server(CardDavFixture::report($members, 'token-2'), syncToken: 'token-1'));

            self::assertSame([$card], $this->cards, $order);
            self::assertSame(self::BOOK . 'moved/ada.vcf', $card->href, $order);
        }
    }

    public function testAPermanentFailureIsRecordedOnTheBookForTheSettingsList(): void
    {
        $fixture = new CardDavFixture(CalDavFixture::status(401));

        try {
            $this->syncWith($fixture);
            self::fail('a 401 must fail the sync');
        } catch (CalendarSyncPermanentException $e) {
            self::assertSame($e->getMessage(), $this->book->lastSyncError);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A sync-collection server: optional push responses, then the collection
     * probe and the report. A dead token is not scripted here; the conflict
     * test reads the token it was sent from the request body instead.
     *
     * @param list<MockResponse> $pushFirst
     */
    private function server(MockResponse $report, array $pushFirst = [], ?string $syncToken = null): CardDavFixture
    {
        $this->book->syncToken = $syncToken;

        return new CardDavFixture(
            ...$pushFirst,
            ...[CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: true), $report],
        );
    }

    private function sync(CardDavFixture $fixture): int
    {
        return $this->syncWith($fixture);
    }

    private function syncWith(CardDavFixture $fixture): int
    {
        $sync = new AddressBookSync($fixture->driver, new VCardConverter(), $this->repository(), $this->entityManager(), new NullLogger());

        return $sync->sync($this->book);
    }

    private function card(string $uid, string $name, string $etag): ContactCard
    {
        $card        = new ContactCard($this->book, $uid);
        $card->href  = self::BOOK . $uid . '.vcf';
        $card->etag  = $etag;
        $card->vcard = CardDavFixture::vcard($uid, $name, $uid . '@example.com');
        $card->fill(new VCardFields($uid, $name, [$uid . '@example.com']));

        $this->cards[] = $card;

        return $card;
    }

    private function edit(ContactCard $card, string $name): void
    {
        $fields          = new VCardFields($card->uid, $name, $card->emails);
        $card->vcard     = new VCardConverter()->write($card->vcard, $fields);
        $card->syncState = SyncState::PendingUpdate;
        $card->fill($fields);
    }

    private function repository(): ContactCardRepository
    {
        $cards = &$this->cards;

        return new class($cards) extends ContactCardRepository {
            /** @param list<ContactCard> $cards */
            public function __construct(private array &$cards)
            {
            }

            public function findPending(AddressBook $book): array
            {
                return array_values(array_filter($this->cards, static fn (ContactCard $c): bool => $c->syncState->isPending()));
            }

            public function indexForBook(AddressBook $book): array
            {
                $byHref = [];
                $byUid  = [];

                foreach ($this->cards as $card) {
                    if (null !== $card->href) {
                        $byHref[$card->href] = $card;
                    }

                    $byUid[$card->uid] = $card;
                }

                return ['byHref' => $byHref, 'byUid' => $byUid];
            }
        };
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof ContactCard) {
                $this->cards[] = $entity;
            }
        });

        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->cards = array_values(array_filter($this->cards, static fn (ContactCard $c): bool => $c !== $entity));
        });

        return $em;
    }
}
