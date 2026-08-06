<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Contact;

use App\Entity\Mail\Contact;
use App\Entity\User\User;
use App\Jmap\Method\Contact\ContactAutocompleteMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The suggestion list a phone offers while a recipient is typed, and the four
 * ways it can be wrong without anything throwing.
 *
 * It can match too little (only the address, so a surname finds nobody); it can
 * rank badly (the once-seen mailing list above the colleague, which is a list
 * you scroll past rather than pick from); it can answer a question nobody
 * asked (an empty query LIKEs everything and comes back with eight plausible
 * strangers); and it can answer with somebody else's address book, which is
 * the failure mode of every query on a shared table with a user column.
 *
 * Against the real repository and a real database rather than a double. The
 * ranking and the matching are SQL — an ORDER BY and four case-folded LIKEs —
 * so a mocked repository would assert the shape of a call instead of observing
 * that the right people came back in the right order, which is the only thing
 * worth pinning here.
 */
final class ContactAutocompleteMethodTest extends JmapTestCase
{
    private ContactAutocompleteMethod $autocomplete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autocomplete = self::getContainer()->get(ContactAutocompleteMethod::class);
    }

    /**
     * Both halves of a contact are searchable, because both are how somebody
     * refers to a person: the name they know them by, and the address or the
     * domain they remember.
     */
    public function testAContactIsFoundByNameAsWellAsByAddress(): void
    {
        $this->seedContact('anna.k@example.test', 'Anna Karlsson');

        self::assertSame(['anna.k@example.test'], $this->emailsFor('anna.k'));
        self::assertSame(['anna.k@example.test'], $this->emailsFor('Karlsson'));
        self::assertSame(['anna.k@example.test'], $this->emailsFor('example.test'));
    }

    /**
     * The ranking is the whole reason this is a server call rather than a
     * client-side scan of whatever mail the device happens to hold: the person
     * written to most often comes first, wherever they sit alphabetically.
     */
    public function testTheMostFrequentlySeenComeFirst(): void
    {
        $this->seedContact('zoe@example.test', 'Zoe Andersson', frequency: 40);
        $this->seedContact('adam@example.test', 'Adam Berg', frequency: 2);
        $this->seedContact('mira@example.test', 'Mira Cole', frequency: 17);

        self::assertSame(
            ['zoe@example.test', 'mira@example.test', 'adam@example.test'],
            $this->emailsFor('example.test'),
        );
    }

    /**
     * A suggestion is a JMAP EmailAddress with the ranking signals hung off it,
     * so a client can put it straight into an Email/set create. `initials` is
     * deliberately absent — the web route computes it for a chip renderer, and
     * it is derivable from the two fields above it.
     */
    public function testASuggestionIsAnEmailAddressPlusItsRanking(): void
    {
        $seen = new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC'));
        $this->seedContact('kim@example.test', 'Kim Larsen', frequency: 9, isCorrespondent: true, lastSeenAt: $seen);

        $suggestion = $this->call(['query' => 'kim'])['list'][0];

        self::assertSame(
            [
                'name' => 'Kim Larsen',
                'email' => 'kim@example.test',
                'frequency' => 9,
                'lastSeenAt' => '2026-03-04T05:06:07Z',
                'isCorrespondent' => true,
            ],
            $suggestion,
        );
    }

    /**
     * Most harvested addresses never carried a display name. The field is null
     * rather than absent or an empty string, so a client has one thing to test
     * before falling back to the address.
     */
    public function testAnAddressWithNoDisplayNameSuggestsANullName(): void
    {
        $this->seedContact('nameless@example.test', null);

        self::assertNull($this->call(['query' => 'nameless'])['list'][0]['name']);
    }

    /**
     * Capped rather than obeyed, and the response says which. Asserted on the
     * length of the list as well as on the echoed number: a cap that reached
     * the response but not the query would pass a test that only read the
     * latter, and the sequential scan it exists to prevent would still happen
     * on every keystroke.
     */
    public function testAnOversizedLimitIsCappedAndSaidSo(): void
    {
        for ($i = 0; $i < ContactAutocompleteMethod::MAX_LIMIT + 1; ++$i) {
            $this->seedContact(sprintf('person%02d@example.test', $i), sprintf('Person %02d', $i));
        }

        $result = $this->call(['query' => 'example.test', 'limit' => 500]);

        self::assertSame(ContactAutocompleteMethod::MAX_LIMIT, $result['limit']);
        self::assertCount(ContactAutocompleteMethod::MAX_LIMIT, $result['list']);
    }

    /** A limit below the cap is the client's to choose. */
    public function testASmallLimitIsHonoured(): void
    {
        $this->seedContact('one@example.test', 'One', frequency: 3);
        $this->seedContact('two@example.test', 'Two', frequency: 2);

        $result = $this->call(['query' => 'example.test', 'limit' => 1]);

        self::assertSame(['one@example.test'], array_column($result['list'], 'email'));
        self::assertSame(1, $result['limit']);
    }

    /** An absent limit is the same list length the web composer asks for. */
    public function testTheDefaultLimitIsStatedInTheResponse(): void
    {
        $this->seedContact('kim@example.test', 'Kim');

        self::assertSame(ContactAutocompleteMethod::DEFAULT_LIMIT, $this->call(['query' => 'kim'])['limit']);
    }

    /**
     * Refused rather than answered with everybody. A blank query matches every
     * contact the user has, so the friendly reading — return the top eight —
     * offers eight plausible strangers to somebody who has typed nothing, and
     * the client has no way to tell that from a real match.
     */
    public function testAnEmptyQueryIsRefusedRatherThanMatchingEverybody(): void
    {
        $this->seedContact('kim@example.test', 'Kim');

        foreach ([null, '', '   '] as $query) {
            try {
                $this->call(['query' => $query]);
                self::fail(sprintf('an empty query (%s) was answered rather than refused', var_export($query, true)));
            } catch (MethodException $exception) {
                self::assertSame('invalidArguments', $exception->errorType);
            }
        }
    }

    /** A number is not a query; sending one is a bug in the client, not a search. */
    public function testANonStringQueryIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->call(['query' => 42]);
    }

    /**
     * Named rather than ignored, and the error says what the method does take.
     * `filter`, `sort` and `position` are the reasonable guesses — every other
     * query-shaped method here accepts them — and a silently ignored `filter`
     * returns a correct-looking list that had no filter applied.
     */
    public function testAnArgumentThisMethodDoesNotHaveIsRefusedByName(): void
    {
        $this->seedContact('kim@example.test', 'Kim');

        foreach (['filter', 'sort', 'position'] as $argument) {
            try {
                $this->call(['query' => 'kim', $argument => []]);
                self::fail(sprintf('"%s" was accepted and ignored', $argument));
            } catch (MethodException $exception) {
                self::assertSame('invalidArguments', $exception->errorType);
                self::assertStringContainsString($argument, $exception->getMessage());
                self::assertStringContainsString('query', $exception->getMessage(), 'the error must name what is accepted');
            }
        }
    }

    /** A non-integer limit is refused rather than cast to one. */
    public function testANonsensicalLimitIsRefused(): void
    {
        foreach (['8', 0, -1, 2.5] as $limit) {
            try {
                $this->call(['query' => 'kim', 'limit' => $limit]);
                self::fail(sprintf('limit %s was accepted', var_export($limit, true)));
            } catch (MethodException $exception) {
                self::assertSame('invalidArguments', $exception->errorType);
            }
        }
    }

    /**
     * The claim this class exists for. The address book is one table with a
     * user column, and it holds every address anybody has ever mailed —
     * suggesting a stranger's would leak their correspondents one keystroke at
     * a time to whoever guessed a prefix.
     */
    public function testAnotherUsersAddressBookIsNeverSuggested(): void
    {
        $stranger = $this->seedStranger();
        $this->seedContact('secret@example.test', 'Secret Contact', usr: $stranger, frequency: 999);
        $this->seedContact('mine@example.test', 'Mine', frequency: 1);

        self::assertSame([], $this->emailsFor('secret'));
        self::assertSame(['mine@example.test'], $this->emailsFor('example.test'));
    }

    public function testAForeignAccountIdIsNotAnAddressBook(): void
    {
        $this->seedContact('kim@example.test', 'Kim');

        $this->expectException(MethodException::class);

        $this->autocomplete->handle(['accountId' => '0', 'query' => 'kim'], $this->context());
    }

    /**
     * The deliberate difference from calendars, which are user-scoped in the
     * same way and yet are served from the first account only. Nothing here has
     * an id for a client to key by (accountId, id), so there is no object to
     * draw twice — and refusing this would fail a client composing from its
     * second account for no benefit.
     */
    public function testEveryAccountTheUserOwnsAnswers(): void
    {
        $second = $this->secondAccount();
        $this->seedContact('kim@example.test', 'Kim');

        $result = $this->autocomplete->handle([
            'accountId' => (string) $second->id,
            'query' => 'kim',
        ], $this->context());

        self::assertSame((string) $second->id, $result['accountId']);
        self::assertSame(['kim@example.test'], array_column($result['list'], 'email'));
    }

    /** The trimmed query is echoed, so a client can tell which keystroke a late response belongs to. */
    public function testTheQueryComesBackAsItWasRun(): void
    {
        $this->seedContact('kim@example.test', 'Kim');

        self::assertSame('kim', $this->call(['query' => '  kim  '])['query']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function call(array $arguments): array
    {
        return $this->autocomplete->handle(
            array_merge(['accountId' => $this->accountId()], $arguments),
            $this->context(),
        );
    }

    /** @return list<string> */
    private function emailsFor(string $query): array
    {
        return array_column($this->call(['query' => $query])['list'], 'email');
    }

    private function seedContact(
        string $email,
        ?string $name,
        ?User $usr = null,
        int $frequency = 1,
        bool $isCorrespondent = false,
        ?DateTimeImmutable $lastSeenAt = null,
    ): Contact {
        $contact = new Contact();
        $contact->usr = $usr ?? $this->user;
        $contact->email = $email;
        $contact->displayName = $name;
        $contact->frequency = $frequency;
        $contact->isCorrespondent = $isCorrespondent;

        if (null !== $lastSeenAt) {
            $contact->lastSeenAt = $lastSeenAt;
        }

        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function seedStranger(): User
    {
        $stranger = new User();
        $stranger->email = 'stranger-'.uniqid('', true).'@example.test';
        $stranger->nameFirst = 'Stranger';
        $stranger->nameLast = 'Fixture';
        $stranger->roles = ['ROLE_USER'];
        $stranger->password = 'x';

        $this->em->persist($stranger);
        $this->em->flush();

        return $stranger;
    }
}
