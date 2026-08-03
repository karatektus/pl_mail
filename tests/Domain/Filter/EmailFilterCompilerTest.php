<?php

declare(strict_types=1);

namespace App\Tests\Domain\Filter;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Mail\MessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What each filter condition actually means, asserted against a real database.
 *
 * This is now the only implementation of filter semantics — there was briefly
 * an in-memory twin plus a differential test holding the two together, until
 * matching moved wholesale into Postgres. The corpus and the expectations
 * survived that move, because they were never really about agreement: they
 * pin down what "before", "NOT" and "maxSize on a sizeless message" mean.
 *
 * Runs against Postgres deliberately. The interesting conditions — tsvector
 * full text, jsonb containment, ILIKE over serialised address arrays — have no
 * meaning outside the database, and asserting the compiler still emits
 * yesterday's SQL string would test nothing worth knowing.
 */
final class EmailFilterCompilerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageRepository $messages;
    private EmailFilterCompiler $compiler;

    /** @var list<Message> */
    private array $corpus = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->messages = $container->get(MessageRepository::class);
        $this->compiler = new EmailFilterCompiler();

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database — a property the e2e specs
        // notably do not have.
        $this->connection->beginTransaction();

        $this->seedCorpus();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{array<string,mixed>, list<int>}>
     */
    public static function conditionProvider(): iterable
    {
        // Index into the corpus: 0 = invoice, 1 = standup, 2 = sizeless.
        yield 'subject substring'        => [['subject' => 'invoice'], [0]];
        yield 'subject is case-insensitive' => [['subject' => 'INVOICE'], [0]];
        yield 'subject no match'         => [['subject' => 'nothing matches this'], []];
        yield 'from address'             => [['from' => 'billing@'], [0]];
        yield 'from display name'        => [['from' => 'Acme'], [0]];
        yield 'to'                       => [['to' => 'ops@example.test'], [0]];
        yield 'cc'                       => [['cc' => 'cc@example.test'], [1]];
        yield 'body'                     => [['body' => 'wire transfer'], [0]];
        yield 'hasAttachment true'       => [['hasAttachment' => true], [0]];
        yield 'hasAttachment false'      => [['hasAttachment' => false], [1, 2]];
        yield 'filename'                 => [['filename' => 'receipt'], [0]];
        yield 'minSize'                  => [['minSize' => 1000], [0]];
        yield 'maxSize'                  => [['maxSize' => 1000], [1]];
        yield 'before'                   => [['before' => '2026-07-01T00:00:00Z'], [1]];
        yield 'after'                    => [['after' => '2026-07-01T00:00:00Z'], [0, 2]];
        yield 'hasKeyword seen'          => [['hasKeyword' => '$seen'], [0]];
        yield 'notKeyword seen'          => [['notKeyword' => '$seen'], [1, 2]];
        yield 'hasKeyword flagged'       => [['hasKeyword' => '$flagged'], [1]];

        // Now available to rules, because Postgres does the matching: real
        // stemming via the generated search_vector column.
        yield 'text stems'               => [['text' => 'transferring'], [0]];
        yield 'text spans subject+body'  => [['text' => 'standup'], [1]];

        // Keys are canonicalised at ingest by HeaderNormalizer, which is what
        // makes a direct lookup possible at all.
        yield 'listId'                   => [['listId' => 'billing.acme.test'], [0]];
        yield 'listId no match'          => [['listId' => 'other.list'], []];

        yield 'implicit AND' => [['subject' => 'invoice', 'hasAttachment' => true], [0]];

        yield 'AND' => [[
            'operator' => 'AND',
            'conditions' => [['subject' => 'invoice'], ['from' => 'billing@']],
        ], [0]];
        yield 'OR' => [[
            'operator' => 'OR',
            'conditions' => [['subject' => 'invoice'], ['subject' => 'standup']],
        ], [0, 1]];
        // NOT is "none of", not "not all of".
        yield 'NOT means none of' => [[
            'operator' => 'NOT',
            'conditions' => [['subject' => 'invoice'], ['subject' => 'standup']],
        ], [2]];
        yield 'nested' => [[
            'operator' => 'AND',
            'conditions' => [
                ['operator' => 'OR', 'conditions' => [['subject' => 'invoice'], ['subject' => 'standup']]],
                ['operator' => 'NOT', 'conditions' => [['hasAttachment' => true]]],
            ],
        ], [1]];
    }

    /**
     * @param array<string,mixed> $ast
     * @param list<int>           $expectedIndexes
     */
    #[DataProvider('conditionProvider')]
    public function testMatchesExactly(array $ast, array $expectedIndexes): void
    {
        $expected = array_map(fn (int $i): int => (int) $this->corpus[$i]->id, $expectedIndexes);
        sort($expected);

        $actual = $this->messages->matchingIds($this->corpusIds(), $this->compiler->compile($ast));
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * A comparison against NULL is NULL in SQL, so a sizeless message matches
     * neither bound. maxSize is the surprising half — it reads like "smaller
     * than", and a message with no size is not that. The IMAP path never
     * records a size, so this is the common case rather than a corner one.
     */
    public function testNullSizeMatchesNeitherBound(): void
    {
        $sizeless = (int) $this->corpus[2]->id;

        self::assertNotContains($sizeless, $this->match(['minSize' => 1]));
        self::assertNotContains($sizeless, $this->match(['maxSize' => 999999]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $ast
     *
     * @return list<int>
     */
    private function match(array $ast): array
    {
        return $this->messages->matchingIds($this->corpusIds(), $this->compiler->compile($ast));
    }

    /**
     * @return list<int>
     */
    private function corpusIds(): array
    {
        return array_map(static fn (Message $m): int => (int) $m->id, $this->corpus);
    }

    private function seedCorpus(): void
    {
        $user = new User();
        $user->email = 'filter-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Filter';
        $user->nameLast = 'Corpus';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Filter Corpus';
        $account->username = 'filter-corpus@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->corpus = [
            $this->message($account, [
                'subject' => 'Invoice 42 attached',
                'from' => 'billing@acme.test',
                'fromName' => 'Acme Billing',
                'to' => [['name' => 'Ops', 'address' => 'ops@example.test']],
                'body' => 'Please arrange a wire transfer.',
                'attachment' => 'receipt-42.pdf',
                'size' => 4096,
                'received' => '2026-07-15 10:00:00',
                'seen' => true,
                'headers' => ['list-id' => '<invoices.billing.acme.test>'],
            ]),
            $this->message($account, [
                'subject' => 'Daily standup',
                'from' => 'team@example.test',
                'fromName' => 'The Team',
                'cc' => [['name' => 'Watcher', 'address' => 'cc@example.test']],
                'body' => 'No blockers today.',
                'size' => 500,
                'received' => '2026-06-20 09:00:00',
                'starred' => true,
            ]),
            $this->message($account, [
                'subject' => 'Sizeless message',
                'from' => 'nobody@example.test',
                'fromName' => 'Nobody',
                'body' => 'This one never had its size recorded.',
                'size' => null,
                'received' => '2026-07-20 12:00:00',
            ]),
        ];

        $this->em->flush();
    }

    /**
     * @param array<string,mixed> $spec
     */
    private function message(Account $account, array $spec): Message
    {
        $message = new Message();
        $message->account = $account;
        $message->subject = $spec['subject'];
        $message->fromAddress = $spec['from'];
        $message->fromName = $spec['fromName'];
        $message->toAddresses = $spec['to'] ?? null;
        $message->ccAddresses = $spec['cc'] ?? null;
        $message->bodyText = $spec['body'];
        $message->size = $spec['size'];
        $message->headers = $spec['headers'] ?? null;
        $message->receivedAt = new \DateTimeImmutable($spec['received']);
        $message->hasAttachments = true === isset($spec['attachment']);

        if (true === ($spec['seen'] ?? false)) {
            $message->seenAt = new \DateTimeImmutable('2026-07-16 08:00:00');
        }

        if (true === ($spec['starred'] ?? false)) {
            $message->starredAt = new \DateTimeImmutable('2026-07-16 08:00:00');
        }

        $this->em->persist($message);

        if (true === isset($spec['attachment'])) {
            $part = new MessagePart();
            $part->message     = $message;
            $part->contentType = 'application/pdf';
            $part->filename    = $spec['attachment'];
            $part->disposition = 'attachment';
            $this->em->persist($part);
            $message->addMessagePart($part);
        }

        return $message;
    }
}
