<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\UserRepository;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A mailbox big enough to measure against.
 *
 * The other `app:test:*` seeders build a handful of rows a browser test can
 * name. This one builds the opposite: a few hundred thousand messages nobody
 * looks at individually, because query plans are the thing under test and
 * Postgres will not show you the plan you have in production until the table
 * is the size it is in production. Against the 22-row test database every
 * query is a sequential scan and every one of them is instant, which is the
 * most misleading possible answer.
 *
 * **Shapes, not volume alone.** A million identical rows would still mislead:
 * the planner reads statistics, GIN reads the lexeme dictionary, and both
 * behave differently against real distributions. So this seeds
 *
 *   • senders on a long tail — a handful of addresses hold most of the mail,
 *     which is what a real mailbox looks like and what makes `from:` selective
 *     for some values and useless for others;
 *   • bodies of wildly different lengths, from one-line replies to newsletters,
 *     because row width is what makes a sequential scan expensive and it is the
 *     average that decides;
 *   • a vocabulary large enough that the tsvector dictionary is not a toy —
 *     a word that appears in every row teaches the planner nothing;
 *   • threads of one, a few, and occasionally eighty messages;
 *   • dates spread over years, since almost every list here is ordered by one.
 *
 * `search_vector` is a generated column, so it is built by Postgres as the
 * rows land — inserting through this path exercises the same index maintenance
 * a real sync does.
 *
 * Raw SQL and one INSERT per chunk, deliberately. The ORM would need an entity,
 * a UnitOfWork slot and a flush per message; at this size that is the
 * difference between seconds and an afternoon, and none of what it buys —
 * events, threading, label derivation — is what a plan measurement is about.
 *
 * Deterministic: seeded from a fixed value, so the same invocation twice
 * produces the same mailbox and a measurement can be repeated.
 *
 * Refuses to run in prod, and only ever touches its own account.
 */
#[AsCommand(
    name: 'app:test:seed-bulk',
    description: 'Seed a large, realistically-shaped mailbox for query measurement',
)]
final class SeedBulkMailCommand extends Command
{
    use TargetsTestUser;

    private const string SEED_ACCOUNT_USERNAME = 'bulk@e2e.test';

    /** Rows per INSERT. Large enough to amortise the round trip, small enough to hold in memory. */
    private const int CHUNK = 2_000;

    /**
     * The vocabulary bodies are built from.
     *
     * Ordinary mail words rather than lorem ipsum, because the point is a
     * lexeme dictionary shaped like English — the stemmer, the stop-word list
     * and the GIN posting lists all behave differently against real ones.
     */
    private const array WORDS = [
        'invoice', 'meeting', 'deadline', 'proposal', 'attached', 'review', 'quarterly',
        'shipment', 'tracking', 'delivery', 'confirm', 'booking', 'flight', 'reservation',
        'password', 'account', 'security', 'verify', 'subscription', 'renewal', 'payment',
        'contract', 'signature', 'agreement', 'schedule', 'reschedule', 'available',
        'thanks', 'regards', 'morning', 'afternoon', 'following', 'attached', 'question',
        'release', 'deployment', 'incident', 'postmortem', 'rollback', 'migration',
        'newsletter', 'unsubscribe', 'discount', 'offer', 'expires', 'exclusive',
        'photos', 'holiday', 'weekend', 'dinner', 'birthday', 'congratulations',
    ];

