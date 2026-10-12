<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\UserRepository;
use App\Service\Crypto\MessageCryptoDetector;
use App\Service\Label\LabelResolver;
use App\Service\Mail\RawMessageResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Puts one message, given as its raw source, into the inbox of an end-to-end
 * test user's seeded mailbox — for the specs that need mail a browser
 * encrypted to the user's own key (encryption-reading.spec.ts).
 *
 * The message is classified the way ingest classifies it
 * (MessageCryptoDetector) and its raw source stored the way ingest stores it,
 * so the reading pane meets it exactly as it would meet synced mail. Base64 on
 * the command line rather than a file: the spec runs outside the container
 * and a few kilobytes of armor fit an argument easily.
 *
 * Needs `app:test:seed-mail` first, which creates the mailbox this delivers
 * into. Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:seed-crypto-mail',
    description: 'Deliver one raw (e.g. OpenPGP-encrypted) message into an E2E user\'s seeded inbox',
)]
final class SeedCryptoMailCommand extends Command
{
    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';

    public function __construct(
        private readonly UserRepository         $users,
        private readonly AccountRepository      $accounts,
        private readonly LabelResolver          $labels,
        private readonly MessageCryptoDetector  $detector,
        private readonly RawMessageResolver     $raw,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The E2E user')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Subject of the message')
            ->addOption('raw-base64', null, InputOption::VALUE_REQUIRED, 'The raw RFC 5322 message, base64');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-crypto-mail must not run in the prod environment.');

            return Command::FAILURE;
        }

        $user    = $this->users->findOneBy(['email' => (string) $input->getOption('email')]);
        $account = null === $user ? null : $this->accounts->findOneBy(['usr' => $user, 'username' => self::SEED_ACCOUNT_USERNAME]);
        $raw     = base64_decode((string) $input->getOption('raw-base64'), true);

        if (null === $account || false === $raw || '' === $raw) {
            $io->error('Needs a seeded E2E user with app:test:seed-mail run, and a raw message.');

            return Command::FAILURE;
        }

        $subject = (string) $input->getOption('subject');
        $now     = new DateTimeImmutable();
        $inbox   = $this->labels->systemLabel(LabelRole::Inbox, $account);

        [$headers] = explode("\r\n\r\n", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), 2);

        $message = new Message();
        $message->account        = $account;
        $message->subject        = $subject;
        $message->fromName       = 'E2E Sender';
        $message->fromAddress    = 'sender@e2e.test';
        $message->toAddresses    = [['name' => 'E2E Tester', 'address' => self::SEED_ACCOUNT_USERNAME]];
        $message->headers        = ['content-type' => self::contentType($headers)];
        $message->receivedAt     = $now;
        $message->sentAt         = $now;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = $now;
        $message->messageId      = sprintf('crypto-%s@e2e.test', bin2hex(random_bytes(6)));
        $message->addLabel($inbox);

        $this->detector->seal($message, $this->detector->classify(self::contentType($headers), self::textBody($raw), ''));

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower(trim($subject));
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 1;
        $thread->category          = MessageCategory::Primary;
        $thread->attachmentCount   = 0;
        $thread->lastMessageAt     = $now;
        $thread->addLabel($inbox);

        $message->thread = $thread;

        $this->entityManager->persist($thread);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->raw->store($message, $raw);
        $this->entityManager->flush();

        $io->success(sprintf('Delivered "%s" (%s).', $subject, $message->crypto->value ?? 'plain'));

        return Command::SUCCESS;
    }

    /** The top-level Content-Type, unfolded. */
    private static function contentType(string $headers): string
    {
        $unfolded = (string) preg_replace("/\r\n[ \t]+/", ' ', $headers);

        return 1 === preg_match('/^content-type:\s*(.+)$/im', $unfolded, $match) ? trim($match[1]) : 'text/plain';
    }

    /** For inline PGP: the body of a single-part text message. */
    private static function textBody(string $raw): string
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);

        return $parts[1] ?? '';
    }
}
