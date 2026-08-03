<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Search\SearchQueryParser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Search, from the string a person types to the rows that come back.
 *
 * Driven through the parser rather than by handing the repository a filter
 * object, because the two halves are only useful together and the bugs live in
 * the seam: an operator the parser understands and the SQL never applies is
 * indistinguishable, from the outside, from one it applies to the wrong column.
 * Both failures widen the result set, and a search that returns too much is one
 * nobody notices is broken.
 *
 * Against Postgres, not a double: every operator here is a range, a JSON
 * containment or a full-text match, which is exactly what a fake would have to
 * reimplement to be wrong in a different way.
 */
final class MessageSearchTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageThreadRepository $repository;
    private SearchQueryParser $parser;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageThreadRepository::class);
        $this->parser     = $container->get(SearchQueryParser::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testFromMatchesTheAddressAndTheName(): void
    {
        $this->seedMessage(subject: 'Invoice', fromAddress: 'billing@acme.test', fromName: 'Acme Billing');
        $this->seedMessage(subject: 'Lunch', fromAddress: 'kim@example.test', fromName: 'Kim');

        self::assertSame(['Invoice'], $this->search('from:billing@'));
        self::assertSame(['Invoice'], $this->search('from:acme billing'));
        // Case is the sender's business, not the searcher's.
        self::assertSame(['Invoice'], $this->search('from:BILLING@ACME.TEST'));
    }

    /**
     * to: and cc: read a JSON array of {name, address} objects. cc: existed on
     * the SQL side for a long time with no way to reach it — the parser had no
     * case for it — so this is as much about the seam as the column.
     */
    public function testToAndCcReadTheirOwnRecipientLists(): void
    {
        $this->seedMessage(
            subject: 'Direct',
            to: [['name' => 'Kim', 'address' => 'kim@example.test']],
        );
        $this->seedMessage(
            subject: 'Copied',
            to: [['name' => 'Sam', 'address' => 'sam@example.test']],
            cc: [['name' => 'Kim', 'address' => 'kim@example.test']],
        );

        self::assertSame(['Direct'], $this->search('to:kim@example.test'));
        self::assertSame(['Copied'], $this->search('cc:kim@example.test'));
    }

    public function testSubjectMatchesPartOfIt(): void
    {
        $this->seedMessage(subject: 'Quarterly invoice 2026-0841');
        $this->seedMessage(subject: 'Lunch on Thursday');

        self::assertSame(['Quarterly invoice 2026-0841'], $this->search('subject:invoice'));
    }

    /**
     * Free text goes through the generated tsvector, which is the whole reason
     * it is Postgres doing the matching: "meetings" finds "meeting" because the
     * column is stemmed, and no LIKE would.
     */
    public function testFreeTextIsStemmed(): void
    {
        $this->seedMessage(subject: 'Notes', body: 'The meeting is postponed until Tuesday.');
        $this->seedMessage(subject: 'Other', body: 'Nothing relevant in here.');

        self::assertSame(['Notes'], $this->search('meetings'));
    }

    public function testLabelMatchesAUserLabelByName(): void
    {
        $receipts = $this->seedLabel('Receipts');

        $this->seedMessage(subject: 'Filed', labels: [$receipts]);
        $this->seedMessage(subject: 'Unfiled');

        self::assertSame(['Filed'], $this->search('label:receipts'));
    }

    /**
     * in: names a mailbox and the SQL matches a role, which only works because
     * the parser resolves the alias first. `in:junk` finding nothing would look
     * like an empty spam folder rather than a filter that never applied.
     */
    public function testInMatchesTheRoleBehindTheMailboxName(): void
    {
        $spam = $this->seedLabel('Spam', LabelRole::Spam);

        $this->seedMessage(subject: 'Dubious', labels: [$spam]);
        $this->seedMessage(subject: 'Ordinary');

        self::assertSame(['Dubious'], $this->search('in:junk'));
        self::assertSame(['Dubious'], $this->search('in:spam'));
    }

    public function testFlagsAndDatesNarrowTheSameWay(): void
    {
        $this->seedMessage(subject: 'Unread old', receivedAt: '2024-03-01', seen: false);
        $this->seedMessage(subject: 'Read recent', receivedAt: '2026-03-01', seen: true);

        self::assertSame(['Unread old'], $this->search('is:unread'));
        self::assertSame(['Read recent'], $this->search('is:read'));
        self::assertSame(['Read recent'], $this->search('after:2025-01-01'));
        self::assertSame(['Unread old'], $this->search('before:2025-01-01'));
    }

    /** Operators are ANDed: each one may only ever narrow the result. */
    public function testOperatorsCombine(): void
    {
        $this->seedMessage(subject: 'Invoice', fromAddress: 'billing@acme.test');
        $this->seedMessage(subject: 'Invoice', fromAddress: 'other@else.test');

        self::assertSame(['Invoice'], $this->search('subject:invoice from:acme'));
        self::assertCount(2, $this->search('subject:invoice'));
    }

    /**
     * The guard the whole search rests on. Every clause is ANDed onto a user
     * scope, so a filter that matches everything still may not reach another
     * user's mail — the failure mode nobody would see in their own account.
     */
    public function testSearchNeverLeavesTheUser(): void
    {
        $this->seedMessage(subject: 'Mine', fromAddress: 'billing@acme.test');

        $stranger = $this->seedUser();
        $this->seedMessage(
            subject: 'Theirs',
            fromAddress: 'billing@acme.test',
            account: $this->seedAccount($stranger),
        );

        self::assertSame(['Mine'], $this->search('from:billing@acme.test'));
        self::assertSame(1, $this->repository->countSearch($this->user, $this->parser->parse('from:billing@acme.test')));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @return list<string> subjects of the matching threads */
    private function search(string $query): array
    {
        return array_map(
            static fn (MessageThread $thread): string => (string) $thread->subject,
            $this->repository->search($this->user, $this->parser->parse($query)),
        );
    }

    /**
     * @param list<array{name: string, address: string}> $to
     * @param list<array{name: string, address: string}> $cc
     * @param list<Label>                                $labels
     */
    private function seedMessage(
        string $subject,
        string $fromAddress = 'sender@example.test',
        string $fromName = 'Sender',
        array $to = [],
        array $cc = [],
        array $labels = [],
        string $body = 'Nothing in particular.',
        string $receivedAt = '2026-01-01',
        bool $seen = false,
        ?Account $account = null,
    ): void {
        $account ??= $this->account;

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable($receivedAt);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = $fromAddress;
        $message->fromName       = $fromName;
        $message->toAddresses    = $to;
        $message->ccAddresses    = $cc;
        $message->bodyText       = $body;
        $message->receivedAt     = new DateTimeImmutable($receivedAt);
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];

        if (true === $seen) {
            $message->seenAt = new DateTimeImmutable($receivedAt);
        }

        foreach ($labels as $label) {
            $message->addLabel($label);
            $thread->addLabel($label);
        }

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();
    }

    private function seedLabel(string $name, ?LabelRole $role = null): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Search fixture';
        $account->email          = 'search@example.test';
        $account->username       = uniqid('search-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'search-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Search';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