    private const array SUBJECT_HEADS = [
        'Re:', 'Fwd:', 'Your', 'Weekly', 'Action required:', 'Reminder:', 'Update on',
        'Notice:', 'Invitation:', 'Draft', 'Question about', '[ticket]',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly UserRepository $userRepository,
        private readonly AccountRepository $accountRepository,
        private readonly LabelResolver $labelResolver,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();

        $this
            ->addOption('messages', null, InputOption::VALUE_REQUIRED, 'How many messages to seed', '200000')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Remove the bulk account and everything in it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-bulk must not run in the prod environment.');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['email' => $this->resolveUserEmail($input)]);

        if (null === $user) {
            $io->error('No such user — run app:test:seed-user first.');

            return Command::FAILURE;
        }

        $account = $this->account($user);

        if (true === $input->getOption('clear')) {
            $this->wipe($account);
            $io->success('Bulk mailbox cleared.');

            return Command::SUCCESS;
        }

        $total = max(1, (int) $input->getOption('messages'));

        $this->wipe($account);

        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $this->entityManager->flush();

        $io->writeln(sprintf('Seeding %s messages…', number_format($total)));
        $started = microtime(true);

        $this->seed($account, (int) $inbox->id, $total, $io);

        // Statistics, or every plan below is drawn against an empty table's
        // guesses. This is the step people forget, and it is the difference
        // between measuring the query and measuring the absence of an ANALYZE.
        $io->writeln('Analysing…');
        $this->connection->executeStatement('ANALYZE message');
        $this->connection->executeStatement('ANALYZE message_thread');
        $this->connection->executeStatement('ANALYZE thread_label');

        $io->success(sprintf(
            '%s messages in %s threads, in %.1fs.',
            number_format($total),
            number_format((int) ceil($total / 3)),
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }

    private function seed(Account $account, int $inboxLabelId, int $total, SymfonyStyle $io): void
    {
        // Fixed seed: the same command twice builds the same mailbox, so a
        // before-and-after measurement compares like with like.
        mt_srand(20260118);

        $senders = $this->senders();
        $now     = time();
        $made    = 0;

        $io->progressStart($total);

        while ($made < $total) {
            $size = min(self::CHUNK, $total - $made);

            $this->connection->beginTransaction();

            // One thread per chunk-of-three on average, with the occasional
            // long one — threads are what every list query groups by, so their
            // size distribution is part of the shape being reproduced.
            $threadIds = $this->insertThreads($account, $inboxLabelId, (int) ceil($size / 3), $now);

            $this->insertMessages($account, $threadIds, $senders, $size, $now);

            $this->connection->commit();

            $made += $size;
            $io->progressAdvance($size);
        }

        $io->progressFinish();
    }

    /**
     * @return list<int>
     */
    private function insertThreads(Account $account, int $inboxLabelId, int $count, int $now): array
    {
        $rows   = [];
        $params = [];

        for ($i = 0; $i < $count; ++$i) {
            $when    = date('Y-m-d H:i:s', $now - mt_rand(0, 3 * 365 * 86400));
            $subject = $this->subject();

            $rows[]   = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params[] = $account->id;
            $params[] = $subject;
            $params[] = mb_strtolower($subject);
            $params[] = 'subject_fallback';
            $params[] = $when;
            $params[] = mt_rand(0, 4) === 0 ? 1 : 0;
            $params[] = 3;
            $params[] = 0;
            $params[] = 'primary';
            $params[] = $when;
        }

        $this->connection->executeStatement(
            'INSERT INTO message_thread'
            . ' (account_id, subject, normalized_subject, threading_method, last_message_at,'
            . '  unread_count, message_count, attachment_count, category, listed_at)'
            . ' VALUES ' . implode(', ', $rows),
            $params,
        );

        /** @var list<int> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM message_thread WHERE account_id = ? ORDER BY id DESC LIMIT ?',
            [$account->id, $count],
        );

        // Every thread wears Inbox, so the list queries have the join they have
        // in the app rather than an easier one.
        $labelRows   = [];
        $labelParams = [];

        foreach ($ids as $id) {
            $labelRows[]   = '(?, ?)';
            $labelParams[] = $id;
            $labelParams[] = $inboxLabelId;
        }

        $this->connection->executeStatement(
            'INSERT INTO thread_label (message_thread_id, label_id) VALUES ' . implode(', ', $labelRows)
            . ' ON CONFLICT DO NOTHING',
            $labelParams,
        );

        return $ids;
    }

    /**
     * @param list<int>                                  $threadIds
     * @param list<array{address: string, name: string}> $senders
     */
    private function insertMessages(Account $account, array $threadIds, array $senders, int $count, int $now): void
    {
        $rows   = [];
        $params = [];

        for ($i = 0; $i < $count; ++$i) {
            $sender  = $senders[$this->skewed(count($senders))];
            $when    = date('Y-m-d H:i:s', $now - mt_rand(0, 3 * 365 * 86400));
            $subject = $this->subject();

            $rows[]   = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params[] = $account->id;
            $params[] = $threadIds[array_rand($threadIds)];
            $params[] = $subject;
            $params[] = $sender['address'];
            $params[] = $sender['name'];
            $params[] = json_encode([['name' => 'You', 'address' => 'you@example.test']]);
            $params[] = $this->body();
            $params[] = '[]';
            $params[] = 0;
            $params[] = 0;
            $params[] = $when;
            $params[] = $when;
            $params[] = mt_rand(0, 3) === 0 ? null : $when;
            $params[] = $when;
            $params[] = $when;
        }

        $this->connection->executeStatement(
            'INSERT INTO message'
            . ' (account_id, thread_id, subject, from_address, from_name, to_addresses, body_text,'
            . '  flags, has_attachments, cancelled, sent_at, received_at, seen_at, created_at, updated_at)'
            . ' VALUES ' . implode(', ', $rows),
            $params,
        );
    }

    /**
     * The vocabulary, drawn the way words actually occur.
     *
     * The first version of this had 52 words in it, and the measurement it
     * produced was worthless in an instructive way: every one of them appeared
     * in 74% of the messages, so a sequential scan genuinely WAS the right plan
     * and Postgres was right to choose it. Tuning a query against that is
     * tuning it against a mailbox nobody has.
     *
     * Real text is Zipfian — "the" is everywhere, most words are rare, and a
     * search term is worth an index precisely because it matches a small
     * fraction. This draws log-uniformly over a 20,000-word vocabulary, which
     * is close enough to Zipf for a planner: the common end stays common, the
     * tail stays long, and a typical term lands in a fraction of a percent of
     * rows rather than in three quarters of them.
     *
     * The fixed words above are kept and sit at the common end, so a test that
     * wants a term certain to match something still has one.
     */
    private const int VOCABULARY = 20_000;

    private function word(): string
    {
        // exp(uniform * ln V) — log-uniform over 1..V. Cheap, no cumulative
        // table, and monotone in the random draw, so the fixed seed keeps
        // producing the same mailbox.
        $rank = (int) floor(self::VOCABULARY ** (mt_rand() / mt_getrandmax()));

        return $rank < count(self::WORDS)
            ? self::WORDS[$rank]
            : 'w' . $rank;
    }

    /**
     * A long tail of senders: a few write constantly, most write once.
     *
     * @return list<array{address: string, name: string}>
     */
    private function senders(): array
    {
        $domains = ['example.test', 'mail.test', 'shop.test', 'bank.test', 'news.test', 'team.test'];
        $senders = [];

        for ($i = 0; $i < 4_000; ++$i) {
            $senders[] = [
                'address' => sprintf('sender%d@%s', $i, $domains[$i % count($domains)]),
                'name'    => sprintf('%s %s', self::WORDS[$i % count(self::WORDS)], 'Sender ' . $i),
            ];
        }

        return $senders;
    }

    /**
     * An index into a list, biased hard towards the front.
     *
     * Uniform picking would give every sender the same number of messages,
     * which is the one distribution no mailbox has — and it is exactly the
     * distribution that makes an index look uniformly useful.
     */
    private function skewed(int $size): int
    {
        return (int) min($size - 1, floor(abs(mt_rand() / mt_getrandmax()) ** 3 * $size));
    }

    private function subject(): string
    {
        $words = [];

        for ($i = 0, $n = mt_rand(2, 7); $i < $n; ++$i) {
            $words[] = $this->word();
        }

        return self::SUBJECT_HEADS[array_rand(self::SUBJECT_HEADS)] . ' ' . implode(' ', $words);
    }

    /**
     * A body whose length is drawn from something like the real distribution:
     * mostly short, occasionally enormous.
     */
    private function body(): string
    {
        $roll = mt_rand(1, 100);

        $length = match (true) {
            $roll <= 55 => mt_rand(10, 60),      // a reply
            $roll <= 90 => mt_rand(60, 400),     // an ordinary mail
            $roll <= 99 => mt_rand(400, 1_500),  // a long one
            default     => mt_rand(1_500, 6_000) // a newsletter
        };

        $words = [];

        for ($i = 0; $i < $length; ++$i) {
            $words[] = $this->word();
        }

        // Real mail is full of compound tokens — link hosts, footer addresses,
        // unsubscribe URLs — and they are the whole reason the search index
        // splits them (see Version20260818120000). A corpus of bare words would
        // measure the tokenizer change against text it never has to handle.
        for ($i = 0, $n = mt_rand(1, 4); $i < $n; ++$i) {
            $words[] = mt_rand(0, 1) === 0
                ? sprintf('%s.%s.de', $this->word(), $this->word())
                : sprintf('%s.%s@%s-corp.co.uk', $this->word(), $this->word(), $this->word());
        }

        return implode(' ', $words);
    }

    private function account(object $user): Account
    {
        $account = $this->accountRepository->findOneBy([
            'usr'      => $user,
            'username' => self::SEED_ACCOUNT_USERNAME,
        ]);

        if (null !== $account) {
            return $account;
        }

        $account = new Account();
        $account->usr            = $user;
        $account->name           = 'Bulk Mailbox';
        $account->email          = 'bulk@e2e.test';
        $account->username       = self::SEED_ACCOUNT_USERNAME;
        $account->imapHost       = 'imap.e2e.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /** Everything this command has ever made on its own account, and nothing else. */
    private function wipe(Account $account): void
    {
        $this->connection->executeStatement(
            'DELETE FROM message WHERE account_id = ?',
            [$account->id],
        );
        $this->connection->executeStatement(
            'DELETE FROM message_thread WHERE account_id = ?',
            [$account->id],
        );
    }
}
