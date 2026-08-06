<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The address book, which is written by every sync and read by two features
 * that behave badly when it is wrong.
 *
 * It fills compose's autocomplete, so junk in it is junk somebody is offered
 * while addressing mail. And it decides who counts as a correspondent, which
 * the categoriser uses to pull people out of Promotions — so a lost
 * correspondent flag files a colleague's reply as an advert.
 *
 * The upsert is one hand-written statement doing four things at once (insert,
 * count, promote to correspondent, fill in a missing name) and every one of
 * them is a silent failure: nothing throws, the address book is just quietly
 * wrong.
 */
final class ContactRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ContactRepository $repository;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(ContactRepository::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnAddressIsStoredOnceAndCountedEveryTime(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'kim@example.test', 'name' => 'Kim Larsen'],
            ['email' => 'kim@example.test', 'name' => 'Kim Larsen'],
        ]);

        $rows = $this->contacts();

        self::assertCount(1, $rows);
        self::assertSame(2, (int) $rows[0]['frequency']);
    }

    /**
     * Addresses arrive as the sender wrote them, and the same person writes
     * their own address differently over time. Stored as-is they would become
     * two contacts, two autocomplete entries and two frequency counts.
     */
    public function testCaseIsNotAnIdentity(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'Kim@Example.test', 'name' => 'Kim'],
            ['email' => 'kim@example.TEST', 'name' => 'Kim'],
        ]);

        $rows = $this->contacts();

        self::assertCount(1, $rows);
        self::assertSame('kim@example.test', $rows[0]['email']);
    }

    /**
     * The guard this class exists for. A header that failed to parse yields
     * fragments — a bare `"Doe`, an empty local part — and those used to become
     * contacts, which then turned up in autocomplete while somebody was
     * addressing a message.
     */
    public function testFragmentsThatAreNotAddressesAreNotContacts(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => '"Doe', 'name' => 'Broken'],
            ['email' => '', 'name' => 'Empty'],
            ['email' => '@example.test', 'name' => 'No local part'],
            ['email' => 'kim@example.test', 'name' => 'Kim'],
        ]);

        self::assertSame(['kim@example.test'], array_column($this->contacts(), 'email'));
    }

    /** A display name is only worth having if it says more than the address. */
    public function testANameThatIsJustTheAddressIsNotAName(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'kim@example.test', 'name' => 'kim@example.test'],
        ]);

        self::assertNull($this->contacts()[0]['display_name']);
    }

    /**
     * A later sighting must not blank a name an earlier one learned — most
     * mail carries an address and no display name, so the common case would
     * erase the uncommon one.
     */
    public function testANamelessSightingKeepsTheNameAlreadyKnown(): void
    {
        $this->repository->upsertBatch($this->user, [['email' => 'kim@example.test', 'name' => 'Kim Larsen']]);
        $this->repository->upsertBatch($this->user, [['email' => 'kim@example.test', 'name' => null]]);

        self::assertSame('Kim Larsen', $this->contacts()[0]['display_name']);
    }

    /**
     * Correspondence is a one-way door: it is set when the user writes to
     * somebody, and every later inbound message from them arrives with the flag
     * unset. ORing rather than assigning is what stops the next newsletter from
     * demoting a colleague.
     */
    public function testBeingWrittenToIsNeverForgotten(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'kim@example.test', 'name' => 'Kim', 'correspondent' => true],
        ]);
        $this->repository->upsertBatch($this->user, [
            ['email' => 'kim@example.test', 'name' => 'Kim', 'correspondent' => false],
        ]);

        self::assertTrue($this->repository->isCorrespondent($this->user, 'kim@example.test'));
        self::assertSame(
            ['kim@example.test' => true],
            $this->repository->findCorrespondentEmails($this->user),
        );
    }

    public function testSomebodyOnlyEverReceivedFromIsNotACorrespondent(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'news@shop.test', 'name' => 'Shop'],
        ]);

        self::assertFalse($this->repository->isCorrespondent($this->user, 'news@shop.test'));
        self::assertSame([], $this->repository->findCorrespondentEmails($this->user));
    }

    /** Asked with the spelling from a message header, not a normalised one. */
    public function testTheCorrespondentCheckNormalisesWhatItIsAsked(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'kim@example.test', 'name' => 'Kim', 'correspondent' => true],
        ]);

        self::assertTrue($this->repository->isCorrespondent($this->user, '  Kim@Example.TEST '));
        self::assertFalse($this->repository->isCorrespondent($this->user, ''));
    }

    /**
     * Autocomplete matches a prefix or anything inside the address, so a
     * surname or a domain both find somebody, and the most-written-to come
     * first — a list ordered by anything else is a list you scroll.
     *
     * Frequency is the first key, not the only one; the tie-break below is the
     * rest of the same claim.
     */
    public function testAutocompleteMatchesEitherHalfAndRanksByFrequency(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'rare@example.test', 'name' => 'Rarely Written'],
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->repository->upsertBatch($this->user, [
                ['email' => 'frequent@example.test', 'name' => 'Frequently Written'],
            ]);
        }

        $byDomain = $this->repository->findForAutocomplete($this->user, 'example.test');

        self::assertSame(
            ['frequent@example.test', 'rare@example.test'],
            array_map(static fn ($contact): string => (string) $contact->email, $byDomain),
        );

        // Found by a word in the display name, not only by the address.
        self::assertCount(1, $this->repository->findForAutocomplete($this->user, 'rarely'));
        self::assertSame([], $this->repository->findForAutocomplete($this->user, '   '));
    }

    /**
     * The tie is the common case, not the exotic one: most of an address book
     * is people seen exactly once, so frequency alone leaves most of the list
     * to whatever order Postgres felt like returning. That is two bugs at once
     * — suggestions that reshuffle between keystrokes, and the colleague
     * mailed last week sitting below a stranger from two years ago.
     *
     * Deliberately equal frequencies. A test that also varied frequency would
     * pass on the first key alone and never reach the one it is named for.
     */
    public function testContactsSeenEquallyOftenAreRankedByHowRecently(): void
    {
        $this->repository->upsertBatch($this->user, [
            ['email' => 'stale@example.test', 'name' => 'Stale Contact'],
            ['email' => 'recent@example.test', 'name' => 'Recent Contact'],
            ['email' => 'middling@example.test', 'name' => 'Middling Contact'],
        ]);

        $this->seenAt('stale@example.test', '2024-01-01 09:00:00');
        $this->seenAt('middling@example.test', '2025-06-15 09:00:00');
        $this->seenAt('recent@example.test', '2026-07-30 09:00:00');

        self::assertSame(
            [1, 1, 1],
            array_map(static fn (array $row): int => (int) $row['frequency'], $this->contacts()),
            'the tie-break is only the subject while the frequencies are equal',
        );

        self::assertSame(
            ['recent@example.test', 'middling@example.test', 'stale@example.test'],
            array_map(
                static fn ($contact): string => (string) $contact->email,
                $this->repository->findForAutocomplete($this->user, 'example.test'),
            ),
        );
    }

    /**
     * And frequency still outranks recency, rather than the new key quietly
     * becoming the primary one: somebody written to twenty times last year
     * belongs above somebody seen once this morning.
     */
    public function testRecencyBreaksTiesRatherThanOutrankingFrequency(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->repository->upsertBatch($this->user, [['email' => 'colleague@example.test', 'name' => 'Colleague']]);
        }

        $this->repository->upsertBatch($this->user, [['email' => 'stranger@example.test', 'name' => 'Stranger']]);

        $this->seenAt('colleague@example.test', '2025-01-01 09:00:00');
        $this->seenAt('stranger@example.test', '2026-08-06 09:00:00');

        self::assertSame(
            ['colleague@example.test', 'stranger@example.test'],
            array_map(
                static fn ($contact): string => (string) $contact->email,
                $this->repository->findForAutocomplete($this->user, 'example.test'),
            ),
        );
    }

    /**
     * Address books are per user. This is a query on a shared table with a
     * user column, which is exactly the shape that leaks when the clause is
     * dropped.
     */
    public function testOneUserNeverSeesAnother(): void
    {
        $stranger = $this->seedUser();

        $this->repository->upsertBatch($stranger, [
            ['email' => 'secret@example.test', 'name' => 'Secret', 'correspondent' => true],
        ]);

        self::assertSame([], $this->repository->findForAutocomplete($this->user, 'secret'));
        self::assertSame([], $this->repository->findCorrespondentEmails($this->user));
        self::assertFalse($this->repository->isCorrespondent($this->user, 'secret@example.test'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Backdate a sighting, because the write path cannot.
     *
     * `upsertBatch` stamps `last_seen_at` with `now()` for every row it
     * touches, and the column is `TIMESTAMP(0)` — second precision — so two
     * contacts harvested in the same test are seen at the *same* instant and
     * there is no tie to break. Written through the connection rather than the
     * entity for the same reason the upsert is: this is the column, and going
     * through Doctrine would only mean re-reading a row to write one field of
     * it.
     *
     * **There is deliberately no NULL counterpart to this helper.**
     * `last_seen_at` is NOT NULL (Version20260714094203, never altered), so a
     * never-seen contact cannot be inserted and the query's nulls-last CASE
     * cannot be exercised from here. It is in the query against the column
     * becoming nullable later — see findForAutocomplete, which explains what
     * would break — and the day it does, this is where the test goes.
     */
    private function seenAt(string $email, string $when): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE contact SET last_seen_at = ? WHERE usr_id = ? AND email = ?',
            [$when, $this->user->id, $email],
        );

        self::assertSame(1, $updated, sprintf('no contact "%s" to backdate — the fixture did not write one', $email));
    }

    /** @return list<array<string,mixed>> this user's contacts, oldest first */
    private function contacts(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT email, display_name, frequency, is_correspondent
             FROM contact WHERE usr_id = ? ORDER BY id',
            [$this->user->id],
        );
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'contacts-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Contacts';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
